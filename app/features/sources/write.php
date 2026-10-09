<?php
declare(strict_types=1);

/**
 * Sources' writes (sources.md "Writes"; connectors.md §5–§6). The caller holds the transaction (inv_guard()), except run_probe(), which talks to
 * the network between its start and its finish and commits each itself. Ciphertext goes in as convert_to(:sealed, 'UTF8') and never comes
 * back out of this file.
 */
require_once dirname(__DIR__, 2) . '/sources/pulls.php';

/** INSERT or UPDATE a source; the settings replace the row's. A change of base_url, settings or user_agent resumes it (§6.5). Returns ['id', 'resumed']. */
function save_source(PDO $pdo, ?int $id, array $f, array $settings, int $by): array
{
    $json = json_encode((object) $settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO sources (name, connector, role, supplier_id, base_url, settings, schedule_minutes, rate_per_second, user_agent, created_by)
                             VALUES (:name, :conn, :role, :sup, :url, CAST(:settings AS jsonb), :sched, :rate, :ua, :by) RETURNING id');
        $st->execute(['name' => $f['name'], 'conn' => $f['connector'], 'role' => $f['role'], 'sup' => $f['supplier_id'], 'url' => $f['base_url'], 'settings' => $json,
                      'sched' => $f['schedule_minutes'], 'rate' => $f['rate_per_second'] ?? 1, 'ua' => $f['user_agent'], 'by' => $by]);
        return ['id' => (int) $st->fetchColumn(), 'resumed' => false];
    }
    $cur = $pdo->prepare('SELECT base_url, settings, user_agent FROM sources WHERE id = :id FOR UPDATE');
    $cur->execute(['id' => $id]);
    $old = $cur->fetch() ?: throw new DomainException('Not found.');
    $pdo->prepare('UPDATE sources SET name = :name, role = :role, supplier_id = :sup, base_url = :url, settings = CAST(:settings AS jsonb), schedule_minutes = :sched,
                          rate_per_second = :rate, user_agent = :ua, active = CAST(:active AS boolean) WHERE id = :id')
        ->execute(['name' => $f['name'], 'role' => $f['role'], 'sup' => $f['supplier_id'], 'url' => $f['base_url'], 'settings' => $json, 'sched' => $f['schedule_minutes'],
                   'rate' => $f['rate_per_second'], 'ua' => $f['user_agent'], 'active' => $f['active'] ? 1 : 0, 'id' => $id]);
    $changed = $old['base_url'] !== $f['base_url'] || (json_decode((string) $old['settings'], true) ?: []) != $settings || $old['user_agent'] !== $f['user_agent'];
    if ($changed) { $pdo->prepare('SELECT inv_source_resume(:id)')->execute(['id' => $id]); }
    return ['id' => $id, 'resumed' => $changed];
}

function delete_source(PDO $pdo, int $id): void
{
    $pdo->prepare('DELETE FROM sources WHERE id = :id')->execute(['id' => $id]);
}

/** The schedule (0 = manual) and the rate (capped by the trigger). Returns the row's values before and after. */
function set_schedule(PDO $pdo, int $id, int $minutes, ?float $rate): array
{
    $before = $pdo->query('SELECT schedule_minutes, rate_per_second FROM sources WHERE id = ' . $id)->fetch();
    $pdo->prepare('UPDATE sources SET schedule_minutes = :m, rate_per_second = COALESCE(CAST(:r AS numeric), rate_per_second) WHERE id = :id')
        ->execute(['m' => $minutes, 'r' => $rate, 'id' => $id]);
    $after = $pdo->query('SELECT schedule_minutes, rate_per_second FROM sources WHERE id = ' . $id)->fetch();
    return ['before' => ['schedule_minutes' => (int) $before['schedule_minutes'], 'rate_per_second' => (float) $before['rate_per_second']],
            'after' => ['schedule_minutes' => (int) $after['schedule_minutes'], 'rate_per_second' => (float) $after['rate_per_second']]];
}

function pause_source(PDO $pdo, int $id, string $reason): void
{
    $pdo->prepare('UPDATE sources SET paused_at = now(), paused_reason = :r WHERE id = :id')->execute(['r' => mb_substr($reason, 0, 200), 'id' => $id]);
}

function resume_source(PDO $pdo, int $id): void
{
    $pdo->prepare('SELECT inv_source_resume(:id)')->execute(['id' => $id]);
}

/**
 * "Pull now" (§6.4): refused while a pull runs (the SQL's sentence) and within 5 minutes of the last pull of kind scheduled or manual (the
 * handler's — DomainException "Pulled 2 minutes ago — try again at 14:07"); a `running` row marked queued for the worker. Returns the pull id.
 */
function queue_pull(PDO $pdo, int $sourceId, int $by): int
{
    $last = one_value($pdo, "SELECT started_at FROM source_pulls WHERE source_id = :s AND kind IN ('scheduled', 'manual') ORDER BY started_at DESC LIMIT 1", ['s' => $sourceId]);
    if ($last !== null && strtotime((string) $last) > time() - 300) {
        $mins = max(1, (int) round((time() - strtotime((string) $last)) / 60));
        throw new DomainException('Pulled ' . $mins . ' minute' . ($mins === 1 ? '' : 's') . ' ago — try again at ' . date('H:i', (int) strtotime((string) $last) + 300) . ' UTC');
    }
    $st = $pdo->prepare("SELECT inv_source_pull_start(:s, 'manual', :b)");
    $st->execute(['s' => $sourceId, 'b' => $by]);
    $pid = (int) $st->fetchColumn();
    $pdo->prepare("UPDATE source_pulls SET policy = '{\"queued\": true}'::jsonb WHERE id = :p")->execute(['p' => $pid]);
    return $pid;
}

/**
 * The probe (§6.5), inline: a `probe` pull row started (refused while a pull runs), the connector's probe(), the row finished ok / blocked /
 * failed (the ladder applies). Commits itself. Returns {state, message, facts, pull_id, http_status}.
 */
function run_probe(PDO $pdo, int $sourceId, int $by): array
{
    $st = $pdo->prepare("SELECT inv_source_pull_start(:s, 'probe', :b)");
    $st->execute(['s' => $sourceId, 'b' => $by]);
    $pid = (int) $st->fetchColumn();
    $row = $pdo->query('SELECT connector, base_url FROM sources WHERE id = ' . $sourceId)->fetch();
    $http = null;
    try {
        $source = inv_source_for_connector($pdo, $sourceId);
        $c = inv_connector((string) $row['connector']);
        $res = $c->probe($source);
    } catch (InvCredentialError) {
        $res = inv_probe_result('misconfigured', 'the credential cannot be opened — set it again');
    } catch (InvMisconfigured $e) {
        $res = inv_probe_result('misconfigured', $e->getMessage());
    } catch (Throwable $e) {
        $res = inv_probe_result('misconfigured', mb_substr($e->getMessage(), 0, 200));
    }
    $facts = (array) ($res['facts'] ?? []);
    $status = match ($res['state']) { 'ok' => 'ok', 'blocked' => 'blocked', default => 'failed' };
    $policy = ['probe' => true, 'state' => $res['state']];
    $robots = $facts['robots'] ?? null;
    if ($robots === 'blocked') { $policy['robots'] = 'blocked'; }                       // the host refuses crawlers at the door
    elseif ($robots === 'ok' || $robots === 'none') { $policy['robots'] = 'ok'; }       // an absent robots.txt allows everything; 'disallow' (one path) leaves the state alone
    foreach (['http_status', 'status'] as $k) { if (isset($facts[$k]) && is_int($facts[$k])) { $http = $facts[$k]; } }
    $st = $pdo->prepare('SELECT * FROM inv_source_pull_finish(:p, :s, :e, CAST(:pol AS jsonb), NULL, NULL)');
    $st->execute(['p' => $pid, 's' => $status, 'e' => mb_substr((string) $res['message'], 0, 200), 'pol' => json_encode($policy)]);
    $after = $st->fetch();
    inv_pull_notify($pdo, [], $after, $pdo->query('SELECT * FROM source_pulls WHERE id = ' . $pid)->fetch());     // a probe climbs the ladder like a pull, and tells the Buyer once per rung
    return ['state' => $res['state'], 'message' => (string) $res['message'], 'facts' => $facts, 'pull_id' => $pid, 'http_status' => $http];
}

/**
 * A credential set or rotated (§5.3): sealed, a NEW row, the source pointed at it, the old row's rotated_at stamped and the row deleted,
 * inv_source_resume(). Returns {credential_id, last4, label, rotated}.
 */
function set_credential(PDO $pdo, int $sourceId, string $kind, string $label, array $fields, int $by): array
{
    $cred = ['kind' => $kind, 'label' => $label] + $fields;
    $sum = inv_credential_summary($cred);
    $sealed = inv_seal($cred);
    $old = one_value($pdo, 'SELECT credential_id FROM sources WHERE id = :s FOR UPDATE', ['s' => $sourceId]);
    $st = $pdo->prepare("INSERT INTO source_credentials (source_id, kind, label, last4, ciphertext, created_by) VALUES (:s, :k, :l, :f, convert_to(:c, 'UTF8'), :b) RETURNING id");
    $st->execute(['s' => $sourceId, 'k' => $kind, 'l' => $label, 'f' => $sum['last4'], 'c' => $sealed, 'b' => $by]);
    $new = (int) $st->fetchColumn();
    $pdo->prepare('UPDATE sources SET credential_id = :c WHERE id = :s')->execute(['c' => $new, 's' => $sourceId]);
    if ($old !== null) {
        $pdo->prepare('UPDATE source_credentials SET rotated_at = now() WHERE id = :o')->execute(['o' => $old]);
        $pdo->prepare('DELETE FROM source_credentials WHERE id = :o')->execute(['o' => $old]);
    }
    $pdo->prepare('SELECT inv_source_resume(:s)')->execute(['s' => $sourceId]);
    return ['credential_id' => $new, 'last4' => $sum['last4'], 'label' => $label, 'kind' => $kind, 'rotated' => $old !== null];
}

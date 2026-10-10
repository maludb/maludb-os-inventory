<?php
declare(strict_types=1);

/**
 * The worker glue (docs/build-specs/connectors.md §6 — every function and step as written there; slice 3, sources.md "The worker glue"):
 * the due sources and the queued on-demand pulls, the `$source` array a connector receives (the ONE opener of a credential), one pull end
 * to end — start, build, pull with an emitter that writes each listing in its own transaction, the status downgraded by emit errors, the
 * removals after a full read, the finish (the ladder is the SQL's), the matcher's proposals, the log, the Buyer's notice —, the live search,
 * and the cache housekeeping. A connector only reads; the schema matches, snapshots and keeps the price sheet inside inv_upsert_listing();
 * this file drives. The worker reads base tables (it has no member — the mcp_* views answer a member only).
 */
require_once __DIR__ . '/registry.php';

/** The pass (§6.1): the queued on-demand pulls first, then the due sources oldest-first; each pull its own try; a 50-minute budget. */
function sources_run_due_pulls(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    $report = ['sources' => 0, 'pulls' => 0, 'queued' => 0, 'ok' => 0, 'partial' => 0, 'failed' => 0, 'blocked' => 0, 'listings' => 0, 'errors' => 0, 'proposals' => 0, 'cache_pruned' => 0];
    $budget = (int) (env('INV_WORKER_PULLS_BUDGET_SECONDS') ?: 3000);
    $started = microtime(true);
    $tally = static function (array $r) use (&$report): void {
        $report['pulls']++;
        $st = (string) ($r['status'] ?? 'failed');
        if (isset($report[$st])) { $report[$st]++; }
        $report['listings'] += (int) ($r['listings_seen'] ?? 0);
        $report['proposals'] += (int) ($r['proposals'] ?? 0);
    };
    $queued = $pdo->query("SELECT id, source_id FROM source_pulls WHERE status = 'running' AND policy->>'queued' = 'true' AND started_at > now() - interval '6 hours' ORDER BY started_at")->fetchAll();
    foreach ($queued as $q) {
        if (microtime(true) - $started > $budget) { break; }
        try {
            $tally(inv_run_pull($pdo, (int) $q['source_id'], 'manual', null, null, (int) $q['id']));
            $report['queued']++;
        } catch (Throwable $e) {
            $report['errors']++;
            error_log('pull ' . $q['id'] . ': ' . $e->getMessage());
        }
    }
    $due = $pdo->query('SELECT id FROM inv_sources_due() LIMIT ' . max(1, $limit))->fetchAll(PDO::FETCH_COLUMN);
    $report['sources'] = count($due);
    foreach ($due as $sid) {
        if (microtime(true) - $started > $budget) { break; }
        try {
            $tally(inv_run_pull($pdo, (int) $sid, 'scheduled', null));
        } catch (Throwable $e) {
            $report['errors']++;                                      // a refusal at the start (paused, running) is the pass's error line, never an exception out of it
            error_log('pull of source ' . $sid . ': ' . $e->getMessage());
        }
    }
    // housekeeping (§6.7): once a day, the first pass after 03:00 local
    $dir = inv_pull_cache_dir();
    $mark = $dir . '/.pruned';
    $today = $now->format('Y-m-d');
    if ((int) $now->format('G') >= 3 && (!is_file($mark) || trim((string) @file_get_contents($mark)) !== $today)) {
        $report['cache_pruned'] = inv_http_cache_prune($dir, 30);
        @file_put_contents($mark, $today);
    }
    return $report;
}

/** The HTTP cache directory: storage/http-cache, or INV_HTTP_CACHE_DIR outside production (the proofs' scratch cache). */
function inv_pull_cache_dir(): string
{
    $dir = app_is_prod() ? '' : (string) (env('INV_HTTP_CACHE_DIR') ?: '');
    if ($dir === '') { return inv_http_default_cache_dir(); }
    if (!is_dir($dir)) { @mkdir($dir, 0770, true); }
    return $dir;
}

/** The honest user-agent (§6.2): the source's override, the settings', the business name + contact, else null (the client's default says it is unconfigured). */
function inv_source_user_agent(PDO $pdo, array $row): ?string
{
    if (trim((string) ($row['user_agent'] ?? '')) !== '') { return trim((string) $row['user_agent']); }
    $s = $pdo->query('SELECT crawl_user_agent, business_name, business_contact_email FROM inv_settings WHERE id = 1')->fetch() ?: [];
    if (trim((string) ($s['crawl_user_agent'] ?? '')) !== '') { return trim((string) $s['crawl_user_agent']); }
    $name = trim((string) preg_replace('/[^A-Za-z0-9 \-]+/', '', (string) ($s['business_name'] ?? '')));
    $mail = trim((string) ($s['business_contact_email'] ?? ''));
    if ($name !== '' && $mail !== '') {
        return $name . ' InventoryBot/1.0 (+mailto:' . $mail . '; MaluDB Inventory)';
    }
    return null;
}

/**
 * The `$source` array of §1.2 from the row (§6.2) — THE ONE PLACE a credential is opened (inv_open()); InvCredentialError when the seal does
 * not open. The page caps from inv_settings.crawl_max_pages when the source's settings are silent.
 */
function inv_source_for_connector(PDO $pdo, int $sourceId): array
{
    $st = $pdo->prepare('SELECT * FROM sources WHERE id = :id');
    $st->execute(['id' => $sourceId]);
    $row = $st->fetch() ?: throw new InvMisconfigured('No such source');
    $settings = json_decode((string) $row['settings'], true) ?: [];
    $credential = null;
    if ($row['credential_id'] !== null) {
        $c = $pdo->prepare("SELECT convert_from(ciphertext, 'UTF8') FROM source_credentials WHERE id = :c AND source_id = :s");
        $c->execute(['c' => $row['credential_id'], 's' => $sourceId]);
        $sealed = $c->fetchColumn();
        if ($sealed === false) { throw new InvCredentialError('credential_unreadable: missing'); }
        $credential = inv_open((string) $sealed);
    }
    $pages = (int) ($pdo->query('SELECT crawl_max_pages FROM inv_settings WHERE id = 1')->fetchColumn() ?: 500);
    if ($row['connector'] === 'shopify' && !isset($settings['max_products'])) { $settings['max_products'] = $pages * 250; }
    if ($row['connector'] === 'woocommerce' && !isset($settings['max_products'])) { $settings['max_products'] = $pages * 100; }
    if ($row['connector'] === 'jsonld' && !isset($settings['max_pages'])) { $settings['max_pages'] = $pages; }
    return [
        'id' => (int) $row['id'],
        'connector' => $row['connector'],
        'base_url' => $row['base_url'],
        'settings' => $settings,
        'credential' => $credential,
        'rate_per_second' => (float) $row['rate_per_second'],
        'user_agent' => inv_source_user_agent($pdo, $row),
        'timeout' => 20,
        'cache_dir' => inv_pull_cache_dir(),
    ];
}

/** The client's facts → source_pulls.policy (§6.3 step 6): a scalar robots, the hosts' map, only the requests that were not ok (≤ 50), the connector's extras, the errors. */
function inv_pull_policy(array $stats, string $baseHost): array
{
    $facts = (array) ($stats['policy'] ?? []);
    $hosts = (array) ($facts['robots'] ?? []);
    $policy = [];
    $states = array_map(static fn ($h) => is_array($h) ? (string) ($h['state'] ?? '') : (string) $h, $hosts);
    if (in_array('blocked', $states, true)) {
        $policy['robots'] = 'blocked';
    } elseif (isset($states[$baseHost]) && in_array($states[$baseHost], ['ok', 'none'], true)) {
        $policy['robots'] = 'ok';
    }
    $policy['crawl_delay'] = is_array($hosts[$baseHost] ?? null) ? ($hosts[$baseHost]['crawl_delay'] ?? null) : null;
    foreach (['user_agent', 'rate_per_second', 'proxy'] as $k) { $policy[$k] = $facts[$k] ?? null; }
    $policy['hosts'] = $hosts;
    foreach (['http_requests', 'bytes', 'cached', 'blocked', 'robots_skipped'] as $k) { $policy[$k] = $facts[$k] ?? ($stats[$k] ?? 0); }
    $requests = (array) ($facts['requests'] ?? []);
    $notOk = array_values(array_filter($requests, static function ($r): bool {
        $s = (int) ($r['status'] ?? 0);
        return !empty($r['skipped']) || !empty($r['blocked']) || !empty($r['reason']) || $s < 200 || $s >= 300;
    }));
    $policy['requests'] = array_slice($notOk, 0, 50);
    $policy['requests_total'] = count($requests);
    $extra = array_diff_key($stats, array_flip(['status', 'listings_seen', 'http_requests', 'bytes', 'cached', 'errors', 'policy']));
    $policy['connector'] = $extra;
    $policy['errors'] = array_slice(array_map(static fn ($e): string => mb_substr((string) $e, 0, 200), (array) ($stats['errors'] ?? [])), 0, 20);
    return $policy;
}

/** The matcher's proposals after an ok or partial pull (§6.3 step 7): every live unmatched listing variant of the source with no proposal row at all. */
function inv_propose_after_pull(PDO $pdo, int $sourceId, int $limit): int
{
    $st = $pdo->prepare('SELECT COALESCE(sum(inv_propose_matches(x.id, NULL)), 0) FROM (
            SELECT lv.id FROM listing_variants lv JOIN listings l ON l.id = lv.listing_id
             WHERE l.source_id = :s AND lv.variant_id IS NULL AND lv.removed_at IS NULL AND l.forgotten_at IS NULL
               AND NOT EXISTS (SELECT 1 FROM match_proposals mp WHERE mp.listing_variant_id = lv.id)
             ORDER BY lv.id DESC LIMIT ' . max(1, $limit) . ') x');
    $st->execute(['s' => $sourceId]);
    return (int) $st->fetchColumn();
}

/** The Buyer's notice on a failed or blocked pull (§6.3 step 9): once per rung of the ladder by the dedupe key. */
function inv_pull_notify(PDO $pdo, array $sourceBefore, array $sourceAfter, array $pull): void
{
    if (!in_array($pull['status'], ['failed', 'blocked'], true)) { return; }
    $to = $pdo->query('SELECT buyer_member_id FROM inv_settings WHERE id = 1')->fetchColumn() ?: ($sourceAfter['created_by'] ?? null);
    if ($to === null || $to === false) { return; }
    $n = (int) $sourceAfter['consecutive_failures'];
    // DECISION (slice 3): the key names the streak too — its first failed pull — so a source resumed and failing again is told again; the spec's
    // pull_failed:<source>:<rung> alone would have silenced every later streak's first and second rungs forever (the outbox remembers the key).
    $streak = (int) (one_value($pdo, "SELECT id FROM source_pulls WHERE source_id = :s AND status IN ('failed', 'blocked') ORDER BY id DESC OFFSET " . max(0, $n - 1) . ' LIMIT 1', ['s' => (int) $sourceAfter['id']]) ?? $pull['id']);
    $then = $sourceAfter['paused_at'] !== null ? 'paused — resume it from the source page'
        : ($sourceAfter['backoff_until'] !== null ? 'backing off until ' . date('M j H:i', (int) strtotime((string) $sourceAfter['backoff_until'])) . ' UTC' : '');
    $body = trim(((string) ($pull['error'] ?? $pull['status'])) . ($then !== '' ? ' — ' . $then : ''));
    $pdo->prepare('SELECT inv_notify(:m, :k, :rt, :rid, :t, :b, :d, false)')->execute([
        'm' => (int) $to, 'k' => $pull['status'] === 'blocked' ? 'pull_blocked' : 'pull_failed', 'rt' => 'source', 'rid' => (int) $sourceAfter['id'],
        't' => $sourceAfter['name'] . ': pull ' . $pull['status'], 'b' => mb_substr($body, 0, 1000), 'd' => 'pull_failed:' . (int) $sourceAfter['id'] . ':' . $n . ':' . $streak,
    ]);
}

/** Delete cached bodies and metas older than $days, and lock files untouched as long (§6.7). */
function inv_http_cache_prune(string $dir, int $days = 30): int
{
    if (!is_dir($dir)) { return 0; }
    $cut = time() - $days * 86400;
    $n = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = $f->getPathname();
        if (str_ends_with($p, '.meta.json')) {
            $meta = json_decode((string) @file_get_contents($p), true);
            $stored = (int) ($meta['stored_at'] ?? $f->getMTime());
            if ($stored < $cut) {
                @unlink($p);
                @unlink(substr($p, 0, -strlen('.meta.json')) . '.body');
                $n++;
            }
        } elseif (str_ends_with($p, '.lock') && $f->getMTime() < $cut) {
            @unlink($p);
            $n++;
        }
    }
    return $n;
}

/** The emitter (§6.3 step 3): one transaction per listing; never throws. */
function inv_pull_emitter(PDO $pdo, int $sourceId, int $pullId, int &$written, array &$errors, array &$ids): Closure
{
    return function (array $listing) use ($pdo, $sourceId, $pullId, &$written, &$errors, &$ids): void {
        $why = '';
        if (!inv_listing_valid($listing, $why)) { $errors[] = mb_substr('listing ' . ($listing['external_id'] ?? '?') . ': ' . $why, 0, 200); return; }
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare('SELECT inv_upsert_listing(:s, :p, CAST(:j AS jsonb))');
            $st->execute(['s' => $sourceId, 'p' => $pullId, 'j' => json_encode($listing, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            $ids[] = (int) $st->fetchColumn();
            $pdo->commit();
            $written++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $errors[] = mb_substr($listing['external_id'] . ': ' . db_message($e, 'could not be written'), 0, 200);
        }
    };
}

/** "first error (+N more)" ≤ 200. */
function inv_pull_error_text(array $errors): ?string
{
    if ($errors === []) { return null; }
    $t = (string) $errors[0] . (count($errors) > 1 ? ' (+' . (count($errors) - 1) . ' more)' : '');
    return mb_substr($t, 0, 200);
}

/**
 * One pull end to end (§6.3). $pullId given = a queued on-demand pull (§6.4): step 1 is skipped and its policy replaced at finish. Returns the
 * finished source_pulls row + ['connector_status', 'emit_errors', 'proposals'].
 */
function inv_run_pull(PDO $pdo, int $sourceId, string $kind, ?int $by, ?string $query = null, ?int $pullId = null): array
{
    // 1. start
    if ($pullId === null) {
        $st = $pdo->prepare('SELECT inv_source_pull_start(:s, :k, :b, :q)');
        $st->execute(['s' => $sourceId, 'k' => $kind, 'b' => $by, 'q' => $query]);
        $pullId = (int) $st->fetchColumn();
        log_activity($pdo, 'source.pull_start', 'source_pull', $pullId, ['source_id' => $sourceId, 'after' => ['pull_id' => $pullId, 'kind' => $kind]]);
    }
    $before = $pdo->query('SELECT * FROM sources WHERE id = ' . $sourceId)->fetch();
    $pullStart = (string) $pdo->query('SELECT started_at FROM source_pulls WHERE id = ' . $pullId)->fetchColumn();
    $written = 0;
    $emitErrors = [];
    $ids = [];
    $stats = null;
    $connectorStatus = 'failed';
    // 2. build
    try {
        $source = inv_source_for_connector($pdo, $sourceId);
        $c = inv_connector((string) $before['connector']);
    } catch (InvCredentialError) {
        $stats = ['status' => 'failed', 'errors' => ['the credential cannot be opened — set it again'], 'http_requests' => 0, 'bytes' => 0, 'policy' => []];
    } catch (InvMisconfigured $e) {
        $stats = ['status' => 'failed', 'errors' => [mb_substr($e->getMessage(), 0, 200)], 'http_requests' => 0, 'bytes' => 0, 'policy' => []];
    }
    // 3. pull
    if ($stats === null) {
        $emit = inv_pull_emitter($pdo, $sourceId, $pullId, $written, $emitErrors, $ids);
        try {
            $stats = $c->pull($source, $emit);
        } catch (Throwable $e) {
            $stats = ['status' => $written > 0 ? 'partial' : 'failed', 'errors' => [mb_substr($e->getMessage(), 0, 200)], 'http_requests' => 0, 'bytes' => 0, 'policy' => []];
        }
    }
    $connectorStatus = (string) ($stats['status'] ?? 'failed');
    // 4. status, downgraded by emit errors
    $status = $connectorStatus;
    if ($status === 'ok' && $emitErrors !== []) { $status = $written > 0 ? 'partial' : 'failed'; }
    if ($status === 'partial' && $written === 0 && $emitErrors !== []) { $status = 'failed'; }
    $error = inv_pull_error_text(array_merge((array) ($stats['errors'] ?? []), $emitErrors));
    // 5. removals — only after a full read of a scheduled or manual pull
    if ($connectorStatus === 'ok' && $status === 'ok' && in_array($kind, ['scheduled', 'manual'], true)) {
        $pdo->prepare('SELECT inv_mark_removed(:s, :p)')->execute(['s' => $sourceId, 'p' => $pullId]);
    }
    // 6. finish
    $host = strtolower((string) (parse_url((string) ($before['base_url'] ?? ''), PHP_URL_HOST) ?? ''));     // the client keys robots by host, no port
    $stats['errors'] = array_merge((array) ($stats['errors'] ?? []), $emitErrors);
    $policy = inv_pull_policy($stats, $host);
    $st = $pdo->prepare('SELECT * FROM inv_source_pull_finish(:p, :s, :e, CAST(:pol AS jsonb), :r, :b)');
    $st->execute(['p' => $pullId, 's' => $status, 'e' => $error, 'pol' => json_encode($policy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'r' => (int) ($stats['http_requests'] ?? 0), 'b' => (int) ($stats['bytes'] ?? 0)]);
    $after = $st->fetch();
    // 7. proposals
    $proposals = in_array($status, ['ok', 'partial'], true) && $kind !== 'probe' ? inv_propose_after_pull($pdo, $sourceId, 200) : 0;
    $pull = $pdo->query('SELECT * FROM source_pulls WHERE id = ' . $pullId)->fetch();
    // 8. log: the listings and offers first (≤ 500 rows), then the pull
    inv_pull_log_changes($pdo, $sourceId, $pullId, $pullStart);
    $event = match ($status) { 'ok', 'partial' => 'source.pull_done', 'blocked' => 'source.pull_blocked', default => 'source.pull_fail' };
    log_activity($pdo, $event, 'source_pull', $pullId, ['source_id' => $sourceId, 'after' => [
        'status' => $status, 'kind' => $pull['kind'], 'listings_seen' => (int) $pull['listings_seen'], 'listings_new' => (int) $pull['listings_new'], 'listings_changed' => (int) $pull['listings_changed'],
        'variants_changed' => (int) $pull['variants_changed'], 'listings_removed' => (int) $pull['listings_removed'], 'http_requests' => (int) $pull['http_requests'], 'bytes' => (int) $pull['bytes'],
        'cached' => (int) ($policy['cached'] ?? 0), 'blocked' => (int) ($policy['blocked'] ?? 0), 'robots' => $policy['robots'] ?? null, 'error' => $error, 'proposals' => $proposals,
        'consecutive_failures' => (int) $after['consecutive_failures'], 'paused' => $after['paused_at'] !== null]]);
    // 9. notify
    inv_pull_notify($pdo, $before, $after, $pull);
    return $pull + ['connector_status' => $connectorStatus, 'emit_errors' => $emitErrors, 'proposals' => $proposals, 'listing_ids' => $ids];
}

/** listing.new|removed and offer.change from the pull's own rows (≤ 500 rows a pull; beyond that one offer.change with the count) — never a value. */
function inv_pull_log_changes(PDO $pdo, int $sourceId, int $pullId, string $pullStart): void
{
    $budget = 500;
    $st = $pdo->prepare('SELECT id, external_id, left(title, 200) AS title FROM listings WHERE source_id = :s AND first_seen_at >= CAST(:t AS timestamptz) ORDER BY id LIMIT 501');
    $st->execute(['s' => $sourceId, 't' => $pullStart]);
    foreach ($st->fetchAll() as $l) {
        if ($budget-- <= 0) { break; }
        log_activity($pdo, 'listing.new', 'listing', (int) $l['id'], ['source_id' => $sourceId, 'after' => ['pull_id' => $pullId, 'external_id' => $l['external_id'], 'title' => $l['title']]]);
    }
    $st = $pdo->prepare('SELECT id, external_id FROM listings WHERE source_id = :s AND removed_at >= CAST(:t AS timestamptz) ORDER BY id LIMIT 501');
    $st->execute(['s' => $sourceId, 't' => $pullStart]);
    foreach ($st->fetchAll() as $l) {
        if ($budget-- <= 0) { break; }
        log_activity($pdo, 'listing.removed', 'listing', (int) $l['id'], ['source_id' => $sourceId, 'after' => ['pull_id' => $pullId, 'external_id' => $l['external_id']]]);
    }
    $st = $pdo->prepare('SELECT o.listing_variant_id, lv.listing_id,
            (o.price IS DISTINCT FROM p.price) AS price, (o.compare_at_price IS DISTINCT FROM p.compare_at_price) AS compare_at_price,
            (o.cost_price IS DISTINCT FROM p.cost_price) AS cost_price, (o.availability IS DISTINCT FROM p.availability) AS availability,
            (o.qty IS DISTINCT FROM p.qty) AS qty, (o.lead_time_days IS DISTINCT FROM p.lead_time_days) AS lead_time_days
          FROM offer_snapshots o JOIN listing_variants lv ON lv.id = o.listing_variant_id
          JOIN LATERAL (SELECT * FROM offer_snapshots q WHERE q.listing_variant_id = o.listing_variant_id AND q.id < o.id ORDER BY q.id DESC LIMIT 1) p ON true
         WHERE o.pull_id = :p ORDER BY o.id');
    $st->execute(['p' => $pullId]);
    $rows = $st->fetchAll();
    $over = 0;
    foreach ($rows as $r) {
        if ($budget-- <= 0) { $over++; continue; }
        $fields = array_keys(array_filter(['price' => $r['price'], 'compare_at_price' => $r['compare_at_price'], 'cost_price' => $r['cost_price'], 'availability' => $r['availability'], 'qty' => $r['qty'], 'lead_time_days' => $r['lead_time_days']]));
        log_activity($pdo, 'offer.change', 'listing_variant', (int) $r['listing_variant_id'], ['source_id' => $sourceId, 'after' => ['pull_id' => $pullId, 'listing_id' => (int) $r['listing_id'], 'fields' => $fields]]);
    }
    if ($over > 0) {
        log_activity($pdo, 'offer.change', 'source_pull', $pullId, ['source_id' => $sourceId, 'after' => ['pull_id' => $pullId, 'count' => $over]]);
    }
}

/**
 * The live search (§6.6): a source whose connector searches, active, not paused, not backing off; a `search` pull written through the same
 * emitter (the match, the snapshot and the price sheet happen); never counts for removals. Not searchable / refused → ok false with the last
 * pull's matching listings. $persist false (an eval run): the connector is asked, nothing is written, no pull row.
 */
function inv_source_search_live(PDO $pdo, int $sourceId, string $q, int $limit = 20, ?int $by = null, bool $persist = true, ?string $size = null): array
{
    $t0 = microtime(true);
    $res = inv_source_search_live_run($pdo, $sourceId, $q, $limit, $by, $persist);
    // logged by the function (find.md): every live ask, from a screen, the command bar or the bridge — the counts, never a listing
    $pull = isset($res['pull_id']) ? $pdo->query('SELECT listings_seen, listings_new FROM source_pulls WHERE id = ' . (int) $res['pull_id'])->fetch() : null;
    log_activity($pdo, 'source.search', 'source', $sourceId, ['source_id' => $sourceId, 'after' => ['pull_id' => $res['pull_id'] ?? null, 'query' => mb_substr($q, 0, 120), 'size' => $size,
        'status' => $res['ok'] ? 'ok' : ($res['reason'] ?? 'failed'), 'found' => count($res['listings'] ?? []), 'listings_seen' => (int) ($pull['listings_seen'] ?? count($res['listings'] ?? [])),
        'listings_new' => (int) ($pull['listings_new'] ?? 0), 'ms' => (int) round((microtime(true) - $t0) * 1000), 'eval' => !$persist]]);
    return $res;
}

/** The live search's work (inv_source_search_live() logs around it). */
function inv_source_search_live_run(PDO $pdo, int $sourceId, string $q, int $limit, ?int $by, bool $persist): array
{
    $row = $pdo->query('SELECT * FROM sources WHERE id = ' . $sourceId)->fetch();
    if ($row === false) { return ['ok' => false, 'reason' => 'no such source', 'listings' => []]; }
    $last = static function () use ($pdo, $sourceId, $q): array {
        $st = $pdo->prepare("SELECT l.id, l.title, l.url FROM listings l WHERE l.source_id = :s AND l.removed_at IS NULL AND l.forgotten_at IS NULL
                               AND (l.title ILIKE :q OR EXISTS (SELECT 1 FROM listing_variants lv WHERE lv.listing_id = l.id AND (lv.sku ILIKE :q2 OR lv.barcode = :raw))) ORDER BY l.last_seen_at DESC LIMIT 20");
        $st->execute(['s' => $sourceId, 'q' => '%' . $q . '%', 'q2' => '%' . $q . '%', 'raw' => $q]);
        return $st->fetchAll();
    };
    try {
        $c = inv_connector((string) $row['connector']);
    } catch (InvMisconfigured $e) {
        return ['ok' => false, 'reason' => $e->getMessage(), 'listings' => $last()];
    }
    if (empty($c->capabilities()['has_search'])) { return ['ok' => false, 'reason' => 'no live search', 'listings' => $last()]; }
    if (!$row['active'] || $row['paused_at'] !== null) { return ['ok' => false, 'reason' => 'paused', 'listings' => $last()]; }
    if ($row['backoff_until'] !== null && strtotime((string) $row['backoff_until']) > time()) { return ['ok' => false, 'reason' => 'backing off', 'listings' => $last()]; }
    try {
        $source = inv_source_for_connector($pdo, $sourceId);
    } catch (Throwable $e) {
        return ['ok' => false, 'reason' => $e instanceof InvCredentialError ? 'the credential cannot be opened — set it again' : $e->getMessage(), 'listings' => $last()];
    }
    $source['timeout'] = 10;
    if (!$persist) {
        try {
            return ['ok' => true, 'pull_id' => null, 'listings' => $c->search($source, $q, $limit), 'listing_ids' => [], 'eval' => true];
        } catch (InvNotSupported | InvMisconfigured $e) {
            return ['ok' => false, 'reason' => $e->getMessage(), 'listings' => $last()];
        }
    }
    try {
        $st = $pdo->prepare("SELECT inv_source_pull_start(:s, 'search', :b, :q)");
        $st->execute(['s' => $sourceId, 'b' => $by, 'q' => mb_substr($q, 0, 120)]);
        $pullId = (int) $st->fetchColumn();
    } catch (PDOException) {
        return ['ok' => false, 'reason' => 'a pull is running', 'listings' => $last()];
    }
    $written = 0; $errors = []; $ids = [];
    $emit = inv_pull_emitter($pdo, $sourceId, $pullId, $written, $errors, $ids);
    $found = [];
    $status = 'ok';
    $message = null;
    try {
        $found = $c->search($source, $q, $limit);
        foreach ($found as $l) { $emit($l); }
        if ($errors !== []) { $status = $written > 0 ? 'partial' : 'failed'; $message = inv_pull_error_text($errors); }
    } catch (InvNotSupported | InvMisconfigured $e) {
        $status = str_contains($e->getMessage(), 'blocked') ? 'blocked' : 'failed';
        $message = mb_substr($e->getMessage(), 0, 200);
    }
    $pdo->prepare('SELECT inv_source_pull_finish(:p, :s, :e, CAST(:pol AS jsonb), NULL, NULL)')
        ->execute(['p' => $pullId, 's' => $status, 'e' => $message, 'pol' => json_encode(['query' => mb_substr($q, 0, 120), 'found' => count($found), 'errors' => $errors])]);
    if ($status === 'failed' || $status === 'blocked') {
        return ['ok' => false, 'reason' => $message ?? $status, 'pull_id' => $pullId, 'listings' => $last()];
    }
    return ['ok' => true, 'pull_id' => $pullId, 'listings' => $found, 'listing_ids' => $ids];
}

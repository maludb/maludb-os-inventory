<?php
declare(strict_types=1);

/** The dispatch list (screen `dispatch-list`): mcp_agent_dispatches (the admin and the Buyer see every one; the asker and the agent their own) with the asker's name. */

const DISPATCH_STATUSES = ['sent' => 'Pending', 'answered' => 'Answered', 'awaiting_approval' => 'Awaiting approval', 'refused' => 'Refused', 'failed' => 'Failed'];
const DISPATCH_PAGE = 50;

function dispatches_where(array $f, array &$args): string
{
    $sql = '';
    $status = (string) ($f['status'] ?? '');
    if ($status !== '' && isset(DISPATCH_STATUSES[$status])) { $sql .= ' AND d.status = :status'; $args['status'] = $status; }
    if (!empty($f['agent'])) { $sql .= ' AND d.agent_member_id = :agent'; $args['agent'] = (int) $f['agent']; }
    return $sql;
}

/** [rows, total, pages] of the dispatches, newest first. */
function find_dispatches(PDO $pdo, array $f, int $page = 1): array
{
    $args = [];
    $where = dispatches_where($f, $args);
    $t = $pdo->prepare('SELECT count(*) FROM mcp_agent_dispatches d WHERE true' . $where);
    $t->execute($args);
    $total = (int) $t->fetchColumn();
    $st = $pdo->prepare('SELECT d.dispatch_id, d.record_type, d.record_id, d.agent_member_id, d.agent_name, d.kind, d.via, d.acting_member_id, a.display_name AS asker_name, d.run_id, d.status, d.reply_excerpt, d.detail,
                                d.attempts, d.created_at, d.answered_at, d.watch_id, d.listing_variant_id
                           FROM mcp_agent_dispatches d LEFT JOIN mcp_members a ON a.member_id = d.acting_member_id WHERE true' . $where
        . ' ORDER BY d.created_at DESC, d.dispatch_id DESC LIMIT ' . DISPATCH_PAGE . ' OFFSET ' . (max(1, $page) - 1) * DISPATCH_PAGE);
    $st->execute($args);
    return ['rows' => $st->fetchAll(), 'total' => $total, 'page' => max(1, $page), 'pages' => max(1, (int) ceil($total / DISPATCH_PAGE))];
}

function find_dispatch(PDO $pdo, int $id): ?array
{
    $r = one_row($pdo, 'SELECT d.dispatch_id, d.agent_member_id, d.agent_name, d.kind, d.via, d.acting_member_id, d.run_id, d.status, d.reply_excerpt, d.detail, d.attempts, d.created_at, d.answered_at, d.watch_id
                          FROM mcp_agent_dispatches d WHERE d.dispatch_id = :id', ['id' => $id]);
    return $r;
}

/** The agents that have been handed something (the filter). [{member_id, display_name}] */
function dispatch_agents(PDO $pdo): array
{
    return $pdo->query('SELECT DISTINCT d.agent_member_id AS member_id, d.agent_name AS display_name FROM mcp_agent_dispatches d ORDER BY 2')->fetchAll();
}

/** A failed or refused dispatch goes back to sent with no run: due at once (the backoff clock reads created_at + 2^attempts and the retry forgives it by setting attempts to 1 — the failure history stays in the log). */
function retry_dispatch(PDO $pdo, int $id, int $by): void
{
    $st = $pdo->prepare("UPDATE agent_dispatches SET status = 'sent', run_id = NULL, detail = NULL, answered_at = NULL, attempts = 1 WHERE id = :id AND status IN ('failed', 'refused')");
    $st->execute(['id' => $id]);
    if ($st->rowCount() !== 1) { throw new DomainException('Only a failed dispatch is retried.'); }
}

// ---- the agents here (screen `agent-list`, slice 9) --------------------------------------------------------------------------------------------

/** The agents maludb-os.json declares (key, name, duty {name, schedule_cron, runbook}, skills[] by name). */
function declared_agents(): array
{
    $m = json_decode((string) @file_get_contents(dirname(__DIR__, 3) . '/maludb-os.json'), true) ?: [];
    $out = [];
    foreach ((array) ($m['agents'] ?? []) as $a) {
        $out[] = ['key' => (string) $a['key'], 'name' => (string) $a['name'], 'duty' => isset($a['duty']) ? ['name' => (string) ($a['duty']['name'] ?? ''), 'schedule_cron' => (string) ($a['duty']['schedule_cron'] ?? '')] : null,
                  'skills' => array_values(array_map(static fn ($s): string => basename((string) $s), (array) ($a['skills'] ?? []))), 'roles' => array_values((array) ($a['roles'] ?? []))];
    }
    return $out;
}

/**
 * The agents here, from what this application can see (DECISION 8: the kernel is asked nothing): every declared agent matched to a mirror agent — by display_name (case-insensitive, the mirror's name may carry a prefix), else by job_title —
 * then every other mirror agent holding a role here. Each {member_id, display_name, job_title, declared_key, declared_name, roles, hired, duty, skills, last_action: {action, occurred_at, sentence}|null,
 * last_dispatch: {status, created_at}|null, dispatches_pending, dispatches_failed, proposals_today}.
 */
function agents_here(PDO $pdo): array
{
    $mirror = $pdo->query("SELECT member_id, display_name, job_title, roles, status FROM mcp_members WHERE is_agent ORDER BY display_name, member_id")->fetchAll();
    $today = business_today($pdo);
    $detail = static function (array $m) use ($pdo, $today): array {
        $id = (int) $m['member_id'];
        $la = $pdo->prepare('SELECT activity_id, occurred_at, actor_member_id, actor_name, actor_is_agent, source, action, screen, entity_type, entity_id, after, source_id, sales_order_id, purchase_order_id, location_id, token_id
                               FROM mcp_activity_log WHERE actor_member_id = :m AND action <> \'screen.view\' ORDER BY occurred_at DESC, activity_id DESC LIMIT 1');
        $la->execute(['m' => $id]);
        $row = $la->fetch();
        $ld = $pdo->prepare('SELECT status, created_at, run_id FROM mcp_agent_dispatches WHERE agent_member_id = :m ORDER BY created_at DESC, dispatch_id DESC LIMIT 1');
        $ld->execute(['m' => $id]);
        $disp = $ld->fetch();
        $c = one_row($pdo, "SELECT count(*) FILTER (WHERE status IN ('sent', 'awaiting_approval')) AS pending, count(*) FILTER (WHERE status = 'failed') AS failed FROM mcp_agent_dispatches WHERE agent_member_id = :m", ['m' => $id]) ?? ['pending' => 0, 'failed' => 0];
        $p = (int) one_value($pdo, 'SELECT count(*) FROM mcp_buyer_proposals WHERE proposed_by = :m AND note_date = CAST(:d AS date)', ['m' => $id, 'd' => $today]);
        return [
            'last_action' => $row === false ? null : ['action' => $row['action'], 'occurred_at' => $row['occurred_at'], 'sentence' => activity_sentence($row)],
            'last_dispatch' => $disp === false ? null : ['status' => $disp['status'], 'created_at' => $disp['created_at']],
            'dispatches_pending' => (int) $c['pending'], 'dispatches_failed' => (int) $c['failed'], 'proposals_today' => $p,
        ];
    };
    $out = [];
    $taken = [];
    foreach (declared_agents() as $d) {
        $match = null;
        $n = mb_strtolower($d['name']);
        foreach ($mirror as $m) {
            if (isset($taken[$m['member_id']])) { continue; }
            if (mb_strtolower((string) $m['display_name']) === $n) { $match = $m; break; }
        }
        if ($match === null) {
            foreach ($mirror as $m) { if (!isset($taken[$m['member_id']]) && str_contains(mb_strtolower((string) $m['display_name']), $n)) { $match = $m; break; } }
        }
        if ($match === null) {
            foreach ($mirror as $m) { if (!isset($taken[$m['member_id']]) && mb_strtolower((string) ($m['job_title'] ?? '')) === $n) { $match = $m; break; } }
        }
        $row = ['member_id' => $match === null ? null : (int) $match['member_id'], 'display_name' => $match['display_name'] ?? $d['name'], 'job_title' => $match['job_title'] ?? null, 'declared_key' => $d['key'], 'declared_name' => $d['name'],
                'roles' => $match === null ? [] : pg_text_array((string) $match['roles']), 'hired' => $match !== null && $match['status'] === 'active' && pg_text_array((string) $match['roles']) !== [],
                'duty' => $d['duty'], 'skills' => $d['skills'], 'declared' => true];
        if ($match !== null) { $taken[$match['member_id']] = true; $row += $detail($match); } else { $row += ['last_action' => null, 'last_dispatch' => null, 'dispatches_pending' => 0, 'dispatches_failed' => 0, 'proposals_today' => 0]; }
        $out[] = $row;
    }
    foreach ($mirror as $m) {
        if (isset($taken[$m['member_id']]) || $m['status'] !== 'active' || pg_text_array((string) $m['roles']) === []) { continue; }
        $out[] = ['member_id' => (int) $m['member_id'], 'display_name' => $m['display_name'], 'job_title' => $m['job_title'], 'declared_key' => null, 'declared_name' => null, 'roles' => pg_text_array((string) $m['roles']), 'hired' => true,
                  'duty' => null, 'skills' => [], 'declared' => false] + $detail($m);
    }
    return $out;
}

/** The OS's pages for an agent: OS_LAUNCHER_URL's host with `app.` turned into `os.` — null when it names no app. host. [{label, url}] */
function os_agent_links(): array
{
    $p = parse_url((string) env('OS_LAUNCHER_URL', ''));
    if ($p === false || empty($p['host']) || !str_starts_with((string) $p['host'], 'app.')) { return []; }
    $base = ($p['scheme'] ?? 'https') . '://os.' . substr((string) $p['host'], 4) . (isset($p['port']) ? ':' . $p['port'] : '');
    return [['label' => 'The agents in the OS', 'url' => $base . '/agents'], ['label' => 'Agent runs', 'url' => $base . '/ai/runs']];
}

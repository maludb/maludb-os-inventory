<?php
declare(strict_types=1);

/**
 * The Inventory worker — one pass a minute from inventory-worker.timer (design §14): the due pulls by schedule and rate, the
 * heartbeat snapshots and removals, the watches, the outbox (email through MaluMail, texts through the kernel's K6), dispatches to
 * agents and their retries, link expiry, key-usage pruning. Idempotent; advisory-locked so two runs never overlap; each pass its own
 * try (one failing never stops the next); the report is printed as one JSON line and logged once as `worker.pass` (counts and
 * sentences, never a body, a credential or a raw object).
 *   php bin/worker.php [--passes=pulls,snapshots_heartbeat,watches,outbox,dispatches,links_expire,key_usage_prune] [--limit=200]
 * With --passes the named passes run whether or not they are due; without it every pass runs (each decides its own rhythm from the
 * settings once its slice builds it). INV_WORKER_NOW (ISO 8601) replaces the clock a pass selects by; honoured only when APP_ENV is
 * not prod. Exits 1 when a pass failed.
 *
 * PHASE 0: every pass is a STUB that changes nothing and answers 0 — the slice that owns it fills it in (design §10): `pulls` is
 * slice 3's (the connectors under app/sources/ — another builder's), `snapshots_heartbeat` and `watches` slice 4's, `outbox` and
 * `dispatches` slice 8's, `links_expire` slices 5 and 6's, `key_usage_prune` slice 9's.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }
require_once dirname(__DIR__) . '/app/bootstrap.php';

/** The passes, in the order they run. A new pass is a name here and a function worker_pass_<name>(PDO, int $limit, DateTimeImmutable $now): array. */
const WORKER_PASSES = ['pulls', 'snapshots_heartbeat', 'watches', 'outbox', 'dispatches', 'links_expire', 'key_usage_prune'];

$only = [];
$limit = 200;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--passes=')) {
        $only = array_values(array_filter(explode(',', substr($a, 9))));
        if (array_diff($only, WORKER_PASSES) !== []) {
            fwrite(STDERR, 'Unknown pass: ' . implode(', ', array_diff($only, WORKER_PASSES)) . '. Passes: ' . implode(', ', WORKER_PASSES) . ".\n");
            exit(2);
        }
    } elseif (str_starts_with($a, '--limit=')) {
        $limit = max(1, min(1000, (int) substr($a, 8)));
    } else {
        fwrite(STDERR, "Usage: php bin/worker.php [--passes=a,b] [--limit=N]\n");
        exit(2);
    }
}
$now = new DateTimeImmutable();
if (($fake = getenv('INV_WORKER_NOW')) !== false && $fake !== '' && app_is_prod() === false) {
    $now = new DateTimeImmutable($fake);
}
$pdo = db();
if (!(bool) $pdo->query("SELECT pg_try_advisory_lock(hashtext('inventory_worker'))")->fetchColumn()) {
    echo json_encode(['skipped' => 'another pass holds the lock']) . "\n";
    exit(0);
}
$GLOBALS['__public_door'] = 'cron';                 // every row a pass writes is source cron, actor none
$started = microtime(true);
$report = ['at' => $now->format(DATE_ATOM), 'ran' => [], 'errors' => []];
foreach ($only === [] ? WORKER_PASSES : $only as $pass) {
    try {
        $report['ran'][$pass] = ('worker_pass_' . $pass)($pdo, $limit, $now);
    } catch (Throwable $e) {
        error_log('worker ' . $pass . ': ' . $e->getMessage());
        $report['errors'][] = $pass . ': ' . mb_substr($e->getMessage(), 0, 200);
    }
}
$report['seconds'] = round(microtime(true) - $started, 2);
log_activity($pdo, 'worker.pass', null, null, ['source' => 'cron', 'actor_member_id' => null, 'after' => $report]);
echo json_encode($report, JSON_UNESCAPED_SLASHES) . "\n";
$pdo->query("SELECT pg_advisory_unlock(hashtext('inventory_worker'))");
exit($report['errors'] === [] ? 0 : 1);

// ---- the passes (stubs until their slice) ------------------------------------------------------------------------------------------

/**
 * SLICE 3 HOOK — the due pulls: for each active source whose schedule says so (schedule_minutes > 0, last_ok_at + schedule <= now,
 * not paused, robots_state not blocked), one Connector::pull() through app/sources/ (another builder's — the worker never reads a site
 * itself), one transaction per listing, snapshots on change, the matcher on new listings, `source.pull_start|pull_done|pull_fail|
 * pull_blocked` logged with source_id. Until then: nothing.
 */
function worker_pass_pulls(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    // if (is_file(APP_ROOT . '/app/sources/pulls.php')) { require_once APP_ROOT . '/app/sources/pulls.php'; return sources_run_due_pulls($pdo, $limit, $now); }
    return ['sources' => 0, 'pulls' => 0, 'stub' => true];
}

/** SLICE 4 — a snapshot of every live listing variant unchanged for snapshot_heartbeat_days (the daily heartbeat) and the removals (last_seen_at older than two pulls). */
function worker_pass_snapshots_heartbeat(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    return ['snapshots' => 0, 'removed' => 0, 'stub' => true];
}

/** SLICE 4 — the watches: fire once per state change (back in stock, price below, cost below, MAP breach, lead time over, removed), queue the notice. */
function worker_pass_watches(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    return ['fired' => 0, 'stub' => true];
}

/** SLICE 8 — the outbox: email through MaluMail (malumail_send), texts to members through the kernel (kernel_send_text), retries with backoff. */
function worker_pass_outbox(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    return ['sent' => 0, 'skipped' => 0, 'retried' => 0, 'failed' => 0, 'stub' => true];
}

/** SLICE 8 — agent dispatches: a watch naming an agent, an ask, a duty proposal → one chat turn (ask_assistant), at most three attempts. */
function worker_pass_dispatches(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    return ['dispatched' => 0, 'retried' => 0, 'stub' => true];
}

/** SLICES 5 and 6 — the doors' links: a customer's order link dies 180 days after the order closes, a supplier's 90 days after the purchase order closes (settings). */
function worker_pass_links_expire(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    return ['expired' => 0, 'stub' => true];
}

/** SLICE 9 — the feed's key_usage rows older than the retention (settings) are pruned. */
function worker_pass_key_usage_prune(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    return ['pruned' => 0, 'stub' => true];
}

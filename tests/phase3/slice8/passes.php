<?php
/** The other passes and the driver (returns-worker.md "Proof": the other pass ≥ 10): key-usage pruning, idempotence, the lock, a pass that throws, the report row. The heartbeat's and links_expire's proofs are slice 4's and slice 5's. */
require __DIR__ . '/lib.php';
$w = returns_world();
kernel_clean();

echo "1. key_usage_prune\n";
$kid = (int) one("SELECT id FROM feed_keys WHERE label = 'S8 key'");
$old = (int) one("SELECT count(*) FROM key_usage WHERE token_id = :k AND bucket_kind = 'day' AND bucket_start < now() - interval '35 days'", ['k' => $kid]);
$oldMin = (int) one("SELECT count(*) FROM key_usage WHERE token_id = :k AND bucket_kind = 'minute' AND bucket_start < now() - interval '1 day' + interval '1 hour' - interval '1 hour'", ['k' => $kid]);
psql_exec("INSERT INTO key_usage (token_id, bucket_kind, bucket_start, calls) VALUES ($kid, 'minute', now() - interval '2 days', 4) ON CONFLICT DO NOTHING");
$r = wk('key_usage_prune');
ok($old === 1 && ($r['key_usage_prune']['pruned'] ?? 0) === 2 && $r['_']['exit'] === 0, 'the pass prunes the 40-day-old day bucket and the 2-day-old minute bucket: ' . json_encode($r['key_usage_prune'] ?? null));
ok((int) one("SELECT count(*) FROM key_usage WHERE token_id = :k", ['k' => $kid]) === 3 && (int) one("SELECT count(*) FROM key_usage WHERE token_id = :k AND bucket_kind = 'day' AND bucket_start = date_trunc('day', now())", ['k' => $kid]) === 1 && (int) one("SELECT count(*) FROM key_usage WHERE token_id = :k AND bucket_kind = 'minute' AND bucket_start >= date_trunc('minute', now()) - interval '1 minute'", ['k' => $kid]) === 1, 'today\'s day bucket and the current minute\'s are kept; the 3-hour-old minute bucket is within the day and stays');
ok(($r2 = wk('key_usage_prune'))['key_usage_prune']['pruned'] === 0, 'idempotent: a second pass prunes nothing');

echo "2. Every pass twice\n";
$a = wk('outbox,dispatches,key_usage_prune,links_expire');
$b = wk('outbox,dispatches,key_usage_prune,links_expire');
ok($b['outbox']['sent'] === 0 && $b['outbox']['failed'] === 0 && $b['dispatches']['called'] === 0 && $b['key_usage_prune']['pruned'] === 0 && $b['links_expire']['expired'] === 0, 'a second run of outbox, dispatches, key_usage_prune and links_expire finds nothing new: ' . json_encode($b['_']['ran']));

echo "3. The lock\n";
$lockPdo = new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', need('DB_HOST'), need('DB_PORT'), need('DB_NAME')), need('DB_USER'), need('DB_PASSWORD'));
$lockPdo->query("SELECT pg_advisory_lock(hashtext('inventory_worker'))");
$r = wk('outbox');
ok(($r['_']['skipped'] ?? '') === 'another pass holds the lock' && $r['_']['exit'] === 0, 'while another pass holds the advisory lock a run does nothing and says so');
$lockPdo->query("SELECT pg_advisory_unlock(hashtext('inventory_worker'))");
ok(isset(wk('outbox')['outbox']), 'and runs again once it is released');

echo "4. A pass that throws\n";
psql_exec('ALTER FUNCTION inv_fire_watches() RENAME TO inv_fire_watches_held');
$since = last_activity_id();
$r = wk('watches,key_usage_prune');
psql_exec('ALTER FUNCTION inv_fire_watches_held() RENAME TO inv_fire_watches');
ok($r['_']['exit'] === 1 && count($r['_']['errors']) === 1 && str_starts_with($r['_']['errors'][0], 'watches: ') && isset($r['key_usage_prune']) && !isset($r['watches']), 'a pass that throws lands in errors[] (its sentence), the next pass still ran, the exit code is 1');
$lg = last_log('worker.pass', $since); $a = after_of($lg);
ok($lg !== null && $lg['source'] === 'cron' && $lg['actor_member_id'] === null && isset($a['ran']['key_usage_prune']) && count($a['errors']) === 1 && isset($a['seconds']), 'worker.pass is logged once for the run (cron) with the counts, the errors and the seconds');
$since = last_activity_id();
wk('key_usage_prune');
ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'worker.pass' AND id > :s", ['s' => $since]) === 1 && !str_contains((string) last_log('worker.pass', $since)['after'], '@'), 'one worker.pass row per run, holding no address or body');
$out = shell_exec('php ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/worker.php') . ' --passes=nonsense 2>&1; echo "exit=$?"');
ok(str_contains((string) $out, 'Unknown pass: nonsense') && str_contains((string) $out, 'exit=2'), 'an unknown pass name is refused (exit 2)');
ok(wk('watches')['_']['exit'] === 0, 'with the function back the watches pass runs clean');
finish();

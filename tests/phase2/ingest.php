<?php
/**
 * Proof: the activity ingest bridge (mcp/activity_ingest.py) ships the log to the MaluDB API tagged "inventory" first, with the audit keys
 * (source_id, sales_order_id, purchase_order_id, location_id, token_id) where set, advances the checkpoint only past rows the API accepted,
 * stops on a rejected row, and health reads ingest_lag (sso-shell.md "Proof: ingest"). The MaluDB API is a fake (tests/fake_maludb.php) —
 * a scratch row must never reach the tenant's real memory. Needs the asyncpg/httpx venv of /srv/apps/projects (read only).
 */
require __DIR__ . '/lib.php';
$py = getenv('INV_PYTHON') ?: '/srv/apps/projects/mcp/venv/bin/python';
$log = need('FAKE_MALUDB_LOG');
$run = fn (string $env = '') => trim((string) shell_exec($env . ' ' . escapeshellarg($py) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/mcp/activity_ingest.py') . ' 2>&1; echo "exit=$?"'));
$state = fn () => (int) one('SELECT last_id FROM activity_ingest_state WHERE id = 1');
$max = fn () => (int) one('SELECT max(id) FROM activity_log');
$lines = fn () => array_values(array_filter(array_map(fn ($l) => json_decode($l, true), file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])));

file_put_contents($log, '');
// a source-scoped row with a sales-order key, as slice 3 and slice 5 will write them (no record exists yet: the keys are plain columns on activity_log)
pdo()->exec("INSERT INTO activity_log (actor_member_id, source, action, entity_type, entity_id, source_id, token_id, after) VALUES (40, 'web', 'listing.match', 'listing_variant', 77, 9, NULL, '{\"variant_id\": 12}'), (NULL, 'feed', 'feed.read', NULL, NULL, NULL, 3, '{\"label\": \"SMOKE website key\", \"rows\": 4}')");   // the log is append-only for inventory_rw
$h = json_decode(req('GET', '/api/v1/health')['body'], true);
ok($state() === 0 && $h['ingest_lag'] === $max() && $max() > 50, "before: checkpoint 0, health ingest_lag {$h['ingest_lag']} = {$max()} rows waiting");
$out = $run('MALUDB_API_TOKEN=wrong-token');
ok(str_contains($out, 'episode POST failed') && str_contains($out, 'exit=1') && $state() === 0 && $lines() === [], 'the API refuses (401): the run reports it, exits 1 and the checkpoint does NOT move');
$total = $max();
$out = $run();
$runs = 1;
while (preg_match('/shipped (\d+)\/(\d+) activity rows; checkpoint now (\d+)/', $out, $m) && (int) $m[3] < $total && $runs < 10) { $out = $run(); $runs++; }   // batches of 500
ok(preg_match('/shipped (\d+)\/(\d+) activity rows; checkpoint now (\d+)/', $out, $m) && (int) $m[1] === (int) $m[2] && (int) $m[3] === $total && str_contains($out, 'exit=0'), "good runs ship every row ($runs run(s)): $out");
ok($state() === $total, "the checkpoint is at the last row ($total)");
$eps = $lines();
ok(count($eps) === $total, count($eps) . ' episodes reached the API, one per activity row');
ok(count(array_filter($eps, fn ($e) => ($e['kind'] ?? '') === 'activity' && ($e['payload']['application'] ?? '') === 'inventory')) === $total && array_keys($eps[0]['payload'])[0] === 'application', 'every one is an activity episode whose payload STARTS with "application": "inventory"');
$match = array_values(array_filter($eps, fn ($e) => ($e['payload']['action'] ?? '') === 'listing.match'));
ok($match !== [] && ($match[0]['payload']['source_id'] ?? 0) === 9 && ($match[0]['payload']['entity_type'] ?? '') === 'listing_variant' && !array_key_exists('sales_order_id', $match[0]['payload']) && str_starts_with($match[0]['title'], 'listing.match by member #40'), 'a source-scoped episode carries source_id (and not the null keys) so memory can answer "what happened with that store"');
$feed = array_values(array_filter($eps, fn ($e) => ($e['payload']['action'] ?? '') === 'feed.read'));
ok($feed !== [] && ($feed[0]['payload']['token_id'] ?? 0) === 3 && ($feed[0]['payload']['source'] ?? '') === 'feed' && ($feed[0]['payload']['after']['label'] ?? '') === 'SMOKE website key', 'a feed episode carries token_id and the key\'s LABEL, source feed');
$signOns = array_values(array_filter($eps, fn ($e) => ($e['payload']['action'] ?? '') === 'member.sign_on'));
ok($signOns !== [] && !array_key_exists('source_id', $signOns[0]['payload']) && isset($signOns[0]['payload']['after']['capability']), 'a sign-on episode carries no audit key (nulls are dropped) and its after.capability');
ok(preg_match('/mcp_[0-9a-f]{48}/', file_get_contents($log)) === 0, 'no episode carries a token value');
$out = $run();
ok($out === 'exit=0' && count($lines()) === $total, 'a further run has nothing to do (no duplicates)');
$h = json_decode(req('GET', '/api/v1/health')['body'], true);
ok($h['ingest_lag'] <= 1 && $h['maludb'] === 'ok', "health: ingest_lag {$h['ingest_lag']} (the health request's own row at most), maludb ok");
[$j, ] = sign_on(40);
$out = $run();
ok(preg_match('/shipped (\d+)\/(\d+)/', $out, $m) && (int) $m[1] >= 1 && $state() === $max(), 'new activity ships on the next run and the checkpoint follows: ' . $out);
finish();

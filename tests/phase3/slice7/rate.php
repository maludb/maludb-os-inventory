<?php
/** The rate (feed.md "Proof" ≈ 18): the minute and the day limits, 429 with Retry-After, one feed.rate_limited row a bucket, a refused call never charged to the day, a malformed flood still counted, the next minute ok again, and a revoked key is a 401, not a 429. */
require __DIR__ . '/lib.php';
$w = feed_world();
$owner = $w['owner'];
$run = substr(md5((string) microtime(true)), 0, 6);
$cookie = static fn (array $r): bool => stripos((string) $r['headers'], 'set-cookie') !== false;
$retry = static fn (array $r): ?int => preg_match('/^Retry-After:\s*(\d+)/mi', (string) $r['headers'], $m) ? (int) $m[1] : null;
$limited = static fn (int $kid, string $action = 'feed.rate_limited'): array => q('SELECT * FROM activity_log WHERE action = :a AND token_id = :k ORDER BY id', ['a' => $action, 'k' => $kid]);
$gtin = $w['queen_gtin'];

echo "1. The minute\n";
within_minute(40);
[$c, , $key, $kid] = mint_key("Rate five $run", ['rate_per_minute' => '5']);
$codes = [];
for ($i = 0; $i < 5; $i++) { $codes[] = feed_get("gtin=$gtin", $key)[0]; }
ok($c === 200 && $codes === [200, 200, 200, 200, 200], 'a key limited to 5 a minute: five calls → 200');
[$c6, $b6, $r6] = feed_get("gtin=$gtin", $key);
ok($c6 === 429 && $b6['error']['code'] === 'rate_limited' && $b6['error']['limit'] === 'minute' && $b6['error']['message'] === 'Too many calls (minute).' && is_int($b6['error']['retry_after']) && $b6['error']['retry_after'] >= 1 && $b6['error']['retry_after'] <= 60, 'the 6th: 429 {code rate_limited, limit minute, retry_after 1–60}');
ok($retry($r6) === $b6['error']['retry_after'] && $retry($r6) >= 1 && $retry($r6) <= 60 && !$cookie($r6), 'Retry-After is the same number of seconds (and no cookie)');
$log1 = $limited($kid);
$a = after_of($log1[0] ?? null);
ok(count($log1) === 1 && $log1[0]['source'] === 'feed' && $log1[0]['actor_member_id'] === null && $a['limit_hit'] === 'minute' && $a['key_label'] === "Rate five $run" && $a['retry_after'] >= 1, 'feed.rate_limited logged once: source feed, the label, limit_hit minute, retry_after');
feed_get("gtin=$gtin", $key);
[$c8] = feed_get("gtin=$gtin", $key);
ok($c8 === 429 && count($limited($kid)) === 1, 'the 7th and 8th: 429 and no further log row — a flood writes one row a minute');
$mb = q("SELECT * FROM key_usage WHERE token_id = :k AND bucket_kind = 'minute'", ['k' => $kid])[0];
$db = q("SELECT * FROM key_usage WHERE token_id = :k AND bucket_kind = 'day'", ['k' => $kid])[0];
ok((int) $mb['calls'] === 5 && (int) $mb['refused'] === 3 && (int) $db['calls'] === 5 && (int) $db['refused'] === 0, 'the minute bucket: 5 calls and 3 refused; the day bucket: 5 calls — the refused calls were never charged to the day');
$ok = q('SELECT * FROM inv_rate_ok(:k, now() + interval \'1 minute\' + interval \'2 seconds\')', ['k' => $kid])[0];
ok($ok['ok'] === true && (int) $ok['minute_calls'] === 1, 'inv_rate_ok() at a time in the next minute: ok again, a new bucket (1 call)');
$rows = (int) one("SELECT count(*) FROM key_usage WHERE token_id = :k AND bucket_kind = 'minute'", ['k' => $kid]);
ok($rows === 2, 'the next minute has its own bucket');
$shown = req('GET', "/admin/feed-keys/?key=$kid", ['jar' => as_member(1)]);
ok(str_contains($shown['body'], 'id="feed-key-usage-totals"') && preg_match('~id="feed-key-usage-row-\d{8}"~', $shown['body']) === 1 && str_contains($shown['body'], 'text-danger'), 'the usage page lists the day with its calls and the refused ones in red');

echo "2. The day\n";
$sister = feed_raw('sister');
$sid = $w['k_sister'];
$codes = [];
for ($i = 0; $i < 3; $i++) { $codes[] = feed_get("gtin=$gtin", $sister)[0]; }
[$c4, $b4, $r4] = feed_get("gtin=$gtin", $sister);
ok($codes === [200, 200, 200] && $c4 === 429 && $b4['error']['limit'] === 'day' && $b4['error']['message'] === 'Too many calls (day).' && $b4['error']['retry_after'] >= 1 && $b4['error']['retry_after'] <= 86400 && $retry($r4) === $b4['error']['retry_after'], 'Sister installation (3 a day): the 4th → 429 limit day, retry_after up to 86,400, Retry-After to match');
$ld = $limited($sid);
feed_get("gtin=$gtin", $sister);
ok(count($ld) === 1 && after_of($ld[0])['limit_hit'] === 'day' && count($limited($sid)) === 1, 'feed.rate_limited with limit_hit day, once for the day');
$sd = q("SELECT * FROM key_usage WHERE token_id = :k AND bucket_kind = 'day'", ['k' => $sid])[0];
ok((int) $sd['calls'] === 3 && (int) $sd['refused'] === 2, 'the day bucket: 3 calls and 2 refused');

echo "3. A flood of malformed calls still counts\n";
within_minute(30);
[, , $fkey, $fid] = mint_key("Rate flood $run", ['rate_per_minute' => '3']);
$codes = [];
for ($i = 0; $i < 3; $i++) { $codes[] = feed_get('', $fkey)[0]; }
[$cf] = feed_get("gtin=$gtin", $fkey);
ok($codes === [422, 422, 422] && $cf === 429, 'three calls with no query → 422 each; the fourth, a good one → 429 (the rate is counted before the query is read)');

$list = req('GET', '/admin/feed-keys/', ['jar' => as_member(1)]);
ok(preg_match('~id="feed-key-row-' . $sid . '-today"><span class="text-danger fw-semibold">3</span>~', $list['body']) === 1 && preg_match('~id="feed-key-row-' . $kid . '-today"><span class="">' . (int) one('SELECT inv_feed_key_calls_today(:k)', ['k' => $kid]) . '</span><span class="text-muted"> / 10,000~', $list['body']) === 1, 'the list\'s Today turns danger at 90 % of the day\'s limit (3 of 3) and stays plain at a handful of 10,000');
$rsp = feed_get("gtin=$gtin", $sister);
ok($rsp[0] === 429 && str_contains((string) $rsp[2]['headers'], 'application/json') && !str_contains((string) $rsp[2]['body'], $sister), 'a 429 is JSON and never echoes the key');

echo "4. A key revoked between calls\n";
[, , $rkey, $rid] = mint_key("Rate revoked $run");
[$c1] = feed_get("gtin=$gtin", $rkey);
act($owner, '/admin/feed-keys/revoke.php', ['key' => $rid]);
[$c2, $b2] = feed_get("gtin=$gtin", $rkey);
ok($c1 === 200 && $c2 === 401 && ($b2['error']['code'] ?? '') === 'unauthorized', 'the next call after a revoke is a 401, not a 429');
$race = q('SELECT * FROM inv_rate_ok(:k)', ['k' => $rid])[0];
ok($race['ok'] === false && $race['limit_hit'] === 'revoked', 'inv_rate_ok() itself says revoked — the door turns that into the same 401 (a race between the resolve and the count)');
ok(count($limited($rid)) === 0 && (int) one("SELECT count(*) FROM activity_log WHERE token_id = :k AND action = 'feed.read'", ['k' => $rid]) === 1, 'the revoked key logged only its one answered call');
finish();

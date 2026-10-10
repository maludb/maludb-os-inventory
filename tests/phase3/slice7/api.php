<?php
/** The feed (feed.md "Proof" ≈ 30): the document, what it never says, the three states, a bundle from its components, a discontinued product absent, the quantity setting, a GTIN normalized, every malformed call, one 401 body for every failure, CORS, the method, no cookie, and what is logged. */
require __DIR__ . '/lib.php';
$w = feed_world();
$run = substr(md5((string) microtime(true)), 0, 6);
[$c, , $key, $kid] = mint_key("API proof $run", ['rate_per_minute' => '5000', 'rate_per_day' => '100000']);
ok($c === 200 && $kid > 0 && preg_match('/^feed_[0-9a-f]{48}$/', (string) $key) === 1, 'an API-proof key with generous limits is minted');
$since = last_activity_id();
$cookie = static fn (array $r): bool => stripos((string) $r['headers'], 'set-cookie') !== false;
$setting = static function (string $sql): void { psql_exec($sql); };

echo "1. The document\n";
[$c, $b, $r] = feed_get('gtin=' . $w['queen_gtin'], $key);
ok($c === 200 && $b['schema'] === 'os.inventory-feed/1' && $b['application'] === 'inventory' && $b['currency'] === 'USD' && $b['count'] === 1 && strtotime((string) $b['generated_at']) > time() - 60 && isset($b['as_of']) && $b['query'] === ['gtin' => $w['queen_gtin']], '?gtin= → 200, schema os.inventory-feed/1, currency USD, one result, the query echoed');
$res = $b['results'][0] ?? [];
ok(array_keys($res) === ['sku', 'gtin', 'name', 'size', 'retail_price', 'currency', 'availability', 'quantity', 'lead_time_days', 'ships_how'], 'the result\'s keys are exactly the document\'s ten, in its order — no partner_price, no cost, no source');
ok($res['sku'] === 'SMOKE-NW-CR-Q' && $res['gtin'] === $w['queen_gtin'] && $res['size'] === 'Queen' && (float) $res['retail_price'] === 999.0 && $res['currency'] === 'USD' && str_contains($res['name'], 'Cloudrest Hybrid'), 'the Queen: sku, gtin, name, size, retail 999.00');
ok($res['availability'] === 'in_stock' && $res['quantity'] === null && $res['lead_time_days'] === 0 && $res['ships_how'] === 'parcel', 'in stock, quantity null (the setting is off), lead time 0, ships parcel');
$low = strtolower((string) $r['body']);
ok(!str_contains($low, 'cost') && !str_contains($low, 'source') && !str_contains($low, 'malouf') && !str_contains($low, 'zinus') && !str_contains($low, 'supplier') && !str_contains($low, 'partner_price') && !str_contains($low, 'map_price'), 'the body never says cost, source, a supplier\'s name or a partner price');
ok(str_contains((string) $r['headers'], 'Cache-Control: no-store') && str_contains((string) $r['headers'], 'application/json') && !$cookie($r), 'Cache-Control: no-store, JSON, and no Set-Cookie');

echo "2. Asking by sku, by words, by size\n";
[, $b2] = feed_get('sku=smoke-nw-cr-q', $key);
ok($b2['count'] === 1 && $b2['results'][0]['sku'] === 'SMOKE-NW-CR-Q', '?sku= is exact and case-insensitive');
[, $b2] = feed_get('sku=SMOKE-NW-CR', $key);
ok($b2['count'] === 0 && $b2['results'] === [], '?sku= is exact: a prefix finds nothing');
[$c, $b2] = feed_get('q=cloudrest&size=queen', $key);
$sizes = array_unique(array_column($b2['results'], 'size'));
ok($c === 200 && $b2['count'] >= 2 && $sizes === ['Queen'] && in_array('SMOKE-NW-CR-Q', array_column($b2['results'], 'sku'), true) && !in_array('SMOKE-NW-CR-K', array_column($b2['results'], 'sku'), true), '?q=cloudrest&size=queen → only Queens (the mattress, its foundation, the set), no King');
ok($b2['query'] === ['q' => 'cloudrest', 'size' => 'queen'], '…the query is echoed with its size');
[, $b2] = feed_get('q=cloudrest', $key);
ok($b2['count'] >= 10 && $b2['count'] <= 25, '?q= alone runs Find\'s search: every size, never more than 25');
[, $b2] = feed_get('sku=SMOKE-NW-CR-CK', $key);
ok($b2['results'][0]['availability'] === 'back_order' && $b2['results'][0]['lead_time_days'] === 5 && $b2['results'][0]['quantity'] === null, 'the Cal King: back_order with lead_time_days 5 — the supplier\'s offer, unnamed');
[, $b2] = feed_get('sku=SMOKE-NW-CR-K', $key);
ok($b2['results'][0]['availability'] === 'back_order' && $b2['results'][0]['lead_time_days'] === 3 && $b2['results'][0]['ships_how'] === 'ltl', 'the King: only a floor model in the showroom, so back_order, the faster supplier\'s 3 days, ships ltl');
[, $b2] = feed_get('sku=SMOKE-FND-K', $key);
ok($b2['results'][0]['availability'] === 'out_of_stock' && $b2['results'][0]['lead_time_days'] === null, 'a variant nobody holds or offers: out_of_stock, no lead time');

echo "3. A bundle, a discontinued product, the quantity\n";
[, $b2] = feed_get('sku=SMOKE-SET-Q', $key);
ok($b2['results'][0]['availability'] === 'out_of_stock', 'the Queen set while its foundation is held and offered by nobody: out_of_stock');
psql_exec("SELECT inv_post_txn('receipt', {$w['fnd_queen']}, {$w['wh']}, 2, 120.00, 'opening', 7, 'smoke-s7-fnd2', 'none', NULL, NULL, NULL, 1)");
[, $b2] = feed_get('sku=SMOKE-SET-Q', $key);
ok($b2['results'][0]['availability'] === 'in_stock' && $b2['results'][0]['lead_time_days'] === 0, 'two foundations on the shelf: the set is in_stock — its state is its components\'');
$setting('UPDATE inv_settings SET feed_shows_quantity = true WHERE id = 1');
[, $b2] = feed_get('sku=SMOKE-SET-Q', $key);
[, $b3] = feed_get('sku=SMOKE-NW-CR-Q', $key);
[, $b4] = feed_get('sku=SMOKE-NW-CR-K', $key);
$avail = (int) one("SELECT COALESCE(sum(b.qty_on_hand - b.qty_allocated - b.qty_floor_model), 0) FROM inventory_balances b JOIN locations l ON l.id = b.location_id WHERE b.variant_id = :v AND l.is_sellable AND l.active", ['v' => $w['queen']]);
ok($b3['results'][0]['quantity'] === $avail && $avail === 3 && $b2['results'][0]['quantity'] === 2 && $b4['results'][0]['quantity'] === null, 'feed_shows_quantity on: the Queen says 3 (on hand − allocated − floor), the set 2 (its scarcer part), the King (not in stock) null');
$setting('UPDATE inv_settings SET feed_shows_quantity = false WHERE id = 1');
[, $b3] = feed_get('sku=SMOKE-NW-CR-Q', $key);
ok($b3['results'][0]['quantity'] === null, '…and off again: null');
[, $b2] = feed_get('sku=SMOKE-HP-LT-Q-2', $key);
$before = $b2['count'];
psql_exec("UPDATE products SET status = 'discontinued' WHERE id = {$w['topper']}");
[, $b3] = feed_get('sku=SMOKE-HP-LT-Q-2', $key);
psql_exec("UPDATE products SET status = 'active' WHERE id = {$w['topper']}");
[, $b4] = feed_get('sku=SMOKE-HP-LT-Q-2', $key);
ok($before === 1 && $b3['count'] === 0 && $b4['count'] === 1, 'a discontinued product is absent from the feed (and back when it is active again)');

echo "4. A GTIN, normalized\n";
$twelve = ltrim($w['queen_gtin'], '0');
[, $b2] = feed_get('gtin=' . $twelve, $key);
ok($b2['count'] === 1 && $b2['results'][0]['sku'] === 'SMOKE-NW-CR-Q' && $b2['query'] === ['gtin' => $twelve], 'a GTIN typed with 12 digits is normalized to 14 by the function and finds the Queen');
[, $b2] = feed_get('gtin=00000000000000', $key);
ok($b2['count'] === 0, 'a GTIN nothing carries: count 0, still a 200 document');

echo "5. Malformed calls\n";
$inv = static function (string $query, string $field, string $why) use ($key): void {
    [$c, $b] = feed_get($query, $key);
    ok($c === 422 && ($b['error']['code'] ?? '') === 'invalid' && is_string($b['error']['message'] ?? null) && (isset($b['error']['fields'][$field]) || $field === ''), $why);
};
$inv('q=' . str_repeat('a', 121), 'q', '?q= of 121 characters: 422 invalid with fields.q');
[$c] = feed_get('q=' . str_repeat('a', 120), $key);
ok($c === 200, '?q= of 120 characters is fine');
$inv('', 'query', 'no parameter: 422');
$inv('q=', 'query', 'an empty q: 422');
$inv('gtin=' . $w['queen_gtin'] . '&sku=SMOKE-NW-CR-Q', 'gtin', 'gtin and sku together: 422 naming both');
$inv('q=cloudrest&gtin=' . $w['queen_gtin'], 'q', 'q and gtin together: 422');
$inv('q=cloudrest&size=' . str_repeat('x', 41), 'size', 'a size over 40 characters: 422 with fields.size');
$inv('gtin[]=1', 'query', 'an array where a string belongs: 422, not a 500');

echo "6. One 401 for every failure\n";
[$c0, $b0, $r0] = feed_get('q=cloudrest', null);
$canon = (string) $r0['body'];
ok($c0 === 401 && ($b0['error']['code'] ?? '') === 'unauthorized' && ($b0['error']['message'] ?? '') === 'A valid feed key is required.', 'no bearer: 401 {error: {code: unauthorized, message}}');
$mcp = act($owner = as_member(1), '/settings/tokens/mint.php', ['label' => "SMOKE person $run", 'scope' => 'mcp']);
$person = (string) ($mcp[1]['token'] ?? '');
[, , $rv1] = mint_key("API revoked $run");
[, , $rvRaw, $rvId] = mint_key("API to revoke $run");
act($owner, '/admin/feed-keys/revoke.php', ['key' => $rvId]);
[, , $exRaw, $exId] = mint_key("API to expire $run");
psql_exec("UPDATE feed_keys SET expires_at = now() - interval '1 minute' WHERE id = $exId");
$bodies = [];
foreach (['Bearer nonsense' => 'nonsense', 'a key shaped like one that nobody minted' => 'feed_' . str_repeat('0', 48), 'a key with the wrong characters' => 'feed_' . str_repeat('z', 48), "a person's mcp token" => $person, 'a revoked key' => $rvRaw, 'an expired key' => $exRaw] as $what => $tok) {
    [$c, , $r] = feed_get('q=cloudrest', (string) $tok);
    $bodies[$what] = [$c, (string) $r['body']];
}
$same = true;
foreach ($bodies as [$c, $body]) { if ($c !== 401 || $body !== $canon) { $same = false; } }
ok($person !== '' && str_starts_with($person, 'mcp_') && $same, 'a wrong bearer, an unminted key, a malformed one, a person\'s mcp_ token, a revoked and an expired key: the same 401, the same body');
$r = req('GET', '/api/v1/availability?q=cloudrest', ['headers' => ['Authorization: Basic ' . base64_encode('a:b')]]);
ok($r['code'] === 401 && $r['body'] === $canon && !$cookie($r), 'Basic auth is no key either — and a 401 sends no cookie');

echo "7. CORS, the method, no cookie\n";
$r = req('OPTIONS', '/api/v1/availability', ['headers' => ['Origin: https://shop.example.invalid', 'Access-Control-Request-Method: GET']]);
ok($r['code'] === 204 && stripos((string) $r['headers'], 'Access-Control-Allow-Origin: https://shop.example.invalid') !== false && stripos((string) $r['headers'], 'Access-Control-Allow-Credentials') === false && stripos((string) $r['headers'], 'Access-Control-Allow-Methods: GET, OPTIONS') !== false, 'OPTIONS from an allowed origin: 204 with Allow-Origin and Allow-Methods, never Allow-Credentials');
$r = req('OPTIONS', '/api/v1/availability', ['headers' => ['Origin: https://evil.example.invalid']]);
ok($r['code'] === 204 && stripos((string) $r['headers'], 'Access-Control-Allow-Origin') === false, 'OPTIONS from another origin: no CORS headers');
[, , $r] = feed_get('gtin=' . $w['queen_gtin'], $key, ['headers' => ['Origin: https://shop.example.invalid']]);
ok(stripos((string) $r['headers'], 'Access-Control-Allow-Origin: https://shop.example.invalid') !== false && stripos((string) $r['headers'], 'Access-Control-Allow-Credentials') === false, 'a GET from the allowed origin carries Allow-Origin on the answer too');
[$c, $bp, $r] = feed_get('q=cloudrest', $key, ['method' => 'POST']);
ok($c === 405 && ($bp['error']['code'] ?? '') === 'method_not_allowed' && !$cookie($r), 'a POST: 405');
[$c, , $r] = feed_get('q=' . str_repeat('a', 130), $key);
ok($c === 422 && !$cookie($r), 'a 422 sends no cookie either');

echo "8. What is logged\n";
$u0 = (int) one('SELECT count(*) FROM activity_log WHERE id > :s AND action = \'feed.read\' AND token_id = :k', ['s' => $since, 'k' => $kid]);
$lastId = last_activity_id();
feed_get('sku=SMOKE-NW-CR-Q', $key);
feed_get('q=' . str_repeat('a', 120), $key);
feed_get('gtin=' . $w['queen_gtin'], $key);
$rows = q("SELECT * FROM activity_log WHERE id > :s AND token_id = :k ORDER BY id", ['s' => $lastId, 'k' => $kid]);
$byKind = [];
foreach ($rows as $row) { $byKind[json_decode((string) $row['after'], true)['query_kind'] ?? '?'] = $row; }
ok(count($rows) === 3 && $rows[0]['action'] === 'feed.read' && $rows[0]['source'] === 'feed' && $rows[0]['actor_member_id'] === null && $rows[0]['entity_type'] === 'feed_key' && (int) $rows[0]['entity_id'] === $kid, 'each answered call is one feed.read row: source feed, no actor, entity feed_key, token_id the key');
$a = after_of($byKind['sku'] ?? null);
ok(($a['key_label'] ?? '') === "API proof $run" && $a['count'] === 1 && $a['query_excerpt'] === 'SMOKE-NW-CR-Q', 'after: the key\'s LABEL, the count, the query kind and the excerpt');
ok(mb_strlen((string) (after_of($byKind['q'] ?? null)['query_excerpt'] ?? '')) === 120, 'the excerpt of a 120-character q is cut at 120');
ok(!str_contains(json_encode(array_column($rows, 'after')), $key) && !str_contains(json_encode($rows), hash('sha256', $key)), 'the key and its hash are in no feed.read row');
$after = (int) one('SELECT count(*) FROM activity_log WHERE id > :s AND token_id = :k AND action = \'feed.read\'', ['s' => $since, 'k' => $kid]);
feed_get('q=' . str_repeat('a', 130), $key);
feed_get('q=cloudrest', 'nonsense');
ok((int) one('SELECT count(*) FROM activity_log WHERE id > :s AND token_id = :k AND action = \'feed.read\'', ['s' => $since, 'k' => $kid]) === $after, 'a 422 and a 401 write no feed.read');
ok((int) one("SELECT count(*) FROM activity_log WHERE id > :s AND action = 'screen.view' AND source = 'feed'", ['s' => $since]) === 0, 'the feed never logs a screen.view');
ok(key_row($kid)['last_used_at'] !== null, 'the key\'s last_used_at is stamped by the resolve');
$calls = (int) one("SELECT COALESCE(sum(calls), 0) FROM key_usage WHERE token_id = :k AND bucket_kind = 'day'", ['k' => $kid]);
ok($calls > 30 && (int) one("SELECT COALESCE(sum(calls), 0) FROM key_usage WHERE token_id = :k AND bucket_kind = 'minute'", ['k' => $kid]) === $calls, 'the key\'s usage counts every call it was admitted for — the 422s too, never a 401 — by minute and by day');
finish();

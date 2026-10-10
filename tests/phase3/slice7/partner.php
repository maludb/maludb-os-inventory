<?php
/** The partner price and the price lists (feed.md "Proof" ≈ 10): retail × (1 − percent) to two decimals on every result, absent from every other key, an inactive list prices at retail, the list forms and their refusals. */
require __DIR__ . '/lib.php';
$w = feed_world();
$owner = $w['owner'];
$run = substr(md5((string) microtime(true)), 0, 6);
$partner = feed_raw('partner');
$web = feed_raw('website');
$since = last_activity_id();
$price = static fn (string $sku): float => (float) one('SELECT retail_price FROM product_variants WHERE lower(sku) = lower(:s)', ['s' => $sku]);

echo "1. The partner's price\n";
[$c, $b] = feed_get('q=cloudrest', $partner);
$allOk = $c === 200 && $b['count'] >= 6;
foreach ($b['results'] as $row) {
    if ($row['retail_price'] === null) { continue; }
    if (!array_key_exists('partner_price', $row) || abs((float) $row['partner_price'] - round($price($row['sku']) * 0.80, 2)) > 0.001) { $allOk = false; }
}
ok($allOk, 'Partner Store (Dealer 20): every result carries partner_price = retail × 0.80 to two decimals (' . $b['count'] . ' results)');
[, $b] = feed_get('sku=SMOKE-NW-CR-Q', $partner);
ok($b['results'][0]['partner_price'] === 799.2 && $b['results'][0]['retail_price'] === 999 && array_keys($b['results'][0]) === ['sku', 'gtin', 'name', 'size', 'retail_price', 'currency', 'partner_price', 'availability', 'quantity', 'lead_time_days', 'ships_how'], 'the Queen: 999.00 → 799.20, and the partner\'s document has the eleven keys, partner_price after the currency');
[, $bw] = feed_get('q=cloudrest', $web);
$none = true;
foreach ($bw['results'] as $row) { if (array_key_exists('partner_price', $row)) { $none = false; } }
ok($bw['count'] >= 6 && $none && !str_contains(json_encode($bw), 'partner_price'), 'the Website key: no partner_price key at all on any result');
[, , $ik] = mint_key("Partner proof installation $run", ['consumer_kind' => 'installation']);
[, $bs] = feed_get('sku=SMOKE-NW-CR-Q', $ik);
ok(isset($bs['results'][0]) && !array_key_exists('partner_price', $bs['results'][0]) && $bs['results'][0]['retail_price'] === 999, 'an installation\'s key: retail only, no partner_price either');

echo "2. An inactive list prices at retail\n";
[$c, $b] = act($owner, '/admin/price-lists/save.php', ['price_list' => $w['dealer20'], 'active' => 'no']);
ok($c === 200 && one('SELECT active FROM price_lists WHERE id = :i', ['i' => $w['dealer20']]) === false, 'Dealer 20 made inactive');
[, $b] = feed_get('sku=SMOKE-NW-CR-Q', $partner);
ok($b['results'][0]['partner_price'] === 999 && $b['results'][0]['retail_price'] === 999, 'the partner key answers partner_price = retail while its list is inactive (the function\'s rule)');
$list = req('GET', '/admin/feed-keys/', ['jar' => $owner]);
ok(str_contains($list['body'], 'bg-soft-secondary text-secondary">inactive'), 'and the keys page flags the inactive list on the key');
act($owner, '/admin/price-lists/save.php', ['price_list' => $w['dealer20'], 'active' => 'yes']);
[, $b] = feed_get('sku=SMOKE-NW-CR-Q', $partner);
ok($b['results'][0]['partner_price'] === 799.2, '…and active again: 799.20');
$log = q("SELECT * FROM activity_log WHERE action = 'price_list.save' AND entity_id = :i AND id > :s ORDER BY id", ['i' => $w['dealer20'], 's' => $since]);
ok(count($log) === 2 && after_of($log[0])['changed'] === ['active'] && after_of($log[0])['active'] === false && json_decode((string) $log[0]['before'], true) === ['active' => true], 'price_list.save logged each change with before/after and the fields changed (active)');

echo "3. The price lists\n";
[$c, $b] = screen($owner, '/admin/price-lists/');
$d20 = array_values(array_filter($b['price_lists'], static fn (array $p): bool => $p['name'] === 'Dealer 20'))[0] ?? [];
ok($c === 200 && ($d20['keys_using'] ?? 0) === 1 && (float) $d20['percent_off_retail'] === 20.0 && $d20['active'] === true, 'the list: Dealer 20, 20 %, active, used by 1 key (from mcp_feed_keys)');
$page = req('GET', '/admin/price-lists/', ['jar' => $owner]);
ok(str_contains($page['body'], 'id="price-list-list-table"') && str_contains($page['body'], 'id="price-list-row-' . $w['dealer20'] . '"') && str_contains($page['body'], 'id="price-list-row-' . $w['dealer20'] . '-edit-btn"') && str_contains($page['body'], 'id="price-list-list-add-btn"') && str_contains($page['body'], 'id="price-list-card-' . $w['dealer20'] . '"'), 'the page: the table, the row, its Edit button, Add, and the card for a phone');
$form = req('GET', "/admin/price-lists/{$w['dealer20']}/edit", ['jar' => $owner]);
ok($form['code'] === 200 && str_contains($form['body'], 'id="price-list-form"') && str_contains($form['body'], 'value="20.00"') && str_contains($form['body'], 'name="price_list" value="' . $w['dealer20'] . '"') && str_contains($form['body'], 'id="price-list-form-field-active"'), 'the edit form carries the list, its percent and an active switch');
[$c, $b] = act($owner, '/admin/price-lists/save.php', ['name' => "SMOKE List $run", 'percent_off_retail' => '12.5', 'notes' => 'Sixteen words about this list.']);
$nid = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $nid > 0 && str_contains((string) $b['location'], "#price-list-row-$nid") && (string) one('SELECT percent_off_retail FROM price_lists WHERE id = :i', ['i' => $nid]) === '12.50' && one('SELECT active FROM price_lists WHERE id = :i', ['i' => $nid]) === true, 'a new list: 12.5 → 12.50, active by default, landing on its row');
[$c, $b] = act($owner, '/admin/price-lists/save.php', ['name' => "smoke list $run", 'percent_off_retail' => '5']);
ok($c === 422 && msg($b) === 'That name is already taken.', 'a duplicate name (ignoring case): 422 "That name is already taken."');
foreach (['100' => 'percent_off_retail', '-1' => 'percent_off_retail', '20.123' => 'percent_off_retail', 'abc' => 'percent_off_retail', '' => 'percent_off_retail'] as $pct => $field) {
    [$c, $b] = act($owner, '/admin/price-lists/save.php', ['name' => "SMOKE Bad $run $pct", 'percent_off_retail' => (string) $pct]);
    $bad[$pct] = $c === 422 && isset(fields($b)[$field]);
}
ok(!in_array(false, $bad, true), 'a percent of 100, -1, 20.123, "abc" or nothing: 422 with a field error each');
[, $b] = act($owner, '/admin/price-lists/save.php', ['name' => "SMOKE Bad $run", 'percent_off_retail' => '20.123']);
ok(str_contains(fields($b)['percent_off_retail'] ?? '', 'two decimals'), '…a third decimal says "two decimals"');
[$c, $b] = act($owner, '/admin/price-lists/save.php', ['name' => '', 'percent_off_retail' => '3']);
ok($c === 422 && isset(fields($b)['name']), 'no name: 422');
[$c, $b] = act($w['nora'], '/admin/price-lists/save.php', ['name' => "SMOKE Nora $run", 'percent_off_retail' => '3']);
ok($c === 403, 'a Buyer: 403');
[$c, $b] = act($owner, '/admin/price-lists/save.php', ['price_list' => 999999, 'name' => 'x', 'percent_off_retail' => '3']);
ok($c === 404 && msg($b) === 'Price list not found.', 'changing a list that is not there: 404');
finish();

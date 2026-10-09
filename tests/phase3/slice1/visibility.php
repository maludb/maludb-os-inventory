<?php
/** Who sees: Vera reads everything with cost —, no forms, no Delete; Sam sees retail and MAP, cost with the setting; Wes reads; the owner deletes and is refused on a moved or matched variant; product_delete cascades. */
require __DIR__ . '/lib.php';
$w = catalog_world();
$nora = as_member(40); $sam = as_member(41); $wes = as_member(42); $vera = as_member(43); $ann = as_member(44); $owner = as_member(1);
echo "1. Who sees what\n";
[$c, $d] = screen($vera, '/products/' . $w['mattress']);
ok($c === 200 && count($d['variants']) >= 6 && array_key_exists('cost_price', $d['variants'][0] ?? []) && $d['variants'][0]['cost_price'] === null && ($d['variants'][0]['cost_withheld'] ?? false) === true && ($d['variants'][0]['retail_price'] ?? null) !== null, 'Vera reads the product and its variants: retail there, cost null and withheld (' . $c . ', ' . count($d['variants'] ?? []) . ' variants, first ' . json_encode(array_intersect_key($d['variants'][0] ?? [], ['sku' => 1, 'retail_price' => 1, 'cost_price' => 1, 'cost_withheld' => 1])) . ')');
ok($d['may']['write'] === false && $d['may']['delete'] === false, 'may.write and may.delete false for her');
$h = page($vera, '/products/' . $w['mattress'])['body'];
ok(!str_contains($h, 'id="product-view-edit-btn"') && !str_contains($h, 'id="product-view-delete-btn"') && !str_contains($h, 'id="product-view-add-variant-btn"') && str_contains($h, 'title="cost withheld"'), 'her page: no Edit, Delete or Add variant; cost shown as — with the title');
foreach (['/products/new', '/products/' . $w['mattress'] . '/edit', '/variants/new?product=' . $w['mattress'], '/variants/' . $w['queen'] . '/edit', '/products/' . $w['mattress'] . '/images', '/variants/' . $w['set_q'] . '/bundle', '/catalog/import', '/catalog/gaps'] as $p) {
    if (page($vera, $p)['code'] !== 403) { ok(false, "Vera on $p: " . page($vera, $p)['code']); }
}
ok(str_contains(page($vera, '/products/new')['body'], 'You may not change the catalog.'), 'every form is 403 for Vera, in the right\'s words');
[$c, $d] = screen($vera, '/variants/' . $w['queen'] . '/identifiers');
ok($c === 200 && $d['identifiers'] !== null, 'Vera reads the identifiers');
ok(page($ann, '/products/' . $w['mattress'])['code'] === 200 && json_decode(page($ann, '/variants/' . $w['queen'], ['headers' => JSONH])['body'], true)['data']['variant']['cost_withheld'] === true, 'Ann (external Viewer) reads too, cost withheld');
[$c, $d] = screen($sam, '/variants/' . $w['queen']);
ok($d['variant']['retail_price'] !== null && $d['variant']['map_price'] !== null && $d['variant']['cost_price'] === null && $d['may']['prices'] === false, 'Sam sees retail and MAP, no cost (the setting off), no price forms');
pdo()->exec("UPDATE inv_settings SET sales_sees_cost = true WHERE id = 1");
[$c, $d] = screen($sam, '/variants/' . $w['queen']);
ok($d['variant']['cost_price'] !== null && $d['variant']['cost_withheld'] === false, 'with sales_sees_cost on (SQL) Sam sees cost');
pdo()->exec("UPDATE inv_settings SET sales_sees_cost = false WHERE id = 1");
ok(page($wes, '/products/')['code'] === 200 && page($wes, '/variants/' . $w['queen'])['code'] === 200 && page($wes, '/products/new')['code'] === 403, 'Wes reads the catalog and may not write it');
ok(page($nora, '/variants/' . $w['queen'])['code'] === 200 && json_decode(page($nora, '/variants/' . $w['queen'], ['headers' => JSONH])['body'], true)['data']['variant']['cost_withheld'] === false, 'Nora sees cost');
echo "2. Delete\n";
ok(act($nora, '/variants/delete.php', ['variant' => $w['calking']])[0] === 403 && act($nora, '/products/delete.php', ['product' => $w['pillow']])[0] === 403, 'Nora (no records.delete) may not delete');
[$c, $b] = act($nora, '/variants/save.php', ['product' => $w['mattress'], 'sku' => 'SMOKE-NW-CR-TMP', 'option_values' => json_encode(['Size' => 'Twin XL'])]);
$tmp = (int) $b['record_id'];
$since = last_activity_id();
[$c, $b] = act($owner, '/variants/delete.php', ['variant' => $tmp]);
ok($c === 200 && one('SELECT count(*) FROM product_variants WHERE id = :id', ['id' => $tmp]) === 0 && $b['location'] === '/products/' . $w['mattress'] . '?notice=variant_deleted', 'the owner deletes a variant with nothing on it; lands on the product');
ok(count(activity('variant.delete', $since)) === 1, 'variant.delete logged');
// a movement by SQL as the super-admin (slice 2's world is not here)
pdo()->exec("INSERT INTO locations (name, kind) VALUES ('SMOKE Warehouse', 'warehouse') ON CONFLICT DO NOTHING");
$loc = (int) one("SELECT id FROM locations WHERE name = 'SMOKE Warehouse'");
pdo()->exec("SELECT set_config('app.member_id', '1', false)");
pdo()->exec("SELECT inv_post_txn('receipt', {$w['calking']}, $loc, 2, 500, 'opening', 1, 'smoke-receipt-1', 'none', NULL, NULL, NULL, 1)");
[$c, $b] = act($owner, '/variants/delete.php', ['variant' => $w['calking']]);
ok($c === 422 && str_contains(msg($b), 'has stock movements'), 'refused on a variant with a movement, in words');
$h = page($owner, '/variants/' . $w['calking'])['body'];
ok(!str_contains($h, 'id="variant-view-delete-btn"') && str_contains($h, 'id="variant-view-not-deletable"'), 'the owner\'s page shows no Delete and says why');
ok(str_contains(page($owner, '/variants/' . $w['fnd_queen'])['body'], 'id="variant-view-not-deletable"') && str_contains(page($owner, '/variants/' . $w['fnd_queen'])['body'], 'component of a bundle'), 'a bundle component cannot be deleted either');
$src = (int) one("SELECT id FROM sources WHERE name = 'SMOKE Dreamland feed'");
$lid = (int) one("SELECT id FROM listings WHERE external_id = 'ext-1'");
pdo()->exec("INSERT INTO listing_variants (listing_id, external_variant_id, title, option_values, variant_id, match_kind) VALUES ($lid, 'ev-twin', 'Twin', '{\"Size\":\"Twin\"}', " . variant_id('SMOKE-NW-CR-T') . ", 'gtin')");
[$c, $b] = act($owner, '/variants/delete.php', ['variant' => variant_id('SMOKE-NW-CR-T')]);
ok($c === 422 && str_contains(msg($b), 'matched to a listing'), 'refused on a matched variant');
[$c, $b] = act($owner, '/products/delete.php', ['product' => $w['mattress']]);
ok($c === 422 && str_contains(msg($b), 'Discontinue the product instead'), 'product_delete refused on a product with a touched variant');
[$c, $b] = act($nora, '/products/save.php', ['name' => 'SMOKE Throwaway', 'type' => 'pillow']);
$tp = (int) $b['record_id'];
act($nora, '/variants/save.php', ['product' => $tp, 'sku' => 'SMOKE-TA-1', 'option_values' => json_encode(['Size' => 'Standard'])]);
act($nora, '/variants/save.php', ['product' => $tp, 'sku' => 'SMOKE-TA-2', 'option_values' => json_encode(['Size' => 'King'])]);
$since = last_activity_id();
[$c, $b] = act($owner, '/products/delete.php', ['product' => $tp]);
ok($c === 200 && one('SELECT count(*) FROM products WHERE id = :id', ['id' => $tp]) === 0 && one("SELECT count(*) FROM product_variants WHERE sku LIKE 'SMOKE-TA-%'") === 0 && $b['location'] === '/products/?notice=deleted', 'product_delete cascades the variants of an untouched product');
$log = activity('product.delete', $since);
ok(count($log) === 1 && str_contains((string) $log[0]['after'], 'SMOKE-TA-1'), 'product.delete logged with the variants');
ok(act($owner, '/products/delete.php', ['product' => $tp])[0] === 404, 'deleting it again: 404');
finish();

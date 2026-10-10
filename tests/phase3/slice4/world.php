<?php
/** The world of slice 4 (find.md "Proof"): built through the handlers and the worker; what the later proofs stand on, checked once. */
require __DIR__ . '/lib.php';
$w = find_world();
ok($w['wh'] && $w['sr'] && $w['ret'], 'the three locations: Warehouse and Showroom sellable, Returns not');
ok($w['src_malouf'] && $w['src_zinus_feed'] && $w['src_casper'] && $w['src_paused'] && $w['src_blocked'], 'the five sources made through the handler');
foreach (['malouf', 'zinus_feed', 'casper', 'paused'] as $k) { $p = last_pull($w['src_' . $k]); ok($p !== null && in_array($p['status'], ['ok', 'partial'], true), "the $k source pulled ({$p['status']}, " . ($p['listings_seen'] ?? 0) . ' listings)'); }
ok(source_row($w['src_paused'])['paused_at'] !== null, 'the WooCommerce source is paused after its pull');
as_db(1);
$hb = q('SELECT health FROM inv_source_health() WHERE source_id = :s', ['s' => $w['src_blocked']])[0]['health'] ?? null;
ok($hb === 'blocked', 'the walled source is blocked (' . $hb . ')');
$b = q('SELECT * FROM inventory_balances WHERE variant_id = :v AND location_id = :l', ['v' => $w['queen'], 'l' => $w['wh']])[0];
ok((int) $b['qty_on_hand'] === 4 && (int) $b['qty_allocated'] === 1, '4 Queens at the Warehouse, 1 allocated');
$b = q('SELECT * FROM inventory_balances WHERE variant_id = :v AND location_id = :l', ['v' => $w['king'], 'l' => $w['sr']])[0];
ok((int) $b['qty_on_hand'] === 1 && (int) $b['qty_floor_model'] === 1, 'one King on the Showroom floor (on hand 1, floor 1 — available 0)');
foreach (['SMOKE-NW-CR-T', 'SMOKE-NW-CR-Q', 'SMOKE-NW-CR-K', 'SMOKE-NW-CR-CK'] as $sku) {
    $m = q('SELECT s.name, lv.availability, lv.price, lv.cost_price FROM listing_variants lv JOIN listings l ON l.id = lv.listing_id JOIN sources s ON s.id = l.source_id WHERE lv.variant_id = :v AND lv.removed_at IS NULL ORDER BY s.name', ['v' => variant_id($sku)]);
    echo "     $sku: " . implode('; ', array_map(static fn ($r) => "{$r['name']} {$r['availability']} {$r['price']}/{$r['cost_price']}", $m)) . "\n";
}
ok((int) one("SELECT count(*) FROM listing_variants lv JOIN listings l ON l.id = lv.listing_id WHERE lv.variant_id = :v", ['v' => $w['fnd_king']]) === 0, 'nobody offers the Foundation King');
finish();

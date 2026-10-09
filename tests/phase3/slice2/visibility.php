<?php
/** Who sees (stock.md "Proof — Who sees"): cost behind the wall (Warehouse sees receipt cost only), the documents by right, levels for every reader. */
require __DIR__ . '/lib.php';
$w = stock_world();
[$vera, $wes, $nora, $sam, $ann] = [as_member(43), as_member(42), as_member(40), as_member(41), as_member(44)];
$rid = (int) one("SELECT id FROM goods_receipts WHERE status = 'posted' AND purchase_order_id IS NULL ORDER BY id LIMIT 1");
$aid = (int) one("SELECT a.id FROM inventory_adjustments a JOIN reason_codes rc ON rc.id = a.reason_code_id WHERE rc.code = 'donation'");
echo "1. Cost\n";
[, $d] = screen($vera, '/stock/movements');
ok(array_filter(array_column($d['rows'], 'unit_cost'), static fn ($v) => $v !== null) === [], 'Vera reads every movement with cost null');
$html = page($vera, '/stock/movements')['body'];
ok(str_contains($html, 'title="cost withheld">—') && !str_contains($html, '189.00'), 'and the screen shows "—" titled "cost withheld", never 189.00');
[, $d] = screen($wes, '/stock/movements?type=receipt');
ok(count(array_filter(array_column($d['rows'], 'unit_cost'), static fn ($v) => $v !== null)) > 0, 'Wes sees cost on receipt movements');
[, $d] = screen($wes, '/stock/movements?type=adjustment');
ok($d['rows'] !== [] && array_filter(array_column($d['rows'], 'unit_cost'), static fn ($v) => $v !== null) === [], '…but not on adjustment movements');
[, $d] = screen($wes, '/receipts/' . $rid);
ok(in_array('189.00', array_column($d['lines'], 'unit_cost'), true) && $d['amount'] !== null, 'Wes sees cost on the receipt lines and the amount');
[, $d] = screen($wes, '/adjustments/' . $aid);
ok($d['lines'][0]['unit_cost'] === null && $d['cost_withheld'] === true, 'Wes does not see an adjustment line\'s cost');
[, $d] = screen($nora, '/adjustments/' . $aid);
ok($d['lines'][0]['unit_cost'] === '140.00', 'Nora (cost.read) sees it: 140.00');
[, $d] = screen($nora, '/stock/movements?type=adjustment');
ok(count(array_filter(array_column($d['rows'], 'unit_cost'), static fn ($v) => $v !== null)) === count($d['rows']), 'Nora sees cost on every adjustment movement');
echo "2. The documents by right\n";
foreach (['/receipts/', '/counts/', '/adjustments/', '/transfers/', '/receipts/' . $rid, '/receipts/new', '/counts/new'] as $p) {
    ok(page($sam, $p)['code'] === 403, "Sam is refused $p");
}
$r = page($sam, '/receipts/');
ok(str_contains($r['body'], 'You may not receive goods'), 'in words: "You may not receive goods."');
foreach (['/stock/', '/stock/movements', '/stock/floor-models', '/locations/', '/locations/' . $w['wh']] as $p) {
    ok(page($sam, $p)['code'] === 200, "Sam reads $p");
}
ok(page($vera, '/receipts/')['code'] === 403 && page($vera, '/stock/')['code'] === 200, 'Vera reads the levels but not the receipts');
ok(page($ann, '/stock/')['code'] === 200 && screen($ann, '/stock/')[1]['rows'] !== [], 'Ann (external viewer) reads the levels — locations are not walls');
ok(page($wes, '/receipts/' . $rid)['code'] === 200 && page($wes, '/counts/')['code'] === 200 && page($wes, '/transfers/')['code'] === 200 && page($wes, '/adjustments/')['code'] === 200, 'Wes opens every document');
$omar = sign_on(46)[1]['code'];
ok($omar !== 302, 'Omar (no grant) never signs on');
$html = page($vera, '/stock/floor-models')['body'];
ok(!str_contains($html, 'id="floor-form"'), 'Vera sees no floor form');
ok(str_contains(page($nora, '/stock/floor-models')['body'], 'id="floor-form"'), 'Nora does');
finish();

<?php
/** Today and shipments (orders.md "Proof" ≈ 10): what is to be picked today by store and method, the drop-ships expected, the empty day, the shipments list, one shipment and its Deliver. */
require __DIR__ . '/lib.php';
$w = order_world();
$sam = $w['sam'];
$wes = $w['wes'];
$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));

// ---- an order due today: a Queen to pick and a back order waiting
[$c, $b] = make_quote($sam, $w['alvarez'], [
    ['variant' => $w['queen'], 'qty' => 1, 'fulfilment' => 'stock:' . $w['wh']],
    ['variant' => $w['topper_q2'], 'qty' => 1, 'fulfilment_kind' => 'backorder', 'location' => $w['wh']],
], ['customer_reference' => 'S5-TODAY', 'location' => $w['sr'], 'promised_on' => $today, 'delivery_method' => 'delivery']);
$t1 = (int) $b['record_id'];
act($sam, '/orders/confirm.php', ['order' => $t1]);
$tl = order_lines_of($t1);
$r = req('GET', '/orders/today', ['jar' => $wes]);
$html = $r['body'];
ok($r['code'] === 200 && str_contains($html, 'id="today-' . $w['wh'] . '-delivery"') && str_contains($html, 'id="today-order-' . $t1 . '"'), 'fulfilment-today lists the confirmed order under Warehouse · own delivery (today-{location}-{method}, today-order-{id})');
$seg = substr($html, (int) strpos($html, 'id="today-order-' . $t1 . '"'), 1800);
ok(str_contains($seg, '1 × SMOKE-NW-CR-Q') && str_contains($seg, '1 × SMOKE-HP-LT-Q-2') && str_contains($seg, 'Backorder') && str_contains($seg, 'Allocated') && str_contains($seg, 'Open'), '…with the Queen to pick (allocated) and the back order waiting (open)');
ok(str_contains($html, 'id="today-order-' . $t1 . '-ship"') && !str_contains(req('GET', '/orders/today', ['jar' => $sam])['body'], 'today-order-' . $t1 . '-ship'), 'a Ship button for the warehouse (stock.ship), none for Sales');
// the open day for each
$rs = req('GET', '/orders/today', ['jar' => $sam]);
ok(preg_match('/<option value="' . $w['wh'] . '" selected>/', $rs['body']) === 1, 'for Sales the page opens on the location their orders are filled from (the Warehouse)');
ok(preg_match('/<option value="" selected>Every location/', $r['body']) === 1 || !preg_match('/<option value="\d+" selected>/', $r['body']), 'for the warehouse it opens on every location');
// tomorrow
[$c, $b] = make_quote($sam, $w['alvarez'], [['variant' => $w['queen'], 'qty' => 1, 'fulfilment' => 'stock:' . $w['wh']]], ['customer_reference' => 'S5-TOMORROW', 'promised_on' => $tomorrow]);
$t2 = (int) $b['record_id'];
act($sam, '/orders/confirm.php', ['order' => $t2]);
[$c, $d0] = screen($wes, '/orders/today');
[$c, $d1] = screen($wes, '/orders/today?date=' . $tomorrow);
$ids = static fn (array $d) => array_merge(...array_map(static fn ($g) => array_column($g['orders'], 'sales_order_id'), $d['groups'] ?: [[ 'orders' => [] ]]));
ok(!in_array($t2, $ids($d0), true) && in_array($t2, $ids($d1), true) && in_array($t1, $ids($d1), true), 'a promised date tomorrow: not in today\'s list, in tomorrow\'s (with today\'s still due)');
// the drop-ship expected
[$c, $b] = make_quote($sam, $w['alvarez'], [['variant' => $w['king'], 'qty' => 1, 'fulfilment' => 'dropship:' . $w['lv_zinus_k']]], ['customer_reference' => 'S5-DROP', 'promised_on' => date('Y-m-d', strtotime('+5 days'))]);
$t3 = (int) $b['record_id'];
act($sam, '/orders/confirm.php', ['order' => $t3]);
$po = q('SELECT id, number, expected_on FROM purchase_orders WHERE sales_order_id = :o', ['o' => $t3])[0];
[$c, $dd] = screen($wes, '/orders/today?date=' . $po['expected_on']);
$exp = array_values(array_filter($dd['dropships_expected'], static fn ($x) => $x['number'] === $po['number']));
ok(count($exp) === 1 && $exp[0]['sales_order_id'] === $t3 && $exp[0]['supplier_name'] === 'SMOKE Zinus', 'the drop-ship expected on its date appears beside: purchase order, supplier, sales order');
$rr = req('GET', '/orders/today?date=' . $po['expected_on'], ['jar' => $wes]);
ok(str_contains($rr['body'], 'id="today-dropships"') && str_contains($rr['body'], 'id="today-dropship-' . $po['id'] . '-1"') && str_contains($rr['body'], '/purchasing/' . $po['id']), '…as a row (today-dropship-{po}-{line}) linking the purchase order');
// the empty day
$re = req('GET', '/orders/today?date=2020-01-01&location=' . $w['ret'], ['jar' => $wes]);
ok($re['code'] === 200 && str_contains($re['body'], 'Nothing to deliver or collect on Jan 1, 2020.') && str_contains($re['body'], 'id="fulfilment-today-empty"'), 'an empty day: "Nothing to deliver or collect on Jan 1, 2020."');
ok(req('GET', '/orders/today?date=garbage', ['jar' => $wes])['code'] === 422, 'a date that is not a date: 422');

// ---- the shipments list
$par = ref_order('S5-PARCEL');
$psh = q("SELECT id FROM shipments WHERE sales_order_id = :o", ['o' => $par])[0]['id'];
$r = req('GET', '/shipments/', ['jar' => $sam]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="shipment-row-' . $psh . '"') && str_contains($r['body'], 'ups.com'), 'the shipments list (undelivered by default) shows the UPS parcel with its tracking link');
$pick = q("SELECT id FROM shipments WHERE kind = 'pickup' LIMIT 1")[0]['id'] ?? 0;
ok(!str_contains($r['body'], 'id="shipment-row-' . $pick . '"') && str_contains(req('GET', '/shipments/?undelivered=0&kind=pickup', ['jar' => $sam])['body'], 'id="shipment-row-' . $pick . '"') && !str_contains(req('GET', '/shipments/?undelivered=0&kind=pickup', ['jar' => $sam])['body'], 'id="shipment-row-' . $psh . '"'), 'a delivered pickup is not in the default list; undelivered=0&kind=pickup lists it alone');
[$c, $dl] = screen($sam, '/shipments/?undelivered=0&kind=dropship');
ok($c === 200 && count($dl['shipments']) >= 2 && array_unique(array_column($dl['shipments'], 'kind')) === ['dropship'], 'the kind filter: the drop-ship shipments (JSON)');
// one shipment
$r = req('GET', "/shipments/$psh", ['jar' => $wes]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="shipment-view-deliver"') && str_contains($r['body'], '1 × ') === false && str_contains($r['body'], 'SMOKE-NW-CR-Q') && str_contains($r['body'], 'id="shipment-view-tracking"'), 'the shipment page: its line, the tracking, Deliver (stock.ship)');
ok(!str_contains(req('GET', "/shipments/$psh", ['jar' => $sam])['body'], 'shipment-view-deliver'), '…no Deliver for Sam');
[$c, $b] = act($wes, '/orders/deliver.php', ['shipment' => $psh]);
ok($c === 200 && !str_contains(req('GET', '/shipments/', ['jar' => $sam])['body'], 'id="shipment-row-' . $psh . '"'), 'Deliver on the shipment: delivered, and gone from the undelivered list');
ok(req('GET', '/shipments/99999999', ['jar' => $sam])['code'] === 404, 'an unknown shipment: 404');
finish();

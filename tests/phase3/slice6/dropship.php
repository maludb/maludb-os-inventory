<?php
/** A drop-ship order (purchasing.md "Proof" ≈ 20): the two purchase orders the confirmation drafted, no open line to draft, a cancel that frees the line, the draft from the order, what a drop-ship line may change, the customer's address and the phone. */
require __DIR__ . '/lib.php';
$w = purchasing_world();
$nora = $w['nora'];
$sam = $w['sam'];
$main = $w['main'];
$mainNo = order_row($main)['number'];
$pm = $w['po_malouf'];
$pz = $w['po_zinus'];

// ---- the two purchase orders the confirmation drafted
$r = req('GET', '/purchasing/', ['jar' => $nora]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="po-row-' . $pm . '"') && str_contains($r['body'], 'id="po-row-' . $pz . '"') && str_contains($r['body'], 'id="po-row-' . $pm . '-order"') && str_contains($r['body'], '/orders/' . $main), 'the list shows both drop-ship drafts, each with a link to the sales order');
$r = req('GET', "/orders/$main", ['jar' => $nora]);
ok(str_contains($r['body'], 'id="order-dropship-' . $pm . '"') && str_contains($r['body'], 'id="order-dropship-' . $pz . '"'), 'the order page lists them under Drop-ships');
$r = req('GET', "/purchasing/$pm", ['jar' => $nora]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="po-view-order"') && str_contains($r['body'], 'SMOKE Alvarez') && str_contains($r['body'], 'data-entity="purchase_order"'), 'the purchase-order page names the sales order and the customer');

// ---- nothing open to draft
$r = req('GET', "/purchasing/new?order=$mainNo&kind=dropship", ['jar' => $nora]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="po-form-dropships-none"') && !str_contains($r['body'], 'id="po-form-draft"'), "/purchasing/new?order=<SO>&kind=dropship lists no open line (every one is drafted) and offers no Draft");
[$c, $b] = act($nora, '/purchasing/save.php', ['kind' => 'dropship', 'order' => $main]);
ok($c === 422 && msg($b) === "$mainNo has no open drop-ship line.", 'a drop-ship draft for it: 422 "SO-… has no open drop-ship line."');
[$c, $b] = act($nora, '/purchasing/save.php', ['kind' => 'dropship', 'order' => 'SO-99999999']);
ok($c === 422 && isset(fields($b)['order']), 'an order that is not there: a field error');
[$c, $b] = act($nora, '/purchasing/save.php', ['kind' => 'dropship']);
ok($c === 422 && isset(fields($b)['order']), 'no order named: a field error');
[, $qb] = make_quote($w['sam'], $w['alvarez'], [['variant' => $w['king'], 'qty' => 1, 'fulfilment' => 'dropship:' . $w['lv_zinus_k']]], ['customer_reference' => 'S6-QUOTE']);
$qid = (int) $qb['record_id'];
[$c, $b] = act($nora, '/purchasing/save.php', ['kind' => 'dropship', 'order' => $qid]);
ok($c === 422 && str_contains(msg($b), 'is a quote — confirm it first'), 'a quote\'s drop-ships are not drafted: 422 in words');

// ---- what a drop-ship line may change
[$c, $b] = act($nora, '/purchasing/save.php', ['purchase_order' => $pm, 'supplier' => $w['zinus']]);
ok($c === 422 && isset(fields($b)['supplier']) && str_contains(msg($b), "A drop-ship's supplier is the offer's"), 'the supplier of a drop-ship update: 422 "A drop-ship\'s supplier is the offer\'s."');
$ml = po_lines_of($pm)[0];
[$c, $b] = act($nora, '/purchasing/lines/save.php', ['line' => $ml['id'], 'qty' => 2]);
ok($c === 422 && msg($b) === "A drop-ship line's variant and quantity are the customer's.", 'a drop-ship line\'s quantity: 422 "…are the customer\'s."');
[$c, $b] = act($nora, '/purchasing/lines/save.php', ['line' => $ml['id'], 'variant' => $w['king']]);
ok($c === 422 && msg($b) === "A drop-ship line's variant and quantity are the customer's.", '…and its variant');
$exp = date('Y-m-d', strtotime('+11 days'));
[$c, $b] = act($nora, '/purchasing/lines/save.php', ['line' => $ml['id'], 'unit_cost' => '1250.00', 'expected_on' => $exp]);
$after = po_lines_of($pm)[0];
ok($c === 200 && (float) $after['unit_cost'] === 1250.0 && $after['expected_on'] === $exp && (int) $after['qty_ordered'] === 1 && $after['sales_order_line_id'] === $ml['sales_order_line_id'] && (float) po_row($pm)['total'] === 1250.0, 'its cost and expected date change (1250.00); the quantity and the customer\'s line stay; the total follows');
[$c, $b] = act($nora, '/purchasing/lines/save.php', ['purchase_order' => $pm, 'variant' => $w['queen'], 'qty' => 1]);
ok($c === 422 && str_contains(msg($b), "A drop-ship's lines are the customer's"), 'a line added to a drop-ship: 422');
[$c, $b] = act($nora, '/purchasing/lines/remove.php', ['line' => $ml['id']]);
ok($c === 422 && str_contains(msg($b), "A drop-ship's lines are the customer's"), '…and one removed');
[$c, $b] = act($nora, '/purchasing/save.php', ['purchase_order' => $pm, 'lines' => json_encode([['variant' => $w['queen'], 'qty' => 1]])]);
ok($c === 422 && isset(fields($b)['lines']), 'lines on a drop-ship update: 422');
act($nora, '/purchasing/lines/save.php', ['line' => $ml['id'], 'unit_cost' => '1299.00', 'expected_on' => $exp]);

// ---- the customer's address and the phone
$r = req('GET', "/purchasing/$pm", ['jar' => $nora]);
ok(str_contains($r['body'], 'id="po-ship-to-address"') && str_contains($r['body'], '22 Elm Street') && str_contains($r['body'], 'SMOKE Alvarez') && str_contains($r['body'], '312-555-0188') && preg_match('/id="po-ship-to-phone-mark">shown to the supplier</', $r['body']) === 1, "Nora sees the customer's address and the phone marked \"shown to the supplier\" (the Cal King ships ltl)");
$r = req('GET', "/purchasing/$pz", ['jar' => $nora]);
ok(str_contains($r['body'], '312-555-0188') && preg_match('/id="po-ship-to-phone-mark">not shown to the supplier</', $r['body']) === 1, 'the King\'s purchase order (parcel) marks the phone "not shown to the supplier"');
$r = req('GET', "/purchasing/$pm", ['jar' => $sam]);
ok($r['code'] === 200 && !str_contains($r['body'], '22 Elm') && !str_contains($r['body'], '312-555-0188') && !str_contains($r['body'], 'id="po-ship-to-address"'), 'Sam sees neither the street nor the phone (he holds no purchasing.write)');
[, $d] = screen($sam, "/purchasing/$pm");
ok($d['purchase_order']['ship_to'] === null && !isset($d['purchase_order']['ship_to_name']), 'Sam\'s JSON carries no ship-to block (the city and region only, when the snapshot has them)');

// ---- cancel the Malouf draft: the line goes back to the picker and can be drafted again
$since = last_activity_id();
$nsince = last_notification_id();
[$c, $b] = act($nora, '/purchasing/cancel.php', ['purchase_order' => $pm]);
ok($c === 422 && isset(fields($b)['reason']), 'cancel without a reason: 422');
[$c, $b] = act($nora, '/purchasing/cancel.php', ['purchase_order' => $pm, 'reason' => 'The customer is rethinking']);
$sl = q('SELECT * FROM sales_order_lines WHERE id = :id', ['id' => $ml['sales_order_line_id']])[0];
ok($c === 200 && po_row($pm)['status'] === 'cancelled' && $sl['status'] === 'open' && $sl['purchase_order_line_id'] === null && (int) $b['lines_released'] === 1, 'cancel a drop-ship draft: cancelled, the sales line open again AND free of the cancelled purchase order line (db/020)');
$note = notifications_for(41, 'line_at_risk', $nsince);
ok(count($note) === 1 && (int) $note[0]['record_id'] === $main && str_contains($note[0]['title'], po_row($pm)['number'] . " with SMOKE Malouf was cancelled: The customer is rethinking") && $b['salesperson_told'] === true, 'Sam, the order\'s salesperson, is told (line_at_risk): "PO-… with SMOKE Malouf was cancelled: …"');
$cl = last_log('purchase_order.cancel', $since);
ok($cl !== null && (int) $cl['sales_order_id'] === $main && (int) $cl['purchase_order_id'] === $pm && after_of($cl)['kind'] === 'dropship' && after_of($cl)['reason'] === 'The customer is rethinking', 'purchase_order.cancel is logged with the sales order');
$r = req('GET', "/purchasing/new?order=$mainNo&kind=dropship", ['jar' => $nora]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="po-form-dropship-' . $w['malouf'] . '"') && str_contains($r['body'], 'id="po-form-draft"') && !str_contains($r['body'], 'id="po-form-dropship-' . $w['zinus'] . '"'), 'the form now lists the Cal King under Malouf and offers Draft');
$since2 = last_activity_id();
[$c, $b] = act($nora, '/purchasing/save.php', ['kind' => 'dropship', 'order' => $main, 'lines' => json_encode([['variant' => $w['queen'], 'qty' => 9]]), 'supplier' => $w['zinus']]);
$np = (int) ($b['record_id'] ?? 0);
$nrow = $np ? po_row($np) : [];
$nl = $np ? po_lines_of($np) : [];
ok($c === 200 && $np > 0 && $np !== $pm && $b['purchase_orders'] === [$np] && $nrow['kind'] === 'dropship' && (int) $nrow['supplier_id'] === $w['malouf'] && (int) $nrow['sales_order_id'] === $main && $nrow['ship_to_kind'] === 'customer', 'Draft: a new Malouf purchase order through inv_order_dropships_draft() (the `lines` and `supplier` sent are ignored)');
ok(count($nl) === 1 && (float) $nl[0]['unit_cost'] === 1299.0 && (int) $nl[0]['qty_ordered'] === 1 && (int) $nl[0]['sales_order_line_id'] === (int) $ml['sales_order_line_id'] && $nrow['ship_to_address1'] === "22 Elm Street\nUnit 4", 'its line is the customer\'s line at the line\'s snapshotted cost (1299.00), and the ship-to is the customer\'s');
$dl = last_log('purchase_order.draft', $since2);
ok($dl !== null && (int) $dl['sales_order_id'] === $main && (int) $dl['purchase_order_id'] === $np && after_of($dl)['kind'] === 'dropship' && !str_contains((string) $dl['after'], 'Elm') && !str_contains((string) $dl['after'], '312-555'), 'purchase_order.draft carries the sales order and never the customer\'s address or phone');
$sl = q('SELECT * FROM sales_order_lines WHERE id = :id', ['id' => $ml['sales_order_line_id']])[0];
ok((int) $sl['purchase_order_line_id'] === (int) $nl[0]['id'], 'the customer\'s line points at the new purchase order line');
[$c, $b] = act($nora, '/purchasing/save.php', ['kind' => 'dropship', 'order' => $main]);
ok($c === 422 && str_contains(msg($b), 'has no open drop-ship line'), 'and nothing is left to draft again');
finish();

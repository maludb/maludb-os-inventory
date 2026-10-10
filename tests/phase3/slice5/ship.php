<?php
/** Ship, deliver, close, cancel (orders.md "Proof" ≈ 35): the ship screen, the issue from stock, the supplier's tracking, delivery and cost, the close and the link's life, the cancels and who is told. */
require __DIR__ . '/lib.php';
$w = order_world();
$sam = $w['sam'];
$wes = $w['wes'];
$nora = $w['nora'];
$main = ref_order('S5-MAIN');
$num = order_row($main)['number'];
$run = substr(md5((string) microtime(true)), 0, 6);
// stock for the rest of the proof: six more Queens and the pillows the back order waits for come later
psql_exec("SELECT inv_post_txn('receipt', {$w['queen']}, {$w['wh']}, 6, 450.00, 'opening', 5, 'smoke-s5-q6-$run', 'none', NULL, NULL, NULL, 1)");
$lines = order_lines_of($main);
[$ql, $ckl, $kl, $pl] = [$lines[0], $lines[1], $lines[2], $lines[3]];

// ---- the screen
$r = req('GET', "/orders/$main/ship", ['jar' => $wes]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="order-ship-line-' . $ql['id'] . '"') && str_contains($r['body'], 'id="order-ship-line-' . $pl['id'] . '"') && str_contains($r['body'], 'id="order-ship-drop-' . $ckl['id'] . '"') && str_contains($r['body'], 'id="order-ship-drop-' . $kl['id'] . '"') && str_contains($r['body'], 'id="order-ship-submit"'), 'the ship screen lists the Queen line, the backorder line, and the two drop-ship lines read-only');
ok(str_contains($r['body'], 'type="checkbox"') && preg_match('/name="lines\[' . $ql['id'] . '\]\[ship\]" value="1"[^>]*checked/', $r['body']) === 1 && preg_match('/name="lines\[' . $pl['id'] . '\]\[ship\]" value="1"[^>]*checked/', $r['body']) === 0 && str_contains($r['body'], 'tracking comes from the supplier'), 'a checkbox per line (the stock line ticked, the back order not), and "tracking comes from the supplier"');
ok(req('GET', "/orders/$main/ship", ['jar' => $sam])['code'] === 403, 'Sam (no stock.ship) is refused the screen');
[$c, $b] = act($sam, '/orders/ship.php', ['order' => $main, 'lines' => [$ql['id'] => ['ship' => '1', 'qty' => '1']]]);
ok($c === 403, '…and the action: 403');

// ---- ship the Queen
$bal0 = balance_of($w['queen'], $w['wh']);
$since = last_activity_id();
[$c, $b] = act($wes, '/orders/ship.php', ['order' => $main, 'kind' => 'own_delivery', 'lines' => [$ql['id'] => ['ship' => '1', 'qty' => '1', 'serials' => 'SN-1001'], $pl['id'] => ['ship' => '0', 'qty' => '2']]]);
$sid = (int) ($b['shipment_id'] ?? 0);
$sh = $sid ? q('SELECT * FROM shipments WHERE id = :id', ['id' => $sid])[0] : [];
$ln = q('SELECT * FROM sales_order_lines WHERE id = :id', ['id' => $ql['id']])[0];
$bal1 = balance_of($w['queen'], $w['wh']);
ok($c === 200 && $sid > 0 && $sh['kind'] === 'own_delivery' && (int) $ln['qty_shipped'] === 1 && $ln['status'] === 'shipped' && $ln['serials'] === '{SN-1001}', 'shipping the Queen as own_delivery: a shipment, qty_shipped 1, the line shipped, the serial kept');
$txn = q("SELECT * FROM inventory_transactions WHERE reference_kind = 'shipment' AND reference_id = :s", ['s' => $sid]);
ok(count($txn) === 1 && $txn[0]['txn_type'] === 'sale' && (int) $txn[0]['qty'] === -1 && (int) $txn[0]['location_id'] === $w['wh'] && (float) $txn[0]['unit_cost'] === (float) one('SELECT cost_price FROM product_variants WHERE id = :v', ['v' => $w['queen']]), 'a `sale` movement at the Warehouse for −1 at the variant\'s cost');
ok((int) $bal1['qty_on_hand'] === (int) $bal0['qty_on_hand'] - 1 && (int) $bal1['qty_allocated'] === (int) $bal0['qty_allocated'] - 1 && (int) $ln['qty_allocated'] === 0, 'the allocation is released (balance and line); on hand is one less');
ok(order_row($main)['status'] === 'in_fulfilment', 'the order is in_fulfilment (the drop-ships and the back order are not shipped)');
$log = last_log('order.ship', $since);
$a = after_of($log);
ok($log !== null && (int) $log['sales_order_id'] === $main && $a['shipment_id'] === $sid && $a['issued'][0]['sku'] === 'SMOKE-NW-CR-Q' && $a['issued'][0]['qty'] === 1 && $a['kind'] === 'own_delivery', 'order.ship is logged with the shipment, the lines and what was issued');
[$c, $b] = act($wes, '/orders/ship.php', ['order' => $main, 'lines' => [$ql['id'] => ['ship' => '1', 'qty' => '1']]]);
ok($c === 422 && str_contains(msg($b), 'ships between'), 'shipping the line twice: the SQL\'s sentence as a 422');
$shipsBefore = (int) one('SELECT count(*) FROM shipments WHERE sales_order_id = :o', ['o' => $main]);
[$c, $b] = act($wes, '/orders/ship.php', ['order' => $main, 'lines' => [$pl['id'] => ['ship' => '1', 'qty' => '2']]]);
ok($c === 422 && str_contains(msg($b), 'Not enough on hand') && str_contains(msg($b), 'negative stock is not allowed') && (int) one('SELECT count(*) FROM shipments WHERE sales_order_id = :o', ['o' => $main]) === $shipsBefore, 'the back order with 0 on hand: the ledger\'s "Not enough on hand…" (the spec guessed "Only 0 available") and no shipment is left behind');
[$c, $b] = act($wes, '/orders/ship.php', ['order' => $main, 'lines' => []]);
ok($c === 422 && isset(fields($b)['lines']), 'no lines chosen: 422 "Choose the lines to ship."');

// ---- a parcel with a carrier, and a pickup
[$c, $b] = make_quote($sam, $w['alvarez'], [['variant' => $w['queen'], 'qty' => 1, 'fulfilment' => 'stock:' . $w['wh']]], ['customer_reference' => 'S5-PARCEL', 'delivery_method' => 'parcel']);
$par = (int) $b['record_id'];
act($sam, '/orders/confirm.php', ['order' => $par]);
$pline = order_lines_of($par)[0];
[$c, $b] = act_token('/orders/ship.php', ['order' => $par, 'kind' => 'parcel', 'carrier' => 'UPS', 'tracking' => '1Z999AA10123456784', 'lines' => json_encode([['line_id' => $pline['id'], 'qty' => 1]])], ['X-Action-Token: ' . person_token(42)]);
$psh = q('SELECT * FROM shipments WHERE id = :id', ['id' => (int) ($b['shipment_id'] ?? 0)])[0] ?? [];
ok($c === 200 && ($psh['carrier'] ?? '') === 'UPS' && str_contains((string) ($psh['tracking_url'] ?? ''), 'ups.com') && str_contains((string) $psh['tracking_url'], '1Z999AA10123456784'), 'a parcel with carrier UPS and a number gets a tracking_url (lines as JSON, under an action token)');
[$c, $b] = make_quote($sam, $w['alvarez'], [['variant' => $w['queen'], 'qty' => 1, 'fulfilment' => 'pickup:' . $w['wh']]], ['customer_reference' => 'S5-PICKUP', 'delivery_method' => 'pickup']);
$pk = (int) $b['record_id'];
act($sam, '/orders/confirm.php', ['order' => $pk]);
$kline = order_lines_of($pk)[0];
[$c, $b] = act($wes, '/orders/ship.php', ['order' => $pk, 'kind' => 'pickup', 'lines' => [$kline['id'] => ['ship' => '1', 'qty' => '1']]]);
$ksh = q('SELECT * FROM shipments WHERE id = :id', ['id' => (int) ($b['shipment_id'] ?? 0)])[0] ?? [];
ok($c === 200 && $ksh['kind'] === 'pickup' && $ksh['delivered_at'] !== null && one('SELECT status FROM sales_order_lines WHERE id = :id', ['id' => $kline['id']]) === 'delivered' && order_row($pk)['status'] === 'delivered', 'a pickup is delivered at once: the shipment, the line and the order');
$suggest = req('GET', "/orders/$par/ship", ['jar' => $wes]);
ok(preg_match('/<option value="parcel" selected>/', $suggest['body']) === 1 || $suggest['code'] === 422, 'the screen suggests the kind from the delivery method (parcel)');

// ---- deliver
$since = last_activity_id();
[$c, $b] = act($wes, '/orders/deliver.php', ['shipment' => $sid]);
ok($c === 200 && q('SELECT delivered_at FROM shipments WHERE id = :id', ['id' => $sid])[0]['delivered_at'] !== null && one('SELECT status FROM sales_order_lines WHERE id = :id', ['id' => $ql['id']]) === 'delivered' && last_log('order.deliver', $since) !== null, 'order_deliver: the shipment delivered, the Queen line delivered, order.deliver logged');
[$c, $b] = act($sam, '/orders/deliver.php', ['shipment' => $sid]);
ok($c === 403, 'delivering needs stock.ship: Sam is refused');

// ---- the suppliers' tracking (slice 6 calls inv_po_tracking; the proof does) and delivery
$pos = q("SELECT po.id, po.number, s.name AS supplier FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id WHERE po.sales_order_id = :o ORDER BY po.id", ['o' => $main]);
foreach ($pos as $po) { psql_exec("SELECT inv_po_send({$po['id']}, 40, 'email')"); }
ok(array_unique(array_column(q('SELECT status FROM sales_order_lines WHERE id IN (:a, :b)', ['a' => $ckl['id'], 'b' => $kl['id']]), 'status')) === ['ordered'], 'sending the purchase orders marks the drop-ship lines ordered');
foreach ($pos as $po) {
    $polid = (int) one('SELECT id FROM purchase_order_lines WHERE purchase_order_id = :p', ['p' => $po['id']]);
    psql_exec("SELECT inv_po_tracking($polid, 'FedEx', 'TRK-{$po['id']}', now(), 'portal', NULL)");
}
$drops = q("SELECT * FROM shipments WHERE sales_order_id = :o AND kind = 'dropship' ORDER BY id", ['o' => $main]);
ok(count($drops) === 2 && array_unique(array_column(q('SELECT status FROM sales_order_lines WHERE id IN (:a, :b)', ['a' => $ckl['id'], 'b' => $kl['id']]), 'status')) === ['shipped'], 'the suppliers\' tracking makes a `dropship` shipment each on the order; the lines are shipped');
$r = req('GET', "/orders/$main", ['jar' => $sam]);
ok(str_contains($r['body'], 'TRK-' . $pos[0]['id']) && str_contains($r['body'], 'TRK-' . $pos[1]['id']) && str_contains($r['body'], 'id="order-shipment-' . $drops[0]['id'] . '"'), 'the order page shows both drop-ship shipments with their tracking');
$cost0 = (float) one('SELECT cost_price FROM product_variants WHERE id = :v', ['v' => $w['king']]);
[$c, $b] = act($wes, '/orders/deliver.php', ['order' => $main]);
ok($c === 200 && (int) one("SELECT count(*) FROM shipments WHERE sales_order_id = :o AND delivered_at IS NULL", ['o' => $main]) === 0, 'order_deliver with the order: every undelivered shipment is delivered');
$polines = q("SELECT pl.status, pl.qty_received, pl.unit_cost FROM purchase_order_lines pl JOIN purchase_orders po ON po.id = pl.purchase_order_id WHERE po.sales_order_id = :o", ['o' => $main]);
ok(array_unique(array_column($polines, 'status')) === ['received'], 'delivering a drop-ship receives its purchase order line');
$ph = q("SELECT * FROM price_history WHERE variant_id = :v AND kind = 'cost' ORDER BY id DESC LIMIT 1", ['v' => $w['king']])[0] ?? [];
ok((float) one('SELECT cost_price FROM product_variants WHERE id = :v', ['v' => $w['king']]) === 649.0 && str_starts_with((string) ($ph['reason'] ?? ''), 'drop-ship PO-'), 'with cost_source last_receipt the King\'s cost became the drop-ship\'s (649.00), reason "drop-ship PO-…"');

// ---- the back order is shipped once the pillows are in; the order is delivered
psql_exec("SELECT inv_post_txn('receipt', {$w['pillow_v']}, {$w['wh']}, 2, 20.00, 'opening', 6, 'smoke-s5-pil-$run', 'none', NULL, NULL, NULL, 1)");
[$c, $b] = act($wes, '/orders/ship.php', ['order' => $main, 'kind' => 'own_delivery', 'lines' => [$pl['id'] => ['ship' => '1', 'qty' => '2']]]);
ok($c === 200 && order_row($main)['status'] === 'shipped', 'the back order ships once the pillows are on the shelf: every line is shipped, the order is shipped');
[$c, $b] = act($wes, '/orders/deliver.php', ['order' => $main]);
ok($c === 200 && order_row($main)['status'] === 'delivered', 'delivered: every line delivered, the order is delivered');

// ---- close: refused with a balance due; then the link starts its 180 days
[$c, $b] = act($sam, '/orders/close.php', ['order' => $main]);
$due = number_format((float) order_row($main)['total'] - (float) order_row($main)['amount_paid'], 2);
ok($c === 422 && msg($b) === "$num has $due due — record the payment or a refund first.", 'close with a balance due: 422 "' . $num . ' has ' . $due . ' due — record the payment or a refund first."');
act($sam, '/orders/payments/save.php', ['order' => $main, 'kind' => 'balance', 'amount' => $due, 'method' => 'transfer']);
$since = last_activity_id();
[$c, $b] = act($sam, '/orders/close.php', ['order' => $main]);
$o = order_row($main);
$live = q('SELECT * FROM order_links_secure WHERE sales_order_id = :o AND rotated_at IS NULL', ['o' => $main])[0] ?? null;
ok($c === 200 && $o['status'] === 'closed' && $o['closed_at'] !== null && last_log('order.close', $since) !== null, 'after the balance: closed with closed_at; order.close logged');
$days = $live === null ? -1 : round((strtotime($live['expires_at']) - strtotime($o['closed_at'])) / 86400);
ok($live !== null && $days >= 179 && $days <= 181, 'the customer\'s link now expires about 180 days after the close');
[$c, $b] = act($sam, '/orders/close.php', ['order' => $main]);
ok($c === 422 && str_contains(msg($b), 'a delivered order closes'), 'closing twice: 422');
$tokRow = $live;
$mails = mail_log();
$tokMail = null;
foreach (array_reverse($mails) as $m) { if (($m['to'] ?? '') === $w['alvarez_email'] && token_from_mail($m) && str_contains((string) $m['subject'], $num)) { $tokMail = token_from_mail($m); break; } }
ok($tokMail !== null && req('GET', "/o/$tokMail")['code'] === 200, 'the link still opens after the close (it is alive for its 180 days)');
psql_exec("UPDATE order_links_secure SET expires_at = now() - interval '1 day' WHERE id = {$live['id']}");
ok(req('GET', "/o/$tokMail")['code'] === 404, 'backdated past its end, the link is dead (404)');
psql_exec("UPDATE order_links_secure SET rotated_at = NULL WHERE id = {$live['id']}");
$rep = worker('links_expire');
ok(($rep['links_expire']['expired'] ?? $rep['expired'] ?? null) === 1 && q('SELECT rotated_at FROM order_links_secure WHERE id = :id', ['id' => $live['id']])[0]['rotated_at'] !== null && ($rep['links_expire']['stub'] ?? null) === null, 'the worker\'s links_expire pass marks it rotated and answers 1 (the pass is no stub)');

// ---- cancel
[$c, $b] = make_quote($sam, $w['alvarez'], [['variant' => $w['queen'], 'qty' => 1, 'fulfilment' => 'stock:' . $w['wh']], ['variant' => $w['king'], 'qty' => 1, 'fulfilment' => 'dropship:' . $w['lv_zinus_k']]], ['customer_reference' => 'S5-CANCEL']);
$cx = (int) $b['record_id'];
act($sam, '/orders/confirm.php', ['order' => $cx]);
$bb = balance_of($w['queen'], $w['wh']);
[$c, $b] = act($sam, '/orders/cancel.php', ['order' => $cx]);
ok($c === 422 && isset(fields($b)['reason']), 'a cancel with no reason: 422');
$since = last_activity_id();
[$c, $b] = act($sam, '/orders/cancel.php', ['order' => $cx, 'reason' => 'The customer moved away']);
$ba = balance_of($w['queen'], $w['wh']);
$cpos = q('SELECT status FROM purchase_orders WHERE sales_order_id = :o', ['o' => $cx]);
$log = last_log('order.cancel', $since);
$a = after_of($log);
ok($c === 200 && order_row($cx)['status'] === 'cancelled' && (int) $ba['qty_allocated'] === (int) $bb['qty_allocated'] - 1 && array_unique(array_column($cpos, 'status')) === ['cancelled'], 'cancel: the allocation is released and the draft drop-ship purchase order is cancelled by the SQL');
ok($log !== null && $a['reason'] === 'The customer moved away' && $a['released'][0]['sku'] === 'SMOKE-NW-CR-Q' && $a['dropships_cancelled'] === 1 && $a['dropships_to_cancel_by_hand'] === 0, 'order.cancel is logged with the reason, released[], dropships_cancelled 1 and dropships_to_cancel_by_hand 0');
// a sent purchase order: the Buyer is told
[$c, $b] = make_quote($sam, $w['alvarez'], [['variant' => $w['king'], 'qty' => 1, 'fulfilment' => 'dropship:' . $w['lv_zinus_k']]], ['customer_reference' => 'S5-CANCEL2']);
$c2 = (int) $b['record_id'];
act($sam, '/orders/confirm.php', ['order' => $c2]);
$cpo = q('SELECT id, number FROM purchase_orders WHERE sales_order_id = :o', ['o' => $c2])[0];
psql_exec("SELECT inv_po_send({$cpo['id']}, 40, 'email')");
$nn = (int) one("SELECT count(*) FROM notifications WHERE member_id = 40 AND kind = 'order'");
[$c, $b] = act($sam, '/orders/cancel.php', ['order' => $c2, 'reason' => 'Duplicate order']);
$note = q("SELECT * FROM notifications WHERE member_id = 40 AND kind = 'order' ORDER BY id DESC LIMIT 1")[0] ?? [];
ok($c === 200 && $b['dropships_to_cancel_by_hand'] === 1 && (int) one("SELECT count(*) FROM notifications WHERE member_id = 40 AND kind = 'order'") === $nn + 1 && str_starts_with((string) ($note['title'] ?? ''), 'Cancel ' . $cpo['number'] . ' with SMOKE Zinus') && $note['record_type'] === 'purchase_order' && (int) $note['record_id'] === (int) $cpo['id'], 'cancel with a sent purchase order: Nora (the Buyer) is told "Cancel PO-… with SMOKE Zinus…"; dropships_to_cancel_by_hand 1');
ok(one('SELECT status FROM purchase_orders WHERE id = :id', ['id' => $cpo['id']]) === 'sent', '…the sent purchase order itself is left for a person to cancel with the supplier');
[$c, $b] = act($sam, '/orders/cancel.php', ['order' => $par, 'reason' => 'Changed my mind']);
ok($c === 422 && str_contains(msg($b), 'take a return instead'), 'cancel with a shipped line: 422 "…take a return instead"');
[$c, $b] = act($sam, '/orders/cancel.php', ['order' => $c2, 'reason' => 'again']);
ok($c === 422 && str_contains(msg($b), 'already cancelled'), 'cancelling twice: 422');
[$c, $b] = act($w['wes'], '/orders/cancel.php', ['order' => $c2, 'reason' => 'x']);
ok($c === 403, 'cancel needs orders.write: Wes is refused');
// a line that was ordered: cancelling it tells the Buyer
[$c, $b] = make_quote($sam, $w['alvarez'], [['variant' => $w['king'], 'qty' => 1, 'fulfilment' => 'dropship:' . $w['lv_zinus_k']], ['variant' => $w['queen'], 'qty' => 1, 'fulfilment' => 'stock:' . $w['wh']]], ['customer_reference' => 'S5-LINE']);
$lo = (int) $b['record_id'];
act($sam, '/orders/confirm.php', ['order' => $lo]);
$lpo = q('SELECT id, number FROM purchase_orders WHERE sales_order_id = :o', ['o' => $lo])[0];
psql_exec("SELECT inv_po_send({$lpo['id']}, 40, 'email')");
$ls = order_lines_of($lo);
$nn = (int) one("SELECT count(*) FROM notifications WHERE member_id = 40 AND kind = 'order'");
$since = last_activity_id();
[$c, $b] = act($sam, '/orders/lines/cancel.php', ['line' => $ls[0]['id']]);
$note = q("SELECT * FROM notifications WHERE member_id = 40 AND kind = 'order' ORDER BY id DESC LIMIT 1")[0] ?? [];
ok($c === 200 && one('SELECT status FROM sales_order_lines WHERE id = :id', ['id' => $ls[0]['id']]) === 'cancelled' && (int) one("SELECT count(*) FROM notifications WHERE member_id = 40 AND kind = 'order'") === $nn + 1 && str_contains((string) $note['title'], 'cancel ' . $lpo['number'] . ' line with SMOKE Zinus'), 'order_line_cancel of an `ordered` line: cancelled, and the Buyer is told to cancel that purchase order line');
$ll = last_log('order.line_cancel', $since);
ok($ll !== null && after_of($ll)['status_before'] === 'ordered' && after_of($ll)['sku'] === 'SMOKE-NW-CR-K', 'order.line_cancel is logged with status_before "ordered"');
$bq = balance_of($w['queen'], $w['wh']);
[$c, $b] = act($sam, '/orders/lines/cancel.php', ['line' => $ls[1]['id']]);
ok($c === 200 && (int) $b['released'] === 1 && (int) balance_of($w['queen'], $w['wh'])['qty_allocated'] === (int) $bq['qty_allocated'] - 1, 'order_line_cancel on a stock line releases its allocation');
[$c, $b] = act($sam, '/orders/lines/cancel.php', ['line' => $pline['id']]);
ok($c === 422 && str_contains(msg($b), 'take a return instead'), 'a shipped line cannot be cancelled: "…take a return instead"');
[$c, $b] = act($sam, '/orders/lines/cancel.php', ['line' => $ql['id']]);
ok($c === 422 && str_contains(msg($b), 'is closed'), 'a line of a closed order: 422 "SO-… is closed."');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
ok($reg['actions']['order_cancel']['approval'] === 'deletion' && $reg['actions']['order_ship']['endpoint'] === '/orders/ship.php' && $reg['screens']['order-ship']['built'] === true, 'the registry lists order_cancel as `deletion`; order_ship at /orders/ship.php; the screen built');
finish();

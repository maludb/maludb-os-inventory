<?php
/** Receive and close (returns-worker.md "Proof": receive ≥ 26, close ≥ 10): the screen, the movements each disposition writes, short and zero quantities, the supplier's note, who is told; the refund recorded and linked. */
require __DIR__ . '/lib.php';
$w = returns_world();
[$nora, $sam, $wes, $vera, $owner] = [$w['nora'], $w['sam'], $w['wes'], $w['vera'], $w['owner']];
$ra1 = (int) one("SELECT id FROM return_authorizations WHERE notes = 'SMOKE S8-RA1'");
$rl1 = (int) return_lines_of($ra1)[0]['id'];
$q1 = sol_of($w['so1'], 'SMOKE-NW-CR-Q');
$k1 = sol_of($w['so1'], 'SMOKE-NW-CR-K');
$q2 = sol_of($w['so2'], 'SMOKE-NW-CR-Q');
$lines = static fn (array $l): string => json_encode($l);
$bal = static fn (int $v, int $l): array => balance_of($v, $l) + ['qty_floor_model' => 0];
$ra2 = (int) one("SELECT id FROM return_authorizations WHERE notes = 'SMOKE S8-RA2'");
// more stock and two more shipped orders: SO-3 (2 Queens + a foundation), SO-4 (a Queen + a foundation)
if (ref_order('S8-SO3') === null) {
    foreach ([[$w['queen'], 'q6'], [$w['fnd_queen'], 'f6']] as [$v, $k]) {
        psql_exec("SELECT inv_post_txn('receipt', $v, {$w['wh']}, 6, 300.00, 'opening', 1, 'smoke-s8-$k', 'none', NULL, NULL, NULL, 1)");
    }
    foreach ([['S8-SO3', 2, 1], ['S8-SO4', 1, 1]] as [$tag, $nq, $nf]) {
        [, $b] = make_quote($sam, $w['alvarez'], [['variant' => $w['queen'], 'qty' => $nq, 'fulfilment' => 'stock:' . $w['wh']], ['variant' => $w['fnd_queen'], 'qty' => $nf, 'fulfilment' => 'stock:' . $w['wh']]], ['customer_reference' => $tag, 'delivery_method' => 'delivery']);
        act($sam, '/orders/confirm.php', ['order' => (int) $b['record_id']]);
        $ship = [];
        foreach (order_lines_of((int) $b['record_id']) as $l) { $ship[$l['id']] = ['ship' => '1', 'qty' => (string) $l['qty']]; }
        act($wes, '/orders/ship.php', ['order' => (int) $b['record_id'], 'kind' => 'own_delivery', 'lines' => $ship]);
    }
}
$so3 = ref_order('S8-SO3'); $so4 = ref_order('S8-SO4');

echo "1. The screen\n";
$r = req('GET', "/returns/$ra1/receive", ['jar' => $wes]);
ok($r['code'] === 200 && str_contains($r['body'], "id=\"return-receive-line-$rl1\"") && str_contains($r['body'], 'data-return-scan') && str_contains($r['body'], "id=\"return-receive-line-$rl1-qty_received\"") && str_contains($r['body'], 'id="return-receive-save-btn"') && str_contains($r['body'], 'hx-confirm="This writes the stock movements."'), 'Wes opens /returns/N/receive: a card, a stepper, the scan field, Receive with its confirm');
ok(preg_match('/data-barcode="[0-9]{8,}"/', $r['body']) === 1 && str_contains($r['body'], 'data-sku="smoke-nw-cr-q"'), 'the card carries the variant\'s barcode and SKU for the scan field');
$r = req('GET', "/returns/$ra1/receive", ['jar' => $vera]);
ok($r['code'] === 403, 'a Viewer may not open the receiving screen');
[$c, $d] = screen($wes, "/returns/$ra1/receive");
ok($c === 200 && $d['may_receive'] === true && $d['lines'][0]['sku'] === 'SMOKE-NW-CR-Q', 'its JSON: the lines, may_receive');

echo "2. Receive\n";
[$c] = act($sam, '/returns/receive.php', ['return' => $ra1]);
ok($c === 403, 'Sam receiving an approved return: 403 (returns.receive)');
$since = last_activity_id(); $nid = last_notification_id(); $tx0 = (int) one('SELECT max(id) FROM inventory_transactions');
$sr0 = $bal($w['queen'], $w['sr']);
[$c, $b] = act($wes, '/returns/receive.php', ['return' => $ra1]);
$r1 = return_row($ra1);
$tx = q("SELECT * FROM inventory_transactions WHERE reference_kind = 'return' AND reference_id = :r ORDER BY id", ['r' => $ra1]);
$sr1 = $bal($w['queen'], $w['sr']);
ok($c === 200 && $r1['status'] === 'received' && (int) $r1['received_by'] === 42, 'Wes receives the return at the default quantity: received by him');
ok(count($tx) === 2 && $tx[0]['txn_type'] === 'return' && (int) $tx[0]['location_id'] === $w['sr'] && $tx[1]['txn_type'] === 'floor_model_in' && (int) $tx[1]['location_id'] === $w['sr'] && $sr1['qty_on_hand'] === $sr0['qty_on_hand'] + 1 && $sr1['qty_floor_model'] === $sr0['qty_floor_model'] + 1, 'a `return` and a `floor_model_in` at the showroom: on hand +1 and the floor +1');
$sol = sol_of($w['so1'], 'SMOKE-NW-CR-Q');
ok((int) $sol['qty_returned'] === 1 && $sol['status'] === 'returned' && (int) return_lines_of($ra1)[0]['qty_received'] === 1, 'the order line counts 1 returned and is `returned`; the return line says 1 received');
$lg = last_log('return.receive', $since); $a = after_of($lg);
ok($lg !== null && (int) $lg['sales_order_id'] === $w['so1'] && (int) $lg['location_id'] === $w['wh'] && $a['floor'][0]['sku'] === 'SMOKE-NW-CR-Q' && (int) $a['floor'][0]['location_id'] === $w['sr'] && $a['restocked'] === [] && $a['to_supplier'] === [] && $a['short'] === [] , 'logged return.receive with the header\'s store as location_id, the floor list and nothing short');
$told = q("SELECT member_id FROM notifications WHERE id > :s AND kind = 'return' AND title LIKE '%received'", ['s' => $nid]);
ok(count($told) === 1 && (int) $told[0]['member_id'] === 41, 'Sam — the requester and the order\'s salesperson — is told once; Nora is not');
[$c, $b] = act($wes, '/returns/receive.php', ['return' => $ra1]);
ok($c === 422 && str_contains(msg($b), 'is received — an approved return is received'), 'receiving twice: the verb\'s words');
[$c, $b] = act($nora, '/returns/disposition.php', ['return_line' => $rl1, 'disposition' => 'dispose']);
ok($c === 422 && str_contains(msg($b), 'The lines of a return change while it is requested or approved (it is received)'), 'a disposition change after receipt: the trigger\'s words');

echo "3. Where it lands, short and zero\n";
// a restock line with no place: a return on SO-2's Queen, made with `dispose`, then turned into a restock with nowhere to land (SQL), as a bad import might
[$c, $b] = act($sam, '/returns/save.php', ['order' => $w['so2'], 'lines' => $lines([['line' => $q2['id'], 'qty' => 1, 'reason' => 'damaged', 'disposition' => 'dispose']])]);
$ra3 = (int) $b['record_id'];
act($nora, '/returns/approve.php', ['return' => $ra3]);
psql_exec("UPDATE return_lines SET disposition = 'restock', location_id = NULL WHERE return_id = $ra3");
$wh0 = $bal($w['queen'], $w['wh']); $txn0 = (int) one('SELECT count(*) FROM inventory_transactions');
$rl3 = (int) return_lines_of($ra3)[0]['id'];
[$c, $b] = act($wes, '/returns/receive.php', ['return' => $ra3]);
ok($c === 422 && msg($b) === 'Say where SMOKE-NW-CR-Q comes back to.' && isset(fields($b)["lines.$rl3.location"]), "a restock with no location: \"Say where SMOKE-NW-CR-Q comes back to.\" naming lines.$rl3.location");
ok((int) one('SELECT count(*) FROM inventory_transactions') === $txn0 && return_row($ra3)['status'] === 'approved', 'before anything moved: no transaction, the return still approved');
$r = req('GET', "/returns/$ra3/receive", ['jar' => $wes]);
ok(str_contains($r['body'], "id=\"return-receive-line-$rl3-location\"") && str_contains($r['body'], 'Choose where it lands'), 'the receiving screen asks for the location of that line');
[$c, $b] = act($wes, '/returns/receive.php', ['return' => $ra3, 'lines' => [$rl3 => ['qty_received' => '1', 'location' => $w['wh']]]]);
$wh1 = $bal($w['queen'], $w['wh']);
ok($c === 200 && return_row($ra3)['status'] === 'received' && $wh1['qty_on_hand'] === $wh0['qty_on_hand'] + 1 && $wh1['qty_floor_model'] === $wh0['qty_floor_model'] && (int) one("SELECT count(*) FROM inventory_transactions WHERE reference_kind = 'return' AND reference_id = :r AND txn_type = 'return' AND location_id = :l", ['r' => $ra3, 'l' => $w['wh']]) === 1, 'with the location chosen on the screen it restocks: a `return` at the warehouse, on hand +1, the floor unchanged');
$ra3Log = after_of(last_log('return.receive'));
ok($ra3Log['restocked'][0]['sku'] === 'SMOKE-NW-CR-Q' && (int) $ra3Log['restocked'][0]['location_id'] === $w['wh'] && $ra3Log['restocked'][0]['qty'] === 1, 'logged with restocked [{sku, location_id, qty}]');

// SO-3: two Queens and a foundation; receive 1 of 2 and 0 of 1
$s3q = sol_of($so3, 'SMOKE-NW-CR-Q'); $s3f = sol_of($so3, 'SMOKE-FND-Q');
[$c, $b] = act($sam, '/returns/save.php', ['order' => $so3, 'location' => $w['wh'], 'lines' => $lines([['line' => $s3q['id'], 'qty' => 2, 'reason' => 'comfort', 'disposition' => 'restock'], ['line' => $s3f['id'], 'qty' => 1, 'reason' => 'wrong_item', 'disposition' => 'dispose']])]);
$ra4 = (int) $b['record_id'];
$ln = return_lines_of($ra4);
act($nora, '/returns/approve.php', ['return' => $ra4]);
[$c, $b] = act($wes, '/returns/receive.php', ['return' => $ra4, 'quantities' => json_encode([$ln[0]['id'] => 5])]);
ok($c === 422 && str_contains(msg($b), 'at most 2 came back'), 'more than was asked for: refused ("at most 2 came back")');
$since = last_activity_id(); $w0 = $bal($w['queen'], $w['wh']);
[$c, $b] = act($wes, '/returns/receive.php', ['return' => $ra4, 'quantities' => json_encode([$ln[0]['id'] => 1, $ln[1]['id'] => 0]), 'condition_notes' => json_encode([$ln[0]['id'] => 'box dented'])]);
$a = after_of(last_log('return.receive', $since));
ok($c === 200 && return_row($ra4)['status'] === 'received' && count(return_lines_of($ra4)) === 1 && (int) return_lines_of($ra4)[0]['qty'] === 1 && return_lines_of($ra4)[0]['condition_note'] === 'box dented', 'a short receipt (2 asked, 1 back) lowers the line; a line received at 0 is removed; the condition note is kept');
ok(count($a['short']) === 2 && $a['short'][0]['requested'] === 2 && $a['short'][0]['received'] === 1 && $a['short'][1]['requested'] === 1 && $a['short'][1]['received'] === 0 && $b['short'] == $a['short'], 'the log (and the answer) carry `short` for both lines');
$s3q2 = sol_of($so3, 'SMOKE-NW-CR-Q'); $s3f2 = sol_of($so3, 'SMOKE-FND-Q');
ok((int) $s3q2['qty_returned'] === 1 && $s3q2['status'] === 'shipped' && (int) $s3f2['qty_returned'] === 0 && $bal($w['queen'], $w['wh'])['qty_on_hand'] === $w0['qty_on_hand'] + 1, 'the order line counts 1 of 2 returned (still `shipped`), the foundation 0; the stock +1');

// SO-4: dispose and donate move nothing
$s4q = sol_of($so4, 'SMOKE-NW-CR-Q'); $s4f = sol_of($so4, 'SMOKE-FND-Q');
[$c, $b] = act($sam, '/returns/save.php', ['order' => $so4, 'lines' => $lines([['line' => $s4q['id'], 'qty' => 1, 'reason' => 'damaged', 'disposition' => 'dispose'], ['line' => $s4f['id'], 'qty' => 1, 'reason' => 'comfort', 'disposition' => 'donate']])]);
$ra5 = (int) $b['record_id'];
act($nora, '/returns/approve.php', ['return' => $ra5]);
$txn0 = (int) one('SELECT count(*) FROM inventory_transactions'); $since = last_activity_id();
[$c, $b] = act($wes, '/returns/receive.php', ['return' => $ra5]);
$a = after_of(last_log('return.receive', $since));
ok($c === 200 && (int) one('SELECT count(*) FROM inventory_transactions') === $txn0 && $a['disposed'][0]['sku'] === 'SMOKE-NW-CR-Q' && $a['donated'][0]['sku'] === 'SMOKE-FND-Q' && (int) sol_of($so4, 'SMOKE-NW-CR-Q')['qty_returned'] === 1, 'dispose and donate move no stock; the log lists `disposed` and `donated`; the order lines still count them returned');

// SO-1's drop-ship King goes back to the supplier
[$c, $b] = act($sam, '/returns/save.php', ['order' => $w['so1'], 'lines' => $lines([['line' => $k1['id'], 'qty' => 1, 'reason' => 'damaged', 'disposition' => 'return_to_supplier', 'condition_note' => 'scuffed']])]);
$ra6 = (int) $b['record_id'];
act($nora, '/returns/approve.php', ['return' => $ra6]);
$txn0 = (int) one('SELECT count(*) FROM inventory_transactions'); $since = last_activity_id();
$po = (int) one('SELECT pl.purchase_order_id FROM sales_order_lines sol JOIN purchase_order_lines pl ON pl.id = sol.purchase_order_line_id WHERE sol.id = :s', ['s' => $k1['id']]);
$ev0 = count(po_events_of($po));
[$c, $b] = act($wes, '/returns/receive.php', ['return' => $ra6]);
$a = after_of(last_log('return.receive', $since));
$ev = po_events_of($po);
ok($c === 200 && (int) one('SELECT count(*) FROM inventory_transactions') === $txn0, 'a return to the supplier writes no stock movement');
ok(count($ev) === $ev0 + 1 && end($ev)['kind'] === 'note' && str_contains((string) end($ev)['note'], 'Return ' . return_row($ra6)['number']) && str_contains((string) po_row($po)['internal_notes'], 'to return to supplier') && (int) $a['to_supplier'][0]['purchase_order_id'] === $po, 'it adds a note event and a line in the supplier\'s purchase order notes; the log has to_supplier [{sku, qty, purchase_order_id}]');

echo "4. Close\n";
$pay0 = (int) one('SELECT count(*) FROM order_payments WHERE sales_order_id = :o', ['o' => $w['so1']]);
[$c] = act($vera, '/returns/close.php', ['return' => $ra1, 'refund_amount' => '10']);
ok($c === 403, 'a Viewer closing: 403');
[$c] = act($sam, '/returns/close.php', ['return' => $ra1, 'refund_amount' => '10']);
ok($c === 403, 'Sam closing: 403 (returns.write)');
[$c, $d] = screen($nora, '/returns/?awaiting_disposition=1');
ok($c === 200 && in_array($ra1, array_column($d['returns'], 'return_id'), true), 'awaiting disposition holds the received return until it is closed');
[$c, $b] = act($nora, '/returns/close.php', ['return' => $ra1, 'refund_amount' => '2500.00', 'restocking_fee' => '50']);
ok($c === 422 && str_contains(msg($b), 'The refund cannot exceed what was paid (2,000.00)') && isset(fields($b)['refund_amount']), 'a refund above what was paid: 422, naming the amount');
[$c, $b] = act($nora, '/returns/close.php', ['return' => $ra1, 'refund_amount' => '12.345']);
ok($c === 422 && isset(fields($b)['refund_amount']), 'a refund in more than cents: refused');
[$c, $b] = act($nora, '/returns/close.php', ['return' => $ra2, 'refund_amount' => '10']);
ok($c === 422 && str_contains(msg($b), 'a received return closes'), 'closing a denied return: the verb\'s words');
$since = last_activity_id(); $nid = last_notification_id();
[$c, $b] = act($nora, '/returns/close.php', ['return' => $ra1, 'refund_amount' => '1295.00', 'restocking_fee' => '50.00']);
$r1 = return_row($ra1);
ok($c === 200 && $r1['status'] === 'closed' && $r1['refund_amount'] === '1295.00' && $r1['restocking_fee'] === '50.00' && (int) $r1['closed_by'] === 40, 'closed with a refund of 1,295.00 and a restocking fee of 50.00 on the row');
ok((int) one('SELECT count(*) FROM order_payments WHERE sales_order_id = :o', ['o' => $w['so1']]) === $pay0, 'no payment was written on the order (DECISION 4)');
$a = after_of(last_log('return.close', $since));
ok($a['refund_amount'] === '1295.00' && $a['currency'] === 'USD' && $a['status'] === 'closed', 'logged return.close with the amounts and the currency');
$n = notifications_for(41, 'return', $nid);
ok(count($n) === 1 && str_contains($n[0]['title'], 'closed — refund 1,295.00 to record'), 'the requester is told "closed — refund 1,295.00 to record"');
$r = req('GET', "/returns/$ra1", ['jar' => $nora]);
ok(preg_match('~href="/orders/' . $w['so1'] . '/payment\?kind=refund&amp;amount=1295\.00~', $r['body']) === 1 && str_contains($r['body'], 'id="return-view-refund-btn"'), 'the return links "Record the refund" with the amount filled in');
$p = req('GET', "/orders/{$w['so1']}/payment?kind=refund&amount=1295.00", ['jar' => $nora]);
ok($p['code'] === 200 && preg_match('/id="order-payment-field-amount"[^>]*value="1295\.00"/', $p['body']) === 1, 'and the payment screen opens with 1295.00 in the amount');
[$c, $d] = screen($nora, '/returns/?awaiting_disposition=1');
ok(!in_array($ra1, array_column($d['returns'], 'return_id'), true), 'after the close it is no longer awaiting disposition');
as_db(40);
ok(one('SELECT 1 FROM inv_returns_open() WHERE return_id = :r', ['r' => $ra1]) === false && one('SELECT 1 FROM inv_returns_open() WHERE return_id = :r', ['r' => $ra6]) !== false, 'inv_returns_open() no longer lists it (the approved-and-received ones still open do)');
ok((int) one("SELECT count(*) FROM inv_order_timeline(:o) WHERE title LIKE 'return.%'", ['o' => $w['so1']]) >= 5, 'the order\'s timeline shows the return\'s rows');
[$c] = act($nora, '/orders/payments/refund.php', ['order' => $w['so1'], 'amount' => '1295.00', 'method' => 'card']);
$r = req('GET', "/returns/$ra1", ['jar' => $nora]);
ok($c === 200 && !str_contains($r['body'], 'id="return-view-refund-btn"') && str_contains($r['body'], 'id="return-view-refund"'), 'once that refund is recorded on the order the link goes');
finish();

<?php
/** By hand (purchasing.md "Proof" ≈ 25): the supplier called or wrote and a person records it — acknowledge the order or a line, decline a line, tracking, the events with their source chips, the customer's line and shipment, delivered = received. */
require __DIR__ . '/lib.php';
$w = purchasing_world();
$nora = $w['nora'];
$sam = $w['sam'];
$run = substr(md5((string) microtime(true)), 0, 6);
$since = last_activity_id();
$D = dropship_pair($w, "S6-HAND-$run");
$pm = $D['malouf'];
$pz = $D['zinus'];
$ord = $D['order'];
$ordNo = order_row($ord)['number'];
$pmNo = po_row($pm)['number'];
$pzNo = po_row($pz)['number'];

// ---- acknowledge: the whole order
[, $b] = make_po($nora, $w['zinus'], [['variant' => $w['pillow_v'], 'qty' => 1, 'unit_cost' => '20']], ['notes' => "S6-HANDDRAFT-$run"]);
$draft = (int) $b['record_id'];
[$c, $b] = act($nora, '/purchasing/acknowledge.php', ['purchase_order' => $draft, 'supplier_ref' => 'Z-1']);
ok($c === 422 && str_contains(msg($b), 'is draft — a sent order is acknowledged'), 'acknowledge a draft: the SQL\'s sentence, 422');
$ackDate = date('Y-m-d', strtotime('+6 days'));
$since2 = last_activity_id();
[$c, $b] = act($nora, '/purchasing/acknowledge.php', ['purchase_order' => $pz, 'supplier_ref' => 'ZN-77123', 'expected_on' => $ackDate]);
$row = po_row($pz);
ok($c === 200 && $row['status'] === 'acknowledged' && $row['supplier_order_ref'] === 'ZN-77123' && $row['expected_on'] === $ackDate && $row['acknowledged_at'] !== null, 'purchase_order_acknowledge (the whole order): acknowledged, their reference, the expected date moved, acknowledged_at set');
ok(po_lines_of($pz)[0]['status'] === 'acknowledged' && po_lines_of($pz)[0]['expected_on'] === $ackDate, '…every open line acknowledged with the date');
$ev = array_values(array_filter(po_events_of($pz), static fn ($e) => $e['kind'] === 'acknowledge'));
ok(count($ev) === 1 && $ev[0]['source'] === 'manual' && (int) $ev[0]['member_id'] === 40 && $ev[0]['purchase_order_line_id'] === null && $ev[0]['supplier_order_ref'] === 'ZN-77123', 'one `acknowledge` event: source manual, by Nora, for the whole order');
$al = last_log('purchase_order.supplier_ack', $since2);
ok($al !== null && $al['source'] === 'web' && (int) $al['actor_member_id'] === 40 && (int) $al['purchase_order_id'] === $pz && (int) $al['sales_order_id'] === $ord && after_of($al)['supplier_order_ref'] === 'ZN-77123', 'purchase_order.supplier_ack is logged, source web, with the order');
[$c, $b] = act($nora, '/purchasing/acknowledge.php', ['purchase_order' => $pz, 'expected_on' => 'next tuesday']);
ok($c === 422 && isset(fields($b)['expected_on']), 'a date that is not a date: a field error');
[$c, $b] = act($nora, '/purchasing/acknowledge.php', ['purchase_order' => $pz, 'line' => $D['ck_line']]);
ok($c === 422 && msg($b) === 'That line is not on this purchase order.', 'a line of another order: 422 in words (the SQL would not have noticed)');
// one line of two
$two = static function (string $tag) use ($nora, $w): int {
    [, $b] = make_po($nora, $w['zinus'], [['variant' => $w['twin'], 'qty' => 2], ['variant' => $w['king'], 'qty' => 1]], ['notes' => $tag]);
    act($nora, '/purchasing/send.php', ['purchase_order' => (int) $b['record_id'], 'via' => 'phone']);
    return (int) $b['record_id'];
};
$p2 = $two("S6-HAND2-$run");
$l2 = po_lines_of($p2);
[$c, $b] = act($nora, '/purchasing/acknowledge.php', ['purchase_order' => $p2, 'line' => $l2[0]['id'], 'supplier_ref' => 'ZN-LINE']);
$l2 = po_lines_of($p2);
ok($c === 200 && $l2[0]['status'] === 'acknowledged' && $l2[1]['status'] === 'open' && po_row($p2)['status'] === 'sent', 'acknowledge one line: that line acknowledged, the other open, the order still sent');
$ev = array_values(array_filter(po_events_of($p2), static fn ($e) => $e['kind'] === 'acknowledge'));
ok(count($ev) === 1 && (int) $ev[0]['purchase_order_line_id'] === (int) $l2[0]['id'], '…the event names the line');

// ---- decline a line (the Cal King at Malouf)
$nsince = last_notification_id();
$since3 = last_activity_id();
[$c, $b] = act($nora, '/purchasing/decline.php', ['line' => $D['ck_line']]);
ok($c === 422 && isset(fields($b)['reason']), 'decline without a reason: 422');
[$c, $b] = act($nora, '/purchasing/decline.php', ['line' => $D['ck_line'], 'reason' => 'Discontinued by the mill']);
$cl = po_lines_of($pm)[0];
$sl = q('SELECT * FROM sales_order_lines WHERE id = :id', ['id' => $cl['sales_order_line_id']])[0];
ok($c === 200 && $cl['status'] === 'declined' && $cl['supplier_note'] === 'Discontinued by the mill' && $sl['status'] === 'open' && (float) po_row($pm)['total'] === 0.0, 'purchase_order_line_decline: the line declined with the reason, the customer\'s line open again, the order\'s total dropped to 0.00');
$n = notifications_for(41, 'line_at_risk', $nsince);
ok(count($n) === 1 && (int) $n[0]['record_id'] === $ord && $n[0]['title'] === "Line {$sl['line_no']} of $ordNo was declined by SMOKE Malouf: Discontinued by the mill" && $b['salesperson_told'] === true, 'Sam, the order\'s salesperson, is told (line_at_risk): "Line N of SO-… was declined by SMOKE Malouf: …"');
$ev = array_values(array_filter(po_events_of($pm), static fn ($e) => $e['kind'] === 'decline'));
ok(count($ev) === 1 && $ev[0]['source'] === 'manual' && (int) $ev[0]['member_id'] === 40 && $ev[0]['reason'] === 'Discontinued by the mill', 'one `decline` event with the reason, source manual');
$dl = last_log('purchase_order.supplier_decline', $since3);
ok($dl !== null && $dl['source'] === 'web' && after_of($dl)['reason'] === 'Discontinued by the mill' && (int) $dl['sales_order_id'] === $ord, 'purchase_order.supplier_decline is logged, source web');
[$c, $b] = act($nora, '/purchasing/decline.php', ['line' => $D['ck_line'], 'reason' => 'again']);
ok($c === 422 && str_contains(msg($b), 'declined'), 'declining a declined line: the SQL\'s sentence');
[$c, $b] = act($nora, '/purchasing/tracking.php', ['line' => $D['ck_line'], 'carrier' => 'UPS', 'tracking' => '1Z999']);
ok($c === 422 && str_contains(msg($b), 'is declined'), 'tracking on a declined line: the SQL\'s sentence');
$r = req('GET', "/orders/$ord", ['jar' => $sam]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="order-dropship-' . $pm . '"'), 'the order page still lists the declined purchase order (the Buyer sees what happened)');

// ---- tracking (the King at Zinus): the customer's dropship shipment
$since4 = last_activity_id();
[$c, $b] = act($nora, '/purchasing/tracking.php', ['line' => $D['k_line'], 'carrier' => 'UPS']);
ok($c === 422 && isset(fields($b)['tracking']), 'tracking without a number: 422');
$shipAt = date('Y-m-d\TH:i', strtotime('-1 hour'));
[$c, $b] = act($nora, '/purchasing/tracking.php', ['line' => $D['k_line'], 'carrier' => 'UPS', 'tracking' => '1Z999AA10123456784', 'shipped_at' => $shipAt]);
$kl = po_lines_of($pz)[0];
$sh = q("SELECT * FROM shipments WHERE sales_order_id = :o AND kind = 'dropship' ORDER BY id DESC", ['o' => $ord]);
$ksl = q('SELECT * FROM sales_order_lines WHERE id = :id', ['id' => $kl['sales_order_line_id']])[0];
ok($c === 200 && $kl['status'] === 'shipped' && $kl['tracking_carrier'] === 'UPS' && $kl['tracking_number'] === '1Z999AA10123456784' && $kl['shipped_at'] !== null, 'purchase_order_tracking: the line shipped with the carrier and number');
ok(count($sh) === 1 && $sh[0]['tracking_number'] === '1Z999AA10123456784' && $sh[0]['tracking_url'] === 'https://www.ups.com/track?tracknum=1Z999AA10123456784' && (int) $b['shipment_id'] === (int) $sh[0]['id'], 'a `dropship` shipment on the sales order, with the tracking link written from the carrier map');
ok($ksl['status'] === 'shipped' && (int) $ksl['qty_shipped'] === 1, 'the customer\'s line is shipped');
$tl = last_log('purchase_order.supplier_tracking', $since4);
ok($tl !== null && $tl['source'] === 'web' && after_of($tl)['tracking_number'] === '1Z999AA10123456784' && after_of($tl)['carrier'] === 'UPS' && (int) $tl['sales_order_id'] === $ord, 'purchase_order.supplier_tracking is logged with the carrier and number');
$ev = array_values(array_filter(po_events_of($pz), static fn ($e) => $e['kind'] === 'tracking'));
ok(count($ev) === 1 && $ev[0]['source'] === 'manual' && $ev[0]['tracking_number'] === '1Z999AA10123456784', 'one `tracking` event');
[$c, $b] = act($nora, '/purchasing/decline.php', ['line' => $D['k_line'], 'reason' => 'too late']);
ok($c === 422 && str_contains(msg($b), 'shipped') && str_contains(msg($b), 'cannot be declined'), 'decline a shipped line: the SQL\'s sentence');
$r = req('GET', "/purchasing/$pz", ['jar' => $nora]);
ok(str_contains($r['body'], 'id="po-events"') && substr_count($r['body'], 'id="po-event-') >= 3 && str_contains($r['body'], 'by hand') && str_contains($r['body'], '1Z999AA10123456784') && str_contains($r['body'], 'ZN-77123'), 'the events table shows every row with its source chip ("by hand")');
$r = req('GET', "/orders/$ord", ['jar' => $sam]);
ok(str_contains($r['body'], '1Z999AA10123456784'), 'the order page shows the tracking');

// ---- delivered = received
$wes = $w['wes'];
[$c, $b] = act($wes, '/orders/deliver.php', ['order' => $ord]);
$kl = po_lines_of($pz)[0];
ok($c === 200 && $kl['status'] === 'received' && (int) $kl['qty_received'] === 1 && po_row($pz)['status'] === 'received', 'delivering the customer\'s drop-ship shipment (slice 5\'s verb): the purchase order line is received and the order too');
$kinds = array_column(po_events_of($pz), 'kind');
ok(in_array('received', $kinds, true), '…with a `received` event');
// the lead times of the supplier now have a row
$r = req('GET', "/suppliers/{$w['zinus']}?tab=lead", ['jar' => $nora]);
ok(str_contains($r['body'], 'id="supplier-lead-times-row"') && str_contains($r['body'], 'id="supplier-shipped-' . $kl['id'] . '"'), 'the supplier\'s Lead times tab now has the actuals and the shipped line');
finish();

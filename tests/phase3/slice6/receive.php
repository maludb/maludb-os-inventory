<?php
/** Receive against, close, cancel (purchasing.md "Proof" ≈ 25): a draft goods receipt from the open lines, the posting short by one, close short, cancel and what it frees. */
require __DIR__ . '/lib.php';
$w = purchasing_world();
$nora = $w['nora'];
$sam = $w['sam'];
$wes = $w['wes'];
$run = substr(md5((string) microtime(true)), 0, 6);
$since = last_activity_id();
$grn = static fn (int $id): array => q('SELECT * FROM goods_receipts WHERE id = :id', ['id' => $id])[0];
$grl = static fn (int $id): array => q('SELECT * FROM goods_receipt_lines WHERE goods_receipt_id = :id ORDER BY line_no', ['id' => $id]);

// ---- a stock order of two lines, sent
[, $b] = make_po($nora, $w['zinus'], [['variant' => $w['twin'], 'qty' => 4], ['variant' => $w['king'], 'qty' => 2]], ['notes' => "S6-RECV-$run"]);
$po = (int) $b['record_id'];
$num = po_row($po)['number'];
[$c, $b] = act($nora, '/purchasing/receive.php', ['purchase_order' => $po]);
ok($c === 422 && msg($b) === "Purchase order $num is draft — a sent order is received", 'receive against a draft: the SQL\'s sentence, 422');
act($nora, '/purchasing/send.php', ['purchase_order' => $po, 'via' => 'phone']);
$r = req('GET', "/purchasing/$po/receive", ['jar' => $nora]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="po-receive-lines"') && str_contains($r['body'], 'id="po-receive-location"') && !str_contains($r['body'], 'id="po-receive-submit" disabled') && str_contains($r['body'], 'id="po-receive-line-' . po_lines_of($po)[0]['id'] . '"'), 'the receive screen lists the open lines and the receiving location; Draft the receipt is enabled');
$r = req('GET', "/purchasing/$po/receive", ['jar' => $sam]);
ok($r['code'] === 403, 'the receive screen is Sam\'s 403 (no stock.receive)');
[$c, $b] = act($sam, '/purchasing/receive.php', ['purchase_order' => $po]);
ok($c === 403, 'purchase_order_receive: Sam 403');

// ---- Wes drafts the receipt (he holds stock.receive)
[$c, $b] = act($wes, '/purchasing/receive.php', ['purchase_order' => $po]);
$g1 = (int) ($b['goods_receipt_id'] ?? 0);
$lines = $g1 ? $grl($g1) : [];
ok($c === 200 && $g1 > 0 && str_contains((string) $b['location'], "/receipts/$g1/edit") && $b['lines'] === 2 && $b['units'] === 6 && $b['refresh'] === 'purchaseOrderChanged', 'purchase_order_receive (Wes): a goods receipt, location /receipts/{id}/edit, 2 lines, 6 units');
ok($grn($g1)['status'] === 'draft' && (int) $grn($g1)['purchase_order_id'] === $po && (int) $grn($g1)['location_id'] === $w['wh'] && (int) $grn($g1)['supplier_id'] === $w['zinus'] && count($lines) === 2, 'a DRAFT receipt against the order, at its ship-to (the Warehouse), for Zinus');
ok((int) $lines[0]['qty'] === 4 && (float) $lines[0]['unit_cost'] === 349.5 && (int) $lines[1]['qty'] === 2 && (float) $lines[1]['unit_cost'] === 649.0 && (int) $lines[0]['purchase_order_line_id'] === (int) po_lines_of($po)[0]['id'], 'a line per open line, at the order\'s cost, tied to the order\'s lines');
$rl = last_log('purchase_order.receive', $since);
ok($rl !== null && (int) $rl['purchase_order_id'] === $po && (int) $rl['location_id'] === $w['wh'] && after_of($rl)['goods_receipt_id'] === $g1 && after_of($rl)['lines'] === 2 && after_of($rl)['units'] === 6 && $rl['actor_member_id'] == 42, 'purchase_order.receive is logged (Wes) with the receipt, lines and units');
$r = req('GET', "/purchasing/$po", ['jar' => $nora]);
ok(str_contains($r['body'], 'id="po-receipt-' . $g1 . '"') && str_contains($r['body'], 'href="/receipts/' . $g1 . '?back='), 'the purchase-order page lists the receipt, linked to slice 2\'s screen');
$r = req('GET', "/receipts/$g1/edit", ['jar' => $wes]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="receipt-form"'), '/receipts/{id}/edit — slice 2\'s receipt screen — opens it');
// undo = receipt_cancel
[$c, $b] = act($wes, '/receipts/cancel.php', ['receipt' => $g1]);
ok($c === 200 && $grn($g1)['status'] === 'cancelled' && po_row($po)['status'] === 'sent', 'receipt_cancel undoes it: the receipt cancelled, the order untouched');

// ---- Nora drafts another and posts it short by one
[$c, $b] = act($nora, '/purchasing/receive.php', ['purchase_order' => $po, 'location' => $w['sr']]);
$g2 = (int) $b['record_id'];
$g2 = (int) one('SELECT max(id) FROM goods_receipts WHERE purchase_order_id = :p', ['p' => $po]);
ok($c === 200 && (int) $grn($g2)['location_id'] === $w['sr'], 'a `location` other than the ship-to is honoured (the Showroom)');
psql_exec("UPDATE goods_receipt_lines SET qty = 3 WHERE goods_receipt_id = $g2 AND line_no = 1; UPDATE goods_receipt_lines SET unit_cost = 655.00 WHERE goods_receipt_id = $g2 AND line_no = 2");
$twinBefore = (int) one('SELECT COALESCE(sum(qty_on_hand), 0) FROM inventory_balances WHERE variant_id = :v AND location_id = :l', ['v' => $w['twin'], 'l' => $w['sr']]);
[$c, $b] = act($wes, '/receipts/post.php', ['receipt' => $g2]);
$pl = po_lines_of($po);
ok($c === 200 && $grn($g2)['status'] === 'posted' && po_row($po)['status'] === 'partial', 'posting the receipt (slice 2\'s receipt_post), short by one Twin: the order is partial');
ok((int) $pl[0]['qty_received'] === 3 && $pl[0]['status'] === 'partial' && (int) $pl[1]['qty_received'] === 2 && $pl[1]['status'] === 'received', 'the Twin line 3 of 4 (partial), the King line 2 of 2 (received)');
$recv = array_filter(po_events_of($po), static fn ($e) => $e['kind'] === 'received');
ok(count($recv) === 2, 'two `received` events');
ok((int) one('SELECT COALESCE(sum(qty_on_hand), 0) FROM inventory_balances WHERE variant_id = :v AND location_id = :l', ['v' => $w['twin'], 'l' => $w['sr']]) === $twinBefore + 3 && (float) one('SELECT cost_price FROM product_variants WHERE id = :v', ['v' => $w['king']]) === 655.0, 'the stock moved (3 Twins into the Showroom) and the King\'s cost followed the receipt (655.00, last_receipt)');
[$c, $b] = act($nora, '/purchasing/cancel.php', ['purchase_order' => $po, 'reason' => 'too late']);
ok($c === 422 && str_contains(msg($b), 'is partial — close it instead'), 'cancel a partly received order: the SQL\'s sentence "…is partial — close it instead"');
[$c, $b] = act($nora, '/purchasing/receive.php', ['purchase_order' => $po]);
$g3 = (int) $b['goods_receipt_id'];
ok($c === 200 && $b['lines'] === 1 && $b['units'] === 1, 'receiving against it again drafts a receipt for what is still open: one Twin');
// close it short instead
[$c, $b] = act($nora, '/purchasing/close.php', ['purchase_order' => $po]);
$pl = po_lines_of($po);
ok($c === 200 && $b['status'] === 'closed_short' && $b['lines_short'] === 1 && po_row($po)['status'] === 'closed_short' && $pl[0]['status'] === 'closed_short' && $pl[1]['status'] === 'received' && po_row($po)['closed_at'] !== null, 'close: closed_short, the open Twin line closed_short, the received line left; closed_at set');
$cl = last_log('purchase_order.close', $since);
ok($cl !== null && after_of($cl)['status'] === 'closed_short' && after_of($cl)['lines_short'] === 1, 'purchase_order.close is logged with the status and the lines short');
[$c, $b] = act($nora, '/purchasing/close.php', ['purchase_order' => $po]);
ok($c === 422 && str_contains(msg($b), 'is closed_short — it does not close'), 'closing a closed order: the SQL\'s sentence');
[$c, $b] = make_po($nora, $w['zinus'], [['variant' => $w['pillow_v'], 'qty' => 1, 'unit_cost' => '20']]);
[$c, $b] = act($nora, '/purchasing/close.php', ['purchase_order' => (int) $b['record_id']]);
ok($c === 422 && str_contains(msg($b), 'is draft — it does not close'), 'close a draft: 422 in the SQL\'s words');

// ---- closed, with the link's 90 days
[$pe, $tok] = sent_po($w, $w['zinus'], [['variant' => $w['pillow_v'], 'qty' => 2, 'unit_cost' => '20']], "S6-CLOSE-$run");
ok(req('GET', "/s/$tok")['code'] === 200, 'a purchase order sent by e-mail has a live link');
act($nora, '/purchasing/close.php', ['purchase_order' => $pe]);
$lk = q('SELECT * FROM supplier_links_secure WHERE purchase_order_id = :p AND rotated_at IS NULL', ['p' => $pe])[0];
$days = (strtotime((string) $lk['expires_at']) - strtotime((string) po_row($pe)['closed_at'])) / 86400;
ok(po_row($pe)['status'] === 'closed_short' && $days > 89.9 && $days < 90.1, 'closing starts the link\'s 90 days (expires_at = closed_at + 90 days)');
// received in full, then closed
[$pf, $tf] = sent_po($w, $w['zinus'], [['variant' => $w['pillow_v'], 'qty' => 2, 'unit_cost' => '20']], "S6-FULL-$run");
[, $b] = act($nora, '/purchasing/receive.php', ['purchase_order' => $pf]);
act($wes, '/receipts/post.php', ['receipt' => (int) $b['goods_receipt_id']]);
ok(po_row($pf)['status'] === 'received', 'a receipt posted in full: the order is received');
[$c, $b] = act($nora, '/purchasing/close.php', ['purchase_order' => $pf]);
ok($c === 200 && $b['status'] === 'closed' && $b['lines_short'] === 0 && po_row($pf)['status'] === 'closed', 'closing a received order: `closed`, nothing short');

// ---- cancel: a draft, a sent order (its link expires at once), a sent drop-ship
[, $b] = make_po($nora, $w['zinus'], [['variant' => $w['pillow_v'], 'qty' => 1, 'unit_cost' => '20']], ['notes' => "S6-CANCEL-$run"]);
$cd = (int) $b['record_id'];
$since2 = last_activity_id();
[$c, $b] = act($nora, '/purchasing/cancel.php', ['purchase_order' => $cd, 'reason' => 'Ordered twice']);
ok($c === 200 && po_row($cd)['status'] === 'cancelled' && po_row($cd)['cancel_reason'] === 'Ordered twice' && (int) po_row($cd)['cancelled_by'] === 40 && last_log('purchase_order.cancel', $since2) !== null, 'cancel a draft: cancelled with the reason; purchase_order.cancel is logged');
ok(array_column(po_lines_of($cd), 'status') === ['cancelled'] && array_column(po_events_of($cd), 'kind') === ['cancelled'], 'its lines cancelled and a `cancelled` event written');
[$c, $b] = act($nora, '/purchasing/cancel.php', ['purchase_order' => $cd, 'reason' => 'again']);
ok($c === 422 && str_contains(msg($b), 'is cancelled — close it instead'), 'cancelling a cancelled order: the SQL\'s sentence');
[$ps, $ts] = sent_po($w, $w['zinus'], [['variant' => $w['pillow_v'], 'qty' => 1, 'unit_cost' => '20']], "S6-CANCEL2-$run");
[$c, $b] = act($nora, '/purchasing/cancel.php', ['purchase_order' => $ps, 'reason' => 'Changed our mind']);
$lk = q('SELECT * FROM supplier_links_secure WHERE purchase_order_id = :p AND rotated_at IS NULL', ['p' => $ps])[0];
ok($c === 200 && strtotime((string) $lk['expires_at']) <= time() && req('GET', "/s/$ts")['code'] === 404, 'cancel a sent order: its link expired at once — the door answers 404');
[$c, $b] = act($sam, '/purchasing/cancel.php', ['purchase_order' => $ps, 'reason' => 'x']);
ok($c === 403, 'purchase_order_cancel: Sam 403');
// a sent order with a line the supplier shipped cannot be cancelled: close it short
$D0 = dropship_pair($w, "S6-SHIP-$run");
act($nora, '/purchasing/tracking.php', ['line' => $D0['k_line'], 'carrier' => 'UPS', 'tracking' => '1Z0000']);
[$c, $b] = act($nora, '/purchasing/cancel.php', ['purchase_order' => $D0['zinus'], 'reason' => 'x']);
ok($c === 422 && str_contains(msg($b), 'has goods received or shipped — close it short instead'), 'cancel an order with a shipped line: "…has goods received or shipped — close it short instead"');
// a sent drop-ship
$D = dropship_pair($w, "S6-CXL-$run");
$nsince = last_notification_id();
$kl = po_lines_of($D['zinus'])[0];
[$c, $b] = act($nora, '/purchasing/cancel.php', ['purchase_order' => $D['zinus'], 'reason' => 'Supplier closed']);
$sl = q('SELECT * FROM sales_order_lines WHERE id = :id', ['id' => $kl['sales_order_line_id']])[0];
ok($c === 200 && $sl['status'] === 'open' && $sl['purchase_order_line_id'] === null && $b['lines_released'] === 1 && $b['salesperson_told'] === true, 'cancel a sent drop-ship: the customer\'s line open again and free to draft, the salesperson told');
$n = notifications_for(41, 'line_at_risk', $nsince);
ok(count($n) === 1 && $n[0]['title'] === po_row($D['zinus'])['number'] . ' with SMOKE Zinus was cancelled: Supplier closed', 'Sam\'s line_at_risk reads "PO-… with SMOKE Zinus was cancelled: Supplier closed"');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
ok($reg['actions']['purchase_order_cancel']['approval'] === 'deletion' && $reg['actions']['purchase_order_receive']['approval'] === null, 'purchase_order_cancel is `deletion` in the registry; receive is free');
finish();

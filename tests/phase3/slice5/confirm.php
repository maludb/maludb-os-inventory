<?php
/** Confirm (orders.md "Proof" ≈ 30): what the screen says, the cost wall on it, a line that cannot be covered, the allocation, one draft purchase order per supplier, no e-mail, the order page and its timeline, the expert. */
require __DIR__ . '/lib.php';
$w = order_world();
$sam = $w['sam'];
$nora = $w['nora'];
$ref = 'S5-MAIN';
$since = last_activity_id();
$mails = count(mail_log());

// ---- the main order: a Queen from the Warehouse, a Cal King from Malouf, a King from Zinus, a pillow on back order
$qb0 = balance_of($w['queen'], $w['wh']);
[$c, $b] = make_quote($sam, $w['alvarez'], [
    ['variant' => $w['queen'], 'qty' => 1, 'fulfilment' => 'stock:' . $w['wh']],
    ['variant' => $w['calking'], 'qty' => 1, 'discount' => '100', 'fulfilment' => 'dropship:' . $w['lv_malouf_ck']],
    ['variant' => $w['king'], 'qty' => 1, 'fulfilment' => 'dropship:' . $w['lv_zinus_k']],
    ['variant' => $w['pillow_v'], 'qty' => 2, 'fulfilment_kind' => 'backorder', 'location' => $w['wh']],
], ['customer_reference' => $ref, 'location' => $w['sr'], 'promised_on' => date('Y-m-d'), 'delivery_method' => 'delivery', 'shipping' => '50']);
$oid = (int) ($b['record_id'] ?? 0);
$lines = order_lines_of($oid);
ok($c === 200 && count($lines) === 4 && (float) order_row($oid)['shipping_charge'] === 50.0, 'the main quote: Queen (stock), Cal King (Malouf), King (Zinus), pillows (backorder), 50.00 shipping');

// ---- the screen
$r = req('GET', "/orders/$oid/confirm", ['jar' => $sam]);
$t = preg_replace('/\s+/', ' ', strip_tags($r['body']));
ok($r['code'] === 200 && str_contains($t, 'Allocates 1 at SMOKE Warehouse (3 available)'), 'the confirm screen says "allocates 1 at SMOKE Warehouse (3 available)"');
ok(str_contains($t, 'Drafts a purchase order to SMOKE Zinus · 3 days') && str_contains($t, 'Drafts a purchase order to SMOKE Malouf · 5 days'), '…"drafts a purchase order to SMOKE Zinus · 3 days" and "… to SMOKE Malouf · 5 days"');
ok(str_contains($t, 'Waits for stock at SMOKE Warehouse') && str_contains($t, '2 purchase orders will be drafted'), '…the backorder waits for stock; the count of purchase orders is visible (2)');
ok(preg_match('/id="order-confirm-line-' . $lines[1]['id'] . '"/', $r['body']) === 1 && str_contains($r['body'], 'id="order-confirm-submit"'), '…a row per line (order-confirm-line-{id}) and the Confirm button');
ok(!str_contains($t, 'cost 1299') && !str_contains($t, 'cost 649'), 'Sam\'s screen carries no cost figure');
$rn = req('GET', "/orders/$oid/confirm", ['jar' => $nora]);
$tn = preg_replace('/\s+/', ' ', strip_tags($rn['body']));
ok(str_contains($tn, 'cost 1,299.00') && str_contains($tn, 'cost 649.00'), 'Nora\'s screen carries them (cost 1,299.00 and cost 649.00)');
ok(req('GET', "/orders/$oid/confirm", ['jar' => $w['wes']])['code'] === 403, 'the screen is for orders.write: Wes is refused');

// ---- a line that cannot be covered
[$c, $b] = make_quote($sam, $w['alvarez'], [['variant' => $w['queen'], 'qty' => 9, 'fulfilment' => 'stock:' . $w['wh']]], ['customer_reference' => 'S5-NINE']);
$nid = (int) $b['record_id'];
$nl = order_lines_of($nid)[0];
$r = req('GET', "/orders/$nid/confirm", ['jar' => $sam]);
$t = preg_replace('/\s+/', ' ', strip_tags($r['body']));
ok(str_contains($t, 'Cannot cover: 3 available at SMOKE Warehouse') && str_contains($r['body'], 'id="order-confirm-line-' . $nl['id'] . '-backorder-btn"') && str_contains($r['body'], 'text-danger'), 'with the Queen\'s qty at 9 the screen says "cannot cover: 3 available" and offers "Make it a backorder"');
$qb1 = balance_of($w['queen'], $w['wh']);
[$c, $b] = act($sam, '/orders/confirm.php', ['order' => $nid]);
$qb2 = balance_of($w['queen'], $w['wh']);
ok($c === 422 && str_contains(msg($b), 'only 3 available') && str_contains(msg($b), 'backorder') && order_row($nid)['status'] === 'quote' && (int) $qb2['qty_allocated'] === (int) $qb1['qty_allocated'], 'confirm is refused in the SQL\'s sentence ("only 3 available … make it a backorder") and nothing is allocated');
[$c, $b] = act($sam, '/orders/lines/fulfilment.php', ['line' => $nl['id'], 'fulfilment_kind' => 'backorder', 'location' => $w['wh']]);
ok($c === 200 && one('SELECT fulfilment_kind FROM sales_order_lines WHERE id = :id', ['id' => $nl['id']]) === 'backorder', '"Make it a backorder" posts order_line_fulfilment_set');
[$c, $b] = act($sam, '/orders/confirm.php', ['order' => $nid]);
ok($c === 200 && order_row($nid)['status'] === 'confirmed' && (int) balance_of($w['queen'], $w['wh'])['qty_allocated'] === (int) $qb1['qty_allocated'], 'the confirmation then passes, holding nothing for the backorder');
// a confirmed order with a backorder only has nothing to draft: cancel it (keeps the Queen free for the rest)
act($sam, '/orders/cancel.php', ['order' => $nid, 'reason' => 'SMOKE: the nine-queen test']);

// ---- confirm the main order
[$c, $b] = act($sam, '/orders/confirm.php', ['order' => $oid]);
$o = order_row($oid);
$lines = order_lines_of($oid);
$qb3 = balance_of($w['queen'], $w['wh']);
ok($c === 200 && $o['status'] === 'confirmed' && (int) $o['confirmed_by'] === 41 && $o['confirmed_at'] !== null, 'confirmed: status confirmed, confirmed_by Sam, confirmed_at set');
ok((int) $lines[0]['qty_allocated'] === 1 && $lines[0]['status'] === 'allocated' && (int) $qb3['qty_allocated'] === (int) $qb0['qty_allocated'] + 1, 'the Queen is allocated: qty_allocated 1 on the line, one more on the Warehouse balance');
ok($lines[3]['status'] === 'open' && (int) $lines[3]['qty_allocated'] === 0 && $lines[1]['status'] === 'open', 'the backorder line stays open and holds nothing');
$pos = q("SELECT po.*, s.name AS supplier FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id WHERE po.sales_order_id = :o ORDER BY po.id", ['o' => $oid]);
ok(count($pos) === 2 && count(array_unique(array_column($pos, 'supplier_id'))) === 2 && array_unique(array_column($pos, 'status')) === ['draft'] && array_unique(array_column($pos, 'kind')) === ['dropship'], 'two draft drop-ship purchase orders, one per supplier');
ok($pos[0]['ship_to_kind'] === 'customer' && $pos[0]['ship_to_name'] === 'SMOKE Alvarez' && $pos[1]['ship_to_name'] === 'SMOKE Alvarez', 'addressed to the customer (the order\'s ship-to)');
$pl = q('SELECT pl.*, sol.id AS sol_id, sol.offer_cost AS sol_cost, sol.offer_lead_time_days AS sol_lead, sol.purchase_order_line_id AS back FROM purchase_order_lines pl JOIN sales_order_lines sol ON sol.id = pl.sales_order_line_id WHERE sol.sales_order_id = :o ORDER BY sol.line_no', ['o' => $oid]);
ok(count($pl) === 2 && (float) $pl[0]['unit_cost'] === (float) $pl[0]['sol_cost'] && (float) $pl[1]['unit_cost'] === (float) $pl[1]['sol_cost'] && $pl[0]['expected_on'] === date('Y-m-d', strtotime('+' . (int) $pl[0]['sol_lead'] . ' days')), 'their lines carry the order line\'s snapshotted cost and lead time (expected = today + lead)');
ok((int) $pl[0]['back'] === (int) $pl[0]['id'] && (int) $pl[1]['back'] === (int) $pl[1]['id'], 'each order line points at its purchase order line');
$log = last_log('order.confirm', $since);
$a = after_of($log);
ok($log !== null && (int) $log['sales_order_id'] === $oid && $a['allocated'][0]['sku'] === 'SMOKE-NW-CR-Q' && count($a['dropships_drafted']) === 2 && $a['backordered'] === ['SMOKE-NW-PIL-STD'] && $a['status'] === 'confirmed' && $a['currency'] === 'USD', 'order.confirm is logged with allocated, dropships_drafted (two ids), backordered, status and currency');
ok(count(mail_log()) === $mails, 'confirming sent no e-mail (the fake MaluMail\'s log is unchanged)');
[$c, $b] = act($sam, '/orders/confirm.php', ['order' => $oid]);
ok($c === 422 && str_contains(msg($b), 'only a quote is confirmed'), 'confirming twice: 422 "only a quote is confirmed"');
psql_exec("INSERT INTO sales_orders (customer_id, customer_reference) VALUES ({$w['alvarez']}, 'S5-EMPTY')");
[$c, $b] = act($sam, '/orders/confirm.php', ['order' => ref_order('S5-EMPTY')]);
ok($c === 422 && str_contains(msg($b), 'has no lines'), 'a quote with no lines: 422 "has no lines"');

// ---- the order page and its timeline
$r = req('GET', "/orders/$oid", ['jar' => $nora]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="order-dropships"') && str_contains($r['body'], '/purchasing/' . $pos[0]['id']) && str_contains($r['body'], '/purchasing/' . $pos[1]['id']), 'the order page shows the two purchase orders under order-dropships, linked');
[$c, $dn] = screen($nora, "/orders/$oid?tab=timeline");
[$c2, $ds] = screen($sam, "/orders/$oid?tab=timeline");
$draftN = array_values(array_filter($dn['timeline'], static fn ($t) => $t['kind'] === 'po_draft'));
$draftS = array_values(array_filter($ds['timeline'], static fn ($t) => $t['kind'] === 'po_draft'));
ok(count($draftN) === 2 && count($draftS) === 2, 'the timeline carries a po_draft row per purchase order');
ok($draftN[0]['detail']['total'] !== null && $draftS[0]['detail']['total'] === null, 'a purchase order\'s total shows for Nora and is null for Sam');
$tr = req('GET', "/orders/$oid?tab=timeline", ['jar' => $sam]);
ok($tr['code'] === 200 && str_contains($tr['body'], 'id="order-timeline-0"') && str_contains($tr['body'], 'Confirmed'), 'the timeline tab renders its rows (order-timeline-{n})');
$r = req('GET', "/orders/$oid", ['jar' => $sam]);
ok(str_contains($r['body'], 'id="order-view-actions"') && str_contains($r['body'], 'id="order-view-send-btn"') && str_contains($r['body'], 'id="order-view-payment-btn"') && !str_contains($r['body'], 'order-view-confirm-btn') && !str_contains($r['body'], 'order-view-ship-btn'), 'the actions follow the state and the right: Send and Record payment, no Confirm, no Ship (Sam holds no stock.ship)');
$r = req('GET', "/orders/$oid", ['jar' => $w['wes']]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="order-view-ship-btn"') && !str_contains($r['body'], 'order-view-payment-btn') && !str_contains($r['body'], 'id="order-payments"') && !str_contains($r['body'], 'order-link'), 'Wes sees Ship and no money: no payments panel, no payment button, no link card');

// ---- the expert confirms on the actions path
[$c, $b] = make_quote($sam, $w['alvarez'], [['variant' => $w['pillow_v'], 'qty' => 1, 'fulfilment_kind' => 'backorder', 'location' => $w['wh']]], ['customer_reference' => 'S5-EXPERT']);
$eid = (int) $b['record_id'];
kernel_state(function ($s) { $s['facts']['95'] = ['valid' => true, 'is_agent' => true, 'member_id' => 45, 'run_id' => 95, 'request_id' => 'req-run-95', 'trigger' => 'chat', 'endpoints' => [['name' => 'Records MCP']]]; return $s; });
$since = last_activity_id();
[$c, $b] = act_token('/orders/confirm.php', ['order' => $eid], as_agent(run_token(45, 95)));
$log = last_log('order.confirm', $since);
ok($c === 200 && order_row($eid)['status'] === 'confirmed' && (int) order_row($eid)['confirmed_by'] === 45 && $log !== null && $log['source'] === 'agent' && (int) $log['agent_run_id'] === 95, 'the expert (a run token through the relay) confirms: confirmed_by 45, logged as the agent, run 95');
kernel_state(function ($s) { unset($s['facts']); return $s; });
act($sam, '/orders/cancel.php', ['order' => $eid, 'reason' => 'SMOKE: the expert test']);
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
ok($reg['actions']['order_confirm']['approval'] === 'other' && $reg['actions']['order_confirm']['endpoint'] === '/orders/confirm.php' && $reg['screens']['order-confirm']['built'] === true, 'the registry lists order_confirm as `other` at /orders/confirm.php; the screen built');
finish();

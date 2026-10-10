<?php
/** JSON mode (purchasing.md "Proof" ≈ 10): every handler under a signed action token answers {ok, did, record_id, location, refresh} with its facts; `_partial=1` keeps a supplier's fields; the refusals' shape; the registry reads the 11 screens and 18 actions built. */
require __DIR__ . '/lib.php';
$w = purchasing_world();
$N = ['X-Action-Token: ' . person_token(40)];
$W = ['X-Action-Token: ' . person_token(42)];
$S = ['X-Action-Token: ' . person_token(41)];
$run = substr(md5((string) microtime(true)), 0, 6);
$shape = static fn (array $b, string $refresh): bool => ($b['ok'] ?? false) === true && is_string($b['did'] ?? null) && isset($b['record_id'], $b['location']) && ($b['refresh'] ?? '') === $refresh;

echo "1. A person's action token\n";
[$c, $b] = act_token('/suppliers/save.php', ['name' => "SMOKE Json $run", 'kind' => 'wholesaler', 'email' => "json-$run@example.invalid", 'order_email' => "orders-json-$run@example.invalid", 'account_number' => 'J-1', 'terms' => 'Net 10', 'dropships' => 'yes', 'lead_time_days' => '4'], $N);
$sid = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $shape($b, 'supplierChanged') && $b['supplier_id'] === $sid && $b['location'] === "/suppliers/$sid?notice=created", 'supplier_create: {ok, did, record_id, location, refresh} and supplier_id');
[$c, $b] = act_token('/suppliers/save.php', ['supplier' => $sid, '_partial' => '1', 'phone' => '555-0142'], $N);
$row = supplier_row($sid);
ok($c === 200 && $row['phone'] === '555-0142' && $row['kind'] === 'wholesaler' && $row['account_number'] === 'J-1' && $row['terms'] === 'Net 10' && $row['dropships'] === true && (int) $row['lead_time_days'] === 4 && $row['order_email'] === "orders-json-$run@example.invalid", 'supplier_update with `_partial=1`: only the phone is sent, every other field stays');
[$c, $b] = act_token('/suppliers/save.php', ['name' => ''], $N);
ok($c === 422 && ($b['error']['code'] ?? '') === 'invalid' && isset($b['error']['fields']['name']) && is_array($b['error']['errors'] ?? null), 'a refusal: 422 {error: {code: invalid, message, errors[], fields}}');
[$c, $b] = act_token('/purchasing/save.php', ['supplier' => $sid, 'location' => $w['wh'], 'notes' => "S6-JSON-$run", 'lines' => json_encode([['variant' => $w['twin'], 'qty' => 2, 'unit_cost' => '300'], ['variant' => 'SMOKE-NW-PIL-STD', 'qty' => 3, 'unit_cost' => '20']])], $N);
$po = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $shape($b, 'purchaseOrderChanged') && $b['purchase_order_id'] === $po && preg_match('/^PO-\d+$/', (string) $b['number']) === 1 && $b['status'] === 'draft' && $b['supplier_id'] === $sid && $b['lines'] === 2 && $b['total'] === '660.00', 'purchase_order_draft: the facts (purchase_order_id, number, supplier_id, status, lines, total)');
[$c, $b] = act_token('/purchasing/save.php', ['purchase_order' => $po, '_partial' => '1', 'expected_on' => date('Y-m-d', strtotime('+5 days'))], $N);
$row = po_row($po);
ok($c === 200 && $row['expected_on'] === date('Y-m-d', strtotime('+5 days')) && $row['notes'] === "S6-JSON-$run" && (int) $row['location_id'] === $w['wh'] && (int) $row['supplier_id'] === $sid && count(po_lines_of($po)) === 2, 'purchase_order_update with `_partial=1`: the date changes; the notes, ship-to, supplier and lines stay');
[$c, $b] = act_token('/purchasing/lines/save.php', ['purchase_order' => $po, 'variant' => $w['queen'], 'qty' => 1, 'unit_cost' => '480'], $N);
$nl = (int) ($b['line_id'] ?? 0);
ok($c === 200 && $shape($b, 'purchaseOrderChanged') && $nl > 0 && $b['purchase_order_id'] === $po, 'purchase_order_line_add: line_id and purchase_order_id');
[$c, $b] = act_token('/purchasing/lines/save.php', ['line' => $nl, 'qty' => 2], $N);
ok($c === 200 && $b['line_id'] === $nl && (int) one('SELECT qty_ordered FROM purchase_order_lines WHERE id = :id', ['id' => $nl]) === 2 && (float) one('SELECT unit_cost FROM purchase_order_lines WHERE id = :id', ['id' => $nl]) === 480.0, 'purchase_order_line_update: any field of the add; the cost stays');
[$c, $b] = act_token('/purchasing/lines/remove.php', ['line' => $nl], $N);
ok($c === 200 && $b['line_id'] === $nl && one('SELECT count(*) FROM purchase_order_lines WHERE id = :id', ['id' => $nl]) == 0, 'purchase_order_line_remove: line_id');
[$c, $b] = act_token('/purchasing/send.php', ['purchase_order' => $po, 'message' => 'Json hello'], $N);
ok($c === 200 && $shape($b, 'purchaseOrderChanged') && $b['link_id'] > 0 && $b['sent_via'] === 'email' && str_starts_with((string) $b['message_id'], '<mm-'), 'purchase_order_send: link_id, sent_via, message_id');
$lines = po_lines_of($po);
[$c, $b] = act_token('/purchasing/acknowledge.php', ['purchase_order' => $po, 'supplier_ref' => 'J-77', 'line' => $lines[0]['id']], $N);
ok($c === 200 && $shape($b, 'purchaseOrderChanged') && $b['line_id'] === (int) $lines[0]['id'], 'purchase_order_acknowledge: line_id');
[$c, $b] = act_token('/purchasing/decline.php', ['line' => $lines[1]['id'], 'reason' => 'json'], $N);
ok($c === 200 && $shape($b, 'purchaseOrderChanged') && $b['line_id'] === (int) $lines[1]['id'] && $b['salesperson_told'] === false, 'purchase_order_line_decline: line_id, salesperson_told (a stock order: nobody)');
[$c, $b] = act_token('/purchasing/tracking.php', ['line' => $lines[0]['id'], 'carrier' => 'FedEx', 'tracking' => '7777'], $N);
ok($c === 200 && $shape($b, 'purchaseOrderChanged') && $b['shipment_id'] === null && $b['tracking_url'] === null, 'purchase_order_tracking: shipment_id and tracking_url (null for a stock order)');
[$c, $b] = act_token('/purchasing/receive.php', ['purchase_order' => $po], $W);
ok($c === 200 && $shape($b, 'purchaseOrderChanged') && $b['goods_receipt_id'] > 0 && $b['lines'] === 1 && $b['units'] === 2 && str_contains((string) $b['location'], '/receipts/' . $b['goods_receipt_id'] . '/edit'), 'purchase_order_receive under Wes\'s token (stock.receive): goods_receipt_id, lines, units; the location is the receipt');
[$c, $b] = act_token('/purchasing/link-rotate.php', ['purchase_order' => $po], $N);
ok($c === 200 && $shape($b, 'purchaseOrderChanged') && $b['link_id'] > 0 && $b['mailed'] === true, 'purchase_order_link_rotate: link_id, mailed');
[$c, $b] = act_token('/purchasing/close.php', ['purchase_order' => $po], $N);
ok($c === 200 && $shape($b, 'purchaseOrderChanged') && $b['status'] === 'closed_short', 'purchase_order_close: status');
[$c, $b] = act_token('/suppliers/message.php', ['supplier' => $sid, 'subject' => 'Json', 'body' => 'Hello', 'purchase_order' => $po], $N);
ok($c === 200 && $b['purchase_order_id'] === $po && str_starts_with((string) $b['message_id'], '<mm-'), 'supplier_message: purchase_order_id and message_id');
[, $b] = act_token('/purchasing/save.php', ['supplier' => $sid, 'lines' => json_encode([['variant' => $w['twin'], 'qty' => 1, 'unit_cost' => '300']])], $N);
$po2 = (int) $b['record_id'];
[$c, $b] = act_token('/purchasing/place.php', ['purchase_order' => $po2, 'supplier_ref' => 'J-PLACED', 'via' => 'edi'], $N);
ok($c === 200 && $shape($b, 'purchaseOrderChanged') && $b['supplier_order_ref'] === 'J-PLACED' && $b['sent_via'] === 'edi', 'purchase_order_place: supplier_order_ref, sent_via');
[$c, $b] = act_token('/purchasing/cancel.php', ['purchase_order' => $po2, 'reason' => 'json'], $N);
ok($c === 200 && $shape($b, 'purchaseOrderChanged') && $b['status'] === 'cancelled' && $b['lines_released'] === 0, 'purchase_order_cancel: status, lines_released');
[$c, $b] = act_token('/suppliers/archive.php', ['supplier' => $sid], $N);
ok($c === 200 && $shape($b, 'supplierChanged') && $b['active'] === false, 'supplier_archive: active false');
// a drop-ship draft: the purchase orders drafted
$D = dropship_pair($w, "S6-JSON2-$run");
act_token('/purchasing/cancel.php', ['purchase_order' => $D['malouf'], 'reason' => 'json'], $N);
[$c, $b] = act_token('/purchasing/save.php', ['kind' => 'dropship', 'order' => $D['order']], $N);
ok($c === 200 && $shape($b, 'purchaseOrderChanged') && $b['purchase_orders'] === [$b['record_id']] && $b['sales_order_id'] === $D['order'], 'a drop-ship draft: `purchase_orders` names every one drafted; the sales order');
[$c, $b] = act_token('/purchasing/send.php', ['purchase_order' => $po2], $S);
ok($c === 403 && ($b['error']['code'] ?? '') === 'forbidden', 'Sam\'s token on purchase_order_send: 403 {error: {code: forbidden}}');

echo "2. The screens as JSON\n";
[$c, $d] = screen($w['nora'], '/purchasing/');
ok($c === 200 && isset($d['purchase_orders'], $d['filters'], $d['total']) && isset($d['purchase_orders'][0]['number']), 'GET /purchasing/ answers {filters, page, total, purchase_orders[]}');
[$c, $d] = screen($w['nora'], "/purchasing/$po");
ok($c === 200 && $d['purchase_order']['number'] === po_row($po)['number'] && count($d['purchase_order']['lines']) === 2 && count($d['purchase_order']['events']) >= 4 && $d['purchase_order']['link'] !== null, 'GET /purchasing/{id} answers the order with its lines, events and link');
[$c, $d] = screen($w['nora'], "/purchasing/$po/send");
ok($c === 200 && isset($d['can_send'], $d['subject']), 'GET /purchasing/{id}/send answers can_send, the refusal and the subject');
[$c, $d] = screen($w['nora'], "/suppliers/{$w['zinus']}");
ok($c === 200 && isset($d['supplier'], $d['items'], $d['open_orders'], $d['lead_times']) && $d['supplier']['name'] === 'SMOKE Zinus', 'GET /suppliers/{id} answers the supplier, its price sheet, open orders, lead times and sources');

echo "3. The registry\n";
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$names = ['supplier_create', 'supplier_update', 'supplier_archive', 'supplier_message', 'purchase_order_draft', 'purchase_order_update', 'purchase_order_line_add', 'purchase_order_line_update', 'purchase_order_line_remove', 'purchase_order_send',
          'purchase_order_place', 'purchase_order_acknowledge', 'purchase_order_line_decline', 'purchase_order_tracking', 'purchase_order_receive', 'purchase_order_close', 'purchase_order_cancel', 'purchase_order_link_rotate'];
$bad = [];
foreach ($names as $n) { if (empty($reg['actions'][$n]['built']) || !is_file(dirname(__DIR__, 3) . '/html' . $reg['actions'][$n]['endpoint'])) { $bad[] = $n; } }
ok($bad === [] && count($names) === 18, 'the registry lists all eighteen actions built, each at a file that exists' . ($bad ? ' — not: ' . implode(', ', $bad) : ''));
$screens = ['purchase-order-list', 'purchase-order-add', 'purchase-order-view', 'purchase-order-edit', 'purchase-order-send', 'purchase-order-receive', 'supplier-list', 'supplier-add', 'supplier-view', 'supplier-edit', 'supplier-door'];
$bad = array_values(array_filter($screens, static fn ($s) => empty($reg['screens'][$s]['built'])));
ok($bad === [] && count($screens) === 11, 'and the eleven screens built' . ($bad ? ' — not: ' . implode(', ', $bad) : ''));
$approvals = ['purchase_order_send' => 'money_out', 'purchase_order_place' => 'money_out', 'supplier_message' => 'external_send', 'purchase_order_cancel' => 'deletion'];
$bad = [];
foreach ($approvals as $n => $cat) { if (($reg['actions'][$n]['approval'] ?? null) !== $cat) { $bad[] = $n; } }
ok($bad === [] && count(array_filter($names, static fn ($n) => ($reg['actions'][$n]['approval'] ?? null) !== null)) === 4, 'exactly four carry an approval category: send, place (money_out); message (external_send); cancel (deletion)');
$built = count(array_filter($reg['screens'], static fn ($s) => !empty($s['built'])));
$builtA = count(array_filter($reg['actions'], static fn ($a) => !empty($a['built'])));
ok($built === 83 && $builtA === 103, "the registry reads $built screens and $builtA actions built (72 + 11, 85 + 18)");
$out = shell_exec('cd ' . escapeshellarg(dirname(__DIR__, 3)) . ' && php bin/sync_approvals.php --check >/dev/null 2>&1; echo $?');
ok(trim((string) $out) === '0', 'bin/sync_approvals.php --check: maludb-os.json approvals[] matches the manifest');
finish();

<?php
/** JSON mode (orders.md "Proof" ≈ 15): every handler under a signed action token answers {ok, did, record_id, location, refresh} with its facts; the refusals' shape; the expert on a run token; the registry's twenty actions. */
require __DIR__ . '/lib.php';
$w = order_world();
$S = ['X-Action-Token: ' . person_token(41)];
$W = ['X-Action-Token: ' . person_token(42)];
$run = substr(md5((string) microtime(true)), 0, 6);
$shape = static fn (array $b, string $refresh): bool => ($b['ok'] ?? false) === true && is_string($b['did'] ?? null) && isset($b['record_id'], $b['location']) && ($b['refresh'] ?? '') === $refresh;
echo "1. A person's action token\n";
[$c, $b] = act_token('/customers/save.php', ['name' => "SMOKE Json $run", 'email' => "json-$run@example.invalid", 'phone' => '312-555-0600', 'shipping_address' => '5 Json Way'], $S);
$cid = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $shape($b, 'customerChanged') && $b['customer_id'] === $cid && $b['location'] === "/customers/$cid?notice=created", 'customer_create: {ok, did, record_id, location, refresh} and customer_id');
[$c, $b] = act_token('/orders/save.php', ['customer' => $cid, 'location' => $w['sr'], 'delivery_method' => 'pickup', 'promised_on' => date('Y-m-d'), 'lines' => json_encode([
    ['variant' => $w['queen'], 'qty' => 1, 'fulfilment_kind' => 'pickup', 'location' => $w['wh']], ['variant' => $w['king'], 'qty' => 1, 'fulfilment_kind' => 'dropship', 'listing_variant' => $w['lv_zinus_k']]])], $S);
$oid = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $shape($b, 'orderChanged') && $b['sales_order_id'] === $oid && preg_match('/^SO-\d+$/', (string) $b['number']) === 1 && $b['status'] === 'quote' && $b['lines'] === 2 && (int) $b['location_id'] === $w['sr'], 'quote_create: the facts (sales_order_id, number, status, lines, location_id); lines as JSON in the manifest\'s shape');
$lines = order_lines_of($oid);
[$c, $b] = act_token('/orders/lines/save.php', ['order' => $oid, 'variant' => 'SMOKE-NW-PIL-STD', 'qty' => 2], $S);
$nl = (int) ($b['line_id'] ?? 0);
ok($c === 200 && $shape($b, 'orderChanged') && $nl > 0 && $b['line_ids'] === [$nl], 'order_line_add: the line_id (a SKU names the variant)');
[$c, $b] = act_token('/orders/lines/save.php', ['line' => $nl, 'qty' => 3], $S);
ok($c === 200 && $b['line_id'] === $nl && (int) one('SELECT qty FROM sales_order_lines WHERE id = :id', ['id' => $nl]) === 3, 'order_line_update: any field of the add; the rest stays');
[$c, $b] = act_token('/orders/lines/fulfilment.php', ['line' => $nl, 'fulfilment_kind' => 'backorder', 'location' => $w['wh']], $S);
ok($c === 200 && $b['fulfilment_kind'] === 'backorder' && $b['offer_cost'] === null, 'order_line_fulfilment_set: the kind in the answer');
[$c, $b] = act_token('/orders/lines/cancel.php', ['line' => $nl], $S);
ok($c === 200 && $b['released'] === 0 && one('SELECT status FROM sales_order_lines WHERE id = :id', ['id' => $nl]) === 'cancelled', 'order_line_cancel: released 0, the line cancelled');
[$c, $b] = act_token('/orders/save.php', ['customer' => $cid, 'lines' => json_encode([['variant' => 'NOPE', 'qty' => 1]])], $S);
ok($c === 422 && ($b['error']['code'] ?? '') === 'invalid' && isset($b['error']['fields']) && is_array($b['error']['errors'] ?? null), 'a refusal: 422 {error: {code: invalid, message, errors[], fields}}');
[$c, $b] = act_token('/orders/confirm.php', ['order' => $oid], $S);
ok($c === 200 && $shape($b, 'orderChanged') && $b['allocated'][0]['sku'] === 'SMOKE-NW-CR-Q' && count($b['dropships_drafted']) === 1 && $b['backordered'] === [], 'order_confirm: allocated[], dropships_drafted[], backordered[]');
[$c, $b] = act_token('/orders/payments/save.php', ['order' => $oid, 'kind' => 'deposit', 'amount' => '500', 'method' => 'cash'], $S);
ok($c === 200 && $shape($b, 'orderChanged') && $b['payment_status'] === 'deposit' && $b['amount_paid'] === '500.00' && is_numeric($b['balance_due']), 'payment_record: payment_id, payment_status, amount_paid, balance_due');
[$c, $b] = act_token('/orders/payments/refund.php', ['order' => $oid, 'amount' => '50', 'method' => 'cash'], $S);
ok($c === 200 && $b['payment_status'] === 'partial_refund' && $b['amount_paid'] === '450.00', 'refund_record: partial_refund, amount_paid 450.00');
[$c, $b] = act_token('/orders/send.php', ['order' => $oid, 'message' => 'Hello'], $S);
ok($c === 200 && $shape($b, 'orderChanged') && $b['link_id'] > 0 && $b['rotated'] === false && str_starts_with((string) $b['message_id'], '<mm-'), 'order_send: link_id, rotated, message_id');
[$c, $b] = act_token('/orders/notify.php', ['order' => $oid, 'kind' => 'ready_for_pickup'], $S);
ok($c === 200 && $b['kind'] === 'ready_for_pickup' && $b['message_id'] !== null, 'order_notify: kind and message_id');
[$c, $b] = act_token('/orders/link-rotate.php', ['order' => $oid], $S);
ok($c === 200 && $b['link_id'] > 0, 'order_link_rotate: link_id');
[$c, $b] = act_token('/orders/ship.php', ['order' => $oid, 'kind' => 'pickup', 'lines' => json_encode([['line_id' => $lines[0]['id'], 'qty' => 1]])], $W);
$sid = (int) ($b['shipment_id'] ?? 0);
ok($c === 200 && $shape($b, 'orderChanged') && $sid > 0 && $b['issued'][0]['sku'] === 'SMOKE-NW-CR-Q' && $b['lines'][0]['qty'] === 1, 'order_ship (Wes\'s token): shipment_id, issued[], lines[]');
[$c, $b] = act_token('/orders/deliver.php', ['order' => $oid], $W);
ok($c === 422 && str_contains((string) ($b['error']['message'] ?? ''), 'has no undelivered shipment'), 'order_deliver on an order whose pickup was delivered at once: 422 "…has no undelivered shipment."');
[$c, $b] = act_token('/orders/cancel.php', ['order' => $oid, 'reason' => 'SMOKE json'], $S);
ok($c === 422 && str_contains((string) ($b['error']['message'] ?? ''), 'take a return instead'), 'order_cancel with a shipped line: 422 in words');
[$c, $b] = act_token('/orders/close.php', ['order' => $oid], $S);
ok($c === 422 && str_contains((string) ($b['error']['message'] ?? ''), 'a delivered order closes'), 'order_close on an order with a drop-ship line still open: 422 in words');
[$c, $b] = act_token('/orders/save.php', ['customer' => $cid, 'lines' => json_encode([['variant' => $w['pillow_v'], 'qty' => 1, 'fulfilment_kind' => 'backorder', 'location' => $w['wh']]])], $S);
$x = (int) $b['record_id'];
[$c, $b] = act_token('/orders/cancel.php', ['order' => $x, 'reason' => 'SMOKE json'], $S);
ok($c === 200 && $shape($b, 'orderChanged') && $b['status'] === 'cancelled' && $b['released'] === [] && $b['dropships_cancelled'] === 0, 'order_cancel: status, released[], dropships_cancelled');
[$c, $b] = act_token('/customers/archive.php', ['customer' => $cid], $S);
ok($c === 422 && str_contains((string) ($b['error']['message'] ?? ''), 'open order'), 'customer_archive with an order open: 422 in words');
[$c, $b] = act_token('/customers/save.php', ['name' => "SMOKE Json2 $run"], $S);
[$c2, $b2] = act_token('/customers/delete.php', ['customer' => $b['record_id']], ['X-Action-Token: ' . person_token(1)]);
ok($c2 === 200 && $shape($b2, 'customerChanged'), 'customer_delete (the owner\'s token): {ok, did, record_id, location, refresh}');

echo "2. The expert\n";
kernel_state(function ($s) { $s['facts']['96'] = ['valid' => true, 'is_agent' => true, 'member_id' => 45, 'run_id' => 96, 'request_id' => 'req-run-96', 'trigger' => 'chat', 'endpoints' => [['name' => 'Records MCP']]]; return $s; });
$rt = run_token(45, 96);
$since = last_activity_id();
[$c, $b] = act_token('/orders/save.php', ['customer' => $cid, 'lines' => json_encode([['variant' => $w['queen'], 'qty' => 1, 'fulfilment_kind' => 'stock', 'location' => $w['wh']]])], as_agent($rt));
$eid = (int) ($b['record_id'] ?? 0);
$log = last_log('order.quote', $since);
ok($c === 200 && $eid > 0 && order_row($eid)['origin'] === 'agent' && (int) order_row($eid)['created_by'] === 45 && (int) order_row($eid)['salesperson_member_id'] === 45, 'the expert writes a quote with `lines` JSON: origin agent, created_by and salesperson 45');
ok($log !== null && $log['source'] === 'agent' && (int) $log['agent_run_id'] === 96 && $log['request_id'] === 'req-run-96', '…logged as the agent, run 96, the run\'s request id');
[$c, $b] = act_token('/orders/send.php', ['order' => $eid], as_agent($rt));
ok($c === 422 && str_contains((string) ($b['error']['message'] ?? ''), 'Confirm it first'), 'the expert may ask to send (it holds orders.send; the kernel\'s pause is Phase 4\'s) — a quote is refused in words');
[$c, $b] = act_token('/customers/delete.php', ['customer' => $cid], as_agent($rt));
ok($c === 403, 'the expert may not delete a customer (records.delete): 403');
kernel_state(function ($s) { unset($s['facts']); return $s; });

echo "3. The registry\n";
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$names = ['customer_create', 'customer_update', 'customer_archive', 'customer_delete', 'quote_create', 'order_update', 'order_line_add', 'order_line_update', 'order_line_fulfilment_set', 'order_line_cancel', 'order_confirm', 'order_send', 'order_notify',
          'payment_record', 'refund_record', 'order_ship', 'order_deliver', 'order_close', 'order_cancel', 'order_link_rotate'];
$bad = [];
foreach ($names as $n) { if (empty($reg['actions'][$n]['built']) || !is_file(dirname(__DIR__, 3) . '/html' . $reg['actions'][$n]['endpoint'])) { $bad[] = $n; } }
ok($bad === [] && count($names) === 20, 'the registry lists all twenty actions built, each at a file that exists' . ($bad ? ' — not: ' . implode(', ', $bad) : ''));
$screens = ['customer-list', 'customer-add', 'customer-view', 'customer-edit', 'order-list', 'order-add', 'order-view', 'order-edit', 'order-confirm', 'order-payment', 'order-ship', 'order-send', 'fulfilment-today', 'shipment-list', 'shipment-view', 'customer-door'];
$bad = array_values(array_filter($screens, static fn ($s) => empty($reg['screens'][$s]['built'])));
ok($bad === [] && count($screens) === 16, 'and the sixteen screens built' . ($bad ? ' — not: ' . implode(', ', $bad) : ''));
$approvals = ['order_confirm' => 'other', 'payment_record' => 'other', 'refund_record' => 'other', 'order_send' => 'external_send', 'order_notify' => 'external_send', 'order_cancel' => 'deletion', 'customer_delete' => 'deletion'];
$bad = [];
foreach ($approvals as $n => $cat) { if (($reg['actions'][$n]['approval'] ?? null) !== $cat) { $bad[] = $n; } }
ok($bad === [] && count(array_filter($names, static fn ($n) => ($reg['actions'][$n]['approval'] ?? null) !== null)) === 7, 'exactly seven carry an approval category: confirm, payment, refund (other); send, notify (external_send); cancel, customer delete (deletion)');
finish();

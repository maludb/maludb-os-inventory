<?php
/** A quote on a phone (orders.md "Proof" ≈ 45): the form prefilled by Find, the pick list, a three-line quote and what the triggers did, the refusals in words, a set that expands, the edit form's lines. */
require __DIR__ . '/lib.php';
$w = order_world();
$sam = $w['sam'];
$wes = $w['wes'];
$vera = $w['vera'];
$retail = static fn (int $v): string => number_format((float) one('SELECT retail_price FROM product_variants WHERE id = :v', ['v' => $v]), 2, '.', '');
$since = last_activity_id();

// ---- the form, prefilled the way Find's "Sell this" does it
$r = req('GET', "/orders/new?customer={$w['alvarez']}&variant={$w['queen']}&qty=1&fulfilment=stock&location={$w['wh']}", ['jar' => $sam]);
$html = $r['body'];
ok($r['code'] === 200 && str_contains($html, 'id="order-form"') && str_contains($html, 'id="order-line-0"') && str_contains($html, 'value="' . $w['queen'] . '"'), '/orders/new prefills the first row with the variant (the form, the line, the hidden variant)');
ok(preg_match('/id="lines-0-choice-stock-' . $w['wh'] . '"[^>]*checked/', $html) === 1, 'the picker\'s Warehouse radio is checked (stock:' . $w['wh'] . ')');
ok(str_contains($html, 'SMOKE Alvarez') && str_contains($html, 'id="order-form-field-customer"') && str_contains($html, 'value="' . $w['alvarez'] . '"'), 'the customer picker shows the customer from ?customer=');
ok(str_contains($html, 'id="order-line-1"') && str_contains($html, 'id="order-line-template"') && str_contains($html, 'id="order-lines-add"'), 'a blank row stands beneath it, with the template and the Add line button');
ok(preg_match('/<option value="(\d+)" selected>Cook County/', $html) === 1 && str_contains($html, 'name="delivery_method"'), 'the default tax rate (Cook County) is preselected; the delivery method is a field');
// the pick list
$r = req('GET', '/find/pick?q=cloudrest', ['jar' => $sam, 'headers' => ['HX-Request: true']]);
$n = substr_count($r['body'], 'data-variant-id=');
ok($r['code'] === 200 && $n >= 6 && $n <= 20, "the pick list answers \"cloudrest\" with $n rows (≤ 20)");
$r = req('GET', '/find/pick?q=bulkline', ['jar' => $sam, 'headers' => ['HX-Request: true']]);
ok(substr_count($r['body'], 'data-variant-id=') === 20, 'the pick list is capped at 20 rows (fifty-five pillows match)');
// the compact picker the row loads
$r = req('GET', "/find/availability?variant={$w['king']}&compact=1&qty=1&field=lines%5B1%5D", ['jar' => $sam, 'headers' => ['HX-Request: true']]);
ok($r['code'] === 200 && str_contains($r['body'], 'name="lines[1][fulfilment]"') && str_contains($r['body'], 'value="dropship:' . $w['lv_zinus_k'] . '"'), 'a row\'s picker loads (radios named lines[1][fulfilment], the Zinus drop-ship among them)');
$r = req('GET', "/find/availability?variant={$w['king']}&compact=1&qty=1&field=lines%5B1%5D&choose=dropship:{$w['lv_zinus_k']}", ['jar' => $sam, 'headers' => ['HX-Request: true']]);
ok(preg_match('/value="dropship:' . $w['lv_zinus_k'] . '"[^>]*checked/', $r['body']) === 1, '`choose` keeps the chosen radio checked when the picker reloads (a quantity change)');

// ---- a three-line quote
$promise = date('Y-m-d', strtotime('+4 days'));
[$c, $b] = act($sam, '/orders/save.php', ['customer' => $w['alvarez'], 'location' => $w['sr'], 'delivery_method' => 'delivery', 'promised_on' => $promise, 'customer_reference' => 'PO-77', 'lines' => [
    ['variant' => $w['queen'], 'qty' => '1', 'fulfilment' => 'stock:' . $w['wh']],
    ['variant' => $w['calking'], 'qty' => '1', 'discount' => '100', 'fulfilment' => 'dropship:' . $w['lv_malouf_ck']],
    ['variant' => $w['king'], 'qty' => '1', 'fulfilment' => 'dropship:' . $w['lv_zinus_k']],
]]);
$oid = (int) ($b['record_id'] ?? 0);
$o = $oid ? order_row($oid) : [];
$lines = $oid ? order_lines_of($oid) : [];
ok($c === 200 && $oid > 0 && preg_match('/^SO-\d+$/', (string) ($o['number'] ?? '')) === 1 && $o['status'] === 'quote' && $b['status'] === 'quote' && (int) $b['lines'] === 3, 'the quote is made: numbered SO-…, status quote, three lines');
ok(($o['origin'] ?? '') === 'entered' && (int) $o['created_by'] === 41 && (int) $o['salesperson_member_id'] === 41 && (int) $o['location_id'] === $w['sr'], 'origin entered, created by Sam, Sam the salesperson, the Showroom the store');
ok($o['ship_to_name'] === 'SMOKE Alvarez' && $o['ship_to_address1'] === "22 Elm Street\nUnit 4" && (int) $o['tax_rate_id'] === $w['cook'], 'the ship-to came from the customer (the trigger) and the tax rate is the default');
$sub = array_sum(array_map(static fn ($l) => (float) $l['line_total'], $lines));
ok(count($lines) === 3 && (float) $lines[0]['unit_price'] === (float) $retail($w['queen']) && (float) $lines[1]['discount'] === 100.0 && (float) $lines[1]['line_total'] === (float) $retail($w['calking']) - 100.0, 'the lines are priced at retail; the Cal King\'s discount is taken off its line');
ok(abs((float) $o['subtotal'] - $sub) < 0.005 && abs((float) $o['tax_total'] - round($sub * 0.1025, 2)) < 0.005 && abs((float) $o['total'] - ($sub + round($sub * 0.1025, 2))) < 0.005 && (float) $o['discount_total'] === 100.0, 'the totals and the tax are the database\'s (10.25 % of the subtotal)');
ok($lines[1]['fulfilment_kind'] === 'dropship' && (int) $lines[1]['source_id'] === $w['src_malouf'] && (float) $lines[1]['offer_cost'] === 1299.0 && (int) $lines[1]['offer_lead_time_days'] === 5 && $lines[1]['location_id'] === null, 'the Cal King drop-ship carries its offer: Malouf, cost 1299.00, 5 days, no location');
ok((int) $lines[2]['source_id'] === $w['src_zinus_feed'] && (float) $lines[2]['offer_cost'] === 649.0 && (int) $lines[2]['offer_lead_time_days'] === 3, 'the King drop-ship carries the Zinus feed\'s offer: cost 649.00, 3 days');
$log = last_log('order.quote', $since);
$a = after_of($log);
ok($log !== null && (int) $log['sales_order_id'] === $oid && (int) $log['location_id'] === $w['sr'] && $a['number'] === $o['number'] && $a['lines'] === 3 && $a['currency'] === 'USD' && (int) $a['customer_id'] === $w['alvarez'] && $log['source'] === 'web', 'order.quote is logged with the audit keys (sales_order_id, the store) and the facts');
ok($log !== null && !str_contains((string) $log['after'], 'Elm') && !str_contains((string) $log['after'], '312-555'), 'the log row carries no address and no phone');
[$c, $d] = screen($sam, "/orders/$oid");
ok($c === 200 && $d['order']['status'] === 'quote' && count($d['order']['lines']) === 3 && $d['order']['lines'][1]['offer_cost'] === null && $d['order']['lines'][1]['cost_withheld'] === true, 'the order page answers its lines to Sam with the offer\'s cost withheld (cost is the wall)');
[$c, $d] = screen($w['nora'], "/orders/$oid");
ok($c === 200 && $d['order']['lines'][1]['offer_cost'] === '1299.00' && $d['order']['lines'][2]['offer_cost'] === '649.00', 'and to Nora (the Buyer) with it: 1299.00 and 649.00');

// ---- the refusals, in words
[$c, $b] = act($sam, '/orders/save.php', ['customer' => $w['alvarez'], 'lines' => [['variant' => $w['king'], 'qty' => '1', 'fulfilment' => 'dropship:' . $w['lv_casper_k']]]]);
ok($c === 422 && str_contains(msg($b), 'needs a supplier source') && str_contains(msg($b), 'reference'), 'a drop-ship against a reference offer: the trigger\'s sentence as a 422');
[$c, $b] = act($sam, '/orders/save.php', ['customer' => $w['alvarez'], 'lines' => [['variant' => $w['queen'], 'qty' => '1', 'fulfilment' => 'dropship:' . $w['lv_malouf_ck']]]]);
ok($c === 422 && str_contains(msg($b), 'not matched to this variant'), 'a drop-ship against another variant\'s offer: 422');
[$c, $b] = act($sam, '/orders/save.php', ['customer' => $w['alvarez'], 'lines' => [['variant' => $w['queen'], 'qty' => '1', 'fulfilment_kind' => 'stock']]]);
ok($c === 422 && (str_contains(msg($b), 'location') || str_contains(json_encode($b), 'location')), 'a stock line with no location: 422 naming the location');
[$c, $b] = act($sam, '/orders/save.php', ['customer' => $w['alvarez'], 'discount' => '50', 'lines' => [['variant' => $w['queen'], 'qty' => '1', 'fulfilment' => 'stock:' . $w['wh']], ['variant' => $w['pillow_v'], 'qty' => '1', 'fulfilment' => 'backorder', 'backorder_location' => $w['wh']]]]);
ok($c === 422 && msg($b) === 'A discount is on a line.', 'a header discount with two lines: 422 "A discount is on a line."');
[$c, $b] = act($sam, '/orders/save.php', ['lines' => [['variant' => $w['queen'], 'qty' => '1', 'fulfilment' => 'stock:' . $w['wh']]]]);
ok($c === 422 && msg($b) === 'Pick a customer or name a new one.', 'no customer: 422 "Pick a customer or name a new one."');
[$c, $b] = act($sam, '/orders/save.php', ['customer' => $w['alvarez']]);
ok($c === 422 && msg($b) === 'A quote has at least one line.', 'no lines: 422 "A quote has at least one line."');
[$c, $b] = act($sam, '/orders/save.php', ['customer' => $w['alvarez'], 'lines' => [['variant' => $w['queen'], 'qty' => '1', 'fulfilment' => 'stock:' . $w['wh'], 'notes' => str_repeat('n', 201)]]]);
ok($c === 422 && str_contains(json_encode($b), 'up to 200'), 'a 201-character line note: 422');
[$c, $b] = act($sam, '/orders/save.php', ['customer' => $w['alvarez'], 'lines' => [['variant' => 'NOT-A-SKU', 'qty' => '1']]]);
ok($c === 422 && str_contains(msg($b), 'No variant has the code'), 'an unknown SKU: 422 in words');
[$c, $b] = act($vera, '/orders/save.php', ['customer' => $w['alvarez'], 'lines' => [['variant' => $w['queen'], 'qty' => '1', 'fulfilment' => 'stock:' . $w['wh']]]]);
ok($c === 403 && str_contains(msg($b), 'quote or take orders'), 'Vera (Viewer): 403 in words');
[$c, $b] = act($wes, '/orders/save.php', ['customer' => $w['alvarez'], 'lines' => [['variant' => $w['queen'], 'qty' => '1', 'fulfilment' => 'stock:' . $w['wh']]]]);
ok($c === 403, 'Wes (Warehouse) cannot quote: 403');
ok(req('GET', '/orders/new', ['jar' => $vera])['code'] === 403, 'the form is refused to Vera');

// ---- a set expands into its components; a new customer is made with the quote
$run = substr(md5((string) microtime(true)), 0, 6);
$since = last_activity_id();
[$c, $b] = act($sam, '/orders/save.php', ['new_customer_name' => "SMOKE Newcomer $run", 'new_customer_email' => "new-$run@example.invalid", 'new_customer_phone' => '312-555-0300',
    'lines' => [['variant' => $w['set_q'], 'qty' => '1']]]);
$sid = (int) ($b['record_id'] ?? 0);
$sl = $sid ? order_lines_of($sid) : [];
ok($c === 200 && count($sl) === 2 && $sl[0]['sku'] === 'SMOKE-NW-CR-Q' && (float) $sl[0]['unit_price'] === 1199.0 && $sl[1]['sku'] === 'SMOKE-FND-Q' && (float) $sl[1]['unit_price'] === 0.0, 'the set picked expands to its components: the mattress at the set\'s price (1199.00), the foundation at 0.00');
ok(($sl[0]['fulfilment_kind'] ?? '') !== '' && ($sl[1]['fulfilment_kind'] ?? '') !== '', 'each component took a recommended fulfilment (a line that names none)');
ok($sid > 0 && customer_id_of("SMOKE Newcomer $run") !== null && last_log('customer.create', $since) !== null && last_log('order.quote', $since) !== null, 'a new customer by name, email and phone is made with the quote: customer.create and order.quote are both logged');
$cust = q('SELECT * FROM customers WHERE name = :n', ['n' => "SMOKE Newcomer $run"])[0] ?? [];
ok(($cust['email'] ?? '') === "new-$run@example.invalid" && ($cust['phone'] ?? '') === '312-555-0300' && (int) ($sid ? order_row($sid)['customer_id'] : 0) === (int) ($cust['id'] ?? -1), 'the new customer\'s email and phone are kept, and the quote is theirs');
// a SKU typed (the JavaScript-free path) takes the recommended fulfilment
[$c, $b] = act($sam, '/orders/save.php', ['customer' => $w['alvarez'], 'lines' => [['variant_code' => 'SMOKE-NW-CR-Q', 'qty' => '2']]]);
$tl = $c === 200 ? order_lines_of((int) $b['record_id']) : [];
ok($c === 200 && count($tl) === 1 && (int) $tl[0]['qty'] === 2 && $tl[0]['fulfilment_kind'] === 'stock' && (int) $tl[0]['location_id'] === $w['wh'], 'a SKU typed with no fulfilment takes the recommended one (stock at the Warehouse)');

// ---- the edit form
$r = req('GET', "/orders/$oid/edit", ['jar' => $sam]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="order-form"') && str_contains($r['body'], 'id="order-lines"') && str_contains($r['body'], 'id="order-totals"') && str_contains($r['body'], 'id="order-line-' . $lines[0]['id'] . '"'), 'the edit form: the header form, each line as its own card, the totals panel');
[$c, $b] = act($sam, '/orders/save.php', ['order' => $oid, 'promised_on' => date('Y-m-d', strtotime('+6 days'))]);
ok($c === 200 && order_row($oid)['promised_on'] === date('Y-m-d', strtotime('+6 days')), 'order_update moves the promised date and leaves everything else');
$ul = last_log('order.update', $since);
ok($ul !== null && in_array('promised_on', after_of($ul)['changed'] ?? [], true), 'order.update logs the field changed by name');
[$c, $b] = act($sam, '/orders/save.php', ['order' => $oid, 'promised_on' => date('Y-m-d', strtotime('-3 days'))]);
ok($c === 422 && str_contains(msg($b), 'not before the order date'), 'a promised date before the order date: 422');
$before = (float) order_row($oid)['total'];
$r = req('POST', '/orders/lines/save.php', ['jar' => $sam, 'headers' => ['HX-Request: true', 'HX-Target: order-lines', 'X-CSRF-Token: ' . csrf_of(req('GET', '/', ['jar' => $sam])['body'])], 'form' => ['order' => $oid, 'variant' => 'SMOKE-NW-PIL-STD', 'qty' => '2']]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="order-lines"') && str_contains($r['body'], 'hx-swap-oob') && str_contains($r['body'], 'id="order-totals"') && str_contains($r['body'], 'SMOKE-NW-PIL-STD'), 'order_line_add on the edit form re-renders #order-lines and #order-totals (out of band)');
$after = order_row($oid);
ok(abs((float) $after['subtotal'] - ($sub + 2 * 59.0)) < 0.005 && (float) $after['total'] > $before, 'the order\'s totals moved by the new line (the database recomputed them)');
$pil = (int) one("SELECT id FROM sales_order_lines WHERE sales_order_id = :o AND variant_id = :v", ['o' => $oid, 'v' => $w['pillow_v']]);
[$c, $b] = act($sam, '/orders/lines/save.php', ['line' => $pil, 'qty' => '3']);
$pl = q('SELECT * FROM sales_order_lines WHERE id = :id', ['id' => $pil])[0];
ok($c === 200 && (int) $pl['qty'] === 3 && (float) $pl['line_total'] === 177.0 && abs((float) order_row($oid)['subtotal'] - ($sub + 177.0)) < 0.005, 'order_line_update: qty 3 → line total 177.00 and the order follows; the price, fulfilment and discount stayed');
$ll = last_log('order.line_update', $since);
ok($ll !== null && after_of($ll)['sku'] === 'SMOKE-NW-PIL-STD' && (int) after_of($ll)['qty'] === 3, 'order.line_update is logged with the sku and qty');
// fulfilment: stock → drop-ship re-snapshots and nulls the location; the manifest's shape too
$ql = (int) $lines[0]['id'];
[$c, $b] = act($sam, '/orders/lines/fulfilment.php', ['line' => $ql, 'fulfilment' => 'dropship:' . ((int) lv_for($w['queen'], $w['src_malouf']) ?: 0)]);
$q1 = q('SELECT * FROM sales_order_lines WHERE id = :id', ['id' => $ql])[0];
if ($c === 200) {
    ok($q1['fulfilment_kind'] === 'dropship' && $q1['location_id'] === null && $q1['offer_cost'] !== null && (int) $q1['source_id'] === $w['src_malouf'], 'order_line_fulfilment_set stock → drop-ship: the offer is snapshotted, the location nulled');
} else {
    // the fixtures may not offer the Queen from Malouf: use the King's Zinus offer on a King line instead (recorded in "Built and proven")
    echo "     (Malouf does not offer the Queen: " . msg($b) . ")\n";
    $kl = (int) $lines[2]['id'];
    [$c, $b] = act($sam, '/orders/lines/fulfilment.php', ['line' => $kl, 'fulfilment_kind' => 'stock', 'location' => $w['wh']]);
    $k1 = q('SELECT * FROM sales_order_lines WHERE id = :id', ['id' => $kl])[0];
    ok($c === 200 && $k1['fulfilment_kind'] === 'stock' && (int) $k1['location_id'] === $w['wh'] && $k1['offer_cost'] === null && $k1['source_id'] === null, 'order_line_fulfilment_set drop-ship → stock (the manifest shape): the offer is cleared');
    [$c, $b] = act($sam, '/orders/lines/fulfilment.php', ['line' => $kl, 'fulfilment_kind' => 'dropship', 'listing_variant' => $w['lv_zinus_k']]);
    $k1 = q('SELECT * FROM sales_order_lines WHERE id = :id', ['id' => $kl])[0];
    ok($c === 200 && $k1['fulfilment_kind'] === 'dropship' && $k1['location_id'] === null && (float) $k1['offer_cost'] === 649.0 && (int) $k1['offer_lead_time_days'] === 3, 'order_line_fulfilment_set stock → drop-ship (the manifest shape): re-snapshotted, location nulled');
}
[$c, $b] = act($sam, '/orders/lines/fulfilment.php', ['line' => $ql, 'fulfilment_kind' => 'dropship']);
ok($c === 422 && str_contains(json_encode($b), 'offer'), 'a drop-ship with no offer named: 422');
// cancel a line of a quote
$lineCount = (int) order_row($oid)['subtotal'];
[$c, $b] = act($sam, '/orders/lines/cancel.php', ['line' => $pil]);
$after = order_row($oid);
ok($c === 200 && one('SELECT status FROM sales_order_lines WHERE id = :id', ['id' => $pil]) === 'cancelled' && abs((float) $after['subtotal'] - $sub) < 0.005, 'order_line_cancel on a quote strikes the line (it stays, cancelled) and the totals drop it');
$r = req('GET', "/orders/$oid/edit", ['jar' => $sam]);
ok(str_contains($r['body'], 'id="order-line-' . $pil . '"') && str_contains($r['body'], 'line-through'), 'the cancelled line is shown struck through on the edit form');

// a confirmed order's lines do not change
$o2 = make_quote($sam, $w['alvarez'], [['variant' => $w['pillow_v'], 'qty' => 1, 'fulfilment_kind' => 'backorder', 'location' => $w['wh']]]);
$o2id = (int) $o2[1]['record_id'];
act($sam, '/orders/confirm.php', ['order' => $o2id]);
[$c, $b] = act($sam, '/orders/lines/save.php', ['order' => $o2id, 'variant' => 'SMOKE-NW-CR-Q', 'qty' => '1']);
ok($c === 422 && msg($b) === order_row($o2id)['number'] . ' is confirmed — cancel a line or the order instead', 'a line added to a confirmed order: 422 "SO-… is confirmed — cancel a line or the order instead"');
[$c, $b] = act($sam, '/orders/save.php', ['order' => $o2id, 'promised_on' => date('Y-m-d', strtotime('+9 days'))]);
ok($c === 422 && str_contains(msg($b), 'is confirmed'), 'order_update on a confirmed order: 422 in the same words');
$r = req('GET', "/orders/$o2id/edit", ['jar' => $sam]);
ok($r['code'] === 422 && str_contains($r['body'], 'is confirmed'), 'the edit form opens only a quote');
finish();

<?php
/** A stock order (purchasing.md "Proof" ≈ 40): the reorder prefill, the line defaults, the draft and what the triggers did, the refusals in words, a line added, changed and removed on a draft, a line on a sent order, the supplier changed, Sam's 403. */
require __DIR__ . '/lib.php';
$w = purchasing_world();
$nora = $w['nora'];
$sam = $w['sam'];
$wes = $w['wes'];
$since = last_activity_id();
$run = substr(md5((string) microtime(true)), 0, 6);
$po_total = static fn (int $id): string => number_format((float) one('SELECT total FROM purchase_orders WHERE id = :id', ['id' => $id]), 2, '.', '');

// ---- the form, prefilled from the reorder candidates (a re-run finds the Twin on order: its reorder point is lifted above what is on order)
psql_exec("UPDATE product_variants SET reorder_point = 5 + inv_on_order(id) WHERE id = {$w['twin']}");
$r = req('GET', "/purchasing/new?supplier={$w['zinus']}&reorder=1", ['jar' => $nora]);
$html = $r['body'];
ok($r['code'] === 200 && str_contains($html, 'id="po-form"') && str_contains($html, 'id="po-line-0"') && str_contains($html, 'name="lines[0][variant]"') && str_contains($html, 'value="' . $w['twin'] . '"'), '/purchasing/new?supplier=<Zinus>&reorder=1 prefills the first row with the Twin (a reorder candidate)');
ok(str_contains($html, 'name="lines[0][qty]"') && preg_match('/name="lines\[0\]\[qty\]"[^>]*value="6"/', $html) === 1 && preg_match('/name="lines\[0\]\[unit_cost\]"[^>]*value="349.50"/', $html) === 1, '…at the reorder quantity (6) and Zinus\' cost (349.50)');
ok(preg_match('/<option value="' . $w['twin_zinus_lv'] . '" selected>/', $html) === 1, '…with Zinus\' listing variant selected as the offer it is ordered against');
ok(str_contains($html, 'id="po-form-field-supplier"') && str_contains($html, 'SMOKE Zinus') && str_contains($html, 'id="po-line-template"') && str_contains($html, 'id="po-lines-add-btn"'), 'the supplier picker shows Zinus; the template and the Add line button are there');
ok(preg_match('/<option value="' . $w['wh'] . '" selected>SMOKE Warehouse/', $html) === 1, 'the ship-to defaults to the first warehouse');
$r = req('GET', "/purchasing/new?supplier={$w['zinus']}&variant={$w['king']}", ['jar' => $nora]);
ok($r['code'] === 200 && preg_match('/name="lines\[0\]\[variant\]"[^>]*value="' . $w['king'] . '"/', $r['body']) === 1 && preg_match('/name="lines\[0\]\[unit_cost\]"[^>]*value="649.00"/', $r['body']) === 1, '?variant= opens one row: the King at the price sheet\'s 649.00');
$r = req('GET', "/purchasing/new?supplier={$w['zinus']}", ['jar' => $sam]);
ok($r['code'] === 403, 'the form is Sam\'s 403');

// ---- line-defaults
[, $d] = screen($nora, "/purchasing/line-defaults?supplier={$w['malouf']}&variant={$w['queen']}");
$d = $d['defaults'];
ok($d['supplier_sku'] === 'NW-CR-Q' && $d['unit_cost'] === '700.00' && $d['moq'] === 2 && $d['cost_from'] === 'price_sheet', 'line-defaults: Malouf\'s price sheet gives the Queen\'s SKU, cost 700.00 and a minimum of 2');
[, $d] = screen($nora, "/purchasing/line-defaults?supplier={$w['malouf']}&variant={$w['calking']}");
$d = $d['defaults'];
ok($d['supplier_sku'] === 'NW-CR-CK' && $d['unit_cost'] === '1299.00' && $d['cost_from'] === 'offer_cost' && count($d['offers']) === 1 && $d['listing_variant_id'] === $w['lv_malouf_ck'], '…the sheet has no cost for the Cal King, so the offer\'s cost (1299.00) and the offer');
[, $d] = screen($nora, "/purchasing/line-defaults?supplier={$w['zinus']}&variant={$w['twin']}");
ok($d['defaults']['unit_cost'] === '349.50' && $d['defaults']['listing_variant_id'] === $w['twin_zinus_lv'], '…Zinus\' Twin: 349.50 and its offer');
[, $d] = screen($nora, "/purchasing/line-defaults?supplier={$w['malouf']}&variant={$w['fnd_queen']}");
ok($d['defaults']['offers'] === [] && $d['defaults']['supplier_sku'] === null && $d['defaults']['unit_cost'] === '120.00' && $d['defaults']['cost_from'] === 'variant_cost', '…a variant the supplier does not offer: no offers, no SKU, the variant\'s own cost (120.00)');
[, $d] = screen($nora, "/purchasing/line-defaults?supplier={$w['malouf']}&variant={$w['pillow_v']}");
ok($d['defaults']['unit_cost'] === '0.00' && $d['defaults']['cost_from'] === 'none', '…and 0.00 when nothing knows a cost');
$r = req('GET', "/purchasing/line-defaults?supplier={$w['malouf']}&variant={$w['queen']}&n=3&field=lines%5B3%5D", ['jar' => $nora, 'headers' => ['HX-Request: true']]);
ok($r['code'] === 200 && str_contains($r['body'], 'name="lines[3][listing_variant]"') && str_contains($r['body'], 'data-default-cost="700.00"') && str_contains($r['body'], 'data-moq="2"'), 'the fragment: a select named lines[3][listing_variant] and the defaults as data attributes');
$r = req('GET', "/purchasing/line-defaults?supplier={$w['malouf']}&variant={$w['queen']}", ['jar' => $sam, 'headers' => JSONH]);
ok($r['code'] === 403, 'line-defaults is Sam\'s 403 (purchasing.write)');

// ---- the draft
[$c, $b] = make_po($nora, $w['zinus'], [['variant' => $w['twin'], 'qty' => 6], ['variant' => 'SMOKE-NW-CR-K', 'qty' => 2, 'unit_cost' => '640.00', 'expected_on' => date('Y-m-d', strtotime('+9 days'))]],
    ['notes' => "S6-STOCK-$run", 'internal_notes' => 'INTERNAL-ONLY-' . $run, 'shipping_cost' => '25.00', 'expected_on' => date('Y-m-d', strtotime('+10 days'))]);
$po = (int) ($b['record_id'] ?? 0);
$row = $po ? po_row($po) : [];
$lines = $po ? po_lines_of($po) : [];
ok($c === 200 && $po > 0 && preg_match('/^PO-\d+$/', (string) ($row['number'] ?? '')) === 1 && $b['status'] === 'draft' && $b['lines'] === 2 && $b['refresh'] === 'purchaseOrderChanged', 'purchase_order_draft: 200, numbered PO-…, status draft, two lines, refresh purchaseOrderChanged');
ok($row['kind'] === 'stock' && $row['ship_to_kind'] === 'location' && (int) $row['location_id'] === $w['wh'] && (int) $row['created_by'] === 40 && (int) $row['supplier_id'] === $w['zinus'], 'a stock order shipping to the Warehouse (ship_to_kind location), created by Nora, for Zinus');
ok(count($lines) === 2 && $lines[0]['supplier_sku'] === 'NW-CR-T' && $lines[1]['supplier_sku'] === 'NW-CR-K' && (int) $lines[0]['listing_variant_id'] === $w['twin_zinus_lv'], 'the trigger filled the supplier SKUs from the price sheet; the Twin line took the best offer');
ok((float) $lines[0]['unit_cost'] === 349.5 && (float) $lines[1]['unit_cost'] === 640.0 && $lines[1]['expected_on'] === date('Y-m-d', strtotime('+9 days')), 'the cost defaulted to the sheet\'s (349.50); a cost given (640.00) and a line date stay');
$sub = 6 * 349.5 + 2 * 640.0;
ok(abs((float) $row['subtotal'] - $sub) < 0.005 && (float) $row['shipping_cost'] === 25.0 && abs((float) $row['total'] - ($sub + 25.0)) < 0.005, 'the totals are the database\'s: subtotal ' . number_format($sub, 2) . ', shipping 25.00, total ' . number_format($sub + 25, 2));
$log = last_log('purchase_order.draft', $since);
$a = after_of($log);
ok($log !== null && (int) $log['purchase_order_id'] === $po && (int) $log['location_id'] === $w['wh'] && $log['sales_order_id'] === null && $a['number'] === $row['number'] && $a['supplier_name'] === 'SMOKE Zinus' && $a['kind'] === 'stock'
    && $a['ship_to_kind'] === 'location' && $a['lines'] === 2 && $a['currency'] === 'USD' && $log['source'] === 'web', 'purchase_order.draft is logged with the audit keys (purchase_order_id, the location) and number, supplier, kind, ship-to, lines, total, currency');
ok($log !== null && !str_contains((string) $log['after'], 'INTERNAL-ONLY') && !str_contains((string) $log['after'], 'ZN-5005'), 'the log row carries no internal notes and no account number');
[, $d] = screen($nora, "/purchasing/$po");
ok($d['purchase_order']['total'] === number_format($sub + 25, 2, '.', '') && $d['purchase_order']['lines'][0]['unit_cost'] === '349.50' && $d['purchase_order']['internal_notes'] === 'INTERNAL-ONLY-' . $run, "Nora's page answers the cost and the internal notes");
[, $d] = screen($sam, "/purchasing/$po");
ok($d['purchase_order']['total'] === null && $d['purchase_order']['cost_withheld'] === true && $d['purchase_order']['lines'][0]['unit_cost'] === null && $d['purchase_order']['internal_notes'] === null, "Sam's page withholds the cost and the internal notes (cost is the wall)");

// ---- the refusals, in words
[$c, $b] = make_po($nora, $w['zinus'], [['variant' => 'SMOKE-SET-Q', 'qty' => 1]]);
ok($c === 422 && msg($b) === 'A bundle is bought as its components', 'a bundle as a line: the trigger\'s sentence as a 422');
[$c, $b] = make_po($nora, $w['zinus'], [['qty' => 1]]);
ok($c === 422 && msg($b) === 'A purchase order has at least one line.', 'a line with no variant is no line: 422 "A purchase order has at least one line."');
[$c, $b] = make_po($nora, $w['zinus'], [['variant' => $w['twin'], 'qty' => 0]]);
ok($c === 422 && isset(fields($b)['lines.0.qty']), 'a quantity of 0: a field error on the line');
[$c, $b] = make_po($nora, $w['zinus'], [['variant' => 'NO-SUCH-SKU', 'qty' => 1]]);
ok($c === 422 && isset(fields($b)['lines.0.variant']), 'an unknown SKU: a field error on the line');
[$c, $b] = make_po($nora, $w['zinus'], [['variant' => $w['twin'], 'qty' => 1, 'listing_variant' => $w['lv_malouf_ck']]]);
ok($c === 422 && isset(fields($b)['lines.0.listing_variant']), 'an offer that is not this supplier\'s offer for the variant: a field error');
[$c, $b] = act($nora, '/purchasing/save.php', ['lines' => json_encode([['variant' => $w['twin'], 'qty' => 1]])]);
ok($c === 422 && isset(fields($b)['supplier']), 'no supplier: a field error');
[$c, $b] = act($nora, '/purchasing/save.php', ['supplier' => $w['zinus'], 'location' => 99999999, 'lines' => json_encode([['variant' => $w['twin'], 'qty' => 1]])]);
ok($c === 422 && isset(fields($b)['location']), 'an unknown ship-to location: a field error');
[$c, $b] = make_po($sam, $w['zinus'], [['variant' => $w['twin'], 'qty' => 1]]);
ok($c === 403 && str_contains(msg($b), 'raise or send purchase orders'), 'Sam (no purchasing.write): 403 in words');
[$c, $b] = make_po($wes, $w['zinus'], [['variant' => $w['twin'], 'qty' => 1]]);
ok($c === 403, 'Wes (warehouse, no purchasing.write): 403');

// ---- lines on a draft: add, change, remove
$since2 = last_activity_id();
[$c, $b] = act($nora, '/purchasing/lines/save.php', ['purchase_order' => $po, 'variant' => 'SMOKE-NW-CR-Q', 'qty' => 3]);
$nl = (int) ($b['line_id'] ?? 0);
$nrow = $nl ? q('SELECT * FROM purchase_order_lines WHERE id = :id', ['id' => $nl])[0] : [];
ok($c === 200 && $nl > 0 && (int) $nrow['line_no'] === 3 && $nrow['supplier_sku'] === 'NW-CR-Q' && (float) $nrow['unit_cost'] === 499.0, 'purchase_order_line_add: line 3, the Queen at Zinus\' sheet cost 499.00 with their SKU');
ok(abs((float) po_row($po)['subtotal'] - ($sub + 3 * 499.0)) < 0.005, '…the order\'s subtotal moved to include it');
$la = last_log('purchase_order.line_add', $since2);
ok($la !== null && (int) $la['purchase_order_id'] === $po && after_of($la)['sku'] === 'SMOKE-NW-CR-Q' && after_of($la)['qty_ordered'] === 3, 'purchase_order.line_add is logged (line_id, sku, quantity, cost)');
$since3 = last_activity_id();
[$c, $b] = act($nora, '/purchasing/lines/save.php', ['line' => $nl, 'qty' => 4, 'unit_cost' => '480']);
$nrow = q('SELECT * FROM purchase_order_lines WHERE id = :id', ['id' => $nl])[0];
ok($c === 200 && (int) $nrow['qty_ordered'] === 4 && (float) $nrow['unit_cost'] === 480.0 && $nrow['supplier_sku'] === 'NW-CR-Q', 'purchase_order_line_update: the quantity and cost change; the supplier SKU stays');
ok(last_log('purchase_order.line_update', $since3) !== null, 'purchase_order.line_update is logged');
// an HTMX caller targeting #po-lines gets the region and the totals
$tok = csrf_of(req('GET', '/', ['jar' => $nora])['body']);
$r = req('POST', '/purchasing/lines/save.php', ['jar' => $nora, 'headers' => ['HX-Request: true', 'HX-Target: po-lines'], 'form' => ['line' => $nl, 'qty' => 5, 'csrf_token' => $tok]]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="po-lines"') && str_contains($r['body'], 'id="po-totals"') && str_contains($r['body'], 'id="po-line-' . $nl . '-form"') && (int) one('SELECT qty_ordered FROM purchase_order_lines WHERE id = :id', ['id' => $nl]) === 5, 'an HTMX save targeting #po-lines answers the region and the totals panel (out of band)');
$r = req('GET', "/purchasing/$po/lines", ['jar' => $nora, 'headers' => ['HX-Request: true']]);
ok($r['code'] === 200 && str_contains($r['body'], 'hx-trigger="purchaseOrderChanged from:body"'), 'GET /purchasing/{id}/lines is the region that refreshes itself on purchaseOrderChanged');
$since4 = last_activity_id();
[$c, $b] = act($nora, '/purchasing/lines/remove.php', ['line' => $nl]);
ok($c === 200 && one('SELECT count(*) FROM purchase_order_lines WHERE id = :id', ['id' => $nl]) == 0 && abs((float) po_row($po)['subtotal'] - $sub) < 0.005 && last_log('purchase_order.line_remove', $since4) !== null, 'purchase_order_line_remove: the line goes, the subtotal returns, purchase_order.line_remove is logged');
[$c, $b] = act($nora, '/purchasing/lines/save.php', ['purchase_order' => $po, 'variant' => 'SMOKE-SET-Q', 'qty' => 1]);
ok($c === 422 && msg($b) === 'A bundle is bought as its components', 'a bundle added to a draft: the same sentence');

// ---- the header
$since5 = last_activity_id();
$exp = date('Y-m-d', strtotime('+20 days'));
[$c, $b] = act($nora, '/purchasing/save.php', ['purchase_order' => $po, 'expected_on' => $exp, 'shipping_cost' => '30']);
$row = po_row($po);
ok($c === 200 && $row['expected_on'] === $exp && (float) $row['shipping_cost'] === 30.0 && abs((float) $row['total'] - ($sub + 30)) < 0.005 && count(po_lines_of($po)) === 2, 'purchase_order_update: the expected date and shipping change; the total follows; the lines stay');
$ul = last_log('purchase_order.update', $since5);
ok($ul !== null && after_of($ul)['changed'] === ['expected_on', 'shipping_cost'] && !str_contains((string) $ul['after'], 'INTERNAL-ONLY'), 'purchase_order.update logs the fields changed by name');
[$c, $b] = act($nora, '/purchasing/save.php', ['purchase_order' => $po, 'supplier' => $w['malouf']]);
$lines = po_lines_of($po);
ok($c === 200 && (int) po_row($po)['supplier_id'] === $w['malouf'] && $lines[1]['supplier_sku'] === 'MAL-K' && $lines[0]['supplier_sku'] === 'NW-CR-T' && $lines[0]['listing_variant_id'] === null, 'a new supplier clears the lines\' SKUs and offers: the SKUs are re-read from Malouf\'s price sheet (MAL-K)');
act($nora, '/purchasing/save.php', ['purchase_order' => $po, 'supplier' => $w['zinus']]);
$r = req('GET', "/purchasing/$po/edit", ['jar' => $nora]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="po-form-supplier-note"') && str_contains($r['body'], 'id="po-line-' . po_lines_of($po)[0]['id'] . '-form"') && str_contains($r['body'], 'id="po-lines-add"'), 'the edit form says what changing the supplier does, and lists the lines as forms of their own with an add row');
[$c, $b] = act($nora, '/purchasing/save.php', ['purchase_order' => $po, 'supplier' => 99999999]);
ok($c === 422 && isset(fields($b)['supplier']), 'an unknown supplier on an update: a field error');

// ---- a sent order does not change
act($nora, '/purchasing/send.php', ['purchase_order' => $po, 'via' => 'phone']);
ok(po_row($po)['status'] === 'sent', 'the order is sent (by phone: no mail, no link)');
[$c, $b] = act($nora, '/purchasing/lines/save.php', ['purchase_order' => $po, 'variant' => 'SMOKE-NW-CR-Q', 'qty' => 1]);
ok($c === 422 && str_contains(msg($b), 'The lines of a purchase order change only while it is a draft'), 'a line on a sent order: the SQL\'s sentence');
[$c, $b] = act($nora, '/purchasing/save.php', ['purchase_order' => $po, 'notes' => 'x']);
ok($c === 422 && msg($b) === $row['number'] . ' is sent — only a draft changes', 'the header of a sent order: 422 "PO-… is sent — only a draft changes"');
$r = req('GET', "/purchasing/$po/edit", ['jar' => $nora]);
ok($r['code'] === 422 && str_contains($r['body'], 'close or cancel it instead'), 'the edit form of a sent order: 422 "…close or cancel it instead"');
[$c, $b] = act($nora, '/purchasing/lines/remove.php', ['line' => $lines[0]['id']]);
ok($c === 422 && str_contains(msg($b), 'only while it is a draft'), 'a line removed from a sent order: the SQL\'s sentence');

$tok0 = run_token(45, 96);
kernel_state(function ($s) { $s['facts']['96'] = ['valid' => true, 'is_agent' => true, 'member_id' => 45, 'run_id' => 96, 'request_id' => 'req-run-96', 'trigger' => 'chat', 'endpoints' => [['name' => 'Records MCP']]]; return $s; });
[$c, $b] = act_token('/purchasing/save.php', ['supplier' => $w['zinus'], 'lines' => json_encode([['variant' => $w['twin'], 'qty' => 1]])], as_agent($tok0));
ok($c === 403, 'the expert as the fixture has it (the Sales role) may not raise purchase orders: 403 — an agent holds what its role holds');
// ---- an agent holding the Buyer role (the fixture's expert is Sales: the proof gives its mirror row the Buyer role for this step, as a hired Buyer agent would hold it)
$rolesBefore = (string) one('SELECT roles::text FROM members WHERE id = 45');
psql_exec("UPDATE members SET roles = ARRAY['buyer', 'user'] WHERE id = 45");
kernel_state(function ($s) { $s['facts']['97'] = ['valid' => true, 'is_agent' => true, 'member_id' => 45, 'run_id' => 97, 'request_id' => 'req-run-97', 'trigger' => 'chat', 'endpoints' => [['name' => 'Records MCP']]]; return $s; });
$tok = run_token(45, 97);
[$c, $b] = act_token('/purchasing/save.php', ['supplier' => $w['zinus'], 'lines' => json_encode([['variant' => $w['twin'], 'qty' => 1]]), 'notes' => "S6-EXPERT-$run"], as_agent($tok));
$xl = last_log('purchase_order.draft', $since);
ok($c === 200 && $b['status'] === 'draft' && $xl !== null && $xl['source'] === 'agent' && (int) $xl['agent_run_id'] === 97 && (int) po_row((int) $b['record_id'])['created_by'] === 45, 'an agent holding the Buyer role (a run token) drafts a purchase order: created by 45, logged as the agent, run 97');
kernel_state(function ($s) { unset($s['facts']); return $s; });
psql_exec("UPDATE members SET roles = '" . $rolesBefore . "' WHERE id = 45");
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
ok($reg['actions']['purchase_order_draft']['approval'] === null && $reg['actions']['purchase_order_update']['approval'] === null, 'drafting and updating are free for an agent in the registry (no approval category)');
finish();

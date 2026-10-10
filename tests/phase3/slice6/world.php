<?php
/** The world of slice 6 (purchasing.md "Proof"): slice 5's world plus the suppliers' facts, the confirmed order whose confirmation drafted a purchase order per supplier and the Twin under its reorder point; what the later proofs stand on, checked once. */
require __DIR__ . '/lib.php';
$w = purchasing_world();
ok($w['wh'] && $w['src_malouf'] && $w['src_zinus_feed'] && $w['alvarez'], "slice 5's world: the locations, the suppliers' sources and the customer");
$m = supplier_row($w['malouf']);
$z = supplier_row($w['zinus']);
ok($m['order_method'] === 'portal' && $m['dropships'] && (int) $m['lead_time_days'] === 5 && $m['account_number'] === 'DLR-1001' && $m['order_email'] !== null, 'SMOKE Malouf: portal, drop-ships, lead 5, account DLR-1001, an order e-mail');
ok($z['order_method'] === 'email' && (int) $z['lead_time_days'] === 3 && str_contains((string) $z['order_email'], 'dealers@zinus'), 'SMOKE Zinus: e-mail, lead 3, dealers@zinus…');
ok($w['suppressed'] && $w['flaky'] && $w['nomail'] && one('SELECT order_email FROM suppliers WHERE id = :s', ['s' => $w['nomail']]) === null, 'the suppliers MaluMail refuses (suppressed, flaky) and the one with no address');
ok($w['main'] !== null && order_row($w['main'])['status'] === 'confirmed', 'the order S6-MAIN is confirmed');
ok($w['po_malouf'] !== null && $w['po_zinus'] !== null && $w['po_malouf'] !== $w['po_zinus'] && po_row($w['po_malouf'])['status'] === 'draft' && po_row($w['po_zinus'])['status'] === 'draft', 'the confirmation drafted one purchase order per supplier (both drafts)');
$pm = po_row($w['po_malouf']);
$pl = po_lines_of($w['po_malouf']);
ok($pm['kind'] === 'dropship' && $pm['ship_to_kind'] === 'customer' && count($pl) === 1 && (float) $pl[0]['unit_cost'] === 1299.0 && $pl[0]['sales_order_line_id'] !== null, "Malouf's draft: a drop-ship to the customer, one line, the order line's cost 1299.00");
ok(one('SELECT ships_how FROM product_variants WHERE id = :v', ['v' => $w['calking']]) === 'ltl' && one('SELECT ships_how FROM product_variants WHERE id = :v', ['v' => $w['king']]) === 'parcel', 'the Cal King ships ltl and the King parcel');
as_db(40);
$cand = q('SELECT variant_id, best_supplier_id, best_cost, reorder_qty FROM inv_reorder_candidates() WHERE variant_id = :v', ['v' => $w['twin']]);
ok(count($cand) === 1 && (int) $cand[0]['best_supplier_id'] === $w['zinus'] && (float) $cand[0]['best_cost'] === 349.5 && (int) $cand[0]['reorder_qty'] === 6, 'the Twin is under its reorder point and Zinus is its cheapest in-stock offer (349.50, reorder 6)');
ok($w['twin_zinus_lv'] !== null, "Zinus has a matched listing variant for the Twin");
finish();

<?php
/** The world of slice 8 (returns-worker.md "Proof"): slice 6's world, the Buyer agent, two shipped orders, the watches, the old usage buckets; checked once. */
require __DIR__ . '/lib.php';
$w = returns_world();
ok($w['so1'] !== null && $w['so2'] !== null, 'SO-1 and SO-2 are made');
$s1 = q('SELECT status, number FROM sales_orders WHERE id = :o', ['o' => $w['so1']])[0];
$l = q('SELECT l.fulfilment_kind, l.qty_shipped, l.status, v.sku FROM sales_order_lines l JOIN product_variants v ON v.id = l.variant_id WHERE l.sales_order_id = :o ORDER BY l.line_no', ['o' => $w['so1']]);
ok(count($l) === 2 && $l[0]['qty_shipped'] === 1 && $l[1]['qty_shipped'] === 1 && $l[1]['fulfilment_kind'] === 'dropship', "SO-1 ({$s1['number']}): the Queen shipped from stock, the King shipped by the supplier — " . json_encode(array_column($l, 'status')));
ok(one('SELECT status FROM sales_orders WHERE id = :o', ['o' => $w['so2']]) === 'closed', 'SO-2 is closed (delivered and paid in full)');
ok((int) one('SELECT buyer_member_id FROM inv_settings WHERE id = 1') === 40, 'Nora is the Buyer');
ok(one('SELECT member_kind FROM members WHERE id = 47') === 'agent' && right_of(47, 'reports.read') && !right_of(47, 'agents.settings'), 'the SMOKE Buyer agent (47) holds the buyer role: reports.read, not agents.settings');
ok($w['watch_sam'] > 0 && $w['watch_nora'] > 0 && (int) one('SELECT agent_member_id FROM watches WHERE id = :w', ['w' => $w['watch_sam']]) === 45, "Sam's watch names the expert; Nora's names none");
ok((int) one("SELECT count(*) FROM key_usage WHERE bucket_start < now() - interval '35 days'") >= 1, 'a 40-day-old usage bucket waits for the pruner');
finish();

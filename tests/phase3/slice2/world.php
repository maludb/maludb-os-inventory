<?php
/** The world (stock.md "Proof"): slice 1's catalog, the three locations made by the owner through the handler, the supplier and its sent stock PO. Run first. */
require __DIR__ . '/lib.php';
echo "The world\n";
$w = stock_world();
ok($w['wh'] !== null && $w['sr'] !== null && $w['ret'] !== null, 'the three locations exist (made through /locations/save.php)');
ok(q('SELECT kind, is_sellable, allow_negative FROM locations WHERE id = :id', ['id' => $w['ret']])[0] == ['kind' => 'returns', 'is_sellable' => false, 'allow_negative' => false], 'Returns: kind returns, not sellable, no negative');
ok($w['queen'] !== null && $w['king'] !== null && $w['set_q'] !== null, 'the catalog is there (Queen, King, the Queen set)');
ok(one('SELECT status FROM purchase_orders WHERE id = :p', ['p' => $w['po']]) === 'sent' && one('SELECT kind FROM purchase_orders WHERE id = :p', ['p' => $w['po']]) === 'stock', 'the stock purchase order is sent to SMOKE Dreamland');
ok((int) one('SELECT count(*) FROM inventory_balances') === 0 && (int) one('SELECT count(*) FROM inventory_transactions') === 0, 'nothing is on hand yet: no balance, no movement');
finish();

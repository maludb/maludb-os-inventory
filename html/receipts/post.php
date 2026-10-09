<?php
declare(strict_types=1);
/** Action `receipt_post` (log `stock.receive`: number, supplier_id, purchase_order_id, location_id, lines, units, amount, currency, discrepancies; confirm): inv_post_receipt() — every line a receipt movement; the cost follows the settings. stock.receive. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
stock_write_begin('stock.receive');
$pdo = db();
$r = receipt_or_404($pdo, request_integer('receipt') ?? request_integer('goods_receipt_id'));
$res = inv_guard($pdo, static function () use ($pdo, $r): array {
    $pdo->beginTransaction();
    $res = post_receipt($pdo, (int) $r['goods_receipt_id'], stock_me());
    stock_log($pdo, 'stock.receive', 'goods_receipt', (int) $r['goods_receipt_id'], (int) $r['location_id'], ['number' => $r['number'], 'supplier_id' => $r['supplier_id'] === null ? null : (int) $r['supplier_id'],
        'purchase_order_id' => $r['purchase_order_id'] === null ? null : (int) $r['purchase_order_id'], 'location_id' => (int) $r['location_id'], 'lines' => $res['lines'], 'units' => $res['units'],
        'amount' => $res['amount'], 'currency' => settings_vocabulary($pdo)['currency'], 'discrepancies' => $res['discrepancies']], ['purchase_order_id' => $r['purchase_order_id'] === null ? null : (int) $r['purchase_order_id']]);
    $pdo->commit();
    return $res;
});
inv_done('Posted ' . $r['number'] . ': ' . $res['units'] . ' units into ' . $r['location_name'], (int) $r['goods_receipt_id'], inv_land('/receipts/' . (int) $r['goods_receipt_id'], 'posted'), 'stockChanged', ['units' => $res['units'], 'lines' => $res['lines']]);

<?php
declare(strict_types=1);
/** Action `receipt_cancel` (log `stock.receipt_cancel`; confirm): a draft receipt is cancelled — nothing was posted. stock.receive. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
stock_write_begin('stock.receive');
$pdo = db();
$r = receipt_or_404($pdo, request_integer('receipt') ?? request_integer('goods_receipt_id'));
inv_guard($pdo, static function () use ($pdo, $r): void {
    $pdo->beginTransaction();
    cancel_document($pdo, 'goods_receipts', (int) $r['goods_receipt_id'], 'draft');
    stock_log($pdo, 'stock.receipt_cancel', 'goods_receipt', (int) $r['goods_receipt_id'], (int) $r['location_id'], ['number' => $r['number'], 'lines' => (int) $r['line_count']], ['purchase_order_id' => $r['purchase_order_id'] === null ? null : (int) $r['purchase_order_id']]);
    $pdo->commit();
});
inv_done('Cancelled ' . $r['number'], (int) $r['goods_receipt_id'], inv_land('/receipts/' . (int) $r['goods_receipt_id'], 'cancelled'), 'stockChanged');

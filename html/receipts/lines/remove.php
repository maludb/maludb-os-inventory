<?php
declare(strict_types=1);
/** Action `receipt_line_remove` (log `stock.receipt_line_remove`): a draft receipt's line. stock.receive. */
require_once dirname(dirname(__DIR__), 2) . '/app/features/stock/handler.php';
stock_write_begin('stock.receive');
$pdo = db();
$lineId = request_integer('line') ?? request_integer('goods_receipt_line_id');
$rid = $lineId === null ? null : one_value($pdo, 'SELECT goods_receipt_id FROM mcp_goods_receipt_lines WHERE goods_receipt_line_id = :id', ['id' => $lineId]);
if ($rid === null) { refuse(404, 'Line not found.'); }
$r = receipt_or_404($pdo, (int) $rid);
require_draft($r, 'receipt');
$old = inv_guard($pdo, static function () use ($pdo, $r, $lineId): array {
    $pdo->beginTransaction();
    $old = remove_receipt_line($pdo, $lineId);
    $sku = one_value($pdo, 'SELECT sku FROM mcp_product_variants WHERE variant_id = :id', ['id' => (int) $old['variant_id']]);
    stock_log($pdo, 'stock.receipt_line_remove', 'goods_receipt', (int) $r['goods_receipt_id'], (int) $r['location_id'], ['number' => $r['number'], 'line_id' => $lineId, 'line_no' => (int) $old['line_no'], 'sku' => $sku, 'qty' => (int) $old['qty']],
        ['purchase_order_id' => $r['purchase_order_id'] === null ? null : (int) $r['purchase_order_id']]);
    $pdo->commit();
    return $old;
});
inv_done('Removed line ' . (int) $old['line_no'] . ' of ' . $r['number'], (int) $r['goods_receipt_id'], inv_land('/receipts/' . (int) $r['goods_receipt_id'], 'line_removed'), 'stockChanged');

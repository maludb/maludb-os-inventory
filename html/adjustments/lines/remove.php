<?php
declare(strict_types=1);
/** Action `adjustment_line_remove` (log `stock.adjustment_line_remove`): a draft adjustment's line. stock.adjust. */
require_once dirname(dirname(__DIR__), 2) . '/app/features/stock/handler.php';
stock_write_begin('stock.adjust');
$pdo = db();
$lineId = request_integer('line') ?? request_integer('adjustment_line_id');
$aid = $lineId === null ? null : one_value($pdo, 'SELECT adjustment_id FROM mcp_inventory_adjustment_lines WHERE adjustment_line_id = :id', ['id' => $lineId]);
if ($aid === null) { refuse(404, 'Line not found.'); }
$a = adjustment_or_404($pdo, (int) $aid);
require_draft($a, 'adjustment');
$old = inv_guard($pdo, static function () use ($pdo, $a, $lineId): array {
    $pdo->beginTransaction();
    $old = remove_adjustment_line($pdo, $lineId);
    stock_log($pdo, 'stock.adjustment_line_remove', 'inventory_adjustment', (int) $a['adjustment_id'], (int) $a['location_id'], ['number' => $a['number'], 'line_id' => $lineId, 'line_no' => (int) $old['line_no'],
        'sku' => one_value($pdo, 'SELECT sku FROM mcp_product_variants WHERE variant_id = :id', ['id' => (int) $old['variant_id']]), 'qty_delta' => (int) $old['qty_delta']]);
    $pdo->commit();
    return $old;
});
inv_done('Removed line ' . (int) $old['line_no'] . ' of ' . $a['number'], (int) $a['adjustment_id'], inv_land('/adjustments/' . (int) $a['adjustment_id'], 'line_removed'), 'stockChanged');

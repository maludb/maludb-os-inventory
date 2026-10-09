<?php
declare(strict_types=1);
/** Action `transfer_line_remove` (log `stock.transfer_line_remove`): a draft transfer's line. stock.transfer. */
require_once dirname(dirname(__DIR__), 2) . '/app/features/stock/handler.php';
stock_write_begin('stock.transfer');
$pdo = db();
$lineId = request_integer('line') ?? request_integer('transfer_line_id');
$tid = $lineId === null ? null : one_value($pdo, 'SELECT transfer_id FROM mcp_inventory_transfer_lines WHERE transfer_line_id = :id', ['id' => $lineId]);
if ($tid === null) { refuse(404, 'Line not found.'); }
$t = transfer_or_404($pdo, (int) $tid);
require_draft($t, 'transfer');
$old = inv_guard($pdo, static function () use ($pdo, $t, $lineId): array {
    $pdo->beginTransaction();
    $old = remove_transfer_line($pdo, $lineId);
    stock_log($pdo, 'stock.transfer_line_remove', 'inventory_transfer', (int) $t['transfer_id'], (int) $t['from_location_id'], ['number' => $t['number'], 'line_id' => $lineId, 'line_no' => (int) $old['line_no'],
        'sku' => one_value($pdo, 'SELECT sku FROM mcp_product_variants WHERE variant_id = :id', ['id' => (int) $old['variant_id']]), 'qty' => (int) $old['qty']]);
    $pdo->commit();
    return $old;
});
inv_done('Removed line ' . (int) $old['line_no'] . ' of ' . $t['number'], (int) $t['transfer_id'], inv_land('/transfers/' . (int) $t['transfer_id'], 'line_removed'), 'stockChanged');

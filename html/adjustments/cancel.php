<?php
declare(strict_types=1);
/** Action `adjustment_cancel` (log `stock.adjustment_cancel`; confirm): a draft adjustment is cancelled — nothing was posted. stock.adjust. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
stock_write_begin('stock.adjust');
$pdo = db();
$a = adjustment_or_404($pdo, request_integer('adjustment') ?? request_integer('adjustment_id'));
inv_guard($pdo, static function () use ($pdo, $a): void {
    $pdo->beginTransaction();
    cancel_document($pdo, 'inventory_adjustments', (int) $a['adjustment_id'], 'draft');
    stock_log($pdo, 'stock.adjustment_cancel', 'inventory_adjustment', (int) $a['adjustment_id'], (int) $a['location_id'], ['number' => $a['number'], 'lines' => (int) $a['line_count']]);
    $pdo->commit();
});
inv_done('Cancelled ' . $a['number'], (int) $a['adjustment_id'], inv_land('/adjustments/' . (int) $a['adjustment_id'], 'cancelled'), 'stockChanged');

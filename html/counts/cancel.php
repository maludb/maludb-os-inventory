<?php
declare(strict_types=1);
/** Action `count_cancel` (log `stock.count_cancel`; confirm): an open count is cancelled — nothing posted. stock.count. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
stock_write_begin('stock.count');
$pdo = db();
$c = count_or_404($pdo, request_integer('count') ?? request_integer('count_id'));
inv_guard($pdo, static function () use ($pdo, $c): void {
    $pdo->beginTransaction();
    cancel_document($pdo, 'inventory_counts', (int) $c['count_id'], 'open');
    stock_log($pdo, 'stock.count_cancel', 'inventory_count', (int) $c['count_id'], (int) $c['location_id'], ['number' => $c['number'], 'lines' => (int) $c['line_count'], 'lines_counted' => (int) $c['lines_counted']]);
    $pdo->commit();
});
inv_done('Cancelled ' . $c['number'], (int) $c['count_id'], inv_land('/counts/' . (int) $c['count_id'], 'cancelled'), 'stockChanged');

<?php
declare(strict_types=1);
/** Action `transfer_cancel` (log `stock.transfer_cancel`; confirm): a draft transfer is cancelled — nothing moved. stock.transfer. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
stock_write_begin('stock.transfer');
$pdo = db();
$t = transfer_or_404($pdo, request_integer('transfer') ?? request_integer('transfer_id'));
inv_guard($pdo, static function () use ($pdo, $t): void {
    $pdo->beginTransaction();
    cancel_document($pdo, 'inventory_transfers', (int) $t['transfer_id'], 'draft');
    stock_log($pdo, 'stock.transfer_cancel', 'inventory_transfer', (int) $t['transfer_id'], (int) $t['from_location_id'], ['number' => $t['number'], 'lines' => (int) $t['line_count']]);
    $pdo->commit();
});
inv_done('Cancelled ' . $t['number'], (int) $t['transfer_id'], inv_land('/transfers/' . (int) $t['transfer_id'], 'cancelled'), 'stockChanged');

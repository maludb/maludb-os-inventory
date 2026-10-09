<?php
declare(strict_types=1);
/** Action `transfer_send` (log `stock.transfer_send`: number, from_location_id, to_location_id, lines, units; confirm): inv_transfer_send() — the lines leave the from location into transit on the document. stock.transfer. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
stock_write_begin('stock.transfer');
$pdo = db();
$t = transfer_or_404($pdo, request_integer('transfer') ?? request_integer('transfer_id'));
$res = inv_guard($pdo, static function () use ($pdo, $t): array {
    $pdo->beginTransaction();
    $res = send_transfer($pdo, (int) $t['transfer_id'], stock_me());
    stock_log($pdo, 'stock.transfer_send', 'inventory_transfer', (int) $t['transfer_id'], (int) $t['from_location_id'], ['number' => $t['number'], 'from_location_id' => (int) $t['from_location_id'], 'to_location_id' => (int) $t['to_location_id'],
        'lines' => $res['lines'], 'units' => $res['units']]);
    $pdo->commit();
    return $res;
});
inv_done('Sent ' . $t['number'] . ': ' . $res['units'] . ' units left ' . $t['from_location'], (int) $t['transfer_id'], inv_land('/transfers/' . (int) $t['transfer_id'], 'sent'), 'stockChanged', ['units' => $res['units']]);

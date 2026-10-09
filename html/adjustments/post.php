<?php
declare(strict_types=1);
/** Action `stock_adjust` (log `stock.adjust`: number, location_id, reason_code, lines, units_delta, amount; confirm; an agent's pauses — deletion): inv_post_adjustment() — an adjustment movement per line (a floor_model reason moves the flag instead). stock.adjust. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
stock_write_begin('stock.adjust');
$pdo = db();
$a = adjustment_or_404($pdo, request_integer('adjustment') ?? request_integer('adjustment_id'));
$res = inv_guard($pdo, static function () use ($pdo, $a): array {
    $pdo->beginTransaction();
    $res = post_adjustment($pdo, (int) $a['adjustment_id'], stock_me());
    stock_log($pdo, 'stock.adjust', 'inventory_adjustment', (int) $a['adjustment_id'], (int) $a['location_id'], ['number' => $a['number'], 'location_id' => (int) $a['location_id'], 'reason_code' => $a['reason_code'],
        'lines' => $res['lines'], 'units_delta' => $res['units_delta'], 'amount' => $res['amount'], 'currency' => settings_vocabulary($pdo)['currency']]);
    $pdo->commit();
    return $res;
});
inv_done('Posted ' . $a['number'] . ' (' . ($res['units_delta'] > 0 ? '+' : '') . $res['units_delta'] . ' units, ' . $a['reason_name'] . ')', (int) $a['adjustment_id'], inv_land('/adjustments/' . (int) $a['adjustment_id'], 'posted'), 'stockChanged', ['units_delta' => $res['units_delta']]);

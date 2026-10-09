<?php
declare(strict_types=1);
/** Action `count_post` (log `stock.count_post`: number, location_id, lines, lines_differing, units_delta, corrections; confirm; an agent's pauses — deletion): inv_post_count() — a correction per counted line whose count ≠ on hand NOW. stock.count. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
stock_write_begin('stock.count');
$pdo = db();
$c = count_or_404($pdo, request_integer('count') ?? request_integer('count_id'));
$res = inv_guard($pdo, static function () use ($pdo, $c): array {
    $pdo->beginTransaction();
    $res = post_count($pdo, (int) $c['count_id'], stock_me());
    stock_log($pdo, 'stock.count_post', 'inventory_count', (int) $c['count_id'], (int) $c['location_id'], ['number' => $c['number'], 'location_id' => (int) $c['location_id'], 'lines' => $res['lines'],
        'lines_differing' => $res['lines_differing'], 'corrections' => $res['corrections'], 'units_delta' => $res['units_delta']]);
    $pdo->commit();
    return $res;
});
inv_done('Posted ' . $c['number'] . ': ' . $res['corrections'] . ' correction' . ($res['corrections'] === 1 ? '' : 's'), (int) $c['count_id'], inv_land('/counts/' . (int) $c['count_id'], 'posted'), 'stockChanged',
    ['corrections' => $res['corrections'], 'units_delta' => $res['units_delta']]);

<?php
declare(strict_types=1);
/** Action `count_start` (log `stock.count_start`: number, location_id, lines): inv_count_start() — one open count per location; a line per variant held there with the system quantity frozen. stock.count. Location /counts/{id}. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
stock_write_begin('stock.count');
$pdo = db();
$errors = [];
$loc = stock_location_field($pdo, 'location', null, 'the location', $errors);
if ($loc === null && !isset($errors['location'])) { $errors['location'] = 'Choose where to count.'; }
$notes = stock_text('notes', null, 2000);
if ($errors !== []) { inv_refuse_fields($errors); }
$c = inv_guard($pdo, static function () use ($pdo, $loc, $notes): array {
    $pdo->beginTransaction();
    $c = start_count($pdo, $loc, stock_me(), $notes);
    stock_log($pdo, 'stock.count_start', 'inventory_count', (int) $c['id'], $loc, ['number' => $c['number'], 'location_id' => $loc, 'lines' => $c['lines']]);
    $pdo->commit();
    return $c;
});
inv_done('Started ' . $c['number'] . ' (' . $c['lines'] . ' lines)', (int) $c['id'], inv_land('/counts/' . (int) $c['id'], 'started'), 'stockChanged', ['count_id' => (int) $c['id'], 'lines' => $c['lines']]);

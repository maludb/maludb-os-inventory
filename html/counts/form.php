<?php
declare(strict_types=1);
/** /counts/new?location= — start a count at a location (screen `count-add`). stock.count. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
require_right('stock.count');
$pdo = db();
log_screen_view($pdo, 'count-add');
$open = counts($pdo, ['status' => 'open']);
if (wants_json()) {
    respond_screen(['locations' => locations_for_pick($pdo), 'open_counts' => array_map('present_count', $open)]);
}
render_screen('Start a count', view('counts/form.php', ['locations' => locations_for_pick($pdo), 'locationId' => request_integer('location'), 'open' => $open]), ['activeNav' => 'count-list', 'screen' => 'count-add', 'entity' => 'inventory_count']);

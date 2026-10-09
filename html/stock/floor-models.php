<?php
declare(strict_types=1);
/** /stock/floor-models?location= — the floor models by location (screen `floor-model-list`), with the Take in / Take out form (stock.adjust). */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
require_right('inventory.read');
$pdo = db();
$loc = request_integer('location');
$rows = floor_models($pdo, $loc);
log_screen_view($pdo, 'floor-model-list');
if (wants_json()) {
    respond_screen(['location' => $loc, 'rows' => array_map(static fn (array $r): array => ['variant_id' => (int) $r['variant_id'], 'sku' => $r['sku'], 'product_name' => $r['product_name'], 'size_name' => $r['size_name'],
        'location_id' => (int) $r['location_id'], 'location_name' => $r['location_name'], 'qty_floor_model' => (int) $r['qty_floor_model'], 'qty_on_hand' => (int) $r['qty_on_hand'], 'qty_available' => (int) $r['qty_available']], $rows)]);
}
render_screen('Floor models', view('stock/floor-models.php', ['rows' => $rows, 'location' => $loc, 'locations' => locations_for_pick($pdo), 'mayWrite' => has_right('stock.adjust'), 'here' => here_url(),
    'notice' => inv_notice($_GET['notice'] ?? null, STOCK_NOTICES)]), ['activeNav' => 'floor-model-list', 'screen' => 'floor-model-list', 'entity' => 'stock']);

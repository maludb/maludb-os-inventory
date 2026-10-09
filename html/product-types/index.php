<?php
declare(strict_types=1);
/** /product-types/ — the product types in order with an add form and a per-row inline form (screen `product-type-list`; no separate add/edit screen — DECISION). */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
require_right('inventory.read');
$pdo = db();
$types = product_types($pdo, false);
log_screen_view($pdo, 'product-type-list');
if (wants_json()) {
    respond_screen(['product_types' => array_map('present_product_type', $types)]);
}
render_screen('Product types', view('catalog/product-types.php', ['types' => $types, 'mayWrite' => has_right('catalog.write'), 'notice' => inv_notice($_GET['notice'] ?? null, ['saved' => ['success', 'Saved.'], 'created' => ['success', 'The type is added.']])]),
    ['activeNav' => 'product-type-list', 'screen' => 'product-type-list', 'entity' => 'product_type']);

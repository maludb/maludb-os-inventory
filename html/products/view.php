<?php
declare(strict_types=1);
/** /products/{id}?tab= — one product (screen `product-view`): the variants table with stock, best offer and the three prices (cost walled); the tabs. JSON: product_full(). */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/activity/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/activity/present.php';
require_right('inventory.read');
$pdo = db();
$id = request_integer('id') ?? request_integer('product');
$p = catalog_product_or_404($pdo, $id);
$full = product_full($pdo, $p['product_id']);
$tab = request_string('tab') ?: 'variants';
log_activity($pdo, 'screen.view', 'product', $p['product_id'], ['screen' => 'product-view', 'after' => ['product_id' => $p['product_id'], 'tab' => $tab]]);
if (wants_json()) {
    respond_screen(['product' => present_product($full['product']), 'variants' => array_map('present_variant', $full['variants']), 'identifiers' => array_map('present_identifier', $full['identifiers']),
        'bundle_components' => $full['bundle_components'], 'images' => array_map('present_image', $full['images']), 'listings' => $full['listings'], 'notes' => $full['notes'], 'attachments' => $full['attachments'],
        'deletable' => has_right('records.delete') ? product_deletable($pdo, $p['product_id']) : null, 'may' => ['write' => has_right('catalog.write'), 'delete' => has_right('records.delete'), 'cost' => sees_cost()]]);
}
$notices = ['created' => ['success', 'The product is made. Add its variants.'], 'saved' => ['success', 'Saved.'], 'discontinued' => ['success', 'The product is discontinued; its variants stop selling.'], 'reactivated' => ['success', 'The product is active again.'],
    'variant_deleted' => ['success', 'The variant was deleted.'], 'variant_created' => ['success', 'The variant is made.']];
render_screen($p['name'], view('catalog/product.php', ['full' => $full, 'tab' => $tab, 'may' => ['write' => has_right('catalog.write'), 'delete' => has_right('records.delete'), 'prices' => has_right('prices.write')],
    'deletable' => has_right('records.delete') ? product_deletable($pdo, $p['product_id']) : 'n/a', 'seesCost' => sees_cost(), 'vocab' => settings_vocabulary($pdo), 'tz' => member_timezone(), 'here' => here_url(),
    'trail' => $tab === 'trail' ? find_record_activity($pdo, 'product', $p['product_id']) : [], 'notice' => inv_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'product-list', 'screen' => 'product-view', 'entity' => 'product', 'recordId' => (string) $p['product_id']]);

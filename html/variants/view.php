<?php
declare(strict_types=1);
/** /variants/{id}?tab= — one variant (screen `variant-view`): own stock, the offers ranked, the prices with their inline forms, the price history chart, identifiers, the bundle, open lines, watches, the trail. JSON: variant_full(). */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/activity/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/activity/present.php';
require_right('inventory.read');
$pdo = db();
$v = catalog_variant_or_404($pdo, request_integer('id') ?? request_integer('variant'));
$vid = $v['variant_id'];
$full = variant_full($pdo, $vid);
$product = catalog_product_or_404($pdo, $v['product_id']);
$history = price_history($pdo, $vid, null, null, 100);
log_activity($pdo, 'screen.view', 'product_variant', $vid, ['screen' => 'variant-view', 'after' => ['product_id' => $v['product_id'], 'variant_id' => $vid]]);
if (wants_json()) {
    respond_screen(['variant' => present_variant($full['variant']), 'identifiers' => array_map('present_identifier', $full['identifiers']), 'availability' => $full['availability'], 'price_history' => array_map('present_price_row', $full['price_history']),
        'bundles_containing' => $full['bundles_containing'], 'open_lines' => $full['open_lines'], 'watches' => $full['watches'], 'deletable' => has_right('records.delete') ? variant_deletable($pdo, $vid) : null,
        'may' => ['write' => has_right('catalog.write'), 'delete' => has_right('records.delete'), 'prices' => has_right('prices.write'), 'cost' => sees_cost()]]);
}
$notices = ['created' => ['success', 'The variant is made.'], 'saved' => ['success', 'Saved.'], 'price_set' => ['success', 'The price is set and remembered with its reason.']];
render_screen($v['sku'], view('catalog/variant.php', ['full' => $full, 'product' => $product, 'may' => ['write' => has_right('catalog.write'), 'delete' => has_right('records.delete'), 'prices' => has_right('prices.write'), 'cost' => sees_cost() && has_right('prices.write')],
    'deletable' => has_right('records.delete') ? variant_deletable($pdo, $vid) : 'n/a', 'seesCost' => sees_cost(), 'vocab' => settings_vocabulary($pdo), 'tz' => member_timezone(), 'here' => here_url(),
    'trail' => find_record_activity($pdo, 'product_variant', $vid, 10), 'history' => $history, 'series' => price_series($history), 'notice' => inv_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'product-list', 'screen' => 'variant-view', 'entity' => 'variant', 'recordId' => (string) $vid]);

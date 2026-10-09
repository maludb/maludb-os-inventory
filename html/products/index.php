<?php
declare(strict_types=1);
/** /products/ — the products as cards with filters (screen `product-list`; params q, brand, type, status, kind, page). JSON: the rows with their state. */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
require_right('inventory.read');
$pdo = db();
$filters = ['q' => request_string('q'), 'brand' => request_integer('brand') ?? '', 'type' => request_string('type'), 'status' => request_string('status'), 'kind' => request_string('kind')];
$page = max(1, request_integer('page') ?? 1);
$r = find_products($pdo, ['q' => $filters['q'], 'brand' => $filters['brand'], 'product_type' => $filters['type'], 'status' => $filters['status'], 'kind' => $filters['kind']], PRODUCT_PAGE, ($page - 1) * PRODUCT_PAGE);
foreach ($r['rows'] as &$p) { $p['state'] = product_state($pdo, $p['product_id']); }
unset($p);
log_screen_view($pdo, 'product-list');
if (wants_json()) {
    respond_screen(['filters' => $filters, 'page' => $page, 'total' => $r['total'], 'products' => array_map('present_product', $r['rows'])]);
}
$notices = ['deleted' => ['success', 'The product and its variants were deleted.'], 'imported' => ['success', 'The import finished.']];
render_screen('Products', view('catalog/products.php', ['rows' => $r['rows'], 'total' => $r['total'], 'page' => $page, 'filters' => $filters, 'brands' => find_brands($pdo, null, true, 500), 'types' => product_types($pdo, false),
    'mayWrite' => has_right('catalog.write'), 'tz' => member_timezone(), 'notice' => inv_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'product-list', 'screen' => 'product-list', 'entity' => 'product']);

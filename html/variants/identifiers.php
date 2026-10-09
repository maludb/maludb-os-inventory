<?php
declare(strict_types=1);
/** /variants/{id}/identifiers — the codes sellers use for this variant (screen `variant-identifiers`): the add form and the table. inventory.read; the form for catalog.write. */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
require_right('inventory.read');
$pdo = db();
$v = catalog_variant_or_404($pdo, request_integer('id') ?? request_integer('variant'));
$product = catalog_product_or_404($pdo, $v['product_id']);
$identifiers = variant_identifiers($pdo, $v['variant_id']);
log_screen_view($pdo, 'variant-identifiers');
if (wants_json()) {
    respond_screen(['variant' => present_variant($v), 'identifiers' => array_map('present_identifier', $identifiers), 'kinds' => array_keys(IDENTIFIER_KINDS), 'sources' => supplier_sources($pdo)]);
}
$notices = ['added' => ['success', 'The identifier is added.'], 'removed' => ['success', 'The identifier was removed.']];
render_screen('Identifiers of ' . $v['sku'], view('catalog/variant-identifiers.php', ['variant' => $v, 'product' => $product, 'identifiers' => $identifiers, 'sources' => supplier_sources($pdo), 'here' => here_url(), 'mayWrite' => has_right('catalog.write'), 'tz' => member_timezone(), 'notice' => inv_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'product-list', 'screen' => 'variant-identifiers', 'entity' => 'variant', 'recordId' => (string) $v['variant_id']]);

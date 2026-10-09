<?php
declare(strict_types=1);
/** /variants/new?product= and /variants/{id}/edit (screens `variant-add`, `variant-edit`). catalog.write; people only. */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
require_right('catalog.write');
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('variant');
$cur = $id === null ? null : catalog_variant_or_404($pdo, $id);
$product = catalog_product_or_404($pdo, $cur === null ? request_integer('product') : $cur['product_id']);
$screen = $cur === null ? 'variant-add' : 'variant-edit';
$vocab = settings_vocabulary($pdo);
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['product' => present_product($product), 'variant' => $cur === null ? null : present_variant($cur), 'sizes' => $vocab['sizes'], 'units' => $vocab['units'], 'currency' => $vocab['currency'], 'ships_how' => array_keys(SHIPS_HOW), 'may_cost' => sees_cost() && has_right('prices.write')]);
}
render_screen($cur === null ? 'New variant' : 'Change ' . $cur['sku'], view('catalog/variant-form.php', ['product' => $product, 'cur' => $cur, 'own' => $cur === null ? [] : variant_own_columns($pdo, $cur['variant_id']), 'vocab' => $vocab, 'mayCost' => sees_cost() && has_right('prices.write'), 'here' => here_url()]),
    ['activeNav' => 'product-list', 'screen' => $screen, 'entity' => 'variant', 'recordId' => $id === null ? '' : (string) $id]);

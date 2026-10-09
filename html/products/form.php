<?php
declare(strict_types=1);
/** /products/new?brand=&type= and /products/{id}/edit (screens `product-add`, `product-edit`). catalog.write; people only. */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
require_right('catalog.write');
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('product');
$cur = $id === null ? null : catalog_product_or_404($pdo, $id);
$screen = $cur === null ? 'product-add' : 'product-edit';
$vocab = settings_vocabulary($pdo);
$types = product_types($pdo, true);
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['product' => $cur === null ? null : present_product($cur), 'types' => array_map('present_product_type', $types), 'attribute_keys' => $vocab['attribute_keys'], 'kinds' => array_keys(PRODUCT_KINDS), 'ships_how' => array_keys(SHIPS_HOW)]);
}
render_screen($cur === null ? 'New product' : 'Change ' . $cur['name'], view('catalog/product-form.php', ['cur' => $cur, 'vocab' => $vocab, 'types' => $types, 'prefill' => ['brand' => request_integer('brand'), 'type' => request_string('type') !== '' ? (product_type_by_key_or_id($pdo, request_string('type'))['product_type_id'] ?? null) : null], 'here' => here_url()]),
    ['activeNav' => 'product-list', 'screen' => $screen, 'entity' => 'product', 'recordId' => $id === null ? '' : (string) $id]);

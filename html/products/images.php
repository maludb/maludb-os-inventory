<?php
declare(strict_types=1);
/** /products/{id}/images — the product's images (screen `product-images`): the grid with Make primary / Up / Down / Remove and the upload form. catalog.write. */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
require_once dirname(__DIR__, 2) . '/app/attachments.php';
require_right('catalog.write');
$pdo = db();
$id = request_integer('id') ?? request_integer('product');
$p = catalog_product_or_404($pdo, $id);
$images = product_images($pdo, $p['product_id']);
log_screen_view($pdo, 'product-images');
if (wants_json()) {
    respond_screen(['product' => present_product($p), 'images' => array_map('present_image', $images), 'max_bytes' => attachment_max_bytes($pdo)]);
}
$notices = ['added' => ['success', 'The image is uploaded.'], 'updated' => ['success', 'Saved.'], 'removed' => ['success', 'The image was removed.']];
render_screen('Images of ' . $p['name'], view('catalog/product-images.php', ['product' => $p, 'images' => $images, 'variants' => product_variants($pdo, $p['product_id'], false), 'here' => here_url(), 'max' => attachment_max_bytes($pdo), 'notice' => inv_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'product-list', 'screen' => 'product-images', 'entity' => 'product', 'recordId' => (string) $p['product_id']]);

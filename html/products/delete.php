<?php
declare(strict_types=1);
/** Action `product_delete` (log `product.delete`: name, variants; confirm; an agent pauses — deletion): one with no stock movement, no order line and no match. records.delete. Location /products/. */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
catalog_write_begin('records.delete');
$pdo = db();
$p = catalog_product_or_404($pdo, request_integer('product'));
$why = product_deletable($pdo, $p['product_id']);
if ($why !== null) {
    refuse(422, $why);
}
$variants = product_variants($pdo, $p['product_id'], false);
inv_guard($pdo, static function () use ($pdo, $p, $variants): void {
    $pdo->beginTransaction();
    delete_product($pdo, $p['product_id']);
    catalog_log($pdo, 'product.delete', 'product', $p['product_id'], ['before' => product_loggable($p), 'after' => ['name' => $p['name'], 'variants' => array_column($variants, 'sku')]]);
    $pdo->commit();
});
inv_done('Deleted ' . $p['name'] . ' and ' . count($variants) . ' variant' . (count($variants) === 1 ? '' : 's'), $p['product_id'], inv_land('/products/', 'deleted'), 'productChanged');

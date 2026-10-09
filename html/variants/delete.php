<?php
declare(strict_types=1);
/** Action `variant_delete` (log `variant.delete`; confirm; an agent pauses — deletion): one with no stock movement, no order line and no match. records.delete. Location /products/{product}. */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
catalog_write_begin('records.delete');
$pdo = db();
$v = catalog_variant_or_404($pdo, request_integer('variant'));
$why = variant_deletable($pdo, $v['variant_id']);
if ($why !== null) { refuse(422, $why); }
inv_guard($pdo, static function () use ($pdo, $v): void {
    $pdo->beginTransaction();
    delete_variant($pdo, $v['variant_id']);
    catalog_log($pdo, 'variant.delete', 'product_variant', $v['variant_id'], ['before' => variant_loggable($v), 'after' => ['product_id' => $v['product_id'], 'sku' => $v['sku']]]);
    $pdo->commit();
});
inv_done('Deleted ' . $v['sku'], $v['variant_id'], inv_land('/products/' . $v['product_id'], 'variant_deleted'), 'productChanged', ['product_id' => $v['product_id']]);

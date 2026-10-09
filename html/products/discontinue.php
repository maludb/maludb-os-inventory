<?php
declare(strict_types=1);
/** Action `product_discontinue` (log `product.discontinue`, after.discontinued; confirm): `discontinued` yes (the variants stop selling — stock and history stay) or no (reactivates). catalog.write. */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
catalog_write_begin('catalog.write');
$pdo = db();
$p = catalog_product_or_404($pdo, request_integer('product'));
$discontinued = inv_yes('discontinued', true);
if ($discontinued === ($p['status'] === 'discontinued')) {
    refuse(422, $discontinued ? $p['name'] . ' is already discontinued.' : $p['name'] . ' is not discontinued.');
}
inv_guard($pdo, static function () use ($pdo, $p, $discontinued): void {
    $pdo->beginTransaction();
    discontinue_product($pdo, $p['product_id'], $discontinued);
    catalog_log($pdo, 'product.discontinue', 'product', $p['product_id'], ['before' => ['status' => $p['status']], 'after' => ['discontinued' => $discontinued, 'status' => $discontinued ? 'discontinued' : 'active', 'name' => $p['name']]]);
    $pdo->commit();
});
inv_done(($discontinued ? 'Discontinued ' : 'Reactivated ') . $p['name'], $p['product_id'], inv_land('/products/' . $p['product_id'], $discontinued ? 'discontinued' : 'reactivated'), 'productChanged', ['discontinued' => $discontinued]);

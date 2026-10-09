<?php
declare(strict_types=1);
/**
 * Actions `product_create` (no `product`) / `product_update` (with; log `product.create` / `product.update` — the changed fields, never the description's
 * words): catalog.write. A field left out stays as it was. A status of discontinued is refused pointing at product_discontinue; a kind change on a product
 * whose variants have stock, lines or matches is refused in words. Location /products/{id}; refresh productChanged.
 */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
catalog_write_begin('catalog.write');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('product') ?? request_integer('product_id');
$cur = $id === null ? null : catalog_product_or_404($pdo, $id);
$errors = [];
$f = product_from_request($pdo, $cur, $errors);
if ($errors !== []) {
    inv_refuse_fields($errors);
}
$newId = inv_guard($pdo, static function () use ($pdo, $me, $id, $cur, $f): int {
    $pdo->beginTransaction();
    $newId = save_product($pdo, $id, $f, $me);
    $after = product_loggable(find_product($pdo, $newId));
    if ($cur === null) {
        catalog_log($pdo, 'product.create', 'product', $newId, ['after' => $after]);
    } else {
        $d = inv_diff(product_loggable($cur), $after);
        if ($d['after'] !== []) {
            catalog_log($pdo, 'product.update', 'product', $newId, ['before' => $d['before'], 'after' => $d['after']]);
        }
    }
    $pdo->commit();
    return $newId;
});
inv_done(($id === null ? 'Made ' : 'Saved ') . $f['name'], $newId, inv_land(return_path('/products/' . $newId), $id === null ? 'created' : 'saved'), 'productChanged', ['product_id' => $newId]);

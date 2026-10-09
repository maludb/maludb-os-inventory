<?php
declare(strict_types=1);
/**
 * Actions `variant_create` (no `variant`) / `variant_update` (with; log `variant.create` / `variant.update` with after.product_id): catalog.write. A create
 * carries the three prices (the trigger records price_history with reason `created`); an update with a price field is refused pointing at price_set.
 * The database judges the SKU (unique, never empty) and the barcode (a GTIN, unique) — a 23505 reads "That SKU is already taken." Location /variants/{id}.
 */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
catalog_write_begin('catalog.write');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('variant') ?? request_integer('variant_id');
$cur = $id === null ? null : catalog_variant_or_404($pdo, $id);
if ($cur !== null) { $cur += variant_own_columns($pdo, $cur['variant_id']); }
$product = catalog_product_or_404($pdo, $cur === null ? request_integer('product') : $cur['product_id']);
$errors = [];
$f = variant_from_request($pdo, $product, $cur, $errors);
if ($errors !== []) {
    inv_refuse_fields($errors);
}
$newId = inv_guard($pdo, static function () use ($pdo, $me, $id, $cur, $f): int {
    try {
        $pdo->beginTransaction();
        $newId = save_variant($pdo, $id, $f, $me);
        $row = find_variant($pdo, $newId);
        $after = variant_loggable($row);
        if ($cur === null) {
            foreach (['retail_price', 'map_price', 'cost_price'] as $pk) { if ($f[$pk] !== null) { $after[$pk] = $f[$pk]; } }
            catalog_log($pdo, 'variant.create', 'product_variant', $newId, ['after' => $after]);
        } else {
            $d = inv_diff(variant_loggable($cur), $after);
            if ($d['after'] !== []) {
                catalog_log($pdo, 'variant.update', 'product_variant', $newId, ['before' => $d['before'], 'after' => $d['after'] + ['product_id' => $after['product_id']]]);
            }
        }
        $pdo->commit();
        return $newId;
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '23505') {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw new DomainException(str_contains($e->getMessage(), 'barcode') ? 'That barcode is already on another variant.' : 'That SKU is already taken.');
        }
        throw $e;
    }
});
inv_done(($id === null ? 'Made the variant ' : 'Saved ') . $f['sku'], $newId, inv_land(return_path('/variants/' . $newId), $id === null ? 'created' : 'saved'), 'productChanged', ['variant_id' => $newId, 'product_id' => $f['product_id']]);

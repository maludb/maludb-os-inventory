<?php
declare(strict_types=1);
/** Action `supplier_item_remove` (log `supplier.item_remove`): a row off a supplier's price sheet. suppliers.write. */
require_once dirname(__DIR__, 2) . '/app/features/supplier_items/handler.php';
inv_handler_begin();
require_right('suppliers.write');
$pdo = db();
$id = request_integer('supplier_item') ?? request_integer('supplier_item_id');
if ($id === null || find_supplier_item($pdo, $id) === null) { refuse(404, 'Price-sheet row not found.'); }
$old = inv_guard($pdo, static function () use ($pdo, $id): array {
    $pdo->beginTransaction();
    $old = remove_supplier_item($pdo, $id);
    log_activity($pdo, 'supplier.item_remove', 'supplier_item', $id, ['after' => ['supplier_id' => (int) $old['supplier_id'], 'sku' => $old['sku'], 'supplier_sku' => $old['supplier_sku']]]);
    $pdo->commit();
    return $old;
});
inv_done('Removed ' . $old['sku'] . ' from the price sheet', $id, inv_land('/supplier-items/?supplier=' . (int) $old['supplier_id'], 'removed'), 'supplierChanged');

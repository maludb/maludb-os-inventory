<?php
declare(strict_types=1);
/** Action `supplier_item_save` (log `supplier.item_save`: supplier_id, sku, supplier_sku, cost, lead_time_days, moq): one row of a supplier's price sheet by (supplier, variant) — the fields given; cost only for a caller who sees cost. A Pattern C request answers the row. suppliers.write. */
require_once dirname(__DIR__, 2) . '/app/features/supplier_items/handler.php';
inv_handler_begin();
require_right('suppliers.write');
$pdo = db();
$cur = request_integer('supplier_item') !== null ? (find_supplier_item($pdo, (int) request_integer('supplier_item')) ?? refuse(404, 'Price-sheet row not found.')) : null;
$errors = [];
$sup = $cur['supplier_id'] ?? inv_ref($pdo, 'supplier', null, 'SELECT 1 FROM mcp_suppliers WHERE supplier_id = :id', 'the supplier', $errors, false);
$vid = $cur['variant_id'] ?? null;
if ($cur === null) {
    $v = (string) (req_val('variant') ?? '');
    $vid = $v === '' ? null : (ctype_digit($v) ? one_value($pdo, 'SELECT variant_id FROM mcp_product_variants WHERE variant_id = :v', ['v' => (int) $v]) : one_value($pdo, 'SELECT variant_id FROM mcp_product_variants WHERE lower(sku) = lower(:s)', ['s' => $v]));
    if ($vid === null) { $errors['variant'] = 'Choose the variant.'; }
}
$f = supplier_item_from_request($cur, $errors);
if ($errors !== []) { inv_refuse_fields($errors); }
$id = inv_guard($pdo, static function () use ($pdo, $sup, $vid, $f): int {
    $pdo->beginTransaction();
    $id = save_supplier_item($pdo, (int) $sup, (int) $vid, $f);
    $row = $pdo->query('SELECT si.supplier_sku, si.cost, si.lead_time_days, si.moq, v.sku FROM supplier_items si JOIN product_variants v ON v.id = si.variant_id WHERE si.id = ' . $id)->fetch();
    log_activity($pdo, 'supplier.item_save', 'supplier_item', $id, ['after' => ['supplier_id' => (int) $sup, 'sku' => $row['sku'], 'supplier_sku' => $row['supplier_sku'], 'cost' => $row['cost'],
        'lead_time_days' => $row['lead_time_days'] === null ? null : (int) $row['lead_time_days'], 'moq' => (int) $row['moq'], 'fields' => array_keys($f)]]);
    $pdo->commit();
    return $id;
});
if (is_htmx_request() && !wants_json() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'supplier-item-row-' . $id) {
    emit_action_status(true, ['did' => 'Saved', 'record_id' => $id]);
    hx_trigger('supplierChanged');
    echo view('supplier_items/partials/item-row.php', ['r' => find_supplier_item($pdo, $id), 'mayWrite' => true, 'seesCost' => sees_cost(), 'here' => '/supplier-items/?supplier=' . (int) $sup]);
    exit;
}
inv_done('Saved the price-sheet row', $id, inv_land('/supplier-items/?supplier=' . (int) $sup, 'saved', 'supplier-item-row-' . $id), 'supplierChanged', ['supplier_item_id' => $id]);

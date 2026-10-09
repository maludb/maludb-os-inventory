<?php
declare(strict_types=1);
/** /supplier-items/?supplier=&q=&page= — a supplier's price sheet (screen `supplier-item-list`): their SKU, ours, cost (walled), lead time, MOQ, the feed that keeps it; the add form and inline edits for suppliers.write. */
require_once dirname(__DIR__, 2) . '/app/features/supplier_items/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/sources/queries.php';
require_right('inventory.read');
$pdo = db();
$suppliers = source_suppliers($pdo);
$sup = request_integer('supplier') ?? (isset($suppliers[0]) ? (int) $suppliers[0]['supplier_id'] : null);
$q = request_string('q');
$rows = $sup === null ? [] : supplier_items($pdo, ['supplier' => $sup, 'q' => $q], 500);
log_screen_view($pdo, 'supplier-item-list');
if (wants_json()) {
    respond_screen(['supplier' => $sup, 'q' => $q, 'items' => array_map('present_supplier_item', $rows), 'suppliers' => $suppliers]);
}
render_screen('Price sheets', view('supplier_items/index.php', ['rows' => $rows, 'supplier' => $sup, 'suppliers' => $suppliers, 'q' => $q, 'mayWrite' => has_right('suppliers.write'), 'seesCost' => sees_cost(),
    'here' => here_url(), 'notice' => inv_notice($_GET['notice'] ?? null, ['saved' => ['success', 'Saved.'], 'removed' => ['success', 'Removed.']])]),
    ['activeNav' => 'supplier-item-list', 'screen' => 'supplier-item-list', 'entity' => 'supplier_item']);

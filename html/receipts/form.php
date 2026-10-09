<?php
declare(strict_types=1);
/** /receipts/new?purchase_order=&supplier=&location= and /receipts/{id}/edit (screens `receipt-add`, `receipt-edit`). stock.receive. With a stock PO its open lines are offered to prefill. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
require_right('stock.receive');
$pdo = db();
$id = request_integer('id') ?? request_integer('receipt');
$cur = $id === null ? null : receipt_or_404($pdo, $id);
if ($cur !== null && $cur['status'] !== 'draft') { refuse(422, $cur['number'] . ' is ' . $cur['status'] . ' — only a draft receipt changes'); }
$po = null;
$poId = $cur['purchase_order_id'] ?? request_integer('purchase_order');
if ($poId !== null) { $po = find_purchase_order_brief($pdo, (int) $poId); }
$supplierId = $cur['supplier_id'] ?? ($po['supplier_id'] ?? request_integer('supplier'));
$screen = $cur === null ? 'receipt-add' : 'receipt-edit';
$poLines = ($cur === null && $po !== null && $po['kind'] === 'stock') ? po_open_lines($pdo, (int) $po['purchase_order_id']) : [];
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['receipt' => $cur === null ? null : present_receipt($cur), 'purchase_order' => $po, 'po_open_lines' => $poLines, 'po_options' => $supplierId ? po_options($pdo, (int) $supplierId) : [],
        'locations' => locations_for_pick($pdo), 'suppliers' => suppliers_active($pdo)]);
}
render_screen($cur === null ? 'New receipt' : 'Change ' . $cur['number'], view('receipts/form.php', ['cur' => $cur, 'po' => $po, 'poLines' => $poLines, 'supplierId' => $supplierId === null ? null : (int) $supplierId,
    'poOptions' => $supplierId ? po_options($pdo, (int) $supplierId) : [], 'locationId' => $cur['location_id'] ?? (request_integer('location') ?? ($po['location_id'] ?? null)),
    'locations' => locations_for_pick($pdo), 'suppliers' => suppliers_active($pdo), 'seesCost' => sees_receipt_cost()]),
    ['activeNav' => 'receipt-list', 'screen' => $screen, 'entity' => 'goods_receipt', 'recordId' => $id === null ? '' : (string) $id]);

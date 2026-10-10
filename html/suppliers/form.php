<?php
declare(strict_types=1);
/** /suppliers/new and /suppliers/{id}/edit (screens `supplier-add`, `supplier-edit`). suppliers.write; people only. The account number is on the form for a holder of purchasing.write alone. */
require_once dirname(__DIR__, 2) . '/app/features/suppliers/handler.php';
require_right('suppliers.write');
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('supplier');
$cur = $id === null ? null : supplier_or_404($pdo, $id);
$screen = $cur === null ? 'supplier-add' : 'supplier-edit';
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['supplier' => $cur === null ? null : present_supplier($cur), 'kinds' => SUPPLIER_KINDS, 'order_methods' => SUPPLIER_ORDER_METHODS]);
}
render_screen($cur === null ? 'New supplier' : 'Change ' . $cur['name'], view('suppliers/form.php', ['cur' => $cur, 'seesAccount' => has_right('purchasing.write')]),
    ['activeNav' => 'supplier-list', 'screen' => $screen, 'entity' => 'supplier', 'recordId' => $id === null ? '' : (string) $id]);

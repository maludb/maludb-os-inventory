<?php
declare(strict_types=1);
/** /purchasing/?status=&kind=&supplier=&awaiting_ack=&no_tracking=&q= — the purchase orders as a table (screen `purchase-order-list`): the open ones by default, overdue and awaiting-acknowledgment marked, a drop-ship's sales order a link; 50 a page. */
require_once dirname(__DIR__, 2) . '/app/features/purchasing/handler.php';
require_right('inventory.read');
$pdo = db();
$filters = ['status' => request_string('status'), 'kind' => request_string('kind'), 'supplier' => request_integer('supplier') ?? '', 'awaiting_ack' => ($_GET['awaiting_ack'] ?? '') === '1',
            'no_tracking' => ($_GET['no_tracking'] ?? '') === '1', 'order' => request_integer('order') ?? '', 'q' => mb_substr(request_string('q'), 0, 80)];
$page = max(1, request_integer('page') ?? 1);
$rows = find_purchase_orders($pdo, $filters, PO_PAGE, ($page - 1) * PO_PAGE);
$total = count_purchase_orders($pdo, $filters);
log_screen_view($pdo, 'purchase-order-list');
if (wants_json()) {
    respond_screen(['filters' => $filters, 'page' => $page, 'total' => $total, 'purchase_orders' => array_map('present_po_row', $rows)]);
}
render_screen('Purchase orders', view('purchasing/index.php', ['rows' => $rows, 'total' => $total, 'page' => $page, 'filters' => $filters, 'suppliers' => po_suppliers($pdo), 'mayDraft' => has_right('purchasing.write'),
    'seesCost' => sees_cost(), 'here' => here_url(), 'notice' => inv_notice($_GET['notice'] ?? null, PO_NOTICES)]), ['activeNav' => 'purchase-order-list', 'screen' => 'purchase-order-list', 'entity' => 'purchase_order']);

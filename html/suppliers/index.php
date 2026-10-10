<?php
declare(strict_types=1);
/** /suppliers/?q=&dropships= — the suppliers as cards (screen `supplier-list`): kind, drop-ships, lead time, open purchase orders, sources; inactive ones hidden unless searched. */
require_once dirname(__DIR__, 2) . '/app/features/suppliers/handler.php';
require_right('inventory.read');
$pdo = db();
$filters = ['q' => mb_substr(request_string('q'), 0, 80), 'dropships' => ($_GET['dropships'] ?? '') === '1'];
$page = max(1, request_integer('page') ?? 1);
$rows = find_suppliers($pdo, $filters, SUPPLIER_PAGE, ($page - 1) * SUPPLIER_PAGE);
$total = count_suppliers($pdo, $filters);
log_screen_view($pdo, 'supplier-list');
if (wants_json()) {
    respond_screen(['filters' => $filters, 'page' => $page, 'total' => $total, 'suppliers' => array_map('present_supplier', $rows)]);
}
render_screen('Suppliers', view('suppliers/index.php', ['rows' => $rows, 'total' => $total, 'page' => $page, 'filters' => $filters, 'mayWrite' => has_right('suppliers.write'), 'here' => here_url(),
    'notice' => inv_notice($_GET['notice'] ?? null, SUPPLIER_NOTICES)]), ['activeNav' => 'supplier-list', 'screen' => 'supplier-list', 'entity' => 'supplier']);

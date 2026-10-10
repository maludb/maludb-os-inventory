<?php
declare(strict_types=1);
/** /customers/?q=&source=&archived= — the customers as cards (screen `customer-list`): open orders and last order; email and phone for orders.write (the view nulls them for a Viewer). */
require_once dirname(__DIR__, 2) . '/app/features/customers/handler.php';
require_right('inventory.read');
$pdo = db();
$filters = ['q' => request_string('q'), 'source' => request_string('source'), 'archived' => ($_GET['archived'] ?? '') === '1'];
$page = max(1, request_integer('page') ?? 1);
$rows = find_customers($pdo, $filters, CUSTOMER_PAGE, ($page - 1) * CUSTOMER_PAGE);
$total = count_customers($pdo, $filters);
log_screen_view($pdo, 'customer-list');
if (wants_json()) {
    respond_screen(['filters' => $filters, 'page' => $page, 'total' => $total, 'customers' => array_map('present_customer', $rows)]);
}
render_screen('Customers', view('customers/index.php', ['rows' => $rows, 'total' => $total, 'page' => $page, 'filters' => $filters, 'mayWrite' => has_right('customers.write'), 'here' => here_url(),
    'notice' => inv_notice($_GET['notice'] ?? null, CUSTOMER_NOTICES)]), ['activeNav' => 'customer-list', 'screen' => 'customer-list', 'entity' => 'customer']);

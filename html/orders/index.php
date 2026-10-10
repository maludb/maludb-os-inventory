<?php
declare(strict_types=1);
/** /orders/?status=&customer=&salesperson=&location=&late=&from=&to= — the orders as a table (screen `order-list`): the open ones by default, late marked in red with the days; 50 a page. */
require_once dirname(__DIR__, 2) . '/app/features/orders/handler.php';
require_right('inventory.read');
$pdo = db();
$filters = ['status' => request_string('status'), 'customer' => request_integer('customer') ?? '', 'salesperson' => request_integer('salesperson') ?? '', 'location' => request_integer('location') ?? '',
            'late' => ($_GET['late'] ?? '') === '1', 'from' => request_string('from'), 'to' => request_string('to'), 'q' => mb_substr(request_string('q'), 0, 80)];
foreach (['from', 'to'] as $d) { if ($filters[$d] !== '' && request_date($d) === false) { refuse(422, 'That is not a date.'); } }
$page = max(1, request_integer('page') ?? 1);
$rows = find_orders($pdo, $filters, ORDER_PAGE, ($page - 1) * ORDER_PAGE);
$total = count_orders($pdo, $filters);
log_screen_view($pdo, 'order-list');
if (wants_json()) {
    respond_screen(['filters' => $filters, 'page' => $page, 'total' => $total, 'orders' => array_map('present_order_row', $rows)]);
}
render_screen('Orders', view('orders/index.php', ['rows' => $rows, 'total' => $total, 'page' => $page, 'filters' => $filters, 'locations' => order_locations($pdo), 'salespeople' => order_salespeople($pdo),
    'mayQuote' => has_right('orders.write'), 'here' => here_url(), 'notice' => inv_notice($_GET['notice'] ?? null, ORDER_NOTICES)]), ['activeNav' => 'order-list', 'screen' => 'order-list', 'entity' => 'sales_order']);

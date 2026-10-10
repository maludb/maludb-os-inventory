<?php
declare(strict_types=1);
/** /customers/{id}?tab= — one customer (screen `customer-view`): the facts; tabs Orders (open first), Returns, Notes, Attachments, Trail. */
require_once dirname(__DIR__, 2) . '/app/features/customers/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/orders/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/orders/present.php';
require_once dirname(__DIR__, 2) . '/app/features/activity/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/activity/present.php';
require_right('inventory.read');
$pdo = db();
$c = customer_or_404($pdo, request_integer('id') ?? request_integer('customer'));
$cid = $c['customer_id'];
$tabs = ['orders' => 'Orders', 'returns' => 'Returns', 'notes' => 'Notes', 'attachments' => 'Attachments', 'trail' => 'Trail'];
$tab = request_string('tab', 'orders');
if (!isset($tabs[$tab])) { $tab = 'orders'; }
$data = ['orders' => [], 'returns' => [], 'notes' => [], 'attachments' => [], 'trail' => []];
if ($tab === 'orders' || wants_json()) { $data['orders'] = find_orders($pdo, ['customer' => $cid, 'status' => 'all'], 200, 0); }
if ($tab === 'returns' || wants_json()) { $data['returns'] = customer_returns($pdo, $cid); }
if ($tab === 'notes' || wants_json()) { $data['notes'] = record_notes($pdo, 'customer', $cid); }
if ($tab === 'attachments' || wants_json()) { $data['attachments'] = record_attachments($pdo, 'customer', $cid); }
if ($tab === 'trail') { $data['trail'] = find_record_activity($pdo, 'customer', $cid, 50); }
log_activity($pdo, 'screen.view', 'customer', $cid, ['screen' => 'customer-view', 'after' => ['tab' => $tab, 'name' => $c['name']]]);
if (wants_json()) {
    respond_screen(['customer' => present_customer($c), 'orders' => array_map('present_order_row', $data['orders']), 'returns' => $data['returns'],
        'notes' => $data['notes'], 'attachments' => $data['attachments'], 'deletable' => has_right('records.delete') ? customer_any_orders($pdo, $cid) === 0 : null,
        'may' => ['write' => has_right('customers.write'), 'quote' => has_right('orders.write'), 'delete' => has_right('records.delete')]]);
}
render_screen($c['name'], view('customers/view.php', ['c' => $c, 'tab' => $tab, 'tabs' => $tabs, 'tabdata' => $data, 'may' => ['write' => has_right('customers.write'), 'quote' => has_right('orders.write'), 'delete' => has_right('records.delete')],
    'deletable' => has_right('records.delete') ? customer_any_orders($pdo, $cid) === 0 : false, 'tz' => member_timezone(), 'here' => here_url(), 'notice' => inv_notice($_GET['notice'] ?? null, CUSTOMER_NOTICES)]),
    ['activeNav' => 'customer-list', 'screen' => 'customer-view', 'entity' => 'customer', 'recordId' => (string) $cid]);

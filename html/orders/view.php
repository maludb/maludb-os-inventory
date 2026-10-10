<?php
declare(strict_types=1);
/** /orders/{id}?tab= — one order (screen `order-view`): the header, the lines with where each is filled, payments, shipments, the drop-ship purchase orders, the customer's link, the timeline, notes and attachments, the trail. */
require_once dirname(__DIR__, 2) . '/app/features/orders/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/activity/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/activity/present.php';
require_right('inventory.read');
$pdo = db();
$o = order_or_404($pdo, request_integer('id') ?? request_integer('order'));
$oid = (int) $o['sales_order_id'];
$tab = request_string('tab', 'order');
$tabs = ['order' => 'Order', 'timeline' => 'Timeline', 'notes' => 'Notes', 'attachments' => 'Attachments', 'trail' => 'Trail'];
if (!isset($tabs[$tab])) { $tab = 'order'; }
$extra = ['timeline' => [], 'notes' => [], 'attachments' => [], 'trail' => []];
if ($tab === 'timeline' || wants_json()) { $extra['timeline'] = order_timeline($pdo, $oid); }
if ($tab === 'notes' || wants_json()) { $extra['notes'] = record_notes($pdo, 'sales_order', $oid); }
if ($tab === 'attachments' || wants_json()) { $extra['attachments'] = record_attachments($pdo, 'sales_order', $oid); }
if ($tab === 'trail') { $extra['trail'] = find_activity_by_key($pdo, 'sales_order_id', $oid, 100); }
log_activity($pdo, 'screen.view', 'sales_order', $oid, ['sales_order_id' => $oid, 'location_id' => $o['location_id'], 'screen' => 'order-view', 'after' => ['number' => $o['number'], 'tab' => $tab]]);
$may = ['write' => has_right('orders.write'), 'send' => has_right('orders.send'), 'pay' => has_right('payments.record'), 'ship' => has_right('stock.ship'), 'cost' => sees_cost(), 'customer' => has_right('customers.write'), 'reports' => has_right('reports.read')];
if (wants_json()) {
    respond_screen(['order' => present_order($o), 'timeline' => array_map(static fn (array $t): array => ['at' => json_ts($t['at']), 'kind' => $t['kind'], 'title' => $t['title'], 'detail' => $t['detail'], 'member_name' => $t['member_name']], $extra['timeline']),
        'notes' => $extra['notes'], 'attachments' => $extra['attachments'], 'may' => $may]);
}
render_screen($o['number'], view('orders/view.php', ['o' => $o, 'tab' => $tab, 'tabs' => $tabs, 'extra' => $extra, 'may' => $may, 'tz' => member_timezone(), 'here' => here_url(), 'locations' => sellable_locations($pdo),
    'notice' => inv_notice($_GET['notice'] ?? null, ORDER_NOTICES)]), ['activeNav' => 'order-list', 'screen' => 'order-view', 'entity' => 'sales_order', 'recordId' => (string) $oid]);

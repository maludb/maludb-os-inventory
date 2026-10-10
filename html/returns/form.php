<?php
declare(strict_types=1);
/**
 * GET /returns/new?order= (screen `return-add`) and /returns/{id}/edit (screen `return-edit`; a requested or approved return, the order fixed): the form `return-form` — the order (a picker over the orders with shipped lines),
 * then its returnable lines with a quantity, a reason and a disposition each, how it comes back (pickup or drop-off), when, where to, notes. Saved by save.php. orders.write.
 */
require_once dirname(__DIR__, 2) . '/app/features/returns/handler.php';
require_right('orders.write');
$pdo = db();
$id = request_integer('id');
$cur = $id === null ? null : return_or_404($pdo, $id);
if ($cur !== null && !in_array($cur['status'], ['requested', 'approved'], true)) {
    refuse(422, 'The details of a return change while it is requested or approved (it is ' . $cur['status'] . ')');
}
$orderParam = trim((string) ($_GET['order'] ?? ''));
$orderId = $cur['sales_order_id'] ?? ($orderParam === '' ? null : (ctype_digit($orderParam) ? (int) $orderParam : ((int) (find_order_by_number($pdo, $orderParam)['sales_order_id'] ?? 0) ?: null)));
$order = $orderId === null ? null : one_row($pdo, 'SELECT sales_order_id, number, status, customer_name FROM mcp_sales_orders WHERE sales_order_id = :o', ['o' => $orderId]);
if ($orderId !== null && $order === null) { refuse(404, 'Order not found.'); }
$returnable = $order === null ? [] : returnable_lines($pdo, (int) $orderId, $cur === null ? null : (int) $cur['return_id']);
$have = [];
if ($cur !== null) { foreach (return_lines($pdo, (int) $cur['return_id']) as $l) { $have[$l['sales_order_line_id']] = $l; } }
$q = mb_substr(request_string('q'), 0, 80);
$screen = $cur === null ? 'return-add' : 'return-edit';
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['order' => $order, 'return' => $cur === null ? null : present_return_row($cur), 'returnable_lines' => $returnable, 'reasons' => return_reasons($pdo), 'dispositions' => RETURN_DISPOSITIONS, 'methods' => RETURN_METHODS, 'locations' => return_locations($pdo)]);
}
render_screen($cur === null ? 'Request a return' : 'Change ' . $cur['number'], view('returns/form.php', ['cur' => $cur, 'order' => $order, 'returnable' => $returnable, 'have' => $have, 'reasons' => return_reasons($pdo), 'locations' => return_locations($pdo),
    'lookup' => $order === null ? orders_with_returnable_lines($pdo, $q, 10) : [], 'q' => $q, 'here' => here_url(), 'notice' => inv_notice($_GET['notice'] ?? null, RETURN_NOTICES)]),
    ['activeNav' => 'return-list', 'screen' => $screen, 'entity' => 'return_authorization', 'recordId' => $cur === null ? '' : (string) $cur['return_id']]);

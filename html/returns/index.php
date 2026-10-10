<?php
declare(strict_types=1);
/** /returns/?status=&customer=&awaiting_disposition= — the returns as a table (screen `return-list`): number, status, the order, the customer, lines, method, scheduled date, dispositions, refund; 50 a page. inventory.read. */
require_once dirname(__DIR__, 2) . '/app/features/returns/handler.php';
require_right('inventory.read');
$pdo = db();
$status = request_string('status');
$status = isset(RETURN_STATUSES[$status]) ? $status : '';
$filters = ['status' => $status === '' ? [] : [$status], 'customer' => request_integer('customer') ?? '', 'awaiting_disposition' => ($_GET['awaiting_disposition'] ?? '') === '1'];
$page = max(1, request_integer('page') ?? 1);
$res = find_returns($pdo, $filters, $page);
log_screen_view($pdo, 'return-list');
if (wants_json()) {
    respond_screen(['filters' => ['status' => $status, 'customer' => $filters['customer'], 'awaiting_disposition' => $filters['awaiting_disposition']], 'page' => $res['page'], 'total' => $res['total'], 'returns' => array_map('present_return_row', $res['rows'])]);
}
render_screen('Returns', view('returns/index.php', ['rows' => $res['rows'], 'total' => $res['total'], 'page' => $res['page'], 'pages' => $res['pages'], 'status' => $status, 'filters' => $filters,
    'dispositions' => return_dispositions_by_return($pdo, array_column($res['rows'], 'return_id')), 'may' => ['request' => has_right('orders.write')], 'here' => here_url(), 'notice' => inv_notice($_GET['notice'] ?? null, RETURN_NOTICES)]),
    ['activeNav' => 'return-list', 'screen' => 'return-list', 'entity' => 'return_authorization']);

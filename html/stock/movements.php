<?php
declare(strict_types=1);
/** /stock/movements?variant=&location=&type=&from=&to=&page= — the ledger (screen `movement-list`): the last 30 days by default; Reverse on a row (stock.adjust). */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
require_right('inventory.read');
$pdo = db();
$from = request_date('from');
$to = request_date('to');
$filters = ['variant' => request_integer('variant'), 'location' => request_integer('location'), 'txn_type' => request_string('type') === '' ? [] : [request_string('type')],
            'from' => is_string($from) ? $from : null, 'to' => is_string($to) ? $to : null, 'days' => 30];
$page = max(1, request_integer('page') ?? 1);
$res = stock_movements($pdo, $filters, MOVEMENTS_PAGE, ($page - 1) * MOVEMENTS_PAGE);
log_screen_view($pdo, 'movement-list');
if (wants_json()) {
    respond_screen(['filters' => $filters, 'page' => $page, 'more' => $res['more'], 'rows' => array_map('present_movement', $res['rows'])]);
}
render_screen('Movements', view('stock/movements.php', ['rows' => $res['rows'], 'more' => $res['more'], 'filters' => $filters, 'page' => $page, 'locations' => locations_for_pick($pdo, true),
    'variant' => $filters['variant'] ? find_variant($pdo, $filters['variant']) : null, 'mayReverse' => has_right('stock.adjust'), 'seesCost' => sees_cost(), 'seesReceiptCost' => sees_receipt_cost(),
    'tz' => member_timezone(), 'here' => here_url(), 'notice' => inv_notice($_GET['notice'] ?? null, STOCK_NOTICES)]),
    ['activeNav' => 'movement-list', 'screen' => 'movement-list', 'entity' => 'inventory_transaction']);

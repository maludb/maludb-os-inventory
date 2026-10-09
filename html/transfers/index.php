<?php
declare(strict_types=1);
/** /transfers/?status=&location=&page= — the transfers (screen `transfer-list`), open ones first. stock.transfer. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
require_right('stock.transfer');
$pdo = db();
$filters = ['status' => request_string('status'), 'location' => request_integer('location')];
$page = max(1, request_integer('page') ?? 1);
$rows = transfers_open($pdo, $filters, 101, ($page - 1) * 100);
$more = count($rows) > 100;
$rows = array_slice($rows, 0, 100);
log_screen_view($pdo, 'transfer-list');
if (wants_json()) {
    respond_screen(['filters' => $filters, 'page' => $page, 'more' => $more, 'transfers' => array_map('present_transfer', $rows)]);
}
render_screen('Transfers', view('transfers/index.php', ['rows' => $rows, 'more' => $more, 'page' => $page, 'filters' => $filters, 'locations' => locations_for_pick($pdo, true), 'tz' => member_timezone(), 'here' => here_url(),
    'notice' => inv_notice($_GET['notice'] ?? null, STOCK_NOTICES)]), ['activeNav' => 'transfer-list', 'screen' => 'transfer-list', 'entity' => 'inventory_transfer']);

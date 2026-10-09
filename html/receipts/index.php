<?php
declare(strict_types=1);
/** /receipts/?status=&supplier=&location=&page= — the goods receipts (screen `receipt-list`), drafts first. stock.receive. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
require_right('stock.receive');
$pdo = db();
$filters = ['status' => request_string('status'), 'supplier' => request_integer('supplier'), 'location' => request_integer('location')];
$page = max(1, request_integer('page') ?? 1);
$rows = receipts_open($pdo, $filters, 101, ($page - 1) * 100);
$more = count($rows) > 100;
$rows = array_slice($rows, 0, 100);
log_screen_view($pdo, 'receipt-list');
if (wants_json()) {
    respond_screen(['filters' => $filters, 'page' => $page, 'more' => $more, 'receipts' => array_map('present_receipt', $rows)]);
}
render_screen('Receipts', view('receipts/index.php', ['rows' => $rows, 'more' => $more, 'page' => $page, 'filters' => $filters, 'suppliers' => suppliers_active($pdo), 'locations' => locations_for_pick($pdo, true),
    'tz' => member_timezone(), 'here' => here_url(), 'notice' => inv_notice($_GET['notice'] ?? null, STOCK_NOTICES)]), ['activeNav' => 'receipt-list', 'screen' => 'receipt-list', 'entity' => 'goods_receipt']);

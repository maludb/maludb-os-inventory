<?php
declare(strict_types=1);
/** /adjustments/?status=&location=&reason=&page= — the adjustments (screen `adjustment-list`), drafts first. stock.adjust. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
require_right('stock.adjust');
$pdo = db();
$filters = ['status' => request_string('status'), 'location' => request_integer('location'), 'reason' => request_string('reason')];
$page = max(1, request_integer('page') ?? 1);
$rows = adjustments($pdo, $filters, 101, ($page - 1) * 100);
$more = count($rows) > 100;
$rows = array_slice($rows, 0, 100);
log_screen_view($pdo, 'adjustment-list');
if (wants_json()) {
    respond_screen(['filters' => $filters, 'page' => $page, 'more' => $more, 'adjustments' => array_map('present_adjustment', $rows)]);
}
render_screen('Adjustments', view('adjustments/index.php', ['rows' => $rows, 'more' => $more, 'page' => $page, 'filters' => $filters, 'reasons' => adjustment_reasons($pdo), 'locations' => locations_for_pick($pdo, true),
    'tz' => member_timezone(), 'here' => here_url(), 'notice' => inv_notice($_GET['notice'] ?? null, STOCK_NOTICES)]), ['activeNav' => 'adjustment-list', 'screen' => 'adjustment-list', 'entity' => 'inventory_adjustment']);

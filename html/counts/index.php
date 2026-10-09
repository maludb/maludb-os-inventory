<?php
declare(strict_types=1);
/** /counts/?status=&location=&page= — the counts (screen `count-list`), open ones first. stock.count. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
require_right('stock.count');
$pdo = db();
$filters = ['status' => request_string('status'), 'location' => request_integer('location')];
$page = max(1, request_integer('page') ?? 1);
$rows = counts($pdo, $filters, 101, ($page - 1) * 100);
$more = count($rows) > 100;
$rows = array_slice($rows, 0, 100);
log_screen_view($pdo, 'count-list');
if (wants_json()) {
    respond_screen(['filters' => $filters, 'page' => $page, 'more' => $more, 'counts' => array_map('present_count', $rows)]);
}
render_screen('Counts', view('counts/index.php', ['rows' => $rows, 'more' => $more, 'page' => $page, 'filters' => $filters, 'locations' => locations_for_pick($pdo, true), 'tz' => member_timezone(), 'here' => here_url(),
    'notice' => inv_notice($_GET['notice'] ?? null, STOCK_NOTICES)]), ['activeNav' => 'count-list', 'screen' => 'count-list', 'entity' => 'inventory_count']);

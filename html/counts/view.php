<?php
declare(strict_types=1);
/** /counts/{id} — the counting screen (screen `count-view`): scan or type the counted quantities; Post with corrections. stock.count. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/activity/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/activity/present.php';
require_right('stock.count');
$pdo = db();
$c = count_or_404($pdo, request_integer('id') ?? request_integer('count'));
$cid = (int) $c['count_id'];
$lines = count_lines($pdo, $cid);
$movements = $c['status'] === 'posted' ? document_movements($pdo, 'count', $cid) : [];
log_activity($pdo, 'screen.view', 'inventory_count', $cid, ['screen' => 'count-view', 'location_id' => (int) $c['location_id'], 'after' => ['number' => $c['number']]]);
if (wants_json()) {
    respond_screen(['count' => present_count($c), 'lines' => array_map('present_count_line', $lines), 'movements' => array_map('present_movement', $movements)]);
}
render_screen($c['number'], view('counts/view.php', ['c' => $c, 'lines' => $lines, 'movements' => $movements, 'trail' => find_record_activity($pdo, 'inventory_count', $cid, 20),
    'mayReverse' => has_right('stock.adjust'), 'tz' => member_timezone(), 'here' => here_url(), 'notice' => inv_notice($_GET['notice'] ?? null, STOCK_NOTICES)]),
    ['activeNav' => 'count-list', 'screen' => 'count-view', 'entity' => 'inventory_count', 'recordId' => (string) $cid]);

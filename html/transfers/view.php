<?php
declare(strict_types=1);
/** /transfers/{id} — one transfer (screen `transfer-view`): a draft takes lines and is sent; in transit, the received quantities per line; received, both halves. stock.transfer. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/activity/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/activity/present.php';
require_right('stock.transfer');
$pdo = db();
$t = transfer_or_404($pdo, request_integer('id') ?? request_integer('transfer'));
$tid = (int) $t['transfer_id'];
$lines = transfer_lines($pdo, $tid);
$movements = in_array($t['status'], ['in_transit', 'received'], true) ? document_movements($pdo, 'transfer', $tid) : [];
log_activity($pdo, 'screen.view', 'inventory_transfer', $tid, ['screen' => 'transfer-view', 'location_id' => (int) $t['from_location_id'], 'after' => ['number' => $t['number']]]);
if (wants_json()) {
    respond_screen(['transfer' => present_transfer($t), 'lines' => array_map('present_transfer_line', $lines), 'movements' => array_map('present_movement', $movements)]);
}
render_screen($t['number'], view('transfers/view.php', ['t' => $t, 'lines' => $lines, 'movements' => $movements, 'trail' => find_record_activity($pdo, 'inventory_transfer', $tid, 20),
    'mayReverse' => has_right('stock.adjust'), 'tz' => member_timezone(), 'here' => here_url(), 'notice' => inv_notice($_GET['notice'] ?? null, STOCK_NOTICES)]),
    ['activeNav' => 'transfer-list', 'screen' => 'transfer-view', 'entity' => 'inventory_transfer', 'recordId' => (string) $tid]);

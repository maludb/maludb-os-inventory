<?php
declare(strict_types=1);
/** /adjustments/{id} — one adjustment (screen `adjustment-view`): a draft takes lines; a posted one shows its movements. stock.adjust. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/activity/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/activity/present.php';
require_right('stock.adjust');
$pdo = db();
$a = adjustment_or_404($pdo, request_integer('id') ?? request_integer('adjustment'));
$aid = (int) $a['adjustment_id'];
$lines = adjustment_lines($pdo, $aid);
$movements = $a['status'] === 'posted' ? document_movements($pdo, 'adjustment', $aid) : [];
log_activity($pdo, 'screen.view', 'inventory_adjustment', $aid, ['screen' => 'adjustment-view', 'location_id' => (int) $a['location_id'], 'after' => ['number' => $a['number']]]);
if (wants_json()) {
    respond_screen(['adjustment' => present_adjustment($a), 'lines' => array_map('present_adjustment_line', $lines), 'movements' => array_map('present_movement', $movements), 'cost_withheld' => !sees_cost()]);
}
render_screen($a['number'], view('adjustments/view.php', ['a' => $a, 'lines' => $lines, 'movements' => $movements, 'seesCost' => sees_cost(), 'trail' => find_record_activity($pdo, 'inventory_adjustment', $aid, 20),
    'focusDelta' => request_integer('line'), 'tz' => member_timezone(), 'here' => here_url(), 'notice' => inv_notice($_GET['notice'] ?? null, STOCK_NOTICES)]),
    ['activeNav' => 'adjustment-list', 'screen' => 'adjustment-view', 'entity' => 'inventory_adjustment', 'recordId' => (string) $aid]);

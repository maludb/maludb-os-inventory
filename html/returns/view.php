<?php
declare(strict_types=1);
/** /returns/{id} — one return (screen `return-view`): the header, the lines with their dispositions, the refund and fee recorded, the buttons by status (Approve, Deny, Receive, Close, Edit), notes, photos, the timeline. inventory.read. */
require_once dirname(__DIR__, 2) . '/app/features/returns/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/activity/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/activity/present.php';
require_right('inventory.read');
$pdo = db();
$r = return_or_404($pdo, request_return_id($pdo));
$id = (int) $r['return_id'];
return_screen_view($pdo, $r, 'return-view');
$lines = return_lines($pdo, $id);
$may = ['write' => has_right('orders.write'), 'authorize' => has_right('returns.write'), 'receive' => has_right('returns.receive'), 'pay' => has_right('payments.record')];
$refundShown = $r['status'] === 'closed' && (float) $r['refund_amount'] > 0 ? order_has_refund_of($pdo, $r['sales_order_id'], number_format((float) $r['refund_amount'], 2, '.', '')) : null;
$timeline = return_timeline($pdo, $id);
if (wants_json()) {
    respond_screen(['return' => present_return($r, $lines), 'may' => $may, 'refund_recorded_on_order' => $refundShown,
        'timeline' => array_map(static fn (array $t): array => ['at' => json_ts($t['occurred_at']), 'action' => $t['action'], 'actor' => $t['actor_name'], 'source' => $t['source']], $timeline)]);
}
render_screen($r['number'], view('returns/view.php', ['r' => $r, 'lines' => $lines, 'may' => $may, 'refundShown' => $refundShown, 'timeline' => $timeline, 'locations' => return_locations($pdo),
    'tz' => member_timezone(), 'here' => here_url(), 'notice' => inv_notice($_GET['notice'] ?? null, RETURN_NOTICES)]),
    ['activeNav' => 'return-list', 'screen' => 'return-view', 'entity' => 'return_authorization', 'recordId' => (string) $id]);

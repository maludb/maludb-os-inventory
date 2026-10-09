<?php
declare(strict_types=1);
/** /receipts/{id} — the receiving screen (screen `receipt-view`): a draft takes scans and lines; a posted one shows what it posted. stock.receive. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/activity/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/activity/present.php';
require_right('stock.receive');
$pdo = db();
$r = receipt_or_404($pdo, request_integer('id') ?? request_integer('receipt'));
$rid = (int) $r['goods_receipt_id'];
$lines = receipt_lines($pdo, $rid);
$movements = $r['status'] === 'posted' ? receipt_movements($pdo, $rid) : [];
$poLines = ($r['status'] === 'draft' && $r['purchase_order_id'] !== null) ? po_open_lines($pdo, (int) $r['purchase_order_id']) : [];
log_activity($pdo, 'screen.view', 'goods_receipt', $rid, ['screen' => 'receipt-view', 'location_id' => (int) $r['location_id'], 'purchase_order_id' => $r['purchase_order_id'] === null ? null : (int) $r['purchase_order_id'], 'after' => ['number' => $r['number']]]);
$seesCost = sees_receipt_cost();
$amount = 0.0;
foreach ($lines as $l) { $amount += (int) $l['qty'] * (float) ($l['unit_cost'] ?? 0); }
if (wants_json()) {
    respond_screen(['receipt' => present_receipt($r), 'lines' => array_map('present_receipt_line', $lines), 'movements' => array_map('present_movement', $movements), 'po_open_lines' => $poLines,
        'amount' => $seesCost ? number_format($amount, 2, '.', '') : null, 'cost_withheld' => !$seesCost, 'attachments' => record_attachments($pdo, 'goods_receipt', $rid)]);
}
render_screen($r['number'], view('receipts/view.php', ['r' => $r, 'lines' => $lines, 'movements' => $movements, 'poLines' => $poLines, 'amount' => $amount, 'seesCost' => $seesCost,
    'locations' => locations_for_pick($pdo), 'attachments' => record_attachments($pdo, 'goods_receipt', $rid), 'trail' => find_record_activity($pdo, 'goods_receipt', $rid, 20),
    'mayReverse' => has_right('stock.adjust'), 'tz' => member_timezone(), 'here' => here_url(), 'notice' => inv_notice($_GET['notice'] ?? null, STOCK_NOTICES)]),
    ['activeNav' => 'receipt-list', 'screen' => 'receipt-view', 'entity' => 'goods_receipt', 'recordId' => (string) $rid]);

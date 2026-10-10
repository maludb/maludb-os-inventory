<?php
declare(strict_types=1);
/**
 * Action `purchase_order_line_decline` (log `purchase_order.supplier_decline`, source web: number, line_id, reason; confirm): `line` (of a sent order) and `reason` (required, ≤ 500) — the supplier cannot fill it. The customer's line
 * goes back to open (at risk) and the order's salesperson is told (`line_at_risk`). purchasing.write. Location /purchasing/{id}#po-line-{line}.
 */
require_once dirname(__DIR__, 2) . '/app/features/purchasing/handler.php';
purchasing_write_begin('purchasing.write');
$pdo = db();
$line = find_po_line($pdo, request_integer('line') ?? request_integer('line_id') ?? 0) ?? refuse(404, 'Line not found.');
$o = po_or_404($pdo, $line['purchase_order_id']);
$reason = (string) (req_val('reason') ?? '');
if ($reason === '') { inv_refuse_fields(['reason' => 'Say why the supplier cannot fill it.']); }
if (mb_strlen($reason) > 500) { inv_refuse_fields(['reason' => 'The reason is up to 500 characters.']); }
try {
    $r = inv_guard($pdo, static function () use ($pdo, $o, $line, $reason): array {
        $pdo->beginTransaction();
        $r = decline_po_line($pdo, $line['purchase_order_line_id'], $reason, 'manual', (int) current_member_id());
        po_log($pdo, 'purchase_order.supplier_decline', $o, ['number' => $o['number'], 'line_id' => $line['purchase_order_line_id'], 'line_no' => $line['line_no'], 'reason' => $reason, 'salesperson_told' => $r['salesperson_told']]);
        $pdo->commit();
        return $r;
    });
} catch (Throwable $e) { po_refused($e); }
inv_done('Declined line ' . $line['line_no'] . ' of ' . $o['number'], $line['purchase_order_line_id'], inv_land('/purchasing/' . (int) $o['purchase_order_id'], 'declined', 'po-line-' . $line['purchase_order_line_id']), 'purchaseOrderChanged',
    ['purchase_order_id' => (int) $o['purchase_order_id'], 'line_id' => $line['purchase_order_line_id'], 'salesperson_told' => $r['salesperson_told']]);

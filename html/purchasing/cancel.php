<?php
declare(strict_types=1);
/**
 * Action `purchase_order_cancel` (log `purchase_order.cancel`: number, reason, kind, sales_order_id; confirm; `deletion` for agents): `reason` required. A draft, sent or acknowledged order with no goods received or shipped (the SQL says "close it
 * short instead"); the customer's drop-ship lines go back to open — free to draft again — and the supplier's link expires at once. A cancelled drop-ship tells the order's salesperson. A sent one is cancelled with the supplier by you. purchasing.write.
 */
require_once dirname(__DIR__, 2) . '/app/features/purchasing/handler.php';
purchasing_write_begin('purchasing.write');
$pdo = db();
$o = po_or_404($pdo, request_po_id());
$reason = (string) (req_val('reason') ?? '');
if ($reason === '') { inv_refuse_fields(['reason' => 'Say why it is cancelled.']); }
if (mb_strlen($reason) > 500) { inv_refuse_fields(['reason' => 'The reason is up to 500 characters.']); }
$r = inv_guard($pdo, static function () use ($pdo, $o, $reason): array {
    $pdo->beginTransaction();
    $r = cancel_purchase_order($pdo, (int) $o['purchase_order_id'], (int) current_member_id(), $reason);
    po_log($pdo, 'purchase_order.cancel', $o, ['number' => $o['number'], 'reason' => $reason, 'kind' => $o['kind'], 'sales_order_id' => $o['sales_order_id'], 'was' => $o['status'], 'lines_released' => $r['released'], 'salesperson_told' => $r['salesperson_told']]);
    $pdo->commit();
    return $r;
});
inv_done('Cancelled ' . $o['number'], (int) $o['purchase_order_id'], inv_land('/purchasing/' . (int) $o['purchase_order_id'], 'cancelled'), 'purchaseOrderChanged',
    ['purchase_order_id' => (int) $o['purchase_order_id'], 'status' => 'cancelled', 'lines_released' => $r['released'], 'salesperson_told' => $r['salesperson_told']]);

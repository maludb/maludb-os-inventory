<?php
declare(strict_types=1);
/**
 * Action `order_cancel` (log `order.cancel`: number, reason, released[], dropships_cancelled, dropships_to_cancel_by_hand; confirm; a `deletion` for agents): `reason` required. Allocations are released, the lines cancelled and the
 * DRAFT drop-ship purchase orders cancelled by the SQL; every drop-ship purchase order already sent is cancelled with the supplier by hand — the Buyer is told. An order with a shipped line is refused: take a return. orders.write.
 */
require_once dirname(__DIR__, 2) . '/app/features/orders/handler.php';
orders_write_begin('orders.write');
$pdo = db();
$o = order_or_404($pdo, request_integer('order') ?? request_integer('sales_order_id'));
$reason = trim((string) (req_val('reason') ?? ''));
if ($reason === '') { inv_refuse_fields(['reason' => 'Say why it is cancelled.']); }
if (mb_strlen($reason) > 500) { inv_refuse_fields(['reason' => 'The reason is up to 500 characters.']); }
$r = inv_guard($pdo, static function () use ($pdo, $o, $reason): array {
    $pdo->beginTransaction();
    $r = cancel_order($pdo, (int) $o['sales_order_id'], (int) current_member_id(), $reason);
    order_log($pdo, 'order.cancel', $o, ['number' => $o['number'], 'reason' => $reason, 'released' => $r['released'], 'dropships_cancelled' => $r['dropships_cancelled'], 'dropships_to_cancel_by_hand' => $r['dropships_to_cancel_by_hand']]);
    $pdo->commit();
    return $r;
});
inv_done('Cancelled ' . $o['number'], (int) $o['sales_order_id'], inv_land('/orders/' . (int) $o['sales_order_id'], 'cancelled'), 'orderChanged',
    ['sales_order_id' => (int) $o['sales_order_id'], 'status' => 'cancelled', 'released' => $r['released'], 'dropships_cancelled' => $r['dropships_cancelled'], 'dropships_to_cancel_by_hand' => $r['dropships_to_cancel_by_hand']]);

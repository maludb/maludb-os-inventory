<?php
declare(strict_types=1);
/** Action `order_close` (log `order.close`): a delivered order closes — and only when nothing is due ("SO-… has N due — record the payment or a refund first."); the customer's link then lives 180 days. orders.write. */
require_once dirname(__DIR__, 2) . '/app/features/orders/handler.php';
orders_write_begin('orders.write');
$pdo = db();
$o = order_or_404($pdo, request_integer('order') ?? request_integer('sales_order_id'));
if ($o['status'] === 'delivered' && (float) $o['balance_due'] > 0.004) {
    refuse(422, $o['number'] . ' has ' . money($o['balance_due']) . ' due — record the payment or a refund first.');
}
inv_guard($pdo, static function () use ($pdo, $o): void {
    $pdo->beginTransaction();
    $r = close_order($pdo, (int) $o['sales_order_id'], (int) current_member_id());
    order_log($pdo, 'order.close', $o, ['number' => $o['number'], 'closed_at' => $r['closed_at'] ?? null, 'total' => $o['total'], 'amount_paid' => $o['amount_paid']]);
    $pdo->commit();
});
inv_done('Closed ' . $o['number'], (int) $o['sales_order_id'], inv_land('/orders/' . (int) $o['sales_order_id'], 'closed'), 'orderChanged', ['sales_order_id' => (int) $o['sales_order_id'], 'status' => 'closed']);

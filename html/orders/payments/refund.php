<?php
declare(strict_types=1);
/**
 * Action `refund_record` (log `order.refund`: payment_id, number, method, amount, currency, reference, payment_status; confirm; `other` for agents): money given back, RECORDED. The amount may not exceed what was paid
 * ("Refund exceeds what was paid (N)."). A cancelled order takes a refund. payments.record.
 */
require_once dirname(__DIR__, 3) . '/app/features/orders/handler.php';
orders_write_begin('payments.record');
$pdo = db();
$o = order_or_404($pdo, request_integer('order') ?? request_integer('sales_order_id'));
$errors = [];
$f = order_payment_from_request($errors, 'refund');
if ($errors !== []) { inv_refuse_fields($errors); }
$pid = inv_guard($pdo, static function () use ($pdo, $o, $f): int {
    $pdo->beginTransaction();
    $pid = record_payment($pdo, (int) $o['sales_order_id'], $f, (int) current_member_id());
    $now = find_order($pdo, (int) $o['sales_order_id']);
    order_log($pdo, 'order.refund', $now, ['payment_id' => $pid, 'number' => $o['number'], 'method' => $f['method'], 'amount' => $f['amount'], 'currency' => (string) one_value($pdo, 'SELECT currency FROM mcp_settings'),
        'reference_given' => $f['reference'] !== null, 'payment_status' => $now['payment_status']]);
    $pdo->commit();
    return $pid;
});
$now = find_order($pdo, (int) $o['sales_order_id']);
inv_done('Recorded a refund of ' . money($f['amount']) . ' on ' . $o['number'], $pid, inv_land('/orders/' . (int) $o['sales_order_id'], 'refund', 'order-payments'), 'orderChanged',
    ['sales_order_id' => (int) $o['sales_order_id'], 'payment_id' => $pid, 'payment_status' => $now['payment_status'], 'amount_paid' => $now['amount_paid'], 'balance_due' => $now['balance_due']]);

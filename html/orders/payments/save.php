<?php
declare(strict_types=1);
/**
 * Action `payment_record` (log `order.payment`: payment_id, number, kind, method, amount, currency, reference, payment_status; confirm; `other` for agents): a deposit or a balance, RECORDED — never charged (no processor).
 * `amount` > 0, `method`, `reference` (the last four, a check number — kept, never logged beyond the fact), `taken_at`, `note`. The trigger derives the payment status; a cancelled order takes a refund only. payments.record.
 */
require_once dirname(__DIR__, 3) . '/app/features/orders/handler.php';
orders_write_begin('payments.record');
$pdo = db();
$o = order_or_404($pdo, request_integer('order') ?? request_integer('sales_order_id'));
$errors = [];
$kind = (string) (req_val('kind') ?? '');
if (!in_array($kind, ['deposit', 'balance'], true)) { $errors['kind'] = 'A payment is a deposit or a balance (a refund has its own action).'; }
$f = order_payment_from_request($errors, $kind);
if ($errors !== []) { inv_refuse_fields($errors); }
$pid = inv_guard($pdo, static function () use ($pdo, $o, $f): int {
    $pdo->beginTransaction();
    $pid = record_payment($pdo, (int) $o['sales_order_id'], $f, (int) current_member_id());
    $now = find_order($pdo, (int) $o['sales_order_id']);
    order_log($pdo, 'order.payment', $now, ['payment_id' => $pid, 'number' => $o['number'], 'kind' => $f['kind'], 'method' => $f['method'], 'amount' => $f['amount'], 'currency' => (string) one_value($pdo, 'SELECT currency FROM mcp_settings'),
        'reference_given' => $f['reference'] !== null, 'payment_status' => $now['payment_status']]);
    $pdo->commit();
    return $pid;
});
$now = find_order($pdo, (int) $o['sales_order_id']);
inv_done('Recorded ' . $f['kind'] . ' ' . money($f['amount']) . ' on ' . $o['number'], $pid, inv_land('/orders/' . (int) $o['sales_order_id'], 'payment', 'order-payments'), 'orderChanged',
    ['sales_order_id' => (int) $o['sales_order_id'], 'payment_id' => $pid, 'payment_status' => $now['payment_status'], 'amount_paid' => $now['amount_paid'], 'balance_due' => $now['balance_due']]);

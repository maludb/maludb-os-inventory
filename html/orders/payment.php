<?php
declare(strict_types=1);
/** GET /orders/{id}/payment?kind= — screen `order-payment`: record a deposit, a balance or a refund (the actions are payments/save.php and payments/refund.php). payments.record. */
require_once dirname(__DIR__, 2) . '/app/features/orders/handler.php';
require_right('payments.record');
$pdo = db();
$o = order_or_404($pdo, request_integer('id') ?? request_integer('order'));
$kind = request_string('kind', (float) $o['amount_paid'] > 0 && (float) $o['balance_due'] <= 0 ? 'refund' : ((float) $o['amount_paid'] > 0 ? 'balance' : 'deposit'));
if (!in_array($kind, ['deposit', 'balance', 'refund'], true)) { $kind = 'deposit'; }
$amount = request_string('amount');                                    // a refund recorded on a return arrives with its amount (returns-worker.md DECISION 4)
$amount = preg_match('/^\d{1,8}(\.\d{1,2})?$/', $amount) === 1 && (float) $amount > 0 ? $amount : null;
log_screen_view($pdo, 'order-payment');
if (wants_json()) { respond_screen(['order' => present_order_row($o), 'kind' => $kind, 'methods' => PAYMENT_METHODS, 'payments' => array_map(static fn (array $p): array => ['kind' => $p['kind'], 'amount' => $p['amount'], 'method' => $p['method'], 'taken_at' => json_ts($p['taken_at'])], $o['payments'])]); }
render_screen('Payment for ' . $o['number'], view('orders/payment.php', ['o' => $o, 'kind' => $kind, 'amount' => $amount, 'here' => here_url()]),
    ['activeNav' => 'order-list', 'screen' => 'order-payment', 'entity' => 'sales_order', 'recordId' => (string) $o['sales_order_id']]);

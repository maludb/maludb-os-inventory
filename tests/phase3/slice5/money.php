<?php
/** Money (orders.md "Proof" ≈ 20): a deposit, the balance, refunds and their ceiling, a cancelled order, the walls, the panel, the log. Payments are RECORDS — nothing is charged. */
require __DIR__ . '/lib.php';
$w = order_world();
$sam = $w['sam'];
$main = ref_order('S5-MAIN');
$since = last_activity_id();

// ---- a deposit on the main order
$total = (float) order_row($main)['total'];
[$c, $b] = act($sam, '/orders/payments/save.php', ['order' => $main, 'kind' => 'deposit', 'amount' => '1,000', 'method' => 'card', 'reference' => '4242']);
$o = order_row($main);
ok($c === 200 && $o['payment_status'] === 'deposit' && (float) $o['amount_paid'] === 1000.0 && $b['payment_status'] === 'deposit' && (float) $b['balance_due'] === round($total - 1000, 2), 'a deposit of 1,000 by card: payment_status deposit, amount_paid 1000.00, the balance in the answer');
$p = q('SELECT * FROM order_payments WHERE sales_order_id = :o ORDER BY id DESC LIMIT 1', ['o' => $main])[0];
ok((int) $p['taken_by'] === 41 && $p['method'] === 'card' && $p['reference'] === '4242' && $p['kind'] === 'deposit', 'taken_by Sam; the method and the reference (the last four) are kept');
$log = last_log('order.payment', $since);
$a = after_of($log);
ok($log !== null && (int) $log['sales_order_id'] === $main && $a['amount'] === '1000.00' && $a['currency'] === 'USD' && $a['method'] === 'card' && $a['payment_status'] === 'deposit' && !str_contains((string) $log['after'], '4242'), 'order.payment is logged with the amount, currency, method and status — the reference only as a fact');
ok(str_ends_with((string) $b['location'], '#order-payments'), 'the location lands on the payments panel');

// ---- a smaller order for the rest: the balance, refunds
[$c, $b] = make_quote($sam, $w['alvarez'], [['variant' => $w['pillow_v'], 'qty' => 1, 'fulfilment_kind' => 'backorder', 'location' => $w['wh']]], ['customer_reference' => 'S5-MONEY']);
$m = (int) $b['record_id'];
act($sam, '/orders/confirm.php', ['order' => $m]);
$mt = (float) order_row($m)['total'];
ok(abs($mt - 65.05) < 0.005, 'the small order: one pillow at 59.00 and 10.25 % tax = 65.05');
[$c, $b] = act($sam, '/orders/payments/save.php', ['order' => $m, 'kind' => 'deposit', 'amount' => '20', 'method' => 'cash']);
ok($c === 200 && order_row($m)['payment_status'] === 'deposit', 'a cash deposit of 20.00: deposit');
[$c, $b] = act($sam, '/orders/payments/save.php', ['order' => $m, 'kind' => 'balance', 'amount' => number_format($mt - 20, 2, '.', ''), 'method' => 'check', 'reference' => '1042']);
$o = order_row($m);
ok($c === 200 && $o['payment_status'] === 'paid' && (float) $o['amount_paid'] === $mt, 'a balance to the total: paid');
[$c, $b] = act($sam, '/orders/payments/refund.php', ['order' => $m, 'amount' => '5000', 'method' => 'card']);
ok($c === 422 && msg($b) === 'Refund exceeds what was paid (' . number_format($mt, 2) . ').', 'a refund over what was paid: 422 in words with the figure');
$since = last_activity_id();
[$c, $b] = act($sam, '/orders/payments/refund.php', ['order' => $m, 'amount' => '20', 'method' => 'cash', 'note' => 'a gesture']);
$o = order_row($m);
ok($c === 200 && $o['payment_status'] === 'partial_refund' && (float) $o['amount_paid'] === round($mt - 20, 2), 'a refund of 20.00: partial_refund, amount_paid reduced');
$log = last_log('order.refund', $since);
ok($log !== null && after_of($log)['amount'] === '20.00' && after_of($log)['currency'] === 'USD', 'order.refund is logged with amount and currency');
[$c, $b] = act($sam, '/orders/payments/refund.php', ['order' => $m, 'amount' => number_format($mt - 20, 2, '.', ''), 'method' => 'card']);
ok($c === 200 && order_row($m)['payment_status'] === 'refunded' && (float) order_row($m)['amount_paid'] === 0.0, 'refunding the rest: refunded, amount_paid 0.00');

// ---- a cancelled order takes a refund, not a deposit
[$c, $b] = make_quote($sam, $w['alvarez'], [['variant' => $w['pillow_v'], 'qty' => 1, 'fulfilment_kind' => 'backorder', 'location' => $w['wh']]], ['customer_reference' => 'S5-CXL']);
$x = (int) $b['record_id'];
act($sam, '/orders/payments/save.php', ['order' => $x, 'kind' => 'deposit', 'amount' => '30', 'method' => 'cash']);
act($sam, '/orders/cancel.php', ['order' => $x, 'reason' => 'SMOKE: changed their mind']);
[$c, $b] = act($sam, '/orders/payments/save.php', ['order' => $x, 'kind' => 'deposit', 'amount' => '10', 'method' => 'cash']);
ok($c === 422 && str_contains(msg($b), 'Only a refund is recorded on a cancelled order'), 'a deposit on a cancelled order: the trigger\'s sentence as a 422');
[$c, $b] = act($sam, '/orders/payments/refund.php', ['order' => $x, 'amount' => '30', 'method' => 'cash']);
ok($c === 200 && order_row($x)['payment_status'] === 'refunded', 'a refund on a cancelled order is allowed');

// ---- the field errors and the walls
[$c, $b] = act($sam, '/orders/payments/save.php', ['order' => $m, 'kind' => 'deposit', 'amount' => '0', 'method' => 'cash']);
ok($c === 422 && isset(fields($b)['amount']), 'an amount of 0: 422 with the field error');
[$c, $b] = act($sam, '/orders/payments/save.php', ['order' => $m, 'kind' => 'deposit', 'amount' => '10', 'method' => 'bitcoin']);
ok($c === 422 && isset(fields($b)['method']), 'an unknown method: 422 with the field error');
[$c, $b] = act($sam, '/orders/payments/save.php', ['order' => $m, 'kind' => 'refund', 'amount' => '10', 'method' => 'cash']);
ok($c === 422 && isset(fields($b)['kind']), 'a refund through payment_record: 422 (it has its own action)');
[$c, $b] = act($w['vera'], '/orders/payments/save.php', ['order' => $m, 'kind' => 'deposit', 'amount' => '10', 'method' => 'cash']);
ok($c === 403 && str_contains(msg($b), 'record a payment'), 'Vera: 403 in words');
[$c, $b] = act($w['wes'], '/orders/payments/refund.php', ['order' => $m, 'amount' => '10', 'method' => 'cash']);
ok($c === 403, 'Wes: 403');

// ---- the panel and the balance
$rs = req('GET', "/orders/$main", ['jar' => $sam]);
$rn = req('GET', "/orders/$main", ['jar' => $w['nora']]);
$rw = req('GET', "/orders/$main", ['jar' => $w['wes']]);
ok(str_contains($rs['body'], 'id="order-payments"') && str_contains($rn['body'], 'id="order-payments"') && !str_contains($rw['body'], 'id="order-payments"'), 'the payments panel shows for Sam and Nora, and is absent for Wes');
ok(str_contains($rs['body'], 'id="order-payment-' . $p['id'] . '"') && str_contains($rs['body'], 'id="order-view-balance"') && str_contains(preg_replace('/\s+/', ' ', strip_tags($rs['body'])), number_format($total - 1000, 2)), 'the panel lists the deposit; the balance due is on the page');
[$c, $d] = screen($sam, "/orders/$main");
[$c2, $dw] = screen($w['wes'], "/orders/$main");
ok(count($d['order']['payments']) === 1 && (float) $d['order']['balance_due'] === round($total - 1000, 2) && $dw['order']['payments'] === [], 'in JSON: the payments for Sam (one), none for Wes; the balance due is the same for both');
$r = req('GET', "/orders/$main/payment?kind=balance", ['jar' => $sam]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="order-payment-form"') && str_contains($r['body'], 'value="' . number_format($total - 1000, 2, '.', '') . '"') && req('GET', "/orders/$main/payment", ['jar' => $w['vera']])['code'] === 403, 'the payment screen offers the balance as the amount; Vera is refused it');
finish();

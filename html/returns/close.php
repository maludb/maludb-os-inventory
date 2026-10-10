<?php
declare(strict_types=1);
/**
 * Action `return_close` (log `return.close`: number, refund_amount, restocking_fee, currency; confirm): returns.write. A received return closes with the refund and the restocking fee RECORDED (two decimals, 0 or
 * more; the refund no more than the order was paid). Nothing is paid here: the money is a refund recorded on the order, which the return's page links with the amount filled in (DECISION 4). The requester is told.
 * Location /returns/{id}; refresh returnChanged.
 */
require_once dirname(__DIR__, 2) . '/app/features/returns/handler.php';
returns_write_begin('returns.write');
$pdo = db();
$r = return_or_404($pdo, request_return_id($pdo));
$errors = [];
$money = static function (string $name, string $label) use (&$errors): ?float {
    if (!req_has($name) || (string) req_val($name) === '') { return null; }
    $n = preg_replace('/[^0-9.\-]/', '', (string) req_val($name));
    if (!is_numeric($n) || (float) $n < 0 || (float) $n > 99999999 || round((float) $n, 2) !== (float) $n) { $errors[$name] = $label . ' is an amount of 0 or more, in cents.'; return null; }
    return (float) $n;
};
$refund = $money('refund_amount', 'The refund');
$fee = $money('restocking_fee', 'The restocking fee');
if ($refund !== null && !isset($errors['refund_amount']) && $refund > (float) ($r['amount_paid'] ?? 0) + 0.004) {
    $errors['refund_amount'] = 'The refund cannot exceed what was paid (' . money($r['amount_paid']) . ').';
}
if ($errors !== []) { inv_refuse_fields($errors); }
$cur = (string) (one_value($pdo, 'SELECT currency FROM mcp_settings') ?? 'USD');
$now = inv_guard($pdo, static function () use ($pdo, $r, $refund, $fee, $cur): array {
    $pdo->beginTransaction();
    close_return($pdo, (int) $r['return_id'], (int) current_member_id(), $refund, $fee);
    $now = find_return($pdo, (int) $r['return_id']);
    return_log($pdo, 'return.close', $r, ['number' => $r['number'], 'status' => 'closed', 'refund_amount' => $now['refund_amount'], 'restocking_fee' => $now['restocking_fee'], 'currency' => $cur]);
    if ($r['requested_by'] !== null) {
        notify($pdo, (int) $r['requested_by'], 'return', 'return', (int) $r['return_id'], 'Return ' . $r['number'] . ' closed — refund ' . money($now['refund_amount']) . ' to record', null, 'return:' . $r['return_id'] . ':closed');
    }
    $pdo->commit();
    return $now;
});
inv_done('Closed ' . $r['number'], (int) $r['return_id'], inv_land('/returns/' . (int) $r['return_id'], 'closed'), 'returnChanged',
    ['return_id' => (int) $r['return_id'], 'number' => $r['number'], 'status' => 'closed', 'refund_amount' => $now['refund_amount'], 'restocking_fee' => $now['restocking_fee']]);

<?php
declare(strict_types=1);
/**
 * Action `return_deny` (log `return.deny`: number, deny_reason; confirm): returns.write. `reason` is required (1–500); a requested or approved return is denied. The requester is told, the reason in the body.
 * Location /returns/{id}; refresh returnChanged.
 */
require_once dirname(__DIR__, 2) . '/app/features/returns/handler.php';
returns_write_begin('returns.write');
$pdo = db();
$r = return_or_404($pdo, request_return_id($pdo));
$reason = trim((string) (req_val('reason') ?? ''));
if ($reason === '') { inv_refuse_fields(['reason' => 'Say why the return is denied.']); }
if (mb_strlen($reason) > 500) { inv_refuse_fields(['reason' => 'The reason is up to 500 characters.']); }
inv_guard($pdo, static function () use ($pdo, $r, $reason): void {
    $pdo->beginTransaction();
    deny_return($pdo, (int) $r['return_id'], (int) current_member_id(), $reason);
    return_log($pdo, 'return.deny', $r, ['number' => $r['number'], 'status' => 'denied', 'deny_reason' => $reason]);
    if ($r['requested_by'] !== null) { notify($pdo, (int) $r['requested_by'], 'return', 'return', (int) $r['return_id'], 'Return ' . $r['number'] . ' denied', $reason, 'return:' . $r['return_id'] . ':denied'); }
    $pdo->commit();
});
inv_done('Denied ' . $r['number'], (int) $r['return_id'], inv_land('/returns/' . (int) $r['return_id'], 'denied'), 'returnChanged', ['return_id' => (int) $r['return_id'], 'number' => $r['number'], 'status' => 'denied']);

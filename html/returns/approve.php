<?php
declare(strict_types=1);
/**
 * Action `return_authorize` (log `return.approve`: number, status, lines; confirm; an agent's pauses as `other`): returns.write. A requested return is approved — the database says "has no lines" and
 * "a requested return is approved". The requester is told (Return RA-… approved — pickup on Oct 12). Location /returns/{id}; refresh returnChanged.
 */
require_once dirname(__DIR__, 2) . '/app/features/returns/handler.php';
returns_write_begin('returns.write');
$pdo = db();
$r = return_or_404($pdo, request_return_id($pdo));
inv_guard($pdo, static function () use ($pdo, $r): void {
    $pdo->beginTransaction();
    approve_return($pdo, (int) $r['return_id'], (int) current_member_id());
    $now = find_return($pdo, (int) $r['return_id']);
    return_log($pdo, 'return.approve', $r, ['number' => $r['number'], 'status' => $now['status'], 'lines' => $now['line_count']]);
    if ($r['requested_by'] !== null) { notify($pdo, (int) $r['requested_by'], 'return', 'return', (int) $r['return_id'], 'Return ' . $r['number'] . ' approved — ' . return_when_words($r), null, 'return:' . $r['return_id'] . ':approved'); }
    $pdo->commit();
});
inv_done('Approved ' . $r['number'], (int) $r['return_id'], inv_land('/returns/' . (int) $r['return_id'], 'approved'), 'returnChanged', ['return_id' => (int) $r['return_id'], 'number' => $r['number'], 'status' => 'approved']);

<?php
declare(strict_types=1);
/**
 * Action `return_line_remove` (log `return.line_remove`: return_line_id, sku, qty, reason_code, disposition): orders.write. `return_line` of a requested or approved return — the database's sentence otherwise.
 * Location /returns/{id}; refresh returnChanged.
 */
require_once dirname(__DIR__, 3) . '/app/features/returns/handler.php';
returns_write_begin('orders.write');
$pdo = db();
$l = find_return_line($pdo, request_integer('return_line') ?? 0);
if ($l === null) { refuse(404, 'Return line not found.'); }
$r = return_or_404($pdo, $l['return_id']);
inv_guard($pdo, static function () use ($pdo, $r, $l): void {
    $pdo->beginTransaction();
    return_line_try($pdo, (int) $l['sales_order_line_id'], static function () use ($pdo, $l): void { remove_return_line($pdo, (int) $l['return_line_id']); });
    return_log($pdo, 'return.line_remove', $r, ['return_line_id' => (int) $l['return_line_id'], 'sku' => $l['sku'], 'qty' => $l['qty'], 'reason_code' => $l['reason_code'], 'disposition' => $l['disposition']]);
    $pdo->commit();
});
inv_done('Removed ' . $l['sku'] . ' from ' . $r['number'], (int) $l['return_line_id'], inv_land('/returns/' . (int) $r['return_id'], 'line_removed'), 'returnChanged', ['return_id' => (int) $r['return_id'], 'return_line_id' => (int) $l['return_line_id']]);

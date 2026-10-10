<?php
declare(strict_types=1);
/** Action `purchase_order_close` (log `purchase_order.close`: number, status closed / closed_short, lines_short; confirm): a sent, acknowledged, partly received or received order closes — its open lines close short and the supplier's link lives 90 days more. purchasing.write. */
require_once dirname(__DIR__, 2) . '/app/features/purchasing/handler.php';
purchasing_write_begin('purchasing.write');
$pdo = db();
$o = po_or_404($pdo, request_po_id());
$r = inv_guard($pdo, static function () use ($pdo, $o): array {
    $pdo->beginTransaction();
    $r = close_purchase_order($pdo, (int) $o['purchase_order_id'], (int) current_member_id());
    po_log($pdo, 'purchase_order.close', $o, ['number' => $o['number'], 'status' => $r['status'], 'lines_short' => $r['lines_short']]);
    $pdo->commit();
    return $r;
});
inv_done('Closed ' . $o['number'], (int) $o['purchase_order_id'], inv_land('/purchasing/' . (int) $o['purchase_order_id'], 'closed'), 'purchaseOrderChanged',
    ['purchase_order_id' => (int) $o['purchase_order_id'], 'status' => $r['status'], 'lines_short' => $r['lines_short']]);

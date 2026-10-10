<?php
declare(strict_types=1);
/**
 * Action `order_line_cancel` (log `order.line_cancel`: line_id, sku, qty, status_before, released; confirm): a line of a quote or a confirmed order — its allocation is released (inv_order_line_cancel(), a shipped line
 * is refused: take a return instead). A line `ordered` on a sent drop-ship purchase order tells the Buyer to cancel that purchase order line. orders.write.
 */
require_once dirname(__DIR__, 3) . '/app/features/orders/handler.php';
orders_write_begin('orders.write');
$pdo = db();
$line = find_order_line($pdo, request_integer('line') ?? request_integer('line_id') ?? 0) ?? refuse(404, 'Line not found.');
$o = order_or_404($pdo, $line['sales_order_id']);
if (in_array($o['status'], ['closed', 'cancelled'], true) || $line['status'] === 'cancelled') { refuse(422, $line['status'] === 'cancelled' ? 'Line ' . $line['line_no'] . ' is already cancelled.' : $o['number'] . ' is ' . $o['status'] . '.'); }
$r = inv_guard($pdo, static function () use ($pdo, $o, $line): array {
    $pdo->beginTransaction();
    $r = cancel_order_line($pdo, $line['line_id'], (int) current_member_id());
    $told = false;
    if ($r['was_ordered'] && $r['purchase_order_line_id'] !== null) {
        $po = one_row($pdo, 'SELECT po.id, po.number, s.name AS supplier FROM purchase_order_lines pl JOIN purchase_orders po ON po.id = pl.purchase_order_id JOIN suppliers s ON s.id = po.supplier_id WHERE pl.id = :id', ['id' => $r['purchase_order_line_id']]);
        if ($po !== null) {
            $told = notify_buyer($pdo, 'order', 'sales_order', (int) $o['sales_order_id'], 'Line ' . $line['line_no'] . ' of ' . $o['number'] . ' was cancelled — cancel ' . $po['number'] . ' line with ' . $po['supplier']) !== null;
        }
    }
    order_log($pdo, 'order.line_cancel', $o, ['line_id' => $line['line_id'], 'sku' => $line['sku'], 'qty' => $line['qty'], 'status_before' => $r['status_before'], 'released' => $r['released'], 'buyer_told' => $told]);
    $pdo->commit();
    return $r;
});
$oid = (int) $o['sales_order_id'];
if (is_htmx_request() && !wants_json() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'order-lines') {
    emit_action_status(true, ['did' => 'Cancelled the line', 'record_id' => $line['line_id']]);
    hx_trigger('orderChanged');
    echo orders_lines_fragment($pdo, $oid);
    exit;
}
inv_done('Cancelled line ' . $line['line_no'] . ' of ' . $o['number'], $line['line_id'], inv_land(return_path('/orders/' . $oid), 'line_cancelled'), 'orderChanged', ['sales_order_id' => $oid, 'line_id' => $line['line_id'], 'released' => $r['released']]);

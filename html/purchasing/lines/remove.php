<?php
declare(strict_types=1);
/** Action `purchase_order_line_remove` (log `purchase_order.line_remove`: line_id, line_no, sku, supplier_sku, qty_ordered, unit_cost): a line of a DRAFT purchase order; a drop-ship's line is the customer's and stays. An HTMX caller targeting #po-lines gets the region re-rendered. purchasing.write. */
require_once dirname(__DIR__, 3) . '/app/features/purchasing/handler.php';
purchasing_write_begin('purchasing.write');
$pdo = db();
$line = find_po_line($pdo, request_integer('line') ?? request_integer('line_id') ?? 0) ?? refuse(404, 'Line not found.');
$o = po_or_404($pdo, $line['purchase_order_id']);
if ($o['kind'] === 'dropship') { refuse(422, "A drop-ship's lines are the customer's — cancel the purchase order instead."); }
try {
    inv_guard($pdo, static function () use ($pdo, $o, $line): void {
        $pdo->beginTransaction();
        $fact = po_line_loggable($pdo, $line['purchase_order_line_id']);
        remove_po_line($pdo, $line['purchase_order_line_id']);
        po_log($pdo, 'purchase_order.line_remove', $o, $fact);
        $pdo->commit();
    });
} catch (Throwable $e) { po_refused($e); }
$poId = (int) $o['purchase_order_id'];
if (is_htmx_request() && !wants_json() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'po-lines') {
    emit_action_status(true, ['did' => 'Removed the line', 'record_id' => $line['purchase_order_line_id']]);
    hx_trigger('purchaseOrderChanged');
    echo po_lines_fragment($pdo, $poId);
    exit;
}
inv_done('Removed line ' . $line['line_no'] . ' of ' . $o['number'], $line['purchase_order_line_id'], inv_land(return_path('/purchasing/' . $poId . '/edit'), 'line_removed'), 'purchaseOrderChanged',
    ['purchase_order_id' => $poId, 'line_id' => $line['purchase_order_line_id']]);

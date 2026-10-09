<?php
declare(strict_types=1);
/**
 * Action `receipt_line_add` (log `stock.receipt_line`: line_id, sku, qty, unit_cost, discrepancy_kind, incremented): a line of a draft receipt — the
 * variant from the picker or its `barcode` as scanned (a scan of a variant already on the draft adds one to its line); `line` to change one. A Pattern C
 * request (HX-Target receipt-line-row-{id}) answers the row. stock.receive. Location /receipts/{receipt}#receipt-line-row-{line}.
 */
require_once dirname(dirname(__DIR__), 2) . '/app/features/stock/handler.php';
stock_write_begin('stock.receive');
$pdo = db();
$r = receipt_or_404($pdo, request_integer('receipt') ?? request_integer('goods_receipt_id'));
require_draft($r, 'receipt');
$rid = (int) $r['goods_receipt_id'];
$lineId = request_integer('line');
$cur = $lineId === null ? null : stock_line_of($pdo, 'mcp_goods_receipt_lines', 'goods_receipt_line_id', 'goods_receipt_id', $rid, $lineId);
$errors = [];
$f = receipt_line_from_request($pdo, $r, $cur, $errors);
if ($errors !== []) { inv_refuse_fields($errors); }
$sku = (string) one_value($pdo, 'SELECT sku FROM mcp_product_variants WHERE variant_id = :id', ['id' => $f['variant_id']]);
[$newLine, $inc] = inv_guard($pdo, static function () use ($pdo, $r, $rid, $lineId, $f, $sku): array {
    $pdo->beginTransaction();
    $inc = false;
    $newLine = save_receipt_line($pdo, $rid, $lineId, $f, $inc);
    $qty = (int) one_value($pdo, 'SELECT qty FROM goods_receipt_lines WHERE id = :id', ['id' => $newLine]);
    stock_log($pdo, 'stock.receipt_line', 'goods_receipt', $rid, (int) $r['location_id'], ['number' => $r['number'], 'line_id' => $newLine, 'sku' => $sku, 'qty' => $qty, 'unit_cost' => $f['unit_cost'] ?? null,
        'discrepancy_kind' => $f['discrepancy_kind'], 'incremented' => $inc, 'scanned' => $f['scanned'], 'changed' => $lineId !== null], ['purchase_order_id' => $r['purchase_order_id'] === null ? null : (int) $r['purchase_order_id']]);
    $pdo->commit();
    return [$newLine, $inc];
});
if (is_htmx_request() && !wants_json() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'receipt-line-row-' . $newLine) {
    emit_action_status(true, ['did' => 'Saved line', 'record_id' => $newLine]);
    hx_trigger('stockChanged');
    foreach (receipt_lines($pdo, $rid) as $l) {
        if ((int) $l['goods_receipt_line_id'] === $newLine) { echo view('receipts/partials/line-row.php', ['l' => $l, 'r' => find_receipt($pdo, $rid), 'seesCost' => sees_receipt_cost(), 'locations' => locations_for_pick($pdo), 'here' => '/receipts/' . $rid]); }
    }
    exit;
}
inv_done(($inc ? 'One more ' : 'Added ') . $sku . ($inc ? '' : ' to ' . $r['number']), $newLine, inv_land('/receipts/' . $rid, 'line_added', $f['scanned'] ? 'receipt-scan-form' : 'receipt-line-row-' . $newLine), 'stockChanged', ['goods_receipt_id' => $rid, 'line_id' => $newLine, 'incremented' => $inc]);

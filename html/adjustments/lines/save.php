<?php
declare(strict_types=1);
/** Action `adjustment_line_add` (log `stock.adjustment_line`: line_id, sku, qty_delta, unit_cost): a draft's line — variant (or its barcode), qty_delta signed and never zero, unit_cost only for a caller who sees cost, note; `line` to change one (Pattern C answers the row). stock.adjust. */
require_once dirname(dirname(__DIR__), 2) . '/app/features/stock/handler.php';
stock_write_begin('stock.adjust');
$pdo = db();
$a = adjustment_or_404($pdo, request_integer('adjustment') ?? request_integer('adjustment_id'));
require_draft($a, 'adjustment');
$aid = (int) $a['adjustment_id'];
$lineId = request_integer('line');
$cur = $lineId === null ? null : stock_line_of($pdo, 'mcp_inventory_adjustment_lines', 'adjustment_line_id', 'adjustment_id', $aid, $lineId);
$errors = [];
$f = adjustment_line_from_request($pdo, $cur, $errors);
if ($errors !== []) { inv_refuse_fields($errors); }
$sku = (string) one_value($pdo, 'SELECT sku FROM mcp_product_variants WHERE variant_id = :id', ['id' => $f['variant_id']]);
$newLine = inv_guard($pdo, static function () use ($pdo, $a, $aid, $lineId, $f, $sku): int {
    $pdo->beginTransaction();
    $newLine = save_adjustment_line($pdo, $aid, $lineId, $f);
    stock_log($pdo, 'stock.adjustment_line', 'inventory_adjustment', $aid, (int) $a['location_id'], ['number' => $a['number'], 'line_id' => $newLine, 'sku' => $sku, 'qty_delta' => $f['qty_delta'], 'unit_cost' => $f['unit_cost'] ?? null, 'changed' => $lineId !== null]);
    $pdo->commit();
    return $newLine;
});
if (is_htmx_request() && !wants_json() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'adjustment-line-row-' . $newLine) {
    emit_action_status(true, ['did' => 'Saved line', 'record_id' => $newLine]);
    hx_trigger('stockChanged');
    foreach (adjustment_lines($pdo, $aid) as $l) {
        if ((int) $l['adjustment_line_id'] === $newLine) { echo view('adjustments/partials/line-row.php', ['l' => $l, 'a' => find_adjustment($pdo, $aid), 'seesCost' => sees_cost(), 'here' => '/adjustments/' . $aid]); }
    }
    exit;
}
inv_done('Added ' . $sku . ' to ' . $a['number'], $newLine, inv_land('/adjustments/' . $aid, 'line_added', 'adjustment-line-row-' . $newLine), 'stockChanged', ['adjustment_id' => $aid, 'line_id' => $newLine]);

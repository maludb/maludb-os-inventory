<?php
declare(strict_types=1);
/** Action `transfer_line_add` (log `stock.transfer_line`: line_id, sku, qty, incremented): a draft's line — the variant or its barcode (a scan of a variant already on the draft adds one), qty; `line` to change one (Pattern C answers the row). stock.transfer. */
require_once dirname(dirname(__DIR__), 2) . '/app/features/stock/handler.php';
stock_write_begin('stock.transfer');
$pdo = db();
$t = transfer_or_404($pdo, request_integer('transfer') ?? request_integer('transfer_id'));
require_draft($t, 'transfer');
$tid = (int) $t['transfer_id'];
$lineId = request_integer('line');
$cur = $lineId === null ? null : stock_line_of($pdo, 'mcp_inventory_transfer_lines', 'transfer_line_id', 'transfer_id', $tid, $lineId);
$errors = [];
$f = transfer_line_from_request($pdo, $cur, $errors);
if ($errors !== []) { inv_refuse_fields($errors); }
$sku = (string) one_value($pdo, 'SELECT sku FROM mcp_product_variants WHERE variant_id = :id', ['id' => $f['variant_id']]);
[$newLine, $inc] = inv_guard($pdo, static function () use ($pdo, $t, $tid, $lineId, $f, $sku): array {
    $pdo->beginTransaction();
    $inc = false;
    $newLine = save_transfer_line($pdo, $tid, $lineId, $f, $inc);
    $qty = (int) one_value($pdo, 'SELECT qty FROM inventory_transfer_lines WHERE id = :id', ['id' => $newLine]);
    stock_log($pdo, 'stock.transfer_line', 'inventory_transfer', $tid, (int) $t['from_location_id'], ['number' => $t['number'], 'line_id' => $newLine, 'sku' => $sku, 'qty' => $qty, 'incremented' => $inc, 'scanned' => $f['scanned'], 'changed' => $lineId !== null]);
    $pdo->commit();
    return [$newLine, $inc];
});
if (is_htmx_request() && !wants_json() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'transfer-line-row-' . $newLine) {
    emit_action_status(true, ['did' => 'Saved line', 'record_id' => $newLine]);
    hx_trigger('stockChanged');
    foreach (transfer_lines($pdo, $tid) as $l) {
        if ((int) $l['transfer_line_id'] === $newLine) { echo view('transfers/partials/line-row.php', ['l' => $l, 't' => find_transfer($pdo, $tid), 'here' => '/transfers/' . $tid]); }
    }
    exit;
}
inv_done(($inc ? 'One more ' : 'Added ') . $sku . ($inc ? '' : ' to ' . $t['number']), $newLine, inv_land('/transfers/' . $tid, 'line_added', $f['scanned'] ? 'transfer-scan-form' : 'transfer-line-row-' . $newLine), 'stockChanged', ['transfer_id' => $tid, 'line_id' => $newLine, 'incremented' => $inc]);

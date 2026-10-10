<?php
declare(strict_types=1);
/**
 * Actions `purchase_order_line_add` (with `purchase_order`, a draft) and `purchase_order_line_update` (with `line`, a line of a draft; any field of purchase_order_line_add — a field left out stays): variant (an id, a SKU
 * or a code), qty, unit_cost (the price sheet's or the offer's when empty), supplier_sku, listing_variant (the offer it is ordered against), expected_on. Logs `purchase_order.line_add` / `purchase_order.line_update`.
 * A drop-ship line may change its cost and expected date but not its variant or quantity — the customer's line fixes those. An HTMX caller targeting #po-lines gets the region re-rendered. purchasing.write.
 */
require_once dirname(__DIR__, 3) . '/app/features/purchasing/handler.php';
purchasing_write_begin('purchasing.write');
$pdo = db();
$lineId = request_integer('line') ?? request_integer('line_id');
$curLine = $lineId === null ? null : (find_po_line($pdo, $lineId) ?? refuse(404, 'Line not found.'));
$o = po_or_404($pdo, $curLine !== null ? $curLine['purchase_order_id'] : request_po_id());
$errors = [];
$row = $_POST;
if ($curLine !== null) {                                       // a field left out stays as it was (the cost from the BASE row: the view hides it from a caller who does not see cost)
    $baseCost = one_value($pdo, 'SELECT unit_cost FROM purchase_order_lines WHERE id = :id', ['id' => $curLine['purchase_order_line_id']]);
    $keep = ['variant' => (string) $curLine['variant_id'], 'qty' => (string) $curLine['qty_ordered'], 'unit_cost' => (string) $baseCost, 'supplier_sku' => (string) ($curLine['supplier_sku'] ?? ''),
             'listing_variant' => (string) ($curLine['listing_variant_id'] ?? ''), 'expected_on' => (string) ($curLine['expected_on'] ?? '')];
    foreach ($keep as $k => $v) { if (!array_key_exists($k, $row) || (trim((string) $row[$k]) === '' && in_array($k, ['variant', 'qty', 'unit_cost'], true))) { $row[$k] = $v; } }
    if ($o['kind'] === 'dropship') {
        $nv = line_variant($pdo, (string) $row['variant']);
        if ($nv === null || (int) $nv['variant_id'] !== (int) $curLine['variant_id'] || (int) $row['qty'] !== (int) $curLine['qty_ordered']) { refuse(422, "A drop-ship line's variant and quantity are the customer's."); }
    }
} elseif ($o['kind'] === 'dropship') {
    refuse(422, "A drop-ship's lines are the customer's — they are drafted from the order.");
}
$cols = po_line_columns($pdo, (int) $o['supplier_id'], array_map(static fn ($v) => is_scalar($v) || $v === null ? trim((string) $v) : $v, $row), 'line', $errors);
if ($curLine === null && trim((string) ($row['variant'] ?? '')) === '' && trim((string) ($row['variant_code'] ?? $row['sku'] ?? '')) === '') { $errors = ['line.variant' => 'Choose a variant.']; }
if ($errors !== []) { inv_refuse_fields(array_combine(array_map(static fn ($k) => substr((string) $k, strpos((string) $k, '.') + 1), array_keys($errors)), array_values($errors))); }
try {
    $lid = inv_guard($pdo, static function () use ($pdo, $o, $curLine, $cols): int {
        $pdo->beginTransaction();
        $lid = save_po_line($pdo, (int) $o['purchase_order_id'], $curLine['purchase_order_line_id'] ?? null, $cols);
        po_log($pdo, $curLine === null ? 'purchase_order.line_add' : 'purchase_order.line_update', $o, po_line_loggable($pdo, $lid));
        $pdo->commit();
        return $lid;
    });
} catch (Throwable $e) { po_refused($e); }
$poId = (int) $o['purchase_order_id'];
if (is_htmx_request() && !wants_json() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'po-lines') {
    emit_action_status(true, ['did' => 'Saved the line', 'record_id' => $lid]);
    hx_trigger('purchaseOrderChanged');
    echo po_lines_fragment($pdo, $poId);
    exit;
}
inv_done(($curLine === null ? 'Added a line to ' : 'Saved a line of ') . $o['number'], $lid, inv_land('/purchasing/' . $poId . '/edit', 'line_saved', 'po-line-' . $lid), 'purchaseOrderChanged',
    ['purchase_order_id' => $poId, 'line_id' => $lid]);

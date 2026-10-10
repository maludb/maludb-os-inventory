<?php
declare(strict_types=1);
/**
 * Actions `order_line_add` (with `order`, a quote) and `order_line_update` (with `line`, a line of a quote; any field of order_line_add — a field left out stays): variant (an id, a SKU
 * or a code), qty, unit_price (the retail when empty — the trigger prices it), discount, fulfilment_kind + location / listing_variant or the picker's `fulfilment`, notes. Logs
 * `order.line_add` / `order.line_update` with the offer's snapshot. A bundle added becomes its components. An HTMX caller targeting #order-lines gets the region re-rendered. orders.write.
 */
require_once dirname(__DIR__, 3) . '/app/features/orders/handler.php';
orders_write_begin('orders.write');
$pdo = db();
$me = (int) current_member_id();
$lineId = request_integer('line') ?? request_integer('line_id');
$curLine = $lineId === null ? null : (find_order_line($pdo, $lineId) ?? refuse(404, 'Line not found.'));
$o = order_or_404($pdo, $curLine !== null ? $curLine['sales_order_id'] : (request_integer('order') ?? request_integer('sales_order_id')));
require_quote($o);
$errors = [];
$row = $_POST;
if ($curLine !== null) {                                       // a field left out stays as it was
    $keep = ['variant' => (string) $curLine['variant_id'], 'qty' => (string) $curLine['qty'], 'unit_price' => (string) $curLine['unit_price'], 'discount' => (string) $curLine['discount'], 'notes' => (string) ($curLine['notes'] ?? '')];
    foreach ($keep as $k => $v) { if (!array_key_exists($k, $row) || trim((string) $row[$k]) === '' && $k !== 'notes' && $k !== 'unit_price') { $row[$k] = $v; } }
    if (!isset($row['fulfilment']) && !isset($row['fulfilment_kind'])) {
        $row['fulfilment_kind'] = $curLine['fulfilment_kind'];
        if ($curLine['location_id'] !== null) { $row['location'] = (string) $curLine['location_id']; }
        if ($curLine['listing_variant_id'] !== null) { $row['listing_variant'] = (string) $curLine['listing_variant_id']; }
    }
}
$cols = order_line_columns($pdo, array_map(static fn ($v) => is_scalar($v) || $v === null ? trim((string) $v) : $v, $row), 'line', $errors);
if ($curLine === null && trim((string) ($row['variant'] ?? '')) === '' && trim((string) ($row['variant_code'] ?? $row['sku'] ?? '')) === '') { $errors = ['variant' => 'Choose a variant.']; }
if ($errors !== []) { inv_refuse_fields(array_combine(array_map(static fn ($k) => substr((string) $k, strpos((string) $k, '.') + 1), array_keys($errors)), array_values($errors))); }
if ($curLine !== null && count($cols) !== 1) { refuse(422, 'A line is one variant; add a bundle as new lines.'); }
$ids = inv_guard($pdo, static function () use ($pdo, $o, $curLine, $cols): array {
    $pdo->beginTransaction();
    $ids = [];
    foreach ($cols as $c) {
        $ids[] = save_order_line($pdo, (int) $o['sales_order_id'], $curLine['line_id'] ?? null, $c);
    }
    foreach ($ids as $lid) { order_log($pdo, $curLine === null ? 'order.line_add' : 'order.line_update', $o, line_loggable($pdo, $lid)); }
    $pdo->commit();
    return $ids;
});
$oid = (int) $o['sales_order_id'];
if (is_htmx_request() && !wants_json() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'order-lines') {
    emit_action_status(true, ['did' => 'Saved the line', 'record_id' => $ids[0]]);
    hx_trigger('orderChanged');
    echo orders_lines_fragment($pdo, $oid);
    exit;
}
inv_done(($curLine === null ? 'Added a line to ' : 'Saved a line of ') . $o['number'], $ids[0], inv_land('/orders/' . $oid . '/edit', 'line_saved', 'order-line-' . $ids[0]), 'orderChanged',
    ['sales_order_id' => $oid, 'line_id' => $ids[0], 'line_ids' => $ids]);

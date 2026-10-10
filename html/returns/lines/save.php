<?php
declare(strict_types=1);
/**
 * Action `return_line_add` (log `return.line_add`: return_line_id, sku, qty, reason_code, disposition; undo return_line_remove): orders.write. `return` (requested or approved), `line` (the sales order line), `qty`,
 * `reason` (a reason code id or its code), `disposition` (restock by default), `location`, `condition_note`; with `return_line` it CHANGES that line (the fields sent; a partial update under an action token). A second row for the
 * same order line is "already on this return — change its quantity". The database's sentence is the line's field error. Location /returns/{id}#return-line-{n}; refresh returnChanged.
 */
require_once dirname(__DIR__, 3) . '/app/features/returns/handler.php';
returns_write_begin('orders.write');
$pdo = db();
$lineId = request_integer('return_line');
$existing = $lineId === null ? null : find_return_line($pdo, $lineId);
if ($lineId !== null && $existing === null) { refuse(404, 'Return line not found.'); }
$r = return_or_404($pdo, $existing === null ? request_return_id($pdo) : $existing['return_id']);
$row = [];
foreach (['line' => ['line', 'sales_order_line', 'sales_order_line_id'], 'qty' => ['qty'], 'reason' => ['reason', 'reason_code', 'reason_code_id'], 'disposition' => ['disposition'], 'location' => ['location', 'location_id'], 'condition_note' => ['condition_note']] as $k => $names) {
    foreach ($names as $name) { if (req_has($name)) { $row[$k] = (string) req_val($name); break; } }
}
if ($existing !== null) {
    $row += ['line' => (string) $existing['sales_order_line_id'], 'qty' => (string) $existing['qty'], 'reason' => (string) $existing['reason_code_id'], 'disposition' => $existing['disposition'],
             'location' => $existing['location_id'] === null ? '' : (string) $existing['location_id'], 'condition_note' => (string) ($existing['condition_note'] ?? '')];
    if ((string) $row['line'] !== (string) $existing['sales_order_line_id']) { refuse(422, 'A return line stays on its order line — remove it and add another.'); }
}
$errors = [];
$l = return_line_from_row($pdo, $row, 'k', $errors, (int) $r['sales_order_id']);
if ($errors !== []) { inv_refuse_fields(array_combine(array_map(static fn (string $k): string => substr($k, 2), array_keys($errors)), array_values($errors))); }
if ($existing === null && one_value($pdo, 'SELECT 1 FROM mcp_return_lines WHERE return_id = :r AND sales_order_line_id = :l', ['r' => (int) $r['return_id'], 'l' => $l['sales_order_line_id']]) !== null) {
    inv_refuse_fields(['line' => 'That line is already on this return — change its quantity.']);
}
$loc = $l['location_id'] ?? $r['location_id'];
if (in_array($l['disposition'], ['restock', 'floor_model'], true) && $loc === null) { inv_refuse_fields(['location' => 'Say where ' . $l['sku'] . ' comes back to.']); }
$id = inv_guard($pdo, static function () use ($pdo, $r, $l, $existing): int {
    $pdo->beginTransaction();
    $f = ['qty' => $l['qty'], 'reason_code_id' => $l['reason_code_id'], 'disposition' => $l['disposition'], 'location_id' => $l['location_id'], 'condition_note' => $l['condition_note']];
    if ($existing === null) { $id = return_line_try($pdo, $l['sales_order_line_id'], static fn (): int => add_return_line($pdo, (int) $r['return_id'], $l)); }
    else { $id = (int) $existing['return_line_id']; return_line_try($pdo, $l['sales_order_line_id'], static function () use ($pdo, $id, $f): void { update_return_line($pdo, $id, $f); }); }
    return_log($pdo, 'return.line_add', $r, ['return_line_id' => $id, 'sku' => $l['sku'], 'qty' => $l['qty'], 'reason_code' => $l['reason_code'], 'disposition' => $l['disposition'], 'changed' => $existing !== null]);
    $pdo->commit();
    return $id;
});
inv_done(($existing === null ? 'Added ' : 'Changed ') . $l['sku'] . ' on ' . $r['number'], $id, inv_land('/returns/' . (int) $r['return_id'], $existing === null ? 'line_added' : 'saved', 'return-line-' . $id), 'returnChanged',
    ['return_id' => (int) $r['return_id'], 'return_line_id' => $id, 'sku' => $l['sku'], 'qty' => $l['qty']]);

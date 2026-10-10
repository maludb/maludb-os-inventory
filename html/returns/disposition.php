<?php
declare(strict_types=1);
/**
 * Action `return_disposition_set` (log `return.disposition`: return_line_id, sku, before and after disposition, location_id; undo: set it back): returns.write. `return_line`, `disposition` (restock, floor_model,
 * dispose, return_to_supplier, donate), `location` (where a restock or a floor model lands; required when the return names none). Only while the return is requested or approved — the database's sentence.
 * Location /returns/{id}#return-line-{n}; refresh returnChanged.
 */
require_once dirname(__DIR__, 2) . '/app/features/returns/handler.php';
returns_write_begin('returns.write');
$pdo = db();
$l = find_return_line($pdo, request_integer('return_line') ?? 0);
if ($l === null) { refuse(404, 'Return line not found.'); }
$r = return_or_404($pdo, $l['return_id']);
$d = trim((string) (req_val('disposition') ?? ''));
if (!isset(RETURN_DISPOSITIONS[$d])) { inv_refuse_fields(['disposition' => 'The disposition is restock, floor_model, dispose, return_to_supplier or donate.']); }
$lv = trim((string) (req_val('location') ?? req_val('location_id') ?? ''));
$loc = null;
if ($lv !== '' && $lv !== '0') {
    if (!ctype_digit($lv) || one_value($pdo, 'SELECT 1 FROM mcp_locations WHERE location_id = :id AND active', ['id' => (int) $lv]) === null) { inv_refuse_fields(['location' => 'That location is not here.']); }
    $loc = (int) $lv;
}
if (in_array($d, ['restock', 'floor_model'], true) && ($loc ?? $l['location_id'] ?? $r['location_id']) === null) { inv_refuse_fields(['location' => 'Say where ' . $l['sku'] . ' comes back to.']); }
$out = inv_guard($pdo, static function () use ($pdo, $l, $r, $d, $loc): array {
    $pdo->beginTransaction();
    $out = return_line_try($pdo, (int) $l['sales_order_line_id'], static fn (): array => set_return_disposition($pdo, (int) $l['return_line_id'], $d, $loc));
    return_log($pdo, 'return.disposition', $r, ['return_line_id' => (int) $l['return_line_id'], 'sku' => $l['sku'], 'disposition' => $d, 'location_id' => $out['after']['location_id']], ['before' => ['disposition' => $out['before']['disposition']]]);
    $pdo->commit();
    return $out;
});
inv_done('Set ' . $l['sku'] . ' to ' . strtolower(RETURN_DISPOSITIONS[$d]), (int) $l['return_line_id'], inv_land('/returns/' . (int) $r['return_id'], 'disposition', 'return-line-' . (int) $l['return_line_id']), 'returnChanged',
    ['return_id' => (int) $r['return_id'], 'return_line_id' => (int) $l['return_line_id'], 'disposition' => $d]);

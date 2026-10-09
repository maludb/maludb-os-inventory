<?php
declare(strict_types=1);
/**
 * Action `adjustment_draft` (log `stock.adjustment_draft`: number, location_id, reason_code, lines): a draft — location, reason (a reason code that applies
 * to adjustments), notes, `lines` JSON (variant with qty_delta, unit_cost, note); with `adjustment` the header of a draft changes (the location only while
 * it has no lines). stock.adjust. Location /adjustments/{id}.
 */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
stock_write_begin('stock.adjust');
$pdo = db();
$id = request_integer('adjustment') ?? request_integer('adjustment_id');
$cur = $id === null ? null : adjustment_or_404($pdo, $id);
if ($cur !== null) { require_draft($cur, 'adjustment'); }
$errors = [];
$f = adjustment_from_request($pdo, $cur, $errors);
if ($errors !== []) { inv_refuse_fields($errors); }
$lines = stock_lines_json();
if ($id === null && req_has('variant') && (string) req_val('variant') !== '') {          // the form's first line (adjustment-add with ?variant=)
    $lines[] = ['variant' => (string) req_val('variant'), 'qty_delta' => (string) (req_val('qty_delta') ?? '')] + (req_has('unit_cost') ? ['unit_cost' => (string) req_val('unit_cost')] : []);
}
$lineFields = stock_read_lines($lines, static fn (array &$errs): array => adjustment_line_from_request($pdo, null, $errs));
$me = stock_me();
$newId = inv_guard($pdo, static function () use ($pdo, $id, $cur, $f, $lineFields, $me): int {
    $pdo->beginTransaction();
    $newId = save_adjustment($pdo, $id, $f, $lineFields, $me);
    $a = find_adjustment($pdo, $newId);
    $after = ['number' => $a['number'], 'location_id' => $f['location_id'], 'reason_code' => $a['reason_code'], 'lines' => (int) $a['line_count'], 'lines_added' => count($lineFields)];
    $opts = [];
    if ($cur !== null) {
        $d = inv_diff(['location_id' => (int) $cur['location_id'], 'reason_code' => $cur['reason_code'], 'notes' => $cur['notes']], ['location_id' => $f['location_id'], 'reason_code' => $a['reason_code'], 'notes' => $f['notes']]);
        $opts['before'] = $d['before'];
        $after = $d['after'] + ['number' => $a['number'], 'updated' => true, 'lines_added' => count($lineFields)];
        unset($after['notes']);
    }
    stock_log($pdo, 'stock.adjustment_draft', 'inventory_adjustment', $newId, $f['location_id'], $after, $opts);
    $pdo->commit();
    return $newId;
});
inv_done(($id === null ? 'Drafted the adjustment ' : 'Saved ') . find_adjustment($pdo, $newId)['number'], $newId, inv_land(return_path('/adjustments/' . $newId), $id === null ? 'created' : 'saved'), 'stockChanged', ['adjustment_id' => $newId]);

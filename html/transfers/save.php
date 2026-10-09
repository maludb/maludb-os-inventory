<?php
declare(strict_types=1);
/** Action `transfer_draft` (log `stock.transfer_draft`: number, from_location_id, to_location_id, lines): a draft — from, to, notes, `lines` JSON (variant or barcode with qty); with `transfer` a draft's header changes. stock.transfer. Location /transfers/{id}. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
stock_write_begin('stock.transfer');
$pdo = db();
$id = request_integer('transfer') ?? request_integer('transfer_id');
$cur = $id === null ? null : transfer_or_404($pdo, $id);
if ($cur !== null) { require_draft($cur, 'transfer'); }
$errors = [];
$f = transfer_from_request($pdo, $cur, $errors);
if ($errors !== []) { inv_refuse_fields($errors); }
$lines = stock_lines_json();
if ($id === null && req_has('variant') && (string) req_val('variant') !== '') {      // the form's first line (transfer-add with ?variant=)
    $lines[] = ['variant' => (string) req_val('variant'), 'qty' => (string) (req_val('qty') ?? '1')];
}
$lineFields = stock_read_lines($lines, static fn (array &$errs): array => transfer_line_from_request($pdo, null, $errs));
$me = stock_me();
$newId = inv_guard($pdo, static function () use ($pdo, $id, $cur, $f, $lineFields, $me): int {
    $pdo->beginTransaction();
    $newId = save_transfer($pdo, $id, $f, $lineFields, $me);
    $t = find_transfer($pdo, $newId);
    $after = ['number' => $t['number'], 'from_location_id' => $f['from_location_id'], 'to_location_id' => $f['to_location_id'], 'lines' => (int) $t['line_count'], 'lines_added' => count($lineFields)];
    $opts = [];
    if ($cur !== null) {
        $d = inv_diff(['from_location_id' => (int) $cur['from_location_id'], 'to_location_id' => (int) $cur['to_location_id']], ['from_location_id' => $f['from_location_id'], 'to_location_id' => $f['to_location_id']]);
        $opts['before'] = $d['before'];
        $after = $d['after'] + ['number' => $t['number'], 'updated' => true, 'lines_added' => count($lineFields)];
    }
    stock_log($pdo, 'stock.transfer_draft', 'inventory_transfer', $newId, $f['from_location_id'], $after, $opts);
    $pdo->commit();
    return $newId;
});
inv_done(($id === null ? 'Drafted the transfer ' : 'Saved ') . find_transfer($pdo, $newId)['number'], $newId, inv_land(return_path('/transfers/' . $newId), $id === null ? 'created' : 'saved'), 'stockChanged', ['transfer_id' => $newId]);

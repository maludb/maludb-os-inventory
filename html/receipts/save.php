<?php
declare(strict_types=1);
/**
 * Action `receipt_draft` (log `stock.receipt_draft`: number, supplier_id, purchase_order_id, location_id, lines): a draft's header and the lines given
 * (`lines` JSON — variant or barcode with qty, unit_cost, putaway_location, purchase_order_line, discrepancy_kind, discrepancy_note — or the PO prefill's
 * `po_lines[]` + `po_qty[<line>]`); with `receipt`, the header of a draft changes (a field left out stays). stock.receive. Location /receipts/{id}.
 */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
stock_write_begin('stock.receive');
$pdo = db();
$id = request_integer('receipt') ?? request_integer('goods_receipt_id');
$cur = $id === null ? null : receipt_or_404($pdo, $id);
if ($cur !== null) { require_draft($cur, 'receipt'); }
$errors = [];
$f = receipt_from_request($pdo, $cur, $errors);
if ($errors !== []) { inv_refuse_fields($errors); }
$lines = stock_lines_json();
// the PO prefill (a browser): the checked open lines with their quantities
if (isset($_POST['po_lines']) && is_array($_POST['po_lines']) && $f['purchase_order_id'] !== null) {
    $open = [];
    foreach (po_open_lines($pdo, $f['purchase_order_id']) as $pl) { $open[(int) $pl['purchase_order_line_id']] = $pl; }
    foreach ($_POST['po_lines'] as $plId) {
        $plId = (int) $plId;
        if (!isset($open[$plId])) { continue; }
        $q = (string) ($_POST['po_qty'][$plId] ?? $open[$plId]['qty_open']);
        $lines[] = ['variant' => (string) $open[$plId]['variant_id'], 'qty' => $q === '' ? (string) $open[$plId]['qty_open'] : $q, 'purchase_order_line' => (string) $plId];
    }
}
$pseudo = ['purchase_order_id' => $f['purchase_order_id']];
$lineFields = stock_read_lines($lines, static fn (array &$errs): array => receipt_line_from_request($pdo, $pseudo, null, $errs));
$me = stock_me();
$newId = inv_guard($pdo, static function () use ($pdo, $id, $cur, $f, $lineFields, $me): int {
    $pdo->beginTransaction();
    $newId = save_receipt($pdo, $id, $f, $lineFields, $me);
    $r = find_receipt($pdo, $newId);
    $after = ['number' => $r['number'], 'supplier_id' => $f['supplier_id'], 'purchase_order_id' => $f['purchase_order_id'], 'location_id' => $f['location_id'], 'received_on' => $f['received_on'],
              'delivery_note_ref' => $f['delivery_note_ref'], 'lines' => (int) $r['line_count'], 'lines_added' => count($lineFields)];
    $opts = ['purchase_order_id' => $f['purchase_order_id']];
    if ($cur !== null) {
        $before = ['supplier_id' => $cur['supplier_id'] === null ? null : (int) $cur['supplier_id'], 'purchase_order_id' => $cur['purchase_order_id'] === null ? null : (int) $cur['purchase_order_id'],
                   'location_id' => (int) $cur['location_id'], 'received_on' => $cur['received_on'], 'delivery_note_ref' => $cur['delivery_note_ref']];
        $d = inv_diff($before, array_intersect_key($after, $before));
        $opts['before'] = $d['before'];
        $after = $d['after'] + ['number' => $r['number'], 'updated' => true, 'lines_added' => count($lineFields)];
    }
    stock_log($pdo, 'stock.receipt_draft', 'goods_receipt', $newId, $f['location_id'], $after, $opts);
    $pdo->commit();
    return $newId;
});
inv_done(($id === null ? 'Drafted the receipt ' : 'Saved ') . find_receipt($pdo, $newId)['number'], $newId, inv_land(return_path('/receipts/' . $newId), $id === null ? 'created' : 'saved'), 'stockChanged', ['goods_receipt_id' => $newId]);

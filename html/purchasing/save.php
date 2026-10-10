<?php
declare(strict_types=1);
/**
 * Actions `purchase_order_draft` (no `purchase_order`; log `purchase_order.draft`) and `purchase_order_update` (with `purchase_order`, a draft only; log `purchase_order.update` — the fields changed): purchasing.write.
 * A stock order: `supplier` and the ship-to `location` (the first warehouse by default), `lines` as the form's lines[n][…] or JSON — a bundle is refused (it is bought as its components), a line with no cost takes the
 * price sheet's or the offer's. A DROP-SHIP order (`kind` dropship + `order`) is drafted by the database, one purchase order per supplier with open drop-ship lines (inv_order_dropships_draft()): `lines` and `supplier`
 * are ignored, the answer's record is the first drafted and `purchase_orders` names them all. On an update `lines` ADDS lines and a new supplier clears the lines' supplier SKUs and offers. Location /purchasing/{id}; refresh purchaseOrderChanged.
 */
require_once dirname(__DIR__, 2) . '/app/features/purchasing/handler.php';
purchasing_write_begin('purchasing.write');
$pdo = db();
$me = (int) current_member_id();
$id = request_po_id();
$cur = $id === null ? null : po_or_404($pdo, $id);
if ($cur !== null) { require_po_draft($cur); }
$kind = $cur['kind'] ?? (string) (req_val('kind') ?? 'stock');
if (!isset(PO_KINDS[$kind])) { inv_refuse_fields(['kind' => 'A purchase order is for stock or a drop-ship.']); }

// ---- a drop-ship is drafted by the database
if ($cur === null && $kind === 'dropship') {
    $ordRaw = trim((string) (req_val('order') ?? req_val('sales_order_id') ?? req_val('sales_order') ?? ''));
    $so = $ordRaw === '' ? null : find_sales_order_brief($pdo, $ordRaw);
    if ($ordRaw === '') { inv_refuse_fields(['order' => 'Name the sales order the drop-ship fills.']); }
    if ($so === null) { inv_refuse_fields(['order' => 'That sales order is not here.']); }
    $ids = inv_guard($pdo, static function () use ($pdo, $so, $me): array {
        $pdo->beginTransaction();
        $ids = draft_dropships_for_order($pdo, $so['sales_order_id'], $me);
        foreach ($ids as $pid) {
            $po = find_purchase_order($pdo, $pid);
            po_log($pdo, 'purchase_order.draft', $po, po_loggable($po) + ['for_order' => $so['number']]);
        }
        $pdo->commit();
        return $ids;
    });
    $first = $ids[0];
    inv_done('Drafted ' . count($ids) . ' drop-ship purchase order' . (count($ids) === 1 ? '' : 's') . ' for ' . $so['number'], $first, inv_land(return_path('/purchasing/' . $first), count($ids) === 1 ? 'created' : 'dropships'), 'purchaseOrderChanged',
        ['purchase_order_id' => $first, 'purchase_orders' => $ids, 'sales_order_id' => $so['sales_order_id']]);
}

// ---- a stock order, or the header and lines of a draft
$errors = [];
$head = po_head_from_request($pdo, $cur, $errors);
$supplierId = (int) ($head['supplier_id'] ?? $cur['supplier_id'] ?? 0);
$lines = $supplierId > 0 ? po_lines_from_request($pdo, $supplierId, $errors) : [];
if ($cur !== null && $cur['kind'] === 'dropship' && $lines !== []) { $errors['lines'] = "A drop-ship's lines are the customer's — they are drafted from the order."; }
if ($cur === null && $lines === [] && count(array_filter($errors, static fn ($k) => str_starts_with((string) $k, 'lines.'), ARRAY_FILTER_USE_KEY)) === 0) { $errors['lines'] = 'A purchase order has at least one line.'; }
if ($errors !== []) { inv_refuse_fields($errors); }
try {
    $poId = inv_guard($pdo, static function () use ($pdo, $me, $id, $cur, $head, $lines): int {
        $pdo->beginTransaction();
        if ($cur === null) {
            $poId = draft_purchase_order($pdo, $head, $lines, $me);
            $po = find_purchase_order($pdo, $poId);
            po_log($pdo, 'purchase_order.draft', $po, po_loggable($po));
        } else {
            $poId = (int) $id;
            $d = update_purchase_order($pdo, $poId, $head, $me);
            foreach ($lines as $l) {
                $lid = save_po_line($pdo, $poId, null, $l);
                po_log($pdo, 'purchase_order.line_add', $cur, po_line_loggable($pdo, $lid));
            }
            $po = find_purchase_order($pdo, $poId);
            if ($d['after'] !== [] || $lines !== []) {
                po_log($pdo, 'purchase_order.update', $po, ['number' => $po['number'], 'changed' => array_keys($d['after']), 'lines_added' => count($lines)] + array_diff_key($d['after'], ['notes' => 1, 'internal_notes' => 1]));
            }
        }
        $pdo->commit();
        return $poId;
    });
} catch (Throwable $e) { po_refused($e); }
$po = find_purchase_order($pdo, $poId);
inv_done(($id === null ? 'Drafted ' : 'Saved ') . $po['number'], $poId, inv_land(return_path('/purchasing/' . $poId), $id === null ? 'created' : 'saved'), 'purchaseOrderChanged',
    ['purchase_order_id' => $poId, 'number' => $po['number'], 'supplier_id' => $po['supplier_id'], 'status' => $po['status'], 'total' => $po['total'], 'lines' => (int) $po['line_count']]);

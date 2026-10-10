<?php
declare(strict_types=1);
/**
 * Action `purchase_order_place` (log `purchase_order.place`: number, supplier_order_ref, sent_via; confirm; `money_out` for agents): purchasing.write. A draft (or a sent order) placed on the supplier's portal, by API or by EDI
 * with THEIR reference — recorded as sent, no link and no email. `supplier_ref` is required (≤ 100), `via` portal (the default), api or edi. Location /purchasing/{id}.
 */
require_once dirname(__DIR__, 2) . '/app/features/purchasing/handler.php';
purchasing_write_begin('purchasing.write');
$pdo = db();
$o = po_or_404($pdo, request_po_id());
$errors = [];
$ref = (string) (req_val('supplier_ref') ?? req_val('reference') ?? '');
if ($ref === '') { $errors['supplier_ref'] = "Give the supplier's reference for this order."; }
elseif (mb_strlen($ref) > 100) { $errors['supplier_ref'] = 'The reference is up to 100 characters.'; }
$via = (string) (req_val('via') ?? '');
if ($via === '') { $via = 'portal'; }
if (!in_array($via, ['portal', 'api', 'edi'], true)) { $errors['via'] = 'Placed on their portal, by API or by EDI.'; }
if ($errors !== []) { inv_refuse_fields($errors); }
inv_guard($pdo, static function () use ($pdo, $o, $ref, $via): void {
    $pdo->beginTransaction();
    $r = place_purchase_order($pdo, (int) $o['purchase_order_id'], $ref, $via, (int) current_member_id());
    $po = find_purchase_order($pdo, (int) $o['purchase_order_id']);
    po_log($pdo, 'purchase_order.place', $po, po_loggable($po) + ['supplier_order_ref' => $r['supplier_order_ref'] ?? $ref, 'sent_via' => $via, 'was_draft' => $o['status'] === 'draft']);
    $pdo->commit();
});
inv_done('Placed ' . $o['number'] . ' with ' . $o['supplier_name'], (int) $o['purchase_order_id'], inv_land('/purchasing/' . (int) $o['purchase_order_id'], 'placed'), 'purchaseOrderChanged',
    ['purchase_order_id' => (int) $o['purchase_order_id'], 'number' => $o['number'], 'supplier_order_ref' => $ref, 'sent_via' => $via]);

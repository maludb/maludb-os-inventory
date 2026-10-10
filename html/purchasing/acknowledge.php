<?php
declare(strict_types=1);
/**
 * Action `purchase_order_acknowledge` (log `purchase_order.supplier_ack`, source web: number, line_id, supplier_order_ref, expected_on): the supplier called or wrote and a person records it — `supplier_ref` (≤ 100), `expected_on` (a date),
 * `line` (one line of this order; the whole order by default). inv_po_acknowledge() with source `manual`. purchasing.write. Location /purchasing/{id}#po-events.
 */
require_once dirname(__DIR__, 2) . '/app/features/purchasing/handler.php';
purchasing_write_begin('purchasing.write');
$pdo = db();
$o = po_or_404($pdo, request_po_id());
$errors = [];
$ref = (string) (req_val('supplier_ref') ?? '');
if (mb_strlen($ref) > 100) { $errors['supplier_ref'] = 'The reference is up to 100 characters.'; }
$exp = po_date_field('expected_on', null, 'The expected date', $errors);
$line = request_integer('line') ?? request_integer('line_id');
if ($errors !== []) { inv_refuse_fields($errors); }
try {
    inv_guard($pdo, static function () use ($pdo, $o, $ref, $exp, $line): void {
        $pdo->beginTransaction();
        $r = acknowledge_purchase_order($pdo, (int) $o['purchase_order_id'], $line, $ref === '' ? null : $ref, $exp, 'manual', (int) current_member_id());
        po_log($pdo, 'purchase_order.supplier_ack', $o, ['number' => $o['number'], 'line_id' => $line, 'supplier_order_ref' => $ref === '' ? null : $ref, 'expected_on' => $exp, 'status' => $r['status'] ?? null]);
        $pdo->commit();
    });
} catch (Throwable $e) { po_refused($e); }
inv_done('Recorded the acknowledgment of ' . $o['number'], (int) $o['purchase_order_id'], inv_land('/purchasing/' . (int) $o['purchase_order_id'], 'acknowledged', 'po-events'), 'purchaseOrderChanged',
    ['purchase_order_id' => (int) $o['purchase_order_id'], 'number' => $o['number'], 'line_id' => $line]);

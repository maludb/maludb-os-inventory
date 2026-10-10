<?php
declare(strict_types=1);
/**
 * Action `purchase_order_tracking` (log `purchase_order.supplier_tracking`, source web: number, line_id, carrier, tracking_number, shipped_at): `line`, `carrier` (≤ 60), `tracking` (required, ≤ 100), `shipped_at` (a date or a date and time;
 * now by default). inv_po_tracking(): the line is shipped; a drop-ship line is the customer's `dropship` shipment, whose tracking link is written from the carrier map. purchasing.write. Location /purchasing/{id}#po-line-{line}.
 */
require_once dirname(__DIR__, 2) . '/app/features/purchasing/handler.php';
purchasing_write_begin('purchasing.write');
$pdo = db();
$line = find_po_line($pdo, request_integer('line') ?? request_integer('line_id') ?? 0) ?? refuse(404, 'Line not found.');
$o = po_or_404($pdo, $line['purchase_order_id']);
$errors = [];
$carrier = (string) (req_val('carrier') ?? '');
if (mb_strlen($carrier) > 60) { $errors['carrier'] = 'The carrier is up to 60 characters.'; }
$tracking = (string) (req_val('tracking') ?? req_val('tracking_number') ?? '');
if ($tracking === '') { $errors['tracking'] = 'Give the tracking number.'; }
elseif (mb_strlen($tracking) > 100) { $errors['tracking'] = 'The tracking number is up to 100 characters.'; }
$shipped = order_datetime_field('shipped_at', 'The ship date', $errors);
if ($errors !== []) { inv_refuse_fields($errors); }
try {
    $r = inv_guard($pdo, static function () use ($pdo, $o, $line, $carrier, $tracking, $shipped): array {
        $pdo->beginTransaction();
        $r = track_po_line($pdo, $line['purchase_order_line_id'], $carrier === '' ? null : $carrier, $tracking, $shipped, 'manual', (int) current_member_id());
        po_log($pdo, 'purchase_order.supplier_tracking', $o, ['number' => $o['number'], 'line_id' => $line['purchase_order_line_id'], 'line_no' => $line['line_no'], 'carrier' => $carrier === '' ? null : $carrier,
            'tracking_number' => $tracking, 'shipped_at' => $shipped, 'shipment_id' => $r['shipment_id']]);
        $pdo->commit();
        return $r;
    });
} catch (Throwable $e) { po_refused($e); }
inv_done('Recorded tracking for line ' . $line['line_no'] . ' of ' . $o['number'], $line['purchase_order_line_id'], inv_land('/purchasing/' . (int) $o['purchase_order_id'], 'tracking', 'po-line-' . $line['purchase_order_line_id']), 'purchaseOrderChanged',
    ['purchase_order_id' => (int) $o['purchase_order_id'], 'line_id' => $line['purchase_order_line_id'], 'shipment_id' => $r['shipment_id'], 'tracking_url' => $r['tracking_url']]);

<?php
declare(strict_types=1);
/**
 * Action `supplier_message` (log `supplier.message`: supplier_id, purchase_order_id, subject, length — never the body; confirm; `external_send` for agents): purchasing.write. One email to the supplier's
 * order address (else its email) through MaluMail, inline; a purchase order named is prefixed to the subject and gets a `note` event "emailed: <subject>". No address → 422, a refused address 422, a
 * transport failure or a missing key 503. Location /suppliers/{id} (or the purchase order's page when one is named).
 */
require_once dirname(__DIR__, 2) . '/app/features/suppliers/handler.php';
inv_handler_begin();
require_right('purchasing.write');
$pdo = db();
$s = supplier_or_404($pdo, request_integer('supplier') ?? request_integer('supplier_id'));
$errors = [];
$subject = (string) (req_val('subject') ?? '');
$body = (string) (req_val('body') ?? '');
if ($subject === '' || mb_strlen($subject) > 200) { $errors['subject'] = 'Give the message a subject of up to 200 characters.'; }
if ($body === '' || mb_strlen($body) > 5000) { $errors['body'] = 'Write the message — up to 5,000 characters.'; }
$po = request_integer('purchase_order');
if ($po !== null && one_value($pdo, 'SELECT 1 FROM mcp_purchase_orders WHERE purchase_order_id = :id AND supplier_id = :s', ['id' => $po, 's' => $s['supplier_id']]) === null) { $errors['purchase_order'] = 'That purchase order is not this supplier\'s.'; }
if ($errors !== []) { inv_refuse_fields($errors); }
try {
    $r = inv_guard($pdo, static function () use ($pdo, $s, $subject, $body, $po): array {
        $r = message_supplier($pdo, $s['supplier_id'], $subject, $body, $po, (int) current_member_id());
        log_activity($pdo, 'supplier.message', 'supplier', $s['supplier_id'], ['purchase_order_id' => $po, 'after' => ['supplier_id' => $s['supplier_id'], 'supplier_name' => $s['name'], 'purchase_order_id' => $po,
            'subject' => mb_substr($subject, 0, 200), 'length' => mb_strlen($body), 'to' => $r['to_kind'], 'message_id' => $r['message_id']]]);
        return $r;
    });
} catch (Throwable $e) { order_refused($e); }
inv_done('Emailed ' . $s['name'], $s['supplier_id'], inv_land($po !== null ? '/purchasing/' . $po : '/suppliers/' . $s['supplier_id'], 'messaged', $po !== null ? 'po-events' : null), 'supplierChanged',
    ['supplier_id' => $s['supplier_id'], 'purchase_order_id' => $po, 'message_id' => $r['message_id']]);

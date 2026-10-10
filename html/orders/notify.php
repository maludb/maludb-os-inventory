<?php
declare(strict_types=1);
/**
 * Action `order_notify` (log `order.notify`: kind, number, promised_on; confirm; `external_send` for agents): `kind` delivery_date | delay | ready_for_pickup | shipped, `message` (≤ 2,000), `promised_on` (a new date — with delivery_date or delay it
 * MOVES the order's promised date, in the same transaction as the mail). The email carries no link (see your order page from your confirmation email). orders.send; a quote is not notified.
 */
require_once dirname(__DIR__, 2) . '/app/features/orders/handler.php';
orders_write_begin('orders.send');
$pdo = db();
$o = order_or_404($pdo, request_integer('order') ?? request_integer('sales_order_id'));
$errors = [];
$kind = trim((string) (req_val('kind') ?? ''));
if (!isset(ORDER_NOTIFY_KINDS[$kind])) { $errors['kind'] = 'A notice is delivery_date, delay, ready_for_pickup or shipped.'; }
$message = trim((string) (req_val('message') ?? ''));
if (mb_strlen($message) > 2000) { $errors['message'] = 'The message is up to 2,000 characters.'; }
$promised = order_date_field('promised_on', null, 'The new date', $errors);
if ($promised !== null && $promised < $o['ordered_on']) { $errors['promised_on'] = 'The new date is not before the order date (' . format_date($o['ordered_on']) . ').'; }
if (in_array($kind, ['delivery_date'], true) && $promised === null && !isset($errors['promised_on'])) { $errors['promised_on'] = 'Give the new date.'; }
if ($errors !== []) { inv_refuse_fields($errors); }
if ($o['status'] === 'quote') { refuse(422, 'Confirm it first — a quote is not notified.'); }
if ($o['status'] === 'cancelled') { refuse(422, $o['number'] . ' is cancelled.'); }
if ((string) one_value($pdo, 'SELECT email FROM mcp_customers WHERE customer_id = :c', ['c' => (int) $o['customer_id']]) === '') { refuse(422, 'The customer has no email address.'); }
try {
    $r = inv_guard($pdo, static function () use ($pdo, $o, $kind, $message, $promised): array {
        $r = notify_order($pdo, (int) $o['sales_order_id'], $kind, $message === '' ? null : $message, $promised, (int) current_member_id());
        order_log($pdo, 'order.notify', $o, ['kind' => $kind, 'number' => $o['number'], 'promised_on' => $promised, 'message_id' => $r['message_id']]);
        return $r;
    });
} catch (Throwable $e) { order_refused($e); }
inv_done('Told the customer about ' . $o['number'], (int) $o['sales_order_id'], inv_land('/orders/' . (int) $o['sales_order_id'], 'notified'), 'orderChanged',
    ['sales_order_id' => (int) $o['sales_order_id'], 'kind' => $kind, 'promised_on' => $promised, 'message_id' => $r['message_id']]);

<?php
declare(strict_types=1);
/**
 * GET /orders/{id}/send — screen `order-send`: the confirmation email as the customer will see it (the link is made when you send), a message of your own, Send.
 * POST — action `order_send` (log `order.send`: number, to "the customer's email", link_id, rotated, message_id — never the address, never the token; confirm; `external_send` for agents): orders.send. The order is confirmed or
 * later and not cancelled (a quote is not sent), the customer has an email; in ONE transaction the link is minted (the previous live one is rotated), the mail goes through MaluMail and the transaction commits only when MaluMail
 * accepted it — a refused address is a 422 and no link is written, a transport failure a 503. The raw token lives in the email alone. Location /orders/{id}#order-link; refresh orderChanged.
 */
require_once dirname(__DIR__, 2) . '/app/features/orders/handler.php';
$pdo = db();
/** The reasons an order cannot be sent, in words, or null. */
function order_send_refusal(PDO $pdo, array $o): ?string
{
    if ($o['status'] === 'quote') { return 'Confirm it first — a quote is not sent in v1.'; }
    if ($o['status'] === 'cancelled') { return $o['number'] . ' is cancelled.'; }
    $email = (string) one_value($pdo, 'SELECT email FROM mcp_customers WHERE customer_id = :c', ['c' => (int) $o['customer_id']]);
    return $email === '' ? 'The customer has no email address.' : null;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    orders_write_begin('orders.send');
    $o = order_or_404($pdo, request_integer('order') ?? request_integer('sales_order_id') ?? request_integer('id'));
    if (($why = order_send_refusal($pdo, $o)) !== null) { refuse(422, $why); }
    $message = trim((string) (req_val('message') ?? ''));
    if (mb_strlen($message) > 2000) { inv_refuse_fields(['message' => 'The message is up to 2,000 characters.']); }
    try {
        $r = inv_guard($pdo, static function () use ($pdo, $o, $message): array {
            $r = send_order($pdo, (int) $o['sales_order_id'], $message === '' ? null : $message, (int) current_member_id());
            order_log($pdo, 'order.send', $o, ['number' => $o['number'], 'to' => "the customer's email", 'link_id' => $r['link_id'], 'rotated' => $r['rotated'], 'message_id' => $r['message_id'], 'with_message' => $message !== '']);
            return $r;
        });
    } catch (Throwable $e) { order_refused($e); }
    inv_done('Sent ' . $o['number'] . ' to the customer', (int) $o['sales_order_id'], inv_land('/orders/' . (int) $o['sales_order_id'], 'sent', 'order-link'), 'orderChanged',
        ['sales_order_id' => (int) $o['sales_order_id'], 'link_id' => $r['link_id'], 'rotated' => $r['rotated'], 'message_id' => $r['message_id']]);
}
require_right('orders.send');
$o = order_or_404($pdo, request_integer('id') ?? request_integer('order'));
log_screen_view($pdo, 'order-send');
$settings = order_mail_settings($pdo);
$preview = order_confirmation_mail($o, $o['lines'], $settings, str_repeat('0', 48), null);
$email = (string) one_value($pdo, 'SELECT email FROM mcp_customers WHERE customer_id = :c', ['c' => (int) $o['customer_id']]);
if (wants_json()) { respond_screen(['order' => present_order_row($o), 'can_send' => order_send_refusal($pdo, $o) === null, 'refusal' => order_send_refusal($pdo, $o), 'subject' => $preview['subject'], 'has_email' => $email !== '']); }
render_screen('Send ' . $o['number'], view('orders/send.php', ['o' => $o, 'preview' => $preview, 'refusal' => order_send_refusal($pdo, $o), 'email' => $email, 'here' => here_url(),
    'notice' => inv_notice($_GET['notice'] ?? null, ORDER_NOTICES)]), ['activeNav' => 'order-list', 'screen' => 'order-send', 'entity' => 'sales_order', 'recordId' => (string) $o['sales_order_id']]);

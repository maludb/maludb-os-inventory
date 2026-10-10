<?php
declare(strict_types=1);
/**
 * GET /purchasing/{id}/send — screen `purchase-order-send`: the email as the supplier will see it (the link is made when you send), a message of your own, Send by email — or Mark placed with the supplier's reference, or Sent by phone.
 * POST — action `purchase_order_send` (log `purchase_order.send`: number, supplier, kind, sales order, lines, total, sent_via, link_id, message_id — never the address, never the token; confirm; `money_out` for agents): purchasing.write.
 * A draft with an open line (the SQL); `via` email (the default) or phone. By email, in ONE transaction: the draft is sent, the supplier's link is minted and the mail goes through MaluMail; the transaction commits only when
 * MaluMail accepted it — a refused address is a 422 and no link is written, a transport error or a missing key a 503. By phone nothing is mailed and no link is minted. Location /purchasing/{id}#po-link; refresh purchaseOrderChanged.
 */
require_once dirname(__DIR__, 2) . '/app/features/purchasing/handler.php';
$pdo = db();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    purchasing_write_begin('purchasing.write');
    $o = po_or_404($pdo, request_po_id());
    $via = (string) (req_val('via') ?? 'email');
    if ($via === '') { $via = 'email'; }
    if (!in_array($via, ['email', 'phone'], true)) { inv_refuse_fields(['via' => 'Send by email or by phone.']); }
    $message = trim((string) (req_val('message') ?? ''));
    if (mb_strlen($message) > 2000) { inv_refuse_fields(['message' => 'The message is up to 2,000 characters.']); }
    try {
        $r = inv_guard($pdo, static function () use ($pdo, $o, $via, $message): array {
            $r = send_purchase_order($pdo, (int) $o['purchase_order_id'], $via, $message === '' ? null : $message, (int) current_member_id());
            $po = find_purchase_order($pdo, (int) $o['purchase_order_id']);
            po_log($pdo, 'purchase_order.send', $po, po_loggable($po) + ['sent_via' => $r['sent_via'], 'link_id' => $r['link_id'], 'message_id' => $r['message_id'], 'with_message' => $message !== '']);
            return $r;
        });
    } catch (Throwable $e) { po_refused($e); }
    inv_done('Sent ' . $o['number'] . ($via === 'phone' ? ' (by phone)' : ' to the supplier'), (int) $o['purchase_order_id'], inv_land('/purchasing/' . (int) $o['purchase_order_id'], $via === 'phone' ? 'sent_phone' : 'sent', 'po-link'), 'purchaseOrderChanged',
        ['purchase_order_id' => (int) $o['purchase_order_id'], 'number' => $o['number'], 'sent_via' => $r['sent_via'], 'link_id' => $r['link_id'], 'message_id' => $r['message_id']]);
}
require_right('purchasing.write');
$o = po_or_404($pdo, request_po_id());
log_screen_view($pdo, 'purchase-order-send');
$d = po_for_mail($pdo, (int) $o['purchase_order_id']);
$preview = purchase_order_mail($d['po'], $d['lines'], $d['supplier'], $d['settings'], str_repeat('0', 48), null, $d['show_phone']);
$refusal = $o['status'] !== 'draft' ? $o['number'] . ' is ' . str_replace('_', ' ', $o['status']) . ' — only a draft is sent.' : ($o['line_count'] === 0 ? $o['number'] . ' has no lines.' : null);
if (wants_json()) {
    respond_screen(['purchase_order' => present_po_row($o), 'can_send' => $refusal === null, 'refusal' => $refusal, 'subject' => $preview['subject'], 'has_email' => $d['to'] !== '', 'order_method' => $d['supplier']['order_method'], 'shows_phone' => $d['show_phone']]);
}
render_screen('Send ' . $o['number'], view('purchasing/send.php', ['o' => $o, 'preview' => $preview, 'refusal' => $refusal, 'to' => $d['to'], 'supplier' => $d['supplier'], 'showPhone' => $d['show_phone'], 'here' => here_url(),
    'notice' => inv_notice($_GET['notice'] ?? null, PO_NOTICES)]), ['activeNav' => 'purchase-order-list', 'screen' => 'purchase-order-send', 'entity' => 'purchase_order', 'recordId' => (string) $o['purchase_order_id']]);

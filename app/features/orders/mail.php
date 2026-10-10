<?php
declare(strict_types=1);

/**
 * The two emails a customer gets (orders.md "The mail"): the confirmation with their link, and the four notices (a date, a delay, ready, shipped). Built here, sent by
 * malumail_send() INLINE (the customer is not a member — the outbox is for members). NEVER a supplier's name, a source, a cost, an internal note or the salesperson.
 * The payload keys are the malumail-send skill's: from, from_name, to, reply_to, subject, text, html.
 */

/** The public base URL of the customer's door: INV_PUBLIC_BASE_URL, else APP_URL. */
function inv_public_url(string $path): string
{
    $base = trim((string) env('INV_PUBLIC_BASE_URL', ''));
    return rtrim($base !== '' ? $base : (string) env('APP_URL', ''), '/') . '/' . ltrim($path, '/');
}

/** business_name, business_contact_email, business_phone, currency — what a mail says about the business. */
function order_mail_settings(PDO $pdo): array
{
    return one_row($pdo, 'SELECT business_name, business_contact_email, business_phone, currency FROM mcp_settings') ?? ['business_name' => app_name(), 'business_contact_email' => null, 'business_phone' => null, 'currency' => 'USD'];
}

function mail_money(?string $amount, string $currency): string
{
    return ($currency === 'USD' || $currency === '' ? '$' : $currency . ' ') . number_format((float) $amount, 2);
}

/** Where the goods go, in words: "pickup at <store>" or "delivery to <city>" with the method and the promised date. */
function mail_delivery_words(array $order): string
{
    $m = DELIVERY_METHODS[$order['delivery_method']] ?? $order['delivery_method'];
    if ($order['delivery_method'] === 'pickup') { $w = 'Pickup at ' . ($order['location_name'] ?? 'the store'); }
    else { $w = $m . (($order['ship_to_city'] ?? '') !== '' ? ' to ' . $order['ship_to_city'] : ''); }
    return $w . ($order['promised_on'] !== null ? ' — promised for ' . format_date((string) $order['promised_on']) : '');
}

/** The confirmation: ['subject', 'text', 'html'] (and nothing about a supplier). $rawToken is the one-time token; $message the sender's words, or null. */
function order_confirmation_mail(array $order, array $lines, array $settings, string $rawToken, ?string $message): array
{
    $biz = (string) ($settings['business_name'] ?? app_name());
    $cur = (string) ($settings['currency'] ?? 'USD');
    $link = inv_public_url('/o/' . $rawToken);
    $subject = 'Your order ' . $order['number'] . ' from ' . $biz;
    $live = array_values(array_filter($lines, static fn (array $l): bool => $l['status'] !== 'cancelled'));
    $text = $biz . "\n" . implode(' · ', array_filter([(string) ($settings['business_contact_email'] ?? ''), (string) ($settings['business_phone'] ?? '')])) . "\n\n";
    if ($message !== null && trim($message) !== '') { $text .= trim($message) . "\n\n"; }
    $text .= 'Order ' . $order['number'] . ' of ' . format_date((string) $order['ordered_on']) . "\n\n";
    foreach ($live as $l) {
        $text .= $l['product_name'] . ($l['size_name'] ? ', ' . $l['size_name'] : '') . ' × ' . $l['qty'] . ' at ' . mail_money($l['unit_price'], $cur) . ' = ' . mail_money($l['line_total'], $cur) . "\n";
    }
    $text .= "\nSubtotal " . mail_money($order['subtotal'], $cur);
    if ((float) $order['discount_total'] > 0) { $text .= ' (after ' . mail_money($order['discount_total'], $cur) . ' off)'; }
    $text .= "\nTax " . mail_money($order['tax_total'], $cur) . "\nShipping " . mail_money($order['shipping_charge'], $cur) . "\nTotal " . mail_money($order['total'], $cur) . "\n";
    $text .= 'Payment: ' . strtolower(PAYMENT_STATUSES[$order['payment_status']] ?? $order['payment_status']) . '. Balance due: ' . mail_money($order['balance_due'], $cur) . "\n\n";
    $text .= mail_delivery_words($order) . "\n\nSee your order, its delivery and tracking here:\n" . $link . "\n";
    $h = '<div style="font-family: Arial, Helvetica, sans-serif; max-width: 640px; margin: 0 auto; color: #283c50">'
        . '<h2 style="margin: 0 0 4px">' . e($biz) . '</h2><div style="color: #6b7885; margin-bottom: 16px">' . e(implode(' · ', array_filter([(string) ($settings['business_contact_email'] ?? ''), (string) ($settings['business_phone'] ?? '')]))) . '</div>';
    if ($message !== null && trim($message) !== '') { $h .= '<p style="white-space: pre-line">' . e(trim($message)) . '</p>'; }
    $h .= '<p>Order <strong>' . e($order['number']) . '</strong> of ' . e(format_date((string) $order['ordered_on'])) . '</p>'
        . '<table style="width: 100%; border-collapse: collapse" cellpadding="6"><tr style="text-align: left; border-bottom: 1px solid #dee2e6"><th>Item</th><th style="text-align: right">Qty</th><th style="text-align: right">Price</th><th style="text-align: right">Total</th></tr>';
    foreach ($live as $l) {
        $h .= '<tr style="border-bottom: 1px solid #eee"><td>' . e($l['product_name'] . ($l['size_name'] ? ', ' . $l['size_name'] : '')) . '</td><td style="text-align: right">' . (int) $l['qty'] . '</td><td style="text-align: right">'
            . e(mail_money($l['unit_price'], $cur)) . '</td><td style="text-align: right">' . e(mail_money($l['line_total'], $cur)) . '</td></tr>';
    }
    $h .= '</table><p style="text-align: right">Subtotal ' . e(mail_money($order['subtotal'], $cur)) . '<br>Tax ' . e(mail_money($order['tax_total'], $cur)) . '<br>Shipping ' . e(mail_money($order['shipping_charge'], $cur))
        . '<br><strong>Total ' . e(mail_money($order['total'], $cur)) . '</strong><br>Payment: ' . e(strtolower(PAYMENT_STATUSES[$order['payment_status']] ?? $order['payment_status'])) . '. <strong>Balance due: ' . e(mail_money($order['balance_due'], $cur)) . '</strong></p>'
        . '<p>' . e(mail_delivery_words($order)) . '</p>'
        . '<p><a href="' . e($link) . '" style="background: #3454d1; color: #fff; padding: 10px 16px; text-decoration: none; border-radius: 4px; display: inline-block">See your order, its delivery and tracking</a></p>'
        . '<p style="color: #6b7885; font-size: 12px">' . e($link) . '</p></div>';
    return ['subject' => $subject, 'text' => $text, 'html' => $h];
}

/** The notice (a new date, a delay, ready for pickup, shipped): no link — "see your order page from your confirmation email". */
function order_notice_mail(array $order, string $kind, ?string $message, ?string $promisedOn, array $settings): array
{
    $biz = (string) ($settings['business_name'] ?? app_name());
    $subject = 'Your order ' . $order['number'] . ' — ' . ['delivery_date' => 'a new delivery date', 'delay' => 'a delay', 'ready_for_pickup' => 'ready for pickup', 'shipped' => 'shipped'][$kind];
    $facts = match ($kind) {
        'delivery_date' => 'Your delivery is now planned for ' . ($promisedOn !== null ? format_date($promisedOn) : 'a date we will confirm') . '.',
        'delay' => 'Your order is delayed.' . ($promisedOn !== null ? ' It is now expected on ' . format_date($promisedOn) . '.' : ''),
        'ready_for_pickup' => 'Your order is ready for pickup at ' . ($order['location_name'] ?? 'the store') . '.',
        default => 'Your order has shipped.',
    };
    $text = $biz . "\n\n" . $facts . "\n" . ($message !== null && trim($message) !== '' ? "\n" . trim($message) . "\n" : '')
        . "\nOrder " . $order['number'] . ' of ' . format_date((string) $order['ordered_on']) . "\nSee your order page from your confirmation email.\n"
        . implode(' · ', array_filter([(string) ($settings['business_contact_email'] ?? ''), (string) ($settings['business_phone'] ?? '')])) . "\n";
    $html = '<div style="font-family: Arial, Helvetica, sans-serif; max-width: 640px; margin: 0 auto; color: #283c50"><h2 style="margin: 0 0 12px">' . e($biz) . '</h2><p>' . e($facts) . '</p>'
        . ($message !== null && trim($message) !== '' ? '<p style="white-space: pre-line">' . e(trim($message)) . '</p>' : '')
        . '<p>Order <strong>' . e($order['number']) . '</strong> of ' . e(format_date((string) $order['ordered_on'])) . '. See your order page from your confirmation email.</p>'
        . '<p style="color: #6b7885">' . e(implode(' · ', array_filter([(string) ($settings['business_contact_email'] ?? ''), (string) ($settings['business_phone'] ?? '')]))) . '</p></div>';
    return ['subject' => $subject, 'text' => $text, 'html' => $html];
}

/** The malumail_send() payload for a built mail to one address. */
function order_mail_payload(array $mail, string $to, array $settings): array
{
    $p = ['from' => (string) env('MAIL_FROM', ''), 'from_name' => (string) (env('MAIL_FROM_NAME') ?: ($settings['business_name'] ?? app_name())), 'to' => $to,
          'reply_to' => (string) ($settings['business_contact_email'] ?? ''), 'subject' => $mail['subject'], 'text' => $mail['text'], 'html' => $mail['html']];
    return $p['reply_to'] === '' ? array_diff_key($p, ['reply_to' => 1]) : $p;
}

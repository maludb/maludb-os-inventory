<?php
declare(strict_types=1);

/**
 * The emails a supplier gets (purchasing.md "The mail"): the purchase order with their link, the "Updated link" mail of a rotation, and a free message. Built here, sent by malumail_send() INLINE through
 * order_mail_send() (the supplier is not a member — the outbox is for members). The ship-to's phone appears only when inv_po_shows_phone() said so ($showPhone). NEVER the internal notes. The payload keys are the
 * malumail-send skill's (order_mail_payload()): from, from_name, to, reply_to, subject, text, html.
 */

/** The ship-to block of a purchase order in words: [heading, lines[]]. A location: its name and address; a drop-ship: "Ship to our customer:" the name, address lines, city line, delivery notes and — when allowed — the phone. */
function po_ship_to_lines(array $po, bool $showPhone): array
{
    if (($po['ship_to_kind'] ?? 'location') === 'customer') {
        $city = trim(implode(' ', array_filter([(string) ($po['ship_to_city'] ?? ''), (string) ($po['ship_to_region'] ?? ''), (string) ($po['ship_to_postal'] ?? ''), (string) ($po['ship_to_country'] ?? '')])));
        $lines = array_values(array_filter([(string) ($po['ship_to_name'] ?? ''), (string) ($po['ship_to_address1'] ?? ''), (string) ($po['ship_to_address2'] ?? ''), $city], static fn (string $x): bool => $x !== ''));
        if ($showPhone && (string) ($po['ship_to_phone'] ?? '') !== '') { $lines[] = 'Phone: ' . $po['ship_to_phone']; }
        if ((string) ($po['ship_to_notes'] ?? '') !== '') { $lines[] = 'Delivery notes: ' . $po['ship_to_notes']; }
        return ['Ship to our customer:', $lines];
    }
    $lines = array_values(array_filter([(string) ($po['location_name'] ?? ''), (string) ($po['location_address'] ?? '')], static fn (string $x): bool => $x !== ''));
    return ['Ship to:', $lines];
}

/** The purchase order's email: ['subject', 'text', 'html']. $rawToken is the one-time token of the supplier's link; $message the sender's words, or null. */
function purchase_order_mail(array $po, array $lines, array $supplier, array $settings, string $rawToken, ?string $message, bool $showPhone): array
{
    $biz = (string) ($settings['business_name'] ?? app_name());
    $cur = (string) ($settings['currency'] ?? 'USD');
    $link = inv_public_url('/s/' . $rawToken);
    $subject = 'Purchase order ' . $po['number'] . ' from ' . $biz;
    $contact = implode(' · ', array_filter([(string) ($settings['business_contact_email'] ?? ''), (string) ($settings['business_phone'] ?? '')]));
    [$shipHead, $ship] = po_ship_to_lines($po, $showPhone);
    $account = (string) ($supplier['account_number'] ?? '');
    $text = $biz . "\n" . $contact . "\n\n";
    if ($message !== null && trim($message) !== '') { $text .= trim($message) . "\n\n"; }
    if ($account !== '') { $text .= 'Your account for us: ' . $account . "\n"; }
    $text .= 'Purchase order ' . $po['number'] . ' of ' . format_date((string) $po['ordered_on']) . "\n\n";
    foreach ($lines as $l) {
        $text .= $l['line_no'] . '. ' . (string) ($l['supplier_sku'] ?? '') . ' (our ' . $l['sku'] . ') ' . $l['product_name'] . ($l['size_name'] ? ', ' . $l['size_name'] : '') . ' × ' . $l['qty_ordered'] . ' at ' . mail_money((string) $l['unit_cost'], $cur)
            . ' = ' . mail_money((string) $l['line_cost'], $cur) . "\n";
    }
    $text .= "\nShipping " . mail_money((string) $po['shipping_cost'], $cur) . "\nTotal " . mail_money((string) $po['total'], $cur) . "\n";
    if ($po['expected_on'] !== null) { $text .= 'Expected: ' . format_date((string) $po['expected_on']) . "\n"; }
    $text .= "\n" . $shipHead . "\n" . implode("\n", $ship) . "\n";
    if ((string) ($po['notes'] ?? '') !== '') { $text .= "\nNotes: " . trim((string) $po['notes']) . "\n"; }
    $text .= "\nAcknowledge, decline a line or add tracking here:\n" . $link . "\n";
    $h = '<div style="font-family: Arial, Helvetica, sans-serif; max-width: 680px; margin: 0 auto; color: #283c50">'
        . '<h2 style="margin: 0 0 4px">' . e($biz) . '</h2><div style="color: #6b7885; margin-bottom: 16px">' . e($contact) . '</div>';
    if ($message !== null && trim($message) !== '') { $h .= '<p style="white-space: pre-line">' . e(trim($message)) . '</p>'; }
    if ($account !== '') { $h .= '<p>Your account for us: <strong>' . e($account) . '</strong></p>'; }
    $h .= '<p>Purchase order <strong>' . e($po['number']) . '</strong> of ' . e(format_date((string) $po['ordered_on'])) . '</p>'
        . '<table style="width: 100%; border-collapse: collapse" cellpadding="6"><tr style="text-align: left; border-bottom: 1px solid #dee2e6"><th>Your SKU</th><th>Item</th><th style="text-align: right">Qty</th><th style="text-align: right">Cost</th><th style="text-align: right">Total</th></tr>';
    foreach ($lines as $l) {
        $h .= '<tr style="border-bottom: 1px solid #eee"><td>' . e((string) ($l['supplier_sku'] ?? '')) . '<div style="color: #6b7885; font-size: 12px">our ' . e($l['sku']) . '</div></td><td>' . e($l['product_name'] . ($l['size_name'] ? ', ' . $l['size_name'] : '')) . '</td><td style="text-align: right">'
            . (int) $l['qty_ordered'] . '</td><td style="text-align: right">' . e(mail_money((string) $l['unit_cost'], $cur)) . '</td><td style="text-align: right">' . e(mail_money((string) $l['line_cost'], $cur)) . '</td></tr>';
    }
    $h .= '</table><p style="text-align: right">Shipping ' . e(mail_money((string) $po['shipping_cost'], $cur)) . '<br><strong>Total ' . e(mail_money((string) $po['total'], $cur)) . '</strong></p>';
    if ($po['expected_on'] !== null) { $h .= '<p>Expected: ' . e(format_date((string) $po['expected_on'])) . '</p>'; }
    $h .= '<p><strong>' . e($shipHead) . '</strong><br>' . implode('<br>', array_map('e', $ship)) . '</p>';
    if ((string) ($po['notes'] ?? '') !== '') { $h .= '<p style="white-space: pre-line"><strong>Notes</strong><br>' . e(trim((string) $po['notes'])) . '</p>'; }
    $h .= '<p><a href="' . e($link) . '" style="background: #3454d1; color: #fff; padding: 10px 16px; text-decoration: none; border-radius: 4px; display: inline-block">Acknowledge, decline a line or add tracking</a></p>'
        . '<p style="color: #6b7885; font-size: 12px">' . e($link) . '</p></div>';
    return ['subject' => $subject, 'text' => $text, 'html' => $h];
}

/** The mail of a rotation: "Updated link for PO-…" — the previous link no longer works, the new one is here. */
function purchase_order_link_mail(array $po, array $supplier, array $settings, string $rawToken): array
{
    $biz = (string) ($settings['business_name'] ?? app_name());
    $link = inv_public_url('/s/' . $rawToken);
    $contact = implode(' · ', array_filter([(string) ($settings['business_contact_email'] ?? ''), (string) ($settings['business_phone'] ?? '')]));
    $text = $biz . "\n" . $contact . "\n\nPurchase order " . $po['number'] . ': the previous link no longer works. Use this one to acknowledge, decline a line or add tracking:' . "\n" . $link . "\n";
    $h = '<div style="font-family: Arial, Helvetica, sans-serif; max-width: 640px; margin: 0 auto; color: #283c50"><h2 style="margin: 0 0 4px">' . e($biz) . '</h2><div style="color: #6b7885; margin-bottom: 16px">' . e($contact) . '</div>'
        . '<p>Purchase order <strong>' . e($po['number']) . '</strong>: the previous link no longer works. Use this one to acknowledge, decline a line or add tracking.</p>'
        . '<p><a href="' . e($link) . '" style="background: #3454d1; color: #fff; padding: 10px 16px; text-decoration: none; border-radius: 4px; display: inline-block">Open the purchase order</a></p>'
        . '<p style="color: #6b7885; font-size: 12px">' . e($link) . '</p></div>';
    return ['subject' => 'Updated link for ' . $po['number'], 'text' => $text, 'html' => $h];
}

/** A free message to a supplier: the business's name and contact above the sender's words. $po is the purchase order it mentions (number prefixed to the subject), or null. */
function supplier_message_mail(array $supplier, array $settings, string $subject, string $body, ?array $po): array
{
    $biz = (string) ($settings['business_name'] ?? app_name());
    $contact = implode(' · ', array_filter([(string) ($settings['business_contact_email'] ?? ''), (string) ($settings['business_phone'] ?? '')]));
    $subj = ($po !== null ? $po['number'] . ': ' : '') . $subject;
    $text = $biz . "\n" . $contact . "\n\n" . trim($body) . "\n";
    $h = '<div style="font-family: Arial, Helvetica, sans-serif; max-width: 640px; margin: 0 auto; color: #283c50"><h2 style="margin: 0 0 4px">' . e($biz) . '</h2><div style="color: #6b7885; margin-bottom: 16px">' . e($contact) . '</div>'
        . '<p style="white-space: pre-line">' . e(trim($body)) . '</p></div>';
    return ['subject' => $subj, 'text' => $text, 'html' => $h];
}

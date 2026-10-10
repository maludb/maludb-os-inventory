<?php
declare(strict_types=1);

/**
 * Orders' presenters (orders.md "Status vocabulary"): the JSON shapes (whitelists over the view rows), the chips, the line's fulfilment in words (the staff's and the
 * customer's), the carrier → tracking URL map, and order_line_from_request() — the ONE reader of a line's fulfilment choice (the picker's `kind:id` or the manifest's
 * `fulfilment_kind` + `location` / `listing_variant`).
 */

function order_status_chip(string $status, string $id = ''): string
{
    $c = ['quote' => 'secondary', 'confirmed' => 'info', 'in_fulfilment' => 'info', 'shipped' => 'primary', 'delivered' => 'success', 'closed' => 'dark', 'cancelled' => 'danger'][$status] ?? 'secondary';
    return '<span class="badge bg-soft-' . $c . ' text-' . $c . '"' . ($id !== '' ? ' id="' . e($id) . '"' : '') . '>' . e(ORDER_STATUSES[$status] ?? $status) . '</span>';
}

function line_status_chip(string $status): string
{
    $c = ['open' => 'secondary', 'allocated' => 'info', 'ordered' => 'info', 'shipped' => 'primary', 'delivered' => 'success', 'cancelled' => 'dark', 'returned' => 'warning'][$status] ?? 'secondary';
    return '<span class="badge bg-soft-' . $c . ' text-' . ($c === 'dark' ? 'dark' : $c) . '">' . e(LINE_STATUSES[$status] ?? $status) . '</span>';
}

function payment_status_chip(string $status, string $id = ''): string
{
    $c = ['unpaid' => 'warning', 'deposit' => 'info', 'paid' => 'success', 'refunded' => 'dark', 'partial_refund' => 'secondary'][$status] ?? 'secondary';
    return '<span class="badge bg-soft-' . $c . ' text-' . ($c === 'dark' ? 'dark' : $c) . '"' . ($id !== '' ? ' id="' . e($id) . '"' : '') . '>' . e(PAYMENT_STATUSES[$status] ?? $status) . '</span>';
}

function late_badge(array $o): string
{
    return !empty($o['is_late']) ? ' <span class="badge bg-soft-danger text-danger">late ' . (int) $o['late_days'] . ' day' . ((int) $o['late_days'] === 1 ? '' : 's') . '</span>' : '';
}

function fulfilment_chip(string $kind): string
{
    [$icon, $c] = ['stock' => ['feather-box', 'secondary'], 'dropship' => ['feather-truck', 'info'], 'backorder' => ['feather-clock', 'warning'], 'pickup' => ['feather-shopping-bag', 'secondary']][$kind] ?? ['feather-box', 'secondary'];
    return '<span class="badge bg-soft-' . $c . ' text-' . ($c === 'secondary' ? 'dark' : $c) . '"><i class="' . $icon . ' me-1"></i>' . e(FULFILMENT_KINDS[$kind] ?? $kind) . '</span>';
}

/** Where a line is filled from, staff's words: "Warehouse" · "Malouf · 5 days · cost 980.00" (the cost only when the view gave it) · "pickup at Showroom" · "backorder at Warehouse". */
function line_where(array $l): string
{
    switch ($l['fulfilment_kind']) {
        case 'dropship':
            return trim((string) ($l['source_name'] ?? 'a supplier') . ($l['offer_lead_time_days'] !== null ? ' · ' . $l['offer_lead_time_days'] . ' days' : '') . (!$l['cost_withheld'] && $l['offer_cost'] !== null ? ' · cost ' . money((string) $l['offer_cost']) : ''));
        case 'pickup':
            return 'pickup at ' . ($l['location_name'] ?? '?');
        case 'backorder':
            return 'backorder' . (($l['location_name'] ?? null) ? ' at ' . $l['location_name'] : '');
        default:
            return (string) ($l['location_name'] ?? '');
    }
}

/** The carrier word → a tracking link (a fixed four-carrier map; anything else has none). */
function tracking_url(?string $carrier, ?string $tracking): ?string
{
    $t = trim((string) $tracking);
    $c = strtolower(trim((string) $carrier));
    if ($t === '' || $c === '') { return null; }
    $u = rawurlencode($t);
    if (str_contains($c, 'ups')) { return 'https://www.ups.com/track?tracknum=' . $u; }
    if (str_contains($c, 'fedex')) { return 'https://www.fedex.com/fedextrack/?trknbr=' . $u; }
    if (str_contains($c, 'usps')) { return 'https://tools.usps.com/go/TrackConfirmAction?tLabels=' . $u; }
    if (str_contains($c, 'dhl')) { return 'https://www.dhl.com/en/express/tracking.html?AWB=' . $u; }
    return null;
}

/** The kind a delivery method suggests for a shipment. */
function ship_kind_for(string $method): string
{
    return ['pickup' => 'pickup', 'parcel' => 'parcel', 'ltl' => 'ltl', 'white_glove' => 'ltl', 'delivery' => 'own_delivery'][$method] ?? 'own_delivery';
}

function present_order_row(array $o): array
{
    return ['sales_order_id' => (int) $o['sales_order_id'], 'number' => $o['number'], 'customer_id' => (int) $o['customer_id'], 'customer_name' => $o['customer_name'], 'status' => $o['status'],
            'salesperson_member_id' => $o['salesperson_member_id'], 'salesperson_name' => $o['salesperson_name'], 'location_id' => $o['location_id'], 'location_name' => $o['location_name'],
            'ordered_on' => $o['ordered_on'], 'promised_on' => $o['promised_on'], 'is_late' => (bool) $o['is_late'], 'late_days' => (int) $o['late_days'], 'delivery_method' => $o['delivery_method'],
            'subtotal' => $o['subtotal'], 'discount_total' => $o['discount_total'], 'tax_total' => $o['tax_total'], 'shipping_charge' => $o['shipping_charge'], 'total' => $o['total'],
            'payment_status' => $o['payment_status'], 'amount_paid' => $o['amount_paid'], 'balance_due' => $o['balance_due'], 'line_count' => (int) $o['line_count']];
}

function present_order_line(array $l): array
{
    return ['line_id' => (int) $l['line_id'], 'line_no' => (int) $l['line_no'], 'variant_id' => (int) $l['variant_id'], 'sku' => $l['sku'], 'product_name' => $l['product_name'], 'size_name' => $l['size_name'],
            'qty' => (int) $l['qty'], 'unit_price' => $l['unit_price'], 'discount' => $l['discount'], 'line_total' => $l['line_total'], 'fulfilment_kind' => $l['fulfilment_kind'],
            'location_id' => $l['location_id'], 'location_name' => $l['location_name'] ?? null, 'listing_variant_id' => $l['listing_variant_id'], 'source_id' => $l['source_id'], 'source_name' => $l['source_name'],
            'offer_cost' => $l['cost_withheld'] ? null : $l['offer_cost'], 'cost_withheld' => (bool) $l['cost_withheld'], 'offer_lead_time_days' => $l['offer_lead_time_days'], 'purchase_order_line_id' => $l['purchase_order_line_id'],
            'qty_allocated' => (int) $l['qty_allocated'], 'qty_shipped' => (int) $l['qty_shipped'], 'qty_returned' => (int) $l['qty_returned'], 'status' => $l['status'], 'serials' => $l['serials'], 'notes' => $l['notes']];
}

function present_order(array $o): array
{
    $ship = ['ship_to_name' => $o['ship_to_name'], 'ship_to_address1' => $o['ship_to_address1'], 'ship_to_address2' => $o['ship_to_address2'], 'ship_to_city' => $o['ship_to_city'],
             'ship_to_region' => $o['ship_to_region'], 'ship_to_postal' => $o['ship_to_postal'], 'ship_to_country' => $o['ship_to_country'], 'ship_to_phone' => $o['ship_to_phone'], 'ship_to_notes' => $o['ship_to_notes']];
    return present_order_row($o) + $ship + ['origin' => $o['origin'], 'tax_rate_id' => $o['tax_rate_id'], 'customer_reference' => $o['customer_reference'], 'notes' => $o['notes'], 'confirmed_at' => json_ts($o['confirmed_at']),
        'closed_at' => json_ts($o['closed_at']), 'cancelled_at' => json_ts($o['cancelled_at']), 'cancel_reason' => $o['cancel_reason'],
        'lines' => array_map('present_order_line', $o['lines']),
        'payments' => array_map(static fn (array $p): array => ['payment_id' => (int) $p['payment_id'], 'kind' => $p['kind'], 'amount' => $p['amount'], 'method' => $p['method'], 'reference' => $p['reference'],
            'taken_by_name' => $p['taken_by_name'], 'taken_at' => json_ts($p['taken_at']), 'note' => $p['note']], $o['payments']),
        'shipments' => array_map('present_shipment', array_map(static fn (array $s): array => $s + ['order_number' => $o['number'], 'customer_name' => $o['customer_name']], $o['shipments'])),
        'dropships' => array_map(static fn (array $p): array => ['purchase_order_id' => (int) $p['purchase_order_id'], 'number' => $p['number'], 'supplier_name' => $p['supplier_name'], 'status' => $p['status'],
            'expected_on' => $p['expected_on'], 'acknowledged_at' => json_ts($p['acknowledged_at']), 'lines' => array_map(static fn (array $l): array => ['line_no' => (int) $l['line_no'], 'sku' => $l['sku'], 'status' => $l['status'],
            'tracking_carrier' => $l['tracking_carrier'], 'tracking_number' => $l['tracking_number']], $p['lines'])], $o['dropships']),
        'returns' => $o['returns'], 'link' => $o['link'] === null ? null : ['is_live' => $o['link']['is_live'], 'expires_at' => json_ts($o['link']['expires_at']), 'rotated_at' => json_ts($o['link']['rotated_at']),
            'view_count' => $o['link']['view_count'], 'last_used_at' => json_ts($o['link']['last_used_at'])],
        'note_count' => $o['note_count'], 'attachment_count' => $o['attachment_count'], 'created_at' => json_ts($o['created_at']), 'updated_at' => json_ts($o['updated_at'])];
}

/**
 * ONE reader of a line's fulfilment: either the picker's `fulfilment` ("stock:12", "pickup:12", "dropship:34", "backorder" with `backorder_location`) or the manifest's
 * `fulfilment_kind` + `location` / `listing_variant`. Returns ['kind', 'location_id', 'listing_variant_id'] with nulls for what does not apply, or ['error' => sentence]
 * for a choice that does not make sense; ['kind' => null] when the row names none (the caller decides: a form's default, the recommendation).
 */
function order_fulfilment_from_row(array $row): array
{
    $none = ['kind' => null, 'location_id' => null, 'listing_variant_id' => null];
    $f = trim((string) ($row['fulfilment'] ?? ''));
    $kind = trim((string) ($row['fulfilment_kind'] ?? ''));
    $loc = trim((string) ($row['location'] ?? ''));
    $lv = trim((string) ($row['listing_variant'] ?? ''));
    if ($f !== '') {
        [$kind, $id] = array_pad(explode(':', $f, 2), 2, '');
        if ($kind === 'backorder') { $loc = trim((string) ($row['backorder_location'] ?? '')); }
        elseif ($kind === 'dropship') { $lv = $id; }
        else { $loc = $id; }
    } elseif ($kind === '') {
        return $none;
    }
    if (!isset(FULFILMENT_KINDS[$kind])) { return ['error' => 'Fulfilment is stock, dropship, backorder or pickup.']; }
    $locId = ctype_digit($loc) && $loc !== '' ? (int) $loc : null;
    $lvId = ctype_digit($lv) && $lv !== '' ? (int) $lv : null;
    if ($loc !== '' && $locId === null) { return ['error' => 'Choose the location from the list.']; }
    if ($lv !== '' && $lvId === null) { return ['error' => 'Choose the offer from the list.']; }
    return match ($kind) {
        'dropship' => $lvId === null ? ['error' => 'A drop-ship line names the supplier offer it is sold against.'] : ['kind' => 'dropship', 'location_id' => null, 'listing_variant_id' => $lvId],
        'stock', 'pickup' => $locId === null ? ['error' => 'A ' . ($kind === 'stock' ? 'stock' : 'pickup') . ' line names the location it is filled from.'] : ['kind' => $kind, 'location_id' => $locId, 'listing_variant_id' => null],
        default => ['kind' => 'backorder', 'location_id' => $locId, 'listing_variant_id' => null],
    };
}

/**
 * A line's fields from one row (the form's lines[n], an agent's JSON object, or the request itself): variant (an id, a SKU or a code), qty, unit_price, discount, notes and the
 * fulfilment above. Returns the raw reading: ['variant' => string, 'qty' => ?int, 'unit_price' => ?string, 'discount' => string, 'notes' => ?string, 'fulfilment' => [...], 'errors' => [field => sentence]].
 */
function order_line_from_request(array $row): array
{
    $errors = [];
    $variant = trim((string) ($row['variant'] ?? ''));
    if ($variant === '') { $variant = trim((string) ($row['variant_code'] ?? $row['sku'] ?? '')); }
    $qtyRaw = trim((string) ($row['qty'] ?? ''));
    $qty = null;
    if ($qtyRaw !== '') {
        if (filter_var($qtyRaw, FILTER_VALIDATE_INT) === false || (int) $qtyRaw < 1 || (int) $qtyRaw > 9999) { $errors['qty'] = 'The quantity is a whole number from 1 to 9999.'; } else { $qty = (int) $qtyRaw; }
    }
    $money = static function (string $k, string $label) use ($row, &$errors): ?string {
        $v = trim((string) ($row[$k] ?? ''));
        if ($v === '') { return null; }
        $n = preg_replace('/[^0-9.\-]/', '', $v);
        if (!is_numeric($n) || (float) $n < 0 || (float) $n > 99999999) { $errors[$k] = $label . ' is an amount of 0 or more.'; return null; }
        return number_format((float) $n, 2, '.', '');
    };
    $price = $money('unit_price', 'The unit price');
    $discount = $money('discount', 'The discount');
    $notes = trim((string) ($row['notes'] ?? ''));
    if (mb_strlen($notes) > 200) { $errors['notes'] = "A line's note is up to 200 characters."; }
    $ful = order_fulfilment_from_row($row);
    if (isset($ful['error'])) { $errors['fulfilment'] = $ful['error']; $ful = ['kind' => null, 'location_id' => null, 'listing_variant_id' => null]; }
    return ['variant' => $variant, 'qty' => $qty, 'unit_price' => $price, 'discount' => $discount ?? '0.00', 'discount_given' => $discount !== null, 'notes' => $notes === '' ? null : $notes, 'fulfilment' => $ful, 'errors' => $errors];
}

const ORDER_NOTICES = [
    'created' => ['success', 'The quote is written.'], 'saved' => ['success', 'Saved.'], 'confirmed' => ['success', 'Confirmed — the stock is held and the drop-ship purchase orders are drafted.'],
    'payment' => ['success', 'The payment is recorded.'], 'refund' => ['success', 'The refund is recorded.'], 'shipped' => ['success', 'Shipped.'], 'delivered' => ['success', 'Delivered.'],
    'closed' => ['success', 'The order is closed.'], 'cancelled' => ['success', 'The order is cancelled.'], 'sent' => ['success', 'Sent to the customer, with their link.'], 'notified' => ['success', 'The customer is told.'],
    'link_rotated' => ['success', 'The old link stopped. A new one goes out with the next send.'], 'line_cancelled' => ['success', 'The line is cancelled.'], 'line_saved' => ['success', 'The line is saved.'],
];

/** A purchase order's status chip (slice 6 owns the vocabulary — purchasing.md "Status vocabulary"; the order page, today's drop-ships and the purchase-order screens show it). */
function po_status_chip(string $status, string $id = ''): string
{
    $c = ['draft' => 'secondary', 'sent' => 'info', 'acknowledged' => 'primary', 'partial' => 'warning', 'received' => 'success', 'closed' => 'dark', 'closed_short' => 'dark', 'cancelled' => 'danger'][$status] ?? 'secondary';
    $w = ['partial' => 'Partly received', 'closed_short' => 'Closed short'][$status] ?? ucfirst(str_replace('_', ' ', $status));
    return '<span class="badge bg-soft-' . $c . ' text-' . $c . '"' . ($id !== '' ? ' id="' . e($id) . '"' : '') . '>' . e($w) . '</span>';
}

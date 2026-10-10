<?php
declare(strict_types=1);

/**
 * Purchase orders' presenters (purchasing.md "Status vocabulary"): the JSON shapes (whitelists over the view rows), the chips, the supplier-side words, and po_line_from_request() — the ONE reader of a
 * purchase-order line from the form's lines[n][…], an agent's JSON object or the request itself.
 */

function po_kind_chip(string $kind): string
{
    [$icon, $c] = $kind === 'dropship' ? ['feather-truck', 'info'] : ['feather-box', 'secondary'];
    return '<span class="badge bg-soft-' . $c . ' text-' . ($c === 'secondary' ? 'dark' : $c) . '"><i class="' . $icon . ' me-1"></i>' . e(PO_KINDS[$kind] ?? $kind) . '</span>';
}

function po_line_status_chip(string $status): string
{
    $c = ['open' => 'secondary', 'acknowledged' => 'info', 'declined' => 'danger', 'partial' => 'warning', 'shipped' => 'primary', 'received' => 'success', 'closed_short' => 'dark', 'cancelled' => 'dark'][$status] ?? 'secondary';
    return '<span class="badge bg-soft-' . $c . ' text-' . $c . '">' . e(PO_LINE_STATUSES[$status] ?? $status) . '</span>';
}

function po_source_chip(string $source): string
{
    [$w, $c] = ['portal' => ['by the supplier', 'info'], 'manual' => ['by hand', 'secondary'], 'email' => ['by email', 'secondary'], 'phone' => ['by phone', 'secondary']][$source] ?? [$source, 'secondary'];
    return '<span class="badge bg-soft-' . $c . ' text-' . ($c === 'secondary' ? 'dark' : $c) . '">' . e($w) . '</span>';
}

function po_overdue_badge(array $o): string
{
    return !empty($o['overdue']) ? ' <span class="badge bg-soft-danger text-danger">overdue</span>' : '';
}

function po_awaiting_badge(array $o): string
{
    return !empty($o['awaiting_ack']) ? ' <span class="badge bg-soft-warning text-warning">awaiting acknowledgment</span>' : '';
}

function present_po_row(array $o): array
{
    return ['purchase_order_id' => (int) $o['purchase_order_id'], 'number' => $o['number'], 'supplier_id' => (int) $o['supplier_id'], 'supplier_name' => $o['supplier_name'], 'kind' => $o['kind'],
            'sales_order_id' => $o['sales_order_id'], 'sales_order_number' => $o['sales_order_number'], 'customer_name' => $o['customer_name'] ?? null, 'status' => $o['status'], 'ordered_on' => $o['ordered_on'],
            'expected_on' => $o['expected_on'], 'supplier_order_ref' => $o['supplier_order_ref'], 'sent_via' => $o['sent_via'], 'sent_at' => json_ts($o['sent_at']), 'acknowledged_at' => json_ts($o['acknowledged_at']),
            'subtotal' => $o['subtotal'], 'shipping_cost' => $o['shipping_cost'], 'total' => $o['total'], 'cost_withheld' => (bool) $o['cost_withheld'], 'line_count' => (int) $o['line_count'],
            'has_tracking' => (bool) $o['has_tracking'], 'awaiting_ack' => (bool) $o['awaiting_ack'], 'overdue' => (bool) $o['overdue'], 'location_id' => $o['location_id']];
}

function present_po_line(array $l): array
{
    return ['purchase_order_line_id' => (int) $l['purchase_order_line_id'], 'line_no' => (int) $l['line_no'], 'variant_id' => (int) $l['variant_id'], 'sku' => $l['sku'], 'product_name' => $l['product_name'], 'size_name' => $l['size_name'],
            'supplier_sku' => $l['supplier_sku'], 'listing_variant_id' => $l['listing_variant_id'], 'qty_ordered' => (int) $l['qty_ordered'], 'qty_received' => (int) $l['qty_received'],
            'unit_cost' => $l['cost_withheld'] ? null : $l['unit_cost'], 'line_cost' => $l['cost_withheld'] ? null : $l['line_cost'], 'cost_withheld' => (bool) $l['cost_withheld'], 'expected_on' => $l['expected_on'],
            'sales_order_line_id' => $l['sales_order_line_id'], 'status' => $l['status'], 'supplier_note' => $l['supplier_note'], 'tracking_carrier' => $l['tracking_carrier'], 'tracking_number' => $l['tracking_number'],
            'shipped_at' => json_ts($l['shipped_at']), 'offer_source' => $l['offer_source'], 'offer_price' => $l['offer_price'], 'offer_availability' => $l['offer_availability']];
}

function present_po_event(array $e): array
{
    return ['event_id' => (int) $e['event_id'], 'line_no' => $e['line_no'] === null ? null : (int) $e['line_no'], 'kind' => $e['kind'], 'source' => $e['source'], 'supplier_order_ref' => $e['supplier_order_ref'],
            'expected_on' => $e['expected_on'], 'carrier' => $e['carrier'], 'tracking_number' => $e['tracking_number'], 'shipped_at' => json_ts($e['shipped_at']), 'reason' => $e['reason'], 'note' => $e['note'],
            'by' => $e['member_name'], 'created_at' => json_ts($e['created_at'])];
}

function present_purchase_order(array $o): array
{
    return present_po_row($o) + ['ship_to_kind' => $o['ship_to_kind'], 'location_name' => $o['location_name'], 'ship_to_city' => $o['ship_to_city'], 'ship_to_region' => $o['ship_to_region'],
        'notes' => $o['notes'], 'internal_notes' => $o['internal_notes'], 'closed_at' => json_ts($o['closed_at']), 'cancelled_at' => json_ts($o['cancelled_at']), 'cancel_reason' => $o['cancel_reason'],
        'created_at' => json_ts($o['created_at']), 'updated_at' => json_ts($o['updated_at']), 'shows_phone' => (bool) $o['shows_phone'],
        'ship_to' => $o['ship_to'], 'lines' => array_map('present_po_line', $o['lines']), 'events' => array_map('present_po_event', $o['events']),
        'receipts' => array_map(static fn (array $r): array => ['goods_receipt_id' => (int) $r['goods_receipt_id'], 'number' => $r['number'], 'status' => $r['status'], 'received_on' => $r['received_on']], $o['receipts']),
        'link' => $o['link'] === null ? null : ['is_live' => $o['link']['is_live'], 'expires_at' => json_ts($o['link']['expires_at']), 'rotated_at' => json_ts($o['link']['rotated_at']), 'view_count' => $o['link']['view_count'],
            'last_used_at' => json_ts($o['link']['last_used_at'])], 'supplier' => $o['supplier'] === null ? null : ['supplier_id' => $o['supplier']['supplier_id'], 'name' => $o['supplier']['name'], 'order_method' => $o['supplier']['order_method'],
            'account_number' => $o['supplier']['account_number'], 'order_email_set' => (string) ($o['supplier']['order_email'] ?? $o['supplier']['email'] ?? '') !== ''],
        'note_count' => $o['note_count'], 'attachment_count' => $o['attachment_count']];
}

/**
 * A line from one row (the form's lines[n], an agent's JSON object, or the request itself): variant (an id, a SKU or a code), qty, unit_cost, supplier_sku, listing_variant, expected_on.
 * Returns the raw reading: ['variant' => string, 'qty' => ?int, 'unit_cost' => ?string, 'supplier_sku' => ?string, 'listing_variant' => ?int, 'expected_on' => ?string, 'errors' => [field => sentence]].
 */
function po_line_from_request(array $row): array
{
    $errors = [];
    $variant = trim((string) ($row['variant'] ?? ''));
    if ($variant === '') { $variant = trim((string) ($row['variant_code'] ?? $row['sku'] ?? '')); }
    $qtyRaw = trim((string) ($row['qty'] ?? $row['qty_ordered'] ?? ''));
    $qty = null;
    if ($qtyRaw !== '') {
        if (filter_var($qtyRaw, FILTER_VALIDATE_INT) === false || (int) $qtyRaw < 1 || (int) $qtyRaw > 99999) { $errors['qty'] = 'The quantity is a whole number from 1 to 99999.'; } else { $qty = (int) $qtyRaw; }
    }
    $cost = null;
    $c = trim((string) ($row['unit_cost'] ?? ''));
    if ($c !== '') {
        $n = preg_replace('/[^0-9.\-]/', '', $c);
        if (!is_numeric($n) || (float) $n < 0 || (float) $n > 99999999) { $errors['unit_cost'] = 'The unit cost is an amount of 0 or more.'; } else { $cost = number_format((float) $n, 2, '.', ''); }
    }
    $ssku = trim((string) ($row['supplier_sku'] ?? ''));
    if (mb_strlen($ssku) > 60) { $errors['supplier_sku'] = "The supplier's SKU is up to 60 characters."; }
    $lv = trim((string) ($row['listing_variant'] ?? $row['listing_variant_id'] ?? ''));
    $lvId = null;
    if ($lv !== '' && $lv !== '0') {
        if (!ctype_digit($lv)) { $errors['listing_variant'] = 'Choose the offer from the list.'; } else { $lvId = (int) $lv; }
    }
    $exp = null;
    $e = trim((string) ($row['expected_on'] ?? ''));
    if ($e !== '') {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $e);
        if ($d === false || $d->format('Y-m-d') !== $e) { $errors['expected_on'] = 'The expected date is a date.'; } else { $exp = $e; }
    }
    return ['variant' => $variant, 'qty' => $qty, 'unit_cost' => $cost, 'supplier_sku' => $ssku === '' ? null : $ssku, 'listing_variant' => $lvId, 'expected_on' => $exp,
            'cost_given' => $c !== '', 'errors' => $errors];
}

const PO_NOTICES = [
    'created' => ['success', 'The purchase order is drafted.'], 'saved' => ['success', 'Saved.'], 'sent' => ['success', 'Sent to the supplier, with their link.'], 'sent_phone' => ['success', 'Recorded as sent by phone.'],
    'placed' => ['success', 'Recorded as placed with the supplier.'], 'acknowledged' => ['success', 'Acknowledged.'], 'declined' => ['success', 'The line is declined — the customer\'s line is open again.'],
    'tracking' => ['success', 'Tracking is recorded.'], 'received' => ['success', 'The receipt is drafted — check the quantities and post it.'], 'closed' => ['success', 'The purchase order is closed.'],
    'cancelled' => ['success', 'The purchase order is cancelled.'], 'link_rotated' => ['success', 'The old link stopped. A new one goes out with the next send.'], 'link_mailed' => ['success', 'The old link stopped. The new one is on its way to the supplier.'],
    'line_saved' => ['success', 'The line is saved.'], 'line_removed' => ['success', 'The line is removed.'], 'messaged' => ['success', 'The message is sent.'], 'dropships' => ['success', 'The drop-ship purchase orders are drafted.'],
];

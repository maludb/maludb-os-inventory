<?php
declare(strict_types=1);

/** Returns as chips and as JSON (whitelists — a customer's name is on the row, never their contact details). */

function return_status_chip(string $status, string $id = ''): string
{
    $c = ['requested' => 'warning', 'approved' => 'info', 'received' => 'success', 'closed' => 'secondary', 'denied' => 'dark'][$status] ?? 'secondary';
    return '<span class="badge bg-soft-' . $c . ' text-' . $c . '"' . ($id !== '' ? ' id="' . e($id) . '"' : '') . '>' . e(RETURN_STATUSES[$status] ?? $status) . '</span>';
}

function disposition_chip(string $d): string
{
    $c = ['restock' => 'success', 'floor_model' => 'info', 'dispose' => 'dark', 'return_to_supplier' => 'warning', 'donate' => 'secondary'][$d] ?? 'secondary';
    return '<span class="badge bg-soft-' . $c . ' text-' . $c . '">' . e(RETURN_DISPOSITIONS[$d] ?? $d) . '</span>';
}

function present_return_row(array $r): array
{
    return ['return_id' => $r['return_id'], 'number' => $r['number'], 'status' => $r['status'], 'sales_order_id' => $r['sales_order_id'], 'order_number' => $r['order_number'], 'customer_id' => $r['customer_id'], 'customer_name' => $r['customer_name'],
            'method' => $r['method'], 'scheduled_on' => $r['scheduled_on'], 'location_id' => $r['location_id'], 'location' => $r['location_name'] ?? null, 'line_count' => $r['line_count'] ?? null,
            'dispositions' => $r['dispositions'] ?? [], 'refund_amount' => $r['refund_amount'], 'restocking_fee' => $r['restocking_fee'], 'created_at' => json_ts($r['created_at'])];
}

function present_return(array $r, array $lines): array
{
    return present_return_row($r) + ['notes' => $r['notes'], 'requested_by' => $r['requested_by'], 'requested_by_name' => $r['requested_by_name'] ?? null, 'approved_at' => json_ts($r['approved_at']), 'approved_by' => $r['approved_by'],
        'received_at' => json_ts($r['received_at']), 'received_by' => $r['received_by'], 'closed_at' => json_ts($r['closed_at']), 'closed_by' => $r['closed_by'], 'denied_at' => json_ts($r['denied_at']), 'denied_by' => $r['denied_by'],
        'deny_reason' => $r['deny_reason'], 'lines' => array_map('present_return_line', $lines)];
}

function present_return_line(array $l): array
{
    return ['return_line_id' => $l['return_line_id'], 'sales_order_line_id' => $l['sales_order_line_id'], 'line_no' => $l['line_no'], 'variant_id' => $l['variant_id'], 'sku' => $l['sku'], 'product_name' => $l['product_name'], 'size_name' => $l['size_name'],
            'qty' => $l['qty'], 'qty_received' => $l['qty_received'], 'reason_code' => $l['reason_code'], 'reason' => $l['reason_name'], 'disposition' => $l['disposition'], 'location_id' => $l['location_id'], 'location' => $l['location_name'], 'condition_note' => $l['condition_note']];
}

const RETURN_NOTICES = [
    'requested' => ['success', 'The return is requested. The Buyer decides whether to take it.'], 'saved' => ['success', 'Saved.'], 'approved' => ['success', 'The return is approved.'], 'denied' => ['secondary', 'The return is denied.'],
    'received' => ['success', 'The return is received — the stock movements are written.'], 'closed' => ['success', 'The return is closed. The refund is recorded here; record the money on the order.'],
    'disposition' => ['success', 'The disposition is saved.'], 'line_added' => ['success', 'The line is on the return.'], 'line_removed' => ['success', 'The line is off the return.'],
];

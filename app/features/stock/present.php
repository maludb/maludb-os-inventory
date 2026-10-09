<?php
declare(strict_types=1);

/** The ledger's JSON shapes and chips (stock.md "Status vocabulary"). Cost arrives already walled by the views (null = withheld). */

function stock_chip(string $label, string $colour, string $id = ''): string
{
    return '<span class="badge bg-soft-' . $colour . ' text-' . $colour . '"' . ($id !== '' ? ' id="' . e($id) . '"' : '') . '>' . e($label) . '</span>';
}

function doc_status_chip(string $status, string $id = ''): string
{
    $c = ['draft' => 'secondary', 'posted' => 'success', 'cancelled' => 'dark', 'in_transit' => 'info', 'received' => 'success', 'open' => 'warning'][$status] ?? 'secondary';
    $w = DOC_STATUSES[$status] ?? TRANSFER_STATUSES[$status] ?? COUNT_STATUSES[$status] ?? $status;
    return stock_chip($w, $c, $id);
}

function txn_type_chip(string $type): string
{
    $c = ['receipt' => 'success', 'issue' => 'danger', 'sale' => 'danger', 'transfer_out' => 'danger', 'transfer_in' => 'success', 'return' => 'success', 'adjustment' => 'warning',
          'count_correction' => 'info', 'floor_model_in' => 'secondary', 'floor_model_out' => 'secondary', 'reversal' => 'dark'][$type] ?? 'secondary';
    return stock_chip(TXN_TYPES[$type] ?? $type, $c);
}

function discrepancy_chip(string $kind): string
{
    if ($kind === 'none') { return ''; }
    $c = in_array($kind, ['over', 'substitute'], true) ? 'warning' : 'danger';
    return stock_chip(DISCREPANCY_KINDS[$kind] ?? $kind, $c);
}

/** A signed quantity: red when negative. */
function signed_qty(int|string $q): string
{
    $q = (int) $q;
    return '<span class="' . ($q < 0 ? 'text-danger' : '') . '">' . ($q > 0 ? '+' : '') . number_format($q) . '</span>';
}

/** A quantity that may be negative (red), unsigned when positive. */
function signed_qty_plain(int $q): string
{
    return $q < 0 ? '<span class="text-danger">' . number_format($q) . '</span>' : number_format($q);
}

/** A walled cost cell: the amount, or "—" titled "cost withheld". */
function cost_cell(?string $amount, bool $sees): string
{
    if (!$sees) { return '<span class="text-muted" title="cost withheld">—</span>'; }
    return $amount === null ? '<span class="text-muted">—</span>' : e(number_format((float) $amount, 2));
}

function present_level(array $r): array
{
    return ['variant_id' => (int) $r['variant_id'], 'sku' => $r['sku'], 'product_id' => (int) $r['product_id'], 'product_name' => $r['product_name'], 'size_name' => $r['size_name'],
            'location_id' => (int) $r['location_id'], 'location_name' => $r['location_name'], 'qty_on_hand' => (int) $r['qty_on_hand'], 'qty_allocated' => (int) $r['qty_allocated'],
            'qty_floor_model' => (int) $r['qty_floor_model'], 'qty_available' => (int) $r['qty_available'], 'below_reorder' => (bool) $r['below_reorder']];
}

function present_movement(array $r): array
{
    return ['transaction_id' => (int) $r['transaction_id'], 'txn_type' => $r['txn_type'], 'affects' => $r['affects'], 'variant_id' => (int) $r['variant_id'], 'sku' => $r['sku'],
            'location_id' => (int) $r['location_id'], 'location_name' => $r['location_name'], 'qty' => (int) $r['qty'], 'unit_cost' => $r['unit_cost'],
            'counterparty_kind' => $r['counterparty_kind'], 'counterparty_name' => $r['counterparty_name'], 'reason_code' => $r['reason_code'],
            'reference_kind' => $r['reference_kind'], 'reference_id' => (int) $r['reference_id'], 'document_number' => $r['document_number'], 'document_url' => movement_document_url($r),
            'reverses_id' => $r['reverses_id'] === null ? null : (int) $r['reverses_id'], 'reversed_by' => $r['reversed_by'], 'note' => $r['note'], 'occurred_at' => json_ts($r['occurred_at']), 'actor_name' => $r['actor_name']];
}

function present_receipt(array $r): array
{
    return ['goods_receipt_id' => (int) $r['goods_receipt_id'], 'number' => $r['number'], 'status' => $r['status'], 'supplier_id' => $r['supplier_id'] === null ? null : (int) $r['supplier_id'],
            'supplier_name' => $r['supplier_name'], 'purchase_order_id' => $r['purchase_order_id'] === null ? null : (int) $r['purchase_order_id'], 'purchase_order_number' => $r['purchase_order_number'],
            'location_id' => (int) $r['location_id'], 'location_name' => $r['location_name'], 'delivery_note_ref' => $r['delivery_note_ref'], 'received_on' => $r['received_on'], 'notes' => $r['notes'],
            'line_count' => (int) $r['line_count'], 'units' => (int) $r['units'], 'posted_by_name' => $r['posted_by_name'], 'posted_at' => json_ts($r['posted_at'])];
}

function present_receipt_line(array $l): array
{
    return ['goods_receipt_line_id' => (int) $l['goods_receipt_line_id'], 'line_no' => (int) $l['line_no'], 'variant_id' => (int) $l['variant_id'], 'sku' => $l['sku'], 'product_name' => $l['product_name'],
            'size_name' => $l['size_name'], 'qty' => (int) $l['qty'], 'unit_cost' => $l['unit_cost'], 'purchase_order_line_id' => $l['purchase_order_line_id'] === null ? null : (int) $l['purchase_order_line_id'],
            'putaway_location_id' => $l['putaway_location_id'] === null ? null : (int) $l['putaway_location_id'], 'putaway_location_name' => $l['putaway_location_name'],
            'discrepancy_kind' => $l['discrepancy_kind'], 'discrepancy_note' => $l['discrepancy_note']];
}

function present_adjustment(array $a): array
{
    return ['adjustment_id' => (int) $a['adjustment_id'], 'number' => $a['number'], 'status' => $a['status'], 'location_id' => (int) $a['location_id'], 'location_name' => $a['location_name'],
            'reason_code' => $a['reason_code'], 'reason_name' => $a['reason_name'], 'notes' => $a['notes'], 'line_count' => (int) $a['line_count'], 'units_delta' => (int) $a['units_delta'],
            'posted_by_name' => $a['posted_by_name'], 'posted_at' => json_ts($a['posted_at'])];
}

function present_adjustment_line(array $l): array
{
    return ['adjustment_line_id' => (int) $l['adjustment_line_id'], 'line_no' => (int) $l['line_no'], 'variant_id' => (int) $l['variant_id'], 'sku' => $l['sku'], 'product_name' => $l['product_name'],
            'size_name' => $l['size_name'], 'qty_delta' => (int) $l['qty_delta'], 'unit_cost' => $l['unit_cost'], 'note' => $l['note']];
}

function present_transfer(array $t): array
{
    return ['transfer_id' => (int) $t['transfer_id'], 'number' => $t['number'], 'status' => $t['status'], 'from_location_id' => (int) $t['from_location_id'], 'from_location' => $t['from_location'],
            'to_location_id' => (int) $t['to_location_id'], 'to_location' => $t['to_location'], 'notes' => $t['notes'], 'line_count' => (int) $t['line_count'], 'units' => (int) $t['units'],
            'units_received' => (int) $t['units_received'], 'shipped_by_name' => $t['shipped_by_name'], 'shipped_at' => json_ts($t['shipped_at']), 'received_by_name' => $t['received_by_name'], 'received_at' => json_ts($t['received_at'])];
}

function present_transfer_line(array $l): array
{
    return ['transfer_line_id' => (int) $l['transfer_line_id'], 'line_no' => (int) $l['line_no'], 'variant_id' => (int) $l['variant_id'], 'sku' => $l['sku'], 'product_name' => $l['product_name'],
            'size_name' => $l['size_name'], 'qty' => (int) $l['qty'], 'qty_received' => (int) $l['qty_received']];
}

function present_count(array $c): array
{
    return ['count_id' => (int) $c['count_id'], 'number' => $c['number'], 'status' => $c['status'], 'location_id' => (int) $c['location_id'], 'location_name' => $c['location_name'], 'notes' => $c['notes'],
            'line_count' => (int) $c['line_count'], 'lines_counted' => (int) $c['lines_counted'], 'lines_differing' => (int) $c['lines_differing'], 'started_by_name' => $c['started_by_name'],
            'started_at' => json_ts($c['started_at']), 'posted_by_name' => $c['posted_by_name'], 'posted_at' => json_ts($c['posted_at'])];
}

function present_count_line(array $l): array
{
    return ['count_line_id' => (int) $l['count_line_id'], 'variant_id' => (int) $l['variant_id'], 'sku' => $l['sku'], 'product_name' => $l['product_name'], 'size_name' => $l['size_name'],
            'system_qty' => (int) $l['system_qty'], 'counted_qty' => $l['counted_qty'] === null ? null : (int) $l['counted_qty'], 'difference' => $l['difference'] === null ? null : (int) $l['difference'],
            'counted_by_name' => $l['counted_by_name'], 'correction_transaction_id' => $l['correction_transaction_id'] === null ? null : (int) $l['correction_transaction_id'],
            'correction_qty' => $l['correction_qty'] === null ? null : (int) $l['correction_qty']];
}

/** The notices of the ledger's screens. */
const STOCK_NOTICES = [
    'created' => ['success', 'The draft is made — add its lines.'], 'saved' => ['success', 'Saved.'], 'line_added' => ['success', 'Line added.'], 'line_removed' => ['success', 'Line removed.'],
    'posted' => ['success', 'Posted — the movements are on the ledger.'], 'cancelled' => ['success', 'Cancelled — nothing was posted.'], 'sent' => ['success', 'Sent — the stock is in transit on the document.'],
    'received' => ['success', 'Received.'], 'started' => ['success', 'The count is open — scan or type what is on the shelf.'], 'counted' => ['success', 'Counted.'],
    'floor' => ['success', 'The floor model is recorded.'], 'reversed' => ['success', 'Reversed — the opposite movement is posted and linked.'],
    'archived' => ['success', 'Archived.'], 'restored' => ['success', 'Restored.'],
];

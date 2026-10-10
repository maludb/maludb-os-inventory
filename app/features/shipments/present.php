<?php
declare(strict_types=1);

/** Shipments' JSON and chips. */

function present_shipment(array $s): array
{
    return ['shipment_id' => (int) $s['shipment_id'], 'sales_order_id' => (int) $s['sales_order_id'], 'order_number' => $s['order_number'] ?? null, 'customer_name' => $s['customer_name'] ?? null,
            'kind' => $s['kind'], 'carrier' => $s['carrier'], 'tracking_number' => $s['tracking_number'], 'tracking_url' => $s['tracking_url'], 'shipped_at' => json_ts($s['shipped_at']),
            'delivered_at' => json_ts($s['delivered_at']), 'note' => $s['note'] ?? null,
            'lines' => array_map(static fn (array $l): array => ['line_no' => (int) $l['line_no'], 'sku' => $l['sku'], 'product_name' => $l['product_name'], 'size_name' => $l['size_name'], 'qty' => (int) $l['qty'], 'serials' => $l['serials']], $s['lines'] ?? [])];
}

function shipment_kind_chip(string $kind): string
{
    return '<span class="badge bg-soft-secondary text-dark">' . e(SHIPMENT_KINDS[$kind] ?? $kind) . '</span>';
}

function shipment_state_chip(array $s): string
{
    return $s['delivered_at'] !== null ? '<span class="badge bg-soft-success text-success">Delivered</span>' : '<span class="badge bg-soft-info text-info">On its way</span>';
}

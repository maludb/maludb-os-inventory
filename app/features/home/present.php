<?php
declare(strict_types=1);

/** The home's JSON (whitelists): the same keys as home_summary(), a region the person has no right to is null. */

function present_home(array $s): array
{
    $nz = static fn ($v): ?int => $v === null ? null : (int) $v;
    $note = null;
    if ($s['note'] !== null) {
        $note = ['date' => $s['note']['date'], 'headings' => []];
        foreach ($s['note']['headings'] as $k => $h) {
            $note['headings'][$k] = ['count' => $nz($h['count']), 'withheld' => $h['withheld']] + ($k === 'purchase_orders' ? ['awaiting_ack' => $nz($h['awaiting_ack'] ?? null), 'overdue' => $nz($h['overdue'] ?? null), 'untracked' => $nz($h['untracked'] ?? null)] : []);
        }
    }
    return [
        'note' => $note,
        'at_risk' => $s['at_risk'] === null ? null : ['count' => $s['at_risk']['count'], 'rows' => array_map(static fn (array $r): array => ['sales_order_id' => (int) $r['sales_order_id'], 'order_number' => $r['order_number'], 'line_id' => (int) $r['line_id'],
            'line_no' => (int) $r['line_no'], 'sku' => $r['sku'], 'customer_name' => $r['customer_name'], 'promised_on' => $r['promised_on'], 'risk' => $r['risk'], 'detail' => $r['detail'], 'salesperson_member_id' => $nz($r['salesperson_member_id'])], $s['at_risk']['rows'])],
        'sources' => $s['sources'] === null ? null : ['count' => $s['sources']['count'], 'rows' => array_map(static fn (array $r): array => ['source_id' => (int) $r['source_id'], 'name' => $r['name'], 'connector' => $r['connector'], 'role' => $r['role'],
            'health' => $r['health'], 'last_pull_at' => json_ts($r['last_pull_at']), 'consecutive_failures' => (int) $r['consecutive_failures']], $s['sources']['rows'])],
        'unmatched' => $s['unmatched'] === null ? null : ['count' => $s['unmatched']['count'], 'rows' => array_map(static fn (array $r): array => ['listing_variant_id' => (int) $r['listing_variant_id'], 'listing_id' => (int) $r['listing_id'],
            'source_id' => (int) $r['source_id'], 'source' => $r['source_name'], 'title' => $r['title'], 'variant_title' => $r['variant_title'], 'sku' => $r['sku'], 'price' => $r['price'] === null ? null : (float) $r['price']], $s['unmatched']['rows'])],
        'po_ack' => $s['po_ack'] === null ? null : ['count' => $s['po_ack']['count'], 'rows' => array_map(static fn (array $r): array => ['purchase_order_id' => (int) $r['purchase_order_id'], 'number' => $r['number'], 'kind' => $r['kind'],
            'supplier' => $r['supplier_name'], 'sent_at' => json_ts($r['sent_at']), 'days_waiting' => $nz($r['days_waiting'])], $s['po_ack']['rows'])],
        'today' => $s['today'],
        'my_orders' => $s['my_orders'] === null ? null : ['count' => $s['my_orders']['count'], 'rows' => array_map(static fn (array $o): array => ['sales_order_id' => (int) $o['sales_order_id'], 'number' => $o['number'], 'customer' => $o['customer_name'],
            'status' => $o['status'], 'promised_on' => $o['promised_on'], 'next_step' => $o['next_step'], 'step' => $o['step'], 'days_late' => (int) $o['days_late']], $s['my_orders']['rows'])],
        'warehouse' => $s['warehouse'] === null ? null : ['to_receive' => ['count' => $s['warehouse']['to_receive']['count'],
            'receipts' => array_map(static fn (array $r): array => ['goods_receipt_id' => (int) $r['goods_receipt_id'], 'number' => $r['number'], 'supplier' => $r['supplier_name'], 'location' => $r['location_name']], $s['warehouse']['to_receive']['receipts']),
            'purchase_orders' => array_map(static fn (array $r): array => ['purchase_order_id' => (int) $r['purchase_order_id'], 'number' => $r['number'], 'supplier' => $r['supplier_name'], 'expected_on' => $r['expected_on']], $s['warehouse']['to_receive']['purchase_orders'])],
            'to_pick' => $s['warehouse']['to_pick'],
            'to_count' => array_map(static fn (array $r): array => ['count_id' => (int) $r['count_id'], 'number' => $r['number'], 'location' => $r['location_name']], $s['warehouse']['to_count'])],
        'admin' => $s['admin'],
        'bell' => array_map('present_notification', $s['bell']),
        'may' => $s['may'],
    ];
}

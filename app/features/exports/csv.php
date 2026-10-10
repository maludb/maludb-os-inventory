<?php
declare(strict_types=1);

/** An export document as CSV: a UTF-8 BOM, the columns as headed, money with two decimals, dates ISO. */

function export_amount(mixed $v): string
{
    return $v === null || $v === '' ? '' : number_format((float) $v, 2, '.', '');
}

/** The header and the rows of a document: [[heading…], [cell…]…]. */
function document_table(string $export, array $doc): array
{
    $amt = 'export_amount';
    switch ($export) {
        case 'sales_closed':
            $methods = [];
            foreach ($doc['orders'] as $o) { foreach (array_keys((array) ($o['payments_by_method'] ?? [])) as $m) { $methods[$m] = true; } }
            $methods = array_keys($methods);
            sort($methods);
            $head = ['Order', 'Ordered on', 'Closed at', 'Customer', 'Location', 'Delivery method', 'Line', 'SKU', 'Product', 'Size', 'Qty', 'Unit price', 'Discount', 'Line total', 'Fulfilment', 'Qty returned',
                     'Order subtotal', 'Order discount', 'Tax name', 'Tax rate', 'Tax', 'Shipping', 'Total', 'Amount paid', 'Balance due', 'Refunds', 'COGS'];
            foreach ($methods as $m) { $head[] = 'Paid by ' . $m; }
            $rows = [];
            foreach ($doc['orders'] as $o) {
                $tail = [$amt($o['subtotal']), $amt($o['discount_total']), $o['tax']['name'] ?? '', rtrim(rtrim(number_format((float) ($o['tax']['rate'] ?? 0), 4, '.', ''), '0'), '.') ?: '0', $amt($o['tax']['amount'] ?? null), $amt($o['shipping_charge']),
                         $amt($o['total']), $amt($o['amount_paid']), $amt($o['balance_due']), $amt($o['refunds']), $amt($o['cogs'])];
                foreach ($methods as $m) { $tail[] = $amt($o['payments_by_method'][$m] ?? null); }
                $lead = [$o['number'], $o['ordered_on'], $o['closed_at'] === null ? '' : substr((string) $o['closed_at'], 0, 19), $o['customer']['name'] ?? '', $o['location']['name'] ?? '', $o['delivery_method']];
                $lines = $o['lines'] ?: [null];
                foreach ($lines as $l) {
                    $rows[] = array_merge($lead, $l === null ? array_fill(0, 10, '') : [$l['line_no'], $l['sku'], $l['product_name'], $l['size'], $l['qty'], $amt($l['unit_price']), $amt($l['discount']), $amt($l['line_total']), $l['fulfilment_kind'], $l['qty_returned']], $tail);
                }
            }
            return [$head, $rows];
        case 'purchases_received':
            $head = ['Supplier', 'Account number', 'Terms', 'Kind', 'Document', 'Purchase order', 'Supplier ref', 'Received on / delivered at', 'Location', 'Line', 'SKU', 'Supplier SKU', 'Product', 'Size', 'Qty', 'Unit cost', 'Line cost', 'Discrepancy', 'Sales order'];
            $rows = [];
            foreach ($doc['suppliers'] as $s) {
                $lead = [$s['name'], $s['account_number'] ?? '', $s['terms'] ?? ''];
                foreach ($s['receipts'] as $d) {
                    foreach ($d['lines'] as $l) {
                        $rows[] = array_merge($lead, ['receipt', $d['number'], $d['purchase_order_number'] ?? '', $d['supplier_order_ref'] ?? '', $d['received_on'] ?? '', $d['location']['name'] ?? '', $l['line_no'], $l['sku'], $l['supplier_sku'] ?? '',
                            $l['product_name'] ?? '', $l['size'] ?? '', $l['qty'], $amt($l['unit_cost']), $amt($l['line_cost']), $l['discrepancy_kind'] ?? '', '']);
                    }
                }
                foreach ($s['dropships'] as $d) {
                    foreach ($d['lines'] as $l) {
                        $rows[] = array_merge($lead, ['dropship', $d['number'], $d['number'], $d['supplier_order_ref'] ?? '', isset($l['delivered_at']) ? substr((string) $l['delivered_at'], 0, 10) : '', '', $l['line_no'], $l['sku'], $l['supplier_sku'] ?? '',
                            '', '', $l['qty'], $amt($l['unit_cost']), $amt($l['line_cost']), '', $d['sales_order_number'] ?? '']);
                    }
                }
            }
            return [$head, $rows];
        case 'stock_valuation':
            $head = [ucfirst((string) $doc['by']), 'Units', 'Value', 'Variants'];
            $rows = [];
            foreach ($doc['rows'] as $r) { $rows[] = [$r['group_name'], $r['units'], $amt($r['value']), $r['variants']]; }
            $rows[] = ['Total', $doc['totals']['units'], $amt($doc['totals']['value']), ''];
            return [$head, $rows];
        case 'catalog':
            $head = ['Product', 'Brand', 'Type', 'Kind', 'Status', 'SKU', 'Size', 'GTIN', 'MPN', 'Other identifiers', 'Weight g', 'Length mm', 'Width mm', 'Height mm', 'Ships how', 'Retail', 'MAP', 'Cost', 'Reorder point', 'Reorder qty', 'Active', 'On hand', 'Available'];
            $rows = [];
            foreach ($doc['rows'] as $r) {
                $rows[] = [$r['product'], $r['brand'] ?? '', $r['type'], $r['kind'], $r['status'], $r['sku'], $r['size'] ?? '', $r['gtin'] ?? '', $r['mpn'] ?? '', $r['identifiers'], $r['weight_g'] ?? '', $r['length_mm'] ?? '', $r['width_mm'] ?? '', $r['height_mm'] ?? '',
                           $r['ships_how'] ?? '', $amt($r['retail_price']), $amt($r['map_price']), $amt($r['cost_price']), $r['reorder_point'] ?? '', $r['reorder_qty'] ?? '', $r['active'] ? 'yes' : 'no', $r['on_hand'] ?? '', $r['available'] ?? ''];
            }
            return [$head, $rows];
        case 'listings':
            $head = ['Source', 'Role', 'Listing', 'Variant', 'Size', 'SKU', 'Barcode', 'MPN', 'Price', 'Compare at', 'Currency', 'Cost', 'Availability', 'Qty', 'Lead time days', 'Ships how', 'Matched SKU', 'Match kind', 'First seen', 'Last seen', 'Removed at', 'URL'];
            $rows = [];
            $ts = static fn (?string $v): string => $v === null ? '' : (new DateTimeImmutable($v))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
            foreach ($doc['rows'] as $r) {
                $rows[] = [$r['source'], $r['role'], $r['listing_title'] ?? '', $r['variant_title'] ?? '', $r['size'] ?? '', $r['sku'] ?? '', $r['barcode'] ?? '', $r['mpn'] ?? '', $amt($r['price']), $amt($r['compare_at_price']), $r['currency'] ?? '', $amt($r['cost_price']),
                           $r['availability'] ?? '', $r['qty'] ?? '', $r['lead_time_days'] ?? '', $r['ships_how'] ?? '', $r['matched_sku'] ?? '', $r['match_kind'] ?? '', $ts($r['first_seen_at']), $ts($r['last_seen_at']), $ts($r['removed_at']), $r['url'] ?? ''];
            }
            return [$head, $rows];
    }
    throw new InvalidArgumentException('No such export.');
}

/** The whole CSV text of a document. */
function document_csv(string $export, array $doc): string
{
    [$head, $rows] = document_table($export, $doc);
    $out = fopen('php://temp', 'w+');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $head, ',', '"', '');
    foreach ($rows as $r) { fputcsv($out, array_map(static fn ($v): string => is_bool($v) ? ($v ? 'yes' : 'no') : (string) ($v ?? ''), $r), ',', '"', ''); }
    rewind($out);
    $text = (string) stream_get_contents($out);
    fclose($out);
    return $text;
}

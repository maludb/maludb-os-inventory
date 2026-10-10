<?php
declare(strict_types=1);
/**
 * /purchasing/new?supplier=&kind=&order=&reorder=&variant= and /purchasing/{id}/edit — screens `purchase-order-add`, `purchase-order-edit` (a draft only). A stock order: the supplier, the ship-to location, the lines (a pick list over
 * /find/pick, the cost and the offers loading from /purchasing/line-defaults; ?reorder=1 prefills the supplier's reorder candidates, ?variant= one row). A drop-ship (kind dropship + order) shows the sales order's open drop-ship lines
 * grouped by supplier and one Draft button — the database drafts them. purchasing.write; people only.
 */
require_once dirname(__DIR__, 2) . '/app/features/purchasing/handler.php';
require_right('purchasing.write');
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('purchase_order');
$cur = $id === null ? null : po_or_404($pdo, $id);
if ($cur !== null && $cur['status'] !== 'draft') { refuse(422, $cur['number'] . ' is ' . str_replace('_', ' ', $cur['status']) . ' — close or cancel it instead'); }
$screen = $cur === null ? 'purchase-order-add' : 'purchase-order-edit';
log_screen_view($pdo, $screen);
$pre = ['supplier' => request_integer('supplier'), 'kind' => request_string('kind'), 'order' => request_string('order'), 'reorder' => ($_GET['reorder'] ?? '') === '1', 'variant' => request_integer('variant'),
        'q' => mb_substr(request_string('q'), 0, 120)];
$kind = $cur['kind'] ?? ($pre['kind'] === 'dropship' || ($pre['kind'] === '' && $pre['order'] !== '') ? 'dropship' : 'stock');
$supplierId = $cur['supplier_id'] ?? $pre['supplier'];
$rows = [];
$dropships = null;
$so = null;
if ($cur === null && $kind === 'stock' && $supplierId !== null) {
    if ($pre['reorder']) {
        foreach (reorder_rows_for($pdo, (int) $supplierId) as $r) {
            $rows[] = ['variant_id' => (int) $r['variant_id'], 'sku' => $r['sku'], 'label' => $r['product_name'] . ($r['size_name'] ? ', ' . $r['size_name'] : ''), 'qty' => max(1, (int) $r['reorder_qty']),
                       'unit_cost' => $r['best_cost'] === null ? null : number_format((float) $r['best_cost'], 2, '.', ''), 'listing_variant_id' => $r['best_listing_variant_id'] === null ? null : (int) $r['best_listing_variant_id']];
        }
    }
    if ($pre['variant'] !== null) {
        $v = one_row($pdo, 'SELECT variant_id, sku, product_name, size_name FROM mcp_product_variants WHERE variant_id = :id', ['id' => $pre['variant']]);
        if ($v !== null) {
            $d = po_line_defaults($pdo, (int) $supplierId, (int) $v['variant_id']);
            $rows[] = ['variant_id' => (int) $v['variant_id'], 'sku' => $v['sku'], 'label' => $v['product_name'] . ($v['size_name'] ? ', ' . $v['size_name'] : ''), 'qty' => max(1, (int) ($d['moq'] ?? 1)), 'unit_cost' => $d['unit_cost'], 'listing_variant_id' => $d['listing_variant_id']];
        }
    }
}
if ($cur === null && $kind === 'dropship' && $pre['order'] !== '') {
    $so = find_sales_order_brief($pdo, $pre['order']);
    if ($so !== null) { $dropships = order_open_dropship_lines($pdo, $so['sales_order_id']); }
}
$head = $cur ?? ['supplier_id' => $supplierId, 'location_id' => default_po_location($pdo), 'expected_on' => null, 'shipping_cost' => '0.00', 'notes' => null, 'internal_notes' => null, 'kind' => $kind];
if (wants_json()) {
    respond_screen(['purchase_order' => $cur === null ? null : present_purchase_order($cur), 'kind' => $kind, 'locations' => po_locations($pdo), 'prefill' => $rows,
        'dropship_lines' => $dropships === null ? null : array_values(array_map(static fn (array $g): array => ['supplier_id' => $g['supplier_id'], 'supplier_name' => $g['supplier_name'], 'lines' => array_map(static fn (array $l): array => [
            'line_id' => (int) $l['line_id'], 'line_no' => (int) $l['line_no'], 'sku' => $l['sku'], 'qty' => (int) $l['qty'], 'offer_cost' => $l['cost_withheld'] ? null : $l['offer_cost'], 'lead_time_days' => $l['offer_lead_time_days']], $g['lines'])], $dropships))]);
}
render_screen($cur === null ? 'New purchase order' : 'Change ' . $cur['number'], view('purchasing/form.php', ['cur' => $cur, 'head' => $head, 'kind' => $kind, 'pre' => $pre, 'rows' => $rows, 'dropships' => $dropships, 'so' => $so,
    'locations' => po_locations($pdo), 'seesCost' => sees_cost(), 'here' => here_url()]), ['activeNav' => 'purchase-order-list', 'screen' => $screen, 'entity' => 'purchase_order', 'recordId' => $id === null ? '' : (string) $id]);

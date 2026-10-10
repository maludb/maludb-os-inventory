<?php
declare(strict_types=1);
/**
 * /orders/new?customer=&variant=&qty=&fulfilment=&listing_variant=&location= and /orders/{id}/edit — screens `order-add`, `order-edit` (a quote only). The first row is prefilled from Find's "Sell this"; the picker of each
 * line is slice 4's compact availability partial. orders.write; people only.
 */
require_once dirname(__DIR__, 2) . '/app/features/orders/handler.php';
require_right('orders.write');
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('order');
$cur = $id === null ? null : order_or_404($pdo, $id);
if ($cur !== null && $cur['status'] !== 'quote') { refuse(422, $cur['number'] . ' is ' . str_replace('_', ' ', $cur['status']) . ' — cancel a line or the order instead'); }
$screen = $cur === null ? 'order-add' : 'order-edit';
log_screen_view($pdo, $screen);
$pre = ['customer' => request_integer('customer'), 'variant' => request_integer('variant'), 'qty' => max(1, min(999, request_integer('qty') ?? 1)), 'fulfilment' => request_string('fulfilment'),
        'listing_variant' => request_integer('listing_variant'), 'location' => request_integer('location'), 'q' => mb_substr(request_string('q'), 0, 120)];
$first = null;
if ($cur === null && $pre['variant'] !== null) {
    $v = one_row($pdo, 'SELECT variant_id, sku, product_name, size_name, retail_price, kind FROM mcp_product_variants WHERE variant_id = :id', ['id' => $pre['variant']]);
    if ($v !== null) {
        $choose = match ($pre['fulfilment']) { 'stock', 'pickup' => $pre['location'] !== null ? $pre['fulfilment'] . ':' . $pre['location'] : '', 'dropship' => $pre['listing_variant'] !== null ? 'dropship:' . $pre['listing_variant'] : '', 'backorder' => 'backorder', default => '' };
        $first = $v + ['qty' => $pre['qty'], 'choose' => $choose];
    }
}
$results = $cur === null && $pre['q'] !== '' && mb_strlen($pre['q']) >= 2 ? find_variants($pdo, $pre['q'], null, [], 20) : [];
$head = $cur ?? ['customer_id' => $pre['customer'], 'location_id' => default_store($pdo), 'salesperson_member_id' => current_member_id(), 'delivery_method' => 'delivery', 'promised_on' => null,
                 'tax_rate_id' => (int) (one_value($pdo, 'SELECT tax_rate_id FROM mcp_tax_rates WHERE is_default AND archived_at IS NULL') ?? 0) ?: null, 'shipping_charge' => '0.00', 'customer_reference' => null, 'notes' => null];
if ($cur === null && $head['customer_id'] !== null) { $head += customer_ship_to($pdo, (int) $head['customer_id']); }
if (wants_json()) {
    respond_screen(['order' => $cur === null ? null : present_order($cur), 'locations' => order_locations($pdo), 'tax_rates' => tax_rates_live($pdo), 'delivery_methods' => DELIVERY_METHODS, 'fulfilment_kinds' => FULFILMENT_KINDS]);
}
render_screen($cur === null ? 'New quote' : 'Change ' . $cur['number'], view('orders/form.php', ['cur' => $cur, 'head' => $head, 'first' => $first, 'results' => $results, 'pre' => $pre, 'locations' => order_locations($pdo), 'rates' => tax_rates_live($pdo),
    'people' => members_for_pick($pdo), 'sellable' => sellable_locations($pdo), 'seesCost' => sees_cost(), 'mayCustomer' => has_right('customers.write'), 'errors' => [], 'here' => here_url()]),
    ['activeNav' => 'order-list', 'screen' => $screen, 'entity' => 'sales_order', 'recordId' => $id === null ? '' : (string) $id]);

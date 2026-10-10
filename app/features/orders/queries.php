<?php
declare(strict_types=1);

/**
 * Sales orders (orders.md "Query functions"): read from the mcp_* views (the views null what the caller may not see — the ship-to without orders.write or
 * stock.ship, the payments without payments.record or reports.read, the offer cost behind the wall, the link without orders.send) and from the SQL functions
 * (inv_fulfilment_today(), inv_order_timeline()). PHP decodes and renders; the database decides a price, a total, an allocation. The Phase 4 resolvers and
 * tools (find_orders, get_order, order_timeline, fulfilment_today) call these.
 */

const ORDER_STATUSES = ['quote' => 'Quote', 'confirmed' => 'Confirmed', 'in_fulfilment' => 'In fulfilment', 'shipped' => 'Shipped', 'delivered' => 'Delivered', 'closed' => 'Closed', 'cancelled' => 'Cancelled'];
const ORDER_OPEN_STATUSES = ['confirmed', 'in_fulfilment', 'shipped'];
const DELIVERY_METHODS = ['pickup' => 'Pickup', 'delivery' => 'Own delivery', 'parcel' => 'Parcel', 'ltl' => 'LTL freight', 'white_glove' => 'White glove'];
const FULFILMENT_KINDS = ['stock' => 'Stock', 'dropship' => 'Drop-ship', 'backorder' => 'Backorder', 'pickup' => 'Pickup'];
const PAYMENT_STATUSES = ['unpaid' => 'Unpaid', 'deposit' => 'Deposit', 'paid' => 'Paid', 'refunded' => 'Refunded', 'partial_refund' => 'Partly refunded'];
const PAYMENT_METHODS = ['cash' => 'Cash', 'card' => 'Card', 'check' => 'Check', 'transfer' => 'Transfer', 'financing' => 'Financing', 'other' => 'Other'];
const SHIPMENT_KINDS = ['own_delivery' => 'Own delivery', 'parcel' => 'Parcel', 'ltl' => 'LTL freight', 'dropship' => 'Drop-ship', 'pickup' => 'Pickup'];
const LINE_STATUSES = ['open' => 'Open', 'allocated' => 'Allocated', 'ordered' => 'Ordered', 'shipped' => 'Shipped', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled', 'returned' => 'Returned'];
const ORDER_NOTIFY_KINDS = ['delivery_date' => 'A new delivery date', 'delay' => 'A delay', 'ready_for_pickup' => 'Ready for pickup', 'shipped' => 'Shipped'];
const ORDER_PAGE = 50;

function order_row_decode(array $o): array
{
    foreach (['sales_order_id', 'customer_id', 'line_count'] as $k) { $o[$k] = (int) $o[$k]; }
    foreach (['salesperson_member_id', 'location_id', 'tax_rate_id'] as $k) { $o[$k] = $o[$k] === null ? null : (int) $o[$k]; }
    $o['is_late'] = (bool) $o['is_late'];
    $o['late_days'] = $o['is_late'] && $o['promised_on'] !== null ? max(1, (int) floor((strtotime(date('Y-m-d')) - strtotime((string) $o['promised_on'])) / 86400)) : 0;
    return $o;
}

const ORDER_LIST_COLUMNS = 'o.sales_order_id, o.number, o.customer_id, o.customer_name, o.status, o.origin, o.salesperson_member_id, o.salesperson_name, o.location_id, o.location_name, o.ordered_on, o.promised_on,
    o.delivery_method, o.ship_to_city, o.ship_to_region, o.tax_rate_id, o.subtotal, o.discount_total, o.tax_total, o.shipping_charge, o.total, o.payment_status, o.amount_paid, o.balance_due,
    o.customer_reference, o.notes, o.confirmed_at, o.closed_at, o.cancelled_at, o.cancel_reason, o.created_at, o.updated_at, o.is_late, o.line_count';

function orders_where(array $f, array &$args): string
{
    $sql = '';
    $status = (string) ($f['status'] ?? '');
    if ($status === '') {
        $sql .= " AND o.status IN ('confirmed', 'in_fulfilment', 'shipped')";
    } elseif ($status !== 'all') {
        $list = array_values(array_filter(array_map('trim', explode(',', $status)), static fn (string $s): bool => isset(ORDER_STATUSES[$s])));
        $sql .= $list === [] ? ' AND false' : " AND o.status IN ('" . implode("','", $list) . "')";
    }
    foreach (['customer' => 'o.customer_id', 'salesperson' => 'o.salesperson_member_id', 'location' => 'o.location_id'] as $k => $col) {
        if (!empty($f[$k])) { $sql .= " AND $col = :$k"; $args[$k] = (int) $f[$k]; }
    }
    if (!empty($f['late'])) { $sql .= ' AND o.is_late'; }
    foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
        if (!empty($f[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $f[$k])) { $sql .= " AND o.ordered_on $op CAST(:$k AS date)"; $args[$k] = $f[$k]; }
    }
    if (!empty($f['q'])) { $sql .= ' AND (o.number ILIKE :qq OR o.customer_name ILIKE :qq OR o.customer_reference ILIKE :qq)'; $args['qq'] = '%' . $f['q'] . '%'; }
    return $sql;
}

/** The orders table: filters status (default the open ones; 'all'; or a list), customer, salesperson, location, late, from, to, q. Newest first. */
function find_orders(PDO $pdo, array $filters, int $limit = ORDER_PAGE, int $offset = 0): array
{
    $args = [];
    $where = orders_where($filters, $args);
    $st = $pdo->prepare('SELECT ' . ORDER_LIST_COLUMNS . ' FROM mcp_sales_orders o WHERE true' . $where . ' ORDER BY o.ordered_on DESC, o.sales_order_id DESC LIMIT ' . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset));
    $st->execute($args);
    return array_map('order_row_decode', $st->fetchAll());
}

function count_orders(PDO $pdo, array $filters): int
{
    $args = [];
    $where = orders_where($filters, $args);
    $st = $pdo->prepare('SELECT count(*) FROM mcp_sales_orders o WHERE true' . $where);
    $st->execute($args);
    return (int) $st->fetchColumn();
}

/** An order with everything the page shows. Null when it is not there (or not the caller's to see). */
function find_order(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT o.* FROM mcp_sales_orders o WHERE o.sales_order_id = :id');
    $st->execute(['id' => $id]);
    $o = $st->fetch();
    if ($o === false) { return null; }
    $o = order_row_decode($o);
    $o['lines'] = order_lines($pdo, $id);
    $o['payments'] = order_payments($pdo, $id);
    $o['shipments'] = order_shipments($pdo, $id);
    $o['dropships'] = order_dropships($pdo, $id);
    $o['returns'] = order_returns($pdo, $id);
    $o['link'] = order_link_state($pdo, $id);
    $o['note_count'] = (int) one_value($pdo, "SELECT count(*) FROM mcp_notes WHERE record_type = 'sales_order' AND record_id = :id", ['id' => $id]);
    $o['attachment_count'] = (int) one_value($pdo, "SELECT count(*) FROM mcp_attachments WHERE record_type = 'sales_order' AND record_id = :id", ['id' => $id]);
    return $o;
}

function find_order_by_number(PDO $pdo, string $number): ?array
{
    $id = one_value($pdo, 'SELECT sales_order_id FROM mcp_sales_orders WHERE upper(number) = upper(:n)', ['n' => trim($number)]);
    return $id === null ? null : find_order($pdo, (int) $id);
}

function line_row_decode(array $l): array
{
    foreach (['line_id', 'sales_order_id', 'line_no', 'variant_id', 'qty', 'qty_allocated', 'qty_shipped', 'qty_returned'] as $k) { $l[$k] = (int) $l[$k]; }
    foreach (['location_id', 'listing_variant_id', 'source_id', 'offer_lead_time_days', 'purchase_order_line_id'] as $k) { $l[$k] = $l[$k] === null ? null : (int) $l[$k]; }
    $l['cost_withheld'] = (bool) $l['cost_withheld'];
    $l['serials'] = pg_text_array((string) ($l['serials'] ?? '{}'));
    return $l;
}

const ORDER_LINE_SELECT = 'SELECT l.line_id, l.sales_order_id, l.order_number, l.line_no, l.variant_id, l.sku, l.product_name, l.size_name, l.qty, l.unit_price, l.discount, l.line_total, l.fulfilment_kind, l.location_id,
    loc.name AS location_name, l.listing_variant_id, l.source_id, l.source_name, l.offer_cost, l.cost_withheld, l.offer_lead_time_days, l.purchase_order_line_id, l.qty_allocated, l.qty_shipped, l.qty_returned,
    l.status, l.serials, l.notes, l.created_at, l.updated_at, v.kind AS variant_kind, v.retail_price AS variant_retail
    FROM mcp_sales_order_lines l LEFT JOIN mcp_locations loc ON loc.location_id = l.location_id JOIN mcp_product_variants v ON v.variant_id = l.variant_id';

function order_lines(PDO $pdo, int $orderId): array
{
    $st = $pdo->prepare(ORDER_LINE_SELECT . ' WHERE l.sales_order_id = :o ORDER BY l.line_no');
    $st->execute(['o' => $orderId]);
    return array_map('line_row_decode', $st->fetchAll());
}

function find_order_line(PDO $pdo, int $lineId): ?array
{
    $st = $pdo->prepare(ORDER_LINE_SELECT . ' WHERE l.line_id = :id');
    $st->execute(['id' => $lineId]);
    $r = $st->fetch();
    return $r === false ? null : line_row_decode($r);
}

/** The payments — the view answers nothing to a caller without payments.record or reports.read. */
function order_payments(PDO $pdo, int $orderId): array
{
    $st = $pdo->prepare('SELECT payment_id, sales_order_id, kind, amount, method, reference, taken_by, taken_by_name, taken_at, note FROM mcp_order_payments WHERE sales_order_id = :o ORDER BY taken_at, payment_id');
    $st->execute(['o' => $orderId]);
    return $st->fetchAll();
}

/** The shipments with their lines (SKU, product, size, qty, serials). */
function order_shipments(PDO $pdo, int $orderId): array
{
    $st = $pdo->prepare('SELECT shipment_id, sales_order_id, kind, carrier, tracking_number, tracking_url, shipped_at, delivered_at, shipped_by, note FROM mcp_shipments WHERE sales_order_id = :o ORDER BY shipped_at, shipment_id');
    $st->execute(['o' => $orderId]);
    $out = [];
    foreach ($st->fetchAll() as $sh) {
        $sh['shipment_id'] = (int) $sh['shipment_id'];
        $sh['lines'] = shipment_lines($pdo, $sh['shipment_id']);
        $out[] = $sh;
    }
    return $out;
}

function shipment_lines(PDO $pdo, int $shipmentId): array
{
    $st = $pdo->prepare('SELECT sl.shipment_line_id, sl.shipment_id, sl.sales_order_line_id, sl.qty, sl.serials, l.line_no, l.sku, l.product_name, l.size_name
                           FROM mcp_shipment_lines sl JOIN mcp_sales_order_lines l ON l.line_id = sl.sales_order_line_id WHERE sl.shipment_id = :s ORDER BY l.line_no');
    $st->execute(['s' => $shipmentId]);
    return array_map(static function (array $r): array { $r['serials'] = pg_text_array((string) ($r['serials'] ?? '{}')); $r['qty'] = (int) $r['qty']; return $r; }, $st->fetchAll());
}

/** The drop-ship purchase orders of an order, each with its lines' tracking (the Buyer's screens are slice 6's). */
function order_dropships(PDO $pdo, int $orderId): array
{
    $st = $pdo->prepare("SELECT purchase_order_id, number, supplier_id, supplier_name, status, expected_on, acknowledged_at, supplier_order_ref, total, cost_withheld
                           FROM mcp_purchase_orders WHERE sales_order_id = :o AND kind = 'dropship' ORDER BY purchase_order_id");
    $st->execute(['o' => $orderId]);
    $out = [];
    foreach ($st->fetchAll() as $po) {
        $po['purchase_order_id'] = (int) $po['purchase_order_id'];
        $l = $pdo->prepare('SELECT purchase_order_line_id, line_no, sku, product_name, qty_ordered, status, expected_on, tracking_carrier, tracking_number, sales_order_line_id FROM mcp_purchase_order_lines WHERE purchase_order_id = :p ORDER BY line_no');
        $l->execute(['p' => $po['purchase_order_id']]);
        $po['lines'] = $l->fetchAll();
        $out[] = $po;
    }
    return $out;
}

function order_returns(PDO $pdo, int $orderId): array
{
    $st = $pdo->prepare('SELECT return_id, number, status, refund_amount FROM mcp_return_authorizations WHERE sales_order_id = :o ORDER BY return_id');
    $st->execute(['o' => $orderId]);
    return $st->fetchAll();
}

/** The customer's link as the page shows it: the newest link row (live / expires / rotated), or null when there is none — or the caller lacks orders.send (the view is empty). */
function order_link_state(PDO $pdo, int $orderId): ?array
{
    $st = $pdo->prepare('SELECT link_id, expires_at, rotated_at, last_used_at, view_count, created_at, is_live FROM mcp_order_links WHERE sales_order_id = :o ORDER BY link_id DESC LIMIT 1');
    $st->execute(['o' => $orderId]);
    $r = $st->fetch();
    if ($r === false) { return null; }
    $r['link_id'] = (int) $r['link_id'];
    $r['is_live'] = (bool) $r['is_live'];
    $r['view_count'] = (int) $r['view_count'];
    return $r;
}

/** inv_order_timeline(): the order's facts, its drop-ship events, shipments, returns and activity, oldest first. */
function order_timeline(PDO $pdo, int $orderId, int $limit = 200): array
{
    $st = $pdo->prepare('SELECT "at", kind, title, detail, member_id, (SELECT display_name FROM members m WHERE m.id = t.member_id) AS member_name FROM inv_order_timeline(:o) t
                          WHERE NOT (t.kind = \'activity\' AND t.title IN (\'screen.view\', \'order.customer_view\')) ORDER BY 1 LIMIT ' . max(1, min(500, $limit)));
    $st->execute(['o' => $orderId]);
    return array_map(static function (array $r): array { $r['detail'] = json_decode((string) ($r['detail'] ?? '{}'), true) ?: []; return $r; }, $st->fetchAll());
}

// ---- the form's helpers ---------------------------------------------------------------------------------------------------
/** The stores a quote may be written at: the active locations, stores and showrooms first. */
function order_locations(PDO $pdo): array
{
    return $pdo->query("SELECT location_id, name, kind, is_sellable FROM mcp_locations WHERE active ORDER BY (kind IN ('store', 'showroom')) DESC, name")->fetchAll();
}

/** The default store: the first of kind store or showroom, else the first active. */
function default_store(PDO $pdo): ?int
{
    $v = one_value($pdo, "SELECT location_id FROM mcp_locations WHERE active ORDER BY (kind IN ('store', 'showroom')) DESC, (kind = 'store') DESC, location_id LIMIT 1");
    return $v === null ? null : (int) $v;
}

/** What a bundle variant sells as: its components [{variant_id, qty, unit_price}] — the set's price (or the entered one) on the first row, 0 on the rest. */
function expand_bundle_line(PDO $pdo, int $bundleVariantId, int $qty, ?string $price): array
{
    // the dearest component first (the mattress of a set carries the set's price), then by SKU
    $st = $pdo->prepare('SELECT c.component_variant_id, c.qty FROM mcp_bundle_components c JOIN mcp_product_variants cv ON cv.variant_id = c.component_variant_id
                          WHERE c.bundle_variant_id = :v ORDER BY cv.retail_price DESC NULLS LAST, c.component_sku, c.component_variant_id');
    $st->execute(['v' => $bundleVariantId]);
    $comps = $st->fetchAll();
    if ($comps === []) { return []; }
    $retail = $price ?? (string) (one_value($pdo, 'SELECT retail_price FROM mcp_product_variants WHERE variant_id = :v', ['v' => $bundleVariantId]) ?? '0');
    $out = [];
    foreach ($comps as $i => $c) {
        $q = (int) $c['qty'] * $qty;
        $out[] = ['variant_id' => (int) $c['component_variant_id'], 'qty' => $q, 'unit_price' => $i === 0 ? number_format((float) $retail * $qty / max(1, $q), 2, '.', '') : '0.00'];
    }
    return $out;
}

/** The variant a line names: from an id, or a code typed (SKU, GTIN, UPC) — the JS-free path. Null when nothing matches. */
function line_variant(PDO $pdo, string $v): ?array
{
    $v = trim($v);
    if ($v === '') { return null; }
    if (ctype_digit($v) && strlen($v) < 9) {
        $r = one_row($pdo, 'SELECT variant_id, sku, kind, product_name, size_name, retail_price, active FROM mcp_product_variants WHERE variant_id = :id', ['id' => (int) $v]);
        if ($r !== null) { return $r; }
    }
    $r = one_row($pdo, 'SELECT variant_id, sku, kind, product_name, size_name, retail_price, active FROM mcp_product_variants WHERE lower(sku) = lower(:s)', ['s' => $v]);
    if ($r !== null) { return $r; }
    require_once dirname(__DIR__) . '/stock/queries.php';
    $f = variant_by_scan($pdo, $v);
    return $f === null ? null : one_row($pdo, 'SELECT variant_id, sku, kind, product_name, size_name, retail_price, active FROM mcp_product_variants WHERE variant_id = :id', ['id' => $f['variant_id']]);
}

// ---- confirm: what it will do, line by line ------------------------------------------------------------------------------
/**
 * Per non-cancelled line what confirming does: stock / pickup → allocates N at the location (M available) or cannot cover; dropship → drafts a purchase order to
 * the supplier (grouped by supplier); backorder → waits. [{line, kind, ok, text, cost, supplier, lead, available, fix}]
 */
function confirm_preview(PDO $pdo, int $orderId): array
{
    $out = [];
    foreach (order_lines($pdo, $orderId) as $l) {
        if ($l['status'] === 'cancelled') { continue; }
        $row = ['line' => $l, 'kind' => $l['fulfilment_kind'], 'ok' => true, 'text' => '', 'cost' => null, 'supplier' => null, 'lead' => null, 'available' => null, 'fix' => false];
        if (in_array($l['fulfilment_kind'], ['stock', 'pickup'], true)) {
            $avail = (int) (one_value($pdo, 'SELECT qty_available FROM mcp_inventory_balances WHERE variant_id = :v AND location_id = :l', ['v' => $l['variant_id'], 'l' => $l['location_id']]) ?? 0);
            $row['available'] = $avail;
            if ($avail >= $l['qty']) {
                $row['text'] = 'allocates ' . $l['qty'] . ' at ' . $l['location_name'] . ' (' . $avail . ' available)';
            } else {
                $row['ok'] = false;
                $row['fix'] = true;
                $row['text'] = 'cannot cover: ' . $avail . ' available at ' . $l['location_name'] . ' — make it a backorder or choose a source';
            }
        } elseif ($l['fulfilment_kind'] === 'dropship') {
            $sup = $l['source_id'] === null ? null : one_value($pdo, 'SELECT supplier_name FROM mcp_sources WHERE source_id = :s', ['s' => $l['source_id']]);
            $row['supplier'] = $sup ?? $l['source_name'] ?? 'the supplier';
            $row['lead'] = $l['offer_lead_time_days'];
            $row['cost'] = $l['cost_withheld'] ? null : $l['offer_cost'];
            $row['text'] = 'drafts a purchase order to ' . $row['supplier'] . ($l['offer_lead_time_days'] !== null ? ' · ' . $l['offer_lead_time_days'] . ' days' : '');
        } else {
            $row['text'] = 'waits for stock at ' . ($l['location_name'] ?? 'a location');
        }
        $out[] = $row;
    }
    return $out;
}

// ---- fulfilment today -----------------------------------------------------------------------------------------------------
/** inv_fulfilment_today(day, location) grouped by location then delivery method then order, with the drop-ships expected that day. {day, groups[], dropships_expected[]} */
function fulfilment_today(PDO $pdo, ?string $day = null, ?int $locationId = null): array
{
    $day = $day ?? date('Y-m-d');
    $st = $pdo->prepare('SELECT * FROM inv_fulfilment_today(CAST(:d AS date), :l)');
    $st->execute(['d' => $day, 'l' => $locationId]);
    $groups = [];
    foreach ($st->fetchAll() as $r) {
        $gk = ($r['location_id'] ?? 0) . '|' . $r['delivery_method'];
        $groups[$gk] ??= ['location_id' => $r['location_id'] === null ? null : (int) $r['location_id'], 'location_name' => $r['location_name'], 'delivery_method' => $r['delivery_method'], 'orders' => []];
        $oid = (int) $r['sales_order_id'];
        $groups[$gk]['orders'][$oid] ??= ['sales_order_id' => $oid, 'order_number' => $r['order_number'], 'customer_name' => $r['customer_name'], 'promised_on' => $r['promised_on'], 'ship_to_city' => $r['ship_to_city'], 'lines' => []];
        $groups[$gk]['orders'][$oid]['lines'][] = ['line_id' => (int) $r['line_id'], 'line_no' => (int) $r['line_no'], 'variant_id' => (int) $r['variant_id'], 'sku' => $r['sku'], 'product_name' => $r['product_name'],
            'size_name' => $r['size_name'], 'qty' => (int) $r['qty'], 'qty_allocated' => (int) $r['qty_allocated'], 'qty_shipped' => (int) $r['qty_shipped'], 'to_pick' => (int) $r['qty'] - (int) $r['qty_shipped'],
            'fulfilment_kind' => $r['fulfilment_kind'], 'line_status' => $r['line_status']];
    }
    foreach ($groups as &$g) { $g['orders'] = array_values($g['orders']); }
    unset($g);
    return ['day' => $day, 'groups' => array_values($groups), 'dropships_expected' => dropships_expected($pdo, $day)];
}

/** The drop-ship purchase order lines expected on a day: open / acknowledged / shipped lines of a drop-ship PO that is not cancelled. */
function dropships_expected(PDO $pdo, string $day): array
{
    $st = $pdo->prepare("SELECT po.purchase_order_id, po.number, po.supplier_name, po.status AS po_status, po.sales_order_id, po.sales_order_number, pl.line_no, pl.sku, pl.product_name, pl.qty_ordered,
                                pl.status, pl.expected_on, pl.tracking_carrier, pl.tracking_number
                           FROM mcp_purchase_order_lines pl JOIN mcp_purchase_orders po ON po.purchase_order_id = pl.purchase_order_id
                          WHERE po.kind = 'dropship' AND pl.expected_on = CAST(:d AS date) AND pl.status IN ('open', 'acknowledged', 'shipped') AND po.status <> 'cancelled'
                          ORDER BY po.supplier_name, po.number, pl.line_no");
    $st->execute(['d' => $day]);
    return $st->fetchAll();
}

/** The location a salesperson's own orders are filled from today (the first one due), or null — the fulfilment page opens on it for Sales. */
function my_store_today(PDO $pdo, int $memberId, string $day): ?int
{
    $v = one_value($pdo, 'SELECT f.location_id FROM inv_fulfilment_today(CAST(:d AS date), NULL) f JOIN mcp_sales_orders o ON o.sales_order_id = f.sales_order_id
                           WHERE o.salesperson_member_id = :m AND f.location_id IS NOT NULL ORDER BY f.promised_on NULLS FIRST, f.sales_order_id LIMIT 1', ['m' => $memberId, 'd' => $day]);
    return $v === null ? null : (int) $v;
}

/** Salespeople for the list's filter: the members who have an order, [{member_id, display_name}]. */
function order_salespeople(PDO $pdo): array
{
    return $pdo->query('SELECT DISTINCT o.salesperson_member_id AS member_id, o.salesperson_name AS display_name FROM mcp_sales_orders o WHERE o.salesperson_member_id IS NOT NULL ORDER BY 2')->fetchAll();
}

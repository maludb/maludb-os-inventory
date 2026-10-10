<?php
declare(strict_types=1);

/**
 * Purchase orders (purchasing.md "Query functions"): read from the mcp_* views (the views null what the caller may not see — the cost behind the wall, the ship-to's name and the
 * internal notes without purchasing.write, the link without purchasing.write) and from the SQL functions (inv_purchase_orders_open(), inv_reorder_candidates(),
 * inv_availability()). PHP decodes and renders; the database decides a number, a total, what a sent order may do next. The Phase 4 resolvers and tools (find_purchase_orders,
 * get_purchase_order, purchase_orders_open) call these. A purchase order's FULL ship-to (address lines, phone, notes) is read from the base table for a holder of purchasing.write alone.
 */

const PO_KINDS = ['stock' => 'For stock', 'dropship' => 'Drop-ship'];
const PO_STATUSES = ['draft' => 'Draft', 'sent' => 'Sent', 'acknowledged' => 'Acknowledged', 'partial' => 'Partly received', 'received' => 'Received', 'closed' => 'Closed', 'closed_short' => 'Closed short', 'cancelled' => 'Cancelled'];
const PO_OPEN_STATUSES = ['draft', 'sent', 'acknowledged', 'partial'];
const PO_SENT_STATUSES = ['sent', 'acknowledged', 'partial'];
const PO_LINE_STATUSES = ['open' => 'Open', 'acknowledged' => 'Acknowledged', 'declined' => 'Declined', 'partial' => 'Partly received', 'shipped' => 'Shipped', 'received' => 'Received', 'closed_short' => 'Closed short', 'cancelled' => 'Cancelled'];
const PO_VIA = ['email' => 'Email', 'portal' => "The supplier's portal", 'api' => 'API', 'edi' => 'EDI', 'phone' => 'Phone'];
const PO_PAGE = 50;

function po_row_decode(array $o): array
{
    foreach (['purchase_order_id', 'supplier_id', 'line_count'] as $k) { if (isset($o[$k])) { $o[$k] = (int) $o[$k]; } }
    foreach (['sales_order_id', 'location_id', 'sent_by'] as $k) { if (array_key_exists($k, $o)) { $o[$k] = $o[$k] === null ? null : (int) $o[$k]; } }
    foreach (['awaiting_ack', 'overdue', 'has_tracking', 'cost_withheld', 'untracked_past_expected'] as $k) { if (array_key_exists($k, $o)) { $o[$k] = (bool) $o[$k]; } }
    return $o;
}

const PO_LIST_COLUMNS = "po.purchase_order_id, po.number, po.supplier_id, po.supplier_name, po.kind, po.sales_order_id, po.sales_order_number, po.status, po.ship_to_kind, po.location_id, po.ordered_on, po.expected_on,
    po.supplier_order_ref, po.sent_via, po.sent_at, po.acknowledged_at, po.subtotal, po.shipping_cost, po.total, po.cost_withheld, po.notes, po.created_at, po.updated_at, po.line_count,
    (SELECT so.customer_name FROM mcp_sales_orders so WHERE so.sales_order_id = po.sales_order_id) AS customer_name,
    EXISTS (SELECT 1 FROM mcp_purchase_order_lines pl WHERE pl.purchase_order_id = po.purchase_order_id AND pl.tracking_number IS NOT NULL) AS has_tracking,
    COALESCE(oo.awaiting_ack, false) AS awaiting_ack,
    (po.expected_on IS NOT NULL AND po.expected_on < current_date AND po.status IN ('sent', 'acknowledged', 'partial')) AS overdue,
    COALESCE(oo.untracked_past_expected, false) AS untracked_past_expected";

function pos_where(array $f, array &$args): string
{
    $sql = '';
    $status = (string) ($f['status'] ?? '');
    if ($status === '') {
        $sql .= " AND po.status IN ('draft', 'sent', 'acknowledged', 'partial')";
    } elseif ($status !== 'all') {
        $list = array_values(array_filter(array_map('trim', explode(',', $status)), static fn (string $s): bool => isset(PO_STATUSES[$s])));
        $sql .= $list === [] ? ' AND false' : " AND po.status IN ('" . implode("','", $list) . "')";
    }
    $kind = (string) ($f['kind'] ?? '');
    if ($kind !== '' && isset(PO_KINDS[$kind])) { $sql .= ' AND po.kind = :kind'; $args['kind'] = $kind; }
    if (!empty($f['supplier'])) { $sql .= ' AND po.supplier_id = :supplier'; $args['supplier'] = (int) $f['supplier']; }
    if (!empty($f['order'])) { $sql .= ' AND po.sales_order_id = :ord'; $args['ord'] = (int) $f['order']; }
    if (!empty($f['awaiting_ack'])) { $sql .= ' AND COALESCE(oo.awaiting_ack, false)'; }
    if (!empty($f['no_tracking'])) { $sql .= ' AND COALESCE(oo.untracked_past_expected, false)'; }
    if (!empty($f['q'])) { $sql .= ' AND (po.number ILIKE :qq OR po.supplier_name ILIKE :qq OR po.supplier_order_ref ILIKE :qq OR po.sales_order_number ILIKE :qq)'; $args['qq'] = '%' . $f['q'] . '%'; }
    return $sql;
}

/** The purchase-order table: filters status (default the open ones; 'all'; or a list), kind, supplier, order, awaiting_ack, no_tracking, q. Newest first. */
function find_purchase_orders(PDO $pdo, array $filters, int $limit = PO_PAGE, int $offset = 0): array
{
    $args = [];
    $where = pos_where($filters, $args);
    $st = $pdo->prepare('SELECT ' . PO_LIST_COLUMNS . ' FROM mcp_purchase_orders po LEFT JOIN inv_purchase_orders_open() oo ON oo.purchase_order_id = po.purchase_order_id WHERE true' . $where
        . ' ORDER BY po.ordered_on DESC, po.purchase_order_id DESC LIMIT ' . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset));
    $st->execute($args);
    return array_map('po_row_decode', $st->fetchAll());
}

function count_purchase_orders(PDO $pdo, array $filters): int
{
    $args = [];
    $where = pos_where($filters, $args);
    $st = $pdo->prepare('SELECT count(*) FROM mcp_purchase_orders po LEFT JOIN inv_purchase_orders_open() oo ON oo.purchase_order_id = po.purchase_order_id WHERE true' . $where);
    $st->execute($args);
    return (int) $st->fetchColumn();
}

/** A purchase order with everything its page shows. Null when it is not there (or not the caller's to see). */
function find_purchase_order(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT ' . PO_LIST_COLUMNS . ', po.ship_to_name, po.ship_to_city, po.ship_to_region, po.ship_to_postal, po.ship_to_country, po.internal_notes, po.approved_by, po.approved_at, po.closed_at,
        po.cancelled_by, po.cancelled_at, po.cancel_reason, po.created_by, (SELECT m.display_name FROM members m WHERE m.id = po.sent_by) AS sent_by_name,
        (SELECT m.display_name FROM members m WHERE m.id = po.created_by) AS created_by_name
        FROM mcp_purchase_orders po LEFT JOIN inv_purchase_orders_open() oo ON oo.purchase_order_id = po.purchase_order_id WHERE po.purchase_order_id = :id');
    $st->execute(['id' => $id]);
    $o = $st->fetch();
    if ($o === false) { return null; }
    $o = po_row_decode($o);
    $o['supplier'] = find_supplier($pdo, $o['supplier_id']);
    $o['lines'] = po_lines($pdo, $id);
    $o['events'] = po_events($pdo, $id);
    $o['receipts'] = po_receipts($pdo, $id);
    $o['link'] = po_link_state($pdo, $id);
    $o['location_name'] = $o['location_id'] === null ? null : one_value($pdo, 'SELECT name FROM mcp_locations WHERE location_id = :l', ['l' => $o['location_id']]);
    $o['ship_to'] = has_right('purchasing.write') ? po_ship_to_full($pdo, $id) : null;
    $o['shows_phone'] = has_right('purchasing.write') ? po_shows_phone($pdo, $id) : false;
    $o['note_count'] = (int) one_value($pdo, "SELECT count(*) FROM mcp_notes WHERE record_type = 'purchase_order' AND record_id = :id", ['id' => $id]);
    $o['attachment_count'] = (int) one_value($pdo, "SELECT count(*) FROM mcp_attachments WHERE record_type = 'purchase_order' AND record_id = :id", ['id' => $id]);
    return $o;
}

function find_purchase_order_by_number(PDO $pdo, string $number): ?array
{
    $id = one_value($pdo, 'SELECT purchase_order_id FROM mcp_purchase_orders WHERE upper(number) = upper(:n)', ['n' => trim($number)]);
    return $id === null ? null : find_purchase_order($pdo, (int) $id);
}

/** The full ship-to snapshot (base table — the writer reads it for purchasing.write; the views carry the city and region only). */
function po_ship_to_full(PDO $pdo, int $id): ?array
{
    return one_row($pdo, 'SELECT ship_to_name, ship_to_address1, ship_to_address2, ship_to_city, ship_to_region, ship_to_postal, ship_to_country, ship_to_phone, ship_to_notes FROM purchase_orders WHERE id = :id', ['id' => $id]);
}

function po_line_decode(array $l): array
{
    foreach (['purchase_order_line_id', 'purchase_order_id', 'line_no', 'variant_id', 'qty_ordered', 'qty_received'] as $k) { $l[$k] = (int) $l[$k]; }
    foreach (['listing_variant_id', 'sales_order_line_id', 'sales_line_no'] as $k) { if (array_key_exists($k, $l)) { $l[$k] = $l[$k] === null ? null : (int) $l[$k]; } }
    $l['cost_withheld'] = (bool) $l['cost_withheld'];
    return $l;
}

const PO_LINE_SELECT = "SELECT pl.purchase_order_line_id, pl.purchase_order_id, pl.line_no, pl.variant_id, pl.sku, pl.product_name, mv.size_name, pl.supplier_sku, pl.listing_variant_id, pl.qty_ordered, pl.unit_cost,
        pl.cost_withheld, CASE WHEN NOT pl.cost_withheld THEN pl.qty_ordered * pl.unit_cost END AS line_cost, pl.expected_on, pl.qty_received, pl.sales_order_line_id, pl.status, pl.supplier_note,
        pl.tracking_carrier, pl.tracking_number, pl.shipped_at, lv.source_name AS offer_source, lv.price AS offer_price, lv.availability AS offer_availability, lv.last_seen_at AS offer_as_of,
        sol.line_no AS sales_line_no, sol.order_number AS sales_order_number, sol.sales_order_id
    FROM mcp_purchase_order_lines pl JOIN mcp_product_variants mv ON mv.variant_id = pl.variant_id
    LEFT JOIN mcp_listing_variants lv ON lv.listing_variant_id = pl.listing_variant_id
    LEFT JOIN mcp_sales_order_lines sol ON sol.line_id = pl.sales_order_line_id";

function po_lines(PDO $pdo, int $poId): array
{
    $st = $pdo->prepare(PO_LINE_SELECT . ' WHERE pl.purchase_order_id = :po ORDER BY pl.line_no');
    $st->execute(['po' => $poId]);
    return array_map('po_line_decode', $st->fetchAll());
}

function find_po_line(PDO $pdo, int $lineId): ?array
{
    $st = $pdo->prepare(PO_LINE_SELECT . ' WHERE pl.purchase_order_line_id = :id');
    $st->execute(['id' => $lineId]);
    $r = $st->fetch();
    return $r === false ? null : po_line_decode($r);
}

/** The supplier's events on the order, oldest first (mcp_purchase_order_events + who and which line). */
function po_events(PDO $pdo, int $poId): array
{
    $st = $pdo->prepare('SELECT e.event_id, e.purchase_order_line_id, pl.line_no, e.kind, e.source, e.supplier_order_ref, e.expected_on, e.carrier, e.tracking_number, e.shipped_at, e.reason, e.note, e.member_id,
                                (SELECT m.display_name FROM members m WHERE m.id = e.member_id) AS member_name, e.created_at
                           FROM mcp_purchase_order_events e LEFT JOIN mcp_purchase_order_lines pl ON pl.purchase_order_line_id = e.purchase_order_line_id
                          WHERE e.purchase_order_id = :po ORDER BY e.created_at, e.event_id');
    $st->execute(['po' => $poId]);
    return $st->fetchAll();
}

/** The goods receipts drafted or posted against the order. */
function po_receipts(PDO $pdo, int $poId): array
{
    $st = $pdo->prepare('SELECT goods_receipt_id, number, status, received_on, location_name, posted_at FROM mcp_goods_receipts WHERE purchase_order_id = :po ORDER BY goods_receipt_id');
    $st->execute(['po' => $poId]);
    return $st->fetchAll();
}

/** The supplier's link as the page shows it (the newest row), or null — and null for a caller without purchasing.write (the view is empty). */
function po_link_state(PDO $pdo, int $poId): ?array
{
    $st = $pdo->prepare('SELECT link_id, expires_at, rotated_at, last_used_at, view_count, created_at, is_live FROM mcp_supplier_links WHERE purchase_order_id = :po ORDER BY link_id DESC LIMIT 1');
    $st->execute(['po' => $poId]);
    $r = $st->fetch();
    if ($r === false) { return null; }
    $r['link_id'] = (int) $r['link_id'];
    $r['is_live'] = (bool) $r['is_live'];
    $r['view_count'] = (int) $r['view_count'];
    return $r;
}

/** inv_po_shows_phone(): the customer's phone reaches the supplier only for the shipping kinds the setting names. */
function po_shows_phone(PDO $pdo, int $poId): bool
{
    return (bool) one_value($pdo, 'SELECT inv_po_shows_phone(:po)', ['po' => $poId]);
}

// ---- the form's helpers ---------------------------------------------------------------------------------------------------
/** The active locations a stock purchase order may ship to: warehouses first. */
function po_locations(PDO $pdo): array
{
    return $pdo->query("SELECT location_id, name, kind FROM mcp_locations WHERE active ORDER BY (kind = 'warehouse') DESC, name")->fetchAll();
}

/** The default ship-to: the first active warehouse, else the first active location. */
function default_po_location(PDO $pdo): ?int
{
    $v = one_value($pdo, "SELECT location_id FROM mcp_locations WHERE active ORDER BY (kind = 'warehouse') DESC, location_id LIMIT 1");
    return $v === null ? null : (int) $v;
}

/** inv_reorder_candidates() kept to the supplier (best_supplier_id). Needs reports.read — a caller without it gets []. */
function reorder_rows_for(PDO $pdo, int $supplierId): array
{
    if (!has_right('reports.read')) { return []; }
    $st = $pdo->prepare('SELECT * FROM inv_reorder_candidates() WHERE best_supplier_id = :s ORDER BY product_name, sku');
    $st->execute(['s' => $supplierId]);
    return $st->fetchAll();
}

/**
 * What a purchase-order line starts from: the supplier's price-sheet entry (SKU, cost, MOQ, lead time), the supplier's offers for the variant (from inv_availability(), the ranked list kept to this
 * supplier, removed ones dropped) and the default cost: the sheet's cost, else the in-stock offer's cost (when the caller sees cost), else its price, else the variant's cost, else 0.
 * ['supplier_sku', 'unit_cost', 'moq', 'lead_time_days', 'offers' => [{listing_variant_id, source, cost, price, availability, as_of, stale}], 'listing_variant_id' (the best in-stock offer or null), 'cost_from']
 */
function po_line_defaults(PDO $pdo, int $supplierId, int $variantId): array
{
    $sheet = one_row($pdo, 'SELECT supplier_sku, cost, moq, lead_time_days FROM mcp_supplier_items WHERE supplier_id = :s AND variant_id = :v AND active', ['s' => $supplierId, 'v' => $variantId]);
    $offers = [];
    try {
        $a = json_decode((string) one_value($pdo, 'SELECT inv_availability(:v)', ['v' => $variantId]), true) ?: [];
    } catch (PDOException) {
        $a = [];
    }
    foreach (($a['offers'] ?? []) as $o) {
        if ((int) ($o['supplier_id'] ?? 0) !== $supplierId || !empty($o['removed'])) { continue; }
        $offers[] = ['listing_variant_id' => (int) $o['listing_variant_id'], 'source' => $o['source'], 'cost' => $o['cost'] ?? null, 'price' => $o['price'] ?? null, 'availability' => $o['availability'] ?? null,
                     'as_of' => $o['as_of'] ?? null, 'stale' => !empty($o['stale']), 'lead_time_days' => $o['lead_time_days'] ?? null];
    }
    $best = null;
    foreach ($offers as $o) { if (in_array($o['availability'], ['in_stock', 'limited'], true)) { $best = $o; break; } }
    $best ??= $offers[0] ?? null;
    $cost = null;
    $from = null;
    if ($sheet !== null && $sheet['cost'] !== null) { $cost = (string) $sheet['cost']; $from = 'price_sheet'; }
    elseif ($best !== null && $best['cost'] !== null) { $cost = (string) $best['cost']; $from = 'offer_cost'; }
    elseif ($best !== null && $best['price'] !== null) { $cost = (string) $best['price']; $from = 'offer_price'; }
    else {
        $vc = one_value($pdo, 'SELECT cost_price FROM mcp_product_variants WHERE variant_id = :v', ['v' => $variantId]);
        if ($vc !== null) { $cost = (string) $vc; $from = 'variant_cost'; }
    }
    return ['supplier_sku' => $sheet['supplier_sku'] ?? null, 'unit_cost' => number_format((float) ($cost ?? 0), 2, '.', ''), 'moq' => $sheet === null ? null : (int) $sheet['moq'],
            'lead_time_days' => $sheet['lead_time_days'] ?? ($best['lead_time_days'] ?? null), 'offers' => $offers, 'listing_variant_id' => $best['listing_variant_id'] ?? null, 'cost_from' => $from ?? 'none'];
}

/** The sales order's open drop-ship lines (fulfilment dropship, status open, no purchase order line yet), grouped by the supplier of the line's source: [supplier_id => {supplier_id, supplier_name, lines[], total_cost}]. A line whose source has no supplier is under key 0. */
function order_open_dropship_lines(PDO $pdo, int $orderId): array
{
    $st = $pdo->prepare("SELECT l.line_id, l.line_no, l.variant_id, l.sku, l.product_name, l.size_name, l.qty, l.source_id, l.offer_cost, l.cost_withheld, l.offer_lead_time_days, l.listing_variant_id,
                                COALESCE(s.supplier_id, 0) AS supplier_id, COALESCE(s.supplier_name, 'No supplier') AS supplier_name
                           FROM mcp_sales_order_lines l LEFT JOIN mcp_sources s ON s.source_id = l.source_id
                          WHERE l.sales_order_id = :o AND l.fulfilment_kind = 'dropship' AND l.status = 'open' AND l.purchase_order_line_id IS NULL ORDER BY s.supplier_name NULLS LAST, l.line_no");
    $st->execute(['o' => $orderId]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $sid = (int) $r['supplier_id'];
        $out[$sid] ??= ['supplier_id' => $sid, 'supplier_name' => $r['supplier_name'], 'lines' => [], 'total_cost' => 0.0];
        $out[$sid]['lines'][] = $r;
        $out[$sid]['total_cost'] += $r['cost_withheld'] || $r['offer_cost'] === null ? 0.0 : (float) $r['offer_cost'] * (int) $r['qty'];
    }
    return $out;
}

/** A sales order by id or by number (SO-00012) for the drop-ship form; null when it is not there. [{sales_order_id, number, status, customer_name, salesperson_member_id}] */
function find_sales_order_brief(PDO $pdo, string $idOrNumber): ?array
{
    $v = trim($idOrNumber);
    if ($v === '') { return null; }
    $st = $pdo->prepare('SELECT sales_order_id, number, status, customer_name, salesperson_member_id FROM mcp_sales_orders WHERE ' . (ctype_digit($v) ? 'sales_order_id = :v' : 'upper(number) = upper(:v)'));
    $st->execute(['v' => ctype_digit($v) ? (int) $v : $v]);
    $r = $st->fetch();
    if ($r === false) { return null; }
    $r['sales_order_id'] = (int) $r['sales_order_id'];
    $r['salesperson_member_id'] = $r['salesperson_member_id'] === null ? null : (int) $r['salesperson_member_id'];
    return $r;
}

/** The suppliers' filter list for the PO table: those with at least one purchase order. */
function po_suppliers(PDO $pdo): array
{
    return $pdo->query('SELECT DISTINCT supplier_id, supplier_name FROM mcp_purchase_orders ORDER BY supplier_name')->fetchAll();
}

/** The open lines of a stock purchase order for the receive screen: ordered − received > 0. */
function po_receivable_lines(PDO $pdo, int $poId): array
{
    return array_values(array_filter(po_lines($pdo, $poId), static fn (array $l): bool => !in_array($l['status'], ['declined', 'cancelled', 'received', 'closed_short'], true) && $l['qty_ordered'] > $l['qty_received']));
}

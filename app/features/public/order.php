<?php
declare(strict_types=1);

/**
 * The customer's door (orders.md "The public door"): GET /o/<48 hex>. No session, no acting member — the mcp_* views answer nothing here, so the page reads the BASE tables as the writer through
 * public_order(), after inv_secure_link_order() has said which order the token opens (live, not rotated, not expired; it counts the view). NEVER a cost, a source, a supplier's name, an internal note,
 * the salesperson or the customer's phone on what it returns.
 */

const ORDER_DOOR_LIMIT_PER_LINK_HOUR = 60;
const ORDER_DOOR_LIMIT_PER_IP_HOUR = 300;

/** The order a token opens, with what the page shows; null for every dead token alike (malformed, unknown, rotated, expired). */
function public_order(string $rawToken): ?array
{
    $pdo = db();
    $oid = one_value($pdo, 'SELECT inv_secure_link_order(:t)', ['t' => $rawToken]);
    if ($oid === null) { return null; }
    $oid = (int) $oid;
    $o = one_row($pdo, 'SELECT o.id AS sales_order_id, o.number, o.status, o.ordered_on, o.promised_on, o.delivery_method, o.location_id, o.ship_to_name, o.ship_to_address1, o.ship_to_address2, o.ship_to_city,
                               o.ship_to_region, o.ship_to_postal, o.ship_to_country, o.ship_to_notes, o.subtotal, o.discount_total, o.tax_total, o.shipping_charge, o.total, o.payment_status, o.amount_paid,
                               o.total - o.amount_paid AS balance_due FROM sales_orders o WHERE o.id = :id', ['id' => $oid]);
    if ($o === null) { return null; }
    $st = $pdo->prepare("SELECT l.line_no, p.name AS product_name, inv_size_name(v.size_key) AS size_name, l.qty, l.unit_price, l.discount, l.line_total, l.fulfilment_kind, l.status, l.location_id,
                                CASE WHEN po.status NOT IN ('draft', 'cancelled') THEN pol.expected_on END AS po_expected_on
                           FROM sales_order_lines l JOIN product_variants v ON v.id = l.variant_id JOIN products p ON p.id = v.product_id
                           LEFT JOIN purchase_order_lines pol ON pol.id = l.purchase_order_line_id LEFT JOIN purchase_orders po ON po.id = pol.purchase_order_id
                          WHERE l.sales_order_id = :o AND l.status <> 'cancelled' ORDER BY l.line_no");
    $st->execute(['o' => $oid]);
    $o['lines'] = $st->fetchAll();
    $st = $pdo->prepare('SELECT kind, carrier, tracking_number, tracking_url, shipped_at, delivered_at FROM shipments WHERE sales_order_id = :o ORDER BY shipped_at, id');
    $st->execute(['o' => $oid]);
    $o['shipments'] = $st->fetchAll();
    $st = $pdo->prepare('SELECT number, status FROM return_authorizations WHERE sales_order_id = :o ORDER BY id');
    $st->execute(['o' => $oid]);
    $o['returns'] = $st->fetchAll();
    $o['settings'] = one_row($pdo, 'SELECT business_name, business_contact_email, business_phone, currency FROM inv_settings WHERE id = 1') ?? [];
    $pickup = null;
    foreach ($o['lines'] as $l) { if ($l['fulfilment_kind'] === 'pickup' && $l['location_id'] !== null) { $pickup = (int) $l['location_id']; break; } }
    $store = $o['delivery_method'] === 'pickup' ? ($pickup ?? ($o['location_id'] === null ? null : (int) $o['location_id'])) : null;
    $o['store'] = $store === null ? null : one_row($pdo, 'SELECT name, address FROM locations WHERE id = :id', ['id' => $store]);
    $o['link'] = one_row($pdo, "SELECT id AS link_id, view_count FROM order_links_secure WHERE token_hash = encode(sha256(CAST(:t AS bytea)), 'hex')", ['t' => $rawToken]) ?? ['link_id' => null, 'view_count' => null];
    return $o;
}

/** Under the limits? Counted from the logged views of the last hour: per link (the order) and per address. */
function door_rate_ok(PDO $pdo, int $orderId, string $ip): bool
{
    $perLink = (int) one_value($pdo, "SELECT count(*) FROM activity_log WHERE action = 'order.customer_view' AND sales_order_id = :o AND occurred_at > now() - interval '1 hour'", ['o' => $orderId]);
    if ($perLink >= ORDER_DOOR_LIMIT_PER_LINK_HOUR) { return false; }
    $perIp = (int) one_value($pdo, "SELECT count(*) FROM activity_log WHERE action = 'order.customer_view' AND ip_address = CAST(:ip AS inet) AND occurred_at > now() - interval '1 hour'", ['ip' => $ip]);
    return $perIp < ORDER_DOOR_LIMIT_PER_IP_HOUR;
}

/** The customer's words for an order's status. */
function customer_status_word(string $status): string
{
    return ['quote' => 'Quote', 'confirmed' => 'Confirmed', 'in_fulfilment' => 'Being prepared', 'shipped' => 'Shipped', 'delivered' => 'Delivered', 'closed' => 'Completed', 'cancelled' => 'Cancelled'][$status] ?? ucfirst($status);
}

/** The customer's words for how a line is filled — never a supplier, a source or a cost: "from stock" · "ships from our supplier" (+ " — expected <date>") · "backordered" · "pickup". */
function customer_fulfilment_word(array $line): string
{
    return match ($line['fulfilment_kind']) {
        'dropship' => 'ships from our supplier' . (!empty($line['po_expected_on']) ? ' — expected ' . format_date((string) $line['po_expected_on']) : ''),
        'backorder' => 'backordered',
        'pickup' => 'pickup',
        default => 'from stock',
    };
}

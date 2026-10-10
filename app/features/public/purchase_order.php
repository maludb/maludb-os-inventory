<?php
declare(strict_types=1);

/**
 * The supplier's door (purchasing.md "The public door"): GET /s/<48 hex> and its three POSTs. No session, no acting member — the mcp_* views answer nothing here, so the page reads the BASE tables as the writer
 * through public_purchase_order(), after inv_secure_link_purchase_order() has said which order the token opens (live, not rotated, not expired; it counts the view). The three verbs run with source `portal` and no
 * member; each success is logged `purchase_order.supplier_*` (source portal, actor null) and tells the Buyer. NEVER the internal notes, another order, a cost the supplier did not agree, a source or the customer's phone
 * unless inv_po_shows_phone() says so.
 */

const SUPPLIER_DOOR_LIMIT_PER_LINK_HOUR = 60;
const SUPPLIER_DOOR_POST_LIMIT_PER_LINK_HOUR = 30;
const SUPPLIER_DOOR_LIMIT_PER_IP_HOUR = 300;

/** The purchase order a token opens, with what the page shows; null for every dead token alike (malformed, unknown, rotated, expired). */
function public_purchase_order(string $rawToken): ?array
{
    $pdo = db();
    $pid = one_value($pdo, 'SELECT inv_secure_link_purchase_order(:t)', ['t' => $rawToken]);
    if ($pid === null) { return null; }
    $pid = (int) $pid;
    $po = one_row($pdo, 'SELECT po.id AS purchase_order_id, po.number, po.status, po.kind, po.ordered_on, po.expected_on, po.supplier_order_ref, po.ship_to_kind, po.location_id, po.ship_to_name, po.ship_to_address1, po.ship_to_address2,
                                po.ship_to_city, po.ship_to_region, po.ship_to_postal, po.ship_to_country, po.ship_to_phone, po.ship_to_notes, po.subtotal, po.shipping_cost, po.total, po.notes, po.supplier_id,
                                (SELECT l.name FROM locations l WHERE l.id = po.location_id) AS location_name, (SELECT l.address FROM locations l WHERE l.id = po.location_id) AS location_address
                           FROM purchase_orders po WHERE po.id = :id', ['id' => $pid]);
    if ($po === null) { return null; }
    $st = $pdo->prepare("SELECT pl.id AS line_id, pl.line_no, pl.supplier_sku, v.sku, p.name AS product_name, inv_size_name(v.size_key) AS size_name, pl.qty_ordered, pl.unit_cost, pl.qty_ordered * pl.unit_cost AS line_cost,
                                pl.status, pl.expected_on, pl.tracking_carrier, pl.tracking_number, pl.shipped_at, pl.supplier_note
                           FROM purchase_order_lines pl JOIN product_variants v ON v.id = pl.variant_id JOIN products p ON p.id = v.product_id WHERE pl.purchase_order_id = :po ORDER BY pl.line_no");
    $st->execute(['po' => $pid]);
    $po['lines'] = $st->fetchAll();
    $po['supplier'] = one_row($pdo, 'SELECT name, account_number FROM suppliers WHERE id = :id', ['id' => $po['supplier_id']]) ?? [];
    $po['shows_phone'] = (bool) one_value($pdo, 'SELECT inv_po_shows_phone(:po)', ['po' => $pid]);
    $po['settings'] = one_row($pdo, 'SELECT business_name, business_contact_email, business_phone, currency FROM inv_settings WHERE id = 1') ?? [];
    $po['link'] = one_row($pdo, "SELECT id AS link_id, view_count FROM supplier_links_secure WHERE token_hash = encode(sha256(CAST(:t AS bytea)), 'hex')", ['t' => $rawToken]) ?? ['link_id' => null, 'view_count' => null];
    return $po;
}

/** The supplier's words for an order's status. */
function supplier_status_word(string $status): string
{
    return ['sent' => 'New — please acknowledge', 'acknowledged' => 'Acknowledged', 'partial' => 'Partly shipped / received', 'received' => 'Received — thank you', 'closed' => 'Closed', 'closed_short' => 'Closed', 'cancelled' => 'Cancelled',
            'draft' => 'Not yet sent'][$status] ?? ucfirst($status);
}

/** The supplier's words for a line's status. */
function supplier_line_word(string $status): string
{
    return ['open' => 'Open', 'acknowledged' => 'Acknowledged', 'declined' => 'Declined', 'partial' => 'Partly received', 'shipped' => 'Shipped', 'received' => 'Received', 'closed_short' => 'Closed', 'cancelled' => 'Cancelled'][$status] ?? ucfirst($status);
}

/** The forms show while the order is sent, acknowledged or partly received. */
function door_open(array $po): bool
{
    return in_array($po['status'], ['sent', 'acknowledged', 'partial'], true);
}

/** Under the view limits? Counted from the logged views of the last hour: per link (the order) and per address. */
function door_view_rate_ok(PDO $pdo, int $poId, string $ip): bool
{
    $perLink = (int) one_value($pdo, "SELECT count(*) FROM activity_log WHERE action = 'purchase_order.supplier_view' AND purchase_order_id = :o AND occurred_at > now() - interval '1 hour'", ['o' => $poId]);
    if ($perLink >= SUPPLIER_DOOR_LIMIT_PER_LINK_HOUR) { return false; }
    $perIp = (int) one_value($pdo, "SELECT count(*) FROM activity_log WHERE action = 'purchase_order.supplier_view' AND ip_address = CAST(:ip AS inet) AND occurred_at > now() - interval '1 hour'", ['ip' => $ip]);
    return $perIp < SUPPLIER_DOOR_LIMIT_PER_IP_HOUR;
}

/** Under the POST limits? The supplier's three actions logged in the last hour: per link and per address. */
function door_post_rate_ok(PDO $pdo, int $poId, string $ip): bool
{
    $acts = "('purchase_order.supplier_ack', 'purchase_order.supplier_decline', 'purchase_order.supplier_tracking')";
    $perLink = (int) one_value($pdo, "SELECT count(*) FROM activity_log WHERE action IN $acts AND source = 'portal' AND purchase_order_id = :o AND occurred_at > now() - interval '1 hour'", ['o' => $poId]);
    if ($perLink >= SUPPLIER_DOOR_POST_LIMIT_PER_LINK_HOUR) { return false; }
    $perIp = (int) one_value($pdo, "SELECT count(*) FROM activity_log WHERE action IN $acts AND source = 'portal' AND ip_address = CAST(:ip AS inet) AND occurred_at > now() - interval '1 hour'", ['ip' => $ip]);
    return $perIp < SUPPLIER_DOOR_LIMIT_PER_IP_HOUR;
}

/** One log row of the door: source portal, no actor, the purchase order's audit keys (a drop-ship's sales order, a stock order's location), never the token. */
function door_log(PDO $pdo, string $action, array $po, array $after): void
{
    $so = one_row($pdo, 'SELECT sales_order_id, location_id, kind FROM purchase_orders WHERE id = :id', ['id' => $po['purchase_order_id']]) ?? [];
    log_activity($pdo, $action, 'purchase_order', (int) $po['purchase_order_id'], ['actor_member_id' => null, 'source' => 'portal', 'purchase_order_id' => (int) $po['purchase_order_id'],
        'sales_order_id' => $so['sales_order_id'] ?? null, 'location_id' => ($so['kind'] ?? '') === 'stock' ? ($so['location_id'] ?? null) : null,
        'after' => ['number' => $po['number'], 'link_id' => $po['link']['link_id'] === null ? null : (int) $po['link']['link_id']] + $after]);
}

/** A line of this order from the door's form, or an error sentence. */
function door_line(PDO $pdo, int $poId, mixed $raw): array|string
{
    $v = trim((string) $raw);
    if ($v === '' || !ctype_digit($v)) { return 'Choose the line.'; }
    $l = one_row($pdo, 'SELECT id, line_no, status, purchase_order_id FROM purchase_order_lines WHERE id = :id', ['id' => (int) $v]);
    if ($l === null || (int) $l['purchase_order_id'] !== $poId) { return 'That line is not on this purchase order.'; }
    return $l;
}

/** The refusal text of the database (a P0001 RAISE or a trigger's check), or null for anything else. */
function door_sentence(Throwable $e): ?string
{
    if ($e instanceof DomainException) { return $e->getMessage(); }
    if ($e instanceof PDOException && in_array((string) $e->getCode(), ['P0001', '23514'], true)) { return db_message($e, 'That could not be done.'); }
    return null;
}

/** Acknowledge: `supplier_ref` (≤ 100), `expected_on` (a date), `line` (one line or the whole order). Returns ['ok', 'message', 'status' (http)]. */
function door_acknowledge(PDO $pdo, int $poId, array $post, array $po): array
{
    $ref = trim((string) ($post['supplier_ref'] ?? ''));
    if (mb_strlen($ref) > 100) { return ['ok' => false, 'message' => 'The reference is up to 100 characters.', 'status' => 422]; }
    $exp = trim((string) ($post['expected_on'] ?? ''));
    if ($exp !== '') {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $exp);
        if ($d === false || $d->format('Y-m-d') !== $exp) { return ['ok' => false, 'message' => 'The expected date is a date.', 'status' => 422]; }
    }
    $lineId = null;
    $lineNo = null;
    if (trim((string) ($post['line'] ?? '')) !== '' && (string) $post['line'] !== '0') {
        $l = door_line($pdo, $poId, $post['line']);
        if (is_string($l)) { return ['ok' => false, 'message' => $l, 'status' => 422]; }
        $lineId = (int) $l['id'];
        $lineNo = (int) $l['line_no'];
    }
    try {
        $pdo->beginTransaction();
        $r = acknowledge_purchase_order($pdo, $poId, $lineId, $ref === '' ? null : $ref, $exp === '' ? null : $exp, 'portal', null);
        door_log($pdo, 'purchase_order.supplier_ack', $po, ['line_id' => $lineId, 'supplier_order_ref' => $ref === '' ? null : $ref, 'expected_on' => $exp === '' ? null : $exp]);
        notify_buyer($pdo, 'po_ack', 'purchase_order', $poId, $po['number'] . ' acknowledged by ' . $po['supplier']['name'] . ($ref !== '' ? ' — ref ' . $ref : '') . ($exp !== '' ? ', expected ' . format_date($exp) : '') . ($lineNo !== null ? ' (line ' . $lineNo . ')' : ''));
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $s = door_sentence($e);
        if ($s === null) { throw $e; }
        return ['ok' => false, 'message' => $s, 'status' => 422];
    }
    return ['ok' => true, 'message' => 'Acknowledged — thank you.', 'status' => 200];
}

/** Decline a line: `line` (an open or acknowledged line) and `reason` (required, ≤ 500). */
function door_decline(PDO $pdo, int $poId, array $post, array $po): array
{
    $reason = trim((string) ($post['reason'] ?? ''));
    if ($reason === '') { return ['ok' => false, 'message' => 'Say why you cannot fill the line.', 'status' => 422]; }
    if (mb_strlen($reason) > 500) { return ['ok' => false, 'message' => 'The reason is up to 500 characters.', 'status' => 422]; }
    $l = door_line($pdo, $poId, $post['line'] ?? '');
    if (is_string($l)) { return ['ok' => false, 'message' => $l, 'status' => 422]; }
    try {
        $pdo->beginTransaction();
        $r = decline_po_line($pdo, (int) $l['id'], $reason, 'portal', null);
        door_log($pdo, 'purchase_order.supplier_decline', $po, ['line_id' => (int) $l['id'], 'line_no' => (int) $l['line_no'], 'reason' => $reason, 'salesperson_told' => $r['salesperson_told']]);
        notify_buyer($pdo, 'po_decline', 'purchase_order', $poId, $po['supplier']['name'] . ' declined line ' . $l['line_no'] . ' of ' . $po['number'] . ': ' . $reason);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $s = door_sentence($e);
        if ($s === null) { throw $e; }
        return ['ok' => false, 'message' => $s, 'status' => 422];
    }
    return ['ok' => true, 'message' => 'Line ' . $l['line_no'] . ' declined.', 'status' => 200];
}

/** Add tracking: `line`, `carrier` (≤ 60), `tracking` (required, ≤ 100), `shipped_at` (a date or a date and time; now by default). */
function door_tracking(PDO $pdo, int $poId, array $post, array $po): array
{
    $carrier = trim((string) ($post['carrier'] ?? ''));
    if (mb_strlen($carrier) > 60) { return ['ok' => false, 'message' => 'The carrier is up to 60 characters.', 'status' => 422]; }
    $tracking = trim((string) ($post['tracking'] ?? ''));
    if ($tracking === '') { return ['ok' => false, 'message' => 'Give the tracking number.', 'status' => 422]; }
    if (mb_strlen($tracking) > 100) { return ['ok' => false, 'message' => 'The tracking number is up to 100 characters.', 'status' => 422]; }
    $at = null;
    $raw = trim((string) ($post['shipped_at'] ?? ''));
    if ($raw !== '') {
        try { $at = (new DateTimeImmutable(str_replace('T', ' ', $raw), new DateTimeZone('UTC')))->format('Y-m-d H:i:sP'); }
        catch (Exception) { return ['ok' => false, 'message' => 'The ship date is a date and time.', 'status' => 422]; }
    }
    $l = door_line($pdo, $poId, $post['line'] ?? '');
    if (is_string($l)) { return ['ok' => false, 'message' => $l, 'status' => 422]; }
    try {
        $pdo->beginTransaction();
        $r = track_po_line($pdo, (int) $l['id'], $carrier === '' ? null : $carrier, $tracking, $at, 'portal', null);
        door_log($pdo, 'purchase_order.supplier_tracking', $po, ['line_id' => (int) $l['id'], 'line_no' => (int) $l['line_no'], 'carrier' => $carrier === '' ? null : $carrier, 'tracking_number' => $tracking, 'shipped_at' => $at, 'shipment_id' => $r['shipment_id']]);
        notify_buyer($pdo, 'po_tracking', 'purchase_order', $poId, $po['supplier']['name'] . ' shipped line ' . $l['line_no'] . ' of ' . $po['number'] . ': ' . trim($carrier . ' ' . $tracking));
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $s = door_sentence($e);
        if ($s === null) { throw $e; }
        return ['ok' => false, 'message' => $s, 'status' => 422];
    }
    return ['ok' => true, 'message' => 'Tracking added for line ' . $l['line_no'] . '.', 'status' => 200];
}

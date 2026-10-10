<?php
declare(strict_types=1);

/**
 * Returns (returns-worker.md "Query functions"): read from mcp_return_authorizations / mcp_return_lines (every reader sees every return — the views; a customer's name is on the row, never their contact
 * details); written by the schema's verbs (db/012) — the database keeps what may come back, what a reason is and what receiving does. PHP decodes, validates what the form can get wrong and presents refusals.
 */

const RETURN_STATUSES = ['requested' => 'Requested', 'approved' => 'Approved', 'received' => 'Received', 'closed' => 'Closed', 'denied' => 'Denied'];
const RETURN_METHODS = ['pickup' => 'Pickup', 'drop_off' => 'Drop-off'];
const RETURN_DISPOSITIONS = ['restock' => 'Restock', 'floor_model' => 'Floor model', 'dispose' => 'Dispose', 'return_to_supplier' => 'Return to supplier', 'donate' => 'Donate'];
const RETURN_PAGE = 50;

function return_decode(array $r): array
{
    foreach (['return_id', 'sales_order_id', 'customer_id'] as $k) { $r[$k] = (int) $r[$k]; }
    foreach (['location_id', 'requested_by', 'approved_by', 'received_by', 'closed_by', 'denied_by', 'line_count'] as $k) { if (array_key_exists($k, $r)) { $r[$k] = $r[$k] === null ? null : (int) $r[$k]; } }
    if (array_key_exists('dispositions', $r)) { $r['dispositions'] = is_array($r['dispositions']) ? $r['dispositions'] : pg_text_array((string) $r['dispositions']); }
    return $r;
}

const RETURN_SELECT = "SELECT ra.return_id, ra.number, ra.sales_order_id, ra.order_number, ra.customer_id, ra.customer_name, ra.status, ra.method, ra.scheduled_on, ra.location_id, l.name AS location_name, ra.refund_amount, ra.restocking_fee,
        ra.notes, ra.requested_by, rq.display_name AS requested_by_name, ra.approved_by, ra.approved_at, ra.received_by, ra.received_at, ra.closed_by, ra.closed_at, ra.denied_by, ra.denied_at, ra.deny_reason, ra.created_at, ra.updated_at,
        (SELECT count(*) FROM mcp_return_lines rl WHERE rl.return_id = ra.return_id) AS line_count,
        (SELECT COALESCE(array_agg(DISTINCT rl.disposition ORDER BY rl.disposition), '{}') FROM mcp_return_lines rl WHERE rl.return_id = ra.return_id) AS dispositions
    FROM mcp_return_authorizations ra LEFT JOIN mcp_locations l ON l.location_id = ra.location_id LEFT JOIN mcp_members rq ON rq.member_id = ra.requested_by";

function returns_where(array $f, array &$args): string
{
    $sql = '';
    $status = $f['status'] ?? [];
    $status = is_array($status) ? $status : array_filter(explode(',', (string) $status));
    $status = array_values(array_filter(array_map('strval', $status), static fn (string $s): bool => isset(RETURN_STATUSES[$s])));
    if ($status !== []) { $sql .= " AND ra.status IN ('" . implode("','", $status) . "')"; }
    if (!empty($f['customer'])) { $sql .= ' AND ra.customer_id = :customer'; $args['customer'] = (int) $f['customer']; }
    if (!empty($f['order'])) { $sql .= ' AND ra.sales_order_id = :order'; $args['order'] = (int) $f['order']; }
    if (!empty($f['awaiting_disposition'])) { $sql .= " AND (ra.status = 'received' OR (ra.status = 'approved' AND ra.scheduled_on IS NOT NULL AND ra.scheduled_on <= current_date))"; }
    foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
        if (!empty($f[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $f[$k]) === 1) { $sql .= " AND ra.created_at::date $op CAST(:$k AS date)"; $args[$k] = $f[$k]; }
    }
    return $sql;
}

/** The returns, newest first, 50 a page: ['rows', 'total', 'page', 'pages']. */
function find_returns(PDO $pdo, array $f, int $page = 1): array
{
    $args = [];
    $where = returns_where($f, $args);
    $t = $pdo->prepare('SELECT count(*) FROM mcp_return_authorizations ra WHERE true' . $where);
    $t->execute($args);
    $total = (int) $t->fetchColumn();
    $st = $pdo->prepare(RETURN_SELECT . ' WHERE true' . $where . ' ORDER BY ra.created_at DESC, ra.return_id DESC LIMIT ' . RETURN_PAGE . ' OFFSET ' . (max(1, $page) - 1) * RETURN_PAGE);
    $st->execute($args);
    return ['rows' => array_map('return_decode', $st->fetchAll()), 'total' => $total, 'page' => max(1, $page), 'pages' => max(1, (int) ceil($total / RETURN_PAGE))];
}

/** One return with the facts of its order a page needs (the salesperson, the order's status and what was paid); null = not here or not the caller's to see. */
function find_return(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare(RETURN_SELECT . ' WHERE ra.return_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) { return null; }
    $r = return_decode($r);
    $o = one_row($pdo, 'SELECT o.salesperson_member_id, o.status AS order_status, o.amount_paid, o.location_id AS order_location_id FROM mcp_sales_orders o WHERE o.sales_order_id = :o', ['o' => $r['sales_order_id']]) ?? [];
    $r['salesperson_member_id'] = ($o['salesperson_member_id'] ?? null) === null ? null : (int) $o['salesperson_member_id'];
    $r['order_status'] = $o['order_status'] ?? null;
    $r['amount_paid'] = $o['amount_paid'] ?? null;
    $r['order_location_id'] = ($o['order_location_id'] ?? null) === null ? null : (int) $o['order_location_id'];
    return $r;
}

/** The lines of a return with what the order line says (shipped, already returned) and the names a screen shows. */
function return_lines(PDO $pdo, int $returnId): array
{
    $st = $pdo->prepare('SELECT rl.return_line_id, rl.return_id, rl.sales_order_line_id, rl.variant_id, rl.sku, sol.line_no, sol.product_name, sol.size_name, rl.qty, rl.qty_received, rl.reason_code_id, rl.reason_code, rc.name AS reason_name,
                                rl.disposition, rl.location_id, l.name AS location_name, rl.condition_note, pv.barcode, sol.qty AS ordered, sol.qty_shipped, sol.qty_returned, sol.fulfilment_kind, sol.purchase_order_line_id
                           FROM mcp_return_lines rl JOIN mcp_sales_order_lines sol ON sol.line_id = rl.sales_order_line_id JOIN mcp_reason_codes rc ON rc.reason_code_id = rl.reason_code_id JOIN mcp_product_variants pv ON pv.variant_id = rl.variant_id LEFT JOIN mcp_locations l ON l.location_id = rl.location_id
                          WHERE rl.return_id = :r ORDER BY sol.line_no, rl.return_line_id');
    $st->execute(['r' => $returnId]);
    return array_map(static function (array $l): array {
        foreach (['return_line_id', 'return_id', 'sales_order_line_id', 'variant_id', 'line_no', 'qty', 'qty_received', 'reason_code_id', 'ordered', 'qty_shipped', 'qty_returned'] as $k) { $l[$k] = (int) $l[$k]; }
        $l['location_id'] = $l['location_id'] === null ? null : (int) $l['location_id'];
        return $l;
    }, $st->fetchAll());
}

function find_return_line(PDO $pdo, int $lineId): ?array
{
    $id = one_value($pdo, 'SELECT return_id FROM mcp_return_lines WHERE return_line_id = :l', ['l' => $lineId]);
    if ($id === null) { return null; }
    foreach (return_lines($pdo, (int) $id) as $l) { if ($l['return_line_id'] === $lineId) { return $l; } }
    return null;
}

/**
 * The lines of an order that can still come back: shipped − already returned − the quantities on other open (requested or approved) returns. $exceptReturn leaves one return's own lines out
 * of the count (its edit form). [{line_id, line_no, sku, product_name, size_name, qty_shipped, qty_returned, pending, returnable, fulfilment_kind}]
 */
function returnable_lines(PDO $pdo, int $orderId, ?int $exceptReturn = null): array
{
    $st = $pdo->prepare("SELECT l.line_id, l.line_no, l.variant_id, l.sku, l.product_name, l.size_name, l.qty_shipped, l.qty_returned, l.fulfilment_kind,
                                COALESCE((SELECT sum(rl.qty) FROM mcp_return_lines rl JOIN mcp_return_authorizations ra ON ra.return_id = rl.return_id
                                           WHERE rl.sales_order_line_id = l.line_id AND ra.status IN ('requested', 'approved') AND ra.return_id IS DISTINCT FROM CAST(:except AS bigint)), 0) AS pending
                           FROM mcp_sales_order_lines l WHERE l.sales_order_id = :o AND l.status <> 'cancelled' ORDER BY l.line_no");
    $st->bindValue(':o', $orderId, PDO::PARAM_INT);
    $st->bindValue(':except', $exceptReturn, $exceptReturn === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->execute();
    $out = [];
    foreach ($st->fetchAll() as $l) {
        $l['returnable'] = max(0, (int) $l['qty_shipped'] - (int) $l['qty_returned'] - (int) $l['pending']);
        foreach (['line_id', 'line_no', 'variant_id', 'qty_shipped', 'qty_returned', 'pending'] as $k) { $l[$k] = (int) $l[$k]; }
        if ($l['returnable'] > 0 || $exceptReturn !== null) { $out[] = $l; }
    }
    return $out;
}

/** The orders a return may be requested on — confirmed or later with some line shipped and not all returned — for the picker. [{sales_order_id, number, customer_name, status, ordered_on}] */
function orders_with_returnable_lines(PDO $pdo, string $q = '', int $limit = 25, int $offset = 0): array
{
    $st = $pdo->prepare("SELECT o.sales_order_id, o.number, o.customer_name, o.status, o.ordered_on FROM mcp_sales_orders o
                          WHERE o.status IN ('confirmed', 'in_fulfilment', 'shipped', 'delivered', 'closed')
                            AND EXISTS (SELECT 1 FROM mcp_sales_order_lines l WHERE l.sales_order_id = o.sales_order_id AND l.status <> 'cancelled' AND l.qty_shipped - l.qty_returned > 0)
                            AND (CAST(:q AS text) = '' OR o.number ILIKE '%' || CAST(:q AS text) || '%' OR o.customer_name ILIKE '%' || CAST(:q AS text) || '%')
                          ORDER BY o.ordered_on DESC, o.sales_order_id DESC LIMIT " . max(1, min(100, $limit)) . ' OFFSET ' . max(0, $offset));
    $st->execute(['q' => $q]);
    return $st->fetchAll();
}

function count_orders_with_returnable_lines(PDO $pdo, string $q = ''): int
{
    return (int) one_value($pdo, "SELECT count(*) FROM mcp_sales_orders o WHERE o.status IN ('confirmed', 'in_fulfilment', 'shipped', 'delivered', 'closed')
        AND EXISTS (SELECT 1 FROM mcp_sales_order_lines l WHERE l.sales_order_id = o.sales_order_id AND l.status <> 'cancelled' AND l.qty_shipped - l.qty_returned > 0)
        AND (CAST(:q AS text) = '' OR o.number ILIKE '%' || CAST(:q AS text) || '%' OR o.customer_name ILIKE '%' || CAST(:q AS text) || '%')", ['q' => $q]);
}

/** The reasons a person may choose for a return line (active return reasons). [{reason_code_id, code, name}] */
function return_reasons(PDO $pdo): array
{
    return $pdo->query("SELECT reason_code_id, code, name FROM mcp_reason_codes WHERE 'return' = ANY (applies_to) AND active ORDER BY sort_order, name")->fetchAll();
}

/** A reason code by id or code (any active code — the trigger refuses one that is not a return reason in its own words). Null when there is none. */
function find_return_reason(PDO $pdo, string $v): ?array
{
    $v = trim($v);
    if ($v === '') { return null; }
    return one_row($pdo, 'SELECT reason_code_id, code, name, applies_to FROM mcp_reason_codes WHERE active AND ' . (ctype_digit($v) ? 'reason_code_id = :v' : 'lower(code) = lower(:v)'), ['v' => ctype_digit($v) ? (int) $v : $v]);
}

/** A location a return comes back to (active). */
function return_locations(PDO $pdo): array
{
    return $pdo->query("SELECT location_id, name, kind, is_sellable FROM mcp_locations WHERE active ORDER BY (kind = 'returns') DESC, is_sellable DESC, name")->fetchAll();
}

/** A return's story: its own rows of the activity log, oldest first (the order's page shows the same rows through the order's key). */
function return_timeline(PDO $pdo, int $id, int $limit = 100): array
{
    $st = $pdo->prepare("SELECT activity_id, occurred_at, actor_member_id, actor_name, actor_is_agent, source, action, screen, entity_type, entity_id, after, agent_run_id, source_id, sales_order_id, purchase_order_id, location_id, token_id
                           FROM mcp_activity_log WHERE entity_type = 'return_authorization' AND entity_id = :id AND action <> 'screen.view' ORDER BY occurred_at, activity_id LIMIT " . max(1, min(500, $limit)));
    $st->execute(['id' => $id]);
    return $st->fetchAll();
}

/** Does the order already hold a refund of this amount? (Only a caller who may see payments can know: null otherwise.) */
function order_has_refund_of(PDO $pdo, int $orderId, string $amount): ?bool
{
    if (!has_right('payments.record') && !has_right('reports.read')) { return null; }
    return one_value($pdo, "SELECT 1 FROM mcp_order_payments WHERE sales_order_id = :o AND kind = 'refund' AND amount = CAST(:a AS numeric)", ['o' => $orderId, 'a' => $amount]) !== null;
}

/** The dispositions of the lines of some returns, by return: return_id => [disposition, …] in line order (the list's chips). */
function return_dispositions_by_return(PDO $pdo, array $ids): array
{
    if ($ids === []) { return []; }
    $st = $pdo->prepare('SELECT return_id, disposition FROM mcp_return_lines WHERE return_id = ANY (CAST(:ids AS bigint[])) ORDER BY return_id, return_line_id');
    $st->execute(['ids' => pg_array_literal(array_map('intval', $ids))]);
    $out = [];
    foreach ($st->fetchAll() as $r) { $out[(int) $r['return_id']][] = $r['disposition']; }
    return $out;
}

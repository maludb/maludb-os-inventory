<?php
declare(strict_types=1);

/**
 * The home's regions (screen `home`, reports-admin.md "Home"): one function, one call per region, each honouring its right and answering null for a region the person has no right to. The database is the referee:
 * every region is a SQL function's or a view's rows as the caller (the cost wall stands); PHP only gathers and words them. Home writes nothing.
 *   note        the morning note's seven headings as counts (morning_note(), counts only), `withheld` per heading
 *   at_risk     inv_lines_at_risk(), at most 25 — every reader
 *   sources     inv_source_health() with health failing · blocked · paused
 *   unmatched   listings.match: the count and the five newest of inv_unmatched_listings()
 *   po_ack      inv_purchase_orders_open() with awaiting_ack
 *   today       fulfilment_today() summarized per location and delivery method
 *   my_orders   orders.write: my open orders with their next step
 *   warehouse   stock.receive|stock.ship|stock.count: to receive, to pick, to count
 *   admin       settings.manage|feed.keys|agents.settings: the feed's usage today and the dispatches
 *   bell        the five newest unread notifications
 */

require_once dirname(__DIR__) . '/buyer/queries.php';
require_once dirname(__DIR__) . '/orders/queries.php';
require_once dirname(__DIR__) . '/feed/queries.php';

const HOME_AT_RISK_MAX = 25;

function home_summary(PDO $pdo, int $memberId): array
{
    $may = [
        'sales' => has_right('orders.write'),
        'warehouse' => has_right('stock.receive') || has_right('stock.ship') || has_right('stock.count'),
        'match' => has_right('listings.match'),
        'admin' => has_right('settings.manage') || has_right('feed.keys') || has_right('agents.settings'),
        'cost' => sees_cost(),
    ];
    $st = $pdo->prepare('SELECT notification_id, kind, record_type, record_id, title, body, created_at FROM mcp_notifications WHERE member_id = :m AND read_at IS NULL ORDER BY created_at DESC LIMIT 5');
    $st->execute(['m' => $memberId]);
    $today = business_today($pdo);
    return [
        'note' => home_note($pdo),
        'at_risk' => home_at_risk($pdo),
        'sources' => home_sources($pdo),
        'unmatched' => $may['match'] ? home_unmatched($pdo) : null,
        'po_ack' => home_po_ack($pdo),
        'today' => home_today($pdo, $today),
        'my_orders' => $may['sales'] ? [
            'rows' => my_open_orders($pdo, $memberId, 10),
            'count' => (int) (one_value($pdo, "SELECT count(*) FROM mcp_sales_orders WHERE salesperson_member_id = :m AND status IN ('quote', 'confirmed', 'in_fulfilment', 'shipped')", ['m' => $memberId]) ?? 0),
        ] : null,
        'warehouse' => $may['warehouse'] ? warehouse_block($pdo) : null,
        'admin' => $may['admin'] ? admin_block($pdo) : null,
        'bell' => $st->fetchAll(),
        'may' => $may,
    ];
}

/** The seven headings as counts (the morning note's own function, no rows); each {count, withheld}, and the purchase orders' three counts. */
function home_note(PDO $pdo): array
{
    $n = morning_note($pdo, null, 1);
    $out = ['date' => $n['date'], 'headings' => []];
    foreach (array_keys(MORNING_HEADINGS) as $k) {
        $h = $n['headings'][$k];
        $out['headings'][$k] = ['count' => $h['count'], 'withheld' => (bool) $h['withheld']];
    }
    foreach (['awaiting_ack', 'overdue', 'untracked'] as $k) { $out['headings']['purchase_orders'][$k] = $n['headings']['purchase_orders'][$k] ?? null; }
    return $out;
}

/** Lines at risk, at most 25, with the whole count. {count, rows} */
function home_at_risk(PDO $pdo): array
{
    $all = $pdo->query('SELECT * FROM inv_lines_at_risk() ORDER BY promised_on NULLS LAST, order_number, line_no')->fetchAll();
    return ['count' => count($all), 'rows' => array_slice($all, 0, HOME_AT_RISK_MAX)];
}

/** Sources whose health asks for a person: failing · blocked · paused. {count, rows} */
function home_sources(PDO $pdo): array
{
    $rows = $pdo->query("SELECT source_id, name, connector, role, health, last_pull_at, last_error, consecutive_failures, paused_reason FROM inv_source_health() WHERE health IN ('failing', 'blocked', 'paused') ORDER BY health, name")->fetchAll();
    return ['count' => count($rows), 'rows' => $rows];
}

/** The count and the five newest unmatched listing variants. {count, rows} */
function home_unmatched(PDO $pdo): array
{
    $rows = $pdo->query('SELECT listing_variant_id, listing_id, source_id, source_name, title, variant_title, sku, price, first_seen_at FROM inv_unmatched_listings(NULL) ORDER BY first_seen_at DESC, listing_variant_id DESC')->fetchAll();
    return ['count' => count($rows), 'rows' => array_slice($rows, 0, 5)];
}

/** Purchase orders sent and not acknowledged past ack_days. {count, rows} */
function home_po_ack(PDO $pdo): array
{
    $rows = $pdo->query('SELECT purchase_order_id, number, kind, supplier_name, sent_at, days_waiting, total, cost_withheld, sales_order_number FROM inv_purchase_orders_open() WHERE awaiting_ack ORDER BY sent_at, number')->fetchAll();
    return ['count' => count($rows), 'rows' => $rows];
}

/** Today's deliveries and pickups per location and delivery method (fulfilment_today() summarized). {day, groups: [{location_id, location_name, delivery_method, orders, lines}], orders, lines, dropships_expected} */
function home_today(PDO $pdo, string $day): array
{
    $f = fulfilment_today($pdo, $day);
    $groups = [];
    $orders = 0;
    $lines = 0;
    foreach ($f['groups'] as $g) {
        $n = 0;
        foreach ($g['orders'] as $o) { $n += count($o['lines']); }
        $groups[] = ['location_id' => $g['location_id'], 'location_name' => $g['location_name'], 'delivery_method' => $g['delivery_method'], 'orders' => count($g['orders']), 'lines' => $n];
        $orders += count($g['orders']);
        $lines += $n;
    }
    return ['day' => $day, 'groups' => $groups, 'orders' => $orders, 'lines' => $lines, 'dropships_expected' => count($f['dropships_expected'])];
}

/**
 * The caller's open orders (quote · confirmed · in_fulfilment · shipped), the late ones first and then the soonest promised, at most $limit, each with its next step (order_next_step()).
 * Each row: the order's list columns + next_step (the words — "Late by N days" for a late order), step (the plain step), days_late.
 */
function my_open_orders(PDO $pdo, int $memberId, int $limit = 10): array
{
    $st = $pdo->prepare("SELECT sales_order_id, number, customer_id, customer_name, status, payment_status, promised_on, total, balance_due, is_late, location_name, delivery_method, line_count
                           FROM mcp_sales_orders WHERE salesperson_member_id = :m AND status IN ('quote', 'confirmed', 'in_fulfilment', 'shipped')
                          ORDER BY COALESCE(promised_on < CAST(:d AS date) AND status <> 'quote', false) DESC, promised_on NULLS LAST, sales_order_id LIMIT " . max(1, min(50, $limit)));
    $today = business_today($pdo);
    $st->execute(['m' => $memberId, 'd' => $today]);
    $out = [];
    foreach ($st->fetchAll() as $o) {
        $o['days_late'] = $o['promised_on'] !== null && $o['promised_on'] < $today && $o['status'] !== 'quote'
            ? (int) round((strtotime($today) - strtotime((string) $o['promised_on'])) / 86400) : 0;
        $o['step'] = order_next_step($pdo, $o, false);
        $o['next_step'] = $o['days_late'] > 0 ? 'Late by ' . $o['days_late'] . ' day' . ($o['days_late'] === 1 ? '' : 's') : $o['step'];
        $out[] = $o;
    }
    return $out;
}

/**
 * What an order waits for, said in a verb: quote "Confirm"; confirmed and unpaid "Record a deposit"; then "Ship" (a stock or pickup line allocated), "Waiting on <supplier>" (a drop-ship ordered), "Backorder — choose a source",
 * "Draft a purchase order" (a drop-ship line not yet ordered); shipped "Deliver"; delivered "Close"; late "Late by N days" first (when $late).
 */
function order_next_step(PDO $pdo, array $order, bool $late = true): string
{
    $today = business_today($pdo);
    $status = (string) $order['status'];
    if ($late && ($order['promised_on'] ?? null) !== null && $order['promised_on'] < $today && in_array($status, ['confirmed', 'in_fulfilment', 'shipped'], true)) {
        $d = (int) round((strtotime($today) - strtotime((string) $order['promised_on'])) / 86400);
        return 'Late by ' . $d . ' day' . ($d === 1 ? '' : 's');
    }
    switch ($status) {
        case 'quote': return 'Confirm';
        case 'shipped': return 'Deliver';
        case 'delivered': return 'Close';
        case 'closed': return 'Done';
        case 'cancelled': return 'Cancelled';
    }
    if ($status === 'confirmed' && (string) ($order['payment_status'] ?? '') === 'unpaid') { return 'Record a deposit'; }
    $st = $pdo->prepare("SELECT fulfilment_kind, status, source_id, source_name, qty, qty_allocated, qty_shipped FROM mcp_sales_order_lines WHERE sales_order_id = :o AND status <> 'cancelled' ORDER BY line_no");
    $st->execute(['o' => $order['sales_order_id']]);
    $lines = $st->fetchAll();
    foreach ($lines as $l) {
        if (in_array($l['fulfilment_kind'], ['stock', 'pickup'], true) && (int) $l['qty_allocated'] > (int) $l['qty_shipped']) { return 'Ship'; }
    }
    foreach ($lines as $l) {
        if ($l['fulfilment_kind'] === 'dropship' && $l['status'] === 'ordered') {
            $sup = $l['source_id'] === null ? null : one_value($pdo, 'SELECT supplier_name FROM mcp_sources WHERE source_id = :s', ['s' => $l['source_id']]);
            return 'Waiting on ' . ($sup ?? $l['source_name'] ?? 'the supplier');
        }
    }
    foreach ($lines as $l) {
        if ($l['fulfilment_kind'] === 'backorder' && $l['status'] !== 'shipped') { return 'Backorder — choose a source'; }
    }
    foreach ($lines as $l) {
        if ($l['fulfilment_kind'] === 'dropship' && $l['status'] === 'open') { return 'Draft a purchase order'; }
    }
    return 'Ship';
}

/** For Warehouse: to receive (draft receipts and stock purchase orders expected today or earlier), to pick (today's stock and pickup lines by location), to count (open counts). */
function warehouse_block(PDO $pdo): array
{
    $today = business_today($pdo);
    $receipts = $pdo->query("SELECT goods_receipt_id, number, supplier_name, location_name, purchase_order_number, received_on FROM mcp_goods_receipts WHERE status = 'draft' ORDER BY goods_receipt_id")->fetchAll();
    $st = $pdo->prepare("SELECT purchase_order_id, number, supplier_name, expected_on, status FROM inv_purchase_orders_open() WHERE kind = 'stock' AND status IN ('sent', 'acknowledged', 'partial') AND expected_on IS NOT NULL AND expected_on <= CAST(:d AS date) ORDER BY expected_on, number");
    $st->execute(['d' => $today]);
    $pos = $st->fetchAll();
    $f = fulfilment_today($pdo, $today);
    $pick = [];
    foreach ($f['groups'] as $g) {
        $lines = 0;
        $qty = 0;
        $orders = [];
        foreach ($g['orders'] as $o) {
            foreach ($o['lines'] as $l) {
                if (in_array($l['fulfilment_kind'], ['stock', 'pickup'], true) && $l['to_pick'] > 0) { $lines++; $qty += $l['to_pick']; $orders[$o['sales_order_id']] = true; }
            }
        }
        if ($lines > 0) { $pick[] = ['location_id' => $g['location_id'], 'location_name' => $g['location_name'], 'delivery_method' => $g['delivery_method'], 'orders' => count($orders), 'lines' => $lines, 'qty' => $qty]; }
    }
    $counts = $pdo->query("SELECT count_id, number, location_name, started_at, line_count, lines_differing FROM mcp_inventory_counts WHERE status = 'open' ORDER BY count_id")->fetchAll();
    return ['to_receive' => ['receipts' => $receipts, 'purchase_orders' => $pos, 'count' => count($receipts) + count($pos)], 'to_pick' => $pick, 'to_count' => $counts];
}

/** For the admin: the feed's usage today (live keys and their calls) and the dispatches (sent = pending, running, awaiting approval, failed). */
function admin_block(PDO $pdo): array
{
    $keys = [];
    try {
        $keys = key_usage_summary($pdo);
    } catch (PDOException $e) {
        if ((string) $e->getCode() !== '42501') { throw $e; }
    }
    $d = ['sent' => 0, 'running' => 0, 'awaiting_approval' => 0, 'failed' => 0];
    try {
        $r = one_row($pdo, "SELECT count(*) FILTER (WHERE status = 'sent' AND run_id IS NULL) AS sent, count(*) FILTER (WHERE status = 'sent' AND run_id IS NOT NULL) AS running,
                                   count(*) FILTER (WHERE status = 'awaiting_approval') AS awaiting_approval, count(*) FILTER (WHERE status = 'failed') AS failed FROM mcp_agent_dispatches") ?? [];
        foreach ($d as $k => $_) { $d[$k] = (int) ($r[$k] ?? 0); }
    } catch (PDOException $e) {
        if ((string) $e->getCode() !== '42501') { throw $e; }
    }
    return ['feed' => ['live_keys' => count($keys), 'calls_today' => array_sum(array_column($keys, 'calls_today')), 'keys' => array_slice($keys, 0, 5)], 'dispatches' => $d];
}

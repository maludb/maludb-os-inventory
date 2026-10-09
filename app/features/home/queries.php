<?php
declare(strict_types=1);
/**
 * The home's regions (screen `home`), as design §9 names them. Phase 2 fixes the SHAPE; slice 9 (reports-admin.md) fills every
 * key from the slices' own query functions and replaces this file. A key is null until then; the view shows the empty state
 * naming the slice that fills it. `may` carries the rights the view gates a region by.
 *   note        slice 8/9 — the morning note's seven headings, each a count
 *   at_risk     slice 8   — lines at risk (inv_lines_at_risk)
 *   sources     slice 3   — pulls failed or blocked (inv_source_health)
 *   unmatched   slice 3   — unmatched listings (listings.match)
 *   po_ack      slice 6   — purchase orders awaiting acknowledgment
 *   today       slice 5   — today's deliveries and pickups (fulfilment_today)
 *   my_orders   slice 5   — for Sales: my open orders and their next step
 *   warehouse   slice 2/5 — for Warehouse: to receive, to pick, to count
 *   admin       slice 7/8 — for the admin: the feed's usage, the dispatches
 *   bell        Phase 2   — the five newest unread notifications (real)
 */
function home_summary(PDO $pdo, int $memberId): array
{
    $st = $pdo->prepare('SELECT notification_id, kind, record_type, record_id, title, body, created_at FROM mcp_notifications WHERE member_id = :m AND read_at IS NULL ORDER BY created_at DESC LIMIT 5');
    $st->execute(['m' => $memberId]);
    return [
        'note' => null, 'at_risk' => null, 'sources' => null, 'unmatched' => null, 'po_ack' => null, 'today' => null,
        'my_orders' => null, 'warehouse' => null, 'admin' => null,
        'bell' => $st->fetchAll(),
        'may' => [
            'sales' => has_right('orders.write'),
            'warehouse' => has_right('stock.receive') || has_right('stock.ship') || has_right('stock.count'),
            'match' => has_right('listings.match'),
            'admin' => has_right('settings.manage') || has_right('feed.keys') || has_right('agents.settings'),
            'cost' => sees_cost(),
        ],
    ];
}

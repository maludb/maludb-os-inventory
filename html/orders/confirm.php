<?php
declare(strict_types=1);
/**
 * GET /orders/{id}/confirm — screen `order-confirm`: line by line what confirming will do (allocate where, which purchase orders are drafted per supplier, what cannot be covered and must become a backorder).
 * POST — action `order_confirm` (log `order.confirm`: number, customer, allocated[], dropships_drafted[], backordered[], total, currency; confirm; `other` for agents): orders.write; inv_order_confirm() allocates,
 * refuses what it cannot cover and drafts one purchase order per supplier. Confirming sends NO email (that is order_send). Location /orders/{id}; refresh orderChanged.
 */
require_once dirname(__DIR__, 2) . '/app/features/orders/handler.php';
$pdo = db();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    orders_write_begin('orders.write');
    $o = order_or_404($pdo, request_integer('order') ?? request_integer('sales_order_id') ?? request_integer('id'));
    $r = inv_guard($pdo, static function () use ($pdo, $o): array {
        $pdo->beginTransaction();
        $r = confirm_order($pdo, (int) $o['sales_order_id'], (int) current_member_id());
        $now = find_order($pdo, (int) $o['sales_order_id']);
        order_log($pdo, 'order.confirm', $now, order_loggable($now) + ['allocated' => $r['allocated'], 'dropships_drafted' => $r['dropships_drafted'], 'backordered' => $r['backordered']]);
        $pdo->commit();
        return $r;
    });
    inv_done('Confirmed ' . $o['number'], (int) $o['sales_order_id'], inv_land('/orders/' . (int) $o['sales_order_id'], 'confirmed'), 'orderChanged',
        ['sales_order_id' => (int) $o['sales_order_id'], 'number' => $o['number'], 'allocated' => $r['allocated'], 'dropships_drafted' => $r['dropships_drafted'], 'backordered' => $r['backordered']]);
}
require_right('orders.write');
$o = order_or_404($pdo, request_integer('id') ?? request_integer('order'));
log_screen_view($pdo, 'order-confirm');
$preview = confirm_preview($pdo, (int) $o['sales_order_id']);
if (wants_json()) {
    respond_screen(['order' => present_order_row($o), 'will' => array_map(static fn (array $p): array => ['line_id' => $p['line']['line_id'], 'line_no' => $p['line']['line_no'], 'sku' => $p['line']['sku'], 'kind' => $p['kind'], 'ok' => $p['ok'],
        'text' => $p['text'], 'supplier' => $p['supplier'], 'lead_time_days' => $p['lead'], 'cost' => $p['cost'], 'available' => $p['available']], $preview)]);
}
render_screen('Confirm ' . $o['number'], view('orders/confirm.php', ['o' => $o, 'preview' => $preview, 'seesCost' => sees_cost(), 'locations' => sellable_locations($pdo), 'here' => here_url(),
    'notice' => inv_notice($_GET['notice'] ?? null, ORDER_NOTICES)]), ['activeNav' => 'order-list', 'screen' => 'order-confirm', 'entity' => 'sales_order', 'recordId' => (string) $o['sales_order_id']]);

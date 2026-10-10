<?php
declare(strict_types=1);
/**
 * Action `order_deliver` (log `order.deliver`: shipment_id, number, delivered_at): `shipment`, or `order` for every undelivered shipment of it; `delivered_at` (default now). inv_shipment_deliver() delivers the lines;
 * a drop-ship shipment receives its purchase order lines (and, with cost_source last_receipt, sets the variant's cost). stock.ship. Location /orders/{id}#order-shipments; refresh orderChanged.
 */
require_once dirname(__DIR__, 2) . '/app/features/orders/handler.php';
orders_write_begin('stock.ship');
$pdo = db();
$errors = [];
$at = order_datetime_field('delivered_at', 'The time delivered', $errors);
if ($errors !== []) { inv_refuse_fields($errors); }
$sid = request_integer('shipment') ?? request_integer('shipment_id');
if ($sid !== null) {
    $sh = find_shipment($pdo, $sid) ?? refuse(404, 'Shipment not found.');
    $oid = $sh['sales_order_id'];
    $ids = [$sid];
} else {
    $oid = request_integer('order') ?? request_integer('sales_order_id') ?? refuse(422, 'Name the shipment or the order.');
    $ids = array_map(static fn (array $s): int => (int) $s['shipment_id'], array_filter(order_shipments($pdo, $oid), static fn (array $s): bool => $s['delivered_at'] === null));
    if (find_order($pdo, $oid) === null) { refuse(404, 'Order not found.'); }
}
$o = order_or_404($pdo, $oid);
if ($ids === []) { refuse(422, $o['number'] . ' has no undelivered shipment.'); }
inv_guard($pdo, static function () use ($pdo, $o, $ids, $at): void {
    $pdo->beginTransaction();
    foreach ($ids as $id) {
        $r = deliver_shipment($pdo, $id, $at, (int) current_member_id());
        order_log($pdo, 'order.deliver', $o, ['shipment_id' => $id, 'number' => $o['number'], 'delivered_at' => $r['delivered_at'] ?? null]);
    }
    $pdo->commit();
});
$now = find_order($pdo, $oid);
inv_done('Delivered ' . count($ids) . ' shipment' . (count($ids) === 1 ? '' : 's') . ' of ' . $o['number'], $ids[0], inv_land(return_path('/orders/' . $oid), 'delivered', 'order-shipments'), 'orderChanged',
    ['sales_order_id' => $oid, 'shipment_ids' => $ids, 'status' => $now['status']]);

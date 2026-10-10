<?php
declare(strict_types=1);
/**
 * Action `order_link_rotate` (log `order.link_rotate`: number, link_id; confirm): the customer's current link stops at once. The new link's token is DISCARDED (the fresh row is retired as it is made), so no token rests in a table
 * or a log — the next order_send mints a link and mails it. orders.send. Location /orders/{id}#order-link.
 */
require_once dirname(__DIR__, 2) . '/app/features/orders/handler.php';
orders_write_begin('orders.send');
$pdo = db();
$o = order_or_404($pdo, request_integer('order') ?? request_integer('sales_order_id'));
$linkId = inv_guard($pdo, static function () use ($pdo, $o): int {
    $pdo->beginTransaction();
    mint_order_link($pdo, (int) $o['sales_order_id']);
    $id = (int) one_value($pdo, 'SELECT max(id) FROM order_links_secure WHERE sales_order_id = :o', ['o' => (int) $o['sales_order_id']]);
    $pdo->prepare('UPDATE order_links_secure SET rotated_at = now() WHERE id = :id')->execute(['id' => $id]);
    order_log($pdo, 'order.link_rotate', $o, ['number' => $o['number'], 'link_id' => $id]);
    $pdo->commit();
    return $id;
});
inv_done('Stopped the customer\'s link for ' . $o['number'], (int) $o['sales_order_id'], inv_land('/orders/' . (int) $o['sales_order_id'], 'link_rotated', 'order-link'), 'orderChanged', ['sales_order_id' => (int) $o['sales_order_id'], 'link_id' => $linkId]);

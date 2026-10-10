<?php
declare(strict_types=1);
/** GET /orders/{id}/lines — the lines editor region re-rendered (a fragment, not a screen): `#order-lines` and the totals panel out of band. The edit form refreshes itself from it on orderChanged. orders.write. */
require_once dirname(__DIR__, 2) . '/app/features/orders/handler.php';
require_right('inventory.read');
$pdo = db();
$o = order_or_404($pdo, request_integer('id') ?? request_integer('order'));
if (wants_json()) { respond_screen(['lines' => array_map('present_order_line', $o['lines']), 'subtotal' => $o['subtotal'], 'discount_total' => $o['discount_total'], 'tax_total' => $o['tax_total'], 'shipping_charge' => $o['shipping_charge'], 'total' => $o['total']]); }
echo orders_lines_fragment($pdo, (int) $o['sales_order_id']);

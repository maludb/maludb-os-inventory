<?php
declare(strict_types=1);
/** /shipments/{id} — screen `shipment-view`: one shipment, its lines, the tracking link and Deliver (stock.ship). */
require_once dirname(__DIR__, 2) . '/app/features/orders/handler.php';
require_right('inventory.read');
$pdo = db();
$s = find_shipment($pdo, request_integer('id') ?? request_integer('shipment') ?? 0) ?? refuse(404, 'Shipment not found.');
log_activity($pdo, 'screen.view', 'shipment', $s['shipment_id'], ['sales_order_id' => $s['sales_order_id'], 'screen' => 'shipment-view', 'after' => ['order' => $s['order_number']]]);
if (wants_json()) { respond_screen(['shipment' => present_shipment($s)]); }
render_screen('Shipment of ' . $s['order_number'], view('shipments/view.php', ['s' => $s, 'mayShip' => has_right('stock.ship'), 'tz' => member_timezone(), 'here' => here_url(), 'notice' => inv_notice($_GET['notice'] ?? null, ORDER_NOTICES)]),
    ['activeNav' => 'shipment-list', 'screen' => 'shipment-view', 'entity' => 'shipment', 'recordId' => (string) $s['shipment_id']]);

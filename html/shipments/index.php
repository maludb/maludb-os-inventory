<?php
declare(strict_types=1);
/** /shipments/?kind=&from=&to=&undelivered= — the shipments (screen `shipment-list`): order, customer, kind, carrier, tracking, shipped, delivered. The undelivered ones by default. */
require_once dirname(__DIR__, 2) . '/app/features/orders/handler.php';
require_right('inventory.read');
$pdo = db();
$filters = ['kind' => request_string('kind'), 'from' => request_string('from'), 'to' => request_string('to'), 'undelivered' => ($_GET['undelivered'] ?? '1') === '1'];
foreach (['from', 'to'] as $d) { if ($filters[$d] !== '' && request_date($d) === false) { refuse(422, 'That is not a date.'); } }
$page = max(1, request_integer('page') ?? 1);
$rows = find_shipments($pdo, $filters, 100, ($page - 1) * 100);
$total = count_shipments($pdo, $filters);
log_screen_view($pdo, 'shipment-list');
if (wants_json()) { respond_screen(['filters' => $filters, 'total' => $total, 'shipments' => array_map('present_shipment', $rows)]); }
render_screen('Shipments', view('shipments/index.php', ['rows' => $rows, 'total' => $total, 'filters' => $filters, 'tz' => member_timezone(), 'here' => here_url()]), ['activeNav' => 'shipment-list', 'screen' => 'shipment-list', 'entity' => 'shipment']);

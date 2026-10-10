<?php
declare(strict_types=1);
/** /orders/today?date=&location= — screen `fulfilment-today`: what to pick, deliver or collect today by store and method (inv_fulfilment_today()), the drop-ships expected beside it. For Sales it opens on their store; for the warehouse on every location. */
require_once dirname(__DIR__, 2) . '/app/features/orders/handler.php';
require_right('inventory.read');
$pdo = db();
$day = request_date('date');
if ($day === false) { refuse(422, 'That is not a date.'); }
$day ??= date('Y-m-d');
$loc = request_integer('location');
if ($loc === null && !isset($_GET['location']) && has_right('orders.write') && !has_right('stock.ship')) { $loc = my_store_today($pdo, (int) current_member_id(), $day); }
$data = fulfilment_today($pdo, $day, $loc);
log_screen_view($pdo, 'fulfilment-today');
if (wants_json()) { respond_screen($data + ['location' => $loc]); }
render_screen('Fulfilment today', view('orders/today.php', ['tabdata' => $data, 'day' => $day, 'loc' => $loc, 'locations' => order_locations($pdo), 'mayShip' => has_right('stock.ship'), 'here' => here_url()]),
    ['activeNav' => 'fulfilment-today', 'screen' => 'fulfilment-today', 'entity' => 'sales_order']);

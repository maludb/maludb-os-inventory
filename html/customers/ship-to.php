<?php
declare(strict_types=1);
/** GET /customers/ship-to?customer=&prefix= — the customer's shipping block for the order form (a fragment: the nine ship-to fields prefilled from the customer). orders.write. */
require_once dirname(__DIR__, 2) . '/app/features/customers/handler.php';
require_right('orders.write');
$pdo = db();
$cid = request_integer('customer');
$c = $cid === null ? null : find_customer($pdo, $cid);
if (wants_json()) { respond_screen(['ship_to' => $c === null ? (object) [] : customer_ship_to($pdo, $cid)]); }
echo view('customers/partials/ship-to.php', ['ship' => $c === null ? [] : customer_ship_to($pdo, $cid) + ['ship_to_country' => ''], 'errors' => []]);

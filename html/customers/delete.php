<?php
declare(strict_types=1);
/** Action `customer_delete` (log `customer.delete`, name — logged before the delete; confirm; a deletion for agents): records.delete; refused once any order names the customer. Location /customers/. */
require_once dirname(__DIR__, 2) . '/app/features/customers/handler.php';
inv_handler_begin();
require_right('records.delete');
$pdo = db();
$c = customer_or_404($pdo, request_integer('customer') ?? request_integer('customer_id'));
if (customer_any_orders($pdo, $c['customer_id']) > 0) {
    refuse(422, $c['name'] . ' has orders; archive them instead.');
}
inv_guard($pdo, static function () use ($pdo, $c): void {
    $pdo->beginTransaction();
    log_activity($pdo, 'customer.delete', 'customer', $c['customer_id'], ['before' => customer_loggable($c), 'after' => ['name' => $c['name']]]);
    delete_customer($pdo, $c['customer_id']);
    $pdo->commit();
});
inv_done('Deleted ' . $c['name'], $c['customer_id'], inv_land('/customers/', 'deleted'), 'customerChanged');

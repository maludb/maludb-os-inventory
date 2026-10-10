<?php
declare(strict_types=1);
/** Action `customer_archive` (log `customer.archive`, after.archived; confirm): `archived` yes (the default) / no. Archiving one with an order not closed or cancelled — quotes included — is refused. customers.write. */
require_once dirname(__DIR__, 2) . '/app/features/customers/handler.php';
inv_handler_begin();
require_right('customers.write');
$pdo = db();
$c = customer_or_404($pdo, request_integer('customer') ?? request_integer('customer_id'));
$archived = inv_yes('archived', true);
if ($archived === ($c['archived_at'] !== null)) {
    refuse(422, $c['name'] . ($archived ? ' is already archived.' : ' is not archived.'));
}
if ($archived && ($open = customer_open_orders($pdo, $c['customer_id'])) > 0) {
    refuse(422, 'Close or cancel their ' . $open . ' open order' . ($open === 1 ? '' : 's') . ' first.');
}
inv_guard($pdo, static function () use ($pdo, $c, $archived): void {
    $pdo->beginTransaction();
    archive_customer($pdo, $c['customer_id'], $archived);
    log_activity($pdo, 'customer.archive', 'customer', $c['customer_id'], ['before' => ['archived' => $c['archived_at'] !== null], 'after' => ['archived' => $archived, 'name' => $c['name']]]);
    $pdo->commit();
});
inv_done(($archived ? 'Archived ' : 'Restored ') . $c['name'], $c['customer_id'], inv_land('/customers/' . $c['customer_id'], $archived ? 'archived' : 'restored'), 'customerChanged', ['archived' => $archived]);

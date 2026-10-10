<?php
declare(strict_types=1);
/**
 * Actions `customer_create` (no `customer`) / `customer_update` (with; any field of customer_create — a field left out stays; log `customer.create` / `customer.update`):
 * customers.write. A duplicate live name reads "That name is already taken." The log says "email changed", never the address, the phone or the notes. Location /customers/{id}; refresh customerChanged.
 */
require_once dirname(__DIR__, 2) . '/app/features/customers/handler.php';
inv_handler_begin();
require_right('customers.write');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('customer') ?? request_integer('customer_id');
$cur = $id === null ? null : customer_or_404($pdo, $id);
$errors = [];
$f = customer_from_request($pdo, $cur, $errors);
if ($errors !== []) { inv_refuse_fields($errors); }
$newId = inv_guard($pdo, static function () use ($pdo, $me, $id, $cur, $f): int {
    $pdo->beginTransaction();
    $newId = save_customer($pdo, $id, $f, $me);
    $now = find_customer($pdo, $newId);
    if ($cur === null) {
        log_activity($pdo, 'customer.create', 'customer', $newId, ['after' => customer_loggable($now)]);
    } else {
        $d = inv_diff(customer_loggable($cur), customer_loggable($now));
        $private = customer_changed_private($cur, $now);
        if ($d['after'] !== [] || $private !== []) {
            log_activity($pdo, 'customer.update', 'customer', $newId, ['before' => $d['before'], 'after' => $d['after'] + ['name' => $now['name'], 'changed' => $private]]);
        }
    }
    $pdo->commit();
    return $newId;
});
inv_done(($id === null ? 'Made the customer ' : 'Saved ') . $f['name'], $newId, inv_land(return_path('/customers/' . $newId), $id === null ? 'created' : 'saved'), 'customerChanged', ['customer_id' => $newId]);

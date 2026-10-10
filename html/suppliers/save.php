<?php
declare(strict_types=1);
/**
 * Actions `supplier_create` (no `supplier`) / `supplier_update` (with; any field of supplier_create — a field left out stays; log `supplier.create` / `supplier.update`): suppliers.write. A duplicate
 * live name reads "That name is already taken." The log says which fields changed — never the account number, the contact's words or the notes. Location /suppliers/{id}; refresh supplierChanged.
 */
require_once dirname(__DIR__, 2) . '/app/features/suppliers/handler.php';
inv_handler_begin();
require_right('suppliers.write');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('supplier') ?? request_integer('supplier_id');
$cur = $id === null ? null : supplier_or_404($pdo, $id);
$base = $id === null ? null : supplier_base_row($pdo, $id);
$errors = [];
$f = supplier_from_request($pdo, $base, $errors);
if ($errors !== []) { inv_refuse_fields($errors); }
$newId = inv_guard($pdo, static function () use ($pdo, $me, $id, $base, $f): int {
    $pdo->beginTransaction();
    $newId = save_supplier($pdo, $id, $f, $me);
    $now = supplier_base_row($pdo, $newId);
    if ($base === null) {
        log_activity($pdo, 'supplier.create', 'supplier', $newId, ['after' => supplier_loggable($now)]);
    } else {
        $changed = supplier_changed($base, $now);
        if ($changed !== []) { log_activity($pdo, 'supplier.update', 'supplier', $newId, ['after' => supplier_loggable($now) + ['changed' => $changed]]); }
    }
    $pdo->commit();
    return $newId;
});
inv_done(($id === null ? 'Made the supplier ' : 'Saved ') . $f['name'], $newId, inv_land(return_path('/suppliers/' . $newId), $id === null ? 'created' : 'saved'), 'supplierChanged', ['supplier_id' => $newId]);

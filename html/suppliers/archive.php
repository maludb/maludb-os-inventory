<?php
declare(strict_types=1);
/** Action `supplier_archive` (log `supplier.archive`; confirm): `active` no (the default — archives) / yes (brings back). Archiving one with a purchase order in draft, sent, acknowledged or partial is refused. suppliers.write. */
require_once dirname(__DIR__, 2) . '/app/features/suppliers/handler.php';
inv_handler_begin();
require_right('suppliers.write');
$pdo = db();
$s = supplier_or_404($pdo, request_integer('supplier') ?? request_integer('supplier_id'));
$active = inv_yes('active', false);
if ($active === $s['active']) { refuse(422, $s['name'] . ($active ? ' is not archived.' : ' is already archived.')); }
if (!$active && ($open = supplier_open_order_count($pdo, $s['supplier_id'])) > 0) {
    refuse(422, 'Close or cancel their ' . $open . ' open purchase order' . ($open === 1 ? '' : 's') . ' first.');
}
inv_guard($pdo, static function () use ($pdo, $s, $active): void {
    $pdo->beginTransaction();
    archive_supplier($pdo, $s['supplier_id'], $active);
    log_activity($pdo, 'supplier.archive', 'supplier', $s['supplier_id'], ['before' => ['active' => $s['active']], 'after' => ['active' => $active, 'name' => $s['name']]]);
    $pdo->commit();
});
inv_done(($active ? 'Restored ' : 'Archived ') . $s['name'], $s['supplier_id'], inv_land('/suppliers/' . $s['supplier_id'], $active ? 'restored' : 'archived'), 'supplierChanged', ['active' => $active]);

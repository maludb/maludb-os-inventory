<?php
declare(strict_types=1);
/** /transfers/new?from_location=&to_location=&variant= and /transfers/{id}/edit (screens `transfer-add`, `transfer-edit`). stock.transfer. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
require_right('stock.transfer');
$pdo = db();
$id = request_integer('id') ?? request_integer('transfer');
$cur = $id === null ? null : transfer_or_404($pdo, $id);
if ($cur !== null && $cur['status'] !== 'draft') { refuse(422, $cur['number'] . ' is ' . str_replace('_', ' ', $cur['status']) . ' — only a draft transfer changes'); }
$screen = $cur === null ? 'transfer-add' : 'transfer-edit';
$variant = $cur === null && request_integer('variant') !== null ? find_variant($pdo, (int) request_integer('variant')) : null;
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['transfer' => $cur === null ? null : present_transfer($cur), 'locations' => locations_for_pick($pdo)]);
}
render_screen($cur === null ? 'New transfer' : 'Change ' . $cur['number'], view('transfers/form.php', ['cur' => $cur, 'locations' => locations_for_pick($pdo), 'variant' => $variant,
    'fromId' => $cur['from_location_id'] ?? request_integer('from_location'), 'toId' => $cur['to_location_id'] ?? request_integer('to_location')]),
    ['activeNav' => 'transfer-list', 'screen' => $screen, 'entity' => 'inventory_transfer', 'recordId' => $id === null ? '' : (string) $id]);

<?php
declare(strict_types=1);
/** /adjustments/new?location=&reason=&variant= and /adjustments/{id}/edit (screens `adjustment-add`, `adjustment-edit`). stock.adjust. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
require_right('stock.adjust');
$pdo = db();
$id = request_integer('id') ?? request_integer('adjustment');
$cur = $id === null ? null : adjustment_or_404($pdo, $id);
if ($cur !== null && $cur['status'] !== 'draft') { refuse(422, $cur['number'] . ' is ' . $cur['status'] . ' — only a draft adjustment changes'); }
$screen = $cur === null ? 'adjustment-add' : 'adjustment-edit';
$variant = $cur === null && request_integer('variant') !== null ? find_variant($pdo, (int) request_integer('variant')) : null;
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['adjustment' => $cur === null ? null : present_adjustment($cur), 'reasons' => adjustment_reasons($pdo), 'locations' => locations_for_pick($pdo)]);
}
render_screen($cur === null ? 'New adjustment' : 'Change ' . $cur['number'], view('adjustments/form.php', ['cur' => $cur, 'reasons' => adjustment_reasons($pdo), 'locations' => locations_for_pick($pdo),
    'locationId' => $cur['location_id'] ?? request_integer('location'), 'reason' => $cur['reason_code'] ?? request_string('reason'), 'variant' => $variant, 'seesCost' => sees_cost()]),
    ['activeNav' => 'adjustment-list', 'screen' => $screen, 'entity' => 'inventory_adjustment', 'recordId' => $id === null ? '' : (string) $id]);

<?php
declare(strict_types=1);
/** Action `location_archive` (log `location.archive`, after.active; confirm): `active` no archives — refused while anything is on hand, allocated or on the floor there; yes restores. settings.manage. */
require_once dirname(__DIR__, 2) . '/app/features/locations/handler.php';
inv_handler_begin();
require_right('settings.manage');
$pdo = db();
$l = location_or_404($pdo, request_integer('location') ?? request_integer('location_id'));
$active = inv_yes('active', false);
if ($active === $l['active']) {
    refuse(422, $active ? $l['name'] . ' is not archived.' : $l['name'] . ' is already archived.');
}
if (!$active && ($held = location_holds($pdo, $l['location_id'])) > 0) {
    refuse(422, $l['name'] . ' still holds ' . number_format($held) . ' units — move or adjust them first');
}
inv_guard($pdo, static function () use ($pdo, $l, $active): void {
    $pdo->beginTransaction();
    archive_location($pdo, $l['location_id'], $active);
    log_activity($pdo, 'location.archive', 'location', $l['location_id'], ['location_id' => $l['location_id'], 'before' => ['active' => $l['active']], 'after' => ['active' => $active, 'name' => $l['name']]]);
    $pdo->commit();
});
inv_done(($active ? 'Restored ' : 'Archived ') . $l['name'], $l['location_id'], inv_land('/locations/' . $l['location_id'], $active ? 'restored' : 'archived'), 'locationChanged', ['active' => $active]);

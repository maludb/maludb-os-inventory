<?php
declare(strict_types=1);
/** Actions `location_create` / `location_update` (logs `location.create` / `location.update`, inv_diff() on a change): settings.manage; `location` to change one. A duplicate name reads "That name is already taken." Location /locations/{id}; refresh locationChanged. */
require_once dirname(__DIR__, 2) . '/app/features/locations/handler.php';
inv_handler_begin();
require_right('settings.manage');
$pdo = db();
$id = request_integer('location') ?? request_integer('location_id');
$cur = $id === null ? null : location_or_404($pdo, $id);
$errors = [];
$f = location_from_request($pdo, $cur, $errors);
if ($errors !== []) { inv_refuse_fields($errors); }
if ($cur !== null && $cur['active'] && !$f['active'] && ($held = location_holds($pdo, $id)) > 0) {
    inv_refuse_fields(['active' => $cur['name'] . ' still holds ' . number_format($held) . ' units — move or adjust them first']);
}
$newId = inv_guard($pdo, static function () use ($pdo, $id, $cur, $f): int {
    $pdo->beginTransaction();
    $newId = save_location($pdo, $id, $f);
    $after = location_loggable(find_location($pdo, $newId));
    if ($cur === null) {
        log_activity($pdo, 'location.create', 'location', $newId, ['location_id' => $newId, 'after' => $after]);
    } else {
        $d = inv_diff(location_loggable($cur), $after);
        if ($d['after'] !== []) { log_activity($pdo, 'location.update', 'location', $newId, ['location_id' => $newId, 'before' => $d['before'], 'after' => $d['after'] + ['name' => $after['name']]]); }
    }
    $pdo->commit();
    return $newId;
});
inv_done(($id === null ? 'Made the location ' : 'Saved ') . $f['name'], $newId, inv_land(return_path('/locations/' . $newId), $id === null ? 'created' : 'saved'), 'locationChanged', ['location_id' => $newId]);

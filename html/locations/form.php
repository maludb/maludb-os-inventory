<?php
declare(strict_types=1);
/** /locations/new and /locations/{id}/edit (screens `location-add`, `location-edit`). settings.manage; people only. */
require_once dirname(__DIR__, 2) . '/app/features/locations/handler.php';
require_right('settings.manage');
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('location');
$cur = $id === null ? null : location_or_404($pdo, $id);
$screen = $cur === null ? 'location-add' : 'location-edit';
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['location' => $cur === null ? null : present_location($cur), 'kinds' => LOCATION_KINDS, 'departments' => find_live_departments($pdo)]);
}
render_screen($cur === null ? 'New location' : 'Change ' . $cur['name'], view('locations/form.php', ['cur' => $cur, 'departments' => find_live_departments($pdo)]),
    ['activeNav' => 'location-list', 'screen' => $screen, 'entity' => 'location', 'recordId' => $id === null ? '' : (string) $id]);

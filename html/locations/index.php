<?php
declare(strict_types=1);
/** /locations/?q=&kind=&archived= — the locations as cards (screen `location-list`): kind, department, sellable, allows negative, units on hand. */
require_once dirname(__DIR__, 2) . '/app/features/locations/handler.php';
require_right('inventory.read');
$pdo = db();
$q = request_string('q');
$kind = request_string('kind');
$archived = ($_GET['archived'] ?? '') === '1';
$locations = find_locations($pdo, $q, $kind === '' ? null : $kind, false, $archived, 500);
log_screen_view($pdo, 'location-list');
if (wants_json()) {
    respond_screen(['q' => $q, 'kind' => $kind, 'locations' => array_map('present_location', $locations)]);
}
render_screen('Locations', view('locations/index.php', ['locations' => $locations, 'q' => $q, 'kind' => $kind, 'archived' => $archived, 'mayWrite' => has_right('settings.manage'), 'here' => here_url(),
    'notice' => inv_notice($_GET['notice'] ?? null, ['created' => ['success', 'The location is made.'], 'saved' => ['success', 'Saved.']])]),
    ['activeNav' => 'location-list', 'screen' => 'location-list', 'entity' => 'location']);

<?php
declare(strict_types=1);
/** /locations/{id}?tab= — one location (screen `location-view`): levels, recent movements, open transfers, counts, the trail. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/activity/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/activity/present.php';
require_right('inventory.read');
$pdo = db();
$l = location_or_404($pdo, request_integer('id') ?? request_integer('location'));
$lid = $l['location_id'];
$tab = request_string('tab', 'levels');
$tabs = ['levels' => 'Levels', 'movements' => 'Movements', 'transfers' => 'Transfers', 'counts' => 'Counts', 'trail' => 'Trail'];
if (!isset($tabs[$tab])) { $tab = 'levels'; }
$data = ['levels' => [], 'totals' => null, 'movements' => [], 'transfers' => [], 'counts' => [], 'trail' => []];
if ($tab === 'levels' || wants_json()) { $data['levels'] = stock_levels($pdo, ['location' => $lid], 500); $data['totals'] = stock_totals($pdo, ['location' => $lid]); }
if ($tab === 'movements' || wants_json()) { $data['movements'] = stock_movements($pdo, ['location' => $lid, 'days' => null], 50)['rows']; }
if ($tab === 'transfers' || wants_json()) { $data['transfers'] = transfers_open($pdo, ['location' => $lid, 'status' => ['draft', 'in_transit']]); }
if ($tab === 'counts' || wants_json()) {
    $data['counts'] = array_merge(counts($pdo, ['location' => $lid, 'status' => 'open']), counts($pdo, ['location' => $lid, 'status' => 'posted'], 5));
}
if ($tab === 'trail') { $data['trail'] = find_activity_by_key($pdo, 'location_id', $lid, 50); }
log_activity($pdo, 'screen.view', 'location', $lid, ['screen' => 'location-view', 'after' => ['tab' => $tab, 'name' => $l['name']]]);
if (wants_json()) {
    respond_screen(['location' => present_location($l), 'levels' => array_map('present_level', $data['levels']), 'totals' => $data['totals'], 'movements' => array_map('present_movement', $data['movements']),
        'transfers' => array_map('present_transfer', $data['transfers']), 'counts' => array_map('present_count', $data['counts']), 'holds' => location_holds($pdo, $lid)]);
}
render_screen($l['name'], view('locations/view.php', ['l' => $l, 'tab' => $tab, 'tabs' => $tabs, 'data' => $data, 'mayWrite' => has_right('settings.manage'), 'mayAdjust' => has_right('stock.adjust'),
    'seesCost' => sees_cost(), 'tz' => member_timezone(), 'here' => here_url(), 'notice' => inv_notice($_GET['notice'] ?? null, STOCK_NOTICES)]),
    ['activeNav' => 'location-list', 'screen' => 'location-view', 'entity' => 'location', 'recordId' => (string) $lid]);

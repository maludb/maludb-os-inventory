<?php
declare(strict_types=1);
/** /sources/{id} — one source (screen `source-view`): health, Probe, Pull now, Pause / Resume, the credential card, the last pulls, the listings' counts, the settings, the trail. */
require_once dirname(__DIR__, 2) . '/app/features/sources/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/activity/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/activity/present.php';
require_right('inventory.read');
$pdo = db();
$s = source_or_404($pdo, request_integer('id') ?? request_integer('source'));
$sid = $s['source_id'];
$full = source_full($pdo, $sid);
$raw = has_right('sources.write') ? source_settings_raw($pdo, $sid) : null;
$ua = has_right('sources.write') ? inv_source_user_agent($pdo, ['user_agent' => one_value($pdo, 'SELECT user_agent FROM sources WHERE id = :id', ['id' => $sid])]) : null;
log_activity($pdo, 'screen.view', 'source', $sid, ['screen' => 'source-view', 'source_id' => $sid, 'after' => ['source_id' => $sid]]);
if (wants_json()) {
    respond_screen(['source' => present_source($s), 'credential' => $full['credential'], 'health' => $full['health'], 'pulls' => array_map('present_pull', $full['pulls']), 'counts' => $full['counts'],
        'user_agent' => $ua, 'delete_counts' => has_right('records.delete') ? source_delete_counts($pdo, $sid) : null]);
}
render_screen($s['name'], view('sources/view.php', ['s' => $s, 'full' => $full, 'settings' => $raw, 'ua' => $ua, 'may' => ['write' => has_right('sources.write'), 'credentials' => has_right('sources.credentials'),
    'delete' => has_right('records.delete'), 'match' => has_right('listings.match')], 'deleteCounts' => has_right('records.delete') ? source_delete_counts($pdo, $sid) : null,
    'trail' => find_activity_by_key($pdo, 'source_id', $sid, 20), 'tz' => member_timezone(), 'here' => here_url(), 'notice' => inv_notice($_GET['notice'] ?? null, SOURCE_NOTICES)]),
    ['activeNav' => 'source-list', 'screen' => 'source-view', 'entity' => 'source', 'recordId' => (string) $sid]);

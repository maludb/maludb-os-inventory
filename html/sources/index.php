<?php
declare(strict_types=1);
/** /sources/?q=&connector=&role=&health= — the sources as cards (screen `source-list`) with their health; every reader sees the cards (settings never on a card). */
require_once dirname(__DIR__, 2) . '/app/features/sources/handler.php';
require_right('inventory.read');
$pdo = db();
$filters = ['q' => request_string('q'), 'connector' => request_string('connector'), 'role' => request_string('role'), 'health' => request_string('health'), 'include_inactive' => ($_GET['inactive'] ?? '') === '1'];
$sources = find_sources($pdo, $filters, 500);
log_screen_view($pdo, 'source-list');
if (wants_json()) {
    respond_screen(['filters' => $filters, 'sources' => array_map('present_source', $sources), 'health_counts' => health_counts($pdo)]);
}
render_screen('Sources', view('sources/index.php', ['sources' => $sources, 'filters' => $filters, 'counts' => health_counts($pdo), 'mayWrite' => has_right('sources.write'), 'here' => here_url(),
    'notice' => inv_notice($_GET['notice'] ?? null, SOURCE_NOTICES)]), ['activeNav' => 'source-list', 'screen' => 'source-list', 'entity' => 'source']);

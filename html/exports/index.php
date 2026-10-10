<?php
declare(strict_types=1);
/**
 * /exports/?from=&to= — the downloads (screen `export-list`, `export-card-{export}`): the accounting system's three files (sales closed, purchases received, stock valuation — they carry cost), the catalog and the listings,
 * each as CSV or JSON. A reader below the cost wall sees the three accounting cards disabled. reports.read.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/reports/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/exports/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/exports/present.php';
require_any_right('reports.read|exports.all');
$pdo = db();
$tz = business_tz($pdo);
$today = new DateTimeImmutable('now', $tz);
$from = request_date('from');
$to = request_date('to');
if ($from === false || $to === false) { refuse(422, 'That is not a date.'); }
$to ??= $today->format('Y-m-d');
$from ??= (new DateTimeImmutable($to))->modify('first day of this month')->format('Y-m-d');
$cards = [];
foreach (export_specs() as $slug => $s) { $cards[] = present_export_card($slug, $s); }
$sources = $pdo->query('SELECT source_id, name FROM mcp_sources WHERE active ORDER BY lower(name)')->fetchAll();
log_screen_view($pdo, 'export-list');
if (wants_json()) {
    respond_screen(['exports' => $cards, 'from' => $from, 'to' => $to]);
}
render_screen('Exports', view('exports/index.php', ['cards' => $cards, 'from' => $from, 'to' => $to, 'asOf' => $today->format('Y-m-d'), 'sources' => $sources, 'seesCost' => sees_cost()]),
    ['activeNav' => 'export-list', 'screen' => 'export-list', 'entity' => 'export']);

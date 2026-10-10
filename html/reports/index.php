<?php
declare(strict_types=1);
/** /reports/ — the reports as cards (screen `report-list`, `report-card-{report}`): what each answers and who may read it. reports.read (the catalog gaps card: catalog.write). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/reports/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/reports/present.php';
require_right('reports.read');
$pdo = db();
$cards = [];
foreach (report_specs() as $slug => $s) {
    $cards[] = ['report' => $slug, 'title' => $s['title'], 'blurb' => $s['blurb'], 'who' => $s['who'], 'href' => '/reports/' . $slug];
}
if (has_right('catalog.write')) {
    $cards[] = ['report' => 'catalog-gaps', 'title' => 'Catalog gaps', 'blurb' => 'Variants with no GTIN, no cost, no retail price or no weight — what the catalog still needs.', 'who' => 'catalog.write', 'href' => '/catalog/gaps'];
}
log_screen_view($pdo, 'report-list');
if (wants_json()) {
    respond_screen(['reports' => array_map(static fn (array $c): array => ['report' => $c['report'], 'title' => $c['title'], 'about' => $c['blurb'], 'who' => $c['who'], 'url' => $c['href']], $cards)]);
}
render_screen('Reports', view('reports/index.php', ['cards' => $cards]), ['activeNav' => 'report-list', 'screen' => 'report-list', 'entity' => 'report']);

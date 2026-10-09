<?php
declare(strict_types=1);
/** /catalog/gaps?gap=&brand= — what the catalog is missing (screen `catalog-gaps`): inv_catalog_gaps() (catalog.write — the function's rule; no_cost rows only for sees_cost()). */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
require_right('catalog.write');
$pdo = db();
$gap = request_string('gap') ?: null;
if ($gap !== null && !isset(GAP_KINDS[$gap])) { $gap = null; }
$rows = catalog_gaps($pdo, $gap, request_integer('brand'));
$counts = catalog_gap_counts($pdo);
log_screen_view($pdo, 'catalog-gaps');
if (wants_json()) {
    respond_screen(['gap' => $gap, 'counts' => $counts, 'rows' => $rows]);
}
render_screen('Catalog gaps', view('catalog/gaps.php', ['rows' => $rows, 'counts' => $counts, 'gap' => $gap, 'here' => here_url()]), ['activeNav' => 'catalog-gaps', 'screen' => 'catalog-gaps']);

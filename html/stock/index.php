<?php
declare(strict_types=1);
/** /stock/?location=&brand=&type=&q=&variant=&below_reorder=&page=&format=csv — the levels (screen `stock-levels`): variant × location, nonzero rows; `format=csv` the same rows as text/csv. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
require_right('inventory.read');
$pdo = db();
$filters = ['location' => request_integer('location'), 'brand' => request_integer('brand'), 'product_type' => request_string('type'), 'q' => request_string('q'),
            'variant' => request_integer('variant'), 'below_reorder' => ($_GET['below_reorder'] ?? '') === '1'];
$page = max(1, request_integer('page') ?? 1);
$csv = ($_GET['format'] ?? '') === 'csv';
$rows = stock_levels($pdo, $filters, $csv ? 100000 : LEVELS_PAGE, $csv ? 0 : ($page - 1) * LEVELS_PAGE);
$totals = stock_totals($pdo, $filters);
log_activity($pdo, 'screen.view', null, null, ['screen' => 'stock-levels', 'after' => array_filter(['format' => $csv ? 'csv' : null, 'location_id' => $filters['location'], 'rows' => $totals['rows']], static fn ($v) => $v !== null)]);
if ($csv) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="stock-levels-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['sku', 'product', 'size', 'location', 'on_hand', 'allocated', 'floor_model', 'available', 'below_reorder'], ',', '"', '');
    foreach ($rows as $r) {
        fputcsv($out, [$r['sku'], $r['product_name'], $r['size_name'], $r['location_name'], $r['qty_on_hand'], $r['qty_allocated'], $r['qty_floor_model'], $r['qty_available'], $r['below_reorder'] ? 'yes' : 'no'], ',', '"', '');
    }
    fclose($out);
    exit;
}
if (wants_json()) {
    respond_screen(['filters' => $filters, 'page' => $page, 'rows' => array_map('present_level', $rows), 'totals' => $totals]);
}
render_screen('Stock levels', view('stock/levels.php', ['rows' => $rows, 'totals' => $totals, 'filters' => $filters, 'page' => $page, 'pages' => max(1, (int) ceil($totals['rows'] / LEVELS_PAGE)),
    'locations' => locations_for_pick($pdo, true), 'brands' => find_brands($pdo, null, false, 500), 'types' => product_types($pdo), 'variant' => $filters['variant'] ? find_variant($pdo, $filters['variant']) : null, 'here' => here_url()]),
    ['activeNav' => 'stock-levels', 'screen' => 'stock-levels', 'entity' => 'stock']);

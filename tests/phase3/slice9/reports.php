<?php
/** The reports (reports-admin.md "Proof": ≥ 36): each report as the database's function answers it, the cost wall, the filters' refusals, the CSV, the log. */
require __DIR__ . '/lib.php';
$w = admin_world();
[$nora, $sam, $vera, $owner, $omar] = [who('nora'), who('sam'), who('vera'), who('owner'), who('omar')];
$run = static fn (string $jar, array $f, array $h = JSONH): array => (function () use ($jar, $f, $h): array { $r = act_raw($jar, '/reports/run.php', $f, $h); return [(int) $r['code'], json_decode($r['body'], true) ?? [], $r]; })();
$direct = static function (int $m, string $sql, array $a = []): array { as_db($m); return q($sql, $a); };

echo "1. The data to find\n";
psql_exec("UPDATE product_variants SET map_price = retail_price + 50 WHERE id = {$w['twin']}");                                  // retail under MAP
psql_exec("UPDATE product_variants SET cost_price = round(cost_price * 1.3, 2) WHERE id = {$w['queen']}");                        // a cost that moved 30 %
$zl = po_lines_of(po_for($w['so1'], $w['zinus']))[0];
psql_exec("UPDATE purchase_order_lines SET expected_on = current_date - 2, shipped_at = now() - interval '1 day' WHERE id = {$zl['id']}");   // promised vs actual
$kinds = array_unique(array_column($direct(40, 'SELECT kind FROM inv_price_exceptions()'), 'kind'));
sort($kinds);
ok($kinds === ['cost_moved', 'reference_undercut', 'retail_under_map'], 'the three kinds of price exception exist: ' . implode(', ', $kinds));

echo "2. Stock value\n";
[$c, $b] = $run($nora, ['report' => 'stock-value', 'by' => 'location']);
$sv = $direct(40, 'SELECT * FROM inv_stock_value(now(), \'location\')');
$wh = array_values(array_filter($b['rows'] ?? [], static fn (array $r): bool => $r['group_name'] === 'SMOKE Warehouse'))[0] ?? [];
$whDirect = array_values(array_filter($sv, static fn (array $r): bool => $r['group_name'] === 'SMOKE Warehouse'))[0];
ok($c === 200 && ($wh['units'] ?? null) === (int) $whDirect['units'] && abs(($wh['value'] ?? -1) - (float) $whDirect['value']) < 0.005 && $b['cost_withheld'] === false, "by location: the warehouse's units and value as the function says (" . $whDirect['units'] . ' units, ' . $whDirect['value'] . ')');
ok(abs($b['totals']['value'] - array_sum(array_column($sv, 'value'))) < 0.005 && $b['totals']['units'] === array_sum(array_column($sv, 'units')), 'and the totals row is the sum');
foreach (['brand', 'type', 'variant'] as $by) { [$c, $b2] = $run($nora, ['report' => 'stock-value', 'by' => $by]); if ($c !== 200 || $b2['rows'] === []) { ok(false, "stock value by $by"); } }
ok(true, 'by brand, by type and by variant all answer rows');
[$c, $b] = $run($omar, ['report' => 'stock-value', 'by' => 'location']);
ok($c === 200 && $b['cost_withheld'] === true && array_unique(array_column($b['rows'], 'value')) === [null] && $b['totals']['value'] === null && $b['rows'][0]['units'] > 0, 'the analyst (reports.read, no cost): value null, units shown, cost_withheld true');
[$c, $html] = pg($omar, '/reports/stock-value');
ok($c === 200 && has_id($html, 'report-cost-withheld') && str_contains(text_of($html, 'report-cost-withheld'), 'Cost withheld'), 'and the page says "Cost withheld" (report-cost-withheld)');
[$c, $b] = $run($nora, ['report' => 'stock-value', 'as_of' => '2020-01-01']);
ok($c === 200 && $b['rows'] === [] && $b['totals']['units'] === 0, 'as of a date before any stock existed: nothing');

echo "3. Sell-through\n";
[$c, $b] = $run($nora, ['report' => 'sell-through', 'days' => '30', 'by' => 'variant']);
$q = array_values(array_filter($b['rows'] ?? [], static fn (array $r): bool => str_contains($r['group_name'], 'SMOKE-NW-CR-Q')))[0] ?? [];
ok($c === 200 && ($q['units_sold'] ?? 0) >= 1 && $q['revenue'] > 0 && $q['cogs'] > 0 && $q['margin_pct'] !== null && $q['weeks_of_cover'] !== null, 'by variant, 30 days: the shipped Queen has units sold, revenue, COGS, margin % and weeks of cover: ' . json_encode($q));
[$c, $b] = $run($nora, ['report' => 'sell-through', 'days' => '30', 'by' => 'salesperson']);
ok($c === 200 && in_array('SMOKE Sam', array_column($b['rows'], 'group_name'), true) && $b['rows'][0]['weeks_of_cover'] === null, 'by salesperson: Sam, and no weeks of cover (variant only)');
[$c, $b] = $run($nora, ['report' => 'sell-through', 'days' => '0']);
ok($c === 422 && isset($b['error']['fields']['days']), 'days 0 → 422 naming days');
[$c, $b] = $run($omar, ['report' => 'sell-through', 'days' => '30']);
ok($c === 200 && $b['cost_withheld'] === true && array_unique(array_column($b['rows'], 'cogs')) === [null] && $b['rows'][0]['revenue'] > 0, 'below the wall: revenue shown, COGS and margin null');

echo "4. Sales and margin\n";
$from = gmdate('Y-m-01'); $to = gmdate('Y-m-d');
[$c, $b] = $run($nora, ['report' => 'sales', 'from' => $from, 'to' => $to, 'by' => 'brand']);
$sd = $direct(40, "SELECT * FROM inv_sales_summary(CAST(:f AS date), CAST(:t AS date), 'brand')", ['f' => $from, 't' => $to]);
ok($c === 200 && abs($b['totals']['revenue'] - array_sum(array_column($sd, 'revenue'))) < 0.005 && abs($b['totals']['tax'] - array_sum(array_column($sd, 'tax'))) < 0.005 && $b['totals']['revenue'] > 0, 'this month by brand: the confirmed orders\' revenue (' . $b['totals']['revenue'] . ') and tax (' . $b['totals']['tax'] . ') as the function sums them');
foreach (['day', 'week', 'month', 'type', 'variant', 'location', 'salesperson'] as $by) {
    [$c, $b2] = $run($nora, ['report' => 'sales', 'from' => $from, 'to' => $to, 'by' => $by]);
    if ($c !== 200 || $b2['rows'] === []) { ok(false, "sales by $by"); }
}
ok(true, 'by day, week (db/016), month, type, variant, location and salesperson all answer rows');
[$c, $b] = $run($nora, ['report' => 'sales', 'from' => $from, 'to' => $to, 'by' => 'salesperson']);
ok(in_array('SMOKE Sam', array_column($b['rows'], 'group_name'), true) && $b['totals']['orders'] === array_sum(array_column($b['rows'], 'orders')), 'by salesperson names Sam; orders add up where they cannot overlap');
[$c, $b] = $run($nora, ['report' => 'margin', 'from' => $from, 'to' => $to]);
ok($c === 200 && array_keys($b['rows'][0])[1] === 'cogs' && array_keys($b['rows'][0])[2] === 'margin_pct' && $b['params']['by'] === 'brand', 'margin: brand by default, COGS and margin right after the group');
[$c, $html] = pg($nora, "/reports/margin?from=$from&to=$to");
ok(preg_match('#<thead.*?<th[^>]*>Group</th>\s*<th[^>]*>COGS</th>\s*<th[^>]*>Margin %</th>#s', $html) === 1, 'and the table shows them first');
[$c, $b] = $run($omar, ['report' => 'margin', 'from' => $from, 'to' => $to]);
ok($c === 200 && $b['cost_withheld'] === true && $b['rows'][0]['cogs'] === null && $b['rows'][0]['margin_pct'] === null && $b['rows'][0]['revenue'] > 0, 'margin below the wall: "cost withheld" — revenue only');
[$c, $b] = $run($nora, ['report' => 'sales', 'from' => $from, 'to' => $to, 'by' => 'galaxy']);
ok($c === 422 && isset($b['error']['fields']['by']) && str_contains($b['error']['fields']['by'], 'Group by'), 'a bad by → 422 naming by: ' . ($b['error']['fields']['by'] ?? ''));
[$c, $b] = $run($nora, ['report' => 'sales', 'from' => '2024-01-01', 'to' => '2026-10-01', 'by' => 'brand']);
ok($c === 422 && isset($b['error']['fields']['to']), 'a period over 366 days → 422 naming to');
[$c, $b] = $run($nora, ['report' => 'sales', 'from' => '2026-13-45', 'to' => $to]);
ok($c === 422 && isset($b['error']['fields']['from']), 'a date that is not a date → 422 naming from');

echo "5. Price exceptions\n";
[$c, $b] = $run($nora, ['report' => 'price-exceptions']);
$got = array_unique(array_column($b['rows'], 'kind'));
sort($got);
ok($c === 200 && $got === ['cost_moved', 'reference_undercut', 'retail_under_map'], 'all three kinds');
[$c, $b] = $run($nora, ['report' => 'price-exceptions', 'kind' => 'retail_under_map']);
ok($c === 200 && array_unique(array_column($b['rows'], 'kind')) === ['retail_under_map'] && $b['rows'][0]['map_price'] > $b['rows'][0]['retail_price'], 'kind[] filters (retail under MAP only)');
[$c, $b] = $run($nora, ['report' => 'price-exceptions', 'kind' => 'bogus']);
ok($c === 422 && isset($b['error']['fields']['kind']), 'an unknown kind → 422 naming kind');
[$c, $b] = $run($nora, ['report' => 'price-exceptions', 'brand' => (string) brand_id('SMOKE Zinus')]);
ok($c === 200 && $b['rows'] === [], 'filtered to a brand with no exceptions: nothing');
[$c, $b] = $run($omar, ['report' => 'price-exceptions']);
ok($c === 200 && $b['cost_withheld'] === true && array_unique(array_column($b['rows'], 'cost_price')) === [null], 'below the wall: cost null');

echo "6. Source health\n";
[$c, $b] = $run($nora, ['report' => 'source-health', 'days' => '30']);
$by = array_column($b['rows'], null, 'name');
ok($c === 200 && ($by['SMOKE Malouf store']['health'] ?? '') === 'ok', 'Malouf is ok');
$blocked = array_values(array_filter($b['rows'], static fn (array $r): bool => $r['health'] === 'blocked'));
ok(count($blocked) >= 1 && $blocked[0]['pulls'] >= 1 && $blocked[0]["success_pct"] == 0 && $blocked[0]['last_error'] !== null && mb_strlen($blocked[0]['last_error']) <= 200, 'the blocked reference: its pulls, 0 % success, its last error');
$mal = $by['SMOKE Malouf store'];
$dr = $direct(40, "SELECT count(*) FILTER (WHERE action = 'source.pull_done') AS done, count(*) AS n FROM activity_log WHERE source_id = :s AND action IN ('source.pull_done', 'source.pull_fail', 'source.pull_blocked') AND occurred_at > now() - interval '30 days'", ['s' => $w['src_malouf']])[0];
ok($mal['pulls'] === (int) $dr['n'] && abs($mal['success_pct'] - round(100 * $dr['done'] / max(1, $dr['n']), 1)) < 0.05, 'Malouf\'s pulls and success % are the log rows\': ' . $mal['pulls'] . ' pulls, ' . $mal['success_pct'] . ' %');
[$c, $b] = $run($nora, ['report' => 'source-health', 'health' => ['blocked', 'paused']]);
ok($c === 200 && array_diff(array_unique(array_column($b['rows'], 'health')), ['blocked', 'paused']) === [] && count($b['rows']) >= 2, 'health[] filters (blocked · paused)');
[$c, $b] = $run($nora, ['report' => 'source-health', 'health' => ['sparkling']]);
ok($c === 422 && isset($b['error']['fields']['health']), 'an unknown health → 422 naming health');

echo "7. Lead times\n";
[$c, $b] = $run($nora, ['report' => 'lead-times']);
$z = array_values(array_filter($b['rows'], static fn (array $r): bool => $r['supplier_name'] === 'SMOKE Zinus'))[0] ?? [];
ok($c === 200 && ($z['lines'] ?? 0) >= 1 && $z['actual_avg_days'] !== null && $z['promised_avg_days'] !== null && $z['on_time_pct'] === 0.0 || ($z['on_time_pct'] ?? -1) >= 0, 'Zinus: promised vs actual from the PO line: ' . json_encode($z));
[$c, $b] = $run($nora, ['report' => 'lead-times', 'supplier' => (string) $w['zinus']]);
ok($c === 200 && array_unique(array_column($b['rows'], 'supplier_name')) === ['SMOKE Zinus'], 'filtered to one supplier');
[$c, $b] = $run($nora, ['report' => 'lead-times', 'supplier' => '99999']);
ok($c === 422 && isset($b['error']['fields']['supplier']), 'a supplier that is not here → 422 naming supplier');

echo "8. The refusals and the screens\n";
[$c, $b] = $run($nora, ['report' => 'nonesuch']);
ok($c === 404 && ($b['error']['message'] ?? '') === 'No such report.', 'a report outside the seven → 404 "No such report."');
ok(pg($nora, '/reports/nonesuch')[0] === 404, '… and the screen too');
[$c] = $run($vera, ['report' => 'stock-value']);
ok($c === 403, 'a Viewer → 403 (reports.read)');
ok(pg($sam, '/reports/')[0] === 403 && pg($sam, '/reports/sales')[0] === 403, 'Sam (Sales) → 403 on the hub and on a report');
[$c, $html] = pg($nora, '/reports/');
ok($c === 200 && has_id($html, 'report-card-stock-value') && has_id($html, 'report-card-margin') && has_id($html, 'report-card-lead-times') && has_id($html, 'report-card-catalog-gaps'), 'the hub: a card per report (and the catalog gaps for catalog.write)');
[$c, $html] = pg($owner, "/reports/sales?by=week&from=$from&to=$to");
ok($c === 200 && preg_match('#<option value="week" selected#', $html) === 1 && str_contains($html, 'value="' . $from . '"') && has_id($html, 'report-results-table') && has_id($html, 'report-form-run-btn') && has_id($html, 'report-form-csv-btn'), 'the GET renders the form filled from the query string, with its results and the Run and CSV buttons');
$r = act_raw($owner, '/reports/run.php', ['report' => 'sales', 'from' => $from, 'to' => $to, 'by' => 'brand', 'format' => 'html'], ['HX-Request: true', 'HX-Target: report-results']);
ok($r['code'] === 200 && str_starts_with(trim($r['body']), '<div class="card" id="report-results"') && !str_contains($r['body'], '<html'), 'Run through HTMX answers the results partial alone (#report-results)');

echo "9. The CSV and the log\n";
$r = act_raw($nora, '/reports/run.php', ['report' => 'sales', 'from' => $from, 'to' => $to, 'by' => 'brand', 'format' => 'csv']);
$lines = preg_split('/\r?\n/', trim(substr($r['body'], 3)));
ok($r['code'] === 200 && str_starts_with($r['body'], "\xEF\xBB\xBF") && str_contains((string) hdr($r, 'Content-Type'), 'text/csv') && str_contains((string) hdr($r, 'Content-Disposition'), 'attachment; filename="sales-' . gmdate('Y-m-d') . '.csv"'), 'format=csv is a download: BOM, text/csv, Content-Disposition names the file');
ok($lines[0] === 'Group,Orders,Units,Revenue,Discount,Tax,COGS,"Margin %"' && preg_match('/^"?SMOKE Cloudrest"?,\d+,\d+,\d+\.\d{2},\d+\.\d{2},\d+\.\d{2},\d+\.\d{2},[\d.]+$/', $lines[1]) === 1, 'the columns as headed and the amounts with two decimals: ' . $lines[1]);
ok(str_starts_with(end($lines), 'Total,'), 'and the totals row closes it');
$r = act_raw($omar, '/reports/run.php', ['report' => 'margin', 'from' => $from, 'to' => $to, 'format' => 'csv']);
ok(!preg_match('/\d{3,}\.\d{2},\d+\.\d{2},\d/', explode("\n", $r['body'])[1]) && explode(',', explode("\n", $r['body'])[1])[1] === '', 'the CSV below the wall has the cost columns empty');
$since = last_activity_id();
act_raw($nora, '/reports/run.php', ['report' => 'sell-through', 'days' => '7', 'by' => 'brand', 'format' => 'csv']);
$lg = last_log('report.run', $since); $a = after_of($lg);
ok($lg !== null && $a['report'] === 'sell-through' && $a['params']['days'] === 7 && $a['params']['by'] === 'brand' && $a['params']['format'] === 'csv' && !str_contains($lg['after'], 'SMOKE'), 'report.run logged with the report and its params (by, days, format) and never rows');
ok(count(q("SELECT id FROM activity_log WHERE action = 'report.run' AND id > :s", ['s' => $since])) === 1, 'one run logs report.run exactly once');
[$c, $b] = $run($nora, ['report' => 'stock-value', 'format' => 'xml']);
ok($c === 422 && isset($b['error']['fields']['format']), 'a format that is neither html nor csv → 422 naming format');
finish();

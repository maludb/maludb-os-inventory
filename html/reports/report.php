<?php
declare(strict_types=1);
/**
 * /reports/{report}?from=&to=&location=&brand=&type=&supplier=&source=&by=&days=&as_of=&kind= — one report (screen `report`, `report-{report}`): the filter form, Run and CSV, the results. The GET renders the form and the
 * results for the query-string values, so a link lands on a filled report. `report` ∈ stock-value · sell-through · sales · margin · price-exceptions · source-health · lead-times (else 404 "No such report."). reports.read.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/reports/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/reports/present.php';
require_right('reports.read');
$pdo = db();
$report = request_string('report');
$spec = report_spec($report);
if ($spec === null) { refuse(404, 'No such report.'); }
$errors = [];
$params = report_params($pdo, $report, $_GET, $errors);
if ($errors !== [] && wants_json()) { respond_invalid(array_values($errors), $errors); }
$result = $errors === [] ? inv_guard($pdo, static fn (): array => report_rows($pdo, $report, $params)) : ['rows' => [], 'totals' => null, 'cost_withheld' => false, 'truncated' => false, 'count' => 0];
log_activity($pdo, 'screen.view', null, null, ['screen' => 'report', 'after' => ['screen' => 'report', 'report' => $report, 'params' => report_params_loggable($params)]]);
if (wants_json()) {
    respond_screen(present_report($report, $result, $params));
}
$tz = member_timezone();
$formHtml = view('reports/partials/form.php', ['report' => $report, 'spec' => $spec, 'params' => $params, 'errors' => $errors, 'lists' => report_pick_lists($pdo)]);
$resultsHtml = $errors === [] ? view('reports/partials/results.php', ['report' => $report, 'spec' => $spec, 'params' => $params, 'result' => $result, 'tz' => $tz])
    : '<div class="card" id="report-results"><div class="card-body text-danger" id="report-results-errors">' . e(implode(' ', $errors)) . '</div></div>';
render_screen($spec['title'], view('reports/report.php', ['report' => $report, 'spec' => $spec, 'params' => $params, 'errors' => $errors, 'formHtml' => $formHtml, 'resultsHtml' => $resultsHtml]),
    ['activeNav' => 'report-list', 'screen' => 'report', 'entity' => 'report', 'recordId' => $report]);

<?php
declare(strict_types=1);
/**
 * Action `report_run` (log `report.run`: the report and its params, never rows): run a report. `report` (one of the seven) + the filters (each validated), `format` html (the default: the results for the HTMX target, or the whole
 * report for a plain post) · csv (a download: a UTF-8 BOM, the columns as headed, amounts with two decimals, dates ISO). JSON mode answers {ok, did, rows (≤ 500), totals, truncated, report, params}. reports.read.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/reports/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/reports/present.php';
require_once dirname(__DIR__, 2) . '/app/features/reports/csv.php';
inv_handler_begin();
require_right('reports.read');
$pdo = db();
$report = request_string('report');
$spec = report_spec($report);
if ($spec === null) { refuse(404, 'No such report.'); }
$format = strtolower(request_string('format', 'html'));
if (!in_array($format, ['html', 'csv'], true)) { inv_refuse_fields(['format' => 'Format is html or csv.']); }
$errors = [];
$params = report_params($pdo, $report, $_POST, $errors);
if ($errors !== []) { inv_refuse_fields($errors); }
if ($format === 'csv') { $params['_limit'] = REPORT_CSV_LIMIT; }
$result = inv_guard($pdo, static fn (): array => report_rows($pdo, $report, $params));
log_activity($pdo, 'report.run', 'report', null, ['after' => ['report' => $report, 'params' => report_params_loggable($params) + ['format' => $format]]]);
$did = 'Ran ' . $spec['title'] . ' — ' . $result['count'] . ' row' . ($result['count'] === 1 ? '' : 's');
if (wants_json()) {
    emit_action_status(true, ['did' => $did, 'record_id' => null]);
    respond_saved(['did' => $did, 'record_id' => null] + present_report($report, $result, $params));
}
if ($format === 'csv') {
    emit_action_status(true, ['did' => $did, 'record_id' => null]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $report . '-' . date('Y-m-d') . '.csv"');
    header('X-Content-Type-Options: nosniff');
    echo report_csv($report, $result);
    exit;
}
emit_action_status(true, ['did' => $did, 'record_id' => null]);
$tz = member_timezone();
$resultsHtml = view('reports/partials/results.php', ['report' => $report, 'spec' => $spec, 'params' => $params, 'result' => $result, 'tz' => $tz]);
if (is_htmx_request()) {
    header('Vary: HX-Request');
    echo $resultsHtml;
    exit;
}
$formHtml = view('reports/partials/form.php', ['report' => $report, 'spec' => $spec, 'params' => $params, 'errors' => [], 'lists' => report_pick_lists($pdo)]);
render_screen($spec['title'], view('reports/report.php', ['report' => $report, 'spec' => $spec, 'params' => $params, 'errors' => [], 'formHtml' => $formHtml, 'resultsHtml' => $resultsHtml]),
    ['activeNav' => 'report-list', 'screen' => 'report', 'entity' => 'report', 'recordId' => $report]);

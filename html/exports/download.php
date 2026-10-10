<?php
declare(strict_types=1);
/**
 * Action `export_download` (log `export.download`: the export, the format, the rows, the period, the source — never rows): download one of five documents as CSV or JSON. The three accounting files need exports.all, or reports.read
 * with the cost wall's right (403 "The accounting files carry cost — the admin or the Buyer exports them."); the catalog and the listings reports.read. At most 50,000 rows (422 beyond: narrow it). JSON mode answers the facts
 * {ok, did, rows, bytes, period, location} and carries no file (DECISION 4).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/reports/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/exports/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/exports/documents.php';
require_once dirname(__DIR__, 2) . '/app/features/exports/csv.php';
require_once dirname(__DIR__, 2) . '/app/features/exports/present.php';
inv_handler_begin();
$pdo = db();
$export = request_string('export');
$spec = export_spec($export);
if ($spec === null) { refuse(404, 'No such export.'); }
if (!export_may($export)) {
    if ($spec['accounting']) { refuse(403, 'The accounting files carry cost — the admin or the Buyer exports them.'); }
    require_right('reports.read');
}
$format = strtolower(request_string('format'));
if (!in_array($format, ['csv', 'json'], true)) { inv_refuse_fields(['format' => 'Format is csv or json.']); }
$errors = [];
$params = export_params($pdo, $export, $_POST, $errors);
if ($errors !== []) { inv_refuse_fields($errors); }
$doc = inv_guard($pdo, static fn (): array => export_document($pdo, $export, $params));
$rows = export_rows_count($export, $doc);
if ($rows > EXPORT_ROW_LIMIT) { inv_refuse_fields(['export' => 'That is ' . number_format($rows) . ' rows — more than an export carries (' . number_format(EXPORT_ROW_LIMIT) . '); narrow the period.']); }
$body = $format === 'json' ? json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_PRETTY_PRINT) : document_csv($export, $doc);
$opts = ['after' => ['export' => $export, 'format' => $format, 'rows' => $rows, 'period' => export_period($params)]];
if (isset($params['source']) && $params['source'] !== null) { $opts['source_id'] = $params['source']; $opts['after']['source_id'] = $params['source']; }
log_activity($pdo, 'export.download', 'export', null, $opts);
$did = 'Exported ' . $spec['title'] . ' — ' . $rows . ' row' . ($rows === 1 ? '' : 's') . ' as ' . strtoupper($format);
if (wants_json()) {
    emit_action_status(true, ['did' => $did, 'record_id' => null]);
    respond_saved(['did' => $did, 'record_id' => null, 'rows' => $rows, 'bytes' => strlen($body), 'period' => export_period($params), 'location' => '/exports/']);
}
emit_action_status(true, ['did' => $did, 'record_id' => null]);
header('Content-Type: ' . ($format === 'json' ? 'application/json; charset=utf-8' : 'text/csv; charset=utf-8'));
header('Content-Disposition: attachment; filename="' . export_filename($export, $params, $format) . '"');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . strlen($body));
echo $body;
exit;

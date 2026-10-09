<?php
declare(strict_types=1);
/** /sources/{id}/pulls?status=&page= — a source's pulls with their policy facts (screen `source-pulls`). */
require_once dirname(__DIR__, 2) . '/app/features/sources/handler.php';
require_right('inventory.read');
$pdo = db();
$s = source_or_404($pdo, request_integer('id') ?? request_integer('source'));
$status = request_string('status');
$page = max(1, request_integer('page') ?? 1);
$rows = source_pulls($pdo, ['source' => $s['source_id'], 'status' => $status], 51, ($page - 1) * 50);
$more = count($rows) > 50;
$rows = array_slice($rows, 0, 50);
log_screen_view($pdo, 'source-pulls');
if (wants_json()) {
    respond_screen(['source' => present_source($s), 'status' => $status, 'page' => $page, 'more' => $more, 'pulls' => array_map('present_pull', $rows)]);
}
render_screen('Pulls of ' . $s['name'], view('sources/pulls.php', ['s' => $s, 'rows' => $rows, 'more' => $more, 'page' => $page, 'status' => $status, 'tz' => member_timezone(), 'here' => here_url()]),
    ['activeNav' => 'source-list', 'screen' => 'source-pulls', 'entity' => 'source', 'recordId' => (string) $s['source_id']]);

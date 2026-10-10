<?php
declare(strict_types=1);
/**
 * /trail?product=&variant=&source=&order=&purchase_order=&member=&action=&period= — the activity trail in words (screen `trail`, reports-admin.md): my own rows newest first, 50 a page; with a record
 * param that record's history — `source`, `order`, `purchase_order` by the audit keys (everything that touched it); `product`, `variant` by entity and the rows whose payload names them; `member` another person's
 * trail (the admin or reports.read; an agent's rows any reader — the tool surface's DECISION 17). Filters `action` (a prefix) and `period` (1 · 7 · 30 · 90 days; 30 by default).
 * Pattern B on #trail-results. JSON: the rows with their sentences.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/activity/queries.php';
require_once dirname(__DIR__) . '/app/features/activity/present.php';
require_login();
$pdo = db();
$me = (int) current_member_id();
$record = null;
foreach (['product', 'variant', 'source', 'order', 'purchase_order', 'member'] as $k) {
    if (($v = request_integer($k)) !== null) { $record = [$k, $v]; break; }
}
if ($record !== null && $record[0] === 'member' && $record[1] !== $me && !is_inv_admin() && !has_right('reports.read')) {
    $st = $pdo->prepare("SELECT member_kind FROM members WHERE id = :id");
    $st->execute(['id' => $record[1]]);
    if ($st->fetchColumn() !== 'agent') {
        require_right('reports.read');
    }
}
$filters = ['own' => $record === null, 'action' => request_string('action'), 'since' => request_integer('period') ?? request_integer('since')];
if ($record !== null) { $filters[$record[0]] = $record[1]; }
if (!preg_match('/^[a-z_]+(\.[a-z_]+)*\.?$/', $filters['action'])) { $filters['action'] = ''; }
if (!in_array($filters['since'], [1, 7, 30, 90], true)) { $filters['since'] = 30; }
$page = max(1, (int) (request_integer('page') ?? 1));
$result = find_my_activity($pdo, $me, $page, $filters);
$rows = $result['rows'];
foreach ($rows as &$r) { $r['sentence'] = activity_sentence($r); }
unset($r);
$query = array_filter([($record[0] ?? 'x') => $record[1] ?? null, 'action' => $filters['action'], 'period' => $filters['since']], static fn ($v) => $v !== null && $v !== '');
if (!wants_json() || ($_SERVER['HTTP_X_SCREEN_VIEW'] ?? '') === '1') {
    if (!(is_htmx_request() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'trail-results')) {
        log_activity($pdo, 'screen.view', null, null, ['screen' => 'trail', 'after' => ['screen' => 'trail'] + ($record !== null ? [$record[0] => $record[1]] : [])]);
    }
}
if (wants_json()) {
    respond_screen(['rows' => array_map('present_activity_row', $rows), 'page' => $page, 'more' => $result['more'], 'filters' => $query]);
}
$data = ['rows' => $rows, 'page' => $page, 'more' => $result['more'], 'filters' => $filters, 'query' => $query, 'tz' => member_timezone(), 'record' => $record];
$resultsHtml = view('activity/rows.php', $data);
if (is_htmx_request() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'trail-results') {
    header('Vary: HX-Request');
    echo $resultsHtml;
    exit;
}
render_screen('My trail', view('activity/trail.php', $data + ['resultsHtml' => $resultsHtml]), ['activeNav' => 'trail', 'screen' => 'trail', 'entity' => $record !== null ? $record[0] : '', 'recordId' => $record !== null ? (string) $record[1] : '']);

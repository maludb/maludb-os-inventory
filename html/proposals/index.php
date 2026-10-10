<?php
declare(strict_types=1);
/** /proposals/?kind=&status=&date= — the Buyer agent's proposals (screen `proposal-list`) as cards, in three tabs (proposed, accepted, dismissed), with the day's morning note above, collapsed. reports.read or agents.settings. */
require_once dirname(__DIR__, 2) . '/app/features/orders/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/buyer/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/buyer/present.php';
require_once dirname(__DIR__, 2) . '/app/features/buyer/write.php';
require_any_right('reports.read|agents.settings');
$pdo = db();
$status = request_string('status', 'proposed');
if (!isset(PROPOSAL_STATUSES[$status])) { $status = 'proposed'; }
$kind = request_string('kind');
$kind = isset(PROPOSAL_KINDS[$kind]) ? $kind : '';
$date = request_date('date');
if ($date === false) { refuse(422, 'That is not a date.'); }
$f = ['kind' => $kind === '' ? [] : [$kind], 'date' => $date ?? ''];
$page = max(1, request_integer('page') ?? 1);
$res = find_buyer_proposals($pdo, ['status' => $status] + $f, $page);
$counts = proposal_tab_counts($pdo, $f);
log_screen_view($pdo, 'proposal-list');
if (wants_json()) {
    respond_screen(['status' => $status, 'kind' => $kind, 'date' => $date, 'page' => $res['page'], 'total' => $res['total'], 'counts' => $counts, 'proposals' => array_map('present_buyer_proposal', $res['rows'])]);
}
$note = morning_note($pdo, $date);
render_screen('Buyer proposals', view('proposals/index.php', ['rows' => $res['rows'], 'total' => $res['total'], 'page' => $res['page'], 'pages' => $res['pages'], 'status' => $status, 'kind' => $kind, 'date' => $date ?? '',
    'counts' => $counts, 'note' => $note, 'dates' => proposal_dates($pdo), 'may' => ['decide' => has_right('purchasing.write'), 'human' => (current_member()['member_kind'] ?? '') === 'human'], 'tz' => member_timezone(), 'here' => here_url()]),
    ['activeNav' => 'proposal-list', 'screen' => 'proposal-list', 'entity' => 'buyer_proposal']);

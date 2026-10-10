<?php
declare(strict_types=1);
/**
 * /watches/?kind=&fired=&member=&cleared=&page= — screen `watch-list` (find.md): my watches (watches.all: everyone's, with the member filter);
 * active first, then fired_at desc, then newest; 100 a page; Clear on each. No add form — a watch is set from Find or a record.
 */
require_once dirname(__DIR__, 2) . '/app/features/watches/handler.php';
require_right('watches.own');
$pdo = db();
$all = has_right('watches.all');
$filters = ['kind' => request_string('kind'), 'fired' => request_string('fired'), 'member' => $all ? request_integer('member') : null, 'cleared' => ($_GET['cleared'] ?? '') === '1'];
$page = max(1, request_integer('page') ?? 1);
$total = count_watches($pdo, $filters);
$rows = find_watches($pdo, $filters, WATCH_PAGE, ($page - 1) * WATCH_PAGE);
log_screen_view($pdo, 'watch-list');
if (wants_json()) { respond_screen(['filters' => $filters, 'page' => $page, 'total' => $total, 'watches' => array_map('present_watch', $rows)]); }
render_screen('Watches', view('watches/index.php', ['rows' => $rows, 'filters' => $filters, 'page' => $page, 'total' => $total, 'all' => $all,
    'members' => $all ? $pdo->query('SELECT DISTINCT member_id, member_name FROM mcp_watches ORDER BY member_name')->fetchAll() : [], 'tz' => member_timezone(), 'here' => here_url(),
    'notice' => inv_notice($_GET['notice'] ?? null, WATCH_NOTICES)]), ['activeNav' => 'watch-list', 'screen' => 'watch-list', 'entity' => 'watch', 'recordId' => '']);

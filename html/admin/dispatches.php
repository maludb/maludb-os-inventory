<?php
declare(strict_types=1);
/** /admin/dispatches?status=&agent= — every dispatch to an agent (screen `dispatch-list`): the agent, the asker, kind, how, the watch's title, status, attempts, the run (a link to the kernel's AI Ops when the launcher is known), when, the reply's excerpt; Retry on a failed or refused one. agents.settings. */
require_once dirname(__DIR__, 2) . '/app/features/orders/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/agents/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/agents/present.php';
require_right('agents.settings');
$pdo = db();
$status = request_string('status');
$status = isset(DISPATCH_STATUSES[$status]) ? $status : '';
$agent = request_integer('agent');
$page = max(1, request_integer('page') ?? 1);
$res = find_dispatches($pdo, ['status' => $status, 'agent' => $agent], $page);
log_screen_view($pdo, 'dispatch-list');
if (wants_json()) {
    respond_screen(['filters' => ['status' => $status, 'agent' => $agent], 'page' => $res['page'], 'total' => $res['total'], 'dispatches' => array_map('present_dispatch', $res['rows'])]);
}
render_screen('Dispatches', view('admin/dispatches.php', ['rows' => $res['rows'], 'total' => $res['total'], 'page' => $res['page'], 'pages' => $res['pages'], 'status' => $status, 'agent' => $agent, 'agents' => dispatch_agents($pdo),
    'tz' => member_timezone(), 'here' => here_url(), 'notice' => inv_notice($_GET['notice'] ?? null, ['retried' => ['success', 'The dispatch is queued again; the worker calls the agent on its next pass.']])]),
    ['activeNav' => 'dispatch-list', 'screen' => 'dispatch-list', 'entity' => 'agent_dispatch']);

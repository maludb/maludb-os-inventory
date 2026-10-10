<?php
declare(strict_types=1);
/**
 * /admin/agents — the agents here (screen `agent-list`), read-only: the agents maludb-os.json declares, each matched to a mirror agent, with the job, the roles held here, the duty in words, the skills, the last action, the last dispatch,
 * the dispatches pending and failed and the proposals today; then every other agent holding a role here. Hiring, grants, duties and approvals are the kernel's — links to the OS. agents.settings.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/agents/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/buyer/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/admin/present.php';
require_once dirname(__DIR__, 2) . '/app/features/activity/present.php';
require_right('agents.settings');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { header('Allow: GET'); refuse(405, 'This page takes no form — hiring, grants and duties are the kernel\'s.'); }
$pdo = db();
$agents = agents_here($pdo);
log_screen_view($pdo, 'agent-list');
if (wants_json()) {
    respond_screen(['agents' => array_map(static fn (array $a): array => ['member_id' => $a['member_id'], 'display_name' => $a['display_name'], 'job_title' => $a['job_title'], 'declared_key' => $a['declared_key'], 'roles' => $a['roles'], 'hired' => $a['hired'],
        'duty' => $a['duty'] === null ? null : ['name' => $a['duty']['name'], 'schedule' => cron_in_words($a['duty']['schedule_cron'])], 'skills' => $a['skills'],
        'last_action' => $a['last_action'] === null ? null : ['action' => $a['last_action']['action'], 'occurred_at' => json_ts($a['last_action']['occurred_at']), 'sentence' => $a['last_action']['sentence']],
        'last_dispatch' => $a['last_dispatch'] === null ? null : ['status' => $a['last_dispatch']['status'], 'created_at' => json_ts($a['last_dispatch']['created_at'])],
        'dispatches_pending' => $a['dispatches_pending'], 'dispatches_failed' => $a['dispatches_failed'], 'proposals_today' => $a['proposals_today']], $agents)]);
}
render_screen('Agents', view('admin/agents.php', ['agents' => $agents, 'osLinks' => os_agent_links(), 'tz' => member_timezone()]), ['activeNav' => 'agent-list', 'screen' => 'agent-list', 'entity' => 'agent']);

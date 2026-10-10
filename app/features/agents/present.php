<?php
declare(strict_types=1);

/** Dispatches as chips and as JSON (whitelists — never the reply's words beyond the 200-character excerpt). */

function dispatch_status_chip(array $d): string
{
    $s = (string) $d['status'];
    $label = $s === 'sent' ? ($d['run_id'] === null ? 'pending' : 'running') : str_replace('_', ' ', $s);
    $c = ['sent' => 'secondary', 'answered' => 'success', 'awaiting_approval' => 'warning', 'refused' => 'dark', 'failed' => 'danger'][$s] ?? 'secondary';
    return '<span class="badge bg-soft-' . $c . ' text-' . $c . '">' . e($label) . '</span>';
}

/** The kernel's AI Ops run page: OS_LAUNCHER_URL's host with `app.` turned into `os.`; null when the launcher is not set or names no app. host. */
function dispatch_run_url(?int $runId): ?string
{
    if ($runId === null) { return null; }
    $base = (string) env('OS_LAUNCHER_URL', '');
    $p = parse_url($base);
    if ($p === false || empty($p['host']) || !str_starts_with((string) $p['host'], 'app.')) { return null; }
    return ($p['scheme'] ?? 'https') . '://os.' . substr((string) $p['host'], 4) . (isset($p['port']) ? ':' . $p['port'] : '') . '/ai/runs/' . $runId;
}

function present_dispatch(array $d): array
{
    return ['dispatch_id' => (int) $d['dispatch_id'], 'agent_member_id' => (int) $d['agent_member_id'], 'agent' => $d['agent_name'], 'kind' => $d['kind'], 'via' => $d['via'],
            'acting_member_id' => $d['acting_member_id'] === null ? null : (int) $d['acting_member_id'], 'asker' => $d['asker_name'] ?? null, 'status' => $d['status'],
            'run_id' => $d['run_id'] === null ? null : (int) $d['run_id'], 'run_url' => dispatch_run_url($d['run_id'] === null ? null : (int) $d['run_id']), 'watch_id' => $d['watch_id'] === null ? null : (int) $d['watch_id'],
            'detail' => $d['detail'], 'reply_excerpt' => $d['reply_excerpt'], 'attempts' => (int) $d['attempts'], 'created_at' => json_ts($d['created_at']), 'answered_at' => json_ts($d['answered_at'])];
}

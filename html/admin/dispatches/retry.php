<?php
declare(strict_types=1);
/**
 * Action `dispatch_retry` (log `agent.dispatch` with after.retry = true): agents.settings. A failed or refused dispatch goes back to sent with no run, due at once; the worker's dispatches pass calls the agent again.
 * Anything else is "Only a failed dispatch is retried." Location /admin/dispatches; refresh dispatchChanged.
 */
require_once dirname(__DIR__, 3) . '/app/features/orders/handler.php';
require_once dirname(__DIR__, 3) . '/app/features/agents/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/agents/present.php';
inv_handler_begin();
require_right('agents.settings');
$pdo = db();
$d = find_dispatch($pdo, request_integer('dispatch') ?? 0);
if ($d === null) { refuse(404, 'Dispatch not found.'); }
inv_guard($pdo, static function () use ($pdo, $d): void {
    $pdo->beginTransaction();
    retry_dispatch($pdo, (int) $d['dispatch_id'], (int) current_member_id());
    log_activity($pdo, 'agent.dispatch', 'agent_dispatch', (int) $d['dispatch_id'], ['after' => ['dispatch_id' => (int) $d['dispatch_id'], 'agent_member_id' => (int) $d['agent_member_id'], 'kind' => $d['kind'], 'via' => $d['via'], 'retry' => true]]);
    $pdo->commit();
});
inv_done('Queued dispatch ' . (int) $d['dispatch_id'] . ' again', (int) $d['dispatch_id'], inv_land('/admin/dispatches', 'retried'), 'dispatchChanged', ['dispatch_id' => (int) $d['dispatch_id'], 'status' => 'sent']);

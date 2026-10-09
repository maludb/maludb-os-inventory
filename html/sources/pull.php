<?php
declare(strict_types=1);
/** Action `source_pull` (log `source.pull_start`: pull_id, kind manual, queued true): "Pull now" queues a pull the worker runs within a minute; refused while one runs and within 5 minutes of the last. sources.write; free for an agent. */
require_once dirname(__DIR__, 2) . '/app/features/sources/handler.php';
source_write_begin('sources.write');
$pdo = db();
$s = source_or_404($pdo, request_integer('source') ?? request_integer('source_id'));
$pid = inv_guard($pdo, static function () use ($pdo, $s): int {
    $pdo->beginTransaction();
    $pid = queue_pull($pdo, $s['source_id'], (int) current_member_id());
    source_log($pdo, 'source.pull_start', 'source_pull', $pid, $s['source_id'], ['pull_id' => $pid, 'kind' => 'manual', 'queued' => true]);
    $pdo->commit();
    return $pid;
});
inv_done('Queued — the worker runs it within a minute', $pid, inv_land('/sources/' . $s['source_id'], 'queued', 'pull-row-' . $pid), 'sourceChanged', ['queued' => true, 'pull_id' => $pid]);

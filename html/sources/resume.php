<?php
declare(strict_types=1);
/** Action `source_resume` (log `source.resume`): inv_source_resume() — the ladder cleared, a blocked robots state becomes unknown. sources.write. */
require_once dirname(__DIR__, 2) . '/app/features/sources/handler.php';
source_write_begin('sources.write');
$pdo = db();
$s = source_or_404($pdo, request_integer('source') ?? request_integer('source_id'));
inv_guard($pdo, static function () use ($pdo, $s): void {
    $pdo->beginTransaction();
    resume_source($pdo, $s['source_id']);
    source_log($pdo, 'source.resume', 'source', $s['source_id'], $s['source_id'], ['was_paused' => $s['paused_at'] !== null, 'consecutive_failures' => $s['consecutive_failures'], 'robots_state' => $s['robots_state']]);
    $pdo->commit();
});
inv_done('Resumed ' . $s['name'], $s['source_id'], inv_land('/sources/' . $s['source_id'], 'resumed'), 'sourceChanged');

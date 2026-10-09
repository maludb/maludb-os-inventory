<?php
declare(strict_types=1);
/** Action `source_pause` (log `source.pause`: reason): the worker skips it until a person resumes it; the reason ≤ 200, "paused by <name>" when empty. sources.write. */
require_once dirname(__DIR__, 2) . '/app/features/sources/handler.php';
source_write_begin('sources.write');
$pdo = db();
$s = source_or_404($pdo, request_integer('source') ?? request_integer('source_id'));
if ($s['paused_at'] !== null) { refuse(422, $s['name'] . ' is already paused.'); }
$reason = trim((string) (req_val('reason') ?? ''));
$reason = $reason === '' ? 'paused by ' . (current_member()['display_name'] ?? 'a person') : mb_substr($reason, 0, 200);
inv_guard($pdo, static function () use ($pdo, $s, $reason): void {
    $pdo->beginTransaction();
    pause_source($pdo, $s['source_id'], $reason);
    source_log($pdo, 'source.pause', 'source', $s['source_id'], $s['source_id'], ['reason' => $reason]);
    $pdo->commit();
});
inv_done('Paused ' . $s['name'], $s['source_id'], inv_land('/sources/' . $s['source_id'], 'paused'), 'sourceChanged', ['reason' => $reason]);

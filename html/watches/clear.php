<?php
declare(strict_types=1);
/** Action `watch_clear` (log `watch.clear`: watch_id, kind): own, or anyone's for watches.all — the view decides; cleared, never deleted. */
require_once dirname(__DIR__, 2) . '/app/features/watches/handler.php';
inv_handler_begin();
require_right('watches.own');
$pdo = db();
$id = request_integer('watch') ?? request_integer('id') ?? 0;
$w = find_watch($pdo, $id) ?? refuse(404, 'Watch not found.');
if (!$w['active']) { refuse(422, 'That watch is already cleared.'); }
inv_guard($pdo, static function () use ($pdo, $w): void {
    $pdo->beginTransaction();
    clear_watch($pdo, $w['watch_id']);
    watch_log($pdo, 'watch.clear', $w['watch_id'], ['watch_id' => $w['watch_id'], 'kind' => $w['kind']], $w['lv_source_id']);
    $pdo->commit();
});
inv_done('Cleared the watch on ' . $w['target_label'], $w['watch_id'], inv_land('/watches/', 'cleared', 'watch-row-' . $w['watch_id']), 'watchChanged');

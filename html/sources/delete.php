<?php
declare(strict_types=1);
/** Action `source_delete` (log `source.delete`: name, connector, the counts; an agent's pauses — deletion): the cascade takes its listings, offers, pulls, proposals and credential; the price sheet stays. records.delete. */
require_once dirname(__DIR__, 2) . '/app/features/sources/handler.php';
source_write_begin('records.delete');
$pdo = db();
$s = source_or_404($pdo, request_integer('source') ?? request_integer('source_id'));
$counts = source_delete_counts($pdo, $s['source_id']);
inv_guard($pdo, static function () use ($pdo, $s, $counts): void {
    $pdo->beginTransaction();
    source_log($pdo, 'source.delete', 'source', $s['source_id'], $s['source_id'], ['name' => $s['name'], 'connector' => $s['connector']] + $counts);
    delete_source($pdo, $s['source_id']);
    $pdo->commit();
});
inv_done('Deleted ' . $s['name'], $s['source_id'], inv_land('/sources/', 'deleted'), 'sourceChanged', $counts);

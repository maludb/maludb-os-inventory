<?php
declare(strict_types=1);
/**
 * Action `watch_set` (log `watch.set`: watch_id, kind, variant_id, listing_variant_id, product_id, threshold, text_me, agent_member_id; source_id of a
 * listing variant's source): one target, one kind, one threshold, for me (an agent under a run token sets its own). A threshold is refused for the
 * kinds that have none; cost_below needs the wall's right; naming an agent needs watches.all; a duplicate is refused naming the existing watch;
 * last_state is evaluated at once. watches.own.
 */
require_once dirname(__DIR__, 2) . '/app/features/watches/handler.php';
inv_handler_begin();
require_right('watches.own');
$pdo = db();
[$f, $target] = watch_from_request($pdo);
$me = (int) current_member_id();
$id = inv_guard($pdo, static function () use ($pdo, $f, $target, $me): int {
    $pdo->beginTransaction();
    $pdo->query('LOCK TABLE watches IN SHARE ROW EXCLUSIVE MODE');          // the dedupe has no unique index: one writer at a time decides it
    $dup = watch_exists($pdo, $me, $f['kind'], $target, $f['threshold']);
    if ($dup !== null) { throw new DomainException('You already watch that.|' . $dup); }
    $id = save_watch($pdo, $f, $me);
    watch_log($pdo, 'watch.set', $id, ['watch_id' => $id, 'kind' => $f['kind'], 'variant_id' => $f['variant_id'], 'listing_variant_id' => $f['listing_variant_id'], 'product_id' => $f['product_id'],
        'threshold' => $f['threshold'], 'text_me' => $f['text_me'], 'agent_member_id' => $f['agent_member_id']], $target['source_id']);
    $pdo->commit();
    return $id;
});
$state = one_row($pdo, 'SELECT last_state FROM watches WHERE id = :id', ['id' => $id])['last_state'] ?? null;     // a boolean false is a state, not "no row"
$title = (string) one_value($pdo, 'SELECT inv_watch_title(:id)', ['id' => $id]);
inv_done('Watching ' . $title, $id, inv_land(return_path('/watches/'), 'watched', 'watch-row-' . $id), 'watchChanged', ['title' => $title, 'current' => $state === null ? null : (bool) $state]);

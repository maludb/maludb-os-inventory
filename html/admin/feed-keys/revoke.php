<?php
declare(strict_types=1);
/** Action `feed_key_revoke` (log `feed.key_revoke`; confirm): the key answers 401 at once. A key already revoked is refused. Revoking a key mid-overlap revokes only it. feed.keys. */
require_once dirname(__DIR__, 3) . '/app/features/feed/handler.php';
inv_handler_begin();
require_right('feed.keys');
$pdo = db();
$me = (int) current_member_id();
$k = feed_key_or_404($pdo, request_integer('key') ?? request_integer('key_id'));
if ($k['revoked_at'] !== null) { refuse(422, 'That key is already revoked.'); }
inv_guard($pdo, static function () use ($pdo, $me, $k): void {
    $pdo->beginTransaction();
    revoke_feed_key($pdo, $k['key_id'], $me);
    feed_log($pdo, 'feed.key_revoke', $k['key_id'], ['label' => $k['label'], 'consumer_kind' => $k['consumer_kind']]);
    $pdo->commit();
});
inv_done('Revoked the key "' . $k['label'] . '"', $k['key_id'], inv_land('/admin/feed-keys/', 'revoked', 'feed-key-row-' . $k['key_id']), 'feedChanged', ['key_id' => $k['key_id']]);

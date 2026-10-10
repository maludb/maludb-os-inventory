<?php
declare(strict_types=1);
/**
 * Action `feed_key_rotate` (log `feed.key_rotate`; confirm): a live key only. A new key under the same minter, label, consumer, price list and limits, shown ONCE; the old one keeps working for the overlap
 * (the settings' hours, 24 by default) and no longer. JSON carries `key` (the new raw key) and `old_expires_at`. feed.keys.
 */
require_once dirname(__DIR__, 3) . '/app/features/feed/handler.php';
inv_handler_begin();
require_right('feed.keys');
$pdo = db();
$me = (int) current_member_id();
$old = feed_key_or_404($pdo, request_integer('key') ?? request_integer('key_id'));
$new = inv_guard($pdo, static function () use ($pdo, $me, $old): array {
    if ($old['revoked_at'] === null && !$old['is_live']) { throw new DomainException('Only a live key is rotated'); }
    $pdo->beginTransaction();
    $n = rotate_feed_key($pdo, $old['key_id'], $me);
    $row = find_feed_key($pdo, $n['key_id']) ?? throw new RuntimeException('The key was not found after it was rotated.');
    feed_log($pdo, 'feed.key_rotate', $n['key_id'], ['label' => $row['label'], 'consumer_kind' => $row['consumer_kind'], 'rotated_from' => $old['key_id'], 'old_expires_at' => $n['old_expires_at']]);
    $pdo->commit();
    return $n;
});
$oldExpires = $new['old_expires_at'] === null ? null : json_ts((string) $new['old_expires_at']);
if (!wants_json()) { feed_key_stash($new['key_id'], $new['raw'], (string) $new['old_expires_at']); }
feed_key_done('Rotated the key "' . $old['label'] . '" — the new one is shown once', $new['key_id'], $new['raw'], 'rotated', ['old_key_id' => $old['key_id'], 'old_expires_at' => $oldExpires]);

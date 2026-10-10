<?php
declare(strict_types=1);
/**
 * Action `feed_key_mint` (log `feed.key_mint`: the label and facts, token_id = the key — never the key or its hash; external_send for an agent): a feed key for one outside consumer — website, another installation or a
 * partner store (which names an active price list). Limits blank take the settings' defaults. The raw key (`feed_` + 48 hex) is shown ONCE: in the copy-once box on the next page, or in a JSON reply's `key`. feed.keys.
 */
require_once dirname(__DIR__, 3) . '/app/features/feed/handler.php';
inv_handler_begin();
require_right('feed.keys');
$pdo = db();
$me = (int) current_member_id();
$errors = [];
$f = feed_key_from_request($pdo, $errors);
if ($errors !== []) { inv_refuse_fields($errors); }
$minted = inv_guard($pdo, static function () use ($pdo, $me, $f): array {
    $pdo->beginTransaction();
    $m = mint_feed_key($pdo, $f, $me);
    $row = find_feed_key($pdo, $m['key_id']) ?? throw new RuntimeException('The key was not found after it was made.');
    feed_log($pdo, 'feed.key_mint', $m['key_id'], feed_key_loggable($row));
    $pdo->commit();
    return $m;
});
if (!wants_json()) { feed_key_stash($minted['key_id'], $minted['raw']); }
feed_key_done('Minted the key "' . $f['label'] . '" — shown once', $minted['key_id'], $minted['raw'], 'minted');

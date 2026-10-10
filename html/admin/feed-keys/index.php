<?php
declare(strict_types=1);
/**
 * /admin/feed-keys/?key=&bucket= — the availability feed's keys (screen `feed-key-list`): label, consumer kind, price list, limits, today's count, last used, status, minter; mint, rotate, revoke. With ?key= the key's
 * usage beneath (day buckets of the last 35 days; &bucket=minute the last hour). The minted or rotated key is shown ONCE from the session. feed.keys.
 */
require_once dirname(__DIR__, 3) . '/app/features/feed/handler.php';
require_right('feed.keys');
$pdo = db();
$tz = member_timezone();
$keys = find_feed_keys($pdo);
$sel = request_integer('key');
$selKey = $sel === null ? null : find_feed_key($pdo, $sel);
$bucket = request_string('bucket') === 'minute' ? 'minute' : 'day';
$usage = $selKey === null ? null : key_usage($pdo, (int) $selKey['key_id'], $bucket);
feed_screen_view($pdo, 'feed-key-list', $selKey === null ? null : (int) $selKey['key_id']);
if (wants_json()) {
    respond_screen(['keys' => array_map('present_feed_key', $keys), 'usage' => $usage === null ? null : present_usage($usage), 'url' => rtrim((string) env('INV_PUBLIC_BASE_URL', ''), '/') . '/api/v1/availability']);
}
$minted = feed_key_unstash();
$limits = one_row($pdo, 'SELECT feed_rate_per_minute AS per_minute, feed_rate_per_day AS per_day FROM inv_settings WHERE id = 1') ?? ['per_minute' => 60, 'per_day' => 10000];
render_screen('Feed keys', view('admin/feed-keys/index.php', ['keys' => $keys, 'selected' => $selKey, 'usage' => $usage, 'bucket' => $bucket, 'minted' => $minted, 'tz' => $tz, 'here' => here_url(),
    'limits' => $limits, 'feedUrl' => rtrim((string) env('INV_PUBLIC_BASE_URL', ''), '/') . '/api/v1/availability', 'notice' => inv_notice($_GET['notice'] ?? null, FEED_NOTICES)]), ['activeNav' => 'feed-key-list', 'screen' => 'feed-key-list', 'entity' => 'feed_key']);

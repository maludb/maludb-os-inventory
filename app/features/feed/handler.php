<?php
declare(strict_types=1);

/**
 * The feed's prelude (feed.md "Handlers"): required by every html/admin/feed-keys and html/admin/price-lists controller. It loads the feed's queries, presenters and request readers, and holds the gate
 * (feed.keys), the 404s, and feed_log() — the one writer of a key's log row, which stamps `token_id` (the audit key of design §6) on every row about a key. A key's value is never an argument here.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/write.php';

/** The key through its view (null → "Feed key not found." — also for a caller without feed.keys, whom the gate has refused already). */
function feed_key_or_404(PDO $pdo, ?int $id): array
{
    $k = $id === null ? null : find_feed_key($pdo, $id);
    if ($k === null) { refuse(404, 'Feed key not found.'); }
    return $k;
}

function price_list_or_404(PDO $pdo, ?int $id): array
{
    $p = $id === null ? null : find_price_list($pdo, $id);
    if ($p === null) { refuse(404, 'Price list not found.'); }
    return $p;
}

/** A key's log row: entity `feed_key`, `token_id` = the key. $after never carries the key or its hash. */
function feed_log(PDO $pdo, string $action, int $keyId, array $after, array $before = []): void
{
    log_activity($pdo, $action, 'feed_key', $keyId, ['token_id' => $keyId, 'after' => $after] + ($before === [] ? [] : ['before' => $before]));
}

/** A key's reply after a mint or a rotate: the browser lands on the keys page (the raw key waits in the session for one render), JSON gets it once (never through emit_action_status — an action token's X-Action-Data header). */
function feed_key_done(string $did, int $keyId, string $raw, string $notice, array $extra = []): never
{
    $land = inv_land('/admin/feed-keys/', $notice, 'feed-key-row-' . $keyId);
    emit_action_status(true, ['did' => $did, 'record_id' => $keyId, 'refresh' => 'feedChanged']);
    if (wants_json()) {
        respond_saved(['did' => $did, 'record_id' => $keyId, 'location' => '/admin/feed-keys/#feed-key-row-' . $keyId, 'refresh' => 'feedChanged', 'key' => $raw] + $extra);       // the value, once
    }
    saved_go($land, 'feedChanged');
}

/** `screen.view` of one of the four admin screens; the keys page with ?key= carries `after.key` (and the key as its entity, so the trail of a key shows who looked at its usage). */
function feed_screen_view(PDO $pdo, string $screen, ?int $keyId = null): void
{
    if (wants_json() && ($_SERVER['HTTP_X_SCREEN_VIEW'] ?? '') !== '1') { return; }
    if ($keyId === null) { log_activity($pdo, 'screen.view', null, null, ['screen' => $screen]); return; }
    log_activity($pdo, 'screen.view', 'feed_key', $keyId, ['screen' => $screen, 'token_id' => $keyId, 'after' => ['key' => $keyId]]);
}

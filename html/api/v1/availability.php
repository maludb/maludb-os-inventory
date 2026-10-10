<?php
declare(strict_types=1);
/**
 * GET /api/v1/availability?gtin=|sku=|q=[&size=] — the availability feed (design §4; document os.inventory-feed/1; feed.md "The feed"). A DOOR, not an action: no session, no CSRF, no cookie. A feed key (`feed_` + 48 hex) as the
 * Bearer is the only authority — one 401 for every failure. In order, every call: CORS (API_CORS_ORIGINS only, never credentials) → GET only → the key → the rate (60 a minute and 10,000 a day by default; a refused call is never
 * charged to the day) → the query (exactly one of gtin, sku, q; q up to 120) → inv_feed_answer() as the key's minter → `feed.read` logged (source `feed`, token_id, the key's LABEL — never its value).
 */
$GLOBALS['__public_door'] = 'feed';
require_once dirname(__DIR__, 3) . '/app/api/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/feed/queries.php';
feed_drop_session();
api_cors();
api_require_get();
$key = feed_authenticate();
feed_rate_limit($key);
$parsed = feed_query_from_request();
$pdo = db();
try {
    $doc = feed_answer($pdo, (int) $key['key_id'], $parsed['query']);
} catch (PDOException $e) {
    if ((string) $e->getCode() === '42501') {              // the minter no longer reads here: the key is dead
        feed_unauthorized();
    }
    throw $e;
}
log_activity($pdo, 'feed.read', 'feed_key', (int) $key['key_id'], ['actor_member_id' => null, 'source' => 'feed', 'token_id' => (int) $key['key_id'],
    'after' => ['key_label' => (string) $key['label'], 'count' => (int) $doc['count'], 'query_kind' => $parsed['kind'], 'query_excerpt' => $parsed['excerpt']]]);
header('Cache-Control: no-store');
api_json($doc);

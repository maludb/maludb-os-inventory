<?php
declare(strict_types=1);

/**
 * The token API (mcp-and-api.md §6): GET-only /api/v1/*.php, a session or a Bearer token with
 * scope 'api'. One 401 body for every failure. CORS from an allow-list, never with credentials.
 */
require_once dirname(__DIR__) . '/bootstrap.php';

set_exception_handler(static function (Throwable $e): void {
    error_log('api error: ' . $e->getMessage());
    if (!headers_sent()) {
        api_error('server_error', 'Something went wrong.', 500);
    }
    exit;
});

function api_json(array $payload, int $status = 200): never
{
    json_response($payload, $status);
}

function api_error(string $code, string $message, int $status): never
{
    json_error($code, $message, $status);
}

function api_cors(): void
{
    header('Vary: Origin');
    $configured = array_filter(array_map('trim', explode(',', (string) env('API_CORS_ORIGINS', ''))));
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && in_array($origin, $configured, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function api_require_get(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        api_error('method_not_allowed', 'Only GET is supported.', 405);
    }
}

function api_bearer_token(): string
{
    $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $header = (string) $value;
                break;
            }
        }
    }
    return preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m) ? $m[1] : '';
}

/** The caller as a member row, or 401 — a session first, then an `api` token. */
function api_authenticate(): array
{
    $pdo = db();
    if (is_logged_in() && empty($GLOBALS['__action_authed'])) {
        $member = current_member();
        if ($member !== null && $member['status'] === 'active' && $member['capability'] !== null) {
            return $member;
        }
    }
    $token = api_bearer_token();
    if ($token === '') {
        api_error('unauthorized', 'A valid API token is required.', 401);
    }
    $st = $pdo->prepare("SELECT member_id FROM mcp_resolve_token(:h, 'api')");
    $st->execute(['h' => hash('sha256', $token)]);
    $mid = $st->fetchColumn();
    $member = $mid === false ? null : find_member_by_id($pdo, (int) $mid);
    if ($member === null) {
        api_error('unauthorized', 'A valid API token is required.', 401);
    }
    $_SESSION['member_id'] = (int) $member['id'];
    $GLOBALS['__api_token_authed'] = true;
    header_remove('Set-Cookie');
    db_apply_context($pdo);
    return $member;
}

// ---- the availability feed's door (feed.md "The feed"): a feed key, never a session or a person's token ---------------------------------------------------------------

/** The one 401 of the feed: no bearer, a wrong one, a person's mcp_ token, a revoked, expired or rotated-out key, a minter no longer admitted — the same body (DECISION 1). */
function feed_unauthorized(): never
{
    feed_drop_session();
    api_error('unauthorized', 'A valid feed key is required.', 401);
}

/** The feed sends no cookie and keeps no session: the one PHP's bootstrap opened is abandoned (its in-memory $_SESSION stays readable for the request, nothing is saved). */
function feed_drop_session(): void
{
    header_remove('Set-Cookie');
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_abort();
    }
}

/**
 * The key row of inv_resolve_feed_key() (key_id, member_id, label, consumer_kind, price_list_id, rate_per_minute, rate_per_day), or the feed's 401. The minter becomes the acting member for this request
 * (inv_feed_answer() runs as the writer with the minter's identity), every log row's source is `feed`, and no cookie is ever sent — the session is abandoned, not saved.
 */
function feed_authenticate(): array
{
    $raw = api_bearer_token();
    if (preg_match('/^feed_[0-9a-f]{48}$/', $raw) !== 1) {
        feed_unauthorized();
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM inv_resolve_feed_key(:h)');
    $st->execute(['h' => hash('sha256', $raw)]);
    $key = $st->fetch();
    if ($key === false) {
        feed_unauthorized();
    }
    $_SESSION['member_id'] = (int) $key['member_id'];
    $GLOBALS['__public_door'] = 'feed';
    $GLOBALS['__feed_key'] = $key;
    db_apply_context($pdo);
    feed_drop_session();                                  // nothing of this call is kept in a session file, and no cookie is owed
    return $key;
}

/**
 * The rate (DECISION 5: before the query is read, so a flood of malformed calls still counts): inv_rate_ok() counts the call in the minute bucket, then the day's, and a refused call is never charged to the day.
 * Nothing when ok. A key revoked between calls → the feed's 401. Else 429 with Retry-After, logging `feed.rate_limited` ONCE per bucket (when the bucket's `refused` has just become 1).
 */
function feed_rate_limit(array $key): void
{
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM inv_rate_ok(:k)');
    $st->execute(['k' => (int) $key['key_id']]);
    $r = $st->fetch();
    if ($r === false || $r['ok']) {
        return;
    }
    $hit = (string) $r['limit_hit'];
    if (!in_array($hit, ['minute', 'day'], true)) {
        feed_unauthorized();                              // 'revoked' or 'no_key': a race with a revoke
    }
    $retry = max(1, (int) $r['retry_after']);
    $bucket = $pdo->prepare('SELECT refused FROM key_usage WHERE token_id = :k AND bucket_kind = :kind AND bucket_start = date_trunc(CAST(:unit AS text), now())');
    $bucket->execute(['k' => (int) $key['key_id'], 'kind' => $hit, 'unit' => $hit]);
    if ((int) $bucket->fetchColumn() === 1) {
        log_activity($pdo, 'feed.rate_limited', 'feed_key', (int) $key['key_id'], ['actor_member_id' => null, 'source' => 'feed', 'token_id' => (int) $key['key_id'],
            'after' => ['key_label' => (string) $key['label'], 'limit_hit' => $hit, 'retry_after' => $retry]]);
    }
    header('Retry-After: ' . $retry);
    json_error('rate_limited', 'Too many calls (' . $hit . ').', 429, ['limit' => $hit, 'retry_after' => $retry]);
}

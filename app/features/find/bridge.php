<?php
declare(strict_types=1);

/**
 * The bridge (find.md "The bridge"; the tool surface's DECISION 3): the records server's `source_search` tool reaches the live connectors here —
 * loopback + an HMAC of the body under ACTIONS_RELAY_KEY (`inv-bridge:` prefix) within ±30 s, the caller an admitted member holding orders.write,
 * at most five sources in sequence (25 s each, 60 s in all), at most 20 listings each. Under is_eval nothing persists: the connector is asked
 * directly and one `source.search` row per source says eval.
 */
require_once __DIR__ . '/queries.php';

const BRIDGE_SKEW = 30;
const BRIDGE_SOURCE_SECONDS = 25;
const BRIDGE_CALL_SECONDS = 60;
const BRIDGE_MAX_SOURCES = 5;

/** The refusal code (`bad_signature`, `not_loopback`) or null when the request may pass. */
function bridge_verify(string $rawBody, string $header, string $remoteAddr, int $now): ?string
{
    if (!in_array($remoteAddr, ['127.0.0.1', '::1'], true)) { return 'not_loopback'; }
    if (!preg_match('/^(\d{9,12})\.([a-f0-9]{64})$/', $header, $m)) { return 'bad_signature'; }
    if (abs($now - (int) $m[1]) > BRIDGE_SKEW) { return 'bad_signature'; }
    $key = (string) env('ACTIONS_RELAY_KEY', '');
    if ($key === '') { return 'bad_signature'; }
    $want = hash_hmac('sha256', 'inv-bridge:' . $m[1] . '.' . hash('sha256', $rawBody), $key);
    return hash_equals($want, $m[2]) ? null : 'bad_signature';
}

/** The admitted member the body names (a member, active, admitted in the mirror), or null. */
function bridge_caller(PDO $pdo, array $body): ?int
{
    $c = $body['caller'] ?? null;
    if (!is_array($c) || ($c['kind'] ?? '') !== 'member' || !is_int($c['member_id'] ?? null)) { return null; }
    $ok = one_value($pdo, "SELECT 1 FROM members WHERE id = :m AND status = 'active' AND capability IS NOT NULL", ['m' => $c['member_id']]);
    return $ok === null ? null : (int) $c['member_id'];
}

/**
 * The envelope of one `source_search` call: {ok, query, size, asked[], rows[], eval} or {ok: false, error: {code, message}, status}.
 * $args: q (2–120), size, sources (≤ 5 ids; one not searchable answers `skipped`), limit (1–20, default 20).
 */
function bridge_run_source_search(PDO $pdo, array $args, int $memberId, bool $isEval): array
{
    $q = is_string($args['q'] ?? null) ? trim($args['q']) : '';
    if (mb_strlen($q) < 2 || mb_strlen($q) > 120) { return bridge_refusal('invalid_argument', 'Ask for 2 to 120 characters.', 422); }
    $size = is_string($args['size'] ?? null) && trim($args['size']) !== '' ? trim($args['size']) : null;
    $limit = $args['limit'] ?? 20;
    if (!is_int($limit) || $limit < 1 || $limit > 20) { return bridge_refusal('invalid_argument', 'The limit is 1 to 20.', 422); }
    $searchable = [];
    foreach (searchable_sources($pdo) as $s) { $searchable[$s['source_id']] = $s; }
    if (isset($args['sources'])) {
        if (!is_array($args['sources']) || array_filter($args['sources'], static fn ($x) => !is_int($x)) !== []) { return bridge_refusal('invalid_argument', 'Sources are a list of ids.', 422); }
        if (count($args['sources']) > BRIDGE_MAX_SOURCES) { return bridge_refusal('invalid_argument', 'Ask at most ' . BRIDGE_MAX_SOURCES . ' sources at once.', 422); }
        $ids = array_values(array_unique($args['sources']));
    } else {
        $ids = array_slice(array_keys($searchable), 0, BRIDGE_MAX_SOURCES);
    }
    $asked = [];
    $rows = [];
    $started = microtime(true);
    foreach ($ids as $sid) {
        $name = $searchable[$sid]['name'] ?? (string) (one_value($pdo, 'SELECT name FROM mcp_sources WHERE source_id = :s', ['s' => $sid]) ?? '');
        $base = ['source_id' => $sid, 'source' => $name, 'pull_id' => null, 'listings_seen' => 0, 'listings_new' => 0, 'listings_changed' => 0, 'ms' => 0, 'error' => null];
        if (!isset($searchable[$sid])) { $asked[] = ['status' => 'skipped', 'error' => 'not a source that can be asked live'] + $base; continue; }
        if (microtime(true) - $started > BRIDGE_CALL_SECONDS - BRIDGE_SOURCE_SECONDS) { $asked[] = ['status' => 'timeout', 'error' => 'not reached in ' . BRIDGE_CALL_SECONDS . ' s'] + $base; continue; }
        set_time_limit(BRIDGE_SOURCE_SECONDS + 10);
        if ($isEval) {
            $t0 = microtime(true);
            $r = inv_source_search_live($pdo, $sid, $q, $limit, $memberId, false, $size);      // the connector asked directly: no pull row, no listing, no snapshot
            $found = $r['ok'] ? $r['listings'] : [];
            foreach ($found as $l) {
                foreach ($l['variants'] ?? [] as $v) {
                    $rows[] = ['source_id' => $sid, 'title' => $v['title'] ?? $l['title'], 'size_name' => inv_size_label($v['size_key'] ?? null), 'sku' => $v['sku'] ?? null, 'barcode' => $v['barcode'] ?? null,
                               'price' => $v['price'] ?? null, 'availability' => $v['availability'] ?? null, 'lead_time_days' => $v['lead_time_days'] ?? null, 'matched' => false];
                }
            }
            $asked[] = ['status' => $r['ok'] ? 'ok' : bridge_status((string) ($r['reason'] ?? 'failed')), 'listings_seen' => count($found), 'ms' => (int) round((microtime(true) - $t0) * 1000),
                        'error' => $r['ok'] ? null : mb_substr((string) ($r['reason'] ?? ''), 0, 200)] + $base;
            continue;
        }
        $res = live_search_source($pdo, $sid, $q, $limit, $memberId, $size);
        $status = $res['status'] === 'ok' ? 'ok' : bridge_status($res['status']);
        $asked[] = ['status' => $status, 'pull_id' => $res['pull_id'], 'listings_seen' => $res['listings_seen'], 'listings_new' => $res['listings_new'], 'listings_changed' => $res['listings_changed'],
                    'ms' => $res['ms'], 'error' => $status === 'ok' ? null : mb_substr((string) ($res['reason'] ?? $res['status']), 0, 200)] + $base;
        if ($status !== 'ok') { continue; }
        foreach ($res['rows'] as $r) {
            $rows[] = ['listing_variant_id' => (int) $r['listing_variant_id'], 'listing_id' => (int) $r['listing_id'], 'source_id' => (int) $r['source_id'], 'source' => $r['source_name'], 'source_role' => $r['source_role'],
                       'title' => $r['title'] ?? $r['listing_title'], 'listing_title' => $r['listing_title'], 'size_name' => $r['size_name'], 'sku' => $r['sku'], 'barcode' => $r['barcode'], 'mpn' => $r['mpn'],
                       'price' => $r['price'], 'compare_at_price' => $r['compare_at_price'], 'cost_price' => $r['cost_price'], 'cost_withheld' => (bool) $r['cost_withheld'], 'availability' => $r['availability'],
                       'qty' => $r['qty'] === null ? null : (int) $r['qty'], 'lead_time_days' => $r['lead_time_days'] === null ? null : (int) $r['lead_time_days'], 'url' => $r['url'],
                       'matched' => $r['variant_id'] !== null, 'variant_id' => $r['variant_id'] === null ? null : (int) $r['variant_id'], 'our_sku' => $r['our_sku'], 'product_name' => $r['our_product']];
        }
    }
    return ['ok' => true, 'query' => $q, 'size' => $size, 'asked' => $asked, 'rows' => $rows, 'eval' => $isEval];
}

/** The live function's reason as the bridge's status word: no_search | blocked | failed (paused, backing off and a running pull are failures to ask). */
function bridge_status(string $reason): string
{
    return match (true) {
        $reason === 'no live search' => 'no_search',
        str_contains($reason, 'blocked') || $reason === 'backing off' => 'blocked',
        default => 'failed',
    };
}

function bridge_refusal(string $code, string $message, int $status): array
{
    return ['ok' => false, 'error' => ['code' => $code, 'message' => $message], 'status' => $status];
}

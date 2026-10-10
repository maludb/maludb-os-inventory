<?php
declare(strict_types=1);

/** The feed keys' and price lists' JSON shapes (a whitelist over the view rows — never a hash, never a raw key) and chips (feed.md "Status vocabulary"). */

/** A key's state: live · expiring (rotated, inside the overlap) · revoked · expired · minter gone ('orphan': its minter is no longer admitted, so the door answers 401 — DECISION 4). */
function feed_key_state(array $k): string
{
    if ($k['revoked_at'] !== null) { return 'revoked'; }
    if (!$k['is_live']) { return 'expired'; }
    if (!$k['minter_here']) { return 'orphan'; }
    if ($k['successor_id'] !== null && $k['expires_at'] !== null) { return 'expiring'; }
    return 'live';
}

function feed_chip(string $label, string $colour, string $id = '', string $title = ''): string
{
    return '<span class="badge bg-soft-' . $colour . ' text-' . ($colour === 'dark' ? 'dark' : $colour) . '"' . ($id !== '' ? ' id="' . e($id) . '"' : '') . ($title !== '' ? ' title="' . e($title) . '"' : '') . '>' . e($label) . '</span>';
}

/** The status chip; $tz renders the time of an expiring key. */
function feed_key_status_chip(array $k, string $tz, string $id = ''): string
{
    $s = feed_key_state($k);
    return match ($s) {
        'revoked' => feed_chip('revoked', 'dark', $id),
        'expired' => feed_chip('expired', 'secondary', $id),
        'orphan' => feed_chip('minter no longer here', 'danger', $id, 'The person who minted this key is no longer admitted — the door answers 401. Revoke it and mint a new one.'),
        'expiring' => feed_chip('expiring at ' . format_ts($k['expires_at'], $tz, 'M j, g:i A'), 'warning', $id),
        default => feed_chip('live', 'success', $id),
    };
}

function feed_consumer_chip(string $kind): string
{
    $c = ['website' => 'info', 'installation' => 'secondary', 'partner' => 'success'][$kind] ?? 'secondary';
    return feed_chip(strtolower(FEED_CONSUMER_KINDS[$kind] ?? $kind), $c);
}

/** Today's count: danger at 90 % of the day's limit. */
function feed_calls_today_html(array $k): string
{
    $limit = max(1, (int) $k['rate_per_day']);
    $hot = (int) $k['calls_today'] >= 0.9 * $limit;
    return '<span class="' . ($hot ? 'text-danger fw-semibold' : '') . '">' . number_format((int) $k['calls_today']) . '</span><span class="text-muted"> / ' . number_format($limit) . '</span>';
}

function present_feed_key(array $k): array
{
    return ['key_id' => (int) $k['key_id'], 'label' => $k['label'], 'consumer_kind' => $k['consumer_kind'], 'price_list_id' => $k['price_list_id'], 'price_list' => $k['price_list_name'] ?? null,
            'rate_per_minute' => (int) $k['rate_per_minute'], 'rate_per_day' => (int) $k['rate_per_day'], 'calls_today' => (int) $k['calls_today'], 'status' => feed_key_state($k),
            'last_used_at' => json_ts($k['last_used_at']), 'expires_at' => json_ts($k['expires_at']), 'revoked_at' => json_ts($k['revoked_at']), 'rotated_from' => $k['rotated_from'], 'minter' => $k['minter_name'],
            'created_at' => json_ts($k['created_at'])];
}

function present_price_list(array $p): array
{
    return ['price_list_id' => (int) $p['price_list_id'], 'name' => $p['name'], 'percent_off_retail' => (float) $p['percent_off_retail'], 'active' => (bool) $p['active'], 'notes' => $p['notes'],
            'keys_using' => (int) $p['keys_using'], 'created_at' => json_ts($p['created_at']), 'updated_at' => json_ts($p['updated_at']), 'label' => $p['name']];
}

function present_usage(array $u): array
{
    return ['bucket' => $u['bucket'], 'rows' => array_map(static fn (array $r): array => ['bucket_start' => json_ts($r['bucket_start']), 'calls' => $r['calls'], 'refused' => $r['refused']], $u['rows']), 'totals' => $u['totals']];
}

const FEED_NOTICES = ['minted' => ['success', 'The key is made.'], 'rotated' => ['success', 'The key is rotated.'], 'revoked' => ['success', 'The key is revoked.']];
const PRICE_LIST_NOTICES = ['created' => ['success', 'The price list is made.'], 'saved' => ['success', 'Saved.']];

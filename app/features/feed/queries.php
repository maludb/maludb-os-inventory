<?php
declare(strict_types=1);

/**
 * The availability feed's admin side (feed.md "Query functions"): the feed keys (mcp_feed_keys — never the hash; calls_today from inv_feed_key_calls_today()), their usage (mcp_key_usage), the partner price lists
 * (mcp_price_lists), and the feed's own door — the query it reads and the document it answers (inv_feed_answer(), the writer's alone). All reads are the views' (feed.keys); the writes are the table's INSERT and
 * db/013's rotate / revoke functions. A key's raw value exists only in mint_feed_key() / rotate_feed_key()'s return — shown once, never stored (the hash is), never logged.
 */

const FEED_CONSUMER_KINDS = ['website' => 'Website', 'installation' => 'Another installation', 'partner' => 'Partner store'];
const FEED_QUERY_MAX = 120;
const FEED_SIZE_MAX = 40;
const FEED_USAGE_DAYS = 35;

/** The business's time zone (inv_settings.timezone) — a key's expiry is that day's end there. */
function feed_business_tz(PDO $pdo): string
{
    return (string) (one_value($pdo, 'SELECT timezone FROM inv_settings WHERE id = 1') ?: 'UTC');
}

function feed_key_decode(array $k): array
{
    foreach (['key_id', 'member_id', 'rate_per_minute', 'rate_per_day', 'calls_today', 'price_list_id', 'rotated_from', 'revoked_by', 'successor_id'] as $c) {
        if (array_key_exists($c, $k)) { $k[$c] = $k[$c] === null ? null : (int) $k[$c]; }
    }
    $k['is_live'] = (bool) $k['is_live'];
    $k['minter_here'] = !array_key_exists('minter_name', $k) || $k['minter_name'] !== null;
    return $k;
}

const FEED_KEY_COLUMNS = "k.key_id, k.member_id, k.label, k.consumer_kind, k.price_list_id, k.rate_per_minute, k.rate_per_day, k.rotated_from, k.last_used_at, k.expires_at, k.revoked_at, k.revoked_by, k.created_at, k.is_live, k.calls_today,
    pl.name AS price_list_name, pl.active AS price_list_active, mm.display_name AS minter_name,
    (SELECT s.key_id FROM mcp_feed_keys s WHERE s.rotated_from = k.key_id ORDER BY s.key_id DESC LIMIT 1) AS successor_id,
    (SELECT o.label FROM mcp_feed_keys o WHERE o.key_id = k.rotated_from) AS rotated_from_label";
const FEED_KEY_FROM = 'FROM mcp_feed_keys k LEFT JOIN mcp_price_lists pl ON pl.price_list_id = k.price_list_id LEFT JOIN mcp_members mm ON mm.member_id = k.member_id';

/** Every key, newest first. $f: live (bool, only the live ones), consumer_kind, q (on the label). */
function find_feed_keys(PDO $pdo, array $f = []): array
{
    $where = '';
    $args = [];
    if (!empty($f['live'])) { $where .= ' AND k.is_live'; }
    if (!empty($f['consumer_kind']) && isset(FEED_CONSUMER_KINDS[(string) $f['consumer_kind']])) { $where .= ' AND k.consumer_kind = :kind'; $args['kind'] = (string) $f['consumer_kind']; }
    if (trim((string) ($f['q'] ?? '')) !== '') { $where .= ' AND k.label ILIKE :q'; $args['q'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim((string) $f['q'])) . '%'; }
    $st = $pdo->prepare('SELECT ' . FEED_KEY_COLUMNS . ' ' . FEED_KEY_FROM . ' WHERE true' . $where . ' ORDER BY k.created_at DESC, k.key_id DESC');
    $st->execute($args);
    return array_map('feed_key_decode', $st->fetchAll());
}

/** One key, or null — also when the caller lacks feed.keys (the view is empty for them). */
function find_feed_key(PDO $pdo, int $keyId): ?array
{
    $st = $pdo->prepare('SELECT ' . FEED_KEY_COLUMNS . ' ' . FEED_KEY_FROM . ' WHERE k.key_id = :id');
    $st->execute(['id' => $keyId]);
    $r = $st->fetch();
    return $r === false ? null : feed_key_decode($r);
}

/** A fresh raw key: `feed_` + 48 hex. Shown once; only its sha256 is stored. */
function feed_new_raw_key(): string
{
    return 'feed_' . bin2hex(random_bytes(24));
}

/**
 * Mint: INSERT on feed_keys with the hash. $fields: label, consumer_kind, price_list_id (null), rate_per_minute / rate_per_day (null → the trigger fills the settings' defaults), expires_on (a 'Y-m-d' or null —
 * stored as that day's end in the business's time zone). Returns ['key_id', 'raw'].
 */
function mint_feed_key(PDO $pdo, array $fields, int $by): array
{
    $raw = feed_new_raw_key();
    $st = $pdo->prepare('INSERT INTO feed_keys (member_id, label, token_hash, consumer_kind, price_list_id, rate_per_minute, rate_per_day, expires_at)
                         VALUES (:m, :label, :hash, :kind, :pl, :rpm, :rpd,
                                 CASE WHEN CAST(:exp AS text) IS NULL THEN NULL ELSE ((CAST(:exp AS date) + 1)::timestamp AT TIME ZONE CAST(:tz AS text)) - interval \'1 second\' END) RETURNING id');
    $st->execute(['m' => $by, 'label' => $fields['label'], 'hash' => hash('sha256', $raw), 'kind' => $fields['consumer_kind'], 'pl' => $fields['price_list_id'] ?? null,
                  'rpm' => $fields['rate_per_minute'] ?? null, 'rpd' => $fields['rate_per_day'] ?? null, 'exp' => $fields['expires_on'] ?? null, 'tz' => feed_business_tz($pdo)]);
    return ['key_id' => (int) $st->fetchColumn(), 'raw' => $raw];
}

/** Rotate: a new key under the same minter, label, consumer, price list and limits (db/013); the old one lives the overlap and no longer. Returns ['key_id', 'raw', 'old_key_id', 'old_expires_at']. */
function rotate_feed_key(PDO $pdo, int $keyId, int $by): array
{
    $raw = feed_new_raw_key();
    $st = $pdo->prepare('SELECT inv_feed_key_rotate(:old, :hash)');
    $st->execute(['old' => $keyId, 'hash' => hash('sha256', $raw)]);
    $new = (int) $st->fetchColumn();
    $oldExpires = one_value($pdo, 'SELECT expires_at FROM feed_keys WHERE id = :id', ['id' => $keyId]);
    return ['key_id' => $new, 'raw' => $raw, 'old_key_id' => $keyId, 'old_expires_at' => $oldExpires];
}

function revoke_feed_key(PDO $pdo, int $keyId, int $by): void
{
    $pdo->prepare('SELECT inv_feed_key_revoke(:k, :by)')->execute(['k' => $keyId, 'by' => $by]);
}

/**
 * A key's usage (mcp_key_usage): 'day' → the day buckets from $from (default the last 35 days), 'minute' → the last 60 minutes. ['rows' => [{bucket_start, calls, refused}], 'totals' => {calls, refused}], newest first.
 */
function key_usage(PDO $pdo, int $keyId, string $bucket = 'day', ?string $from = null, ?string $to = null): array
{
    $bucket = $bucket === 'minute' ? 'minute' : 'day';
    $lo = $from ?? ($bucket === 'minute' ? date('c', time() - 3600) : date('c', time() - FEED_USAGE_DAYS * 86400));
    $st = $pdo->prepare('SELECT bucket_start, calls, refused FROM mcp_key_usage WHERE key_id = :k AND bucket_kind = :b AND bucket_start >= :lo' . ($to !== null ? ' AND bucket_start <= :hi' : '') . ' ORDER BY bucket_start DESC LIMIT 2100');
    $st->execute(['k' => $keyId, 'b' => $bucket, 'lo' => $lo] + ($to !== null ? ['hi' => $to] : []));
    $rows = $st->fetchAll();
    $tot = ['calls' => 0, 'refused' => 0];
    foreach ($rows as &$r) {
        $r['calls'] = (int) $r['calls'];
        $r['refused'] = (int) $r['refused'];
        $tot['calls'] += $r['calls'];
        $tot['refused'] += $r['refused'];
    }
    unset($r);
    return ['bucket' => $bucket, 'rows' => $rows, 'totals' => $tot];
}

/** Every live key's calls today and its day's limit — slice 9's Home. [{key_id, label, calls_today, rate_per_day}] */
function key_usage_summary(PDO $pdo): array
{
    $st = $pdo->query('SELECT key_id, label, calls_today, rate_per_day FROM mcp_feed_keys WHERE is_live ORDER BY calls_today DESC, key_id');
    return array_map(static fn (array $r): array => ['key_id' => (int) $r['key_id'], 'label' => $r['label'], 'calls_today' => (int) $r['calls_today'], 'rate_per_day' => (int) $r['rate_per_day']], $st->fetchAll());
}

// ---- price lists --------------------------------------------------------------------------------------------------------------------------------------------------------

/** The price lists with the keys using each (live ones counted). */
function find_price_lists(PDO $pdo, bool $activeOnly = false): array
{
    $st = $pdo->query('SELECT p.price_list_id, p.name, p.percent_off_retail, p.notes, p.active, p.created_at, p.updated_at,
                              (SELECT count(*) FROM mcp_feed_keys k WHERE k.price_list_id = p.price_list_id) AS keys_using,
                              (SELECT count(*) FROM mcp_feed_keys k WHERE k.price_list_id = p.price_list_id AND k.is_live) AS live_keys_using
                         FROM mcp_price_lists p' . ($activeOnly ? ' WHERE p.active' : '') . ' ORDER BY lower(p.name), p.price_list_id');
    return array_map('price_list_decode', $st->fetchAll());
}

function find_price_list(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT p.price_list_id, p.name, p.percent_off_retail, p.notes, p.active, p.created_at, p.updated_at,
                                (SELECT count(*) FROM mcp_feed_keys k WHERE k.price_list_id = p.price_list_id) AS keys_using,
                                (SELECT count(*) FROM mcp_feed_keys k WHERE k.price_list_id = p.price_list_id AND k.is_live) AS live_keys_using
                           FROM mcp_price_lists p WHERE p.price_list_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : price_list_decode($r);
}

function price_list_decode(array $p): array
{
    $p['price_list_id'] = (int) $p['price_list_id'];
    $p['active'] = (bool) $p['active'];
    $p['percent_off_retail'] = number_format((float) $p['percent_off_retail'], 2, '.', '');
    $p['keys_using'] = (int) ($p['keys_using'] ?? 0);
    $p['live_keys_using'] = (int) ($p['live_keys_using'] ?? 0);
    return $p;
}

/** INSERT (id null) or UPDATE a price list; returns its id. $fields: name, percent_off_retail, notes, active. A duplicate name (23505) is inv_guard()'s sentence. */
function save_price_list(PDO $pdo, ?int $id, array $fields, int $by): int
{
    $args = ['name' => $fields['name'], 'pct' => $fields['percent_off_retail'], 'notes' => $fields['notes'] ?? null, 'active' => !empty($fields['active']) ? 'true' : 'false'];
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO price_lists (name, percent_off_retail, notes, active) VALUES (:name, :pct, :notes, CAST(:active AS boolean)) RETURNING id');
        $st->execute($args);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE price_lists SET name = :name, percent_off_retail = :pct, notes = :notes, active = CAST(:active AS boolean) WHERE id = :id')->execute($args + ['id' => $id]);
    return $id;
}

// ---- the feed's door -----------------------------------------------------------------------------------------------------------------------------------------------

/**
 * The query of GET /api/v1/availability: exactly one of gtin, sku, q (+ an optional size with q). ['kind' => gtin|sku|q, 'query' => the inv_feed_answer() object, 'excerpt' => ≤ 120 characters], or a 422
 * `invalid` with the fields that are wrong. A gtin is normalized by the function (inv_gtin14).
 */
function feed_query_from_request(): array
{
    $g = static fn (string $k): string => is_string($_GET[$k] ?? null) ? trim((string) $_GET[$k]) : '';
    $vals = ['gtin' => $g('gtin'), 'sku' => $g('sku'), 'q' => $g('q')];
    $given = array_keys(array_filter($vals, static fn (string $v): bool => $v !== ''));
    $fail = static function (string $message, array $fields): never {
        json_error('invalid', $message, 422, ['fields' => (object) $fields]);
    };
    if (count($given) !== 1) {
        $fields = $given === [] ? ['query' => 'Say one of gtin, sku or q.'] : array_fill_keys($given, 'Say only one of gtin, sku or q.');
        $fail('Say gtin, sku or q (up to ' . FEED_QUERY_MAX . ' characters).', $fields);
    }
    $kind = $given[0];
    if (mb_strlen($vals[$kind]) > FEED_QUERY_MAX) {
        $fail('Say gtin, sku or q (up to ' . FEED_QUERY_MAX . ' characters).', [$kind => 'Up to ' . FEED_QUERY_MAX . ' characters.']);
    }
    $query = [$kind => $vals[$kind]];
    if ($kind === 'q') {
        $size = $g('size');
        if (mb_strlen($size) > FEED_SIZE_MAX) {
            $fail('The size is up to ' . FEED_SIZE_MAX . ' characters.', ['size' => 'Up to ' . FEED_SIZE_MAX . ' characters.']);
        }
        if ($size !== '') { $query['size'] = $size; }
    }
    return ['kind' => $kind, 'query' => $query, 'excerpt' => mb_substr($vals[$kind], 0, FEED_QUERY_MAX)];
}

/**
 * The document os.inventory-feed/1: inv_feed_answer(key, query) wrapped. The function's object is the document's body; the one thing done here is the partner price's absence from a key that is not a partner's
 * (the function builds the member with a null — the document says "only for a partner key", so the key is left out, not null). Never cost, never a source's name — the function's.
 */
function feed_answer(PDO $pdo, int $keyId, array $query): array
{
    $st = $pdo->prepare('SELECT inv_feed_answer(:k, CAST(:q AS jsonb))');
    $st->execute(['k' => $keyId, 'q' => json_encode($query, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
    $body = json_decode((string) $st->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
    $kind = (string) one_value($pdo, 'SELECT consumer_kind FROM feed_keys WHERE id = :id', ['id' => $keyId]);
    $order = ['sku', 'gtin', 'name', 'size', 'retail_price', 'currency', 'partner_price', 'availability', 'quantity', 'lead_time_days', 'ships_how'];     // jsonb sorts its keys by length; the document reads in this order
    foreach ($body['results'] as $i => $row) {
        if ($kind !== 'partner') { unset($row['partner_price']); }
        $body['results'][$i] = array_replace(array_intersect_key(array_flip($order), $row), $row);
    }
    return ['schema' => 'os.inventory-feed/1', 'generated_at' => gmdate('Y-m-d\TH:i:s\Z'), 'application' => 'inventory', 'currency' => trim((string) one_value($pdo, 'SELECT inv_currency()'))] + $body;
}

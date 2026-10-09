<?php
declare(strict_types=1);

/**
 * Sources' reads (sources.md "Query functions" — the tools A3, A4, A6, A7, A9, A13 of Phase 4 call these): the sources with their health,
 * templates, pulls and what a pull changed, the connectors' definitions from the PHP registry. Every read is a mcp_* view or a read
 * function (settings and the user-agent are null below sources.write; a credential is a label and four characters, never more).
 */
require_once dirname(__DIR__, 2) . '/sources/registry.php';

const SOURCE_HEALTHS = ['ok' => 'ok', 'stale' => 'stale', 'failing' => 'failing', 'blocked' => 'blocked', 'paused' => 'paused', 'manual' => 'manual', 'never_pulled' => 'never pulled', 'inactive' => 'inactive'];
const SOURCE_ROLES = ['supplier' => 'Supplier', 'reference' => 'Reference'];
const PULL_KINDS = ['scheduled' => 'scheduled', 'manual' => 'manual', 'search' => 'search', 'probe' => 'probe'];
const PULL_STATUSES = ['running' => 'running', 'ok' => 'ok', 'partial' => 'partial', 'failed' => 'failed', 'blocked' => 'blocked'];

/** The connectors of the PHP registry: key → {key, label, description, capabilities}. */
function connector_defs(): array
{
    return array_map(static fn (array $d): array => ['key' => $d['key'], 'label' => $d['label'], 'description' => $d['description'], 'capabilities' => $d['capabilities']], inv_connectors());
}

/** The settings keys a connector reads (sources.md "The source form"). */
function connector_settings_keys(string $connector): array
{
    return match ($connector) {
        'shopify' => ['collections', 'currency', 'api_version', 'max_products'],
        'woocommerce' => ['currency', 'vendor', 'max_products'],
        'jsonld' => ['sitemap_url', 'urls', 'url_pattern', 'max_pages', 'currency'],
        'feed' => ['url', 'sftp', 'format', 'delimiter', 'has_header', 'skip_rows', 'encoding', 'mapping', 'currency', 'vendor', 'lead_time_days'],
        'manual' => ['listings'],
        default => [],
    };
}

const SOURCE_COLUMNS = 's.source_id, s.name, s.connector, s.role, s.supplier_id, s.supplier_name, s.base_url, s.settings, s.credential_id, s.schedule_minutes, s.rate_per_second, s.user_agent,
    s.robots_state, s.robots_checked_at, s.last_pull_id, s.last_ok_at, s.consecutive_failures, s.backoff_until, s.paused_at, s.paused_reason, s.active, s.created_by, s.created_at, s.updated_at,
    h.health, h.last_pull_at, h.last_status, h.last_error, h.next_due_at, h.stale, h.listings_live, h.variants_live, h.variants_unmatched,
    (SELECT p.policy->>\'queued\' = \'true\' FROM mcp_source_pulls p WHERE p.pull_id = s.last_pull_id AND p.status = \'running\') AS last_queued';

function source_row_decode(array $r): array
{
    foreach (['source_id', 'schedule_minutes', 'consecutive_failures'] as $k) { $r[$k] = (int) $r[$k]; }
    foreach (['supplier_id', 'credential_id', 'last_pull_id', 'created_by'] as $k) { $r[$k] = $r[$k] === null ? null : (int) $r[$k]; }
    foreach (['listings_live', 'variants_live', 'variants_unmatched'] as $k) { $r[$k] = (int) ($r[$k] ?? 0); }
    $r['active'] = (bool) $r['active'];
    $r['settings'] = $r['settings'] === null ? null : (json_decode((string) $r['settings'], true) ?: []);
    $r['rate_per_second'] = (float) $r['rate_per_second'];
    $r['last_queued'] = (bool) ($r['last_queued'] ?? false);
    $defs = inv_connectors();
    $r['connector_label'] = $defs[$r['connector']]['label'] ?? $r['connector'];
    $r['label'] = $r['name'] . ' (' . $r['connector_label'] . ')';
    return $r;
}

/** The sources (tool `find_sources`): $filters q, connector, role, supplier, health, include_inactive. */
function find_sources(PDO $pdo, array $filters = [], int $limit = 100, int $offset = 0): array
{
    $w = [];
    $args = [];
    if (empty($filters['include_inactive'])) { $w[] = 's.active'; }
    if (!empty($filters['connector'])) { $w[] = 's.connector = :connector'; $args['connector'] = (string) $filters['connector']; }
    if (!empty($filters['role']) && isset(SOURCE_ROLES[$filters['role']])) { $w[] = 's.role = :role'; $args['role'] = $filters['role']; }
    if (!empty($filters['supplier'])) { $w[] = 's.supplier_id = :supplier'; $args['supplier'] = (int) $filters['supplier']; }
    if (!empty($filters['health']) && isset(SOURCE_HEALTHS[$filters['health']])) { $w[] = 'h.health = :health'; $args['health'] = $filters['health']; }
    if (trim((string) ($filters['q'] ?? '')) !== '') {
        $q = trim((string) $filters['q']);
        if (ctype_digit($q)) { $w[] = 's.source_id = :qid'; $args['qid'] = (int) $q; }
        else { $w[] = '(s.name ILIKE :q OR s.base_url ILIKE :q2 OR s.supplier_name ILIKE :q3)'; $args['q'] = $args['q2'] = $args['q3'] = '%' . $q . '%'; }
    }
    $st = $pdo->prepare('SELECT ' . SOURCE_COLUMNS . ' FROM mcp_sources s LEFT JOIN inv_source_health() h ON h.source_id = s.source_id'
        . ($w === [] ? '' : ' WHERE ' . implode(' AND ', $w)) . ' ORDER BY s.active DESC, s.name LIMIT ' . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset));
    $st->execute($args);
    return array_map('source_row_decode', $st->fetchAll());
}

function find_source(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT ' . SOURCE_COLUMNS . ' FROM mcp_sources s LEFT JOIN inv_source_health() h ON h.source_id = s.source_id WHERE s.source_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : source_row_decode($r);
}

/** One source in full (tool `get_source`): {source, credential, health, pulls (5), counts}. */
function source_full(PDO $pdo, int $id): ?array
{
    $s = find_source($pdo, $id);
    if ($s === null) { return null; }
    $c = $pdo->prepare('SELECT credential_id, kind, label, last4, rotated_at, created_by, created_at FROM mcp_source_credentials WHERE source_id = :s AND credential_id = :c');
    $c->execute(['s' => $id, 'c' => $s['credential_id'] ?? 0]);
    $cred = $c->fetch() ?: null;
    $counts = $pdo->prepare('SELECT count(*) FILTER (WHERE removed_at IS NULL AND forgotten_at IS NULL) AS live, count(*) FILTER (WHERE removed_at IS NOT NULL) AS removed,
                                    count(*) FILTER (WHERE forgotten_at IS NOT NULL) AS forgotten,
                                    COALESCE(sum(matched_count) FILTER (WHERE removed_at IS NULL), 0) AS matched, COALESCE(sum(variant_count - matched_count) FILTER (WHERE removed_at IS NULL AND forgotten_at IS NULL), 0) AS unmatched
                               FROM mcp_listings WHERE source_id = :s');
    $counts->execute(['s' => $id]);
    return ['source' => $s, 'credential' => $cred, 'health' => ['health' => $s['health'], 'sentence' => health_sentence($s)], 'pulls' => source_pulls($pdo, ['source' => $id], 5),
            'counts' => array_map('intval', $counts->fetch())];
}

/** The templates (`find_sources(templates: true)`). */
function source_templates(PDO $pdo, bool $activeOnly = true): array
{
    return $pdo->query('SELECT template_id, key, name, connector, role, base_url, settings, brand_hint, notes, survey_result, surveyed_at, sort_order, active FROM mcp_source_templates'
        . ($activeOnly ? ' WHERE active' : '') . ' ORDER BY sort_order, name')->fetchAll();
}

function find_template(PDO $pdo, string $key): ?array
{
    $st = $pdo->prepare('SELECT template_id, key, name, connector, role, base_url, settings, brand_hint, notes, survey_result, surveyed_at FROM mcp_source_templates WHERE key = :k AND active');
    $st->execute(['k' => $key]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** A template's seeded settings as the connector reads them (connectors.md §8): `sitemap` → `sitemap_url`, `mapping.map` → `mapping.map_price`, inert keys dropped. */
function adopt_template_settings(array $template): array
{
    $settings = json_decode((string) $template['settings'], true) ?: [];
    if (array_key_exists('sitemap', $settings)) { $settings['sitemap_url'] = $settings['sitemap']; unset($settings['sitemap']); }
    if (isset($settings['mapping']) && is_array($settings['mapping'])) {
        if (array_key_exists('map', $settings['mapping'])) { $settings['mapping']['map_price'] = $settings['mapping']['map']; unset($settings['mapping']['map']); }
        $settings['mapping'] = array_filter($settings['mapping'], static fn ($v): bool => $v !== null && $v !== '');
    }
    $settings = array_intersect_key($settings, array_flip(connector_settings_keys((string) $template['connector'])));
    return array_filter($settings, static fn ($v): bool => $v !== null && $v !== [] && $v !== '');
}

/**
 * The fields a create receives from a template (connectors.md §8): name, connector, role, base_url; the brand from brand_hint found by name or
 * made; the settings of adopt_template_settings(); every field the caller gives wins. Returns ['fields', 'settings', 'brand_id'].
 */
function adopt_template(PDO $pdo, array $template, array $given, int $by): array
{
    $brandId = null;
    if (trim((string) ($template['brand_hint'] ?? '')) !== '') {
        $brandId = one_value($pdo, 'SELECT id FROM brands WHERE lower(name) = lower(:n)', ['n' => $template['brand_hint']]);
        if ($brandId === null) {
            $st = $pdo->prepare('INSERT INTO brands (name) VALUES (:n) RETURNING id');
            $st->execute(['n' => $template['brand_hint']]);
            $brandId = (int) $st->fetchColumn();
            log_activity($pdo, 'brand.save', 'brand', $brandId, ['actor_member_id' => $by, 'after' => ['name' => $template['brand_hint'], 'from_template' => $template['key']]]);
        }
        $brandId = (int) $brandId;
    }
    $fields = ['name' => $template['name'], 'connector' => $template['connector'], 'role' => $template['role'], 'base_url' => $template['base_url']];
    return ['fields' => array_merge($fields, array_filter($given, static fn ($v) => $v !== null)), 'settings' => array_merge(adopt_template_settings($template), $given['settings'] ?? []), 'brand_id' => $brandId];
}

/** inv_source_health() filtered (tool `source_health`). */
function source_health(PDO $pdo, ?array $health = null, ?string $role = null): array
{
    $rows = $pdo->query('SELECT * FROM inv_source_health()')->fetchAll();
    return array_values(array_filter($rows, static fn (array $r): bool => ($health === null || in_array($r['health'], $health, true)) && ($role === null || $r['role'] === $role)));
}

/** Per health, how many sources (the list's chips). */
function health_counts(PDO $pdo): array
{
    $out = array_fill_keys(array_keys(SOURCE_HEALTHS), 0);
    foreach ($pdo->query('SELECT health, count(*) AS n FROM inv_source_health() GROUP BY health')->fetchAll() as $r) { $out[$r['health']] = (int) $r['n']; }
    return $out;
}

/** "12 min ago", "3 days ago". */
function ago(?string $ts): string
{
    if ($ts === null) { return 'never'; }
    $d = max(0, time() - (int) strtotime($ts));
    return match (true) { $d < 90 => 'just now', $d < 5400 => round($d / 60) . ' min ago', $d < 129600 => round($d / 3600) . ' h ago', default => round($d / 86400) . ' days ago' };
}

/** The health in one sentence (sources.md "source-view"). */
function health_sentence(array $s): string
{
    $failures = (int) ($s['consecutive_failures'] ?? 0);
    return match ($s['health'] ?? 'never_pulled') {
        'ok' => 'ok · pulled ' . ago($s['last_pull_at'] ?? $s['last_ok_at']),
        'stale' => 'stale — last ok ' . ago($s['last_ok_at']),
        'failing' => 'failing · ' . $failures . ' in a row' . ($s['backoff_until'] ? ' · backing off until ' . date('M j H:i', (int) strtotime((string) $s['backoff_until'])) . ' UTC' : ''),
        'paused' => 'paused: ' . ($s['paused_reason'] ?? 'by a person'),
        'blocked' => 'blocked: ' . ($s['last_error'] ? $s['last_error'] : 'the site refuses crawlers'),
        'manual' => 'manual — pull it yourself',
        'inactive' => 'inactive',
        default => 'never pulled',
    };
}

// ---- pulls -------------------------------------------------------------------------------------------------------------
/** The pulls (tool `source_pulls`): $filters source, status (one or a list), kind, since (days). Newest first. */
function source_pulls(PDO $pdo, array $filters, int $limit = 50, int $offset = 0): array
{
    $w = [];
    $args = [];
    if (!empty($filters['source'])) { $w[] = 'p.source_id = :source'; $args['source'] = (int) $filters['source']; }
    $st = $filters['status'] ?? null;
    $st = array_values(array_filter(is_array($st) ? $st : ($st === null || $st === '' ? [] : [$st]), static fn ($x): bool => isset(PULL_STATUSES[$x])));
    if ($st !== []) { $w[] = 'p.status = ANY (CAST(:st AS text[]))'; $args['st'] = pg_array_literal($st); }
    if (!empty($filters['kind']) && isset(PULL_KINDS[$filters['kind']])) { $w[] = 'p.kind = :kind'; $args['kind'] = $filters['kind']; }
    if (!empty($filters['since'])) { $w[] = 'p.started_at >= now() - make_interval(days => :since)'; $args['since'] = (int) $filters['since']; }
    $q = $pdo->prepare('SELECT p.*, (SELECT display_name FROM mcp_members m WHERE m.member_id = p.started_by) AS started_by_name FROM mcp_source_pulls p'
        . ($w === [] ? '' : ' WHERE ' . implode(' AND ', $w)) . ' ORDER BY p.started_at DESC, p.pull_id DESC LIMIT ' . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset));
    $q->execute($args);
    return array_map('pull_row_decode', $q->fetchAll());
}

function pull_row_decode(array $p): array
{
    foreach (['pull_id', 'source_id', 'listings_seen', 'listings_new', 'listings_changed', 'variants_changed', 'listings_removed', 'http_requests', 'bytes'] as $k) { $p[$k] = (int) $p[$k]; }
    $p['policy'] = json_decode((string) $p['policy'], true) ?: [];
    $p['queued'] = $p['status'] === 'running' && !empty($p['policy']['queued']);
    $p['stale'] = $p['status'] === 'running' && strtotime((string) $p['started_at']) < time() - 6 * 3600;
    $p['seconds'] = $p['finished_at'] !== null ? max(0, (int) strtotime((string) $p['finished_at']) - (int) strtotime((string) $p['started_at'])) : null;
    return $p;
}

function find_pull(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT p.*, NULL AS started_by_name FROM mcp_source_pulls p WHERE p.pull_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : pull_row_decode($r);
}

/** What a pull changed (tool `get_pull`): changed offers (≤ 100), new listings (≤ 50), removed listings (≤ 50). */
function pull_changes(PDO $pdo, int $pullId): array
{
    $p = find_pull($pdo, $pullId);
    if ($p === null) { return []; }
    $c = $pdo->prepare('SELECT o.snapshot_id, o.listing_variant_id, lv.listing_id, lv.listing_title, lv.title, lv.sku, o.price, o.availability, o.qty, o.is_heartbeat
                          FROM mcp_offer_snapshots o JOIN mcp_listing_variants lv ON lv.listing_variant_id = o.listing_variant_id WHERE o.pull_id = :p ORDER BY o.snapshot_id LIMIT 100');
    $c->execute(['p' => $pullId]);
    $n = $pdo->prepare('SELECT listing_id, title FROM mcp_listings WHERE source_id = :s AND first_seen_at >= CAST(:t AS timestamptz) AND (CAST(:f AS timestamptz) IS NULL OR first_seen_at <= CAST(:f2 AS timestamptz)) ORDER BY listing_id LIMIT 50');
    $n->execute(['s' => $p['source_id'], 't' => $p['started_at'], 'f' => $p['finished_at'], 'f2' => $p['finished_at']]);
    $r = $pdo->prepare('SELECT listing_id, title FROM mcp_listings WHERE source_id = :s AND removed_at >= CAST(:t AS timestamptz) AND (CAST(:f AS timestamptz) IS NULL OR removed_at <= CAST(:f2 AS timestamptz)) ORDER BY listing_id LIMIT 50');
    $r->execute(['s' => $p['source_id'], 't' => $p['started_at'], 'f' => $p['finished_at'], 'f2' => $p['finished_at']]);
    return ['pull' => $p, 'changed' => $c->fetchAll(), 'new_listings' => $n->fetchAll(), 'removed_listings' => $r->fetchAll()];
}

/** The policy's facts as label → value rows (the pull's <details>). */
function policy_facts(array $pull): array
{
    $p = $pull['policy'];
    if (!empty($p['queued']) && count($p) === 1) { return ['Queued' => 'the worker runs it within a minute']; }
    $rows = [];
    if (array_key_exists('robots', $p)) { $rows['robots.txt'] = (string) $p['robots']; }
    if (!empty($p['crawl_delay'])) { $rows['Crawl delay'] = $p['crawl_delay'] . ' s'; }
    if (!empty($p['user_agent'])) { $rows['User-agent'] = (string) $p['user_agent']; }
    if (isset($p['rate_per_second'])) { $rows['Rate'] = $p['rate_per_second'] . ' a second'; }
    if (isset($p['proxy'])) { $rows['Proxy'] = $p['proxy'] ? 'yes' : 'no'; }
    if (!empty($p['hosts'])) { $rows['Hosts'] = implode(', ', array_map(static fn ($h, $v) => $h . ' (' . (is_array($v) ? ($v['state'] ?? '') : $v) . ')', array_keys($p['hosts']), $p['hosts'])); }
    if (isset($p['requests_total'])) { $rows['Requests'] = (int) $p['requests_total'] . ' in all, ' . count((array) ($p['requests'] ?? [])) . ' not ok' . (!empty($p['cached']) ? ', ' . (int) $p['cached'] . ' cached' : ''); }
    foreach ((array) ($p['requests'] ?? []) as $i => $r) {
        $rows['Not ok #' . ($i + 1)] = trim(($r['host'] ?? '') . ($r['path'] ?? '') . ' · ' . ($r['status'] ?? '') . ' ' . ($r['reason'] ?? '') . (!empty($r['skipped']) ? ' (skipped)' : ''));
    }
    foreach ((array) ($p['connector'] ?? []) as $k => $v) {
        if (is_scalar($v) || $v === null) { $rows[ucfirst(str_replace('_', ' ', (string) $k))] = is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v; }
    }
    foreach ((array) ($p['errors'] ?? []) as $i => $e) { $rows['Error #' . ($i + 1)] = (string) $e; }
    if (isset($p['query'])) { $rows['Query'] = (string) $p['query']; $rows['Found'] = (string) ($p['found'] ?? 0); }
    return $rows;
}

/** What goes with a source when it is deleted (the confirm's counts). */
function source_delete_counts(PDO $pdo, int $sourceId): array
{
    $one = static fn (string $sql): int => (int) one_value($pdo, $sql, ['s' => $sourceId]);
    return ['listings' => $one('SELECT count(*) FROM listings WHERE source_id = :s'),
            'offers' => $one('SELECT count(*) FROM offer_snapshots o JOIN listing_variants lv ON lv.id = o.listing_variant_id JOIN listings l ON l.id = lv.listing_id WHERE l.source_id = :s'),
            'pulls' => $one('SELECT count(*) FROM source_pulls WHERE source_id = :s'),
            'credential' => $one('SELECT count(*) FROM source_credentials WHERE source_id = :s'),
            'matched' => $one('SELECT count(*) FROM listing_variants lv JOIN listings l ON l.id = lv.listing_id WHERE l.source_id = :s AND lv.variant_id IS NOT NULL')];
}

/** The suppliers for a select (active). */
function source_suppliers(PDO $pdo): array
{
    return $pdo->query('SELECT supplier_id, name FROM mcp_suppliers WHERE active ORDER BY name')->fetchAll();
}

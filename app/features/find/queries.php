<?php
declare(strict_types=1);

/**
 * Find's reads (find.md "Query functions"): inv_find() for the list, inv_availability() and inv_atp() for a variant, the chips' vocabularies, the
 * searchable sources and one live ask. Every answer is the SQL's; PHP decodes and renders. The command bar's `find` and `availability` tools read the
 * same two functions (Phase 4).
 */
require_once dirname(__DIR__) . '/sources/queries.php';

const FIND_LIMIT = 50;
const FIND_PRICE_BANDS = [1 => [null, '499.99'], 2 => ['500', '999.99'], 3 => ['1000', '1999.99'], 4 => ['2000', null]];
const FIND_SHIPS_WITHIN = [3, 7, 14];

/** inv_find(): the ranked variants, best_offer decoded. */
function find_variants(PDO $pdo, ?string $q, ?string $size, array $filters, int $limit = FIND_LIMIT): array
{
    $st = $pdo->prepare('SELECT * FROM inv_find(:q, :size, CAST(:f AS jsonb), :lim)');
    $st->execute(['q' => $q, 'size' => $size, 'f' => json_encode((object) $filters, JSON_UNESCAPED_UNICODE), 'lim' => max(1, min(500, $limit))]);
    return array_map(static function (array $r): array {
        foreach (['variant_id', 'product_id', 'own_available', 'own_on_hand', 'own_floor_model'] as $k) { $r[$k] = (int) $r[$k]; }
        $r['best_lead_time_days'] = $r['best_lead_time_days'] === null ? null : (int) $r['best_lead_time_days'];
        $r['best_offer'] = $r['best_offer'] === null ? null : json_decode((string) $r['best_offer'], true);
        $r['cost_withheld'] = (bool) $r['cost_withheld'];
        $r['score'] = (float) $r['score'];
        return $r;
    }, $st->fetchAll());
}

/**
 * The sellable location with the most available, per variant (a bundle: its first component's) — what "Sell this" names for a card in stock,
 * without an inv_availability() per card. [variant_id => location_id]
 */
function best_stock_locations(PDO $pdo, array $variantIds): array
{
    if ($variantIds === []) { return []; }
    $rows = $pdo->query('SELECT v.id AS variant_id, (SELECT o.location_id FROM inv_own_stock(COALESCE((SELECT c.component_variant_id FROM bundle_components c WHERE c.bundle_variant_id = v.id ORDER BY c.component_variant_id LIMIT 1), v.id)) o
                           WHERE o.is_sellable AND o.qty_available > 0 ORDER BY o.qty_available DESC, o.location_id LIMIT 1) AS location_id
                           FROM unnest(ARRAY[' . implode(',', array_map('intval', $variantIds)) . ']::bigint[]) AS v(id)')->fetchAll();
    $out = [];
    foreach ($rows as $r) { $out[(int) $r['variant_id']] = $r['location_id'] === null ? null : (int) $r['location_id']; }
    return $out;
}

/** The active product types, in order: [{product_type_id, key, name}]. */
function find_product_types(PDO $pdo): array
{
    return $pdo->query('SELECT product_type_id, key, name FROM mcp_product_types WHERE active ORDER BY sort_order, name')->fetchAll();
}

/** The settings' sizes: [{key, name, synonyms}]. */
function find_sizes(PDO $pdo): array
{
    return json_decode((string) one_value($pdo, 'SELECT sizes FROM mcp_settings'), true) ?: [];
}

/** The firmness words of the settings' attribute keys (plush, medium, firm, extra_firm). */
function firmness_choices(PDO $pdo): array
{
    foreach (json_decode((string) one_value($pdo, 'SELECT attribute_keys FROM mcp_settings'), true) ?: [] as $k) {
        if (($k['key'] ?? '') === 'firmness_word') { return array_values((array) ($k['choices'] ?? [])); }
    }
    return [];
}

// variant_availability() (inv_availability() decoded) is slice 1's, in catalog/queries.php — Find reads the same one.

/** inv_atp(variant, qty, by). A qty under 1 is the function's sentence (the caller's inv_guard() makes it a 422). */
function variant_atp(PDO $pdo, int $variantId, int $qty = 1, ?string $by = null): array
{
    $st = $pdo->prepare('SELECT inv_atp(:v, :q, CAST(:by AS date))');
    $st->execute(['v' => $variantId, 'q' => $qty, 'by' => $by]);
    return json_decode((string) $st->fetchColumn(), true) ?: [];
}

/** The active sellable locations. */
function sellable_locations(PDO $pdo): array
{
    return $pdo->query('SELECT location_id, name, kind FROM mcp_locations WHERE active AND is_sellable ORDER BY (kind = \'warehouse\') DESC, name')->fetchAll();
}

/** The sources that can be asked live: active, a connector with search, not paused, not blocked, not backing off (the rule inv_source_search_live() applies). */
function searchable_sources(PDO $pdo): array
{
    $defs = inv_connectors();
    $out = [];
    foreach ($pdo->query('SELECT s.source_id, s.name, s.connector, s.role, s.supplier_id, s.supplier_name, s.paused_at, s.backoff_until, h.health
                            FROM mcp_sources s LEFT JOIN inv_source_health() h ON h.source_id = s.source_id WHERE s.active ORDER BY s.name')->fetchAll() as $s) {
        if (empty($defs[$s['connector']]['capabilities']['has_search'])) { continue; }
        if ($s['paused_at'] !== null || $s['health'] === 'blocked') { continue; }
        if ($s['backoff_until'] !== null && strtotime((string) $s['backoff_until']) > time()) { continue; }
        $s['source_id'] = (int) $s['source_id'];
        $out[] = $s;
    }
    return $out;
}

/**
 * One source asked live (the Find card, the bridge): inv_source_search_live() + the mcp_listing_variants rows by the ids it wrote (as the caller —
 * the wall stands) or, when it answered from the last pull, those listings' variants. Returns {status, reason, pull_id, rows, listings_seen, ms}.
 */
function live_search_source(PDO $pdo, int $sourceId, string $q, int $limit, ?int $by, ?string $size = null): array
{
    $t0 = microtime(true);
    $r = inv_source_search_live($pdo, $sourceId, $q, $limit, $by, true, $size);
    $ms = (int) round((microtime(true) - $t0) * 1000);
    $status = $r['ok'] ? 'ok' : (string) ($r['reason'] ?? 'failed');
    if (!$r['ok'] && (str_contains($status, 'blocked') || ($status === 'backing off'
            && one_value($pdo, 'SELECT health FROM inv_source_health() WHERE source_id = :s', ['s' => $sourceId]) === 'blocked'))) {
        $status = 'blocked';                                // a wall or robots — not a pause for breath
    }
    $ids = array_map('intval', $r['listing_ids'] ?? []);
    if (!$r['ok']) { $ids = array_map(static fn ($l) => (int) $l['id'], $r['listings'] ?? []); }
    $rows = [];
    if ($ids !== []) {
        $rows = $pdo->query('SELECT v.*, pv.sku AS our_sku, pv.product_name AS our_product FROM mcp_listing_variants v LEFT JOIN mcp_product_variants pv ON pv.variant_id = v.variant_id
                              WHERE v.listing_id IN (' . implode(',', array_unique($ids)) . ') AND v.removed_at IS NULL ORDER BY v.listing_title, v.listing_variant_id')->fetchAll();
    }
    if ($size !== null) {                                   // a size narrows the rows (a listing variant of another size never answers) — as the action does
        $want = strtolower(str_replace([' ', '-'], '_', $size));
        $rows = array_values(array_filter($rows, static fn ($lv) => $lv['size_key'] === null || $lv['size_key'] === $want || strtolower((string) $lv['size_name']) === strtolower($size)));
    }
    $pull = isset($r['pull_id']) ? $pdo->query('SELECT listings_seen, listings_new, listings_changed FROM source_pulls WHERE id = ' . (int) $r['pull_id'])->fetch() : null;
    return ['status' => $status, 'reason' => $r['reason'] ?? null, 'pull_id' => $r['pull_id'] ?? null, 'rows' => $rows, 'listings_seen' => (int) ($pull['listings_seen'] ?? count($r['listings'] ?? [])),
            'listings_new' => (int) ($pull['listings_new'] ?? 0), 'listings_changed' => (int) ($pull['listings_changed'] ?? 0), 'ms' => $ms];
}

<?php
declare(strict_types=1);

/**
 * The catalog's reads (catalog.md "Query functions" — the signatures the Phase 4 tools C1–C6 and the resolvers call): products,
 * variants with their availability, identifiers, bundles, prices and their history, images, the listings naming a product, the
 * gaps, brands and product types, the vocabulary. Every read is a mcp_* view or a db/014 function: cost is nulled by the database
 * (`cost_withheld`), never here.
 */

const PRODUCT_PAGE = 48;
const PRODUCT_STATUSES = ['draft' => 'Draft', 'active' => 'Active', 'discontinued' => 'Discontinued'];
const PRODUCT_KINDS = ['single' => 'Single', 'bundle' => 'Bundle'];
const SHIPS_HOW = ['parcel' => 'Parcel', 'ltl' => 'LTL freight', 'white_glove' => 'White glove', 'pickup_only' => 'Pickup only'];
const PRICE_KINDS = ['retail' => 'Retail', 'map' => 'MAP', 'cost' => 'Cost'];
const IDENTIFIER_KINDS = ['gtin' => 'GTIN', 'upc' => 'UPC', 'ean' => 'EAN', 'mpn' => 'MPN', 'asin' => 'ASIN', 'ebay_epid' => 'eBay EPID', 'walmart_item_id' => 'Walmart item id', 'supplier_sku' => "Supplier's SKU", 'other' => 'Other'];
const GAP_KINDS = ['no_gtin' => 'No GTIN', 'no_cost' => 'No cost', 'no_retail' => 'No retail', 'no_image' => 'No image', 'retail_under_map' => 'Retail under MAP', 'empty_bundle' => 'Empty bundle'];
const VARIANT_COLUMNS = 'variant_id, product_id, product_name, brand, kind, sku, option_values, size_key, size_name, barcode, mpn, weight_g, length_mm, width_mm, height_mm, ships_how, retail_price, map_price, cost_price, cost_withheld, cost_updated_at, reorder_point, reorder_qty, active, serialized, created_at, updated_at, qty_on_hand, qty_available';
const PRODUCT_COLUMNS = 'product_id, name, brand_id, brand, product_type_id, product_type, description, attributes, kind, status, options, reorder_point, ships_how, tags, created_by, discontinued_at, created_at, updated_at, variant_count, primary_image_attachment_id';

/** The vocabulary a form and a card read (mcp_settings): sizes [{key,name,synonyms}], attribute_keys [{key,name,kind,choices?}], units, currency. */
function settings_vocabulary(PDO $pdo): array
{
    static $cache = null;
    if ($cache === null) {
        $r = $pdo->query('SELECT sizes, attribute_keys, units, currency, sales_sees_cost FROM mcp_settings')->fetch();
        $cache = ['sizes' => json_decode((string) ($r['sizes'] ?? '[]'), true) ?: [], 'attribute_keys' => json_decode((string) ($r['attribute_keys'] ?? '[]'), true) ?: [],
                  'units' => (string) ($r['units'] ?? 'imperial'), 'currency' => (string) ($r['currency'] ?? 'USD')];
    }
    return $cache;
}

/** The product rows as JSON wants them: a decoded attributes object, options list, tags list. */
function product_row_decode(array $p): array
{
    $p['attributes'] = is_array($p['attributes'] ?? null) ? $p['attributes'] : (json_decode((string) ($p['attributes'] ?? '{}'), true) ?: []);
    $p['options'] = is_array($p['options'] ?? null) ? $p['options'] : (json_decode((string) ($p['options'] ?? '[]'), true) ?: []);
    $p['tags'] = is_array($p['tags'] ?? null) ? $p['tags'] : pg_text_array((string) ($p['tags'] ?? '{}'));
    $p['product_id'] = (int) $p['product_id'];
    $p['variant_count'] = (int) ($p['variant_count'] ?? 0);
    return $p;
}

function variant_row_decode(array $v): array
{
    $v['option_values'] = is_array($v['option_values'] ?? null) ? $v['option_values'] : (json_decode((string) ($v['option_values'] ?? '{}'), true) ?: []);
    $v['variant_id'] = (int) $v['variant_id'];
    $v['product_id'] = (int) $v['product_id'];
    $v['cost_withheld'] = (bool) ($v['cost_withheld'] ?? true);
    $v['active'] = (bool) ($v['active'] ?? true);
    $v['qty_on_hand'] = (int) ($v['qty_on_hand'] ?? 0);
    $v['qty_available'] = (int) ($v['qty_available'] ?? 0);
    return $v;
}

/**
 * The product list (tool `find_products`): mcp_products with the stock sums over its variants; with `q` the product ids come from
 * inv_find() first (name, brand, SKU, GTIN, MPN — ranked). $filters: q, brand (id), product_type (id or key), kind, status
 * ('' = every status but discontinued; 'all' = everything), attributes (object), price_min, price_max, size. Answers ['rows', 'total'].
 */
function find_products(PDO $pdo, array $filters, int $limit = PRODUCT_PAGE, int $offset = 0): array
{
    $where = [];
    $args = [];
    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $f = [];
        if (!empty($filters['brand'])) { $f['brand_id'] = (int) $filters['brand']; }
        if (!empty($filters['product_type'])) { $t = product_type_by_key_or_id($pdo, (string) $filters['product_type']); if ($t) { $f['product_type_id'] = (int) $t['product_type_id']; } }
        if (($filters['status'] ?? '') !== '' && ($filters['status'] ?? '') !== 'all') { $f['status'] = (string) $filters['status']; }
        if (isset($filters['price_min']) && $filters['price_min'] !== '') { $f['price_min'] = (float) $filters['price_min']; }
        if (isset($filters['price_max']) && $filters['price_max'] !== '') { $f['price_max'] = (float) $filters['price_max']; }
        if (!empty($filters['attributes']) && is_array($filters['attributes'])) { $f['attributes'] = $filters['attributes']; }
        $st = $pdo->prepare('SELECT DISTINCT ON (product_id) product_id, score FROM inv_find(:q, :size, CAST(:f AS jsonb), 500) ORDER BY product_id, score DESC');
        $st->execute(['q' => mb_substr($q, 0, 120), 'size' => ($filters['size'] ?? '') !== '' ? (string) $filters['size'] : null, 'f' => json_encode((object) $f)]);
        $found = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        if ($found !== [] && max($found) >= 6) {                    // an identifier, SKU or SKU-prefix hit: only that tier, never the look-alike names
            $found = array_filter($found, static fn ($score): bool => (float) $score >= 6);
        }
        if ($found === []) {
            return ['rows' => [], 'total' => 0];
        }
        $where[] = 'p.product_id = ANY (CAST(:ids AS bigint[]))';
        $args['ids'] = '{' . implode(',', array_map('intval', array_keys($found))) . '}';
    }
    if (!empty($filters['brand'])) { $where[] = 'p.brand_id = :brand'; $args['brand'] = (int) $filters['brand']; }
    if (!empty($filters['product_type'])) {
        $t = product_type_by_key_or_id($pdo, (string) $filters['product_type']);
        $where[] = 'p.product_type_id = :ptype';
        $args['ptype'] = $t === null ? -1 : (int) $t['product_type_id'];
    }
    if (($filters['kind'] ?? '') !== '' && isset(PRODUCT_KINDS[$filters['kind']])) { $where[] = 'p.kind = :kind'; $args['kind'] = (string) $filters['kind']; }
    $status = (string) ($filters['status'] ?? '');
    if ($status === '') { $where[] = "p.status <> 'discontinued'"; }
    elseif ($status !== 'all' && isset(PRODUCT_STATUSES[$status])) { $where[] = 'p.status = :status'; $args['status'] = $status; }
    if ($q === '') {
        if (!empty($filters['attributes']) && is_array($filters['attributes'])) { $where[] = 'p.attributes @> CAST(:attrs AS jsonb)'; $args['attrs'] = json_encode($filters['attributes']); }
        if (($filters['size'] ?? '') !== '') { $where[] = 'EXISTS (SELECT 1 FROM mcp_product_variants sv WHERE sv.product_id = p.product_id AND sv.size_key = inv_size_key(:size))'; $args['size'] = (string) $filters['size']; }
        if (isset($filters['price_min']) && $filters['price_min'] !== '') { $where[] = 'EXISTS (SELECT 1 FROM mcp_product_variants sv WHERE sv.product_id = p.product_id AND sv.retail_price >= :pmin)'; $args['pmin'] = (float) $filters['price_min']; }
        if (isset($filters['price_max']) && $filters['price_max'] !== '') { $where[] = 'EXISTS (SELECT 1 FROM mcp_product_variants sv WHERE sv.product_id = p.product_id AND sv.retail_price <= :pmax)'; $args['pmax'] = (float) $filters['price_max']; }
    }
    $sql = 'FROM mcp_products p' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where));
    $st = $pdo->prepare('SELECT count(*) ' . $sql);
    $st->execute($args);
    $total = (int) $st->fetchColumn();
    $st = $pdo->prepare('SELECT ' . str_replace(', ', ', p.', 'p.' . PRODUCT_COLUMNS) . ',
            COALESCE((SELECT sum(v.qty_on_hand) FROM mcp_product_variants v WHERE v.product_id = p.product_id), 0)::integer AS qty_on_hand,
            COALESCE((SELECT sum(v.qty_available) FROM mcp_product_variants v WHERE v.product_id = p.product_id), 0)::integer AS qty_available,
            (SELECT min(v.retail_price) FROM mcp_product_variants v WHERE v.product_id = p.product_id AND v.active) AS retail_from,
            (SELECT max(v.retail_price) FROM mcp_product_variants v WHERE v.product_id = p.product_id AND v.active) AS retail_to
        ' . $sql . ' ORDER BY ' . ($q !== '' ? 'p.name, p.product_id' : 'p.updated_at DESC, p.product_id DESC') . ' LIMIT ' . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset));
    $st->execute($args);
    $rows = array_map('product_row_decode', $st->fetchAll());
    if ($q !== '') {
        usort($rows, static fn (array $a, array $b): int => ($found[$b['product_id']] <=> $found[$a['product_id']]) ?: strcmp($a['name'], $b['name']));
    }
    return ['rows' => $rows, 'total' => $total];
}

/** The best availability state over a product's variants (the card's chip): in_stock › from_supplier › back_order › unavailable; null with no variant. */
function product_state(PDO $pdo, int $productId): ?string
{
    $st = $pdo->prepare("SELECT state FROM inv_find(NULL, NULL, CAST(:f AS jsonb), 100) WHERE product_id = :p");
    $st->execute(['f' => json_encode(['status' => 'active']), 'p' => $productId]);
    $rank = ['in_stock' => 0, 'from_supplier' => 1, 'back_order' => 2, 'unavailable' => 3];
    $best = null;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $s) {
        if ($best === null || $rank[$s] < $rank[$best]) { $best = $s; }
    }
    return $best;
}

function find_product(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT ' . PRODUCT_COLUMNS . ' FROM mcp_products WHERE product_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : product_row_decode($r);
}

/** A product by its id or, for an agent, its name (exact, case-insensitive; the newest when several). */
function product_by_id_or_name(PDO $pdo, string $v): ?array
{
    if (ctype_digit($v)) { return find_product($pdo, (int) $v); }
    $st = $pdo->prepare('SELECT product_id FROM mcp_products WHERE lower(name) = lower(:n) ORDER BY product_id DESC LIMIT 1');
    $st->execute(['n' => $v]);
    $id = $st->fetchColumn();
    return $id === false ? null : find_product($pdo, (int) $id);
}

function product_variants(PDO $pdo, int $productId, bool $activeOnly = true): array
{
    $st = $pdo->prepare('SELECT ' . VARIANT_COLUMNS . ' FROM mcp_product_variants WHERE product_id = :p' . ($activeOnly ? ' AND active' : '') . ' ORDER BY active DESC, size_key NULLS LAST, sku');
    $st->execute(['p' => $productId]);
    return array_map('variant_row_decode', $st->fetchAll());
}

/** Each variant with `best_offer`, `best_lead_time_days`, `state`, `own` (and `sets_available` for a bundle) from inv_availability() — one call per variant, ≤ 50. */
function product_variants_with_availability(PDO $pdo, int $productId): array
{
    $rows = product_variants($pdo, $productId, false);
    $st = $pdo->prepare('SELECT inv_availability(:v)::text');
    foreach ($rows as $i => $v) {
        if ($i >= 50) { $rows[$i] += ['best_offer' => null, 'best_lead_time_days' => null, 'state' => null, 'own' => [], 'sets_available' => null]; continue; }
        $st->execute(['v' => $v['variant_id']]);
        $a = json_decode((string) $st->fetchColumn(), true) ?: [];
        $rows[$i] += ['best_offer' => $a['offers'][0] ?? null, 'best_lead_time_days' => $a['best_lead_time_days'] ?? null, 'state' => $a['state'] ?? null,
                      'own' => $a['own'] ?? [], 'sets_available' => $a['sets_available'] ?? null, 'margin_pct' => $a['prices']['margin_pct'] ?? null];
    }
    return $rows;
}

/** One product in full (tool `get_product`). */
function product_full(PDO $pdo, int $id): ?array
{
    $p = find_product($pdo, $id);
    if ($p === null) { return null; }
    $variants = product_variants_with_availability($pdo, $id);
    $ids = array_column($variants, 'variant_id');
    $identifiers = [];
    $components = [];
    if ($ids !== []) {
        $lit = '{' . implode(',', $ids) . '}';
        $st = $pdo->prepare('SELECT identifier_id, variant_id, kind, value, source_id, created_by, created_at FROM mcp_variant_identifiers WHERE variant_id = ANY (CAST(:ids AS bigint[])) ORDER BY variant_id, kind, value');
        $st->execute(['ids' => $lit]);
        $identifiers = $st->fetchAll();
        $st = $pdo->prepare('SELECT bundle_variant_id, component_variant_id, component_sku, component_product, qty FROM mcp_bundle_components WHERE bundle_variant_id = ANY (CAST(:ids AS bigint[])) ORDER BY bundle_variant_id, component_variant_id');
        $st->execute(['ids' => $lit]);
        $components = $st->fetchAll();
    }
    return ['product' => $p, 'variants' => $variants, 'identifiers' => $identifiers, 'bundle_components' => $components, 'images' => product_images($pdo, $id),
            'listings' => product_listings($pdo, $id), 'notes' => record_notes($pdo, 'product', $id), 'attachments' => record_attachments($pdo, 'product', $id)];
}

function find_variant(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT ' . VARIANT_COLUMNS . ' FROM mcp_product_variants WHERE variant_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : variant_row_decode($r);
}

/** A variant by its id or SKU (exact, case-insensitive). */
function variant_by_id_or_sku(PDO $pdo, string $v): ?array
{
    if (ctype_digit($v)) { return find_variant($pdo, (int) $v); }
    $st = $pdo->prepare('SELECT variant_id FROM mcp_product_variants WHERE lower(sku) = lower(:s) LIMIT 1');
    $st->execute(['s' => $v]);
    $id = $st->fetchColumn();
    return $id === false ? null : find_variant($pdo, (int) $id);
}

function variant_availability(PDO $pdo, int $variantId): array
{
    $st = $pdo->prepare('SELECT inv_availability(:v)::text');
    $st->execute(['v' => $variantId]);
    return json_decode((string) $st->fetchColumn(), true) ?: [];
}

/** One variant in full (tool `get_variant`). */
function variant_full(PDO $pdo, int $id): ?array
{
    $v = find_variant($pdo, $id);
    if ($v === null) { return null; }
    return ['variant' => $v, 'identifiers' => variant_identifiers($pdo, $id), 'availability' => variant_availability($pdo, $id), 'price_history' => price_history($pdo, $id, null, null, 10),
            'bundles_containing' => bundles_containing($pdo, $id), 'open_lines' => variant_open_lines($pdo, $id), 'watches' => variant_watches($pdo, $id)];
}

function variant_identifiers(PDO $pdo, int $variantId): array
{
    $st = $pdo->prepare('SELECT i.identifier_id, i.variant_id, i.kind, i.value, i.source_id, s.name AS source_name, i.created_by, m.display_name AS created_by_name, i.created_at
                           FROM mcp_variant_identifiers i LEFT JOIN mcp_sources s ON s.source_id = i.source_id LEFT JOIN mcp_members m ON m.member_id = i.created_by
                          WHERE i.variant_id = :v ORDER BY i.kind, i.value');
    $st->execute(['v' => $variantId]);
    return $st->fetchAll();
}

/** Which variant has this identifier (tool `variant_by_identifier`): inv_find kept at score ≥ 9, then mcp_variant_identifiers for `matched_on`. 0, 1 or several rows. */
function variant_by_identifier(PDO $pdo, string $value, ?string $kind = null, ?int $sourceId = null): array
{
    $value = trim($value);
    if ($value === '') { return []; }
    $st = $pdo->prepare('SELECT variant_id, product_id, product_name AS product, brand, sku, size_name, barcode, mpn, retail_price, own_available AS qty_available, state, score FROM inv_find(:q, NULL, \'{}\'::jsonb, 50) WHERE score >= 9');
    $st->execute(['q' => mb_substr($value, 0, 64)]);
    $rows = $st->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $matched = null;
        if (strcasecmp((string) $r['sku'], $value) === 0 && ($kind === null || $kind === 'sku')) { $matched = ['kind' => 'sku', 'value' => $r['sku'], 'source_id' => null]; }
        $g = $pdo->prepare('SELECT inv_gtin14(:v)');
        $g->execute(['v' => $value]);
        $gt = $g->fetchColumn();
        if ($matched === null && $gt && $r['barcode'] === $gt && ($kind === null || in_array($kind, ['gtin', 'upc', 'ean'], true))) { $matched = ['kind' => 'gtin', 'value' => $gt, 'source_id' => null]; }
        if ($matched === null && $r['mpn'] !== null && strcasecmp((string) $r['mpn'], $value) === 0 && ($kind === null || $kind === 'mpn')) { $matched = ['kind' => 'mpn', 'value' => $r['mpn'], 'source_id' => null]; }
        if ($matched === null) {
            $st2 = $pdo->prepare('SELECT kind, value, source_id FROM mcp_variant_identifiers WHERE variant_id = :v AND (lower(value) = lower(:raw) OR value = :g)' . ($kind !== null ? ' AND kind = :k' : '') . ($sourceId !== null ? ' AND source_id = :s' : '') . ' LIMIT 1');
            $a = ['v' => $r['variant_id'], 'raw' => $value, 'g' => $gt ?: ''];
            if ($kind !== null) { $a['k'] = $kind; }
            if ($sourceId !== null) { $a['s'] = $sourceId; }
            $st2->execute($a);
            $m = $st2->fetch();
            if ($m) { $matched = ['kind' => $m['kind'], 'value' => $m['value'], 'source_id' => $m['source_id'] === null ? null : (int) $m['source_id']]; }
        }
        if ($matched === null) { continue; }
        unset($r['score']);
        $r['variant_id'] = (int) $r['variant_id'];
        $r['product_id'] = (int) $r['product_id'];
        $r['matched_on'] = $matched;
        $out[] = $r;
    }
    return $out;
}

/** The picker's rows: by SKU prefix or product name — {variant_id, label, detail}. */
function variant_pick(PDO $pdo, string $q, int $limit = 20, bool $singleOnly = false): array
{
    $q = trim($q);
    $st = $pdo->prepare('SELECT variant_id, sku, product_name, brand, size_name, kind, active FROM mcp_product_variants
                          WHERE active' . ($singleOnly ? " AND kind = 'single'" : '') . ($q === '' ? '' : ' AND (sku ILIKE :p OR product_name ILIKE :w OR brand ILIKE :w OR barcode = inv_gtin14(:q))')
        . ' ORDER BY (sku ILIKE :p2) DESC, product_name, size_key NULLS LAST, sku LIMIT ' . max(1, min(100, $limit)));
    $args = ['p2' => $q . '%'];
    if ($q !== '') { $args += ['p' => $q . '%', 'w' => '%' . $q . '%', 'q' => $q]; }
    $st->execute($args);
    return array_map(static fn (array $r): array => ['variant_id' => (int) $r['variant_id'], 'label' => $r['sku'] . ' — ' . $r['product_name'] . ($r['size_name'] ? ', ' . $r['size_name'] : ''),
        'detail' => trim((string) $r['brand']) . ($r['kind'] === 'bundle' ? ' · bundle' : '')], $st->fetchAll());
}

function bundle_components(PDO $pdo, int $variantId): array
{
    $st = $pdo->prepare('SELECT c.bundle_variant_id, c.component_variant_id, c.component_sku, c.component_product, c.qty, v.size_name AS component_size, v.active AS component_active
                           FROM mcp_bundle_components c JOIN mcp_product_variants v ON v.variant_id = c.component_variant_id WHERE c.bundle_variant_id = :v ORDER BY c.component_product, c.component_sku');
    $st->execute(['v' => $variantId]);
    return $st->fetchAll();
}

function bundle_availability(PDO $pdo, int $variantId): ?array
{
    $st = $pdo->prepare('SELECT inv_bundle_availability(:v)::text');
    $st->execute(['v' => $variantId]);
    $j = $st->fetchColumn();
    return $j === null || $j === false ? null : (json_decode((string) $j, true) ?: null);
}

/** The bundles listing this variant as a component: [{bundle_variant_id, sku, product_name, qty}]. */
function bundles_containing(PDO $pdo, int $variantId): array
{
    $st = $pdo->prepare('SELECT c.bundle_variant_id, v.sku, v.product_name, v.product_id, c.qty FROM mcp_bundle_components c JOIN mcp_product_variants v ON v.variant_id = c.bundle_variant_id WHERE c.component_variant_id = :v ORDER BY v.product_name');
    $st->execute(['v' => $variantId]);
    return $st->fetchAll();
}

/** inv_price_history() filtered (cost rows only for `sees_cost()` — the function's rule). Newest first. */
function price_history(PDO $pdo, int $variantId, ?string $kind = null, ?string $since = null, int $limit = 100): array
{
    $sql = 'SELECT changed_at, kind, old_price, new_price, changed_by, changed_by_name, reason, source_kind FROM inv_price_history(:v) WHERE true';
    $args = ['v' => $variantId];
    if ($kind !== null && isset(PRICE_KINDS[$kind])) { $sql .= ' AND kind = :k'; $args['k'] = $kind; }
    if ($since !== null && $since !== '') { $sql .= ' AND changed_at >= CAST(:since AS timestamptz)'; $args['since'] = $since; }
    $st = $pdo->prepare($sql . ' ORDER BY changed_at DESC LIMIT ' . max(1, min(1000, $limit)));
    $st->execute($args);
    return $st->fetchAll();
}

/** The chart's `series` from history rows: oldest first, one series per kind present, each point [iso8601, number, reason]. */
function price_series(array $history): array
{
    $byKind = [];
    foreach (array_reverse($history) as $h) {
        if ($h['new_price'] === null) { continue; }
        $byKind[$h['kind']][] = [json_ts($h['changed_at']), (float) $h['new_price'], $h['reason']];
    }
    $out = [];
    foreach (PRICE_KINDS as $k => $label) {
        if (isset($byKind[$k])) { $out[] = ['key' => $k, 'label' => $label, 'points' => $byKind[$k]]; }
    }
    return $out;
}

/** inv_catalog_gaps() filtered by gap and brand (slice 9's Reports hub calls this too). */
function catalog_gaps(PDO $pdo, ?string $gap = null, ?int $brandId = null): array
{
    $sql = 'SELECT g.variant_id, g.sku, g.product_name, g.size_name, g.gap, v.product_id FROM inv_catalog_gaps() g JOIN mcp_product_variants v ON v.variant_id = g.variant_id';
    $where = [];
    $args = [];
    if ($gap !== null && isset(GAP_KINDS[$gap])) { $where[] = 'g.gap = :gap'; $args['gap'] = $gap; }
    if ($brandId !== null) { $where[] = 'EXISTS (SELECT 1 FROM mcp_products p WHERE p.product_id = v.product_id AND p.brand_id = :b)'; $args['b'] = $brandId; }
    $st = $pdo->prepare($sql . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY g.product_name, g.sku, g.gap');
    $st->execute($args);
    return $st->fetchAll();
}

function catalog_gap_counts(PDO $pdo): array
{
    $counts = array_fill_keys(array_keys(GAP_KINDS), 0);
    foreach ($pdo->query('SELECT gap, count(*) AS n FROM inv_catalog_gaps() GROUP BY gap')->fetchAll() as $r) { $counts[$r['gap']] = (int) $r['n']; }
    return $counts;
}

/** mcp_brands with product counts and the supplier's name (tool `find_brands`). */
function find_brands(PDO $pdo, ?string $q = null, bool $includeInactive = false, int $limit = 100): array
{
    $sql = 'SELECT b.brand_id, b.name, b.website, b.supplier_id, s.name AS supplier_name, b.active, b.created_at, b.updated_at,
                   (SELECT count(*) FROM mcp_products p WHERE p.brand_id = b.brand_id) AS product_count
              FROM mcp_brands b LEFT JOIN mcp_suppliers s ON s.supplier_id = b.supplier_id WHERE true';
    $args = [];
    if (!$includeInactive) { $sql .= ' AND b.active'; }
    if ($q !== null && trim($q) !== '') { $sql .= ' AND b.name ILIKE :q'; $args['q'] = '%' . trim($q) . '%'; }
    $st = $pdo->prepare($sql . ' ORDER BY b.name LIMIT ' . max(1, min(500, $limit)));
    $st->execute($args);
    return array_map(static function (array $b): array { $b['brand_id'] = (int) $b['brand_id']; $b['product_count'] = (int) $b['product_count']; $b['active'] = (bool) $b['active']; return $b; }, $st->fetchAll());
}

function find_brand(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT b.brand_id, b.name, b.website, b.supplier_id, s.name AS supplier_name, b.active, b.created_at, b.updated_at, (SELECT count(*) FROM mcp_products p WHERE p.brand_id = b.brand_id) AS product_count
                           FROM mcp_brands b LEFT JOIN mcp_suppliers s ON s.supplier_id = b.supplier_id WHERE b.brand_id = :id');
    $st->execute(['id' => $id]);
    $b = $st->fetch();
    if ($b === false) { return null; }
    $b['brand_id'] = (int) $b['brand_id'];
    $b['product_count'] = (int) $b['product_count'];
    $b['active'] = (bool) $b['active'];
    return $b;
}

/** A brand by id or name (an agent names one). */
function brand_by_id_or_name(PDO $pdo, string $v): ?array
{
    if (ctype_digit($v)) { return find_brand($pdo, (int) $v); }
    $st = $pdo->prepare('SELECT brand_id FROM mcp_brands WHERE lower(name) = lower(:n) LIMIT 1');
    $st->execute(['n' => $v]);
    $id = $st->fetchColumn();
    return $id === false ? null : find_brand($pdo, (int) $id);
}

function product_types(PDO $pdo, bool $activeOnly = true): array
{
    $rows = $pdo->query('SELECT t.product_type_id, t.key, t.name, t.sort_order, t.active, (SELECT count(*) FROM mcp_products p WHERE p.product_type_id = t.product_type_id) AS product_count FROM mcp_product_types t' . ($activeOnly ? ' WHERE t.active' : '') . ' ORDER BY t.sort_order, t.name')->fetchAll();
    return array_map(static function (array $t): array { $t['product_type_id'] = (int) $t['product_type_id']; $t['active'] = (bool) $t['active']; $t['product_count'] = (int) $t['product_count']; $t['sort_order'] = (int) $t['sort_order']; return $t; }, $rows);
}

function product_type_by_key_or_id(PDO $pdo, string $v): ?array
{
    $v = trim($v);
    if ($v === '') { return null; }
    $st = $pdo->prepare('SELECT product_type_id, key, name, sort_order, active FROM mcp_product_types WHERE ' . (ctype_digit($v) ? 'product_type_id = :v' : 'lower(key) = lower(:v) OR lower(name) = lower(:v)') . ' LIMIT 1');
    $st->execute(['v' => $v]);
    $t = $st->fetch();
    if ($t === false) { return null; }
    $t['product_type_id'] = (int) $t['product_type_id'];
    $t['active'] = (bool) $t['active'];
    return $t;
}

function product_images(PDO $pdo, int $productId): array
{
    $st = $pdo->prepare('SELECT i.image_id, i.product_id, i.variant_id, v.sku AS variant_sku, i.attachment_id, a.filename, a.mime_type, a.byte_size, i.alt_text, i.sort_order, i.is_primary, i.created_at
                           FROM mcp_product_images i JOIN mcp_attachments a ON a.attachment_id = i.attachment_id LEFT JOIN mcp_product_variants v ON v.variant_id = i.variant_id
                          WHERE i.product_id = :p ORDER BY i.is_primary DESC, i.sort_order, i.image_id');
    $st->execute(['p' => $productId]);
    return array_map(static function (array $i): array { $i['image_id'] = (int) $i['image_id']; $i['attachment_id'] = (int) $i['attachment_id']; $i['is_primary'] = (bool) $i['is_primary']; $i['sort_order'] = (int) $i['sort_order']; return $i; }, $st->fetchAll());
}

function find_image(PDO $pdo, int $imageId): ?array
{
    $st = $pdo->prepare('SELECT i.image_id, i.product_id, i.variant_id, i.attachment_id, a.filename, i.alt_text, i.sort_order, i.is_primary FROM mcp_product_images i JOIN mcp_attachments a ON a.attachment_id = i.attachment_id WHERE i.image_id = :id');
    $st->execute(['id' => $imageId]);
    $i = $st->fetch();
    if ($i === false) { return null; }
    $i['image_id'] = (int) $i['image_id'];
    $i['product_id'] = (int) $i['product_id'];
    $i['attachment_id'] = (int) $i['attachment_id'];
    $i['is_primary'] = (bool) $i['is_primary'];
    return $i;
}

/** mcp_listings naming this product (a product-level match), or whose variants are matched to its variants. */
function product_listings(PDO $pdo, int $productId): array
{
    $st = $pdo->prepare('SELECT l.listing_id, l.source_id, l.source_name, l.source_role, l.title, l.url, l.vendor, l.first_seen_at, l.last_seen_at, l.removed_at, l.variant_count, l.matched_count
                           FROM mcp_listings l WHERE l.product_id = :p OR EXISTS (SELECT 1 FROM mcp_listing_variants lv JOIN mcp_product_variants v ON v.variant_id = lv.variant_id WHERE lv.listing_id = l.listing_id AND v.product_id = :p2)
                          ORDER BY l.source_name, l.title');
    $st->execute(['p' => $productId, 'p2' => $productId]);
    return $st->fetchAll();
}

/** The open sales and purchase lines on a variant (empty until slices 5 and 6 write them). */
function variant_open_lines(PDO $pdo, int $variantId): array
{
    $st = $pdo->prepare("SELECT 'sale' AS side, line_id AS id, order_number AS number, sales_order_id AS record_id, qty, status, fulfilment_kind AS detail, created_at FROM mcp_sales_order_lines WHERE variant_id = :v AND status IN ('open', 'allocated', 'ordered')
                         UNION ALL SELECT 'purchase', purchase_order_line_id, purchase_order_number, purchase_order_id, qty_ordered, status, supplier_sku, created_at FROM mcp_purchase_order_lines WHERE variant_id = :v2 AND status IN ('open', 'acknowledged', 'partial') ORDER BY created_at DESC");
    $st->execute(['v' => $variantId, 'v2' => $variantId]);
    return $st->fetchAll();
}

function variant_watches(PDO $pdo, int $variantId): array
{
    $st = $pdo->prepare('SELECT watch_id, member_id, member_name, agent_member_id, kind, threshold, text_me, last_state, fired_at, fire_count, active, note, created_at FROM mcp_watches WHERE variant_id = :v ORDER BY created_at DESC');
    $st->execute(['v' => $variantId]);
    return $st->fetchAll();
}

function record_notes(PDO $pdo, string $type, int $id): array
{
    $st = $pdo->prepare('SELECT note_id, member_id, member_name, body, created_at FROM mcp_notes WHERE record_type = :t AND record_id = :id ORDER BY created_at DESC LIMIT 50');
    $st->execute(['t' => $type, 'id' => $id]);
    return $st->fetchAll();
}

function record_attachments(PDO $pdo, string $type, int $id): array
{
    $st = $pdo->prepare('SELECT attachment_id, filename, mime_type, byte_size, uploaded_by, created_at FROM mcp_attachments WHERE record_type = :t AND record_id = :id ORDER BY created_at DESC');
    $st->execute(['t' => $type, 'id' => $id]);
    return $st->fetchAll();
}

/** Null when the variant may be deleted, else the sentence that says why not (a movement, a line, a match, a bundle naming it). */
function variant_deletable(PDO $pdo, int $variantId): ?string
{
    $checks = [
        ['SELECT count(*) FROM inventory_transactions WHERE variant_id = :v', 'has stock movements'],
        ['SELECT count(*) FROM sales_order_lines WHERE variant_id = :v', 'is on an order line'],
        ['SELECT count(*) FROM purchase_order_lines WHERE variant_id = :v', 'is on a purchase order line'],
        ['SELECT count(*) FROM listing_variants WHERE variant_id = :v', 'is matched to a listing'],
        ['SELECT count(*) FROM bundle_components WHERE component_variant_id = :v', 'is a component of a bundle'],
    ];
    foreach ($checks as [$sql, $why]) {
        $st = $pdo->prepare($sql);
        $st->execute(['v' => $variantId]);
        if ((int) $st->fetchColumn() > 0) {
            return 'This variant ' . $why . ': deactivate it instead of deleting it.';
        }
    }
    return null;
}

function product_deletable(PDO $pdo, int $productId): ?string
{
    $st = $pdo->prepare('SELECT id FROM product_variants WHERE product_id = :p');
    $st->execute(['p' => $productId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $vid) {
        $why = variant_deletable($pdo, (int) $vid);
        if ($why !== null) {
            return str_replace('This variant', 'A variant of this product', $why) . ' Discontinue the product instead.';
        }
    }
    $st = $pdo->prepare('SELECT count(*) FROM listings WHERE product_id = :p');
    $st->execute(['p' => $productId]);
    return (int) $st->fetchColumn() > 0 ? 'A listing names this product: discontinue it instead of deleting it.' : null;
}

/** Null when the product's kind may change, else the sentence (a variant with stock, a line or a match). */
function product_kind_changeable(PDO $pdo, int $productId): ?string
{
    $st = $pdo->prepare('SELECT id FROM product_variants WHERE product_id = :p');
    $st->execute(['p' => $productId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $vid) {
        foreach (['SELECT count(*) FROM inventory_transactions WHERE variant_id = :v', 'SELECT count(*) FROM sales_order_lines WHERE variant_id = :v', 'SELECT count(*) FROM purchase_order_lines WHERE variant_id = :v', 'SELECT count(*) FROM listing_variants WHERE variant_id = :v'] as $sql) {
            $c = $pdo->prepare($sql);
            $c->execute(['v' => $vid]);
            if ((int) $c->fetchColumn() > 0) {
                return 'The kind cannot change: a variant of this product has stock, order lines or a matched listing.';
            }
        }
    }
    return null;
}

/** The option names a variant of this product uses (for "removing an option a variant uses is refused"). */
function product_option_names_in_use(PDO $pdo, int $productId): array
{
    $st = $pdo->prepare('SELECT DISTINCT k FROM product_variants v, jsonb_object_keys(v.option_values) k WHERE v.product_id = :p');
    $st->execute(['p' => $productId]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/** The supplier sources (mcp_sources, role supplier, active) a supplier SKU may belong to. */
function supplier_sources(PDO $pdo): array
{
    return $pdo->query("SELECT source_id, name, supplier_name FROM mcp_sources WHERE role = 'supplier' AND active ORDER BY name")->fetchAll();
}

/** The active suppliers (a brand's dealer program). */
function suppliers_for_pick(PDO $pdo, string $q = '', int $limit = 100): array
{
    $st = $pdo->prepare('SELECT supplier_id, name, kind, website FROM mcp_suppliers WHERE active' . ($q !== '' ? ' AND name ILIKE :q' : '') . ' ORDER BY name LIMIT ' . max(1, min(500, $limit)));
    $st->execute($q !== '' ? ['q' => '%' . $q . '%'] : []);
    return $st->fetchAll();
}

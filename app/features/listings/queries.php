<?php
declare(strict_types=1);

/**
 * Listings' reads (sources.md "Query functions"): a source's listings with their variants, one listing in full, the offer history and its
 * summaries, the match queue, proposals, the evidence in words, and agent_may_match() — the agent's rule for listing_match.
 */

const LISTING_PAGE = 50;

function listing_variant_decode(array $v): array
{
    foreach (['listing_variant_id', 'listing_id', 'source_id'] as $k) { $v[$k] = (int) $v[$k]; }
    foreach (['variant_id', 'matched_by', 'qty', 'lead_time_days'] as $k) { $v[$k] = $v[$k] === null ? null : (int) $v[$k]; }
    $v['option_values'] = json_decode((string) ($v['option_values'] ?? '{}'), true) ?: [];
    $v['barcode_valid'] = (bool) $v['barcode_valid'];
    $v['cost_withheld'] = (bool) $v['cost_withheld'];
    return $v;
}

/** A source's listings (tool `source_listings`) + each one's variants. $filters source, q, matched (matched|unmatched), include_removed, availability, forgotten. */
function source_listings(PDO $pdo, array $filters, int $limit = LISTING_PAGE, int $offset = 0): array
{
    $w = [];
    $args = [];
    if (!empty($filters['source'])) { $w[] = 'l.source_id = :source'; $args['source'] = (int) $filters['source']; }
    if (empty($filters['include_removed'])) { $w[] = 'l.removed_at IS NULL'; }
    if (empty($filters['forgotten'])) { $w[] = 'l.forgotten_at IS NULL'; }
    if (($filters['matched'] ?? '') === 'matched') { $w[] = 'l.matched_count > 0'; }
    if (($filters['matched'] ?? '') === 'unmatched') { $w[] = 'l.matched_count < l.variant_count'; }
    if (!empty($filters['availability'])) { $w[] = 'EXISTS (SELECT 1 FROM mcp_listing_variants v WHERE v.listing_id = l.listing_id AND v.availability = :av)'; $args['av'] = (string) $filters['availability']; }
    if (trim((string) ($filters['q'] ?? '')) !== '') {
        $q = trim((string) $filters['q']);
        $w[] = '(l.title ILIKE :q OR similarity(l.title, :qs) > 0.3 OR EXISTS (SELECT 1 FROM mcp_listing_variants v WHERE v.listing_id = l.listing_id AND (lower(v.sku) = lower(:qx) OR v.barcode = inv_gtin14(:qg))))';
        $args['q'] = '%' . $q . '%'; $args['qs'] = $q; $args['qx'] = $q; $args['qg'] = $q;
    }
    $st = $pdo->prepare('SELECT l.* FROM mcp_listings l' . ($w === [] ? '' : ' WHERE ' . implode(' AND ', $w)) . ' ORDER BY (l.removed_at IS NOT NULL), l.title, l.listing_id LIMIT ' . (max(1, min(500, $limit)) + 1) . ' OFFSET ' . max(0, $offset));
    $st->execute($args);
    $rows = $st->fetchAll();
    $more = count($rows) > $limit;
    $rows = array_slice($rows, 0, $limit);
    $ids = array_map(static fn ($r) => (int) $r['listing_id'], $rows);
    $variants = [];
    if ($ids !== []) {
        $vs = $pdo->query('SELECT v.*, (SELECT sku FROM mcp_product_variants pv WHERE pv.variant_id = v.variant_id) AS our_sku FROM mcp_listing_variants v WHERE v.listing_id IN (' . implode(',', $ids) . ') ORDER BY v.listing_variant_id')->fetchAll();
        foreach ($vs as $v) { $variants[(int) $v['listing_id']][] = listing_variant_decode($v); }
    }
    foreach ($rows as &$r) {
        $r = listing_decode($r);
        $r['variants'] = $variants[$r['listing_id']] ?? [];
        $prices = array_values(array_filter(array_map(static fn ($v) => $v['price'] === null ? null : (float) $v['price'], array_filter($r['variants'], static fn ($v) => $v['removed_at'] === null)), static fn ($x) => $x !== null));
        $r['price_min'] = $prices === [] ? null : min($prices);
        $r['price_max'] = $prices === [] ? null : max($prices);
        $r['best_availability'] = listing_best_availability($r['variants']);
    }
    return ['rows' => $rows, 'more' => $more];
}

function listing_decode(array $l): array
{
    foreach (['listing_id', 'source_id', 'variant_count', 'matched_count'] as $k) { $l[$k] = (int) $l[$k]; }
    $l['product_id'] = $l['product_id'] === null ? null : (int) $l['product_id'];
    $l['raw'] = $l['raw'] === null ? null : (json_decode((string) $l['raw'], true) ?: []);
    $l['tags'] = pg_text_array((string) ($l['tags'] ?? '{}'));
    return $l;
}

/** The best availability across live variants, in the order a buyer prefers. */
function listing_best_availability(array $variants): string
{
    $order = ['in_stock', 'limited', 'pre_order', 'back_order', 'out_of_stock', 'discontinued', 'unknown'];
    $best = 'unknown';
    foreach ($variants as $v) {
        if ($v['removed_at'] !== null) { continue; }
        if (array_search($v['availability'], $order, true) < array_search($best, $order, true)) { $best = $v['availability']; }
    }
    return $best;
}

function find_listing(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_listings WHERE listing_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : listing_decode($r);
}

/** One listing in full (tool `get_listing`): {listing, variants (+ match), proposals (proposed), raw, sold_lines}. */
function listing_full(PDO $pdo, int $id): ?array
{
    $l = find_listing($pdo, $id);
    if ($l === null) { return null; }
    $st = $pdo->prepare('SELECT v.*, pv.sku AS our_sku, pv.product_id AS our_product_id, pv.product_name AS our_product_name, pv.size_name AS our_size_name,
                                (SELECT display_name FROM mcp_members m WHERE m.member_id = v.matched_by) AS matched_by_name
                           FROM mcp_listing_variants v LEFT JOIN mcp_product_variants pv ON pv.variant_id = v.variant_id WHERE v.listing_id = :id ORDER BY v.listing_variant_id');
    $st->execute(['id' => $id]);
    $variants = array_map('listing_variant_decode', $st->fetchAll());
    $lvIds = array_column($variants, 'listing_variant_id');
    $proposals = $lvIds === [] ? [] : match_proposals($pdo, ['listing_variants' => $lvIds], 200);
    $sold = [];
    if ($lvIds !== []) {
        $sold = $pdo->query('SELECT * FROM mcp_sales_order_lines WHERE listing_variant_id IN (' . implode(',', $lvIds) . ') ORDER BY 1 DESC LIMIT 50')->fetchAll();
    }
    return ['listing' => $l, 'variants' => $variants, 'proposals' => $proposals, 'raw' => $l['raw'], 'sold_lines' => $sold];
}

function find_listing_variant(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT v.*, (SELECT forgotten_at FROM mcp_listings l WHERE l.listing_id = v.listing_id) AS forgotten_at FROM mcp_listing_variants v WHERE v.listing_variant_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : listing_variant_decode($r);
}

/** The resolver `find_listing_variants`: a GTIN or SKU exact, else words; + label. */
function find_listing_variants(PDO $pdo, array $filters, int $limit = 50, int $offset = 0): array
{
    $w = [];
    $args = [];
    if (!empty($filters['source'])) { $w[] = 'v.source_id = :s'; $args['s'] = (int) $filters['source']; }
    if (empty($filters['include_removed'])) { $w[] = 'v.removed_at IS NULL'; }
    if (($filters['matched'] ?? '') === 'matched') { $w[] = 'v.variant_id IS NOT NULL'; }
    if (($filters['matched'] ?? '') === 'unmatched') { $w[] = 'v.variant_id IS NULL'; }
    if (trim((string) ($filters['q'] ?? '')) !== '') {
        $q = trim((string) $filters['q']);
        $w[] = '(v.barcode = inv_gtin14(:g) OR lower(v.sku) = lower(:x) OR (v.listing_title || \' \' || v.title) ILIKE :w)';
        $args['g'] = $q; $args['x'] = $q; $args['w'] = '%' . $q . '%';
    }
    $st = $pdo->prepare('SELECT v.* FROM mcp_listing_variants v' . ($w === [] ? '' : ' WHERE ' . implode(' AND ', $w)) . ' ORDER BY v.listing_variant_id DESC LIMIT ' . max(1, min(200, $limit)) . ' OFFSET ' . max(0, $offset));
    $st->execute($args);
    return array_map(static function (array $v): array {
        $v = listing_variant_decode($v);
        $v['label'] = $v['listing_title'] . ' · ' . $v['title'] . ($v['size_name'] ? ' · ' . $v['size_name'] : '') . ($v['price'] !== null ? ' · ' . number_format((float) $v['price'], 2) : '') . ' · ' . str_replace('_', ' ', (string) $v['availability']);
        return $v;
    }, $st->fetchAll());
}

// ---- the offer history -------------------------------------------------------------------------------------------------
/** inv_offer_history(); `until` filtered here. */
function offer_history(PDO $pdo, int $lvId, ?string $since = null, ?string $until = null, int $limit = 500): array
{
    $st = $pdo->prepare('SELECT * FROM inv_offer_history(:lv, CAST(:since AS timestamptz))');
    $st->execute(['lv' => $lvId, 'since' => $since ?? date(DATE_ATOM, time() - 90 * 86400)]);
    $rows = $st->fetchAll();
    if ($until !== null) { $rows = array_values(array_filter($rows, static fn ($r) => strtotime((string) $r['observed_at']) <= strtotime($until))); }
    return array_slice($rows, -$limit);
}

/** {points, first_at, last_at, price_min, price_max, price_first, price_last, changes, last_out_of_stock_at, days_out_of_stock}. */
function offer_summary(array $series): array
{
    $prices = array_values(array_filter(array_map(static fn ($r) => $r['price'] === null ? null : (float) $r['price'], $series), static fn ($x) => $x !== null));
    $changes = 0;
    $prev = null;
    foreach ($series as $r) { if ($prev !== null && ($r['price'] !== $prev['price'] || $r['availability'] !== $prev['availability'])) { $changes++; } $prev = $r; }
    $runs = availability_runs($series);
    return ['points' => count($series), 'first_at' => $series[0]['observed_at'] ?? null, 'last_at' => $series === [] ? null : end($series)['observed_at'],
            'price_min' => $prices === [] ? null : min($prices), 'price_max' => $prices === [] ? null : max($prices), 'price_first' => $prices[0] ?? null, 'price_last' => $prices === [] ? null : end($prices),
            'changes' => $changes, 'last_out_of_stock_at' => $runs['last_out_of_stock_at'], 'days_out_of_stock' => $runs['days_out_of_stock']];
}

/** Consecutive availability collapsed to runs [{availability, from, to, days}] + {current, last_change_at, out_of_stock_runs, days_out_of_stock, last_out_of_stock_at}. */
function availability_runs(array $series): array
{
    $runs = [];
    foreach ($series as $r) {
        $n = count($runs);
        if ($n > 0 && $runs[$n - 1]['availability'] === $r['availability']) { continue; }
        if ($n > 0) { $runs[$n - 1]['to'] = $r['observed_at']; }
        $runs[] = ['availability' => (string) $r['availability'], 'from' => $r['observed_at'], 'to' => null];
    }
    $oos = 0.0; $oosRuns = 0; $lastOos = null;
    foreach ($runs as &$run) {
        $run['days'] = round(((($run['to'] !== null ? strtotime((string) $run['to']) : time()) - strtotime((string) $run['from'])) / 86400), 1);
        if ($run['availability'] === 'out_of_stock') { $oos += $run['days']; $oosRuns++; $lastOos = $run['from']; }
    }
    unset($run);
    $current = $runs === [] ? null : end($runs);
    return ['runs' => $runs, 'current' => $current['availability'] ?? null, 'last_change_at' => $current['from'] ?? null, 'out_of_stock_runs' => $oosRuns, 'days_out_of_stock' => round($oos, 1), 'last_out_of_stock_at' => $lastOos];
}

/** The chart's series (price, compare-at when any, cost when permitted and present) and bands; a point's reason names its pull; a heartbeat draws no marker. */
function offer_chart_series(array $series, bool $seesCost): array
{
    $mk = static function (string $col) use ($series): array {
        $pts = [];
        foreach ($series as $r) {
            if ($r[$col] === null) { continue; }
            $pts[] = [json_ts($r['observed_at']), (float) $r[$col], $r['is_heartbeat'] ? 'heartbeat' : ($r['pull_id'] !== null ? 'pull #' . (int) $r['pull_id'] : null), (bool) $r['is_heartbeat']];
        }
        return $pts;
    };
    $out = [['key' => 'price', 'label' => 'Price', 'points' => $mk('price')]];
    $cmp = $mk('compare_at_price');
    if ($cmp !== []) { $out[] = ['key' => 'compare_at', 'label' => 'Compare-at', 'points' => $cmp]; }
    if ($seesCost) { $cost = $mk('cost_price'); if ($cost !== []) { $out[] = ['key' => 'cost', 'label' => 'Cost', 'points' => $cost]; } }
    $bands = array_map(static fn (array $r): array => ['from' => json_ts($r['from']), 'to' => $r['to'] === null ? null : json_ts($r['to']), 'state' => $r['availability']], availability_runs($series)['runs']);
    return ['series' => array_values(array_filter($out, static fn ($s) => $s['points'] !== [])), 'bands' => $bands];
}

// ---- the queue and the proposals ---------------------------------------------------------------------------------------
/** inv_unmatched_listings() (tool `unmatched_listings`): with a proposal only, a minimum confidence, paged. */
function unmatched_listings(PDO $pdo, ?int $sourceId = null, bool $withProposalOnly = false, ?float $minConfidence = null, int $limit = 50, int $offset = 0, ?string $q = null): array
{
    $w = [];
    $args = ['s' => $sourceId];
    if ($withProposalOnly) { $w[] = 'u.best_proposal IS NOT NULL'; }
    if ($minConfidence !== null) { $w[] = "COALESCE((u.best_proposal->>'confidence')::numeric, 0) >= :mc"; $args['mc'] = $minConfidence; }
    if ($q !== null && trim($q) !== '') { $w[] = '(u.title ILIKE :q OR u.variant_title ILIKE :q2 OR lower(u.sku) = lower(:qx) OR u.barcode = inv_gtin14(:qg))'; $args['q'] = $args['q2'] = '%' . trim($q) . '%'; $args['qx'] = trim($q); $args['qg'] = trim($q); }
    $st = $pdo->prepare('SELECT u.* FROM inv_unmatched_listings(CAST(:s AS bigint)) u' . ($w === [] ? '' : ' WHERE ' . implode(' AND ', $w))
        . " ORDER BY COALESCE((u.best_proposal->>'confidence')::numeric, 0) DESC, u.last_seen_at DESC LIMIT " . (max(1, min(500, $limit)) + 1) . ' OFFSET ' . max(0, $offset));
    $st->execute($args);
    $rows = array_map(static function (array $r): array {
        $r['listing_variant_id'] = (int) $r['listing_variant_id'];
        $r['listing_id'] = (int) $r['listing_id'];
        $r['source_id'] = (int) $r['source_id'];
        $r['best_proposal'] = $r['best_proposal'] === null ? null : json_decode((string) $r['best_proposal'], true);
        return $r;
    }, $st->fetchAll());
    return ['rows' => array_slice($rows, 0, $limit), 'more' => count($rows) > $limit];
}

/** In the queue, with a proposal, without. */
function queue_counts(PDO $pdo, ?int $sourceId): array
{
    $st = $pdo->prepare('SELECT count(*) AS queued, count(*) FILTER (WHERE best_proposal IS NOT NULL) AS with_proposal FROM inv_unmatched_listings(CAST(:s AS bigint))');
    $st->execute(['s' => $sourceId]);
    $r = $st->fetch();
    return ['queued' => (int) $r['queued'], 'with_proposal' => (int) $r['with_proposal'], 'without' => (int) $r['queued'] - (int) $r['with_proposal']];
}

/** The proposals (tool `match_proposals`): $filters listing_variant (or listing_variants[]), variant, status (default proposed), proposed_by. */
function match_proposals(PDO $pdo, array $filters, int $limit = 50, int $offset = 0): array
{
    $w = [];
    $args = [];
    if (!empty($filters['listing_variant'])) { $w[] = 'mp.listing_variant_id = :lv'; $args['lv'] = (int) $filters['listing_variant']; }
    if (!empty($filters['listing_variants'])) { $w[] = 'mp.listing_variant_id = ANY (CAST(:lvs AS bigint[]))'; $args['lvs'] = pg_array_literal(array_map('intval', $filters['listing_variants'])); }
    if (!empty($filters['variant'])) { $w[] = 'mp.variant_id = :v'; $args['v'] = (int) $filters['variant']; }
    $status = $filters['status'] ?? 'proposed';
    if ($status !== 'all') { $w[] = 'mp.status = :st'; $args['st'] = $status; }
    if (array_key_exists('proposed_by', $filters)) {
        if ($filters['proposed_by'] === null) { $w[] = 'mp.proposed_by IS NULL'; } else { $w[] = 'mp.proposed_by = :pb'; $args['pb'] = (int) $filters['proposed_by']; }
    }
    $st = $pdo->prepare('SELECT mp.*, pv.size_name, pv.product_id, lv.listing_id FROM mcp_match_proposals mp JOIN mcp_product_variants pv ON pv.variant_id = mp.variant_id
                           JOIN mcp_listing_variants lv ON lv.listing_variant_id = mp.listing_variant_id'
        . ($w === [] ? '' : ' WHERE ' . implode(' AND ', $w)) . ' ORDER BY mp.confidence DESC, mp.proposal_id LIMIT ' . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset));
    $st->execute($args);
    return array_map(static function (array $p): array {
        foreach (['proposal_id', 'listing_variant_id', 'variant_id', 'product_id', 'listing_id'] as $k) { $p[$k] = (int) $p[$k]; }
        $p['proposed_by'] = $p['proposed_by'] === null ? null : (int) $p['proposed_by'];
        $p['confidence'] = (float) $p['confidence'];
        $p['evidence'] = json_decode((string) $p['evidence'], true) ?: [];
        return $p;
    }, $st->fetchAll());
}

function find_proposal(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT mp.*, lv.listing_id, lv.source_id FROM mcp_match_proposals mp JOIN mcp_listing_variants lv ON lv.listing_variant_id = mp.listing_variant_id WHERE mp.proposal_id = :id');
    $st->execute(['id' => $id]);
    $p = $st->fetch();
    if ($p === false) { return null; }
    foreach (['proposal_id', 'listing_variant_id', 'variant_id', 'listing_id', 'source_id'] as $k) { $p[$k] = (int) $p[$k]; }
    $p['confidence'] = (float) $p['confidence'];
    $p['evidence'] = json_decode((string) $p['evidence'], true) ?: [];
    return $p;
}

/** The evidence in words: "brand matches · 3 of 4 name words · size matches · dims within 12 mm · type matches". */
function evidence_words(array $e): string
{
    $out = [];
    if (($e['brand'] ?? null) === true) { $out[] = 'brand matches'; } elseif (($e['brand'] ?? null) === 'in_title') { $out[] = 'brand in the title'; }
    if (isset($e['name_tokens'])) {
        $n = count((array) $e['name_tokens']);
        $frac = (float) ($e['name_fraction'] ?? 0);
        $of = $frac > 0 ? (int) round($n / $frac) : $n;
        $out[] = $n . ' of ' . max($n, $of) . ' name word' . ($of === 1 ? '' : 's');
    }
    if (!empty($e['size'])) { $out[] = 'size matches'; }
    if (isset($e['dims_mm'])) { $out[] = 'dims within ' . (int) $e['dims_mm'] . ' mm'; }
    if (!empty($e['type'])) { $out[] = 'type matches'; }
    foreach (array_diff_key($e, array_flip(['brand', 'name_tokens', 'name_fraction', 'size', 'dims_mm', 'type'])) as $k => $v) { $out[] = str_replace('_', ' ', (string) $k) . (is_scalar($v) && $v !== true ? ' ' . $v : ''); }
    return $out === [] ? 'no evidence recorded' : implode(' · ', $out);
}

/**
 * The agent's rule for listing_match (sources.md "The handlers' rules"): null when the pair shares an identifier (a) — the GTIN, the supplier's
 * SKU, or the MPN with equal sizes; 'proposal:<id>' when a person's pending proposal names the pair (b); else the refusal's sentence.
 */
function agent_may_match(PDO $pdo, int $lvId, int $variantId): ?string
{
    $st = $pdo->prepare("SELECT lv.barcode, lv.barcode_valid, lv.sku, lv.mpn, lv.size_key, s.supplier_id, s.id AS source_id
                           FROM listing_variants lv JOIN listings l ON l.id = lv.listing_id JOIN sources s ON s.id = l.source_id WHERE lv.id = :lv");
    $st->execute(['lv' => $lvId]);
    $lv = $st->fetch();
    $v = $pdo->prepare('SELECT barcode, mpn, size_key FROM product_variants WHERE id = :v');
    $v->execute(['v' => $variantId]);
    $pv = $v->fetch();
    if ($lv === false || $pv === false) { return 'Not found.'; }
    $ident = static function (array $kinds, string $value) use ($pdo, $variantId): bool {
        $q = $pdo->prepare('SELECT 1 FROM variant_identifiers WHERE variant_id = :v AND kind = ANY (CAST(:k AS text[])) AND lower(value) = lower(:x)');
        $q->execute(['v' => $variantId, 'k' => pg_array_literal($kinds), 'x' => $value]);
        return $q->fetchColumn() !== false;
    };
    if ($lv['barcode_valid'] && $lv['barcode'] !== null && ($lv['barcode'] === $pv['barcode'] || $ident(['gtin', 'upc', 'ean'], (string) $lv['barcode']))) { return null; }
    if ($lv['sku'] !== null) {
        $si = $pdo->prepare('SELECT 1 FROM supplier_items WHERE variant_id = :v AND supplier_id = :s AND lower(supplier_sku) = lower(:x)');
        $si->execute(['v' => $variantId, 's' => $lv['supplier_id'], 'x' => $lv['sku']]);
        if ($lv['supplier_id'] !== null && $si->fetchColumn() !== false) { return null; }
        $q = $pdo->prepare("SELECT 1 FROM variant_identifiers WHERE variant_id = :v AND kind = 'supplier_sku' AND source_id = :s AND lower(value) = lower(:x)");
        $q->execute(['v' => $variantId, 's' => $lv['source_id'], 'x' => $lv['sku']]);
        if ($q->fetchColumn() !== false) { return null; }
    }
    if ($lv['mpn'] !== null && $lv['size_key'] !== null && $lv['size_key'] === $pv['size_key']
        && (strtolower((string) $pv['mpn']) === strtolower((string) $lv['mpn']) || $ident(['mpn'], (string) $lv['mpn']))) { return null; }
    $p = $pdo->prepare("SELECT mp.id FROM match_proposals mp JOIN members m ON m.id = mp.proposed_by WHERE mp.listing_variant_id = :lv AND mp.variant_id = :v AND mp.status = 'proposed' AND m.member_kind = 'human'");
    $p->execute(['lv' => $lvId, 'v' => $variantId]);
    $pid = $p->fetchColumn();
    if ($pid !== false) { return 'proposal:' . (int) $pid; }
    return 'An agent matches only by identifier or a person\'s proposal — propose it (match_propose)';
}

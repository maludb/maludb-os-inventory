<?php
declare(strict_types=1);
/**
 * Action `source_search` (log `source.search` per source asked: pull_id, query ≤ 120, size, found, ms, eval): ask the sources now — `q` 2–120, `size`,
 * `source` (one); else every active source whose connector searches, not paused, not backing off, at most 5, in sequence, 30 s in all. Each asked
 * live through inv_source_search_live() (a `search` pull that writes listings like a pull); the answer {query, size, asked[], rows[]}. Under an
 * eval run nothing persists. orders.write (design A8).
 */
require_once dirname(__DIR__, 2) . '/app/features/sources/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/listings/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/listings/present.php';
source_write_begin('orders.write');
$pdo = db();
$q = trim((string) (req_val('q') ?? ''));
if (mb_strlen($q) < 2 || mb_strlen($q) > 120) { inv_refuse_fields(['q' => 'Ask for 2 to 120 characters.']); }
$size = trim((string) (req_val('size') ?? '')) ?: null;
$isEval = !empty($GLOBALS['__run_facts']['is_eval']);
if (!$isEval && current_agent_run_id() !== null) {                          // the facts are fetched on an agent's first admission only — ask the kernel for this run's
    $isEval = !empty((run_facts((string) ($GLOBALS['__action_token'] ?? '')) ?? [])['is_eval']);
}
$only = request_integer('source');
if ($only !== null) {
    $ids = find_source($pdo, $only) === null ? refuse(404, 'Source not found.') : [$only];
} else {
    $ids = [];
    foreach (find_sources($pdo, []) as $s) {
        $caps = inv_connectors()[$s['connector']]['capabilities'] ?? [];
        if (!empty($caps['has_search']) && $s['paused_at'] === null && ($s['backoff_until'] === null || strtotime((string) $s['backoff_until']) <= time())) { $ids[] = $s['source_id']; }
        if (count($ids) >= 5) { break; }
    }
}
$asked = [];
$listingIds = [];
$started = microtime(true);
foreach ($ids as $sid) {
    $name = (string) one_value($pdo, 'SELECT name FROM mcp_sources WHERE source_id = :s', ['s' => $sid]);
    if (microtime(true) - $started > 30) { $asked[] = ['source_id' => $sid, 'source' => $name, 'status' => 'timeout', 'pull_id' => null, 'listings_seen' => 0, 'listings_new' => 0, 'ms' => 0, 'error' => 'not reached in 30 s']; continue; }
    $t0 = microtime(true);
    $r = inv_source_search_live($pdo, $sid, $q, 10, (int) current_member_id(), !$isEval);
    $ms = (int) round((microtime(true) - $t0) * 1000);
    $new = ($r['pull_id'] ?? null) !== null ? (int) one_value($pdo, 'SELECT listings_new FROM source_pulls WHERE id = :p', ['p' => $r['pull_id']]) : 0;
    $asked[] = ['source_id' => $sid, 'source' => $name, 'status' => $r['ok'] ? 'ok' : ($r['reason'] ?? 'refused'), 'pull_id' => $r['pull_id'] ?? null, 'listings_seen' => count($r['listings'] ?? []),
                'listings_new' => $new, 'ms' => $ms, 'error' => $r['ok'] ? null : ($r['reason'] ?? null)];
    $listingIds = array_merge($listingIds, $r['listing_ids'] ?? []);
    source_log($pdo, 'source.search', 'source', $sid, $sid, ['pull_id' => $r['pull_id'] ?? null, 'query' => mb_substr($q, 0, 120), 'size' => $size, 'found' => count($r['listings'] ?? []), 'ms' => $ms, 'eval' => $isEval]);
}
$rows = [];
if ($listingIds !== []) {
    $lvs = $pdo->query('SELECT * FROM mcp_listing_variants WHERE listing_id IN (' . implode(',', array_map('intval', array_unique($listingIds))) . ') AND removed_at IS NULL ORDER BY listing_variant_id')->fetchAll();
    if ($size !== null) {                                   // a size narrows the rows (a listing variant of another size never answers)
        $want = strtolower(str_replace([' ', '-'], '_', $size));
        $lvs = array_values(array_filter($lvs, static fn ($lv) => $lv['size_key'] === null || $lv['size_key'] === $want || strtolower((string) $lv['size_name']) === strtolower($size)));
    }
    $seen = [];
    foreach ($lvs as $lv) {
        if ($lv['variant_id'] !== null) {
            if (isset($seen[$lv['variant_id']])) { continue; }
            $seen[$lv['variant_id']] = true;
            $rows[] = ['kind' => 'matched', 'variant_id' => (int) $lv['variant_id'], 'availability' => json_decode((string) one_value($pdo, 'SELECT inv_availability(:v)', ['v' => (int) $lv['variant_id']]), true)];
        } else {
            $rows[] = ['kind' => 'unmatched', 'listing_variant' => present_listing_variant(listing_variant_decode($lv + ['our_sku' => null]))];
        }
    }
}
$answer = ['query' => $q, 'size' => $size, 'asked' => $asked, 'rows' => $rows, 'eval' => $isEval];
emit_action_status(true, ['did' => 'Asked ' . count($asked) . ' source' . (count($asked) === 1 ? '' : 's') . ' for "' . $q . '"', 'record_id' => $asked[0]['pull_id'] ?? null] + $answer);
if (wants_json()) { respond_saved(['did' => 'Asked ' . count($asked) . ' sources', 'record_id' => $asked[0]['pull_id'] ?? null] + $answer); }
echo view('sources/partials/search-result.php', ['answer' => $answer]);

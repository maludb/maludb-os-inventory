<?php
declare(strict_types=1);

/** Listings' JSON shapes (sources.md). Cost is walled by the views; raw is null below listings.match. */

function present_listing(array $l): array
{
    return ['listing_id' => $l['listing_id'], 'source_id' => $l['source_id'], 'source_name' => $l['source_name'], 'external_id' => $l['external_id'], 'handle' => $l['handle'], 'url' => $l['url'],
            'title' => $l['title'], 'vendor' => $l['vendor'], 'product_type' => $l['product_type'], 'tags' => $l['tags'], 'product_id' => $l['product_id'], 'first_seen_at' => json_ts($l['first_seen_at']),
            'last_seen_at' => json_ts($l['last_seen_at']), 'removed_at' => json_ts($l['removed_at']), 'forgotten_at' => json_ts($l['forgotten_at']), 'variant_count' => $l['variant_count'],
            'matched_count' => $l['matched_count'], 'variants' => isset($l['variants']) ? array_map('present_listing_variant', $l['variants']) : null];
}

function present_listing_variant(array $v): array
{
    return ['listing_variant_id' => $v['listing_variant_id'], 'listing_id' => $v['listing_id'], 'external_variant_id' => $v['external_variant_id'], 'title' => $v['title'], 'option_values' => $v['option_values'],
            'size_key' => $v['size_key'], 'size_name' => $v['size_name'], 'sku' => $v['sku'], 'barcode' => $v['barcode'], 'barcode_valid' => $v['barcode_valid'], 'mpn' => $v['mpn'],
            'price' => $v['price'], 'compare_at_price' => $v['compare_at_price'], 'currency' => $v['currency'], 'cost_price' => $v['cost_price'], 'cost_withheld' => $v['cost_withheld'],
            'availability' => $v['availability'], 'qty' => $v['qty'], 'lead_time_days' => $v['lead_time_days'], 'ships_how' => $v['ships_how'], 'url' => $v['url'],
            'variant_id' => $v['variant_id'], 'our_sku' => $v['our_sku'] ?? null, 'match_kind' => $v['match_kind'], 'match_confidence' => $v['match_confidence'], 'matched_by' => $v['matched_by'],
            'matched_by_name' => $v['matched_by_name'] ?? null, 'matched_at' => json_ts($v['matched_at']), 'removed_at' => json_ts($v['removed_at']), 'last_seen_at' => json_ts($v['last_seen_at'])];
}

function present_proposal(array $p): array
{
    return ['proposal_id' => $p['proposal_id'], 'listing_variant_id' => $p['listing_variant_id'], 'variant_id' => $p['variant_id'], 'sku' => $p['sku'], 'product_name' => $p['product_name'],
            'confidence' => $p['confidence'], 'evidence' => $p['evidence'], 'evidence_words' => evidence_words($p['evidence']), 'proposed_by' => $p['proposed_by'], 'proposed_by_name' => $p['proposed_by_name'] ?? null,
            'status' => $p['status']];
}

function present_offer_point(array $r): array
{
    return ['observed_at' => json_ts($r['observed_at']), 'price' => $r['price'], 'compare_at_price' => $r['compare_at_price'], 'cost_price' => $r['cost_price'], 'availability' => $r['availability'],
            'qty' => $r['qty'] === null ? null : (int) $r['qty'], 'lead_time_days' => $r['lead_time_days'] === null ? null : (int) $r['lead_time_days'], 'is_heartbeat' => (bool) $r['is_heartbeat'],
            'pull_id' => $r['pull_id'] === null ? null : (int) $r['pull_id']];
}

/** A walled cost cell: the amount, or "—" titled "cost withheld". */
function cost_cell_src(?string $amount, bool $sees): string
{
    if (!$sees) { return '<span class="text-muted" title="cost withheld">—</span>'; }
    return $amount === null ? '' : e(number_format((float) $amount, 2));
}

/** A confidence bar (0–1). */
function confidence_bar(float $c, string $id = ''): string
{
    $pct = (int) round($c * 100);
    $cls = $c >= 0.8 ? 'bg-success' : ($c >= 0.6 ? 'bg-info' : 'bg-warning');
    return '<div class="d-flex align-items-center gap-1"' . ($id !== '' ? ' id="' . e($id) . '"' : '') . '><div class="progress flex-grow-1" style="height:6px;min-width:3rem" role="progressbar" aria-valuenow="' . $pct . '" aria-valuemin="0" aria-valuemax="100" aria-label="confidence"><div class="progress-bar ' . $cls . '" style="width:' . $pct . '%"></div></div><span class="fs-11">' . number_format($c, 2) . '</span></div>';
}

const LISTING_NOTICES = ['matched' => ['success', 'Matched.'], 'unmatched' => ['success', 'Unmatched — the listing is in the queue again.'], 'proposed' => ['success', 'Proposed.'],
    'scored' => ['success', 'Scored again — the matcher\'s proposals are below.'], 'accepted' => ['success', 'Accepted — matched.'], 'dismissed' => ['success', 'Dismissed.'],
    'forgotten' => ['success', 'Marked not ours — it leaves the queue and is never matched or scored again.']];

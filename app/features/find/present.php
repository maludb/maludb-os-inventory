<?php
declare(strict_types=1);

/** Find's presenters (find.md "Query functions", present.php): the chip values to inv_find()'s filters, the state words, the recommended fulfilment, the picker's radios. */

const FIND_STATES = ['in_stock' => ['In stock', 'success'], 'from_supplier' => ['From supplier', 'info'], 'back_order' => ['Back order', 'warning'], 'unavailable' => ['Unavailable', 'secondary']];

function find_state_chip(string $state, string $id = ''): string
{
    [$w, $c] = FIND_STATES[$state] ?? [$state, 'secondary'];
    return '<span class="badge bg-soft-' . $c . ' text-' . $c . '"' . ($id !== '' ? ' id="' . e($id) . '"' : '') . '>' . e($w) . '</span>';
}

/** The chip values (GET) → inv_find()'s filters object. Unknown values are ignored (a chip is a link). */
function find_filters(array $get, array $productTypes, array $firmness): array
{
    $f = [];
    $type = (string) ($get['type'] ?? '');
    foreach ($productTypes as $t) { if ($t['key'] === $type) { $f['product_type_id'] = (int) $t['product_type_id']; } }
    $firm = (string) ($get['firmness'] ?? '');
    if (in_array($firm, $firmness, true)) { $f['attributes'] = ['firmness_word' => $firm]; }
    foreach (['price_min', 'price_max'] as $k) {
        $v = (string) ($get[$k] ?? '');
        if ($v !== '' && is_numeric($v) && (float) $v >= 0) { $f[$k] = (float) $v; }
    }
    if (($get['in_stock'] ?? '') === '1') { $f['in_stock_only'] = true; }
    $sw = (int) ($get['ships_within'] ?? 0);
    if (in_array($sw, FIND_SHIPS_WITHIN, true)) { $f['max_lead_days'] = $sw; }
    return $f;
}

/** The query parameters Find keeps (its URL), in a fixed order. */
function find_params(array $get): array
{
    $out = [];
    foreach (['q', 'size', 'type', 'firmness', 'price_min', 'price_max', 'in_stock', 'ships_within'] as $k) {
        $v = trim((string) ($get[$k] ?? ''));
        if ($v !== '') { $out[$k] = $v; }
    }
    return $out;
}

/** The Find URL with one parameter set (or removed when it already holds that value — a chip toggles). */
function find_toggle(array $params, array $set): string
{
    foreach ($set as $k => $v) {
        if ($v === null || (isset($params[$k]) && (string) $params[$k] === (string) $v)) { unset($params[$k]); } else { $params[$k] = (string) $v; }
    }
    return '/find' . ($params === [] ? '' : '?' . http_build_query($params));
}

/** The price band (1–4) the params hold, or 0. */
function find_band(array $params): int
{
    foreach (FIND_PRICE_BANDS as $n => [$min, $max]) {
        if (($params['price_min'] ?? null) === $min && ($params['price_max'] ?? null) === $max) { return $n; }
    }
    return 0;
}

/** inv_find()'s compact facts in words: "3 available · best lead 0 days" / "from Malouf in 5 days · in stock" / "back order" / "unavailable". */
function find_compact_facts(array $r): string
{
    if ($r['kind'] === 'bundle') { return $r['own_available'] > 0 ? $r['own_available'] . ' sets from stock' : ($r['best_lead_time_days'] !== null ? 'sets in ' . $r['best_lead_time_days'] . ' days' : 'unavailable'); }
    return match ($r['state']) {
        'in_stock' => $r['own_available'] . ' available · best lead 0 days',
        'from_supplier' => 'from ' . ($r['best_offer']['supplier'] ?? $r['best_offer']['source'] ?? 'a supplier') . ' in ' . ($r['best_offer']['lead_time_days'] ?? '?') . ' days · ' . str_replace('_', ' ', (string) ($r['best_offer']['availability'] ?? '')),
        'back_order' => 'back order',
        default => 'unavailable',
    };
}

/** "12 min ago", "3 days ago"; the date beyond 7 days. */
function as_of_words(?string $ts, string $tz): string
{
    if ($ts === null) { return ''; }
    return time() - (int) strtotime($ts) > 7 * 86400 ? format_ts($ts, $tz, 'M j, Y') : ago($ts);
}

/**
 * The recommended fulfilment of a variant's availability for qty (find.md): in stock → stock at the sellable location with the most available
 * (≥ qty, else the first sellable with any); from a supplier or back order → dropship with the rank-1 offer not removed; unavailable → backorder.
 * A bundle → stock at its first component's best location, else backorder. Returns ['kind', 'location_id', 'listing_variant_id'].
 */
function recommended_fulfilment(array $a, int $qty = 1): array
{
    $none = ['kind' => 'backorder', 'location_id' => null, 'listing_variant_id' => null];
    if (isset($a['components'])) {
        return ($a['sets_available'] ?? 0) > 0 ? ['kind' => 'stock', 'location_id' => null, 'listing_variant_id' => null] : $none;
    }
    $state = $a['state'] ?? 'unavailable';
    if ($state === 'in_stock') {
        $own = array_values(array_filter($a['own'] ?? [], static fn ($o) => !empty($o['sellable']) && (int) $o['available'] > 0));
        usort($own, static fn ($x, $y) => (int) $y['available'] <=> (int) $x['available']);
        $pick = null;
        foreach ($own as $o) { if ((int) $o['available'] >= $qty) { $pick = $o; break; } }
        $pick ??= $own[0] ?? null;
        if ($pick !== null) { return ['kind' => 'stock', 'location_id' => (int) $pick['location_id'], 'listing_variant_id' => null]; }
    }
    if (in_array($state, ['in_stock', 'from_supplier', 'back_order'], true)) {
        foreach ($a['offers'] ?? [] as $o) {
            if (empty($o['removed'])) { return ['kind' => 'dropship', 'location_id' => null, 'listing_variant_id' => (int) $o['listing_variant_id']]; }
        }
    }
    return $none;
}

/** The recommended fulfilment of an inv_find() row (the card): in stock → stock at the location best_stock_locations() named; else as recommended_fulfilment(). */
function card_fulfilment(array $r, ?int $location): array
{
    if ($r['state'] === 'in_stock' && $location !== null) { return ['kind' => 'stock', 'location_id' => $location, 'listing_variant_id' => null]; }
    if ($r['kind'] === 'bundle') { return ['kind' => 'backorder', 'location_id' => null, 'listing_variant_id' => null]; }
    return recommended_fulfilment(['state' => $r['state'] === 'in_stock' ? 'from_supplier' : $r['state'], 'offers' => $r['best_offer'] ? [$r['best_offer']] : []]);
}

/** "Sell this": /orders/new with the recommended fulfilment. */
function sell_url(int $variantId, array $rec): string
{
    $q = ['variant' => $variantId, 'qty' => 1, 'fulfilment' => $rec['kind']];
    if ($rec['location_id'] !== null) { $q['location'] = $rec['location_id']; }
    if ($rec['listing_variant_id'] !== null) { $q['listing_variant'] = $rec['listing_variant_id']; }
    return '/orders/new?' . http_build_query($q);
}

/**
 * The compact shape's radios (the order form's picker per line): stock:{location} per sellable location with available ≥ qty; pickup:{location} for the
 * same; dropship:{listing_variant} per supplier offer not removed with availability in in_stock / limited / pre_order / back_order; backorder last.
 * Each: ['value', 'kind', 'id_suffix', 'label', 'stale', 'cost'].
 */
function picker_choices(array $a, int $qty, array $locations): array
{
    $out = [];
    $stockLocs = array_values(array_filter($a['own'] ?? [], static fn ($o) => !empty($o['sellable']) && (int) $o['available'] >= $qty));
    foreach ($stockLocs as $o) {
        $out[] = ['value' => 'stock:' . (int) $o['location_id'], 'kind' => 'stock', 'id_suffix' => 'stock-' . (int) $o['location_id'], 'label' => $o['location'] . ' — ' . (int) $o['available'] . ' available', 'stale' => false, 'cost' => null];
    }
    foreach ($stockLocs as $o) {
        $out[] = ['value' => 'pickup:' . (int) $o['location_id'], 'kind' => 'pickup', 'id_suffix' => 'pickup-' . (int) $o['location_id'], 'label' => 'Pickup at ' . $o['location'], 'stale' => false, 'cost' => null];
    }
    foreach ($a['offers'] ?? [] as $o) {
        if (!empty($o['removed']) || !in_array($o['availability'], ['in_stock', 'limited', 'pre_order', 'back_order'], true)) { continue; }
        $out[] = ['value' => 'dropship:' . (int) $o['listing_variant_id'], 'kind' => 'dropship', 'id_suffix' => 'dropship-' . (int) $o['listing_variant_id'],
                  'label' => ($o['supplier'] ?? $o['source']) . ' · ' . str_replace('_', ' ', (string) $o['availability']) . ($o['lead_time_days'] !== null ? ' · ' . (int) $o['lead_time_days'] . ' days' : ''),
                  'stale' => !empty($o['stale']), 'cost' => $o['cost'] ?? null];
    }
    $out[] = ['value' => 'backorder', 'kind' => 'backorder', 'id_suffix' => 'backorder', 'label' => 'Back order', 'stale' => false, 'cost' => null];
    return $out;
}

/** The value of the recommended choice among the radios. */
function recommended_value(array $rec, array $choices): string
{
    $want = match ($rec['kind']) { 'stock' => 'stock:' . $rec['location_id'], 'dropship' => 'dropship:' . $rec['listing_variant_id'], default => 'backorder' };
    foreach ($choices as $c) { if ($c['value'] === $want) { return $want; } }
    foreach ($choices as $c) { if ($c['kind'] === 'dropship') { return $c['value']; } }      // qty beyond every location: the first offer
    return 'backorder';
}

/** A field prefix as an id slug: lines[2] → lines-2. */
function field_slug(string $prefix): string
{
    return trim((string) preg_replace('/[\[\]]+/', '-', $prefix), '-');
}

/**
 * The compact picker for one line as HTML (find.md; the order form's picker per line): the radios named by $field, the recommended one checked unless $choose (a value such as stock:12) is
 * among them, the promise line beneath. $store preselects a back order's location. Null when the variant is not there. html/find/availability.php and the order screens (server-side include) share it.
 */
function compact_picker_html(PDO $pdo, int $vid, int $qty, string $field, ?string $choose = null, ?int $store = null): ?string
{
    $a = variant_availability($pdo, $vid);
    if ($a === []) { return null; }
    $locations = sellable_locations($pdo);
    $choices = isset($a['components']) ? [] : picker_choices($a, $qty, $locations);
    $checked = isset($a['components']) ? '' : recommended_value(recommended_fulfilment($a, $qty), $choices);
    foreach ($choices as $c) { if ($choose !== null && $choose !== '' && $c['value'] === $choose) { $checked = $choose; } }
    return view('find/partials/availability-compact.php', ['a' => $a, 'vid' => $vid, 'qty' => $qty, 'field' => $field, 'slug' => field_slug($field), 'choices' => $choices, 'checked' => $checked, 'locations' => $locations,
        'store' => $store, 'atp' => isset($a['components']) ? null : variant_atp($pdo, $vid, $qty), 'seesCost' => sees_cost()]);
}

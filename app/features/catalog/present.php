<?php
declare(strict_types=1);

/** The catalog's JSON shapes (whitelists over the view rows — cost is already nulled by the view) and the display helpers the views share. */

function present_product(array $p): array
{
    return ['product_id' => (int) $p['product_id'], 'name' => $p['name'], 'brand_id' => $p['brand_id'] === null ? null : (int) $p['brand_id'], 'brand' => $p['brand'],
            'product_type_id' => (int) $p['product_type_id'], 'product_type' => $p['product_type'], 'description' => $p['description'], 'attributes' => (object) ($p['attributes'] ?? []),
            'kind' => $p['kind'], 'status' => $p['status'], 'options' => array_values($p['options'] ?? []), 'reorder_point' => $p['reorder_point'] === null ? null : (int) $p['reorder_point'],
            'ships_how' => $p['ships_how'], 'tags' => array_values($p['tags'] ?? []), 'variant_count' => (int) ($p['variant_count'] ?? 0),
            'primary_image_attachment_id' => isset($p['primary_image_attachment_id']) ? (int) $p['primary_image_attachment_id'] : null,
            'qty_on_hand' => isset($p['qty_on_hand']) ? (int) $p['qty_on_hand'] : null, 'qty_available' => isset($p['qty_available']) ? (int) $p['qty_available'] : null,
            'state' => $p['state'] ?? null, 'discontinued_at' => json_ts($p['discontinued_at'] ?? null), 'created_at' => json_ts($p['created_at'] ?? null), 'updated_at' => json_ts($p['updated_at'] ?? null),
            'label' => $p['name'] . ($p['brand'] ? ' (' . $p['brand'] . ')' : '')];
}

function present_variant(array $v): array
{
    $out = ['variant_id' => (int) $v['variant_id'], 'product_id' => (int) $v['product_id'], 'product_name' => $v['product_name'] ?? null, 'brand' => $v['brand'] ?? null, 'kind' => $v['kind'] ?? null,
            'sku' => $v['sku'], 'option_values' => (object) ($v['option_values'] ?? []), 'size_key' => $v['size_key'], 'size_name' => $v['size_name'] ?? null, 'barcode' => $v['barcode'], 'mpn' => $v['mpn'],
            'weight_g' => $v['weight_g'] === null ? null : (int) $v['weight_g'], 'length_mm' => $v['length_mm'] === null ? null : (int) $v['length_mm'], 'width_mm' => $v['width_mm'] === null ? null : (int) $v['width_mm'],
            'height_mm' => $v['height_mm'] === null ? null : (int) $v['height_mm'], 'ships_how' => $v['ships_how'], 'retail_price' => $v['retail_price'], 'map_price' => $v['map_price'],
            'cost_price' => $v['cost_price'], 'cost_withheld' => (bool) $v['cost_withheld'], 'cost_updated_at' => json_ts($v['cost_updated_at'] ?? null),
            'reorder_point' => $v['reorder_point'] === null ? null : (int) $v['reorder_point'], 'reorder_qty' => $v['reorder_qty'] === null ? null : (int) $v['reorder_qty'],
            'active' => (bool) $v['active'], 'qty_on_hand' => (int) ($v['qty_on_hand'] ?? 0), 'qty_available' => (int) ($v['qty_available'] ?? 0),
            'created_at' => json_ts($v['created_at'] ?? null), 'updated_at' => json_ts($v['updated_at'] ?? null)];
    foreach (['best_offer', 'best_lead_time_days', 'state', 'own', 'sets_available', 'margin_pct'] as $k) {
        if (array_key_exists($k, $v)) { $out[$k] = $v[$k]; }
    }
    return $out;
}

function present_identifier(array $i): array
{
    return ['identifier_id' => (int) $i['identifier_id'], 'variant_id' => (int) $i['variant_id'], 'kind' => $i['kind'], 'value' => $i['value'], 'source_id' => $i['source_id'] === null ? null : (int) $i['source_id'],
            'source_name' => $i['source_name'] ?? null, 'created_by' => $i['created_by'] === null ? null : (int) $i['created_by'], 'created_at' => json_ts($i['created_at'] ?? null)];
}

function present_image(array $i): array
{
    return ['image_id' => (int) $i['image_id'], 'product_id' => (int) $i['product_id'], 'variant_id' => $i['variant_id'] === null ? null : (int) $i['variant_id'], 'attachment_id' => (int) $i['attachment_id'],
            'filename' => $i['filename'] ?? null, 'alt_text' => $i['alt_text'], 'is_primary' => (bool) $i['is_primary'], 'sort_order' => (int) $i['sort_order'], 'url' => '/files/' . (int) $i['attachment_id']];
}

function present_price_row(array $h): array
{
    return ['changed_at' => json_ts($h['changed_at']), 'kind' => $h['kind'], 'old_price' => $h['old_price'], 'new_price' => $h['new_price'], 'changed_by' => $h['changed_by'] === null ? null : (int) $h['changed_by'],
            'changed_by_name' => $h['changed_by_name'], 'reason' => $h['reason'], 'source_kind' => $h['source_kind']];
}

function present_brand(array $b): array
{
    return ['brand_id' => (int) $b['brand_id'], 'name' => $b['name'], 'website' => $b['website'], 'supplier_id' => $b['supplier_id'] === null ? null : (int) $b['supplier_id'], 'supplier_name' => $b['supplier_name'] ?? null,
            'active' => (bool) $b['active'], 'product_count' => (int) ($b['product_count'] ?? 0)];
}

function present_product_type(array $t): array
{
    return ['product_type_id' => (int) $t['product_type_id'], 'key' => $t['key'], 'name' => $t['name'], 'sort_order' => (int) $t['sort_order'], 'active' => (bool) $t['active'], 'product_count' => (int) ($t['product_count'] ?? 0)];
}

// ---- display helpers ----------------------------------------------------------------------------------------------------
/** A money amount: 1,295.00; '—' for null. */
function money(?string $amount, bool $withheld = false): string
{
    if ($withheld) { return '—'; }
    return $amount === null || $amount === '' ? '—' : number_format((float) $amount, 2);
}

/** A weight in the settings' units: imperial = pounds (2 dp), metric = grams. */
function weight_display(?int $g, string $units): string
{
    if ($g === null) { return ''; }
    return $units === 'metric' ? $g . ' g' : rtrim(rtrim(number_format($g / 453.592, 2, '.', ''), '0'), '.') . ' lb';
}

/** A length in the settings' units: imperial = inches (1 dp), metric = millimetres. */
function length_display(?int $mm, string $units): string
{
    if ($mm === null) { return ''; }
    return $units === 'metric' ? $mm . ' mm' : rtrim(rtrim(number_format($mm / 25.4, 1, '.', ''), '0'), '.') . ' in';
}

/** The form's value for a stored weight / length in the settings' units (no unit word). */
function weight_form_value(?int $g, string $units): string
{
    return $g === null ? '' : ($units === 'metric' ? (string) $g : number_format($g / 453.592, 2, '.', ''));
}
function length_form_value(?int $mm, string $units): string
{
    return $mm === null ? '' : ($units === 'metric' ? (string) $mm : number_format($mm / 25.4, 1, '.', ''));
}

/** Status chips (catalog.md "Status vocabulary"). */
function product_status_chip(string $status): string
{
    $c = ['draft' => 'secondary', 'active' => 'success', 'discontinued' => 'dark'][$status] ?? 'secondary';
    return '<span class="badge bg-soft-' . $c . ' text-' . $c . '">' . e(PRODUCT_STATUSES[$status] ?? $status) . '</span>';
}
function state_chip(?string $state): string
{
    if ($state === null) { return ''; }
    [$c, $w] = ['in_stock' => ['success', 'In stock'], 'from_supplier' => ['info', 'From supplier'], 'back_order' => ['warning', 'Back order'], 'unavailable' => ['danger', 'Unavailable']][$state] ?? ['secondary', $state];
    return '<span class="badge bg-soft-' . $c . ' text-' . $c . '">' . e($w) . '</span>';
}
function identifier_kind_chip(string $kind): string
{
    $c = match ($kind) { 'gtin', 'upc', 'ean' => 'primary', 'mpn' => 'secondary', 'asin', 'ebay_epid', 'walmart_item_id' => 'info', 'supplier_sku' => 'warning', default => 'light' };
    return '<span class="badge bg-soft-' . $c . ' text-' . ($c === 'light' ? 'dark' : $c) . '">' . e(IDENTIFIER_KINDS[$kind] ?? $kind) . '</span>';
}
function gap_chip(string $gap): string
{
    $c = match ($gap) { 'no_gtin', 'no_cost' => 'warning', 'no_retail', 'retail_under_map', 'empty_bundle' => 'danger', default => 'secondary' };
    return '<span class="badge bg-soft-' . $c . ' text-' . $c . '">' . e(GAP_KINDS[$gap] ?? $gap) . '</span>';
}
function availability_chip(?string $availability): string
{
    if ($availability === null) { return ''; }
    $c = match ($availability) { 'in_stock', 'limited' => 'success', 'pre_order', 'back_order' => 'warning', 'out_of_stock' => 'danger', 'discontinued' => 'dark', default => 'secondary' };   // sources.md's vocabulary of the seven states
    return '<span class="badge bg-soft-' . $c . ' text-' . $c . '">' . e(str_replace('_', ' ', $availability)) . '</span>';
}

/** The option values as words: "Queen · 3 inch". */
function option_words(array $values): string
{
    return implode(' · ', array_map(static fn ($v): string => (string) $v, array_values($values)));
}

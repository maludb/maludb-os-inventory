<?php
declare(strict_types=1);

/**
 * The ONE normalized listing shape (design §6.1) every connector returns, validated and filled with defaults.
 * Pure functions: arrays in, arrays out. Identifiers are normalized here (GTIN → 14 digits with the check digit
 * verified, SKU and MPN trimmed); MATCHING is the schema's (§6.2), never this file's.
 *
 * Listing: external_id, handle, url, title, vendor, product_type, tags[], raw{}, variants[]
 * Variant: external_variant_id, title, option_values{}, size_key, sku, barcode, mpn, price, compare_at_price, currency,
 *          cost_price, availability, qty, lead_time_days, ships_how, url
 */

/** The seven availability states of §0.3 (schema.org's, plus unknown). */
const INV_AVAILABILITY_STATES = ['in_stock', 'out_of_stock', 'pre_order', 'back_order', 'limited', 'discontinued', 'unknown'];

/** How it ships (§0.3). */
const INV_SHIPS_HOW = ['parcel', 'ltl', 'white_glove', 'pickup_only'];

/**
 * The mattress sizes (§0.3) with the words sellers use for them. DECISION: size_key is the canonical name in
 * snake_case (twin_xl, california_king); the schema's trigger on product_variants must derive the same keys from the
 * settings' synonyms so a match can compare them. Longer phrases are tried first (split california king before
 * california king before king).
 */
const INV_SIZES = [
    'split_california_king' => ['Split California King', 'split california king', 'split cal king', 'split cal-king', 'split calking', 'split ck', 'split western king'],
    'split_king' => ['Split King', 'split king', 'split eastern king', 'split standard king', 'dual king'],
    'california_king' => ['California King', 'california king', 'cal king', 'cal-king', 'calking', 'cali king', 'california-king', 'western king', 'ck', 'cal. king'],
    'olympic_queen' => ['Olympic Queen', 'olympic queen', 'expanded queen', 'olympic-queen'],
    'rv_short_queen' => ['RV Short Queen', 'rv short queen', 'short queen', 'rv queen', 'short-queen'],
    'twin_xl' => ['Twin XL', 'twin xl', 'twin extra long', 'twin x-long', 'twin-xl', 'twinxl', 'xl twin', 'txl', 'twin extra-long', 'twin long'],
    'full_xl' => ['Full XL', 'full xl', 'full extra long', 'full-xl', 'fullxl', 'xl full', 'double xl'],
    'king' => ['King', 'king', 'eastern king', 'standard king', 'east king', 'k'],
    'queen' => ['Queen', 'queen', 'q'],
    'full' => ['Full', 'full', 'double', 'full size', 'f'],
    'twin' => ['Twin', 'twin', 'single', 'twin size', 't'],
    'crib' => ['Crib', 'crib', 'toddler', 'crib/toddler', 'crib & toddler'],
];

/** Availability words (lowercased, punctuation squeezed) → state. Schema.org URLs are reduced to their last segment. */
const INV_AVAILABILITY_WORDS = [
    'in_stock' => ['instock', 'in_stock', 'in stock', 'available', 'instoreonly', 'in store only', 'onlineonly', 'online only', 'yes', 'y', 'true', 'ready to ship', 'ships today', 'in-stock', 'available now', 'readytoship'],
    'out_of_stock' => ['outofstock', 'out_of_stock', 'out of stock', 'soldout', 'sold_out', 'sold out', 'unavailable', 'no', 'n', 'false', 'not available', 'out-of-stock', 'temporarily unavailable', 'oos', 'none'],
    'pre_order' => ['preorder', 'pre_order', 'pre-order', 'pre order', 'presale', 'pre-sale', 'pre sale', 'coming soon'],
    'back_order' => ['backorder', 'back_order', 'back-order', 'back order', 'backordered', 'onbackorder', 'on backorder', 'on back order'],
    'limited' => ['limited', 'limitedavailability', 'limited availability', 'low stock', 'low_stock', 'lowstock', 'few left', 'only a few left', 'limited stock', 'almost gone'],
    'discontinued' => ['discontinued', 'retired', 'no longer available', 'end of life', 'eol', 'nla'],
];

/** The state for a seller's availability word, a boolean, or a quantity — in that order of authority. */
function inv_availability_state(mixed $word = null, ?bool $available = null, ?int $qty = null): string
{
    if (is_bool($word)) {
        $available = $word;
        $word = null;
    }
    if (is_int($word) || is_float($word)) {
        $word = (string) $word;
    }
    if (is_string($word) && trim($word) !== '') {
        $w = strtolower(trim($word));
        if (in_array($w, INV_AVAILABILITY_STATES, true)) {
            return $w;
        }
        // https://schema.org/InStock, http://schema.org/OutOfStock, schema:PreOrder
        if (preg_match('~(?:schema\.org/|schema:)(\w+)$~i', $w, $m)) {
            $w = strtolower($m[1]);
        }
        $w = preg_replace('~\s+~', ' ', str_replace(['_', '-'], ' ', $w)) ?? $w;
        if ($w === '1') {
            return 'in_stock';
        }
        if ($w === '0') {
            return 'out_of_stock';
        }
        foreach (INV_AVAILABILITY_WORDS as $state => $words) {
            foreach ($words as $known) {
                if ($w === str_replace(['_', '-'], ' ', $known) || $w === str_replace([' ', '_', '-'], '', $known)) {
                    return $state;
                }
            }
        }
        $squeezed = str_replace(' ', '', $w);
        foreach (INV_AVAILABILITY_WORDS as $state => $words) {
            foreach ($words as $known) {
                if ($squeezed === str_replace([' ', '_', '-'], '', $known)) {
                    return $state;
                }
            }
        }
        // a sentence holding one of the words ("Sold out — more on the way")
        foreach (['discontinued', 'back_order', 'pre_order', 'limited', 'out_of_stock', 'in_stock'] as $state) {
            foreach (INV_AVAILABILITY_WORDS[$state] as $known) {
                if (strlen($known) >= 5 && str_contains($w, str_replace(['_', '-'], ' ', $known))) {
                    return $state;
                }
            }
        }
    }
    if ($available !== null) {
        // DECISION: "available for sale" with a known quantity of zero is a store that continues selling when out of
        // stock (Shopify's setting) — back_order, not in_stock
        return $available ? ($qty !== null && $qty <= 0 ? 'back_order' : 'in_stock') : 'out_of_stock';
    }
    if ($qty !== null) {
        return $qty > 0 ? 'in_stock' : 'out_of_stock';
    }
    return 'unknown';
}

/** GS1 check digit (mod 10, weights 3 and 1 from the right) of the digits BEFORE it. */
function inv_gtin_check_digit(string $digits): int
{
    $sum = 0;
    $len = strlen($digits);
    for ($i = 0; $i < $len; $i++) {
        $weight = (($len - $i) % 2 === 1) ? 3 : 1;
        $sum += (int) $digits[$i] * $weight;
    }
    return (10 - ($sum % 10)) % 10;
}

/** Is this a GTIN-8/12/13/14 whose check digit holds? */
function inv_gtin_valid(string $digits): bool
{
    if (!preg_match('~^\d{8}$|^\d{12,14}$~', $digits)) {
        return false;
    }
    return inv_gtin_check_digit(substr($digits, 0, -1)) === (int) $digits[strlen($digits) - 1];
}

/** Any seller's barcode → GTIN-14 (digits only, check digit verified, left-padded), or null when it is not one. */
function inv_gtin_normalize(mixed $value): ?string
{
    if ($value === null || is_bool($value) || is_array($value)) {
        return null;
    }
    $digits = preg_replace('~\D+~', '', (string) $value) ?? '';
    if ($digits === '' || preg_match('~^0+$~', $digits)) {
        return null;
    }
    // a 14-digit GTIN with leading zeros may be a padded 12/13; a bare 11-digit UPC lost its leading zero in a spreadsheet
    if (strlen($digits) === 11) {
        $digits = '0' . $digits;
    }
    if (!inv_gtin_valid($digits)) {
        return null;
    }
    return str_pad($digits, 14, '0', STR_PAD_LEFT);
}

/** A money amount as a string with two decimals ("1299.00"), or null. Currency marks and thousands separators are dropped. */
function inv_money(mixed $value): ?string
{
    if ($value === null || $value === '' || is_bool($value) || is_array($value)) {
        return null;
    }
    if (is_int($value) || is_float($value)) {
        return $value < 0 ? null : number_format((float) $value, 2, '.', '');
    }
    $s = trim((string) $value);
    // "1.299,00" (comma decimal) vs "1,299.00" (comma thousands): the LAST separator is the decimal mark when it is followed by 1–2 digits
    if (preg_match('~^[^\d]*(\d{1,3}(?:[.,\s]\d{3})*|\d+)(?:([.,])(\d{1,2}))?\s*[^\d]*$~u', $s, $m)) {
        $whole = preg_replace('~[^\d]~', '', $m[1]) ?? '0';
        $frac = isset($m[3]) ? str_pad($m[3], 2, '0') : '00';
        return ltrim($whole, '0') === '' ? '0.' . $frac : ltrim($whole, '0') . '.' . $frac;
    }
    $clean = preg_replace('~[^\d.\-]~', '', $s) ?? '';
    if ($clean === '' || !is_numeric($clean) || (float) $clean < 0) {
        return null;
    }
    return number_format((float) $clean, 2, '.', '');
}

/** An amount in minor units ("129900", unit 2) → "1299.00". */
function inv_money_minor(mixed $minor, int $unit = 2): ?string
{
    if ($minor === null || $minor === '' || is_array($minor)) {
        return null;
    }
    $digits = preg_replace('~\D~', '', (string) $minor) ?? '';
    if ($digits === '') {
        return null;
    }
    $digits = str_pad($digits, $unit + 1, '0', STR_PAD_LEFT);
    $whole = substr($digits, 0, strlen($digits) - $unit);
    $frac = $unit > 0 ? substr($digits, -$unit) : '';
    return inv_money($whole . '.' . $frac);
}

/** An ISO 4217 code, upper-cased, or null. */
function inv_currency(mixed $code): ?string
{
    if (!is_string($code)) {
        return null;
    }
    $c = strtoupper(trim($code));
    return preg_match('~^[A-Z]{3}$~', $c) ? $c : null;
}

/** One of the four ships-how words, with a few synonyms, or null. */
function inv_ships_how(mixed $word): ?string
{
    if (!is_string($word) || trim($word) === '') {
        return null;
    }
    $w = strtolower(trim($word));
    $w = str_replace(['-', ' '], '_', $w);
    $map = [
        'parcel' => 'parcel', 'ground' => 'parcel', 'small_parcel' => 'parcel', 'ups' => 'parcel', 'fedex' => 'parcel', 'boxed' => 'parcel', 'bed_in_a_box' => 'parcel',
        'ltl' => 'ltl', 'freight' => 'ltl', 'ltl_freight' => 'ltl', 'truck' => 'ltl', 'curbside' => 'ltl', 'threshold' => 'ltl',
        'white_glove' => 'white_glove', 'whiteglove' => 'white_glove', 'in_home' => 'white_glove', 'in_home_delivery' => 'white_glove', 'room_of_choice' => 'white_glove',
        'pickup_only' => 'pickup_only', 'pickup' => 'pickup_only', 'pick_up' => 'pickup_only', 'in_store_pickup' => 'pickup_only', 'will_call' => 'pickup_only',
    ];
    return $map[$w] ?? null;
}

/** The size_key found in a text, longest synonym first, on word boundaries; null when none. */
function inv_size_key_in(?string $text): ?string
{
    if ($text === null || trim($text) === '') {
        return null;
    }
    static $patterns = null;
    if ($patterns === null) {
        $all = [];
        foreach (INV_SIZES as $key => $words) {
            foreach (array_slice($words, 1) as $w) {
                $all[] = [$key, $w];
            }
        }
        usort($all, static fn($a, $b) => strlen($b[1]) <=> strlen($a[1]));
        $patterns = [];
        foreach ($all as [$key, $w]) {
            $q = preg_quote($w, '~');
            // one-letter codes only as a whole token (option value "K"), never inside words
            $patterns[] = [$key, strlen($w) === 1 ? '~^\s*' . $q . '\s*$~i' : '~(?<![a-z0-9])' . $q . '(?![a-z0-9])~i'];
        }
    }
    $t = strtolower(trim($text));
    foreach ($patterns as [$key, $re]) {
        if (preg_match($re, $t)) {
            return $key;
        }
    }
    return null;
}

/** The size from option values (a Size-named option first, then any value), then the variant title, then the listing title. */
function inv_size_key(array $option_values = [], ?string ...$titles): ?string
{
    foreach ($option_values as $name => $value) {
        if (is_string($value) && preg_match('~size|dimension~i', (string) $name) && ($k = inv_size_key_in($value)) !== null) {
            return $k;
        }
    }
    foreach ($option_values as $value) {
        if (is_string($value) && ($k = inv_size_key_in($value)) !== null) {
            return $k;
        }
    }
    foreach ($titles as $t) {
        if (($k = inv_size_key_in($t)) !== null) {
            return $k;
        }
    }
    return null;
}

/** The display name of a size_key ("California King"). */
function inv_size_label(?string $key): ?string
{
    return $key !== null && isset(INV_SIZES[$key]) ? INV_SIZES[$key][0] : null;
}

/** A trimmed string or null; never an array. */
function inv_str(mixed $v, int $max = 500): ?string
{
    if ($v === null || is_array($v) || is_bool($v)) {
        return null;
    }
    $s = trim((string) $v);
    if ($s === '') {
        return null;
    }
    return mb_substr($s, 0, $max);
}

/** A non-negative integer or null. */
/** A connector value as an integer, or null (named apart from the kit's inv_int() form reader — the two load together since slice 1). */
function inv_norm_int(mixed $v): ?int
{
    if ($v === null || $v === '' || is_bool($v) || is_array($v)) {
        return null;
    }
    if (is_string($v)) {
        $v = trim($v);
        if (!is_numeric($v)) {
            return null;
        }
    }
    $i = (int) round((float) $v);
    return $i < 0 ? 0 : $i;
}

/** Tags from an array or a comma-separated string, trimmed, unique, in order. */
function inv_tags(mixed $tags): array
{
    if (is_string($tags)) {
        $tags = explode(',', $tags);
    }
    if (!is_array($tags)) {
        return [];
    }
    $out = [];
    foreach ($tags as $t) {
        if (is_array($t)) {
            $t = $t['name'] ?? ($t['slug'] ?? null);
        }
        $s = inv_str($t, 100);
        if ($s !== null && !in_array($s, $out, true)) {
            $out[] = $s;
        }
    }
    return $out;
}

/** The option values as name → string value, dropping empties. */
function inv_option_values(mixed $values): array
{
    if (!is_array($values)) {
        return [];
    }
    $out = [];
    foreach ($values as $name => $value) {
        if (is_array($value)) {
            if (isset($value['name'], $value['value'])) { // [{name, value}] lists
                $name = $value['name'];
                $value = $value['value'];
            } else {
                continue;
            }
        }
        $n = inv_str($name, 100);
        $v = inv_str($value, 200);
        if ($n !== null && $v !== null) {
            $out[$n] = $v;
        }
    }
    return $out;
}

/** One variant, validated. $listing is the (partly) normalized parent for defaults. */
function inv_normalize_variant(array $v, array $listing, int $index): array
{
    $options = inv_option_values($v['option_values'] ?? []);
    $price = inv_money($v['price'] ?? null);
    $compare = inv_money($v['compare_at_price'] ?? null);
    // DECISION: a compare-at that is not above the price is noise (Shopify stores often fill it equal) — dropped
    if ($compare !== null && $price !== null && (float) $compare <= (float) $price) {
        $compare = null;
    }
    $qty = inv_norm_int($v['qty'] ?? null);
    $available = $v['available'] ?? null;
    $available = is_bool($available) ? $available : (is_string($available) && $available !== '' ? inv_availability_state($available) === 'in_stock' : null);
    $title = inv_str($v['title'] ?? null, 300) ?? ($options !== [] ? implode(' / ', $options) : (string) $listing['title']);
    return [
        'external_variant_id' => inv_str($v['external_variant_id'] ?? null, 200) ?? ($listing['external_id'] . ':' . ($index + 1)),
        'title' => $title,
        'option_values' => $options,
        'size_key' => inv_str($v['size_key'] ?? null, 50) ?? inv_size_key($options, $title, $listing['title']),
        'sku' => inv_str($v['sku'] ?? null, 100),
        'barcode' => inv_gtin_normalize($v['barcode'] ?? ($v['gtin'] ?? null)),
        'mpn' => inv_str($v['mpn'] ?? null, 100),
        'price' => $price,
        'compare_at_price' => $compare,
        'currency' => inv_currency($v['currency'] ?? ($listing['currency'] ?? null)),
        'cost_price' => inv_money($v['cost_price'] ?? null),
        'availability' => inv_availability_state($v['availability'] ?? null, $available, $qty),
        'qty' => $qty,
        'lead_time_days' => inv_norm_int($v['lead_time_days'] ?? null),
        'ships_how' => inv_ships_how($v['ships_how'] ?? null),
        'url' => inv_str($v['url'] ?? null, 1000) ?? $listing['url'],
    ];
}

/**
 * The normalized listing: every key present, defaults filled, identifiers normalized. A listing with no variants gets
 * ONE variant from its own fields (price, sku, barcode, availability on the listing level) so every listing has a
 * sellable unit. `currency` on the input listing is a default for its variants (not a listing field in §6.1).
 */
function inv_normalize_listing(array $in): array
{
    $title = inv_str($in['title'] ?? null, 300) ?? '';
    $url = inv_str($in['url'] ?? null, 1000);
    $handle = inv_str($in['handle'] ?? null, 200);
    $external = inv_str($in['external_id'] ?? null, 200) ?? $handle ?? ($url !== null ? substr(sha1($url), 0, 16) : null);
    if ($external === null) {
        throw new InvalidArgumentException('a listing needs an external_id, a handle or a url');
    }
    $listing = [
        'external_id' => $external,
        'handle' => $handle,
        'url' => $url,
        'title' => $title,
        'vendor' => inv_str($in['vendor'] ?? null, 200),
        'product_type' => inv_str($in['product_type'] ?? null, 200),
        'tags' => inv_tags($in['tags'] ?? []),
        // DECISION: images are not a §6.1 field; connectors put up to five image URLs in raw.images
        'raw' => is_array($in['raw'] ?? null) ? $in['raw'] : [],
        'currency' => inv_currency($in['currency'] ?? null),
        'variants' => [],
    ];
    $variants = is_array($in['variants'] ?? null) ? array_values($in['variants']) : [];
    if ($variants === []) {
        $variants = [[
            'external_variant_id' => $external . ':1',
            'title' => $title,
            'sku' => $in['sku'] ?? null, 'barcode' => $in['barcode'] ?? ($in['gtin'] ?? null), 'mpn' => $in['mpn'] ?? null,
            'price' => $in['price'] ?? null, 'compare_at_price' => $in['compare_at_price'] ?? null,
            'cost_price' => $in['cost_price'] ?? null, 'availability' => $in['availability'] ?? null,
            'available' => $in['available'] ?? null, 'qty' => $in['qty'] ?? null,
            'lead_time_days' => $in['lead_time_days'] ?? null, 'ships_how' => $in['ships_how'] ?? null,
            'option_values' => $in['option_values'] ?? [], 'size_key' => $in['size_key'] ?? null,
        ]];
    }
    $seen = [];
    foreach ($variants as $i => $v) {
        if (!is_array($v)) {
            continue;
        }
        $nv = inv_normalize_variant($v, $listing, $i);
        if (isset($seen[$nv['external_variant_id']])) {
            $nv['external_variant_id'] .= ':' . ($i + 1);
        }
        $seen[$nv['external_variant_id']] = true;
        $listing['variants'][] = $nv;
    }
    unset($listing['currency']);
    return $listing;
}

/** True when $listing has the shape (every listing and variant key, the states in range) — for proofs and the worker's guard. */
function inv_listing_valid(array $listing, ?string &$why = null): bool
{
    foreach (['external_id', 'handle', 'url', 'title', 'vendor', 'product_type', 'tags', 'raw', 'variants'] as $k) {
        if (!array_key_exists($k, $listing)) {
            $why = "missing $k";
            return false;
        }
    }
    if (count($listing) !== 9) {
        $why = 'extra listing keys: ' . implode(',', array_diff(array_keys($listing), ['external_id', 'handle', 'url', 'title', 'vendor', 'product_type', 'tags', 'raw', 'variants']));
        return false;
    }
    if ($listing['variants'] === []) {
        $why = 'no variants';
        return false;
    }
    $vkeys = ['external_variant_id', 'title', 'option_values', 'size_key', 'sku', 'barcode', 'mpn', 'price', 'compare_at_price', 'currency', 'cost_price', 'availability', 'qty', 'lead_time_days', 'ships_how', 'url'];
    foreach ($listing['variants'] as $i => $v) {
        foreach ($vkeys as $k) {
            if (!array_key_exists($k, $v)) {
                $why = "variant $i missing $k";
                return false;
            }
        }
        if (count($v) !== count($vkeys)) {
            $why = "variant $i has extra keys";
            return false;
        }
        if (!in_array($v['availability'], INV_AVAILABILITY_STATES, true)) {
            $why = "variant $i availability {$v['availability']}";
            return false;
        }
        if ($v['barcode'] !== null && !preg_match('~^\d{14}$~', $v['barcode'])) {
            $why = "variant $i barcode";
            return false;
        }
        foreach (['price', 'compare_at_price', 'cost_price'] as $m) {
            if ($v[$m] !== null && !preg_match('~^\d+\.\d{2}$~', $v[$m])) {
                $why = "variant $i $m {$v[$m]}";
                return false;
            }
        }
        if ($v['ships_how'] !== null && !in_array($v['ships_how'], INV_SHIPS_HOW, true)) {
            $why = "variant $i ships_how";
            return false;
        }
        if ($v['size_key'] !== null && !isset(INV_SIZES[$v['size_key']])) {
            $why = "variant $i size_key {$v['size_key']}";
            return false;
        }
    }
    return true;
}

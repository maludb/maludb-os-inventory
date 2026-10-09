<?php
declare(strict_types=1);

/**
 * The catalog's handler prelude (catalog.md): the gate every catalog screen and write starts with, the request readers that turn a form
 * (or an agent's JSON) into the fields save_product() / save_variant() take — "a field left out stays as it was" —, the unit conversions
 * (imperial inputs to g / mm, DECISION), and catalog_log(): entity_type product / product_variant / brand / product_type with the
 * product's id in `after.product_id` on every variant row. Required by every html/products, html/variants, html/brands, html/product-types
 * and html/catalog controller.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/write.php';

/** Every catalog write starts here: POST, login, CSRF, the right. */
function catalog_write_begin(string $right = 'catalog.write'): void
{
    inv_handler_begin();
    require_right($right);
}

/** log_activity() with the catalog's keys: a variant row carries after.product_id. */
function catalog_log(PDO $pdo, string $action, string $entityType, int $entityId, array $opts = []): void
{
    log_activity($pdo, $action, $entityType, $entityId, $opts);
}

/** A money field: '' or absent → $keep (null when absent is "no price"); a number ≥ 0 with two decimals; else a field error. */
function inv_money_field(string $name, ?string $keep, string $label, array &$errors, bool $nullable = true): ?string
{
    if (!req_has($name)) { return $keep; }
    $v = (string) req_val($name);
    if ($v === '') { return $nullable ? null : $keep; }
    $num = preg_replace('/[^0-9.\-]/', '', $v);
    if (!is_numeric($num) || (float) $num < 0 || (float) $num > 99999999) { $errors[$name] = $label . ' is an amount of 0 or more.'; return $keep; }
    return number_format((float) $num, 2, '.', '');
}

/** A weight in the settings' units → grams. Imperial: pounds (2 dp) × 453.592. */
function inv_weight_field(string $name, ?int $keep, string $units, array &$errors): ?int
{
    if (!req_has($name)) { return $keep; }
    $v = (string) req_val($name);
    if ($v === '') { return null; }
    if (!is_numeric($v) || (float) $v < 0) { $errors[$name] = 'The weight is a number of 0 or more.'; return $keep; }
    return $units === 'metric' ? (int) round((float) $v) : (int) round((float) $v * 453.592);
}

/** A length in the settings' units → millimetres. Imperial: inches (1 dp) × 25.4. */
function inv_length_field(string $name, ?int $keep, string $units, string $label, array &$errors): ?int
{
    if (!req_has($name)) { return $keep; }
    $v = (string) req_val($name);
    if ($v === '') { return null; }
    if (!is_numeric($v) || (float) $v < 0) { $errors[$name] = $label . ' is a number of 0 or more.'; return $keep; }
    return $units === 'metric' ? (int) round((float) $v) : (int) round((float) $v * 25.4);
}

/** ships_how: a kind, '' (inherit — none), or absent ($keep). */
function inv_ships_how_field(?string $keep, array &$errors): ?string
{
    if (!req_has('ships_how')) { return $keep; }
    $v = (string) req_val('ships_how');
    if ($v === '' || $v === 'inherit') { return null; }
    if (!isset(SHIPS_HOW[$v])) { $errors['ships_how'] = 'How it ships is parcel, ltl, white_glove or pickup_only.'; return $keep; }
    return $v;
}

/**
 * The product form → the fields of save_product(). $cur: the current row (an update) or null. The attributes come as one control per
 * declared key (`attr[key]`, from the form) or as JSON (`attributes`, from an agent); an unknown key is refused in words; an empty value
 * drops the key. The options as `options[]` (Size first, fixed). A status of discontinued is refused pointing at product_discontinue.
 */
function product_from_request(PDO $pdo, ?array $cur, array &$errors): array
{
    $vocab = settings_vocabulary($pdo);
    $f = [];
    $f['name'] = req_has('name') ? trim((string) req_val('name')) : (string) ($cur['name'] ?? '');
    if ($f['name'] === '' || mb_strlen($f['name']) > 200) { $errors['name'] = 'Give the product a name of up to 200 characters.'; }
    // the brand: by id, or by name (an agent); '' = none
    $f['brand_id'] = $cur['brand_id'] ?? null;
    if (req_has('brand')) {
        $b = (string) req_val('brand');
        if ($b === '' || $b === '0') { $f['brand_id'] = null; }
        else {
            $brand = brand_by_id_or_name($pdo, $b);
            if ($brand === null) { $errors['brand'] = 'That brand is not here.'; } else { $f['brand_id'] = $brand['brand_id']; }
        }
    }
    // the type: by id, key or name
    $f['product_type_id'] = $cur['product_type_id'] ?? null;
    if (req_has('type') || req_has('product_type')) {
        $t = (string) (req_val('type') ?? req_val('product_type'));
        $type = $t === '' ? null : product_type_by_key_or_id($pdo, $t);
        if ($type === null) { $errors['type'] = 'Choose the product type from the list.'; } else { $f['product_type_id'] = $type['product_type_id']; }
    }
    if ($f['product_type_id'] === null && !isset($errors['type'])) { $errors['type'] = 'Choose the product type from the list.'; }
    $f['description'] = req_has('description') ? (((string) req_val('description') === '') ? null : mb_substr((string) req_val('description'), 0, 4000)) : ($cur['description'] ?? null);
    // attributes
    $declared = [];
    foreach ($vocab['attribute_keys'] as $a) { $declared[(string) $a['key']] = $a; }
    $attrs = $cur['attributes'] ?? [];
    $given = null;
    if (isset($_POST['attr']) && is_array($_POST['attr'])) { $given = $_POST['attr']; }
    elseif (req_has('attributes')) {
        $raw = (string) req_val('attributes');
        $given = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($given)) { $errors['attributes'] = 'The attributes are a JSON object by attribute key.'; $given = null; }
    }
    if ($given !== null) {
        $attrs = [];
        foreach ($given as $k => $v) {
            $k = (string) $k;
            if (!isset($declared[$k])) { $errors['attributes'] = 'Unknown attribute: ' . mb_substr($k, 0, 40) . '. The keys are ' . implode(', ', array_keys($declared)) . '.'; continue; }
            $spec = $declared[$k];
            if ($v === '' || $v === null || $v === []) { continue; }
            switch ($spec['kind'] ?? 'text') {
                case 'number':
                    if (!is_numeric($v)) { $errors['attributes'] = ($spec['name'] ?? $k) . ' is a number.'; break; }
                    $attrs[$k] = (float) $v == (int) $v ? (int) $v : (float) $v;
                    break;
                case 'choice':
                    if (!in_array((string) $v, array_map('strval', $spec['choices'] ?? []), true)) { $errors['attributes'] = ($spec['name'] ?? $k) . ' is one of ' . implode(', ', $spec['choices'] ?? []) . '.'; break; }
                    $attrs[$k] = (string) $v;
                    break;
                case 'multi':
                    $list = is_array($v) ? $v : explode(',', (string) $v);
                    $list = array_values(array_unique(array_filter(array_map(static fn ($x): string => trim((string) $x), $list), static fn (string $x): bool => $x !== '')));
                    $bad = array_diff($list, array_map('strval', $spec['choices'] ?? []));
                    if ($bad !== []) { $errors['attributes'] = ($spec['name'] ?? $k) . ' allows ' . implode(', ', $spec['choices'] ?? []) . '.'; break; }
                    if ($list !== []) { $attrs[$k] = $list; }
                    break;
                default:
                    $attrs[$k] = mb_substr(is_array($v) ? implode(', ', $v) : (string) $v, 0, 200);
            }
        }
    }
    $f['attributes'] = $attrs;
    // kind
    $f['kind'] = $cur['kind'] ?? 'single';
    if (req_has('kind') && (string) req_val('kind') !== '') {
        $k = (string) req_val('kind');
        if (!isset(PRODUCT_KINDS[$k])) { $errors['kind'] = 'The kind is single or bundle.'; }
        elseif ($cur !== null && $k !== $cur['kind'] && ($why = product_kind_changeable($pdo, (int) $cur['product_id'])) !== null) { $errors['kind'] = $why; }
        else { $f['kind'] = $k; }
    }
    // options: Size first and fixed, ≤ 3, unique, ≤ 40 chars
    $f['options'] = $cur['options'] ?? ['Size'];
    $opts = request_list('options');
    if ($opts !== null) {
        $opts = array_values(array_unique(array_map(static fn (string $o): string => mb_substr(trim($o), 0, 40), $opts)));
        if ($opts === [] || strcasecmp($opts[0], 'Size') !== 0) { array_unshift($opts, 'Size'); $opts = array_values(array_unique($opts)); }
        if (count($opts) > 3) { $errors['options'] = 'A product has at most three options.'; }
        elseif ($cur !== null) {
            $used = product_option_names_in_use($pdo, (int) $cur['product_id']);
            $removed = array_diff($cur['options'] ?? [], $opts);
            $lost = array_intersect($removed, $used);
            if ($lost !== []) { $errors['options'] = 'The option ' . implode(', ', $lost) . ' is used by a variant and cannot be removed.'; }
        }
        if (!isset($errors['options'])) { $f['options'] = $opts; }
    }
    $f['ships_how'] = inv_ships_how_field($cur['ships_how'] ?? null, $errors);
    $f['reorder_point'] = inv_int('reorder_point', $cur['reorder_point'] ?? null, 0, 1000000, 'The reorder point', $errors, true);
    $f['tags'] = $cur['tags'] ?? [];
    if (req_has('tags') || isset($_POST['tags'])) {
        $tags = request_list('tags') ?? [];
        $tags = array_values(array_unique(array_map(static fn (string $t): string => mb_substr($t, 0, 40), $tags)));
        if (count($tags) > 20) { $errors['tags'] = 'At most 20 tags.'; } else { $f['tags'] = $tags; }
    }
    $f['status'] = $cur['status'] ?? 'draft';
    if (req_has('status') && (string) req_val('status') !== '') {
        $s = (string) req_val('status');
        if ($s === 'discontinued') { $errors['status'] = 'Discontinue a product with its own action (product_discontinue): it is confirmed.'; }
        elseif (!isset(PRODUCT_STATUSES[$s])) { $errors['status'] = 'The status is draft or active.'; }
        elseif ($cur !== null && $cur['status'] === 'discontinued') { $errors['status'] = 'Reactivate the product with product_discontinue (discontinued: no) first.'; }
        else { $f['status'] = $s; }
    }
    return $f;
}

/**
 * The variant form → the fields of save_variant(). The option values come as one control per product option (`option[Size]`, the form;
 * "Other…" with `option_other[Size]`) or as JSON (`option_values`, an agent). On an update the three price fields are refused pointing
 * at price_set.
 */
function variant_from_request(PDO $pdo, array $product, ?array $cur, array &$errors): array
{
    $vocab = settings_vocabulary($pdo);
    $units = $vocab['units'];
    $f = ['product_id' => (int) $product['product_id']];
    $f['sku'] = req_has('sku') ? trim((string) req_val('sku')) : (string) ($cur['sku'] ?? '');
    if ($f['sku'] === '' || mb_strlen($f['sku']) > 100) { $errors['sku'] = 'A SKU of up to 100 characters is required.'; }
    // option values
    $values = $cur['option_values'] ?? [];
    $given = null;
    if (isset($_POST['option']) && is_array($_POST['option'])) {
        $given = [];
        foreach ($_POST['option'] as $k => $v) {
            $v = trim((string) (is_array($v) ? '' : $v));
            if ($v === '__other' && isset($_POST['option_other'][$k])) { $v = trim((string) $_POST['option_other'][$k]); }
            $given[(string) $k] = $v;
        }
    } elseif (req_has('option_values')) {
        $raw = (string) req_val('option_values');
        $given = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($given)) { $errors['option_values'] = 'The option values are a JSON object: {"Size": "Queen"}.'; $given = null; }
    }
    if ($given !== null) {
        $values = [];
        $names = $product['options'] ?? ['Size'];
        foreach ($given as $k => $v) {
            $match = null;
            foreach ($names as $n) { if (strcasecmp($n, (string) $k) === 0) { $match = $n; } }
            if ($match === null) { $errors['option_values'] = 'The product has no option "' . mb_substr((string) $k, 0, 40) . '" (its options: ' . implode(', ', $names) . ').'; continue; }
            $v = mb_substr(trim((string) $v), 0, 60);
            if ($v !== '') { $values[$match] = $v; }
        }
        foreach ($names as $n) {
            if (strcasecmp($n, 'Size') === 0 && !isset($values[$n])) { $errors['option_values'] = $errors['option_values'] ?? 'Choose the size.'; }
        }
    }
    $f['option_values'] = $values;
    $f['barcode'] = req_has('barcode') ? (((string) req_val('barcode') === '') ? null : trim((string) req_val('barcode'))) : ($cur['barcode'] ?? null);
    $f['mpn'] = req_has('mpn') ? (((string) req_val('mpn') === '') ? null : mb_substr(trim((string) req_val('mpn')), 0, 100)) : ($cur['mpn'] ?? null);
    $f['weight_g'] = inv_weight_field('weight', $cur['weight_g'] ?? null, $units, $errors);
    if (req_has('weight_g')) { $f['weight_g'] = inv_int('weight_g', $f['weight_g'], 0, 100000000, 'The weight', $errors, true); }
    $f['length_mm'] = inv_length_field('length', $cur['length_mm'] ?? null, $units, 'The length', $errors);
    $f['width_mm'] = inv_length_field('width', $cur['width_mm'] ?? null, $units, 'The width', $errors);
    $f['height_mm'] = inv_length_field('height', $cur['height_mm'] ?? null, $units, 'The height', $errors);
    foreach (['length_mm' => 'The length', 'width_mm' => 'The width', 'height_mm' => 'The height'] as $k => $label) {
        if (req_has($k)) { $f[$k] = inv_int($k, $f[$k], 0, 100000000, $label, $errors, true); }
    }
    $f['ships_how'] = inv_ships_how_field($cur['ships_how_own'] ?? null, $errors);
    $f['reorder_point'] = inv_int('reorder_point', $cur['reorder_point_own'] ?? null, 0, 1000000, 'The reorder point', $errors, true);
    $f['reorder_qty'] = inv_int('reorder_qty', $cur['reorder_qty'] ?? null, 1, 1000000, 'The reorder quantity', $errors, true);
    $f['active'] = inv_yes('active', $cur['active'] ?? true);
    if ($cur === null) {
        $f['retail_price'] = inv_money_field('retail_price', null, 'The retail price', $errors);
        $f['map_price'] = inv_money_field('map_price', null, 'The MAP', $errors);
        $f['cost_price'] = (sees_cost() && has_right('prices.write')) ? inv_money_field('cost_price', null, 'The cost', $errors) : null;
        if (req_has('cost_price') && (string) req_val('cost_price') !== '' && !(sees_cost() && has_right('prices.write'))) { $errors['cost_price'] = 'You may not set cost.'; }
    } else {
        foreach (['retail_price', 'map_price', 'cost_price'] as $pk) {
            if (req_has($pk) && (string) req_val($pk) !== '' && (string) req_val($pk) !== (string) ($cur[$pk . '_raw'] ?? '')) {
                $errors[$pk] = 'Set a price with its reason on the variant\'s page (price_set).';
            }
        }
    }
    return $f;
}

/** The raw product_variants row's own columns the form edits (ships_how / reorder_point are COALESCEd in the view). */
function variant_own_columns(PDO $pdo, int $variantId): array
{
    $st = $pdo->prepare('SELECT ships_how, reorder_point, retail_price, map_price, cost_price FROM product_variants WHERE id = :id');
    $st->execute(['id' => $variantId]);
    $r = $st->fetch() ?: [];
    return ['ships_how_own' => $r['ships_how'] ?? null, 'reorder_point_own' => $r['reorder_point'] === null ? null : (int) $r['reorder_point'],
            'retail_price_raw' => $r['retail_price'] ?? null, 'map_price_raw' => $r['map_price'] ?? null, 'cost_price_raw' => $r['cost_price'] ?? null];
}

/** The brand form → save_brand()'s fields. */
function brand_from_request(PDO $pdo, ?array $cur, array &$errors): array
{
    $f = [];
    $f['name'] = req_has('name') ? trim((string) req_val('name')) : (string) ($cur['name'] ?? '');
    if ($f['name'] === '' || mb_strlen($f['name']) > 120) { $errors['name'] = 'Give the brand a name of up to 120 characters.'; }
    $f['website'] = req_has('website') ? (((string) req_val('website') === '') ? null : mb_substr(trim((string) req_val('website')), 0, 300)) : ($cur['website'] ?? null);
    if ($f['website'] !== null && !preg_match('#^https?://#i', $f['website'])) { $f['website'] = 'https://' . $f['website']; }
    $f['supplier_id'] = inv_ref($pdo, 'supplier', $cur['supplier_id'] ?? null, 'SELECT 1 FROM mcp_suppliers WHERE supplier_id = :id', 'the supplier', $errors);
    $f['active'] = inv_yes('active', $cur['active'] ?? true);
    return $f;
}

/** The product type form → save_product_type()'s fields; the key from the name when absent. */
function product_type_from_request(?array $cur, array &$errors): array
{
    $f = [];
    $f['name'] = req_has('name') ? trim((string) req_val('name')) : (string) ($cur['name'] ?? '');
    if ($f['name'] === '' || mb_strlen($f['name']) > 80) { $errors['name'] = 'Give the type a name of up to 80 characters.'; }
    $key = req_has('key') ? trim((string) req_val('key')) : (string) ($cur['key'] ?? '');
    if ($key === '') { $key = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($f['name'])), '_'); }
    $key = mb_substr($key, 0, 40);
    if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key)) { $errors['key'] = 'The key is lowercase letters, digits and underscores, starting with a letter.'; }
    $f['key'] = $key;
    $f['sort_order'] = inv_int('sort_order', $cur['sort_order'] ?? 0, 0, 10000, 'The sort order', $errors) ?? 0;
    $f['active'] = inv_yes('active', $cur['active'] ?? true);
    return $f;
}

/** The loggable shape of a product (names, never the description's words — its length). */
function product_loggable(array $p): array
{
    return ['name' => $p['name'], 'brand_id' => $p['brand_id'] === null ? null : (int) $p['brand_id'], 'brand' => $p['brand'] ?? null, 'product_type_id' => (int) $p['product_type_id'], 'product_type' => $p['product_type'] ?? null,
            'kind' => $p['kind'], 'status' => $p['status'], 'options' => array_values($p['options'] ?? []), 'attributes' => $p['attributes'] ?? [], 'ships_how' => $p['ships_how'],
            'reorder_point' => $p['reorder_point'] === null ? null : (int) $p['reorder_point'], 'tags' => array_values($p['tags'] ?? []), 'description_length' => mb_strlen((string) ($p['description'] ?? ''))];
}

function variant_loggable(array $v): array
{
    return ['product_id' => (int) $v['product_id'], 'sku' => $v['sku'], 'size_key' => $v['size_key'], 'option_values' => $v['option_values'] ?? [], 'barcode' => $v['barcode'], 'mpn' => $v['mpn'],
            'weight_g' => $v['weight_g'] === null ? null : (int) $v['weight_g'], 'length_mm' => $v['length_mm'] === null ? null : (int) $v['length_mm'], 'width_mm' => $v['width_mm'] === null ? null : (int) $v['width_mm'],
            'height_mm' => $v['height_mm'] === null ? null : (int) $v['height_mm'], 'ships_how' => $v['ships_how'], 'reorder_point' => $v['reorder_point'] === null ? null : (int) $v['reorder_point'],
            'reorder_qty' => $v['reorder_qty'] === null ? null : (int) $v['reorder_qty'], 'active' => (bool) $v['active']];
}

/** A product the caller may see, or 404 in words. */
function catalog_product_or_404(PDO $pdo, ?int $id): array
{
    $p = $id === null ? null : find_product($pdo, $id);
    if ($p === null) { refuse(404, 'Product not found.'); }
    return $p;
}

function catalog_variant_or_404(PDO $pdo, ?int $id): array
{
    $v = $id === null ? null : find_variant($pdo, $id);
    if ($v === null) { refuse(404, 'Variant not found.'); }
    return $v;
}

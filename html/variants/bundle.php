<?php
declare(strict_types=1);
/**
 * /variants/{id}/bundle — the bundle editor (screen `variant-bundle`, GET: a bundle variant only, else 404 in words) and action `bundle_set` (POST; log
 * `variant.bundle_set`: components [{variant_id, sku, qty}]): catalog.write. `components` as JSON ([{variant, qty}] — a variant by id or SKU) or as the
 * form's components[n][variant] / [qty]; the whole list replaces what was there. The database refuses a bundle inside a bundle and components on a
 * single product. Location /variants/{bundle}/bundle.
 */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
$pdo = db();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    catalog_write_begin('catalog.write');
    $v = catalog_variant_or_404($pdo, request_integer('bundle') ?? request_integer('variant'));
    if ($v['kind'] !== 'bundle') { refuse(422, $v['sku'] . ' is not a variant of a bundle product.'); }
    $raw = $_POST['components'] ?? [];
    if (is_string($raw)) { $raw = json_decode($raw, true); if (!is_array($raw)) { inv_refuse_fields(['components' => 'The components are a JSON list of {variant, qty}.']); } }
    $components = [];
    foreach ((array) $raw as $c) {
        if (!is_array($c)) { continue; }
        $ref = trim((string) ($c['variant'] ?? $c['variant_id'] ?? ''));
        if ($ref === '') { continue; }
        $cv = variant_by_id_or_sku($pdo, $ref);
        if ($cv === null) { inv_refuse_fields(['components' => 'No variant "' . mb_substr($ref, 0, 40) . '".']); }
        $qty = filter_var($c['qty'] ?? 1, FILTER_VALIDATE_INT);
        if ($qty === false || $qty < 1 || $qty > 1000) { inv_refuse_fields(['components' => 'The quantity of ' . $cv['sku'] . ' is a whole number from 1 to 1000.']); }
        if ($cv['variant_id'] === $v['variant_id']) { inv_refuse_fields(['components' => 'A bundle does not contain itself.']); }
        $components[$cv['variant_id']] = ['variant_id' => $cv['variant_id'], 'sku' => $cv['sku'], 'qty' => (int) $qty];
    }
    $components = array_values($components);
    $before = array_map(static fn (array $c): array => ['variant_id' => (int) $c['component_variant_id'], 'sku' => $c['component_sku'], 'qty' => (int) $c['qty']], bundle_components($pdo, $v['variant_id']));
    $after = inv_guard($pdo, static function () use ($pdo, $v, $components, $before): array {
        $pdo->beginTransaction();
        $after = set_bundle($pdo, $v['variant_id'], $components);
        catalog_log($pdo, 'variant.bundle_set', 'product_variant', $v['variant_id'], ['before' => ['components' => $before], 'after' => ['product_id' => $v['product_id'], 'components' => $after]]);
        $pdo->commit();
        return $after;
    });
    inv_done('Saved ' . count($after) . ' component' . (count($after) === 1 ? '' : 's') . ' of ' . $v['sku'], $v['variant_id'], inv_land('/variants/' . $v['variant_id'] . '/bundle', 'saved'), 'productChanged', ['components' => $after]);
}
require_right('catalog.write');
require_human();
$v = catalog_variant_or_404($pdo, request_integer('id') ?? request_integer('variant'));
if ($v['kind'] !== 'bundle') { refuse(404, $v['sku'] . ' is not a variant of a bundle product: only a bundle has components.'); }
$product = catalog_product_or_404($pdo, $v['product_id']);
log_screen_view($pdo, 'variant-bundle');
if (wants_json()) {
    respond_screen(['variant' => present_variant($v), 'components' => bundle_components($pdo, $v['variant_id']), 'availability' => bundle_availability($pdo, $v['variant_id'])]);
}
render_screen('Components of ' . $v['sku'], view('catalog/variant-bundle.php', ['variant' => $v, 'product' => $product, 'components' => bundle_components($pdo, $v['variant_id']), 'availability' => bundle_availability($pdo, $v['variant_id']) ?? [], 'here' => here_url(), 'notice' => inv_notice($_GET['notice'] ?? null, ['saved' => ['success', 'The components are saved.']])]),
    ['activeNav' => 'product-list', 'screen' => 'variant-bundle', 'entity' => 'variant', 'recordId' => (string) $v['variant_id']]);

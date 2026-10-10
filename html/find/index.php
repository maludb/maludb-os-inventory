<?php
declare(strict_types=1);
/**
 * /find?q=&size=&type=&firmness=&price_min=&price_max=&in_stock=&ships_within= — screen `find` (find.md): the box, the chips, one inv_find() for the
 * list (limit 50), each card's availability loaded when revealed; "Ask the sources now" fans out in the browser. An empty Find runs no query.
 * The form carries no hx-push-url: the answer's HX-Push-Url is the query as sent.
 */
require_once dirname(__DIR__, 2) . '/app/features/listings/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/find/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/find/present.php';
require_right('inventory.read');
$pdo = db();
$params = find_params($_GET);
$q = $params['q'] ?? null;
if ($q !== null && mb_strlen($q) > 120) { refuse(422, 'Keep it under 120 characters.'); }
$types = find_product_types($pdo);
$firmness = firmness_choices($pdo);
$filters = find_filters($_GET, $types, $firmness);
$size = $params['size'] ?? null;
$ran = $q !== null || $size !== null || $filters !== [];
$rows = $ran ? find_variants($pdo, $q, $size, $filters, FIND_LIMIT) : null;
if (!wants_json() || ($_SERVER['HTTP_X_SCREEN_VIEW'] ?? '') === '1') {                 // the rule of log_screen_view(), with the query on the row
    log_activity($pdo, 'screen.view', null, null, ['screen' => 'find', 'after' => ['q' => $q === null ? null : mb_substr($q, 0, 120), 'size' => $size, 'type' => $params['type'] ?? null,
    'firmness' => $params['firmness'] ?? null, 'price_min' => $params['price_min'] ?? null, 'price_max' => $params['price_max'] ?? null, 'in_stock' => isset($params['in_stock']),
    'ships_within' => $params['ships_within'] ?? null, 'results' => $rows === null ? null : count($rows)]]);
}
$here = find_toggle($params, []);
if (wants_json()) {
    respond_screen(['query' => $params, 'filters' => (object) $filters, 'ran' => $ran, 'limit' => FIND_LIMIT, 'results' => $rows === null ? [] : array_map(static fn ($r) => [
        'variant_id' => $r['variant_id'], 'product_id' => $r['product_id'], 'product' => $r['product_name'], 'brand' => $r['brand'], 'product_type' => $r['product_type'], 'kind' => $r['kind'],
        'sku' => $r['sku'], 'size_key' => $r['size_key'], 'size' => $r['size_name'], 'barcode' => $r['barcode'], 'mpn' => $r['mpn'], 'retail_price' => $r['retail_price'], 'map_price' => $r['map_price'],
        'cost_price' => $r['cost_withheld'] ? null : $r['cost_price'], 'cost_withheld' => $r['cost_withheld'], 'own_available' => $r['own_available'], 'best_offer' => $r['best_offer'],
        'best_lead_time_days' => $r['best_lead_time_days'], 'state' => $r['state'], 'score' => $r['score']], $rows)]);
}
header('HX-Push-Url: ' . $here);
render_screen('Find', view('find/index.php', ['params' => $params, 'rows' => $rows, 'locations' => $rows === null ? [] : best_stock_locations($pdo, array_column($rows, 'variant_id')), 'types' => $types, 'sizes' => find_sizes($pdo), 'firmness' => $firmness,
    'may' => ['sell' => has_right('orders.write'), 'watch' => has_right('watches.own'), 'ask' => has_right('orders.write') && $q !== null && mb_strlen($q) >= 2 && searchable_sources($pdo) !== []],
    'seesCost' => sees_cost(), 'tz' => member_timezone(), 'here' => $here, 'notice' => inv_notice($_GET['notice'] ?? null, ['watched' => ['success', 'Watching. You will be told when it changes.']])]),
    ['activeNav' => 'find', 'screen' => 'find', 'entity' => 'variant', 'recordId' => '']);

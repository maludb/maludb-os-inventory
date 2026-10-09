<?php
declare(strict_types=1);
/**
 * Action `price_set` (log `variant.price_set`: kind, before.price, after.price, reason, currency; an agent pauses — other): prices.write; `kind` retail/map/cost;
 * a cost for a caller without sees_cost() → 403 "You may not see cost or margin." Through inv_price_set(…, 'manual'): the history row carries the reason.
 * Location /variants/{id}#variant-prices; refresh productChanged.
 */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
catalog_write_begin('prices.write');
$pdo = db();
$v = catalog_variant_or_404($pdo, request_integer('variant'));
$kind = (string) req_val('kind');
if (!isset(PRICE_KINDS[$kind])) { inv_refuse_fields(['kind' => 'The kind is retail, map or cost.']); }
if ($kind === 'cost' && !sees_cost()) { refuse(403, 'You may not see cost or margin.'); }
$errors = [];
$price = inv_money_field('price', null, 'The price', $errors, false);
if ($price === null && !isset($errors['price'])) { $errors['price'] = 'The price is an amount of 0 or more.'; }
if ($errors !== []) { inv_refuse_fields($errors); }
$reason = req_has('reason') && (string) req_val('reason') !== '' ? mb_substr((string) req_val('reason'), 0, 200) : null;
$currency = settings_vocabulary($pdo)['currency'];
$r = inv_guard($pdo, static function () use ($pdo, $v, $kind, $price, $reason, $currency): array {
    $pdo->beginTransaction();
    $r = set_price($pdo, $v['variant_id'], $kind, $price, $reason);
    catalog_log($pdo, 'variant.price_set', 'product_variant', $v['variant_id'], ['before' => ['price' => $r['before']], 'after' => ['product_id' => $v['product_id'], 'sku' => $v['sku'], 'kind' => $kind, 'price' => $r['after'], 'reason' => $reason, 'currency' => $currency]]);
    $pdo->commit();
    return $r;
});
inv_done('Set the ' . PRICE_KINDS[$kind] . ' of ' . $v['sku'] . ' to ' . $price, $v['variant_id'], inv_land('/variants/' . $v['variant_id'], 'price_set', 'variant-prices'), 'productChanged', ['kind' => $kind, 'before' => $r['before'], 'after' => $r['after']]);

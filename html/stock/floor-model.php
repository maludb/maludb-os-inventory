<?php
declare(strict_types=1);
/** Action `floor_model_set` (log `stock.floor_model`: sku, location_id, direction, qty, adjustment_id): a `floor_model` adjustment drafted and posted in one transaction — the units move onto (in) or off (out) the floor; on hand unchanged. stock.adjust. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
stock_write_begin('stock.adjust');
$pdo = db();
$errors = [];
$scanned = false;
$variantId = stock_variant_from_request($pdo, $errors, $scanned, 'flag');
$locationId = stock_location_field($pdo, 'location', null, 'the location', $errors);
if ($locationId === null && !isset($errors['location'])) { $errors['location'] = 'Choose the location.'; }
$direction = (string) (req_val('direction') ?? '');
if (!in_array($direction, ['in', 'out'], true)) { $errors['direction'] = 'The direction is in or out.'; }
$qty = inv_int('qty', 1, 1, 100000, 'The quantity', $errors);
$note = stock_text('note', null, 1000);
if ($errors !== []) { inv_refuse_fields($errors); }
$sku = (string) one_value($pdo, 'SELECT sku FROM mcp_product_variants WHERE variant_id = :id', ['id' => $variantId]);
$res = inv_guard($pdo, static function () use ($pdo, $variantId, $locationId, $direction, $qty, $note, $sku): array {
    $pdo->beginTransaction();
    $res = set_floor_model($pdo, $variantId, $locationId, $direction, $qty, $note, stock_me());
    stock_log($pdo, 'stock.floor_model', 'product_variant', $variantId, $locationId, ['sku' => $sku, 'location_id' => $locationId, 'direction' => $direction, 'qty' => $qty, 'adjustment_id' => $res['adjustment_id'], 'number' => $res['number'], 'transaction_id' => $res['transaction_id']]);
    $pdo->commit();
    return $res;
});
inv_done(($direction === 'in' ? 'Took ' : 'Took off ') . $qty . ' × ' . $sku . ($direction === 'in' ? ' onto the floor' : ' the floor'), $res['adjustment_id'], inv_land('/stock/floor-models?location=' . $locationId, 'floor', 'floor-row-' . $variantId . '-' . $locationId), 'stockChanged', $res);

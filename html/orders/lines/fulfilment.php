<?php
declare(strict_types=1);
/**
 * Action `order_line_fulfilment_set` (log `order.line_fulfilment_set`): a quote's line is filled from stock, pickup, a supplier's offer (its cost and lead time are snapshotted by the trigger) or a back order —
 * the picker's `fulfilment` (kind:id) or `fulfilment_kind` + `location` / `listing_variant`. orders.write.
 */
require_once dirname(__DIR__, 3) . '/app/features/orders/handler.php';
orders_write_begin('orders.write');
$pdo = db();
$line = find_order_line($pdo, request_integer('line') ?? request_integer('line_id') ?? 0) ?? refuse(404, 'Line not found.');
$o = order_or_404($pdo, $line['sales_order_id']);
require_quote($o);
$r = order_fulfilment_from_row($_POST);
if (isset($r['error'])) { inv_refuse_fields(['fulfilment' => $r['error']]); }
if ($r['kind'] === null) { inv_refuse_fields(['fulfilment_kind' => 'Say how the line is filled: stock, dropship, backorder or pickup.']); }
if ($r['kind'] === 'backorder' && $r['location_id'] === null) { $r['location_id'] = $line['location_id'] ?? default_backorder_location($pdo); }
$after = inv_guard($pdo, static function () use ($pdo, $o, $line, $r): array {
    $pdo->beginTransaction();
    set_line_fulfilment($pdo, $line['line_id'], $r);
    $l = line_loggable($pdo, $line['line_id']);
    order_log($pdo, 'order.line_fulfilment_set', $o, $l + ['was' => $line['fulfilment_kind']]);
    $pdo->commit();
    return $l;
});
$oid = (int) $o['sales_order_id'];
if (is_htmx_request() && !wants_json() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'order-lines') {
    emit_action_status(true, ['did' => 'Changed how the line is filled', 'record_id' => $line['line_id']]);
    hx_trigger('orderChanged');
    echo orders_lines_fragment($pdo, $oid);
    exit;
}
inv_done('Changed how line ' . $line['line_no'] . ' of ' . $o['number'] . ' is filled', $line['line_id'], inv_land(return_path('/orders/' . $oid . '/confirm'), 'line_saved', 'order-line-' . $line['line_id']), 'orderChanged',
    ['sales_order_id' => $oid, 'line_id' => $line['line_id'], 'fulfilment_kind' => $after['fulfilment_kind'], 'offer_cost' => sees_cost() ? ($after['offer_cost'] ?? null) : null, 'offer_lead_time_days' => $after['offer_lead_time_days'] ?? null]);

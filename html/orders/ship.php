<?php
declare(strict_types=1);
/**
 * GET /orders/{id}/ship — screen `order-ship`: the stock, pickup and backorder lines not fully shipped (a checkbox and a quantity each), the drop-ship lines read-only, the kind, carrier, tracking.
 * POST — action `order_ship` (log `order.ship`: shipment_id, number, kind, carrier, tracking_number, lines[], issued[]; confirm): stock.ship. `lines` = the form's lines[line_id][ship|qty|serials] or JSON
 * [{line_id, qty, serials}]; inv_order_ship() issues a `sale` movement per stock line and releases its allocation; a parcel with a known carrier gets its tracking URL. Location /shipments/{id}; refresh orderChanged.
 */
require_once dirname(__DIR__, 2) . '/app/features/orders/handler.php';
$pdo = db();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    orders_write_begin('stock.ship');
    $o = order_or_404($pdo, request_integer('order') ?? request_integer('sales_order_id') ?? request_integer('id'));
    $errors = [];
    $kind = trim((string) (req_val('kind') ?? ''));
    if ($kind === '') { $kind = 'own_delivery'; }
    if (!in_array($kind, ['own_delivery', 'parcel', 'ltl', 'pickup'], true)) { $errors['kind'] = 'A shipment is own_delivery, parcel, ltl or pickup.'; }
    $carrier = trim((string) (req_val('carrier') ?? ''));
    $tracking = trim((string) (req_val('tracking') ?? req_val('tracking_number') ?? ''));
    if (mb_strlen($carrier) > 60) { $errors['carrier'] = 'The carrier is up to 60 characters.'; }
    if (mb_strlen($tracking) > 100) { $errors['tracking'] = 'The tracking number is up to 100 characters.'; }
    $at = order_datetime_field('shipped_at', 'The time shipped', $errors);
    $raw = $_POST['lines'] ?? [];
    if (!is_array($raw)) { $raw = (string) $raw === '' ? [] : json_decode((string) $raw, true); }
    if (!is_array($raw)) { refuse(422, 'lines is a JSON list of {line, qty}.'); }
    $lines = [];
    foreach ($raw as $key => $row) {
        if (!is_array($row)) { refuse(422, 'lines is a JSON list of {line, qty}.'); }
        $api = array_key_exists('line_id', $row) || array_key_exists('line', $row);
        $lid = $api ? (int) ($row['line_id'] ?? $row['line']) : (int) $key;
        if (!$api && (string) ($row['ship'] ?? '0') !== '1') { continue; }                         // a form row: only the ticked ones
        $q = trim((string) ($row['qty'] ?? ''));
        if (!$api && ($q === '' || $q === '0')) { continue; }
        if ($q !== '' && (filter_var($q, FILTER_VALIDATE_INT) === false || (int) $q < 0)) { $errors['lines.' . $lid] = 'Line ' . $lid . ': the quantity is a whole number.'; continue; }
        $ser = $row['serials'] ?? [];
        $ser = is_array($ser) ? $ser : array_values(array_filter(array_map('trim', explode(',', (string) $ser)), static fn (string $s): bool => $s !== ''));
        $e = ['line_id' => $lid];
        if ($q !== '') { $e['qty'] = (int) $q; }
        if ($ser !== []) { $e['serials'] = array_values(array_map('strval', $ser)); }
        $lines[] = $e;
    }
    $top = request_list('serials');
    if ($top !== null && $top !== [] && count($lines) === 1 && !isset($lines[0]['serials'])) { $lines[0]['serials'] = $top; }
    if ($lines === [] && $errors === []) { $errors['lines'] = 'Choose the lines to ship.'; }
    if ($errors !== []) { inv_refuse_fields($errors); }
    $r = inv_guard($pdo, static function () use ($pdo, $o, $lines, $kind, $carrier, $tracking, $at): array {
        $pdo->beginTransaction();
        $r = ship_order($pdo, (int) $o['sales_order_id'], $lines, $kind, $carrier === '' ? null : $carrier, $tracking === '' ? null : $tracking, $at, (int) current_member_id());
        $now = find_order($pdo, (int) $o['sales_order_id']);
        order_log($pdo, 'order.ship', $now, ['shipment_id' => $r['shipment_id'], 'number' => $o['number'], 'kind' => $kind, 'carrier' => $carrier === '' ? null : $carrier, 'tracking_number' => $tracking === '' ? null : $tracking,
            'lines' => $r['lines'], 'issued' => $r['issued']]);
        $pdo->commit();
        return $r;
    });
    inv_done('Shipped from ' . $o['number'], $r['shipment_id'], inv_land('/shipments/' . $r['shipment_id'], 'shipped'), 'orderChanged',
        ['sales_order_id' => (int) $o['sales_order_id'], 'shipment_id' => $r['shipment_id'], 'issued' => $r['issued'], 'lines' => $r['lines']]);
}
require_right('stock.ship');
$o = order_or_404($pdo, request_integer('id') ?? request_integer('order'));
log_screen_view($pdo, 'order-ship');
if (wants_json()) { respond_screen(['order' => present_order_row($o), 'suggested_kind' => ship_kind_for($o['delivery_method']), 'lines' => array_map('present_order_line', $o['lines'])]); }
render_screen('Ship ' . $o['number'], view('orders/ship.php', ['o' => $o, 'here' => here_url(), 'seesCost' => sees_cost(), 'notice' => inv_notice($_GET['notice'] ?? null, ORDER_NOTICES)]),
    ['activeNav' => 'order-list', 'screen' => 'order-ship', 'entity' => 'sales_order', 'recordId' => (string) $o['sales_order_id']]);

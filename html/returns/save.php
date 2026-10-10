<?php
declare(strict_types=1);
/**
 * Actions `return_request` (no `return`; log `return.request`: number, customer_id, status, method, scheduled_on, lines [{sku, qty, reason_code, disposition}], currency) and `return_update` (with `return`, a
 * requested or approved return only; log `return.update`: the header's changed fields before and after, the lines): orders.write. `order` (an id or a number; a confirmed order with shipped lines), `lines` as JSON
 * [{line, qty, reason, disposition?, location?, condition_note?}] or the form's lines[<order line id>][qty|reason|disposition|location|condition_note] (a line with qty 0 is skipped; on an update the lines
 * sent ARE the set), `method` (pickup by default), `scheduled_on` (may be past), `location` (where it comes back to), `notes`. The database decides what may come back and what a reason is; its sentence is
 * the field error of the line. A request tells the Buyer. Location /returns/{id}; refresh returnChanged.
 */
require_once dirname(__DIR__, 2) . '/app/features/returns/handler.php';
returns_write_begin('orders.write');
$pdo = db();
$me = (int) current_member_id();
$id = request_return_id($pdo);
$cur = $id === null ? null : return_or_404($pdo, $id);
$errors = [];
if ($cur === null) {
    $ov = trim((string) (req_val('order') ?? req_val('sales_order_id') ?? ''));
    $orderId = $ov === '' ? null : (ctype_digit($ov) ? (int) $ov : (find_order_by_number($pdo, $ov)['sales_order_id'] ?? null));
    $order = $orderId === null ? null : one_row($pdo, 'SELECT sales_order_id, number, status FROM mcp_sales_orders WHERE sales_order_id = :o', ['o' => $orderId]);
    if ($ov === '') { inv_refuse_fields(['order' => 'Choose the order the goods come back from.']); }
    if ($order === null) { refuse(404, 'Order not found.'); }
} else {
    $orderId = $cur['sales_order_id'];
    $order = ['sales_order_id' => $orderId, 'number' => $cur['order_number'], 'status' => $cur['order_status']];
    if (!in_array($cur['status'], ['requested', 'approved'], true)) {
        refuse(422, 'The details of a return change while it is requested or approved (it is ' . $cur['status'] . ')');
    }
}
$head = return_header_from_request($pdo, $cur, $errors);
$lines = return_lines_from_request($pdo, $orderId, $errors);
if ($cur === null && ($lines === null || $lines === []) && $errors === []) { $errors['lines'] = 'Choose at least one line to return.'; }
if ($cur !== null && $lines !== null && $lines === [] && $errors === []) { $errors['lines'] = 'A return keeps at least one line — deny it instead.'; }
// where it comes back to: a restock or a floor model needs one (the header's or the line's own)
$loc = array_key_exists('location_id', $head) ? $head['location_id'] : ($cur['location_id'] ?? null);
if ($lines !== null || array_key_exists('location_id', $head)) {
    $check = $lines ?? array_map(static fn (array $l): array => ['disposition' => $l['disposition'], 'location_id' => $l['location_id'], 'sku' => $l['sku']], return_lines($pdo, (int) $id));
    foreach ($check as $l) {
        if (in_array($l['disposition'], ['restock', 'floor_model'], true) && $l['location_id'] === null && $loc === null) { $errors['location'] = 'Say where it comes back to.'; break; }
    }
}
if ($errors !== []) { inv_refuse_fields($errors); }
$rid = inv_guard($pdo, static function () use ($pdo, $me, $cur, $orderId, $head, $lines): int {
    $pdo->beginTransaction();
    if ($cur === null) {
        $rid = request_return($pdo, $orderId, $head, $lines, $me);
        $r = find_return($pdo, $rid);
        return_log($pdo, 'return.request', $r, return_loggable($r) + ['lines' => array_map(static fn (array $l): array => ['sku' => $l['sku'], 'qty' => $l['qty'], 'reason_code' => $l['reason_code'], 'disposition' => $l['disposition']], $lines),
            'currency' => (string) (one_value($pdo, 'SELECT currency FROM mcp_settings') ?? 'USD')]);
        notify_buyer($pdo, 'return', 'return', $rid, 'Return ' . $r['number'] . ' requested on ' . $r['order_number'], null, 'return:' . $rid . ':requested');
    } else {
        $rid = (int) $cur['return_id'];
        $d = update_return($pdo, $rid, $head, $lines, $me);
        $r = find_return($pdo, $rid);
        return_log($pdo, 'return.update', $r, ['number' => $r['number'], 'changed' => $d['changed']] + $d['after'] + ($lines === null ? [] : ['lines' => array_map(static fn (array $l): array => ['sku' => $l['sku'], 'qty' => $l['qty'], 'reason_code' => $l['reason_code'], 'disposition' => $l['disposition']], $lines)]),
            $d['before'] === [] ? [] : ['before' => $d['before']]);
    }
    $pdo->commit();
    return $rid;
});
$r = find_return($pdo, $rid);
inv_done(($cur === null ? 'Requested return ' : 'Saved ') . $r['number'], $rid, inv_land(return_path('/returns/' . $rid), $cur === null ? 'requested' : 'saved'), 'returnChanged',
    ['return_id' => $rid, 'number' => $r['number'], 'sales_order_id' => $r['sales_order_id'], 'status' => $r['status'], 'lines' => $r['line_count']]);

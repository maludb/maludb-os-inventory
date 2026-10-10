<?php
declare(strict_types=1);
/**
 * Actions `quote_create` (no `order`; log `order.quote`) and `order_update` (with `order`, a quote only; log `order.update` — the fields changed, never an address or a note's words): orders.write.
 * A customer by id, or a new one by `new_customer_name` (+ email, phone: customer.create is logged first); `lines` as the form's lines[n][…] or JSON — a bundle expands into its components, a line with
 * no fulfilment takes the recommended one; the header `discount` only with exactly one line. On an update `lines` ADDS lines. Location /orders/{id}; refresh orderChanged.
 */
require_once dirname(__DIR__, 2) . '/app/features/orders/handler.php';
orders_write_begin('orders.write');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('order') ?? request_integer('sales_order_id');
$cur = $id === null ? null : order_or_404($pdo, $id);
if ($cur !== null) { require_quote($cur); }
$errors = [];
[$head, $newCustomer, $discount] = order_head_from_request($pdo, $cur, $errors);
$lines = order_lines_from_request($pdo, $errors);
if ($cur === null && $lines === [] && !isset($errors['lines']) && count(array_filter($errors, static fn ($k) => str_starts_with((string) $k, 'lines.'), ARRAY_FILTER_USE_KEY)) === 0) { $errors['lines'] = 'A quote has at least one line.'; }
if ($discount !== null) {
    if (count($lines) === 1 && $cur === null) { $lines[0]['discount'] = $discount; } else { $errors['discount'] = 'A discount is on a line.'; }
}
if ($newCustomer !== null && !has_right('customers.write')) { refuse(403, 'You may not ' . RIGHT_WORDS['customers.write'] . '.'); }
if ($errors !== []) { inv_refuse_fields($errors); }
try {
    $oid = inv_guard($pdo, static function () use ($pdo, $me, $id, $cur, $head, $newCustomer, $lines): int {
        $pdo->beginTransaction();
        if ($newCustomer !== null) {
            $cf = ['name' => $newCustomer['name'], 'legal_name' => null, 'email' => $newCustomer['email'], 'phone' => $newCustomer['phone'], 'phone_alt' => null, 'billing_address' => null, 'shipping_address' => null,
                   'source' => 'walk_in', 'tax_rate_id' => null, 'terms_days' => null, 'tax_id' => null, 'email_opt_in' => false, 'notes' => null];
            $cid = save_customer($pdo, null, $cf, $me);
            log_activity($pdo, 'customer.create', 'customer', $cid, ['after' => customer_loggable(find_customer($pdo, $cid))]);
            $head['customer_id'] = $cid;
        }
        if ($cur === null) {
            $head['origin'] = current_agent_run_id() !== null ? 'agent' : 'entered';
            $oid = draft_quote($pdo, $head, $lines, $me);
            $o = find_order($pdo, $oid);
            order_log($pdo, 'order.quote', $o, order_loggable($o));
        } else {
            $oid = (int) $id;
            $d = update_order($pdo, $oid, $head, $me);
            foreach ($lines as $l) {
                $lid = save_order_line($pdo, $oid, null, $l);
                order_log($pdo, 'order.line_add', $cur, line_loggable($pdo, $lid));
            }
            $o = find_order($pdo, $oid);
            if ($d['after'] !== [] || $lines !== []) {
                $keep = array_intersect_key($d['after'], array_flip(['promised_on', 'delivery_method', 'location_id', 'salesperson_member_id', 'tax_rate_id', 'shipping_charge', 'customer_id']));
                order_log($pdo, 'order.update', $o, ['number' => $o['number'], 'changed' => array_keys($d['after']), 'lines_added' => count($lines)] + $keep);
            }
        }
        $pdo->commit();
        return $oid;
    });
} catch (Throwable $e) { order_refused($e); }
$o = find_order($pdo, $oid);
inv_done(($id === null ? 'Wrote the quote ' : 'Saved ') . $o['number'], $oid, inv_land(return_path('/orders/' . $oid), $id === null ? 'created' : 'saved'), 'orderChanged',
    ['sales_order_id' => $oid, 'number' => $o['number'], 'location_id' => $o['location_id'], 'status' => $o['status'], 'total' => $o['total'], 'lines' => (int) $o['line_count']]);

<?php
/**
 * Helpers for the slice 6 proofs (docs/build-specs/purchasing.md, "Proof"). Run through tests/phase3/slice6/run.sh on the scratch database inv_dev6, the application on :8601 (under Apache :8601
 * is the PUBLIC vhost), the fake kernel :8602, the fake MaluDB :8603 and — once the world is built — THE FAKE MALUMAIL on :8606 (FAKE_MALUMAIL_LOG records each send).
 * Builds on slice 5's library (the cast, act(), screen(), order_world() — which builds slice 4's world, slice 3's sources from the fixture server and slice 1's catalog).
 *
 * The world (purchasing_world()): slice 5's world plus the suppliers' facts — SMOKE Malouf (portal, drop-ships, lead 5, account DLR-1001, an order e-mail, the price-sheet entry the Shopify pull made for the Queen given a cost of 700.00 and a minimum of 2)
 * and SMOKE Zinus (e-mail, lead 3, an order e-mail) —, the three suppliers that make MaluMail refuse (suppressed, flaky) or have no address, the Cal King that ships ltl and the King that ships parcel, the Twin under
 * its reorder point (Zinus the cheapest in-stock offer, 349.50), and ONE confirmed order "S6-MAIN" of SMOKE Alvarez with a stock Queen and two drop-ship lines (the Cal King from Malouf, the King from Zinus) whose
 * confirmation drafted one purchase order per supplier. Sam is the order's salesperson; Nora the Buyer.
 */
require dirname(__DIR__) . '/slice5/lib.php';

function po_id_of(string $number): ?int { $v = one('SELECT id FROM purchase_orders WHERE number = :n', ['n' => $number]); return $v === false || $v === null ? null : (int) $v; }
function po_row(int $id): array { return q('SELECT * FROM purchase_orders WHERE id = :id', ['id' => $id])[0]; }
function po_lines_of(int $id): array { return q('SELECT l.*, v.sku FROM purchase_order_lines l JOIN product_variants v ON v.id = l.variant_id WHERE l.purchase_order_id = :id ORDER BY l.line_no', ['id' => $id]); }
function po_events_of(int $id): array { return q('SELECT * FROM purchase_order_events WHERE purchase_order_id = :id ORDER BY id', ['id' => $id]); }
/** The drop-ship purchase order of a sales order for a supplier (the newest not cancelled), or null. */
function po_for(int $orderId, int $supplierId): ?int { $v = one("SELECT id FROM purchase_orders WHERE sales_order_id = :o AND supplier_id = :s AND status <> 'cancelled' ORDER BY id DESC LIMIT 1", ['o' => $orderId, 's' => $supplierId]); return $v === false || $v === null ? null : (int) $v; }
function po_by_note(string $note): ?int { $v = one('SELECT id FROM purchase_orders WHERE notes = :n ORDER BY id DESC LIMIT 1', ['n' => $note]); return $v === false || $v === null ? null : (int) $v; }
function supplier_row(int $id): array { return q('SELECT * FROM suppliers WHERE id = :id', ['id' => $id])[0]; }
function sup_id_of(string $name): ?int { $v = one('SELECT id FROM suppliers WHERE name = :n ORDER BY id LIMIT 1', ['n' => $name]); return $v === false || $v === null ? null : (int) $v; }
/** The raw door token of a purchase-order mail (the proof reads it out of the e-mail, as a supplier would). */
function token_from_po_mail(array $mail): ?string { return preg_match('#/s/([a-f0-9]{48})#', (string) ($mail['text'] ?? ''), $m) ? $m[1] : null; }
function last_mail(): array { $m = mail_log(); return $m === [] ? [] : end($m); }
function notifications_for(int $member, string $kind, int $since = 0): array { return q('SELECT * FROM notifications WHERE member_id = :m AND kind = :k AND id > :s ORDER BY id', ['m' => $member, 'k' => $kind, 's' => $since]); }
function last_notification_id(): int { return (int) one('SELECT COALESCE(max(id), 0) FROM notifications'); }

/** A stock purchase order through the handler: lines are [['variant' => id, 'qty' => n, …]]. Returns [status, body]. */
function make_po(string $jar, int $supplier, array $lines, array $extra = []): array
{
    return act($jar, '/purchasing/save.php', ['supplier' => $supplier, 'lines' => json_encode($lines)] + $extra);
}
/** Draft and send a stock purchase order by e-mail; returns [po id, raw door token]. */
function sent_po(array $w, int $supplier, array $lines, string $note): array
{
    [, $b] = make_po($w['nora'], $supplier, $lines, ['notes' => $note]);
    $id = (int) $b['record_id'];
    act($w['nora'], '/purchasing/send.php', ['purchase_order' => $id]);
    return [$id, token_from_po_mail(last_mail())];
}

/** The door: GET /s/<token> with a cookie jar; returns [status, html, the jar's CSRF token]. */
function door_get(string $token, string $jar): array
{
    $r = req('GET', "/s/$token", ['jar' => $jar]);
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $r['body'], $m);
    return [(int) $r['code'], $r['body'], $m[1] ?? ''];
}
/** A POST to the door: the form fields plus the CSRF token (unless `csrf` => false) and the honeypot left empty. Returns the response. */
function door_post(string $token, string $do, array $fields, string $jar, ?string $csrf, array $o = []): array
{
    $form = $fields + ['website' => ''];
    if ($csrf !== null) { $form['csrf_token'] = $csrf; }
    return req('POST', "/s/$token/$do", ['jar' => $jar, 'form' => $form] + $o);
}

function purchasing_world(): array
{
    $w = order_world();
    $nora = as_member(40);
    $sam = as_member(41);
    if (sup_id_of('SMOKE Suppressed Co') === null) {
        $run = substr(md5((string) microtime(true)), 0, 6);
        psql_exec("UPDATE suppliers SET order_method = 'portal', order_email = 'orders@malouf.example.invalid', email = 'sales@malouf.example.invalid', account_number = 'DLR-1001', terms = 'Net 30', portal_url = 'https://portal.malouf.example.invalid/' WHERE id = {$w['malouf']};"
            . " UPDATE suppliers SET order_method = 'email', order_email = 'dealers@zinus.example.invalid', account_number = 'ZN-5005' WHERE id = {$w['zinus']};"
            . " INSERT INTO suppliers (name, kind, order_method, order_email, lead_time_days) VALUES ('SMOKE Suppressed Co', 'vendor', 'email', 'suppressed-$run@example.invalid', 4), ('SMOKE Flaky Co', 'vendor', 'email', 'flaky-$run@example.invalid', 4), ('SMOKE NoMail Co', 'vendor', 'phone', NULL, 4);"
            . " UPDATE product_variants SET ships_how = 'ltl' WHERE id = {$w['calking']}; UPDATE product_variants SET ships_how = 'parcel' WHERE id = {$w['king']};"
            . " UPDATE supplier_items SET cost = 700.00, lead_time_days = 5, moq = 2 WHERE supplier_id = {$w['malouf']} AND variant_id = {$w['queen']};"
            . " UPDATE supplier_items SET supplier_sku = 'MAL-K' WHERE supplier_id = {$w['malouf']} AND variant_id = {$w['king']};"
            . " UPDATE product_variants SET reorder_point = 5, reorder_qty = 6 WHERE id = {$w['twin']}");
        // the confirmed order: a stock Queen and the two drop-ships
        [, $b] = make_quote($sam, $w['alvarez'], [['variant' => $w['queen'], 'qty' => 1, 'fulfilment' => 'stock:' . $w['wh']],
            ['variant' => $w['calking'], 'qty' => 1, 'fulfilment' => 'dropship:' . $w['lv_malouf_ck']], ['variant' => $w['king'], 'qty' => 1, 'fulfilment' => 'dropship:' . $w['lv_zinus_k']]],
            ['customer_reference' => 'S6-MAIN', 'delivery_method' => 'ltl', 'promised_on' => date('Y-m-d', strtotime('+14 days'))]);
        act($sam, '/orders/confirm.php', ['order' => (int) $b['record_id']]);
    }
    $w['suppressed'] = sup_id_of('SMOKE Suppressed Co');
    $w['flaky'] = sup_id_of('SMOKE Flaky Co');
    $w['nomail'] = sup_id_of('SMOKE NoMail Co');
    $w['main'] = ref_order('S6-MAIN');
    $w['po_malouf'] = $w['main'] ? po_for($w['main'], $w['malouf']) : null;
    $w['po_zinus'] = $w['main'] ? po_for($w['main'], $w['zinus']) : null;
    $w['twin_zinus_lv'] = lv_for($w['twin'], $w['src_zinus_feed']);
    return $w;
}

/**
 * A fresh confirmed order of SMOKE Alvarez with the Cal King drop-shipped by Malouf and the King by Zinus (Sam the salesperson), both purchase orders sent by e-mail. Returns
 * ['order' =>, 'malouf' => po, 'zinus' => po, 'tok_malouf' =>, 'tok_zinus' =>, 'ck_line' =>, 'k_line' => (purchase order line ids)].
 */
function dropship_pair(array $w, string $tag): array
{
    [, $b] = make_quote($w['sam'], $w['alvarez'], [['variant' => $w['calking'], 'qty' => 1, 'fulfilment' => 'dropship:' . $w['lv_malouf_ck']], ['variant' => $w['king'], 'qty' => 1, 'fulfilment' => 'dropship:' . $w['lv_zinus_k']]],
        ['customer_reference' => $tag, 'delivery_method' => 'ltl']);
    $o = (int) $b['record_id'];
    act($w['sam'], '/orders/confirm.php', ['order' => $o]);
    $pm = po_for($o, $w['malouf']);
    $pz = po_for($o, $w['zinus']);
    act($w['nora'], '/purchasing/send.php', ['purchase_order' => $pm]);
    $tm = token_from_po_mail(last_mail());
    act($w['nora'], '/purchasing/send.php', ['purchase_order' => $pz]);
    $tz = token_from_po_mail(last_mail());
    return ['order' => $o, 'malouf' => $pm, 'zinus' => $pz, 'tok_malouf' => $tm, 'tok_zinus' => $tz, 'ck_line' => (int) po_lines_of($pm)[0]['id'], 'k_line' => (int) po_lines_of($pz)[0]['id']];
}

<?php
declare(strict_types=1);

/**
 * Orders' writes (orders.md "Query functions"): INSERT of a quote and its lines (the trigger prices a line and snapshots a drop-ship's offer), the verbs of db/010 and
 * db/011 called inside the caller's transaction (confirm, ship, deliver, close, cancel, a line's cancel), payments, the customer's link and the two mails. The database
 * is the referee: PHP supplies the facts and shows the sentence. Required by the orders handler prelude.
 */

/** A mail that could not go: a 503 for the handler (a 422 is a DomainException, caught by inv_guard()). */
final class OrderMailUnavailable extends RuntimeException {}

const ORDER_HEAD_COLUMNS = ['customer_id', 'salesperson_member_id', 'location_id', 'promised_on', 'delivery_method', 'ship_to_name', 'ship_to_address1', 'ship_to_address2', 'ship_to_city',
    'ship_to_region', 'ship_to_postal', 'ship_to_country', 'ship_to_phone', 'ship_to_notes', 'tax_rate_id', 'shipping_charge', 'customer_reference', 'notes'];

/** A quote: the order row (status quote, origin entered / agent) then each line. Returns the order id. $head: ORDER_HEAD_COLUMNS + origin. */
function draft_quote(PDO $pdo, array $head, array $lines, int $by): int
{
    $args = [];
    foreach (ORDER_HEAD_COLUMNS as $c) { if (($head[$c] ?? null) !== null) { $args[$c] = $head[$c]; } }       // a column left out takes its default (the triggers fill the ship-to and the tax rate)
    $args['origin'] = $head['origin'] ?? 'entered';
    $args['created_by'] = $by;
    $cols = array_keys($args);
    $st = $pdo->prepare('INSERT INTO sales_orders (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_map(static fn (string $c): string => ':' . $c, $cols)) . ') RETURNING id');
    $st->execute($args);
    $id = (int) $st->fetchColumn();
    foreach ($lines as $l) { save_order_line($pdo, $id, null, $l); }
    return $id;
}

/** The header of a quote: the columns given change, the others stay. Returns ['before' => [...], 'after' => [...]] of the changed columns. */
function update_order(PDO $pdo, int $id, array $head, int $by): array
{
    $cur = one_row($pdo, 'SELECT ' . implode(', ', ORDER_HEAD_COLUMNS) . ' FROM sales_orders WHERE id = :id FOR UPDATE', ['id' => $id]);
    if ($cur === null) { throw new DomainException('Not found.'); }
    $set = [];
    $args = ['id' => $id];
    foreach (ORDER_HEAD_COLUMNS as $c) {
        if (array_key_exists($c, $head)) { $set[] = "$c = :$c"; $args[$c] = $head[$c]; }
    }
    if ($set !== []) { $pdo->prepare('UPDATE sales_orders SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($args); }
    $new = one_row($pdo, 'SELECT ' . implode(', ', ORDER_HEAD_COLUMNS) . ' FROM sales_orders WHERE id = :id', ['id' => $id]);
    $before = [];
    $after = [];
    foreach (ORDER_HEAD_COLUMNS as $c) {
        if ((string) ($cur[$c] ?? '') !== (string) ($new[$c] ?? '')) { $before[$c] = $cur[$c]; $after[$c] = $new[$c]; }
    }
    return ['before' => $before, 'after' => $after];
}

/** A line of a quote: INSERT (the next line_no) or UPDATE by id. The trigger prices it (the retail when unit_price is null) and refuses what it refuses. Returns the line id. */
function save_order_line(PDO $pdo, int $orderId, ?int $lineId, array $f): int
{
    $args = ['variant' => $f['variant_id'], 'qty' => $f['qty'], 'price' => $f['unit_price'], 'disc' => $f['discount'] ?? '0.00', 'kind' => $f['fulfilment_kind'], 'loc' => $f['location_id'],
             'lv' => $f['listing_variant_id'], 'notes' => $f['notes']];
    if ($lineId === null) {
        $pdo->prepare('SELECT 1 FROM sales_orders WHERE id = :o FOR UPDATE')->execute(['o' => $orderId]);
        $st = $pdo->prepare('INSERT INTO sales_order_lines (sales_order_id, line_no, variant_id, qty, unit_price, discount, fulfilment_kind, location_id, listing_variant_id, notes)
                             VALUES (:o, (SELECT COALESCE(max(line_no), 0) + 1 FROM sales_order_lines WHERE sales_order_id = :o2), :variant, :qty, :price, :disc, :kind, :loc, :lv, :notes) RETURNING id');
        $st->execute($args + ['o' => $orderId, 'o2' => $orderId]);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE sales_order_lines SET variant_id = :variant, qty = :qty, unit_price = :price, discount = :disc, fulfilment_kind = :kind, location_id = :loc, listing_variant_id = :lv, notes = :notes
                    WHERE id = :id AND sales_order_id = :o')->execute($args + ['id' => $lineId, 'o' => $orderId]);
    return $lineId;
}

/** Re-fulfil a line: the kind and what goes with it (the trigger re-snapshots an offer when the listing variant changed and nulls what does not apply). Returns the line's columns after. */
function set_line_fulfilment(PDO $pdo, int $lineId, array $f): array
{
    $pdo->prepare('UPDATE sales_order_lines SET fulfilment_kind = :k, location_id = :l, listing_variant_id = :lv WHERE id = :id')->execute(['k' => $f['kind'], 'l' => $f['location_id'], 'lv' => $f['listing_variant_id'], 'id' => $lineId]);
    return one_row($pdo, 'SELECT fulfilment_kind, location_id, listing_variant_id, source_id, offer_cost, offer_lead_time_days FROM sales_order_lines WHERE id = :id', ['id' => $lineId]) ?? [];
}

/** Cancel one line (inv_order_line_cancel(): releases its allocation; a shipped line is refused). Returns ['released' => qty, 'was_ordered' => bool, 'status_before']. */
function cancel_order_line(PDO $pdo, int $lineId, int $by): array
{
    $l = one_row($pdo, 'SELECT qty_allocated, status, purchase_order_line_id, sales_order_id FROM sales_order_lines WHERE id = :id', ['id' => $lineId]);
    if ($l === null) { throw new DomainException('Not found.'); }
    $pdo->prepare('SELECT inv_order_line_cancel(:id, :by)')->execute(['id' => $lineId, 'by' => $by]);
    return ['released' => (int) $l['qty_allocated'], 'was_ordered' => $l['status'] === 'ordered', 'status_before' => $l['status'], 'purchase_order_line_id' => $l['purchase_order_line_id'] === null ? null : (int) $l['purchase_order_line_id']];
}

/** Confirm (inv_order_confirm(): allocates, refuses what it cannot cover, drafts one drop-ship PO per supplier). Returns {allocated[], dropships_drafted[], backordered[]}. */
function confirm_order(PDO $pdo, int $id, int $by): array
{
    $pdo->prepare('SELECT * FROM inv_order_confirm(:id, :by)')->execute(['id' => $id, 'by' => $by]);
    $allocated = $pdo->prepare("SELECT v.sku, l.location_id, l.qty_allocated AS qty FROM sales_order_lines l JOIN product_variants v ON v.id = l.variant_id WHERE l.sales_order_id = :o AND l.qty_allocated > 0 ORDER BY l.line_no");
    $allocated->execute(['o' => $id]);
    $pos = $pdo->prepare("SELECT id FROM purchase_orders WHERE sales_order_id = :o AND kind = 'dropship' ORDER BY id");
    $pos->execute(['o' => $id]);
    $back = $pdo->prepare("SELECT v.sku FROM sales_order_lines l JOIN product_variants v ON v.id = l.variant_id WHERE l.sales_order_id = :o AND l.fulfilment_kind = 'backorder' AND l.status <> 'cancelled' ORDER BY l.line_no");
    $back->execute(['o' => $id]);
    return ['allocated' => array_map(static fn (array $r): array => ['sku' => $r['sku'], 'location_id' => (int) $r['location_id'], 'qty' => (int) $r['qty']], $allocated->fetchAll()),
            'dropships_drafted' => array_map('intval', $pos->fetchAll(PDO::FETCH_COLUMN)), 'backordered' => $back->fetchAll(PDO::FETCH_COLUMN)];
}

/** A payment (kind deposit / balance / refund). A refund may not exceed what was paid. Returns the payment id. */
function record_payment(PDO $pdo, int $orderId, array $f, int $by): int
{
    $paid = one_row($pdo, 'SELECT amount_paid FROM sales_orders WHERE id = :o FOR UPDATE', ['o' => $orderId]);
    if ($paid === null) { throw new DomainException('Not found.'); }
    if ($f['kind'] === 'refund' && (float) $f['amount'] > (float) $paid['amount_paid']) {
        throw new DomainException('Refund exceeds what was paid (' . number_format((float) $paid['amount_paid'], 2) . ').');
    }
    $st = $pdo->prepare('INSERT INTO order_payments (sales_order_id, kind, amount, method, reference, taken_at, note, taken_by) VALUES (:o, :k, :a, :m, :r, COALESCE(CAST(:at AS timestamptz), now()), :n, :by) RETURNING id');
    $st->execute(['o' => $orderId, 'k' => $f['kind'], 'a' => $f['amount'], 'm' => $f['method'], 'r' => $f['reference'], 'at' => $f['taken_at'], 'n' => $f['note'], 'by' => $by]);
    return (int) $st->fetchColumn();
}

/**
 * Ship (inv_order_ship()): $lines [{line_id, qty, serials[]}]. A stock line issues a `sale` movement and releases its allocation. The tracking URL is written from the fixed map.
 * Returns {shipment_id, kind, issued: [{sku, location_id, qty}], lines: [{sku, qty}]}.
 */
function ship_order(PDO $pdo, int $orderId, array $lines, string $kind, ?string $carrier, ?string $tracking, ?string $shippedAt, int $by): array
{
    $st = $pdo->prepare('SELECT id FROM inv_order_ship(:o, :by, CAST(:lines AS jsonb), :kind, :carrier, :tracking, COALESCE(CAST(:at AS timestamptz), now()))');
    $st->execute(['o' => $orderId, 'by' => $by, 'lines' => json_encode($lines, JSON_UNESCAPED_UNICODE), 'kind' => $kind, 'carrier' => $carrier, 'tracking' => $tracking, 'at' => $shippedAt]);
    $sid = (int) $st->fetchColumn();
    $url = tracking_url($carrier, $tracking);
    if ($url !== null) { $pdo->prepare('UPDATE shipments SET tracking_url = :u WHERE id = :id')->execute(['u' => $url, 'id' => $sid]); }
    $issued = $pdo->prepare("SELECT v.sku, t.location_id, -t.qty AS qty FROM inventory_transactions t JOIN product_variants v ON v.id = t.variant_id WHERE t.reference_kind = 'shipment' AND t.reference_id = :s AND t.txn_type = 'sale' ORDER BY t.id");
    $issued->execute(['s' => $sid]);
    $ls = $pdo->prepare('SELECT v.sku, sl.qty FROM shipment_lines sl JOIN sales_order_lines l ON l.id = sl.sales_order_line_id JOIN product_variants v ON v.id = l.variant_id WHERE sl.shipment_id = :s ORDER BY l.line_no');
    $ls->execute(['s' => $sid]);
    return ['shipment_id' => $sid, 'kind' => $kind, 'issued' => array_map(static fn (array $r): array => ['sku' => $r['sku'], 'location_id' => (int) $r['location_id'], 'qty' => (int) $r['qty']], $issued->fetchAll()),
            'lines' => array_map(static fn (array $r): array => ['sku' => $r['sku'], 'qty' => (int) $r['qty']], $ls->fetchAll()), 'tracking_url' => $url];
}

/** Deliver (inv_shipment_deliver()): the shipment's lines are delivered; a drop-ship shipment receives its PO lines (db/011's trigger). Returns the shipment row. */
function deliver_shipment(PDO $pdo, int $shipmentId, ?string $at, int $by): array
{
    $st = $pdo->prepare('SELECT id, sales_order_id, kind, delivered_at FROM inv_shipment_deliver(:s, :by, COALESCE(CAST(:at AS timestamptz), now()))');
    $st->execute(['s' => $shipmentId, 'by' => $by, 'at' => $at]);
    return $st->fetch() ?: [];
}

/** Close (inv_order_close()): delivered → closed, the customer's link starts its 180 days. */
function close_order(PDO $pdo, int $id, int $by): array
{
    $st = $pdo->prepare('SELECT id, status, closed_at FROM inv_order_close(:id, :by)');
    $st->execute(['id' => $id, 'by' => $by]);
    return $st->fetch() ?: [];
}

/**
 * Cancel (inv_order_cancel()): allocations released, lines cancelled, the DRAFT drop-ship POs cancelled by the SQL. The Buyer is told of every drop-ship PO of the order that
 * was already sent (sent, acknowledged, partial) — those are cancelled with the supplier by hand. Returns {released[], dropships_cancelled, dropships_to_cancel_by_hand}.
 */
function cancel_order(PDO $pdo, int $id, int $by, string $reason): array
{
    $rel = $pdo->prepare('SELECT v.sku, l.location_id, l.qty_allocated AS qty FROM sales_order_lines l JOIN product_variants v ON v.id = l.variant_id WHERE l.sales_order_id = :o AND l.qty_allocated > 0 ORDER BY l.line_no');
    $rel->execute(['o' => $id]);
    $released = array_map(static fn (array $r): array => ['sku' => $r['sku'], 'location_id' => (int) $r['location_id'], 'qty' => (int) $r['qty']], $rel->fetchAll());
    $draftBefore = (int) one_value($pdo, "SELECT count(*) FROM purchase_orders WHERE sales_order_id = :o AND kind = 'dropship' AND status = 'draft'", ['o' => $id]);
    $pdo->prepare('SELECT * FROM inv_order_cancel(:id, :by, :reason)')->execute(['id' => $id, 'by' => $by, 'reason' => $reason]);
    $number = (string) one_value($pdo, 'SELECT number FROM sales_orders WHERE id = :o', ['o' => $id]);
    $sent = $pdo->prepare("SELECT po.id, po.number, s.name AS supplier FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id WHERE po.sales_order_id = :o AND po.kind = 'dropship' AND po.status IN ('sent', 'acknowledged', 'partial') ORDER BY po.id");
    $sent->execute(['o' => $id]);
    $byHand = $sent->fetchAll();
    foreach ($byHand as $po) {
        notify_buyer($pdo, 'order', 'purchase_order', (int) $po['id'], 'Cancel ' . $po['number'] . ' with ' . $po['supplier'] . ': ' . $number . ' was cancelled');
    }
    return ['released' => $released, 'dropships_cancelled' => $draftBefore, 'dropships_to_cancel_by_hand' => count($byHand)];
}

// The Buyer and notify_buyer() are slice 8's (app/features/notify/queue.php, required by the bootstrap): the settings' Buyer, else the first super-admin.

/** Mint the customer's link (inv_order_link_mint(): the previous live one is rotated). The raw token is returned ONCE — it lives in the email alone. */
function mint_order_link(PDO $pdo, int $orderId): string
{
    return (string) one_value($pdo, 'SELECT inv_order_link_mint(:o)', ['o' => $orderId]);
}

/** One email to the customer through MaluMail, inline. Returns the message id (or null). A refused address is a DomainException (422); a transport failure OrderMailUnavailable (503). */
function order_mail_send(array $payload): ?string
{
    if ((string) env('MALUMAIL_API_KEY', '') === '') {
        throw new OrderMailUnavailable("Email is not configured — the installer's mail step writes the key.");
    }
    try {
        $r = malumail_send($payload);
    } catch (Throwable $e) {
        error_log('order mail: ' . $e->getMessage());
        throw new OrderMailUnavailable('Mail could not be sent — try again.');
    }
    $body = $r['body'];
    if ($r['status'] >= 500 || $r['status'] === 0) { throw new OrderMailUnavailable('Mail could not be sent — try again.'); }
    if ($r['status'] === 401 || $r['status'] === 403) { throw new OrderMailUnavailable("Email is not configured — the installer's mail step writes the key."); }
    $rejected = (array) ($body['rejected'] ?? []);
    if ($r['status'] >= 400 || (($body['accepted'] ?? null) === [] && $rejected !== []) || ($r['status'] >= 200 && $r['status'] < 300 && isset($body['accepted']) && $body['accepted'] === [])) {
        $reason = (string) ($rejected[0]['reason'] ?? $body['error'] ?? 'not accepted');
        throw new DomainException('MaluMail refused the address: ' . $reason);
    }
    return $r['message_id'];
}

/**
 * Send the confirmation (orders.md): ONE transaction — the link is minted (the previous live link is rotated), the mail goes, the transaction commits only when MaluMail
 * accepted it. Returns {link_id, message_id, rotated}. The raw token is nowhere but in the email.
 */
function send_order(PDO $pdo, int $orderId, ?string $message, int $by): array
{
    $o = one_row($pdo, 'SELECT * FROM mcp_sales_orders WHERE sales_order_id = :o', ['o' => $orderId]);
    $email = (string) one_value($pdo, 'SELECT email FROM mcp_customers WHERE customer_id = :c', ['c' => (int) $o['customer_id']]);
    $lines = order_lines($pdo, $orderId);
    $settings = order_mail_settings($pdo);
    $pdo->beginTransaction();
    $rotated = one_value($pdo, 'SELECT 1 FROM order_links_secure WHERE sales_order_id = :o AND rotated_at IS NULL AND (expires_at IS NULL OR expires_at > now()) LIMIT 1', ['o' => $orderId]) !== null;
    $raw = mint_order_link($pdo, $orderId);
    $linkId = (int) one_value($pdo, 'SELECT max(id) FROM order_links_secure WHERE sales_order_id = :o AND rotated_at IS NULL', ['o' => $orderId]);
    $messageId = order_mail_send(order_mail_payload(order_confirmation_mail($o, $lines, $settings, $raw, $message), $email, $settings));
    $pdo->commit();
    return ['link_id' => $linkId, 'message_id' => $messageId, 'rotated' => $rotated];
}

/** Send a notice (a date, a delay, ready, shipped): a promised_on given with delivery_date / delay moves the order's date in the same transaction; rolled back if the mail is refused. */
function notify_order(PDO $pdo, int $orderId, string $kind, ?string $message, ?string $promisedOn, int $by): array
{
    $o = one_row($pdo, 'SELECT * FROM mcp_sales_orders WHERE sales_order_id = :o', ['o' => $orderId]);
    $email = (string) one_value($pdo, 'SELECT email FROM mcp_customers WHERE customer_id = :c', ['c' => (int) $o['customer_id']]);
    $settings = order_mail_settings($pdo);
    $pdo->beginTransaction();
    $moved = false;
    if ($promisedOn !== null && in_array($kind, ['delivery_date', 'delay'], true)) {
        $pdo->prepare('UPDATE sales_orders SET promised_on = CAST(:d AS date) WHERE id = :o')->execute(['d' => $promisedOn, 'o' => $orderId]);
        $moved = true;
    }
    $messageId = order_mail_send(order_mail_payload(order_notice_mail($o, $kind, $message, $promisedOn, $settings), $email, $settings));
    $pdo->commit();
    return ['message_id' => $messageId, 'promised_moved' => $moved];
}

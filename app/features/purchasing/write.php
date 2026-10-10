<?php
declare(strict_types=1);

/**
 * Purchase orders' writes (purchasing.md "Query functions"): INSERT of a stock purchase order and its lines (the trigger fills the supplier SKU and refuses a bundle), the drop-ship draft (the
 * database drafts it), the verbs of db/011 called inside the caller's transaction (send, place, acknowledge, decline, tracking, receive against, close, cancel) and the supplier's link with its
 * two mails. The database is the referee: PHP supplies the facts and shows the sentence. Required by the purchasing handler prelude and by the supplier's door.
 */

const PO_HEAD_COLUMNS = ['supplier_id', 'location_id', 'expected_on', 'shipping_cost', 'notes', 'internal_notes'];

/** A stock purchase order: the row (kind stock, ship-to a location) then each line. Returns the id. $head: PO_HEAD_COLUMNS. */
function draft_purchase_order(PDO $pdo, array $head, array $lines, int $by): int
{
    $args = [];
    foreach (PO_HEAD_COLUMNS as $c) { if (($head[$c] ?? null) !== null) { $args[$c] = $head[$c]; } }       // a column left out takes its default
    $args['kind'] = 'stock';
    $args['ship_to_kind'] = 'location';
    $args['created_by'] = $by;
    $cols = array_keys($args);
    $st = $pdo->prepare('INSERT INTO purchase_orders (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_map(static fn (string $c): string => ':' . $c, $cols)) . ') RETURNING id');
    $st->execute($args);
    $id = (int) $st->fetchColumn();
    foreach ($lines as $l) { save_po_line($pdo, $id, null, $l); }
    return $id;
}

/** A line of a draft: INSERT (the next line_no) or UPDATE by id. $f: variant_id, qty, unit_cost, supplier_sku, listing_variant_id, expected_on. The trigger fills the supplier SKU and refuses a bundle and a non-draft. Returns the line id. */
function save_po_line(PDO $pdo, int $poId, ?int $lineId, array $f): int
{
    $args = ['variant' => $f['variant_id'], 'qty' => $f['qty'], 'cost' => $f['unit_cost'], 'ssku' => $f['supplier_sku'] ?? null, 'lv' => $f['listing_variant_id'] ?? null, 'exp' => $f['expected_on'] ?? null];
    if ($lineId === null) {
        $pdo->prepare('SELECT 1 FROM purchase_orders WHERE id = :o FOR UPDATE')->execute(['o' => $poId]);
        $st = $pdo->prepare('INSERT INTO purchase_order_lines (purchase_order_id, line_no, variant_id, qty_ordered, unit_cost, supplier_sku, listing_variant_id, expected_on)
                             VALUES (:o, (SELECT COALESCE(max(line_no), 0) + 1 FROM purchase_order_lines WHERE purchase_order_id = :o2), :variant, :qty, :cost, :ssku, :lv, CAST(:exp AS date)) RETURNING id');
        $st->execute($args + ['o' => $poId, 'o2' => $poId]);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE purchase_order_lines SET variant_id = :variant, qty_ordered = :qty, unit_cost = :cost, supplier_sku = :ssku, listing_variant_id = :lv, expected_on = CAST(:exp AS date)
                    WHERE id = :id AND purchase_order_id = :o')->execute($args + ['id' => $lineId, 'o' => $poId]);
    return $lineId;
}

function remove_po_line(PDO $pdo, int $lineId): void
{
    $pdo->prepare('DELETE FROM purchase_order_lines WHERE id = :id')->execute(['id' => $lineId]);
}

/**
 * The header of a draft: the columns given change, the others stay. A new supplier clears the lines' supplier SKUs and offers (they are the old supplier's; the trigger re-reads the SKUs from the
 * new supplier's price sheet). Returns ['before' => [...], 'after' => [...]] of the changed columns.
 */
function update_purchase_order(PDO $pdo, int $id, array $head, int $by): array
{
    $cur = one_row($pdo, 'SELECT ' . implode(', ', PO_HEAD_COLUMNS) . ' FROM purchase_orders WHERE id = :id FOR UPDATE', ['id' => $id]);
    if ($cur === null) { throw new DomainException('Not found.'); }
    $set = [];
    $args = ['id' => $id];
    foreach (PO_HEAD_COLUMNS as $c) {
        if (array_key_exists($c, $head)) { $set[] = $c === 'expected_on' ? 'expected_on = CAST(:expected_on AS date)' : "$c = :$c"; $args[$c] = $head[$c]; }
    }
    if ($set !== []) { $pdo->prepare('UPDATE purchase_orders SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($args); }
    $new = one_row($pdo, 'SELECT ' . implode(', ', PO_HEAD_COLUMNS) . ' FROM purchase_orders WHERE id = :id', ['id' => $id]);
    $before = [];
    $after = [];
    foreach (PO_HEAD_COLUMNS as $c) {
        if ((string) ($cur[$c] ?? '') !== (string) ($new[$c] ?? '')) { $before[$c] = $cur[$c]; $after[$c] = $new[$c]; }
    }
    if (isset($after['supplier_id'])) {
        $pdo->prepare('UPDATE purchase_order_lines SET supplier_sku = NULL, listing_variant_id = NULL WHERE purchase_order_id = :id')->execute(['id' => $id]);
    }
    return ['before' => $before, 'after' => $after];
}

/**
 * Draft the order's open drop-ship lines — one purchase order per supplier (inv_order_dropships_draft()): the offer's cost and lead time from the LINE's snapshot, the ship-to the customer's. Returns the purchase order ids
 * drafted. Nothing to draft is a DomainException.
 */
function draft_dropships_for_order(PDO $pdo, int $orderId, int $by): array
{
    $o = one_row($pdo, 'SELECT number, status FROM sales_orders WHERE id = :o', ['o' => $orderId]);
    if ($o === null) { throw new DomainException('Not found.'); }
    if ($o['status'] === 'quote') { throw new DomainException($o['number'] . ' is a quote — confirm it first; its drop-ships are drafted then.'); }
    if (in_array($o['status'], ['cancelled', 'closed'], true)) { throw new DomainException($o['number'] . ' is ' . $o['status'] . ' — there is nothing to draft.'); }
    $open = $pdo->prepare("SELECT l.line_no, s.supplier_id FROM sales_order_lines l LEFT JOIN sources s ON s.id = l.source_id
                            WHERE l.sales_order_id = :o AND l.fulfilment_kind = 'dropship' AND l.status = 'open' AND l.purchase_order_line_id IS NULL ORDER BY l.line_no");
    $open->execute(['o' => $orderId]);
    $rows = $open->fetchAll();
    if ($rows === []) { throw new DomainException($o['number'] . ' has no open drop-ship line.'); }
    foreach ($rows as $r) {
        if ($r['supplier_id'] === null) { throw new DomainException('Line ' . $r['line_no'] . "'s source has no supplier — set the supplier on the source first."); }
    }
    $before = (int) one_value($pdo, 'SELECT COALESCE(max(id), 0) FROM purchase_orders');
    $pdo->prepare('SELECT inv_order_dropships_draft(:o, :by)')->execute(['o' => $orderId, 'by' => $by]);
    $st = $pdo->prepare("SELECT id FROM purchase_orders WHERE sales_order_id = :o AND kind = 'dropship' AND id > :b ORDER BY id");
    $st->execute(['o' => $orderId, 'b' => $before]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

// ---- the mail -------------------------------------------------------------------------------------------------------------
/** Everything a purchase order's email says, read from the base tables (the caller holds purchasing.write): [po, lines, supplier, settings, show_phone, to]. */
function po_for_mail(PDO $pdo, int $poId): array
{
    $po = one_row($pdo, 'SELECT po.*, (SELECT l.name FROM locations l WHERE l.id = po.location_id) AS location_name, (SELECT l.address FROM locations l WHERE l.id = po.location_id) AS location_address,
                                (SELECT so.number FROM sales_orders so WHERE so.id = po.sales_order_id) AS sales_order_number FROM purchase_orders po WHERE po.id = :id', ['id' => $poId]);
    if ($po === null) { throw new DomainException('Not found.'); }
    $st = $pdo->prepare("SELECT pl.line_no, pl.supplier_sku, v.sku, p.name AS product_name, inv_size_name(v.size_key) AS size_name, pl.qty_ordered, pl.unit_cost, pl.qty_ordered * pl.unit_cost AS line_cost, pl.expected_on, pl.status
                           FROM purchase_order_lines pl JOIN product_variants v ON v.id = pl.variant_id JOIN products p ON p.id = v.product_id
                          WHERE pl.purchase_order_id = :po AND pl.status NOT IN ('declined', 'cancelled') ORDER BY pl.line_no");
    $st->execute(['po' => $poId]);
    $supplier = one_row($pdo, 'SELECT id AS supplier_id, name, email, order_email, account_number, order_method FROM suppliers WHERE id = :id', ['id' => $po['supplier_id']]);
    $to = (string) ($supplier['order_email'] ?? '') !== '' ? (string) $supplier['order_email'] : (string) ($supplier['email'] ?? '');
    return ['po' => $po, 'lines' => $st->fetchAll(), 'supplier' => $supplier, 'settings' => order_mail_settings($pdo), 'show_phone' => po_shows_phone($pdo, $poId), 'to' => $to];
}

// ---- the verbs ------------------------------------------------------------------------------------------------------------
/**
 * Send (inv_po_send()): ONE transaction — the draft becomes sent; by email the supplier's link is minted (the raw token lives nowhere but in the email), the mail goes through MaluMail, and the transaction
 * commits only when MaluMail accepted it; by phone nothing is mailed and no link is minted. A supplier with no email address is refused before MaluMail is asked. Returns {link_id, message_id, sent_via}.
 */
function send_purchase_order(PDO $pdo, int $poId, string $via, ?string $message, int $by): array
{
    if (!in_array($via, ['email', 'phone'], true)) { throw new DomainException('Send by email or by phone.'); }
    $pdo->beginTransaction();
    $pdo->prepare('SELECT * FROM inv_po_send(:po, :by, :via)')->execute(['po' => $poId, 'by' => $by, 'via' => $via]);
    if ($via === 'phone') {
        $pdo->commit();
        return ['link_id' => null, 'message_id' => null, 'sent_via' => 'phone'];
    }
    $d = po_for_mail($pdo, $poId);
    if ($d['to'] === '') { throw new DomainException('The supplier has no email address — mark it placed or sent by phone.'); }
    $raw = (string) one_value($pdo, 'SELECT inv_supplier_link_mint(:po)', ['po' => $poId]);
    $linkId = (int) one_value($pdo, 'SELECT max(id) FROM supplier_links_secure WHERE purchase_order_id = :po AND rotated_at IS NULL', ['po' => $poId]);
    $mail = purchase_order_mail($d['po'], $d['lines'], $d['supplier'], $d['settings'], $raw, $message, $d['show_phone']);
    $messageId = order_mail_send(order_mail_payload($mail, $d['to'], $d['settings']));
    $pdo->commit();
    return ['link_id' => $linkId, 'message_id' => $messageId, 'sent_via' => 'email'];
}

/** Place (inv_po_place()): recorded as placed on the supplier's portal (or API / EDI) with their reference; a draft is sent first by the SQL. No link, no email. Returns the order row's status and reference. */
function place_purchase_order(PDO $pdo, int $poId, string $ref, string $via, int $by): array
{
    $st = $pdo->prepare('SELECT status, supplier_order_ref, sent_via FROM inv_po_place(:po, :by, :ref, :via)');
    $st->execute(['po' => $poId, 'by' => $by, 'ref' => $ref, 'via' => $via]);
    return $st->fetch() ?: [];
}

/** A line must be on the order it is asked about. */
function require_po_line(PDO $pdo, int $poId, int $lineId): array
{
    $l = one_row($pdo, 'SELECT id, line_no, status, purchase_order_id, sales_order_line_id FROM purchase_order_lines WHERE id = :l', ['l' => $lineId]);
    if ($l === null || (int) $l['purchase_order_id'] !== $poId) { throw new DomainException('That line is not on this purchase order.'); }
    return $l;
}

/** Acknowledge (inv_po_acknowledge()): the whole order (line null) or one line, with the supplier's reference and an expected date. $source 'manual' (a person) or 'portal' (the door, $by null). */
function acknowledge_purchase_order(PDO $pdo, int $poId, ?int $lineId, ?string $ref, ?string $expected, string $source, ?int $by): array
{
    if ($lineId !== null) { require_po_line($pdo, $poId, $lineId); }
    $st = $pdo->prepare('SELECT status, supplier_order_ref, expected_on, acknowledged_at FROM inv_po_acknowledge(:po, :line, :ref, CAST(:exp AS date), :src, :by)');
    $st->execute(['po' => $poId, 'line' => $lineId, 'ref' => $ref, 'exp' => $expected, 'src' => $source, 'by' => $by]);
    return $st->fetch() ?: [];
}

/**
 * Decline a line (inv_po_decline_line()): the customer's line goes back to open. The order's salesperson is told (`line_at_risk`) when the purchase order is a drop-ship. Returns
 * {purchase_order_id, line_no, sales_order_id, salesperson_told}.
 */
function decline_po_line(PDO $pdo, int $lineId, string $reason, string $source, ?int $by): array
{
    $l = one_row($pdo, 'SELECT pl.id, pl.line_no, pl.purchase_order_id, pl.sales_order_line_id, po.number, po.kind, s.name AS supplier_name FROM purchase_order_lines pl JOIN purchase_orders po ON po.id = pl.purchase_order_id
                          JOIN suppliers s ON s.id = po.supplier_id WHERE pl.id = :l', ['l' => $lineId]);
    if ($l === null) { throw new DomainException('Not found.'); }
    $pdo->prepare('SELECT * FROM inv_po_decline_line(:l, :reason, :src, :by)')->execute(['l' => $lineId, 'reason' => $reason, 'src' => $source, 'by' => $by]);
    $told = false;
    $soId = null;
    if ($l['kind'] === 'dropship' && $l['sales_order_line_id'] !== null) {
        $so = one_row($pdo, 'SELECT o.id, o.number, sol.line_no FROM sales_order_lines sol JOIN sales_orders o ON o.id = sol.sales_order_id WHERE sol.id = :s', ['s' => $l['sales_order_line_id']]);
        if ($so !== null) {
            $soId = (int) $so['id'];
            $told = notify_salesperson_line($pdo, (int) $l['sales_order_line_id'], 'line_at_risk', 'Line ' . $so['line_no'] . ' of ' . $so['number'] . ' was declined by ' . $l['supplier_name'] . ': ' . $reason) !== null;
        }
    }
    return ['purchase_order_id' => (int) $l['purchase_order_id'], 'line_no' => (int) $l['line_no'], 'sales_order_id' => $soId, 'salesperson_told' => $told, 'number' => $l['number'], 'supplier_name' => $l['supplier_name']];
}

/** Tell the salesperson of the sales order a sales line belongs to (a notifications row and the outbox per their prefs). Null when the order has no salesperson. */
function notify_salesperson_line(PDO $pdo, int $salesOrderLineId, string $kind, string $title): ?int
{
    $r = one_row($pdo, 'SELECT o.id, o.salesperson_member_id FROM sales_order_lines sol JOIN sales_orders o ON o.id = sol.sales_order_id WHERE sol.id = :s', ['s' => $salesOrderLineId]);
    if ($r === null || $r['salesperson_member_id'] === null) { return null; }
    $id = one_value($pdo, 'SELECT inv_notify(:m, :k, :rt, :rid, :t, NULL)', ['m' => (int) $r['salesperson_member_id'], 'k' => $kind, 'rt' => 'sales_order', 'rid' => (int) $r['id'], 't' => mb_substr($title, 0, 300)]);
    return $id === null ? null : (int) $id;
}

/** Tracking on a line (inv_po_tracking()): a drop-ship line becomes the customer's `dropship` shipment, whose tracking link is written from the fixed carrier map. Returns {purchase_order_id, shipment_id, tracking_url}. */
function track_po_line(PDO $pdo, int $lineId, ?string $carrier, string $tracking, ?string $shippedAt, string $source, ?int $by): array
{
    $l = one_row($pdo, 'SELECT pl.purchase_order_id, pl.sales_order_line_id, sol.sales_order_id FROM purchase_order_lines pl LEFT JOIN sales_order_lines sol ON sol.id = pl.sales_order_line_id WHERE pl.id = :l', ['l' => $lineId]);
    if ($l === null) { throw new DomainException('Not found.'); }
    $before = (int) one_value($pdo, 'SELECT COALESCE(max(id), 0) FROM shipments');
    $pdo->prepare('SELECT * FROM inv_po_tracking(:l, :carrier, :tracking, COALESCE(CAST(:at AS timestamptz), now()), :src, :by)')
        ->execute(['l' => $lineId, 'carrier' => $carrier, 'tracking' => $tracking, 'at' => $shippedAt, 'src' => $source, 'by' => $by]);
    $sid = null;
    $url = null;
    if ($l['sales_order_id'] !== null) {
        $v = one_value($pdo, "SELECT id FROM shipments WHERE sales_order_id = :o AND kind = 'dropship' AND id > :b ORDER BY id DESC LIMIT 1", ['o' => $l['sales_order_id'], 'b' => $before]);
        if ($v !== null) {
            $sid = (int) $v;
            $url = tracking_url($carrier, $tracking);
            if ($url !== null) { $pdo->prepare('UPDATE shipments SET tracking_url = :u WHERE id = :id')->execute(['u' => $url, 'id' => $sid]); }
        }
    }
    return ['purchase_order_id' => (int) $l['purchase_order_id'], 'shipment_id' => $sid, 'tracking_url' => $url];
}

/** Receive against (inv_receive_against()): a DRAFT goods receipt with a line per open line at the PO's cost. Returns {goods_receipt_id, number, lines, units}. */
function receive_against(PDO $pdo, int $poId, ?int $locationId, int $by): array
{
    $st = $pdo->prepare('SELECT id, number FROM inv_receive_against(:po, :by, :loc)');
    $st->execute(['po' => $poId, 'by' => $by, 'loc' => $locationId]);
    $r = $st->fetch();
    $rid = (int) $r['id'];
    $t = one_row($pdo, 'SELECT count(*) AS lines, COALESCE(sum(qty), 0) AS units FROM goods_receipt_lines WHERE goods_receipt_id = :r', ['r' => $rid]);
    return ['goods_receipt_id' => $rid, 'number' => $r['number'], 'lines' => (int) $t['lines'], 'units' => (int) $t['units']];
}

/** Close (inv_po_close()): the open lines close short; the supplier's link gets its 90 days. Returns {status, lines_short}. */
function close_purchase_order(PDO $pdo, int $poId, int $by): array
{
    $short = (int) one_value($pdo, "SELECT count(*) FROM purchase_order_lines WHERE purchase_order_id = :po AND status NOT IN ('received', 'declined', 'cancelled')", ['po' => $poId]);
    $st = $pdo->prepare('SELECT status, closed_at FROM inv_po_close(:po, :by)');
    $st->execute(['po' => $poId, 'by' => $by]);
    $r = $st->fetch() ?: [];
    return ['status' => $r['status'] ?? null, 'closed_at' => $r['closed_at'] ?? null, 'lines_short' => $short];
}

/**
 * Cancel (inv_po_cancel()): the sales lines go back to open (db/020 frees them), the supplier's link expires at once. A cancelled drop-ship tells the sales order's salesperson (`line_at_risk`). Returns
 * {released, salesperson_told}.
 */
function cancel_purchase_order(PDO $pdo, int $poId, int $by, string $reason): array
{
    $po = one_row($pdo, 'SELECT po.number, po.kind, po.sales_order_id, s.name AS supplier_name FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id WHERE po.id = :po', ['po' => $poId]);
    if ($po === null) { throw new DomainException('Not found.'); }
    $released = (int) one_value($pdo, "SELECT count(*) FROM sales_order_lines WHERE purchase_order_line_id IN (SELECT id FROM purchase_order_lines WHERE purchase_order_id = :po) AND status IN ('open', 'ordered')", ['po' => $poId]);
    $pdo->prepare('SELECT * FROM inv_po_cancel(:po, :by, :reason)')->execute(['po' => $poId, 'by' => $by, 'reason' => $reason]);
    $told = false;
    if ($po['kind'] === 'dropship' && $po['sales_order_id'] !== null) {
        $o = one_row($pdo, 'SELECT id, salesperson_member_id, status FROM sales_orders WHERE id = :o', ['o' => $po['sales_order_id']]);
        if ($o !== null && $o['salesperson_member_id'] !== null && $o['status'] !== 'cancelled') {
            $told = one_value($pdo, 'SELECT inv_notify(:m, :k, :rt, :rid, :t, NULL)', ['m' => (int) $o['salesperson_member_id'], 'k' => 'line_at_risk', 'rt' => 'sales_order', 'rid' => (int) $o['id'],
                't' => mb_substr($po['number'] . ' with ' . $po['supplier_name'] . ' was cancelled: ' . $reason, 0, 300)]) !== null;
        }
    }
    return ['released' => $released, 'salesperson_told' => $told];
}

/**
 * Rotate the supplier's link. A DRAFT: the fresh row is retired as it is made (no live link rests unseen; the next send mints again). A SENT, acknowledged or partly received order: the new link is minted and
 * MAILED AT ONCE in the same transaction (inv_po_send() sends a draft only, so this is the only way the new link reaches the supplier); a refused mail rolls the rotation back. Refused before anything changes
 * when the supplier has no address. Returns {link_id, mailed}.
 */
function rotate_supplier_link(PDO $pdo, int $poId, int $by): array
{
    $po = one_row($pdo, 'SELECT number, status FROM purchase_orders WHERE id = :po', ['po' => $poId]);
    if ($po === null) { throw new DomainException('Not found.'); }
    if (in_array($po['status'], ['received', 'closed', 'closed_short', 'cancelled'], true)) {
        throw new DomainException($po['number'] . ' is ' . str_replace('_', ' ', $po['status']) . ' — its link is not rotated.');
    }
    $pdo->beginTransaction();
    if ($po['status'] === 'draft') {
        $pdo->prepare('SELECT inv_supplier_link_mint(:po)')->execute(['po' => $poId]);
        $id = (int) one_value($pdo, 'SELECT max(id) FROM supplier_links_secure WHERE purchase_order_id = :po', ['po' => $poId]);
        $pdo->prepare('UPDATE supplier_links_secure SET rotated_at = now() WHERE id = :id')->execute(['id' => $id]);
        $pdo->commit();
        return ['link_id' => $id, 'mailed' => false];
    }
    $d = po_for_mail($pdo, $poId);
    if ($d['to'] === '') { throw new DomainException('The supplier has no email address — the old link is gone; there is nowhere to send the new one.'); }
    $raw = (string) one_value($pdo, 'SELECT inv_supplier_link_mint(:po)', ['po' => $poId]);
    $id = (int) one_value($pdo, 'SELECT max(id) FROM supplier_links_secure WHERE purchase_order_id = :po AND rotated_at IS NULL', ['po' => $poId]);
    order_mail_send(order_mail_payload(purchase_order_link_mail($d['po'], $d['supplier'], $d['settings'], $raw), $d['to'], $d['settings']));
    $pdo->commit();
    return ['link_id' => $id, 'mailed' => true];
}

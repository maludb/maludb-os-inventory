<?php
declare(strict_types=1);

/**
 * The ledger's writes (stock.md "Writes"). Drafts and lines are plain INSERT / UPDATE / DELETE on the documents' tables — their triggers refuse a
 * line once the header is not a draft (a count's once not open); posting is ALWAYS the database's verb (inv_post_receipt, inv_post_adjustment,
 * inv_transfer_send / _receive, inv_count_start, inv_post_count, inv_reverse_transaction). PHP never writes a balance or a transaction.
 * The caller holds the transaction (the handler's inv_guard()); every function here assumes it.
 */

/** The next line number of a document (the header row locked first, so two adds never take the same number). */
function next_line_no(PDO $pdo, string $headerTable, string $lineTable, string $fk, int $headerId): int
{
    $pdo->prepare("SELECT 1 FROM $headerTable WHERE id = :id FOR UPDATE")->execute(['id' => $headerId]);
    $st = $pdo->prepare("SELECT COALESCE(max(line_no), 0) + 1 FROM $lineTable WHERE $fk = :id");
    $st->execute(['id' => $headerId]);
    return (int) $st->fetchColumn();
}

/** Set a draft to cancelled; anything else is refused in words ("GR-00012 is posted — reverse its movements instead"). */
function cancel_document(PDO $pdo, string $table, int $id, string $draftStatus): void
{
    $st = $pdo->prepare("SELECT number, status FROM $table WHERE id = :id FOR UPDATE");
    $st->execute(['id' => $id]);
    $d = $st->fetch() ?: throw new DomainException('Not found.');
    if ($d['status'] !== $draftStatus) {
        throw new DomainException(match ($d['status']) {
            'posted', 'received' => $d['number'] . ' is ' . $d['status'] . ' — reverse its movements instead',
            'in_transit' => $d['number'] . ' is in transit — receive it, then reverse what should not have moved',
            'cancelled' => $d['number'] . ' is already cancelled',
            default => $d['number'] . ' is ' . $d['status'] . ' — only ' . ($draftStatus === 'open' ? 'an open count' : 'a draft') . ' is cancelled',
        });
    }
    $pdo->prepare("UPDATE $table SET status = 'cancelled' WHERE id = :id")->execute(['id' => $id]);
}

// ---- receipts ----------------------------------------------------------------------------------------------------------
/** A receipt's header (INSERT with created_by, or UPDATE) and the lines given (each through save_receipt_line). Returns the id. */
function save_receipt(PDO $pdo, ?int $id, array $f, array $lines, int $by): int
{
    $args = ['sup' => $f['supplier_id'], 'po' => $f['purchase_order_id'], 'loc' => $f['location_id'], 'dn' => $f['delivery_note_ref'], 'on' => $f['received_on'], 'notes' => $f['notes']];
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO goods_receipts (supplier_id, purchase_order_id, location_id, delivery_note_ref, received_on, notes, created_by)
                             VALUES (:sup, :po, :loc, :dn, CAST(:on AS date), :notes, :by) RETURNING id');
        $st->execute($args + ['by' => $by]);
        $id = (int) $st->fetchColumn();
    } else {
        $st = $pdo->prepare("UPDATE goods_receipts SET supplier_id = :sup, purchase_order_id = :po, location_id = :loc, delivery_note_ref = :dn, received_on = CAST(:on AS date), notes = :notes
                              WHERE id = :id AND status = 'draft'");
        $st->execute($args + ['id' => $id]);
        if ($st->rowCount() === 0) { throw new DomainException('Only a draft receipt changes — ' . receipt_number($pdo, $id) . ' is not a draft'); }
    }
    foreach ($lines as $l) {
        save_receipt_line($pdo, $id, null, $l);
    }
    return $id;
}

function receipt_number(PDO $pdo, int $id): string
{
    return (string) (one_value($pdo, 'SELECT number FROM goods_receipts WHERE id = :id', ['id' => $id]) ?? ('receipt #' . $id));
}

/**
 * A receipt line. $f: variant_id, qty, unit_cost (string|null; absent = keep / the PO line's cost), purchase_order_line_id, putaway_location_id,
 * discrepancy_kind, discrepancy_note, scanned (bool). THE SCAN RULE: a scanned variant already on the draft (and no `line` named) increments that
 * line by qty; $incremented says so. Returns the line id.
 */
function save_receipt_line(PDO $pdo, int $receiptId, ?int $lineId, array $f, ?bool &$incremented = null): int
{
    $incremented = false;
    if ($lineId === null && !empty($f['scanned'])) {
        $st = $pdo->prepare('SELECT id FROM goods_receipt_lines WHERE goods_receipt_id = :r AND variant_id = :v ORDER BY line_no LIMIT 1');
        $st->execute(['r' => $receiptId, 'v' => $f['variant_id']]);
        $existing = $st->fetchColumn();
        if ($existing !== false) {
            $pdo->prepare('UPDATE goods_receipt_lines SET qty = qty + :q WHERE id = :id')->execute(['q' => $f['qty'], 'id' => (int) $existing]);
            $incremented = true;
            return (int) $existing;
        }
    }
    if (!array_key_exists('unit_cost', $f) && !empty($f['purchase_order_line_id'])) {
        $f['unit_cost'] = one_value($pdo, 'SELECT unit_cost FROM purchase_order_lines WHERE id = :id', ['id' => $f['purchase_order_line_id']]);
    }
    $args = ['v' => $f['variant_id'], 'q' => $f['qty'], 'c' => $f['unit_cost'] ?? null, 'pol' => $f['purchase_order_line_id'] ?? null, 'put' => $f['putaway_location_id'] ?? null,
             'dk' => $f['discrepancy_kind'] ?? 'none', 'dn' => $f['discrepancy_note'] ?? null];
    if ($lineId === null) {
        $no = next_line_no($pdo, 'goods_receipts', 'goods_receipt_lines', 'goods_receipt_id', $receiptId);
        $st = $pdo->prepare('INSERT INTO goods_receipt_lines (goods_receipt_id, line_no, variant_id, qty, unit_cost, purchase_order_line_id, putaway_location_id, discrepancy_kind, discrepancy_note)
                             VALUES (:r, :no, :v, :q, CAST(:c AS numeric), :pol, :put, :dk, :dn) RETURNING id');
        $st->execute($args + ['r' => $receiptId, 'no' => $no]);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE goods_receipt_lines SET variant_id = :v, qty = :q, unit_cost = CAST(:c AS numeric), purchase_order_line_id = :pol, putaway_location_id = :put, discrepancy_kind = :dk, discrepancy_note = :dn
                    WHERE id = :id AND goods_receipt_id = :r')->execute($args + ['id' => $lineId, 'r' => $receiptId]);
    return $lineId;
}

/** Delete a draft's line (the trigger refuses after posting); returns the row as it was. */
function remove_receipt_line(PDO $pdo, int $lineId): array
{
    $st = $pdo->prepare('DELETE FROM goods_receipt_lines WHERE id = :id RETURNING id, goods_receipt_id, line_no, variant_id, qty');
    $st->execute(['id' => $lineId]);
    return $st->fetch() ?: throw new DomainException('Not found.');
}

/** Post: inv_post_receipt() — the row + what it posted (lines, units, amount at cost, discrepancies). */
function post_receipt(PDO $pdo, int $id, int $by): array
{
    $st = $pdo->prepare('SELECT * FROM inv_post_receipt(:id, :by)');
    $st->execute(['id' => $id, 'by' => $by]);
    $row = $st->fetch();
    $st = $pdo->prepare("SELECT count(*) AS lines, COALESCE(sum(qty), 0) AS units, COALESCE(sum(qty * unit_cost), 0) AS amount,
                                count(*) FILTER (WHERE discrepancy_kind <> 'none') AS discrepancies FROM goods_receipt_lines WHERE goods_receipt_id = :id");
    $st->execute(['id' => $id]);
    $t = $st->fetch();
    return ['receipt' => $row, 'lines' => (int) $t['lines'], 'units' => (int) $t['units'], 'amount' => number_format((float) $t['amount'], 2, '.', ''), 'discrepancies' => (int) $t['discrepancies']];
}

// ---- adjustments -------------------------------------------------------------------------------------------------------
function save_adjustment(PDO $pdo, ?int $id, array $f, array $lines, int $by): int
{
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO inventory_adjustments (location_id, reason_code_id, notes, created_by) VALUES (:loc, :rc, :notes, :by) RETURNING id');
        $st->execute(['loc' => $f['location_id'], 'rc' => $f['reason_code_id'], 'notes' => $f['notes'], 'by' => $by]);
        $id = (int) $st->fetchColumn();
    } else {
        $st = $pdo->prepare('SELECT number, status, location_id FROM inventory_adjustments WHERE id = :id FOR UPDATE');
        $st->execute(['id' => $id]);
        $cur = $st->fetch() ?: throw new DomainException('Not found.');
        if ($cur['status'] !== 'draft') { throw new DomainException('Only a draft adjustment changes — ' . $cur['number'] . ' is ' . $cur['status']); }
        if ((int) $cur['location_id'] !== (int) $f['location_id'] && (int) one_value($pdo, 'SELECT count(*) FROM inventory_adjustment_lines WHERE adjustment_id = :id', ['id' => $id]) > 0) {
            throw new DomainException('The location of ' . $cur['number'] . ' changes only while it has no lines — remove them first');
        }
        $pdo->prepare('UPDATE inventory_adjustments SET location_id = :loc, reason_code_id = :rc, notes = :notes WHERE id = :id')
            ->execute(['loc' => $f['location_id'], 'rc' => $f['reason_code_id'], 'notes' => $f['notes'], 'id' => $id]);
    }
    foreach ($lines as $l) {
        save_adjustment_line($pdo, $id, null, $l);
    }
    return $id;
}

/** An adjustment line. $f: variant_id, qty_delta, unit_cost (absent = keep), note. */
function save_adjustment_line(PDO $pdo, int $adjustmentId, ?int $lineId, array $f): int
{
    if ($lineId === null) {
        $no = next_line_no($pdo, 'inventory_adjustments', 'inventory_adjustment_lines', 'adjustment_id', $adjustmentId);
        $st = $pdo->prepare('INSERT INTO inventory_adjustment_lines (adjustment_id, line_no, variant_id, qty_delta, unit_cost, note) VALUES (:a, :no, :v, :q, CAST(:c AS numeric), :n) RETURNING id');
        $st->execute(['a' => $adjustmentId, 'no' => $no, 'v' => $f['variant_id'], 'q' => $f['qty_delta'], 'c' => $f['unit_cost'] ?? null, 'n' => $f['note'] ?? null]);
        return (int) $st->fetchColumn();
    }
    $set = 'variant_id = :v, qty_delta = :q, note = :n' . (array_key_exists('unit_cost', $f) ? ', unit_cost = CAST(:c AS numeric)' : '');
    $args = ['v' => $f['variant_id'], 'q' => $f['qty_delta'], 'n' => $f['note'] ?? null, 'id' => $lineId, 'a' => $adjustmentId] + (array_key_exists('unit_cost', $f) ? ['c' => $f['unit_cost']] : []);
    $pdo->prepare("UPDATE inventory_adjustment_lines SET $set WHERE id = :id AND adjustment_id = :a")->execute($args);
    return $lineId;
}

function remove_adjustment_line(PDO $pdo, int $lineId): array
{
    $st = $pdo->prepare('DELETE FROM inventory_adjustment_lines WHERE id = :id RETURNING id, adjustment_id, line_no, variant_id, qty_delta');
    $st->execute(['id' => $lineId]);
    return $st->fetch() ?: throw new DomainException('Not found.');
}

/** Post: inv_post_adjustment() — the row + lines, units_delta, amount (Σ |delta| × the cost it posted at). */
function post_adjustment(PDO $pdo, int $id, int $by): array
{
    $st = $pdo->prepare('SELECT * FROM inv_post_adjustment(:id, :by)');
    $st->execute(['id' => $id, 'by' => $by]);
    $row = $st->fetch();
    $st = $pdo->prepare("SELECT count(*) AS lines, COALESCE(sum(qty), 0) AS units, COALESCE(sum(qty * unit_cost), 0) AS amount FROM inventory_transactions WHERE reference_kind = 'adjustment' AND reference_id = :id");
    $st->execute(['id' => $id]);
    $t = $st->fetch();
    return ['adjustment' => $row, 'lines' => (int) $t['lines'], 'units_delta' => (int) $t['units'], 'amount' => number_format((float) $t['amount'], 2, '.', '')];
}

// ---- transfers ---------------------------------------------------------------------------------------------------------
function save_transfer(PDO $pdo, ?int $id, array $f, array $lines, int $by): int
{
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO inventory_transfers (from_location_id, to_location_id, notes, created_by) VALUES (:f, :t, :notes, :by) RETURNING id');
        $st->execute(['f' => $f['from_location_id'], 't' => $f['to_location_id'], 'notes' => $f['notes'], 'by' => $by]);
        $id = (int) $st->fetchColumn();
    } else {
        $st = $pdo->prepare("UPDATE inventory_transfers SET from_location_id = :f, to_location_id = :t, notes = :notes WHERE id = :id AND status = 'draft'");
        $st->execute(['f' => $f['from_location_id'], 't' => $f['to_location_id'], 'notes' => $f['notes'], 'id' => $id]);
        if ($st->rowCount() === 0) { throw new DomainException('Only a draft transfer changes — ' . (one_value($pdo, 'SELECT number FROM inventory_transfers WHERE id = :id', ['id' => $id]) ?? 'it') . ' is not a draft'); }
    }
    foreach ($lines as $l) {
        save_transfer_line($pdo, $id, null, $l);
    }
    return $id;
}

/** A transfer line ($f: variant_id, qty, scanned). The scan rule as on a receipt: a scanned variant already on the draft increments its line. */
function save_transfer_line(PDO $pdo, int $transferId, ?int $lineId, array $f, ?bool &$incremented = null): int
{
    $incremented = false;
    if ($lineId === null && !empty($f['scanned'])) {
        $st = $pdo->prepare('SELECT id FROM inventory_transfer_lines WHERE transfer_id = :t AND variant_id = :v ORDER BY line_no LIMIT 1');
        $st->execute(['t' => $transferId, 'v' => $f['variant_id']]);
        $existing = $st->fetchColumn();
        if ($existing !== false) {
            $pdo->prepare('UPDATE inventory_transfer_lines SET qty = qty + :q WHERE id = :id')->execute(['q' => $f['qty'], 'id' => (int) $existing]);
            $incremented = true;
            return (int) $existing;
        }
    }
    if ($lineId === null) {
        $no = next_line_no($pdo, 'inventory_transfers', 'inventory_transfer_lines', 'transfer_id', $transferId);
        $st = $pdo->prepare('INSERT INTO inventory_transfer_lines (transfer_id, line_no, variant_id, qty) VALUES (:t, :no, :v, :q) RETURNING id');
        $st->execute(['t' => $transferId, 'no' => $no, 'v' => $f['variant_id'], 'q' => $f['qty']]);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE inventory_transfer_lines SET variant_id = :v, qty = :q WHERE id = :id AND transfer_id = :t')->execute(['v' => $f['variant_id'], 'q' => $f['qty'], 'id' => $lineId, 't' => $transferId]);
    return $lineId;
}

function remove_transfer_line(PDO $pdo, int $lineId): array
{
    $st = $pdo->prepare('DELETE FROM inventory_transfer_lines WHERE id = :id RETURNING id, transfer_id, line_no, variant_id, qty');
    $st->execute(['id' => $lineId]);
    return $st->fetch() ?: throw new DomainException('Not found.');
}

function transfer_totals(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT count(*) AS lines, COALESCE(sum(qty), 0) AS units, COALESCE(sum(qty_received), 0) AS units_received FROM inventory_transfer_lines WHERE transfer_id = :id');
    $st->execute(['id' => $id]);
    return array_map('intval', $st->fetch());
}

function send_transfer(PDO $pdo, int $id, int $by): array
{
    $st = $pdo->prepare('SELECT * FROM inv_transfer_send(:id, :by)');
    $st->execute(['id' => $id, 'by' => $by]);
    return ['transfer' => $st->fetch()] + transfer_totals($pdo, $id);
}

/** Receive: $quantities {line_id: qty} (empty = every line in full). The row + short[] ({sku, qty, qty_received}). */
function receive_transfer(PDO $pdo, int $id, int $by, array $quantities): array
{
    $st = $pdo->prepare('SELECT * FROM inv_transfer_receive(:id, :by, CAST(:q AS jsonb))');
    $st->execute(['id' => $id, 'by' => $by, 'q' => $quantities === [] ? null : json_encode((object) $quantities, JSON_THROW_ON_ERROR)]);
    $row = $st->fetch();
    $st = $pdo->prepare('SELECT v.sku, l.qty, l.qty_received FROM inventory_transfer_lines l JOIN product_variants v ON v.id = l.variant_id WHERE l.transfer_id = :id AND l.qty_received < l.qty ORDER BY l.line_no');
    $st->execute(['id' => $id]);
    $short = array_map(static fn (array $r): array => ['sku' => $r['sku'], 'qty' => (int) $r['qty'], 'qty_received' => (int) $r['qty_received']], $st->fetchAll());
    return ['transfer' => $row, 'short' => $short] + transfer_totals($pdo, $id);
}

// ---- counts ------------------------------------------------------------------------------------------------------------
function start_count(PDO $pdo, int $locationId, int $by, ?string $notes): array
{
    $st = $pdo->prepare('SELECT * FROM inv_count_start(:l, :by, :n)');
    $st->execute(['l' => $locationId, 'by' => $by, 'n' => $notes]);
    $c = $st->fetch();
    $c['lines'] = (int) one_value($pdo, 'SELECT count(*) FROM inventory_count_lines WHERE count_id = :c', ['c' => (int) $c['id']]);
    return $c;
}

/** Set a counted quantity; a variant with no line on the count gets one with system_qty = the balance now (the scan rule). Returns the line id; $created says so. */
function set_count_line(PDO $pdo, int $countId, int $variantId, int $countedQty, int $by, ?bool &$created = null): int
{
    $created = false;
    $st = $pdo->prepare('UPDATE inventory_count_lines SET counted_qty = :q, counted_by = :by, counted_at = now() WHERE count_id = :c AND variant_id = :v RETURNING id');
    $st->execute(['q' => $countedQty, 'by' => $by, 'c' => $countId, 'v' => $variantId]);
    $id = $st->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }
    $loc = (int) one_value($pdo, 'SELECT location_id FROM inventory_counts WHERE id = :c', ['c' => $countId]);
    $st = $pdo->prepare('INSERT INTO inventory_count_lines (count_id, variant_id, system_qty, counted_qty, counted_by, counted_at)
                         VALUES (:c, :v, COALESCE((SELECT qty_on_hand FROM inventory_balances WHERE variant_id = :v2 AND location_id = :l), 0), :q, :by, now()) RETURNING id');
    $st->execute(['c' => $countId, 'v' => $variantId, 'v2' => $variantId, 'l' => $loc, 'q' => $countedQty, 'by' => $by]);
    $created = true;
    return (int) $st->fetchColumn();
}

/** Post: inv_post_count() — the row + the corrections it wrote (count, units_delta) and the lines that differed. */
function post_count(PDO $pdo, int $id, int $by): array
{
    $st = $pdo->prepare('SELECT * FROM inv_post_count(:id, :by)');
    $st->execute(['id' => $id, 'by' => $by]);
    $row = $st->fetch();
    $st = $pdo->prepare("SELECT count(*) AS corrections, COALESCE(sum(qty), 0) AS units_delta FROM inventory_transactions WHERE reference_kind = 'count' AND reference_id = :id");
    $st->execute(['id' => $id]);
    $t = $st->fetch();
    $lines = (int) one_value($pdo, 'SELECT count(*) FROM inventory_count_lines WHERE count_id = :id', ['id' => $id]);
    $differing = (int) one_value($pdo, 'SELECT count(*) FROM inventory_count_lines WHERE count_id = :id AND counted_qty IS NOT NULL AND counted_qty <> system_qty', ['id' => $id]);
    return ['count' => $row, 'corrections' => (int) $t['corrections'], 'units_delta' => (int) $t['units_delta'], 'lines' => $lines, 'lines_differing' => $differing];
}

// ---- floor models and reversals ----------------------------------------------------------------------------------------
/** A floor move IS an adjustment with the floor_model reason, drafted and posted here (the decision). Returns {adjustment_id, number, transaction_id}. */
function set_floor_model(PDO $pdo, int $variantId, int $locationId, string $direction, int $qty, ?string $note, int $by): array
{
    $rc = (int) (one_value($pdo, "SELECT id FROM reason_codes WHERE code = 'floor_model'") ?? throw new DomainException('The floor model reason code is missing — restore it under Admin → Reason codes'));
    $st = $pdo->prepare('INSERT INTO inventory_adjustments (location_id, reason_code_id, notes, created_by) VALUES (:l, :rc, :n, :by) RETURNING id, number');
    $st->execute(['l' => $locationId, 'rc' => $rc, 'n' => $note, 'by' => $by]);
    $a = $st->fetch();
    $pdo->prepare('INSERT INTO inventory_adjustment_lines (adjustment_id, line_no, variant_id, qty_delta, note) VALUES (:a, 1, :v, :q, :n)')
        ->execute(['a' => (int) $a['id'], 'v' => $variantId, 'q' => $direction === 'in' ? $qty : -$qty, 'n' => $note]);
    $pdo->prepare('SELECT inv_post_adjustment(:a, :by)')->execute(['a' => (int) $a['id'], 'by' => $by]);
    $txn = (int) one_value($pdo, "SELECT id FROM inventory_transactions WHERE reference_kind = 'adjustment' AND reference_id = :a", ['a' => (int) $a['id']]);
    return ['adjustment_id' => (int) $a['id'], 'number' => $a['number'], 'transaction_id' => $txn];
}

/** inv_reverse_transaction(): the opposite movement, linked, once. Returns the new row. */
function reverse_transaction(PDO $pdo, int $txnId, int $by, ?string $note): array
{
    $id = (int) one_value($pdo, 'SELECT inv_reverse_transaction(:t, :by, :n)', ['t' => $txnId, 'by' => $by, 'n' => $note]);
    $st = $pdo->prepare('SELECT id, txn_type, variant_id, location_id, qty, reverses_id, reference_kind, reference_id FROM inventory_transactions WHERE id = :id');
    $st->execute(['id' => $id]);
    return $st->fetch();
}

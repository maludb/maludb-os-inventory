<?php
declare(strict_types=1);

/**
 * Returns' writes (returns-worker.md "Query functions"): every verb is the schema's (db/012) — a return is against a confirmed order, a line returns at most what shipped and is not
 * yet returned, a reason is a return reason, lines change while requested or approved, receiving writes the movements the dispositions say. PHP calls them inside the handler's
 * transaction and shows the database's sentence. Nothing here charges or pays a customer: the refund is RECORDED on the return (DECISION 4).
 */

/** A return's header and its lines. $header: method, scheduled_on, location_id, notes. $lines: [{sales_order_line_id, qty, reason_code_id, disposition, location_id, condition_note}]. Returns the id. */
function request_return(PDO $pdo, int $orderId, array $header, array $lines, int $by): int
{
    $st = $pdo->prepare('INSERT INTO return_authorizations (sales_order_id, customer_id, method, scheduled_on, location_id, notes, requested_by)
                         VALUES (:o, 0, :m, CAST(:s AS date), :l, :n, :by) RETURNING id');
    $st->execute(['o' => $orderId, 'm' => $header['method'] ?? 'pickup', 's' => $header['scheduled_on'] ?? null, 'l' => $header['location_id'] ?? null, 'n' => $header['notes'] ?? null, 'by' => $by]);
    $id = (int) $st->fetchColumn();
    foreach ($lines as $l) { return_line_try($pdo, (int) $l['sales_order_line_id'], static fn () => add_return_line($pdo, $id, $l)); }
    return $id;
}

/**
 * Run one line's write; the schema's refusal of it (more than may come back, a reason that is not a return reason) is a field error on that line — `lines.<order line id>.qty` — in the trigger's
 * own words, after the transaction is rolled back. Anything else is thrown as it is.
 */
function return_line_try(PDO $pdo, int $orderLineId, callable $step): mixed
{
    try {
        return $step();
    } catch (PDOException $e) {
        if (!in_array((string) $e->getCode(), ['P0001', '23514'], true)) { throw $e; }
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $m = db_message($e, 'That could not be done.');
        inv_refuse_fields(['lines.' . $orderLineId . '.' . (str_starts_with($m, 'Line ') ? 'qty' : (str_contains($m, 'reason') ? 'reason' : 'line')) => $m]);
    }
}

/** The header's fields that were sent (method, scheduled_on, location_id, notes) and, when $lines is not null, the whole set of lines made to match (added, changed, removed). ['changed' => keys, 'before' => , 'after' => ]. */
function update_return(PDO $pdo, int $id, array $header, ?array $lines, int $by): array
{
    $cur = $pdo->prepare('SELECT method, scheduled_on::text AS scheduled_on, location_id, notes FROM return_authorizations WHERE id = :id');
    $cur->execute(['id' => $id]);
    $before = $cur->fetch();
    if ($before === false) { throw new DomainException('Not found.'); }
    $set = [];
    $args = ['id' => $id];
    foreach (['method', 'scheduled_on', 'location_id', 'notes'] as $k) {
        if (array_key_exists($k, $header) && $header[$k] !== $before[$k]) { $set[] = $k . ($k === 'scheduled_on' ? ' = CAST(:scheduled_on AS date)' : " = :$k"); $args[$k] = $header[$k]; }
    }
    if ($set !== []) { $pdo->prepare('UPDATE return_authorizations SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($args); }
    $changed = array_keys(array_intersect_key($header, array_flip(array_map(static fn (string $s): string => explode(' ', $s)[0], $set))));
    $diff = inv_diff($before, array_intersect_key($header, array_flip($changed)) + $before);
    $linesChanged = 0;
    if ($lines !== null) {
        $have = $pdo->prepare('SELECT id, sales_order_line_id FROM return_lines WHERE return_id = :r');
        $have->execute(['r' => $id]);
        $byOrderLine = [];
        foreach ($have->fetchAll() as $h) { $byOrderLine[(int) $h['sales_order_line_id']] = (int) $h['id']; }
        $keep = [];
        foreach ($lines as $l) {
            $ol = (int) $l['sales_order_line_id'];
            if (isset($byOrderLine[$ol])) { return_line_try($pdo, $ol, fn () => update_return_line($pdo, $byOrderLine[$ol], $l)); $keep[] = $byOrderLine[$ol]; }
            else { $keep[] = return_line_try($pdo, $ol, fn () => add_return_line($pdo, $id, $l)); }
            $linesChanged++;
        }
        foreach ($byOrderLine as $ol => $rlId) { if (!in_array($rlId, $keep, true)) { remove_return_line($pdo, $rlId); $linesChanged++; } }
    }
    return ['changed' => array_values(array_unique(array_merge(array_keys($diff['after']), $linesChanged > 0 ? ['lines'] : []))), 'before' => $diff['before'], 'after' => $diff['after']];
}

/** One line (the trigger fills the variant and checks the quantity and the reason). Returns the return_lines id. */
function add_return_line(PDO $pdo, int $returnId, array $l): int
{
    $st = $pdo->prepare('INSERT INTO return_lines (return_id, sales_order_line_id, variant_id, qty, reason_code_id, disposition, location_id, condition_note) VALUES (:r, :sol, 0, :q, :rc, :d, :l, :n) RETURNING id');
    $st->execute(['r' => $returnId, 'sol' => $l['sales_order_line_id'], 'q' => $l['qty'], 'rc' => $l['reason_code_id'], 'd' => $l['disposition'] ?? 'restock', 'l' => $l['location_id'] ?? null, 'n' => $l['condition_note'] ?? null]);
    return (int) $st->fetchColumn();
}

/** Change the fields given (qty, reason_code_id, disposition, location_id, condition_note) of a line. */
function update_return_line(PDO $pdo, int $lineId, array $f): void
{
    $set = [];
    $args = ['id' => $lineId];
    foreach (['qty', 'reason_code_id', 'disposition', 'location_id', 'condition_note'] as $k) {
        if (array_key_exists($k, $f)) { $set[] = "$k = :$k"; $args[$k] = $f[$k]; }
    }
    if ($set !== []) { $pdo->prepare('UPDATE return_lines SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($args); }
}

function remove_return_line(PDO $pdo, int $lineId): void
{
    $pdo->prepare('DELETE FROM return_lines WHERE id = :id')->execute(['id' => $lineId]);
}

/** inv_return_approve(): a requested return is approved. */
function approve_return(PDO $pdo, int $id, int $by): array
{
    return one_row($pdo, 'SELECT * FROM inv_return_approve(:id, :by)', ['id' => $id, 'by' => $by]) ?? [];
}

/** inv_return_deny(): a requested or approved return is denied, with its reason. */
function deny_return(PDO $pdo, int $id, int $by, string $reason): array
{
    return one_row($pdo, 'SELECT * FROM inv_return_deny(:id, :by, :r)', ['id' => $id, 'by' => $by, 'r' => $reason]) ?? [];
}

/**
 * Receive an approved return. $quantities: return_line_id => qty received (absent = the quantity asked for; less lowers the line; 0 removes it — DECISION 3); $conditionNotes: return_line_id => note;
 * $locations: return_line_id => a location the line comes back to. The verb writes the movements. Answers ['return', 'restocked', 'floor', 'disposed', 'donated', 'to_supplier', 'short'] — the lists in the words the log keeps.
 */
function receive_return(PDO $pdo, int $id, array $quantities, array $conditionNotes, int $by, array $locations = []): array
{
    $ra = one_row($pdo, 'SELECT status, location_id, number FROM return_authorizations WHERE id = :id', ['id' => $id]);
    if ($ra === null) { throw new DomainException('Not found.'); }
    $short = [];
    if ($ra['status'] === 'approved') {                                                                      // any other status: the verb's own sentence follows
        $lines = $pdo->prepare('SELECT rl.id, rl.qty, rl.disposition, rl.location_id, rl.condition_note, v.sku FROM return_lines rl JOIN product_variants v ON v.id = rl.variant_id WHERE rl.return_id = :r ORDER BY rl.id');
        $lines->execute(['r' => $id]);
        $kept = 0;
        foreach ($lines->fetchAll() as $l) {
            $lid = (int) $l['id'];
            $got = array_key_exists($lid, $quantities) ? (int) $quantities[$lid] : (int) $l['qty'];
            if ($got < 0 || $got > (int) $l['qty']) { throw new DomainException('Line ' . $l['sku'] . ': at most ' . $l['qty'] . ' came back.'); }
            if ($got === 0) {
                $short[] = ['return_line_id' => $lid, 'sku' => $l['sku'], 'requested' => (int) $l['qty'], 'received' => 0];
                remove_return_line($pdo, $lid);
                continue;
            }
            $kept++;
            $f = [];
            if ($got < (int) $l['qty']) { $f['qty'] = $got; $short[] = ['return_line_id' => $lid, 'sku' => $l['sku'], 'requested' => (int) $l['qty'], 'received' => $got]; }
            if (array_key_exists($lid, $conditionNotes)) { $f['condition_note'] = $conditionNotes[$lid] === '' ? null : $conditionNotes[$lid]; }
            $loc = array_key_exists($lid, $locations) && $locations[$lid] !== null ? (int) $locations[$lid] : ($l['location_id'] === null ? null : (int) $l['location_id']);
            if (array_key_exists($lid, $locations) && $locations[$lid] !== null) { $f['location_id'] = $loc; }
            if (in_array($l['disposition'], ['restock', 'floor_model'], true) && ($loc ?? ($ra['location_id'] === null ? null : (int) $ra['location_id'])) === null) {
                throw new DomainException('Say where ' . $l['sku'] . ' comes back to.');
            }
            if ($f !== []) { update_return_line($pdo, $lid, $f); }
        }
        if ($kept === 0) { throw new DomainException('Nothing came back — deny the return instead.'); }
    }
    $r = one_row($pdo, 'SELECT * FROM inv_return_receive(:id, :by)', ['id' => $id, 'by' => $by]) ?? [];
    $out = ['return' => $r, 'restocked' => [], 'floor' => [], 'disposed' => [], 'donated' => [], 'to_supplier' => [], 'short' => $short];
    $st = $pdo->prepare('SELECT rl.id, v.sku, rl.qty, rl.disposition, COALESCE(rl.location_id, ra.location_id) AS location_id,
                                (SELECT pl.purchase_order_id FROM sales_order_lines sol JOIN purchase_order_lines pl ON pl.id = sol.purchase_order_line_id WHERE sol.id = rl.sales_order_line_id) AS purchase_order_id
                           FROM return_lines rl JOIN return_authorizations ra ON ra.id = rl.return_id JOIN product_variants v ON v.id = rl.variant_id WHERE rl.return_id = :r ORDER BY rl.id');
    $st->execute(['r' => $id]);
    foreach ($st->fetchAll() as $l) {
        $row = ['sku' => $l['sku'], 'qty' => (int) $l['qty']];
        match ($l['disposition']) {
            'restock' => $out['restocked'][] = $row + ['location_id' => $l['location_id'] === null ? null : (int) $l['location_id']],
            'floor_model' => $out['floor'][] = $row + ['location_id' => $l['location_id'] === null ? null : (int) $l['location_id']],
            'dispose' => $out['disposed'][] = $row,
            'donate' => $out['donated'][] = $row,
            'return_to_supplier' => $out['to_supplier'][] = $row + ['purchase_order_id' => $l['purchase_order_id'] === null ? null : (int) $l['purchase_order_id']],
        };
    }
    return $out;
}

/** A line's disposition (and where a restock lands), while the return is requested or approved (the trigger). */
function set_return_disposition(PDO $pdo, int $lineId, string $disposition, ?int $locationId): array
{
    $before = one_row($pdo, 'SELECT disposition, location_id FROM return_lines WHERE id = :id', ['id' => $lineId]);
    if ($before === null) { throw new DomainException('Not found.'); }
    $f = ['disposition' => $disposition];
    if ($locationId !== null) { $f['location_id'] = $locationId; }
    update_return_line($pdo, $lineId, $f);
    return ['before' => $before, 'after' => ['disposition' => $disposition, 'location_id' => $locationId ?? $before['location_id']]];
}

/** inv_return_close(): the refund and restocking fee recorded; the money is slice 5's refund_record on the order. */
function close_return(PDO $pdo, int $id, int $by, ?float $refund, ?float $fee): array
{
    return one_row($pdo, 'SELECT * FROM inv_return_close(:id, :by, :refund, :fee)', ['id' => $id, 'by' => $by, 'refund' => $refund, 'fee' => $fee]) ?? [];
}

<?php
declare(strict_types=1);

/**
 * The ledger's prelude (stock.md "Handlers"): the right + POST + CSRF, the readers of the four documents' headers and lines (a field left out
 * stays as it was), the scan resolver for a request, require_draft(), and stock_log() — every row carries `location_id`, a receipt's
 * `purchase_order_id` too (the audit keys of design §6).
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__) . '/catalog/queries.php';
require_once dirname(__DIR__) . '/catalog/present.php';
require_once dirname(__DIR__) . '/catalog/handler.php';
require_once dirname(__DIR__) . '/locations/handler.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/write.php';

function stock_write_begin(string $right): void
{
    inv_handler_begin();
    require_right($right);
}

/** log_activity() with the ledger's audit keys. */
function stock_log(PDO $pdo, string $action, string $entityType, int $entityId, ?int $locationId, array $after, array $opts = []): void
{
    log_activity($pdo, $action, $entityType, $entityId, ['location_id' => $locationId, 'after' => $after] + $opts);
}

/** The acting member's id (the person, or the agent under an action token) — the posting verbs' p_by. */
function stock_me(): int
{
    return (int) (current_member_id() ?? refuse(401, 'Sign in first.'));
}

/** A document that must be in $status, or the sentence ("GR-00012 is posted — only a draft …"). */
function require_draft(array $doc, string $what, string $status = 'draft'): void
{
    if ($doc['status'] !== $status) {
        refuse(422, $doc['number'] . ' is ' . str_replace('_', ' ', $doc['status']) . ' — only ' . ($status === 'open' ? 'an open ' . $what : 'a draft ' . $what) . ' changes');
    }
}

/** An active location by id from a field (required or not); field error otherwise. */
function stock_location_field(PDO $pdo, string $name, ?int $keep, string $label, array &$errors, bool $required = true): ?int
{
    return inv_ref($pdo, $name, $keep, 'SELECT 1 FROM mcp_locations WHERE location_id = :id AND active', $label, $errors, !$required);
}

/**
 * The variant a request names: `barcode` (a scan — GTIN, UPC, EAN or SKU through variant_by_scan()) or `variant` (an id from the picker; a long
 * number or a non-number is treated as a code, so an agent may pass either). $scanned says a code was resolved. A bundle is refused with
 * "A bundle never holds stock — {verb} its components". Field errors go under the field that was sent.
 */
function stock_variant_from_request(PDO $pdo, array &$errors, ?bool &$scanned, string $verb, ?int $keep = null): ?int
{
    $scanned = false;
    $code = req_val('barcode');
    $field = 'barcode';
    if ($code === null || $code === '') {
        $field = 'variant';
        $v = req_val('variant');
        if ($v === null || $v === '') {
            if ($keep !== null) { return $keep; }
            $errors['variant'] = 'Choose a variant, or scan its barcode.';
            return null;
        }
        if (ctype_digit($v) && strlen($v) < 8) {
            $row = one_value($pdo, 'SELECT kind FROM mcp_product_variants WHERE variant_id = :id', ['id' => (int) $v]);
            if ($row === null) { $errors['variant'] = 'That variant is not here.'; return null; }
            if ($row === 'bundle') { $errors['variant'] = 'A bundle never holds stock — ' . $verb . ' its components'; return null; }
            return (int) $v;
        }
        $code = $v;
    }
    $found = variant_by_scan($pdo, $code);
    if ($found === null) { $errors[$field] = 'No variant has the code ' . $code . '.'; return null; }
    if ($found['kind'] === 'bundle') { $errors[$field] = 'A bundle never holds stock — ' . $verb . ' its components'; return null; }
    $scanned = true;
    return $found['variant_id'];
}

/** A JSON `lines` field (an agent, or the PO prefill): a list of objects, or [] when absent. Refused in words when not a list. */
function stock_lines_json(): array
{
    if (!req_has('lines') || (string) req_val('lines') === '') { return []; }
    $raw = $_POST['lines'];
    $lines = is_array($raw) ? $raw : json_decode((string) $raw, true);
    if (!is_array($lines) || !array_is_list($lines)) { refuse(422, 'lines is a JSON list of objects.'); }
    foreach ($lines as $l) { if (!is_array($l)) { refuse(422, 'lines is a JSON list of objects.'); } }
    return $lines;
}

/**
 * Run a line reader over one JSON line: the reader reads $_POST, so the line becomes $_POST for the call (the same validation as `*_line_add`).
 * Errors are prefixed "Line n: ".
 */
function stock_with_post(array $vars, callable $reader): mixed
{
    $saved = $_POST;
    $_POST = array_map(static fn ($v) => is_bool($v) ? ($v ? 'yes' : 'no') : (is_scalar($v) || $v === null ? (string) $v : $v), $vars);
    try {
        return $reader();
    } finally {
        $_POST = $saved;
    }
}

/** Read every JSON line through $reader(array &$errors): array; field errors become "Line n: …" sentences. */
function stock_read_lines(array $lines, callable $reader): array
{
    $out = [];
    $all = [];
    foreach ($lines as $i => $l) {
        $errs = [];
        $f = stock_with_post($l, static function () use ($reader, &$errs) { return $reader($errs); });
        foreach ($errs as $k => $m) { $all['lines.' . $i . '.' . $k] = 'Line ' . ($i + 1) . ': ' . $m; }
        $out[] = $f;
    }
    if ($all !== []) { inv_refuse_fields($all); }
    return $out;
}

/** A free-text field ($keep when left out; null when empty). */
function stock_text(string $name, ?string $keep, int $max): ?string
{
    if (!req_has($name)) { return $keep; }
    $v = (string) req_val($name);
    return $v === '' ? null : mb_substr($v, 0, $max);
}

// ---- receipts ----------------------------------------------------------------------------------------------------------
/** A receipt's header: location (required), supplier, purchase_order (its supplier must match; a stock PO), received_on (not in the future), delivery_note_ref, notes. */
function receipt_from_request(PDO $pdo, ?array $cur, array &$errors): array
{
    $f = [];
    $f['location_id'] = stock_location_field($pdo, 'location', isset($cur['location_id']) ? (int) $cur['location_id'] : null, 'the location', $errors);
    if ($f['location_id'] === null && !isset($errors['location'])) { $errors['location'] = 'Choose where the delivery lands.'; }
    $f['supplier_id'] = inv_ref($pdo, 'supplier', isset($cur['supplier_id']) ? (int) $cur['supplier_id'] : null, 'SELECT 1 FROM mcp_suppliers WHERE supplier_id = :id', 'the supplier', $errors);
    $f['purchase_order_id'] = inv_ref($pdo, 'purchase_order', isset($cur['purchase_order_id']) ? (int) $cur['purchase_order_id'] : null, 'SELECT 1 FROM mcp_purchase_orders WHERE purchase_order_id = :id', 'the purchase order', $errors);
    if ($f['purchase_order_id'] !== null && !isset($errors['purchase_order'])) {
        $po = find_purchase_order_brief($pdo, $f['purchase_order_id']);
        if ($po['kind'] !== 'stock') { $errors['purchase_order'] = $po['number'] . ' is a drop-ship order — nothing of it is received here.'; }
        elseif ($f['supplier_id'] === null) { $f['supplier_id'] = (int) $po['supplier_id']; }
        elseif ((int) $po['supplier_id'] !== $f['supplier_id']) { $errors['purchase_order'] = $po['number'] . ' is ' . $po['supplier_name'] . '’s, not this supplier’s.'; }
    }
    $f['received_on'] = $cur['received_on'] ?? date('Y-m-d');
    if (req_has('received_on') && (string) req_val('received_on') !== '') {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', (string) req_val('received_on'));
        if ($d === false) { $errors['received_on'] = 'The received date is a date (YYYY-MM-DD).'; }
        elseif ($d->format('Y-m-d') > date('Y-m-d')) { $errors['received_on'] = 'The received date is not in the future.'; }
        else { $f['received_on'] = $d->format('Y-m-d'); }
    }
    $f['delivery_note_ref'] = stock_text('delivery_note_ref', $cur['delivery_note_ref'] ?? null, 100);
    $f['notes'] = stock_text('notes', $cur['notes'] ?? null, 2000);
    return $f;
}

/** A receipt line from the request; $receipt is the draft (its PO's lines are the only PO lines a line may name). $cur the line when changing. */
function receipt_line_from_request(PDO $pdo, array $receipt, ?array $cur, array &$errors): array
{
    $f = [];
    $scanned = false;
    $f['variant_id'] = stock_variant_from_request($pdo, $errors, $scanned, 'receive', isset($cur['variant_id']) ? (int) $cur['variant_id'] : null);
    $f['scanned'] = $scanned;
    $f['qty'] = inv_int('qty', isset($cur['qty']) ? (int) $cur['qty'] : 1, 1, 1000000, 'The quantity', $errors);
    if (req_has('unit_cost') && (string) req_val('unit_cost') !== '') {
        if (!sees_receipt_cost()) { $errors['unit_cost'] = 'You may not set a cost on a receipt — leave unit cost empty.'; }
        else { $f['unit_cost'] = inv_money_field('unit_cost', $cur['unit_cost'] ?? null, 'The unit cost', $errors); }
    } elseif (req_has('unit_cost')) {
        if (sees_receipt_cost()) { $f['unit_cost'] = null; }
    } elseif ($cur !== null) {
        $f['unit_cost'] = $cur['unit_cost'];
    }
    $f['purchase_order_line_id'] = isset($cur['purchase_order_line_id']) ? (int) $cur['purchase_order_line_id'] : null;
    if (req_has('purchase_order_line')) {
        $v = (string) req_val('purchase_order_line');
        if ($v === '' || $v === '0') { $f['purchase_order_line_id'] = null; }
        elseif (!ctype_digit($v)) { $errors['purchase_order_line'] = 'Choose the purchase order line from the list.'; }
        else {
            $pl = $pdo->prepare('SELECT purchase_order_id, variant_id FROM mcp_purchase_order_lines WHERE purchase_order_line_id = :id');
            $pl->execute(['id' => (int) $v]);
            $pl = $pl->fetch();
            if ($pl === false || $receipt['purchase_order_id'] === null || (int) $pl['purchase_order_id'] !== (int) $receipt['purchase_order_id']) {
                $errors['purchase_order_line'] = 'That line is not on this receipt’s purchase order.';
            } elseif ($f['variant_id'] !== null && (int) $pl['variant_id'] !== $f['variant_id']) {
                $errors['purchase_order_line'] = 'That purchase order line is for another variant.';
            } else { $f['purchase_order_line_id'] = (int) $v; }
        }
    }
    $f['putaway_location_id'] = stock_location_field($pdo, 'putaway_location', isset($cur['putaway_location_id']) ? (int) $cur['putaway_location_id'] : null, 'the put-away location', $errors, false);
    $f['discrepancy_kind'] = $cur['discrepancy_kind'] ?? 'none';
    if (req_has('discrepancy_kind') && (string) req_val('discrepancy_kind') !== '') {
        $k = (string) req_val('discrepancy_kind');
        if (!isset(DISCREPANCY_KINDS[$k])) { $errors['discrepancy_kind'] = 'The discrepancy is none, short, over, damaged, wrong_item or substitute.'; } else { $f['discrepancy_kind'] = $k; }
    }
    $f['discrepancy_note'] = stock_text('discrepancy_note', $cur['discrepancy_note'] ?? null, 1000);
    return $f;
}

function receipt_or_404(PDO $pdo, ?int $id): array
{
    $r = $id === null ? null : find_receipt($pdo, $id);
    if ($r === null) { refuse(404, 'Receipt not found.'); }
    return $r;
}

// ---- adjustments -------------------------------------------------------------------------------------------------------
function adjustment_from_request(PDO $pdo, ?array $cur, array &$errors): array
{
    $f = [];
    $f['location_id'] = stock_location_field($pdo, 'location', isset($cur['location_id']) ? (int) $cur['location_id'] : null, 'the location', $errors);
    if ($f['location_id'] === null && !isset($errors['location'])) { $errors['location'] = 'Choose the location.'; }
    $f['reason_code_id'] = isset($cur['reason_code_id']) ? (int) $cur['reason_code_id'] : null;
    if (req_has('reason') && (string) req_val('reason') !== '') {
        $rc = find_reason($pdo, (string) req_val('reason'));
        if ($rc === null || !$rc['active']) { $errors['reason'] = 'That reason is not here.'; }
        elseif (!in_array('adjustment', pg_text_array($rc['applies_to']), true)) { $errors['reason'] = 'The reason ' . $rc['name'] . ' is not an adjustment reason'; }
        else { $f['reason_code_id'] = (int) $rc['reason_code_id']; }
    } elseif ($f['reason_code_id'] === null) {
        $errors['reason'] = 'Choose the reason.';
    }
    $f['notes'] = stock_text('notes', $cur['notes'] ?? null, 2000);
    return $f;
}

function adjustment_line_from_request(PDO $pdo, ?array $cur, array &$errors): array
{
    $f = [];
    $scanned = false;
    $f['variant_id'] = stock_variant_from_request($pdo, $errors, $scanned, 'adjust', isset($cur['variant_id']) ? (int) $cur['variant_id'] : null);
    $f['qty_delta'] = isset($cur['qty_delta']) ? (int) $cur['qty_delta'] : null;
    if (req_has('qty_delta')) {
        $v = (string) req_val('qty_delta');
        if ($v === '' || filter_var($v, FILTER_VALIDATE_INT) === false) { $errors['qty_delta'] = 'The quantity change is a whole number, + to add or − to take away.'; }
        elseif ((int) $v === 0) { $errors['qty_delta'] = 'The quantity change is never zero.'; }
        elseif (abs((int) $v) > 1000000) { $errors['qty_delta'] = 'The quantity change is at most 1,000,000 either way.'; }
        else { $f['qty_delta'] = (int) $v; }
    } elseif ($f['qty_delta'] === null) {
        $errors['qty_delta'] = 'Give the quantity change (+ to add, − to take away).';
    }
    if (req_has('unit_cost') && (string) req_val('unit_cost') !== '') {
        if (!sees_cost()) { $errors['unit_cost'] = 'You may not set a cost on an adjustment — leave unit cost empty.'; }
        else { $f['unit_cost'] = inv_money_field('unit_cost', $cur['unit_cost'] ?? null, 'The unit cost', $errors); }
    } elseif (req_has('unit_cost') && sees_cost()) {
        $f['unit_cost'] = null;
    }
    $f['note'] = stock_text('note', $cur['note'] ?? null, 1000);
    return $f;
}

function adjustment_or_404(PDO $pdo, ?int $id): array
{
    $a = $id === null ? null : find_adjustment($pdo, $id);
    if ($a === null) { refuse(404, 'Adjustment not found.'); }
    return $a;
}

// ---- transfers ---------------------------------------------------------------------------------------------------------
function transfer_from_request(PDO $pdo, ?array $cur, array &$errors): array
{
    $f = [];
    $f['from_location_id'] = stock_location_field($pdo, 'from_location', isset($cur['from_location_id']) ? (int) $cur['from_location_id'] : null, 'the location it leaves', $errors);
    $f['to_location_id'] = stock_location_field($pdo, 'to_location', isset($cur['to_location_id']) ? (int) $cur['to_location_id'] : null, 'the location it goes to', $errors);
    if ($f['from_location_id'] === null && !isset($errors['from_location'])) { $errors['from_location'] = 'Choose where the stock leaves from.'; }
    if ($f['to_location_id'] === null && !isset($errors['to_location'])) { $errors['to_location'] = 'Choose where the stock goes.'; }
    if ($f['from_location_id'] !== null && $f['from_location_id'] === $f['to_location_id']) { $errors['to_location'] = 'A transfer goes from one location to another — choose two different ones.'; }
    $f['notes'] = stock_text('notes', $cur['notes'] ?? null, 2000);
    return $f;
}

function transfer_line_from_request(PDO $pdo, ?array $cur, array &$errors): array
{
    $f = [];
    $scanned = false;
    $f['variant_id'] = stock_variant_from_request($pdo, $errors, $scanned, 'move', isset($cur['variant_id']) ? (int) $cur['variant_id'] : null);
    $f['scanned'] = $scanned;
    $f['qty'] = inv_int('qty', isset($cur['qty']) ? (int) $cur['qty'] : 1, 1, 1000000, 'The quantity', $errors);
    return $f;
}

function transfer_or_404(PDO $pdo, ?int $id): array
{
    $t = $id === null ? null : find_transfer($pdo, $id);
    if ($t === null) { refuse(404, 'Transfer not found.'); }
    return $t;
}

function count_or_404(PDO $pdo, ?int $id): array
{
    $c = $id === null ? null : find_count($pdo, $id);
    if ($c === null) { refuse(404, 'Count not found.'); }
    return $c;
}

/** The id of a line of a document from `line`, checked to belong to it; 404 otherwise. */
function stock_line_of(PDO $pdo, string $view, string $idCol, string $fkCol, int $docId, ?int $lineId): array
{
    if ($lineId === null) { refuse(404, 'Line not found.'); }
    $st = $pdo->prepare("SELECT * FROM $view WHERE $idCol = :id");
    $st->execute(['id' => $lineId]);
    $l = $st->fetch();
    if ($l === false || (int) $l[$fkCol] !== $docId) { refuse(404, 'Line not found.'); }
    return $l;
}

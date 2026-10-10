<?php
declare(strict_types=1);

/**
 * Purchasing's prelude (purchasing.md "Handlers"): the gate every purchase-order write starts with, the readers that turn the form (or an agent's JSON) into a header and lines — a field left out stays as
 * it was, a line with no cost takes the price sheet's or the offer's —, the log with the audit keys (`purchase_order_id`, `sales_order_id` for a drop-ship, `location_id` for a stock order's ship-to), the 404s
 * and po_refused(). The database decides a number, a total, what a sent order may do next.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__) . '/catalog/handler.php';
require_once dirname(__DIR__) . '/orders/handler.php';
require_once dirname(__DIR__) . '/suppliers/handler.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/write.php';
require_once __DIR__ . '/mail.php';

function purchasing_write_begin(string $right): void
{
    inv_handler_begin();
    require_right($right);
    // a line's mini-form names its fields under a row key (l12[qty]); that row's fields are merged into the request
    $rk = (string) ($_POST['rowkey'] ?? '');
    if ($rk !== '' && preg_match('/^[a-z][a-z0-9]*$/', $rk) === 1 && is_array($_POST[$rk] ?? null)) {
        $_POST = array_merge($_POST, $_POST[$rk]);
        unset($_POST[$rk], $_POST['rowkey']);
    }
}

function po_or_404(PDO $pdo, ?int $id): array
{
    $o = $id === null ? null : find_purchase_order($pdo, $id);
    if ($o === null) { refuse(404, 'Purchase order not found.'); }
    return $o;
}

/** The purchase order a request names: `purchase_order` / `purchase_order_id` / `id`, or the path's id. */
function request_po_id(): ?int
{
    return request_integer('purchase_order') ?? request_integer('purchase_order_id') ?? request_integer('id');
}

/** log_activity() for a purchase order: entity purchase_order, the audit key purchase_order_id, a drop-ship's sales_order_id and a stock order's location_id. */
function po_log(PDO $pdo, string $action, array $o, array $after, array $opts = []): void
{
    $id = (int) ($o['purchase_order_id'] ?? $o['id']);
    log_activity($pdo, $action, 'purchase_order', $id, ['purchase_order_id' => $id, 'sales_order_id' => $o['sales_order_id'] ?? null,
        'location_id' => ($o['kind'] ?? 'stock') === 'stock' ? ($o['location_id'] ?? null) : null, 'after' => $after] + $opts);
}

/** A draft is the only purchase order whose header and lines change. */
function require_po_draft(array $o): void
{
    if ($o['status'] !== 'draft') {
        refuse(422, $o['number'] . ' is ' . str_replace('_', ' ', $o['status']) . ' — only a draft changes');
    }
}

/** Run a step that sends mail: a refusal in words (422) is inv_guard()'s; a mail that could not go is a 503 in the sentence it carries. */
function po_refused(Throwable $e): never
{
    if ($e instanceof OrderMailUnavailable) { refuse(503, $e->getMessage()); }
    throw $e;
}

/** The loggable shape of a purchase order after a write (never an address, a phone, the internal notes or an account number). */
function po_loggable(array $o): array
{
    return ['number' => $o['number'], 'supplier_id' => (int) $o['supplier_id'], 'supplier_name' => $o['supplier_name'], 'kind' => $o['kind'], 'sales_order_id' => $o['sales_order_id'], 'ship_to_kind' => $o['ship_to_kind'],
            'location_id' => $o['location_id'], 'lines' => (int) $o['line_count'], 'total' => $o['total'], 'currency' => (string) (one_value(db(), 'SELECT currency FROM mcp_settings') ?? 'USD')];
}

/** A line's loggable facts: ids, SKUs, quantity, cost, the offer and the date. */
function po_line_loggable(PDO $pdo, int $lineId): array
{
    $l = one_row($pdo, 'SELECT pl.id AS line_id, pl.line_no, v.sku, pl.supplier_sku, pl.qty_ordered, pl.unit_cost, pl.listing_variant_id, pl.expected_on FROM purchase_order_lines pl JOIN product_variants v ON v.id = pl.variant_id WHERE pl.id = :id', ['id' => $lineId]) ?? [];
    foreach (['line_id', 'line_no', 'qty_ordered', 'listing_variant_id'] as $k) { if (isset($l[$k])) { $l[$k] = (int) $l[$k]; } }
    return $l;
}

/** A date field: '' → null, malformed → error, else the date. */
function po_date_field(string $name, ?string $keep, string $label, array &$errors): ?string
{
    if (!req_has($name)) { return $keep; }
    $v = (string) req_val($name);
    if ($v === '') { return null; }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    if ($d === false || $d->format('Y-m-d') !== $v) { $errors[$name] = $label . ' is a date.'; return $keep; }
    return $v;
}

/**
 * The header of a purchase order from the request. $cur null = a new stock order (the ship-to defaults to the first warehouse); else only the fields sent are returned. Returns the PO_HEAD_COLUMNS read.
 * A drop-ship's supplier is the offer's — a different supplier on an update of one is refused.
 */
function po_head_from_request(PDO $pdo, ?array $cur, array &$errors): array
{
    $h = [];
    $isNew = $cur === null;
    if (req_has('supplier') || req_has('supplier_id') || $isNew) {
        $v = trim((string) (req_val('supplier') ?? req_val('supplier_id') ?? ''));
        if ($v === '' || $v === '0') {
            if ($isNew) { $errors['supplier'] = 'Choose a supplier.'; }
        } else {
            $row = ctype_digit($v) ? one_row($pdo, 'SELECT supplier_id, active FROM mcp_suppliers WHERE supplier_id = :id', ['id' => (int) $v]) : null;
            if ($row === null) { $errors['supplier'] = 'That supplier is not here.'; }
            elseif (!$row['active'] && ($cur === null || (int) $cur['supplier_id'] !== (int) $v)) { $errors['supplier'] = 'That supplier is archived.'; }
            elseif ($cur !== null && $cur['kind'] === 'dropship' && (int) $cur['supplier_id'] !== (int) $v) { $errors['supplier'] = "A drop-ship's supplier is the offer's."; }
            else { $h['supplier_id'] = (int) $v; }
        }
    }
    if ($isNew || $cur['kind'] === 'stock') {
        if (req_has('location') || req_has('location_id') || $isNew) {
            $v = trim((string) (req_val('location') ?? req_val('location_id') ?? ''));
            if ($v === '') {
                if ($isNew) { $h['location_id'] = default_po_location($pdo); if ($h['location_id'] === null) { $errors['location'] = 'There is no location to ship to.'; } }
                else { $errors['location'] = 'Choose where it ships to.'; }
            } else {
                $ok = ctype_digit($v) ? one_value($pdo, 'SELECT 1 FROM mcp_locations WHERE location_id = :id AND active', ['id' => (int) $v]) : null;
                if ($ok === null) { $errors['location'] = 'That location is not here.'; } else { $h['location_id'] = (int) $v; }
            }
        }
    }
    if (req_has('expected_on')) { $h['expected_on'] = po_date_field('expected_on', $cur['expected_on'] ?? null, 'The expected date', $errors); }
    if (req_has('shipping_cost') || req_has('shipping')) {
        $in = req_has('shipping_cost') ? 'shipping_cost' : 'shipping';
        $v = (string) req_val($in);
        $n = preg_replace('/[^0-9.\-]/', '', $v);
        if ($v === '') { $h['shipping_cost'] = '0.00'; }
        elseif (!is_numeric($n) || (float) $n < 0 || (float) $n > 99999999) { $errors[$in] = 'Shipping is an amount of 0 or more.'; }
        else { $h['shipping_cost'] = number_format((float) $n, 2, '.', ''); }
    }
    foreach (['notes' => 'The notes to the supplier', 'internal_notes' => 'The internal notes'] as $k => $label) {
        if (!req_has($k)) { continue; }
        $v = (string) req_val($k);
        if (mb_strlen($v) > 2000) { $errors[$k] = $label . ' are up to 2,000 characters.'; } else { $h[$k] = $v === '' ? null : $v; }
    }
    return $h;
}

/** The lines the request carries: `lines` as JSON (an agent) or the form's lines[n][…] — blank rows skipped. Each row is a raw array. */
function po_rows_from_request(): array
{
    if (!req_has('lines') && !isset($_POST['lines'])) { return []; }
    $raw = $_POST['lines'] ?? [];
    if (!is_array($raw)) {
        $raw = (string) $raw === '' ? [] : json_decode((string) $raw, true);
        if (!is_array($raw) || ($raw !== [] && !array_is_list($raw))) { refuse(422, 'lines is a JSON list of objects.'); }
    }
    $rows = [];
    foreach ($raw as $r) {
        if (!is_array($r)) { refuse(422, 'lines is a JSON list of objects.'); }
        $r = array_map(static fn ($v) => is_scalar($v) || $v === null ? trim((string) $v) : $v, $r);
        if (trim((string) ($r['variant'] ?? '')) === '' && trim((string) ($r['variant_code'] ?? $r['sku'] ?? '')) === '') { continue; }      // a blank row
        $rows[] = $r;
    }
    return $rows;
}

/**
 * One row → the line's columns for save_po_line(): the variant resolved (an id, a SKU or a scanned code; a bundle is bought as its components, the trigger refuses it), qty, the cost as given or the
 * default of po_line_defaults() (the price sheet's, the offer's, the variant's), the supplier SKU as given or left to the trigger, the offer checked to be this supplier's offer for the variant.
 * Problems go to $errors under $key.
 */
function po_line_columns(PDO $pdo, int $supplierId, array $row, string $key, array &$errors): ?array
{
    $r = po_line_from_request($row);
    foreach ($r['errors'] as $k => $m) { $errors[$key . '.' . $k] = $m; }
    $v = line_variant($pdo, $r['variant']);
    if ($v === null) { $errors[$key . '.variant'] = $r['variant'] === '' ? 'Choose a variant.' : 'No variant has the code ' . $r['variant'] . '.'; return null; }
    $vid = (int) $v['variant_id'];
    if ($r['qty'] === null && !isset($r['errors']['qty'])) { $errors[$key . '.qty'] = 'Give the quantity.'; }
    $d = po_line_defaults($pdo, $supplierId, $vid);
    $lv = $r['listing_variant'];
    if ($lv !== null) {
        $ok = array_filter($d['offers'], static fn (array $o): bool => $o['listing_variant_id'] === $lv);
        if ($ok === []) { $errors[$key . '.listing_variant'] = "That is not this supplier's offer for the variant."; }
    } elseif (!$r['cost_given']) {
        $lv = $d['listing_variant_id'];
    }
    return ['variant_id' => $vid, 'qty' => (int) ($r['qty'] ?? 1), 'unit_cost' => $r['cost_given'] ? $r['unit_cost'] : $d['unit_cost'], 'supplier_sku' => $r['supplier_sku'], 'listing_variant_id' => $lv, 'expected_on' => $r['expected_on']];
}

/** Every row of the request → the lines to insert. Field errors are keyed "lines.N.field" and worded "Line n: …". */
function po_lines_from_request(PDO $pdo, int $supplierId, array &$errors): array
{
    $lines = [];
    $local = [];
    foreach (po_rows_from_request() as $i => $row) {
        $e = [];
        $c = po_line_columns($pdo, $supplierId, $row, 'line' . $i, $e);
        if ($c !== null && $e === []) { $lines[] = $c; }
        foreach ($e as $k => $m) { $local['lines.' . $i . '.' . substr($k, strpos($k, '.') + 1)] = 'Line ' . ($i + 1) . ': ' . $m; }
    }
    $errors += $local;
    return $lines;
}

/** The region of the lines editor and the totals panel, for an HTMX caller whose target is #po-lines. */
function po_lines_fragment(PDO $pdo, int $poId): string
{
    $o = find_purchase_order($pdo, $poId);
    return view('purchasing/partials/lines.php', ['o' => $o, 'editable' => $o['status'] === 'draft', 'seesCost' => sees_cost(), 'form' => true, 'may' => ['write' => has_right('purchasing.write')], 'here' => '/purchasing/' . $poId])
        . '<div id="po-totals" hx-swap-oob="true">' . view('purchasing/partials/totals.php', ['o' => $o, 'seesCost' => sees_cost()]) . '</div>';
}

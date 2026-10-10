<?php
declare(strict_types=1);

/**
 * Orders' prelude (orders.md "Handlers"): the gate every order write starts with, the readers that turn the form (or an agent's JSON) into a quote's header and
 * lines — a field left out stays as it was —, the line reader that expands a bundle into its components and gives a line with no fulfilment the recommended one, the
 * log with the audit keys (`sales_order_id`, the store as `location_id`), the 404s and order_refused(). The database decides a price, a total, an allocation.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__) . '/catalog/handler.php';
require_once dirname(__DIR__) . '/customers/handler.php';
require_once dirname(__DIR__) . '/find/queries.php';
require_once dirname(__DIR__) . '/find/present.php';
require_once dirname(__DIR__) . '/stock/queries.php';
require_once dirname(__DIR__) . '/shipments/queries.php';
require_once dirname(__DIR__) . '/shipments/present.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/write.php';
require_once __DIR__ . '/mail.php';

function orders_write_begin(string $right): void
{
    inv_handler_begin();
    require_right($right);
    // a line's mini-form names its picker's radios under a row key (l12[fulfilment]); that row's fields are merged into the request
    $rk = (string) ($_POST['rowkey'] ?? '');
    if ($rk !== '' && preg_match('/^[a-z][a-z0-9]*$/', $rk) === 1 && is_array($_POST[$rk] ?? null)) {
        $_POST = array_merge($_POST, $_POST[$rk]);
        unset($_POST[$rk], $_POST['rowkey']);
    }
}

function order_or_404(PDO $pdo, ?int $id): array
{
    $o = $id === null ? null : find_order($pdo, $id);
    if ($o === null) { refuse(404, 'Order not found.'); }
    return $o;
}

/** log_activity() for an order: entity sales_order, the audit key sales_order_id (the helper's default) and the store as location_id. */
function order_log(PDO $pdo, string $action, array $o, array $after, array $opts = []): void
{
    $id = (int) ($o['sales_order_id'] ?? $o['id']);
    log_activity($pdo, $action, 'sales_order', $id, ['sales_order_id' => $id, 'location_id' => $o['location_id'] ?? null, 'after' => $after] + $opts);
}

/** A quote is the only order whose header and lines change. */
function require_quote(array $o): void
{
    if ($o['status'] !== 'quote') {
        refuse(422, $o['number'] . ' is ' . str_replace('_', ' ', $o['status']) . ' — cancel a line or the order instead');
    }
}

/** Run a step that sends mail: a refusal in words (422) is inv_guard()'s; a mail that could not go is a 503 in the sentence it carries. */
function order_refused(Throwable $e): never
{
    if ($e instanceof OrderMailUnavailable) { refuse(503, $e->getMessage()); }
    throw $e;
}

/** A date field: '' → null, malformed → error, else the date. */
function order_date_field(string $name, ?string $keep, string $label, array &$errors): ?string
{
    if (!req_has($name)) { return $keep; }
    $v = (string) req_val($name);
    if ($v === '') { return null; }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    if ($d === false || $d->format('Y-m-d') !== $v) { $errors[$name] = $label . ' is a date.'; return $keep; }
    return $v;
}

/**
 * The header of a quote from the request. $cur null = a new quote (defaults filled: the delivery method, the store, me); else only the fields sent are returned (the
 * rest stay). Returns [head, newCustomer|null, headerDiscount|null] — newCustomer is ['name', 'email', 'phone'] when the three new_customer_* fields name one.
 */
function order_head_from_request(PDO $pdo, ?array $cur, array &$errors): array
{
    $h = [];
    $new = null;
    $isNew = $cur === null;
    // the customer: an id, or a new one by name (+ email, phone)
    $cid = trim((string) (req_val('customer') ?? req_val('customer_id') ?? ''));
    $ncName = trim((string) (req_val('new_customer_name') ?? ''));
    if ($cid !== '' && $cid !== '0') {
        $ok = ctype_digit($cid) ? one_value($pdo, 'SELECT 1 FROM mcp_customers WHERE customer_id = :id AND archived_at IS NULL', ['id' => (int) $cid]) : null;
        if ($ok === null) { $errors['customer'] = 'That customer is not here.'; } else { $h['customer_id'] = (int) $cid; }
    } elseif ($ncName !== '') {
        $email = trim((string) (req_val('new_customer_email') ?? ''));
        if (mb_strlen($ncName) > 200) { $errors['new_customer_name'] = 'Give the customer a name of up to 200 characters.'; }
        if ($email !== '' && (mb_strlen($email) > 200 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) { $errors['new_customer_email'] = 'That is not an email address.'; }
        $phone = trim((string) (req_val('new_customer_phone') ?? ''));
        if (mb_strlen($phone) > 40) { $errors['new_customer_phone'] = 'The phone is up to 40 characters.'; }
        $new = ['name' => $ncName, 'email' => $email === '' ? null : $email, 'phone' => $phone === '' ? null : $phone];
    } elseif ($isNew) {
        $errors['customer'] = 'Pick a customer or name a new one.';
    }
    // the store, the salesperson, the delivery
    if (req_has('location') || req_has('location_id') || $isNew) {
        $v = trim((string) (req_val('location') ?? req_val('location_id') ?? ''));
        if ($v === '' && $isNew) { $h['location_id'] = default_store($pdo); }
        elseif ($v === '') { $errors['location'] = 'Choose the store that sold it.'; }
        else {
            $ok = ctype_digit($v) ? one_value($pdo, 'SELECT 1 FROM mcp_locations WHERE location_id = :id AND active', ['id' => (int) $v]) : null;
            if ($ok === null) { $errors['location'] = 'That store is not here.'; } else { $h['location_id'] = (int) $v; }
        }
    }
    if (req_has('salesperson') || req_has('salesperson_member_id') || $isNew) {
        $v = trim((string) (req_val('salesperson') ?? req_val('salesperson_member_id') ?? ''));
        if ($v === '') { if ($isNew) { $h['salesperson_member_id'] = (int) current_member_id(); } }
        else {
            $ok = ctype_digit($v) ? one_value($pdo, "SELECT 1 FROM members WHERE id = :id AND status = 'active' AND capability IS NOT NULL", ['id' => (int) $v]) : null;
            if ($ok === null) { $errors['salesperson'] = 'That salesperson is not here.'; } else { $h['salesperson_member_id'] = (int) $v; }
        }
    }
    if (req_has('delivery_method') || $isNew) {
        $v = trim((string) (req_val('delivery_method') ?? ''));
        if ($v === '' && $isNew) { $v = 'delivery'; }
        if (!isset(DELIVERY_METHODS[$v])) { $errors['delivery_method'] = 'Delivery is pickup, delivery, parcel, ltl or white_glove.'; } else { $h['delivery_method'] = $v; }
    }
    if (req_has('promised_on')) {
        $p = order_date_field('promised_on', null, 'The promised date', $errors);
        $ordered = $cur['ordered_on'] ?? date('Y-m-d');
        if ($p !== null && $p < $ordered) { $errors['promised_on'] = 'The promised date is not before the order date (' . format_date($ordered) . ').'; } else { $h['promised_on'] = $p; }
    }
    // the ship-to
    $ship = ['ship_to_name' => [200, 'The ship-to name'], 'ship_to_address1' => [200, 'The address'], 'ship_to_address2' => [200, 'The second address line'], 'ship_to_city' => [100, 'The city'],
             'ship_to_region' => [100, 'The region'], 'ship_to_postal' => [20, 'The postal code'], 'ship_to_phone' => [40, 'The phone'], 'ship_to_notes' => [500, 'The delivery notes']];
    foreach ($ship as $col => [$max, $label]) {
        $name = $col === 'ship_to_address1' && !req_has($col) && req_has('ship_to_address') ? 'ship_to_address' : $col;      // the manifest's `ship_to_address`
        if (!req_has($name)) { continue; }
        $v = (string) req_val($name);
        if (mb_strlen($v) > $max) { $errors[$col] = $label . ' is up to ' . $max . ' characters.'; continue; }
        $h[$col] = $v === '' ? null : $v;
    }
    if (req_has('ship_to_country')) {
        $v = strtoupper((string) req_val('ship_to_country'));
        if ($v !== '' && !preg_match('/^[A-Z]{2}$/', $v)) { $errors['ship_to_country'] = 'The country is two letters (US, CA).'; } else { $h['ship_to_country'] = $v === '' ? null : $v; }
    }
    // tax, shipping, reference, notes
    if (req_has('tax_rate') || req_has('tax_rate_id')) {
        $v = trim((string) (req_val('tax_rate') ?? req_val('tax_rate_id') ?? ''));
        if ($v === '') { $h['tax_rate_id'] = null; }
        else {
            $ok = ctype_digit($v) ? one_value($pdo, 'SELECT 1 FROM mcp_tax_rates WHERE tax_rate_id = :id AND archived_at IS NULL', ['id' => (int) $v]) : null;
            if ($ok === null) { $errors['tax_rate'] = 'That tax rate is not here.'; } else { $h['tax_rate_id'] = (int) $v; }
        }
    }
    foreach (['shipping_charge' => 'shipping_charge', 'shipping' => 'shipping_charge'] as $in => $col) {
        if (!req_has($in) || isset($h[$col])) { continue; }
        $v = (string) req_val($in);
        $n = preg_replace('/[^0-9.\-]/', '', $v);
        if ($v === '') { $h[$col] = '0.00'; }
        elseif (!is_numeric($n) || (float) $n < 0 || (float) $n > 99999999) { $errors[$in] = 'Shipping is an amount of 0 or more.'; }
        else { $h[$col] = number_format((float) $n, 2, '.', ''); }
    }
    if (req_has('customer_reference')) {
        $v = (string) req_val('customer_reference');
        if (mb_strlen($v) > 100) { $errors['customer_reference'] = 'The customer reference is up to 100 characters.'; } else { $h['customer_reference'] = $v === '' ? null : $v; }
    }
    if (req_has('notes')) {
        $v = (string) req_val('notes');
        if (mb_strlen($v) > 2000) { $errors['notes'] = 'The notes are up to 2,000 characters.'; } else { $h['notes'] = $v === '' ? null : $v; }
    }
    $disc = null;
    if (req_has('discount') && (string) req_val('discount') !== '') {
        $n = preg_replace('/[^0-9.\-]/', '', (string) req_val('discount'));
        if (!is_numeric($n) || (float) $n < 0) { $errors['discount'] = 'The discount is an amount of 0 or more.'; } else { $disc = number_format((float) $n, 2, '.', ''); }
    }
    return [$h, $new, $disc];
}

/** The lines the request carries: `lines` as JSON (an agent) or the form's lines[n][…] — blank rows skipped. Each row is a raw array. */
function order_rows_from_request(): array
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

/** The backorder location a line takes when none is chosen: the first sellable location (a warehouse first). */
function default_backorder_location(PDO $pdo): ?int
{
    $l = sellable_locations($pdo);
    return $l === [] ? null : (int) $l[0]['location_id'];
}

/**
 * One row → the line's columns for save_order_line(): the variant resolved (an id, a SKU or a scanned code), qty (1 when blank), the price and discount as given, the fulfilment
 * as given or the RECOMMENDED one (find.md) when the row names none. A bundle returns its components, the set's price on the first. Returns the list of lines; problems go to $errors under $key.
 */
function order_line_columns(PDO $pdo, array $row, string $key, array &$errors): array
{
    $r = order_line_from_request($row);
    foreach ($r['errors'] as $k => $m) { $errors[$key . '.' . $k] = $m; }
    $v = line_variant($pdo, $r['variant']);
    if ($v === null) { $errors[$key . '.variant'] = $r['variant'] === '' ? 'Choose a variant.' : 'No variant has the code ' . $r['variant'] . '.'; return []; }
    $vid = (int) $v['variant_id'];
    $qty = $r['qty'] ?? 1;
    if (!(bool) $v['active']) { $errors[$key . '.variant'] = $v['sku'] . ' is not for sale (it is inactive).'; return []; }
    if ($v['kind'] === 'bundle') {
        $parts = expand_bundle_line($pdo, $vid, $qty, $r['unit_price']);
        if ($parts === []) { $errors[$key . '.variant'] = $v['sku'] . ' is a bundle with no components.'; return []; }
        $out = [];
        foreach ($parts as $i => $p) {
            $rec = recommended_fulfilment(variant_availability($pdo, $p['variant_id']), $p['qty']);
            $out[] = ['variant_id' => $p['variant_id'], 'qty' => $p['qty'], 'unit_price' => $p['unit_price'], 'discount' => $i === 0 ? $r['discount'] : '0.00', 'notes' => $i === 0 ? $r['notes'] : null,
                      'fulfilment_kind' => $rec['kind'], 'location_id' => $rec['location_id'] ?? ($rec['kind'] === 'backorder' ? default_backorder_location($pdo) : null), 'listing_variant_id' => $rec['listing_variant_id']];
        }
        return $out;
    }
    $f = $r['fulfilment'];
    if ($f['kind'] === null) {
        $rec = recommended_fulfilment(variant_availability($pdo, $vid), $qty);
        $f = ['kind' => $rec['kind'], 'location_id' => $rec['location_id'], 'listing_variant_id' => $rec['listing_variant_id']];
    }
    if ($f['kind'] === 'backorder' && $f['location_id'] === null) { $f['location_id'] = default_backorder_location($pdo); }
    return [['variant_id' => $vid, 'qty' => $qty, 'unit_price' => $r['unit_price'], 'discount' => $r['discount'], 'notes' => $r['notes'],
             'fulfilment_kind' => $f['kind'], 'location_id' => $f['location_id'], 'listing_variant_id' => $f['listing_variant_id']]];
}

/** Every row of the request → the lines to insert (bundles expanded). Field errors are keyed "lines.N.field" and worded "Line n: …". */
function order_lines_from_request(PDO $pdo, array &$errors): array
{
    $lines = [];
    $local = [];
    foreach (order_rows_from_request() as $i => $row) {
        $e = [];
        foreach (order_line_columns($pdo, $row, 'line' . $i, $e) as $c) { $lines[] = $c; }
        foreach ($e as $k => $m) { $local['lines.' . $i . '.' . substr($k, strpos($k, '.') + 1)] = 'Line ' . ($i + 1) . ': ' . $m; }
    }
    $errors += $local;
    return $lines;
}

/** The loggable shape of an order after a write (never an address, a phone or a note's words). */
function order_loggable(array $o): array
{
    return ['number' => $o['number'], 'customer_id' => (int) $o['customer_id'], 'customer_name' => $o['customer_name'], 'salesperson_member_id' => $o['salesperson_member_id'], 'location_id' => $o['location_id'],
            'status' => $o['status'], 'lines' => (int) $o['line_count'], 'total' => $o['total'], 'currency' => (string) (one_value(db(), 'SELECT currency FROM mcp_settings') ?? 'USD')];
}

/** A line's loggable facts (orders.md "Activity log events"): ids, SKU, qty, price, fulfilment, the offer's snapshot. */
function line_loggable(PDO $pdo, int $lineId): array
{
    $l = one_row($pdo, 'SELECT l.id AS line_id, l.line_no, v.sku, l.qty, l.unit_price, l.discount, l.fulfilment_kind, l.location_id, l.listing_variant_id, l.source_id, l.offer_cost, l.offer_lead_time_days
                          FROM sales_order_lines l JOIN product_variants v ON v.id = l.variant_id WHERE l.id = :id', ['id' => $lineId]) ?? [];
    foreach (['line_id', 'line_no', 'qty', 'location_id', 'listing_variant_id', 'source_id', 'offer_lead_time_days'] as $k) { if (isset($l[$k])) { $l[$k] = (int) $l[$k]; } }
    return $l;
}

/** Re-render of the lines region for an HTMX caller whose target is #order-lines (the edit form's page-of-a-record rewrite). */
function orders_lines_fragment(PDO $pdo, int $orderId): string
{
    $o = find_order($pdo, $orderId);
    return view('orders/partials/lines.php', ['o' => $o, 'editable' => $o['status'] === 'quote', 'seesCost' => sees_cost(), 'form' => true, 'locations' => sellable_locations($pdo)])
        . '<div id="order-totals" hx-swap-oob="true">' . view('orders/partials/totals.php', ['o' => $o]) . '</div>';
}

/** A date-time field (a form's datetime-local, a date, an agent's ISO): '' → null (the database says now), malformed → error. Returns 'Y-m-d H:i:s' in UTC terms of the server clock. */
function order_datetime_field(string $name, string $label, array &$errors): ?string
{
    $v = trim((string) (req_val($name) ?? ''));
    if ($v === '') { return null; }
    try {
        $d = new DateTimeImmutable(str_replace('T', ' ', $v), new DateTimeZone('UTC'));
    } catch (Exception) {
        $errors[$name] = $label . ' is a date and time.';
        return null;
    }
    return $d->format('Y-m-d H:i:sP');
}

/** A payment's fields (payment_record, refund_record): amount, method, reference, taken_at, note. */
function order_payment_from_request(array &$errors, string $kind): array
{
    $amount = trim((string) (req_val('amount') ?? ''));
    $n = preg_replace('/[^0-9.\-]/', '', $amount);
    if ($amount === '' || !is_numeric($n) || (float) $n <= 0 || (float) $n > 99999999) { $errors['amount'] = 'The amount is more than 0.'; $n = '0'; }
    $method = trim((string) (req_val('method') ?? ''));
    if (!isset(PAYMENT_METHODS[$method])) { $errors['method'] = 'The method is cash, card, check, transfer, financing or other.'; }
    $ref = trim((string) (req_val('reference') ?? ''));
    if (mb_strlen($ref) > 100) { $errors['reference'] = 'The reference is up to 100 characters.'; }
    $note = trim((string) (req_val('note') ?? ''));
    if (mb_strlen($note) > 500) { $errors['note'] = 'The note is up to 500 characters.'; }
    return ['kind' => $kind, 'amount' => number_format((float) $n, 2, '.', ''), 'method' => $method, 'reference' => $ref === '' ? null : $ref,
            'taken_at' => order_datetime_field('taken_at', 'The time taken', $errors), 'note' => $note === '' ? null : $note];
}

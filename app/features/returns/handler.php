<?php
declare(strict_types=1);

/**
 * Returns' prelude (returns-worker.md "Handlers"): the gate every return write starts with, the readers that turn the form (or an agent's JSON) into a header and lines, the log with the audit
 * keys (`sales_order_id` on every row) and the people a return tells. The database decides what may come back; PHP says whose turn it is.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__) . '/orders/handler.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/write.php';

function returns_write_begin(string $right): void
{
    inv_handler_begin();
    require_right($right);
}

function return_or_404(PDO $pdo, ?int $id): array
{
    $r = $id === null ? null : find_return($pdo, $id);
    if ($r === null) { refuse(404, 'Return not found.'); }
    return $r;
}

/** The return id a request names: `return` (an id or an RA- number), `return_id`, `id`. */
function request_return_id(PDO $pdo): ?int
{
    $v = trim((string) (req_val('return') ?? req_val('return_id') ?? req_val('id') ?? ($_GET['id'] ?? $_GET['return'] ?? '')));
    if ($v === '') { return null; }
    if (ctype_digit($v)) { return (int) $v; }
    $id = one_value($pdo, 'SELECT return_id FROM mcp_return_authorizations WHERE upper(number) = upper(:n)', ['n' => $v]);
    return $id === null ? null : (int) $id;
}

/** log_activity() for a return: entity return_authorization, the order as the audit key (and the location a receipt came back to). */
function return_log(PDO $pdo, string $action, array $r, array $after, array $opts = []): void
{
    $id = (int) ($r['return_id'] ?? $r['id']);
    log_activity($pdo, $action, 'return_authorization', $id, ['sales_order_id' => (int) $r['sales_order_id']] + $opts + ['after' => $after]);
}

/** The screen-view row of a return's page (with the order as the audit key); like log_screen_view(), JSON callers log it only when they ask. */
function return_screen_view(PDO $pdo, array $r, string $screen): void
{
    if (wants_json() && ($_SERVER['HTTP_X_SCREEN_VIEW'] ?? '') !== '1') { return; }
    log_activity($pdo, 'screen.view', 'return_authorization', (int) $r['return_id'], ['sales_order_id' => (int) $r['sales_order_id'], 'screen' => $screen, 'after' => ['number' => $r['number']]]);
}

/** A date field: '' → null, malformed → error. A return's date may be past (a drop-off that happened). */
function return_date_field(string $name, ?string $keep, array &$errors): ?string
{
    if (!req_has($name)) { return $keep; }
    $v = (string) req_val($name);
    if ($v === '') { return null; }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    if ($d === false || $d->format('Y-m-d') !== $v) { $errors[$name] = 'The date is year-month-day.'; return $keep; }
    return $v;
}

/**
 * The header of a return from the request: only the fields sent are returned (a field left out stays as it was). method, scheduled_on, location (an active location), notes.
 * $cur null = a new return (the method defaults to pickup).
 */
function return_header_from_request(PDO $pdo, ?array $cur, array &$errors): array
{
    $h = [];
    if (req_has('method') || $cur === null) {
        $m = trim((string) (req_val('method') ?? ''));
        if ($m === '' && $cur === null) { $m = 'pickup'; }
        if (!isset(RETURN_METHODS[$m])) { $errors['method'] = 'The method is pickup or drop_off.'; } else { $h['method'] = $m; }
    }
    if (req_has('scheduled_on')) { $h['scheduled_on'] = return_date_field('scheduled_on', $cur['scheduled_on'] ?? null, $errors); }
    if (req_has('location') || req_has('location_id')) {
        $v = trim((string) (req_val('location') ?? req_val('location_id') ?? ''));
        if ($v === '' || $v === '0') { $h['location_id'] = null; }
        else {
            $ok = ctype_digit($v) ? one_value($pdo, 'SELECT 1 FROM mcp_locations WHERE location_id = :id AND active', ['id' => (int) $v]) : null;
            if ($ok === null) { $errors['location'] = 'That location is not here.'; } else { $h['location_id'] = (int) $v; }
        }
    }
    if (req_has('notes')) {
        $n = (string) req_val('notes');
        if (mb_strlen($n) > 2000) { $errors['notes'] = 'The notes are up to 2,000 characters.'; } else { $h['notes'] = $n === '' ? null : $n; }
    }
    return $h;
}

/** One line as the request gives it ({line|sales_order_line, qty, reason, disposition?, location?, condition_note?}) → the columns, or field errors under $key. */
function return_line_from_row(PDO $pdo, array $r, string $key, array &$errors, ?int $orderId = null): ?array
{
    $line = trim((string) ($r['line'] ?? $r['sales_order_line'] ?? $r['sales_order_line_id'] ?? ''));
    if ($line === '' || !ctype_digit($line)) { $errors[$key . '.line'] = 'Say which order line comes back.'; return null; }
    $own = one_row($pdo, 'SELECT line_id, sales_order_id, sku FROM mcp_sales_order_lines WHERE line_id = :l', ['l' => (int) $line]);
    if ($own === null || ($orderId !== null && (int) $own['sales_order_id'] !== $orderId)) { $errors[$key . '.line'] = 'That line is not on the order.'; return null; }
    $q = trim((string) ($r['qty'] ?? ''));
    if ($q === '' || filter_var($q, FILTER_VALIDATE_INT) === false || (int) $q < 1 || (int) $q > 100000) { $errors[$key . '.qty'] = 'The quantity is a whole number of 1 or more.'; $q = '1'; }
    $reason = find_return_reason($pdo, (string) ($r['reason'] ?? $r['reason_code'] ?? $r['reason_code_id'] ?? ''));
    if ($reason === null) { $errors[$key . '.reason'] = 'Choose a reason.'; }
    $d = trim((string) ($r['disposition'] ?? ''));
    if ($d === '') { $d = 'restock'; }
    if (!isset(RETURN_DISPOSITIONS[$d])) { $errors[$key . '.disposition'] = 'The disposition is restock, floor_model, dispose, return_to_supplier or donate.'; }
    $loc = trim((string) ($r['location'] ?? $r['location_id'] ?? ''));
    if ($loc !== '' && (!ctype_digit($loc) || one_value($pdo, 'SELECT 1 FROM mcp_locations WHERE location_id = :id AND active', ['id' => (int) $loc]) === null)) { $errors[$key . '.location'] = 'That location is not here.'; }
    $note = trim((string) ($r['condition_note'] ?? ''));
    if (mb_strlen($note) > 500) { $errors[$key . '.condition_note'] = 'The condition note is up to 500 characters.'; }
    if ($errors !== [] && isset($errors[$key . '.line'])) { return null; }
    return ['sales_order_line_id' => (int) $line, 'qty' => (int) $q, 'reason_code_id' => $reason === null ? 0 : (int) $reason['reason_code_id'], 'reason_code' => $reason['code'] ?? null, 'disposition' => $d,
            'location_id' => $loc === '' ? null : (int) $loc, 'condition_note' => $note === '' ? null : $note, 'sku' => $own['sku']];
}

/**
 * The lines the request carries: `lines` as JSON (an agent: [{line, qty, reason, disposition?, location?, condition_note?}]) or the form's lines[<order line id>][qty|reason|disposition|location|condition_note]
 * (a line with qty 0 or blank is skipped). null = the request carries no `lines` at all (leave them as they are).
 */
function return_lines_from_request(PDO $pdo, ?int $orderId, array &$errors): ?array
{
    if (!isset($_POST['lines'])) { return null; }
    $raw = $_POST['lines'];
    if (!is_array($raw)) {
        $raw = (string) $raw === '' ? [] : json_decode((string) $raw, true);
        if (!is_array($raw) || ($raw !== [] && !array_is_list($raw))) { refuse(422, 'lines is a JSON list of {line, qty, reason}.'); }
    }
    $out = [];
    $local = [];
    $seen = [];
    foreach ($raw as $key => $r) {
        if (!is_array($r)) { refuse(422, 'lines is a JSON list of {line, qty, reason}.'); }
        if (!array_is_list($raw) || (!isset($r['line']) && !isset($r['sales_order_line']) && !isset($r['sales_order_line_id']))) { $r['line'] = $r['line'] ?? (string) $key; }       // the form: the key is the order line
        $q = trim((string) ($r['qty'] ?? ''));
        if ($q === '' || $q === '0') { continue; }
        $e = [];
        $l = return_line_from_row($pdo, $r, 'k', $e, $orderId);
        foreach ($e as $k => $m) { $local['lines.' . ($r['line'] ?? $key) . '.' . substr($k, 2)] = $m; }
        if ($l !== null && isset($seen[$l['sales_order_line_id']])) { $local['lines.' . $l['sales_order_line_id']] = 'That line is already on this return — change its quantity.'; continue; }
        if ($l !== null) { $seen[$l['sales_order_line_id']] = true; $out[] = $l; }
    }
    $errors += $local;
    return $out;
}

/** The people a received return tells: its requester and the order's salesperson (one notice each, never the same person twice). */
function return_people(array $r): array
{
    return array_values(array_unique(array_filter([$r['requested_by'] ?? null, $r['salesperson_member_id'] ?? null], static fn ($m): bool => $m !== null)));
}

/** "RA-00001" · "pickup on Oct 12" — the words a notice says about when. */
function return_when_words(array $r): string
{
    return (RETURN_METHODS[$r['method']] ?? $r['method']) . ($r['scheduled_on'] !== null ? ' on ' . format_date((string) $r['scheduled_on']) : '');
}

/** The loggable shape of a return after a write (never a customer's contact details or a note's words). */
function return_loggable(array $r): array
{
    return ['number' => $r['number'], 'customer_id' => (int) $r['customer_id'], 'status' => $r['status'], 'method' => $r['method'], 'scheduled_on' => $r['scheduled_on'], 'location_id' => $r['location_id']];
}

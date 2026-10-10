<?php
declare(strict_types=1);

/**
 * Suppliers' prelude (purchasing.md "Suppliers"): the reader that turns the form (or an agent's call) into save_supplier()'s fields — a field left out stays as it was, and the
 * account number is read from the BASE row because the view hides it from a caller without purchasing.write —, the loggable shape of a supplier (a log row never carries the account
 * number), and the 404. Required by every html/suppliers controller and by the purchasing prelude.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__) . '/catalog/handler.php';
require_once dirname(__DIR__) . '/orders/handler.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/write.php';
require_once dirname(__DIR__) . '/purchasing/queries.php';         // the purchase-order vocabulary and reads the supplier's page shows
require_once dirname(__DIR__) . '/purchasing/present.php';        // …and its chips
require_once dirname(__DIR__) . '/purchasing/mail.php';           // supplier_message_mail()

/** A URL field: '' → null, an http(s) URL else an error. */
function supplier_url_field(string $name, ?string $keep, string $label, array &$errors): ?string
{
    if (!req_has($name)) { return $keep; }
    $v = (string) req_val($name);
    if ($v === '') { return null; }
    if (mb_strlen($v) > 300 || !preg_match('#^https?://[^\s/]+#i', $v) || filter_var($v, FILTER_VALIDATE_URL) === false) { $errors[$name] = $label . ' is a web address (https://…).'; return $keep; }
    return $v;
}

/** The fields of save_supplier() from the request; $cur is the BASE row (an update) or null. Field errors collect in $errors. */
function supplier_from_request(PDO $pdo, ?array $cur, array &$errors): array
{
    $f = [];
    $f['name'] = req_has('name') ? (string) req_val('name') : (string) ($cur['name'] ?? '');
    if ($f['name'] === '' || mb_strlen($f['name']) > 200) { $errors['name'] = 'Give the supplier a name of up to 200 characters.'; }
    $text = static function (string $name, ?string $keep, int $max, string $label) use (&$errors): ?string {
        if (!req_has($name)) { return $keep; }
        $v = (string) req_val($name);
        if ($v === '') { return null; }
        if (mb_strlen($v) > $max) { $errors[$name] = $label . ' is up to ' . $max . ' characters.'; return $keep; }
        return $v;
    };
    $f['kind'] = $cur['kind'] ?? 'vendor';
    if (req_has('kind') && (string) req_val('kind') !== '') {
        $k = (string) req_val('kind');
        if (!isset(SUPPLIER_KINDS[$k]) && $k !== ($cur['kind'] ?? null)) { $errors['kind'] = 'The kind is manufacturer, distributor, wholesaler, marketplace, vendor or other.'; } else { $f['kind'] = $k; }
    }
    $f['contact_name'] = $text('contact_name', $cur['contact_name'] ?? null, 120, 'The contact name');
    foreach (['email' => 'email', 'order_email' => 'order_email'] as $name => $_) {
        $f[$name] = $cur[$name] ?? null;
        if (req_has($name)) {
            $v = (string) req_val($name);
            if ($v === '') { $f[$name] = null; }
            elseif (mb_strlen($v) > 200 || filter_var($v, FILTER_VALIDATE_EMAIL) === false) { $errors[$name] = 'That is not an email address.'; }
            else { $f[$name] = $v; }
        }
    }
    $f['phone'] = $text('phone', $cur['phone'] ?? null, 40, 'The phone');
    $f['address'] = $text('address', $cur['address'] ?? null, 500, 'The address');
    $f['website'] = supplier_url_field('website', $cur['website'] ?? null, 'The website', $errors);
    $f['portal_url'] = supplier_url_field('portal_url', $cur['portal_url'] ?? null, 'The portal address', $errors);
    // the account number: only a holder of purchasing.write reads or changes it (the view hides it from the rest — a save without the right keeps what is there)
    $f['account_number'] = $cur['account_number'] ?? null;
    if (req_has('account_number') && has_right('purchasing.write')) { $f['account_number'] = $text('account_number', $f['account_number'], 60, 'The account number'); }
    $f['terms'] = $text('terms', $cur['terms'] ?? null, 60, 'The terms');
    $f['dropships'] = inv_yes('dropships', (bool) ($cur['dropships'] ?? false));
    $f['lead_time_days'] = inv_int('lead_time_days', isset($cur['lead_time_days']) ? (int) $cur['lead_time_days'] : null, 0, 365, 'The lead time', $errors, true);
    $f['order_method'] = $cur['order_method'] ?? 'email';
    if (req_has('order_method') && (string) req_val('order_method') !== '') {
        $m = (string) req_val('order_method');
        if (!isset(SUPPLIER_ORDER_METHODS[$m])) { $errors['order_method'] = 'The order method is email, portal, api, edi or phone.'; } else { $f['order_method'] = $m; }
    }
    $f['min_order'] = $cur['min_order'] ?? null;
    if (req_has('min_order')) {
        $v = (string) req_val('min_order');
        $n = preg_replace('/[^0-9.\-]/', '', $v);
        if ($v === '') { $f['min_order'] = null; }
        elseif (!is_numeric($n) || (float) $n < 0 || (float) $n > 99999999) { $errors['min_order'] = 'The minimum order is an amount of 0 or more.'; }
        else { $f['min_order'] = number_format((float) $n, 2, '.', ''); }
    }
    $f['notes'] = $text('notes', $cur['notes'] ?? null, 2000, 'The notes');
    return $f;
}

/** What a log row may say: names and settings — never the account number, the contact's words or the notes. */
function supplier_loggable(array $s): array
{
    return ['name' => $s['name'], 'kind' => $s['kind'], 'dropships' => (bool) $s['dropships'], 'order_method' => $s['order_method'], 'lead_time_days' => $s['lead_time_days'] === null ? null : (int) $s['lead_time_days']];
}

/** The base row (every column, the account number too) for the save handler's "left out stays" — only the writer's own connection reads it. */
function supplier_base_row(PDO $pdo, int $id): ?array
{
    return one_row($pdo, 'SELECT id AS supplier_id, name, kind, contact_name, email, order_email, phone, address, website, portal_url, account_number, terms, dropships, lead_time_days, order_method, min_order, notes, active FROM suppliers WHERE id = :id', ['id' => $id]);
}

function supplier_or_404(PDO $pdo, ?int $id): array
{
    $s = $id === null ? null : find_supplier($pdo, $id);
    if ($s === null) { refuse(404, 'Supplier not found.'); }
    return $s;
}

/** The fields of the supplier that changed, by name — for the log row. */
function supplier_changed(array $before, array $after): array
{
    $out = [];
    foreach (array_keys(SUPPLIER_COLUMN_MAP) as $k) {
        if ((string) ($before[$k] ?? '') !== (string) ($after[$k] ?? '')) { $out[] = $k; }
    }
    return $out;
}

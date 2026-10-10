<?php
declare(strict_types=1);

/**
 * Customers' prelude (orders.md "Customers"): the reader that turns the form (or an agent's call) into save_customer()'s fields — a field left out stays as it
 * was —, the loggable shape of a customer (a log row says "email changed", never the address, the phone or the notes), and the 404. Required by every
 * html/customers controller (and by the order form, which may make a customer first).
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__) . '/catalog/handler.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/write.php';

/** The fields of save_customer() from the request; $cur is the current row (an update) or null. Field errors collect in $errors. */
function customer_from_request(PDO $pdo, ?array $cur, array &$errors): array
{
    $f = [];
    $f['name'] = req_has('name') ? (string) req_val('name') : (string) ($cur['name'] ?? '');
    if ($f['name'] === '' || mb_strlen($f['name']) > 200) {
        $errors['name'] = 'Give the customer a name of up to 200 characters.';
    }
    $text = static function (string $name, ?string $keep, int $max, string $label) use (&$errors): ?string {
        if (!req_has($name)) { return $keep; }
        $v = (string) req_val($name);
        if ($v === '') { return null; }
        if (mb_strlen($v) > $max) { $errors[$name] = $label . ' is up to ' . $max . ' characters.'; return $keep; }
        return $v;
    };
    $f['legal_name'] = $text('legal_name', $cur['legal_name'] ?? null, 200, 'The legal name');
    $f['email'] = $cur['email'] ?? null;
    if (req_has('email')) {
        $v = (string) req_val('email');
        if ($v === '') { $f['email'] = null; }
        elseif (mb_strlen($v) > 200 || filter_var($v, FILTER_VALIDATE_EMAIL) === false) { $errors['email'] = 'That is not an email address.'; }
        else { $f['email'] = $v; }
    }
    $f['phone'] = $text('phone', $cur['phone'] ?? null, 40, 'The phone');
    $f['phone_alt'] = $text('phone_alt', $cur['phone_alt'] ?? null, 40, 'The second phone');
    $f['billing_address'] = $text('billing_address', $cur['billing_address'] ?? null, 500, 'The billing address');
    $f['shipping_address'] = $text('shipping_address', $cur['shipping_address'] ?? null, 500, 'The shipping address');
    $f['source'] = $cur['source'] ?? 'walk_in';
    if (req_has('source') && (string) req_val('source') !== '') {
        $s = (string) req_val('source');
        if (!isset(CUSTOMER_SOURCES[$s])) { $errors['source'] = 'How they came is walk_in, phone, web, referral or other.'; } else { $f['source'] = $s; }
    }
    $f['tax_rate_id'] = inv_ref($pdo, 'tax_rate', $cur['tax_rate_id'] ?? null, 'SELECT 1 FROM mcp_tax_rates WHERE tax_rate_id = :id AND archived_at IS NULL', 'the tax rate', $errors);
    $f['terms_days'] = inv_int('terms_days', $cur['terms_days'] ?? null, 0, 365, 'The terms', $errors, true);
    $f['tax_id'] = $text('tax_id', $cur['tax_id'] ?? null, 40, 'The tax id');
    $f['email_opt_in'] = inv_yes('email_opt_in', $cur['email_opt_in'] ?? false);
    $f['notes'] = $text('notes', $cur['notes'] ?? null, 2000, 'The notes');
    return $f;
}

/** What a log row may say: names, the source, the opt-in, the terms and rate — never an address, a phone, an email or the notes. */
function customer_loggable(array $c): array
{
    return ['name' => $c['name'], 'source' => $c['source'], 'email_opt_in' => (bool) $c['email_opt_in'], 'terms_days' => $c['terms_days'], 'tax_rate_id' => $c['tax_rate_id']];
}

/** The private fields that changed, by name ("email", "phone") — the words a log row may carry in place of the values. */
function customer_changed_private(array $before, array $after): array
{
    $out = [];
    foreach (['legal_name', 'email', 'phone', 'phone_alt', 'billing_address', 'shipping_address', 'tax_id', 'notes'] as $k) {
        if (($before[$k] ?? null) !== ($after[$k] ?? null)) { $out[] = $k; }
    }
    return $out;
}

function customer_or_404(PDO $pdo, ?int $id): array
{
    $c = $id === null ? null : find_customer($pdo, $id);
    if ($c === null) { refuse(404, 'Customer not found.'); }
    return $c;
}

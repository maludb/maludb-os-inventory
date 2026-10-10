<?php
declare(strict_types=1);

/**
 * The admin's prelude (reports-admin.md): required by every html/admin settings, sequences, tax-rates and reason-codes controller. It loads the queries and presenters, holds the 404s, and reads the request into normalised fields —
 * "a field left out stays as it was" (req_has()); every number through inv_int() with the table's bounds, so a person reads a field's name and never a constraint's. A mistake is a field error in $errors (name => sentence).
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';

function tax_rate_or_404(PDO $pdo, ?int $id): array
{
    $t = $id === null ? null : find_tax_rate($pdo, $id);
    if ($t === null) { refuse(404, 'Tax rate not found.'); }
    return $t;
}

function reason_code_or_404(PDO $pdo, ?int $id): array
{
    $r = $id === null ? null : find_reason_code($pdo, $id);
    if ($r === null) { refuse(404, 'Reason code not found.'); }
    return $r;
}

/** `screen.view` of an admin screen. */
function admin_screen_view(PDO $pdo, string $screen): void
{
    log_screen_view($pdo, $screen);
}

/** A decimal field within bounds with $places decimals, as the normalised string; $keep when left out. */
function inv_decimal(string $name, string $keep, float $min, float $max, int $places, string $label, array &$errors, bool $minExclusive = false): string
{
    if (!req_has($name)) { return $keep; }
    $v = str_replace(',', '.', (string) req_val($name));
    if ($v === '' || !preg_match('/^\d{1,6}(\.\d+)?$/', $v) || (float) $v > $max || (float) $v < $min || ($minExclusive && (float) $v <= $min)) {
        $errors[$name] = $label . ' is a number from ' . ($minExclusive ? 'just over ' : '') . rtrim(rtrim(number_format($min, $places, '.', ''), '0'), '.') . ' to ' . rtrim(rtrim(number_format($max, $places, '.', ''), '0'), '.') . ', ' . $places . ' decimals at most.';
        return $keep;
    }
    if (strlen(substr(strrchr($v, '.') ?: '', 1)) > $places) { $errors[$name] = $label . ' takes ' . $places . ' decimals at most.'; return $keep; }
    return number_format((float) $v, $places, '.', '');
}

/** A text field within a length; empty → null when $nullable; $keep when left out. */
function inv_text(string $name, ?string $keep, int $max, string $label, array &$errors, bool $nullable = true): ?string
{
    if (!req_has($name)) { return $keep; }
    $v = (string) req_val($name);
    if ($v === '') {
        if (!$nullable) { $errors[$name] = $label . ' is required.'; return $keep; }
        return null;
    }
    if (mb_strlen($v) > $max) { $errors[$name] = $label . ' is at most ' . $max . ' characters.'; return $keep; }
    return $v;
}

/** The settings form's fields (only those the request carries), validated; $cur is find_settings(). */
function settings_from_request(PDO $pdo, array $cur, array &$errors): array
{
    $f = [];
    $set = static function (string $k, $v) use (&$f): void { $f[$k] = $v; };
    // the business
    foreach ([['business_name', 120, 'The business name'], ['business_phone', 40, 'The phone'], ['business_address', 500, 'The address']] as [$k, $max, $label]) {
        if (req_has($k)) { $set($k, inv_text($k, $cur[$k], $max, $label, $errors)); }
    }
    if (req_has('business_contact_email')) {
        $v = inv_text('business_contact_email', $cur['business_contact_email'], 254, 'The contact e-mail', $errors);
        if ($v !== null && !isset($errors['business_contact_email']) && filter_var($v, FILTER_VALIDATE_EMAIL) === false) { $errors['business_contact_email'] = 'The contact e-mail is not an address.'; }
        $set('business_contact_email', $v);
    }
    if (req_has('currency')) {
        $v = strtoupper((string) req_val('currency'));
        if (!preg_match('/^[A-Z]{3}$/', $v)) { $errors['currency'] = 'Currency is three letters, like USD.'; } else { $set('currency', $v); }
    }
    if (req_has('units')) {
        $v = (string) req_val('units');
        if (!in_array($v, ['imperial', 'metric'], true)) { $errors['units'] = 'Units are imperial or metric.'; } else { $set('units', $v); }
    }
    if (req_has('timezone')) {
        $v = (string) req_val('timezone');
        if (!in_array($v, DateTimeZone::listIdentifiers(), true)) { $errors['timezone'] = 'That is not a time zone (like America/Chicago).'; } else { $set('timezone', $v); }
    }
    // who sees what, and the doors
    foreach (['sales_sees_cost', 'feed_shows_quantity'] as $k) {
        if (req_has($k)) { $set($k, inv_yes($k, $cur[$k])); }
    }
    if (req_has('supplier_sees_phone')) {
        $list = request_list('supplier_sees_phone') ?? [];
        $kinds = ships_how_kinds($pdo);
        $list = array_values(array_unique(array_map(static fn (string $x): string => trim($x, '{}" '), $list)));
        $list = array_values(array_filter($list, static fn (string $x): bool => $x !== ''));
        $bad = array_diff($list, $kinds);
        if ($bad !== []) { $errors['supplier_sees_phone'] = 'Shipping kinds are ' . implode(', ', $kinds) . '.'; } else { $set('supplier_sees_phone', $list); }
    }
    foreach ([['order_link_days', 1, 3650, 'Order link days'], ['supplier_link_days', 1, 3650, 'Supplier link days'], ['feed_rate_per_minute', 1, 100000, 'The feed rate per minute'], ['feed_rate_per_day', 1, 10000000, 'The feed rate per day'],
              ['key_rotation_overlap_hours', 1, 720, 'The key rotation overlap'], ['reorder_point_default', 0, 1000000, 'The default reorder point'], ['reorder_qty_default', 1, 1000000, 'The default reorder quantity'],
              ['ack_days', 1, 90, 'Days to acknowledge'], ['crawl_max_pages', 1, 10000, 'Crawl pages'], ['schedule_supplier_minutes', 5, 10080, 'The supplier schedule'], ['schedule_reference_minutes', 5, 10080, 'The reference schedule'],
              ['schedule_jsonld_minutes', 60, 10080, 'The JSON-LD schedule'], ['removed_after_pulls', 1, 20, 'Removed after pulls'], ['snapshot_heartbeat_days', 1, 30, 'The snapshot heartbeat'], ['raw_max_bytes', 256, 1048576, 'The raw object size']] as [$k, $lo, $hi, $label]) {
        if (req_has($k)) { $v = inv_int($k, $cur[$k], $lo, $hi, $label, $errors); $set($k, $v); }
    }
    if (req_has('cost_source')) {
        $v = (string) req_val('cost_source');
        if (!in_array($v, ['last_receipt', 'feed', 'manual'], true)) { $errors['cost_source'] = 'Cost source is last_receipt, feed or manual.'; } else { $set('cost_source', $v); }
    }
    if (req_has('cost_move_pct')) { $set('cost_move_pct', inv_decimal('cost_move_pct', $cur['cost_move_pct'], 0, 999.99, 2, 'Cost moved by', $errors)); }
    if (req_has('reference_undercut_pct')) { $set('reference_undercut_pct', inv_decimal('reference_undercut_pct', $cur['reference_undercut_pct'], 0, 999.99, 2, 'Reference undercut by', $errors)); }
    if (req_has('buyer')) {
        $v = (string) req_val('buyer');
        if ($v === '' || $v === '0') { $set('buyer', null); }
        elseif (filter_var($v, FILTER_VALIDATE_INT) === false) { $errors['buyer'] = 'Choose the Buyer from the list.'; }
        else {
            $m = one_row($pdo, "SELECT m.member_kind, m.status, inv_member_roles(m.id) AS roles FROM members m WHERE m.id = :id", ['id' => (int) $v]);
            if ($m === null) { $errors['buyer'] = 'That member is not here.'; }
            elseif ($m['member_kind'] !== 'human') { $errors['buyer'] = 'The morning note goes to a person, not an agent.'; }
            elseif ($m['status'] !== 'active' || pg_text_array((string) $m['roles']) === []) { $errors['buyer'] = 'The Buyer must be an active person who holds a role here.'; }
            else { $set('buyer', (int) $v); }
        }
    }
    // the crawl policy
    if (req_has('crawl_user_agent')) {
        $v = inv_text('crawl_user_agent', $cur['crawl_user_agent'], 300, 'The user-agent', $errors);
        if ($v !== null && !isset($errors['crawl_user_agent']) && !preg_match('/\(\+(https?:\/\/|mailto:)\S+/i', $v)) { $errors['crawl_user_agent'] = 'An honest user-agent names the business and a contact — include (+https://… or (+mailto:…).'; }
        $set('crawl_user_agent', $v);
    }
    if (req_has('crawl_rate_per_second')) { $set('crawl_rate_per_second', inv_decimal('crawl_rate_per_second', $cur['crawl_rate_per_second'], 0.01, 10, 2, 'Requests per second', $errors)); }
    if (req_has('crawl_backoff_minutes')) {
        $list = request_list('crawl_backoff_minutes') ?? [];
        $list = array_map(static fn (string $x): string => trim($x, '{} '), $list);
        $list = array_values(array_filter(array_merge(...array_map(static fn (string $x): array => explode(',', $x), $list ?: ['']))));
        $ok = $list !== [] && count($list) <= 3;
        foreach ($list as $x) { if (filter_var($x, FILTER_VALIDATE_INT) === false || (int) $x < 1 || (int) $x > 43200) { $ok = false; } }
        if (!$ok) { $errors['crawl_backoff_minutes'] = 'The backoff ladder is one to three whole numbers of minutes, each from 1 to 43200.'; }
        else {
            $ints = array_map('intval', $list);
            $sorted = $ints;
            sort($sorted);
            if ($ints !== $sorted || count(array_unique($ints)) !== count($ints)) { $errors['crawl_backoff_minutes'] = 'The backoff ladder must be rising (each step longer than the last).'; } else { $set('crawl_backoff_minutes', $ints); }
        }
    }
    // files: MB in the form (1–1024), bytes in the row. A caller that sends bytes (an agent filling a partial update from the row) is understood: a value of a megabyte or more is bytes.
    if (req_has('max_attachment_bytes')) {
        $v = (string) req_val('max_attachment_bytes');
        if (!preg_match('/^\d+(\.\d+)?$/', $v)) { $errors['max_attachment_bytes'] = 'The largest attachment is a number of megabytes from 1 to 1024.'; }
        else {
            $bytes = (float) $v >= 1048576 ? (int) round((float) $v) : (int) round((float) $v * 1048576);
            if ($bytes < 1048576 || $bytes > 1073741824) { $errors['max_attachment_bytes'] = 'The largest attachment is a number of megabytes from 1 to 1024.'; } else { $set('max_attachment_bytes', $bytes); }
        }
    }
    // the vocabulary
    if (req_has('sizes')) {
        $v = settings_sizes_from_json((string) req_val('sizes'), $errors);
        if ($v !== null) {
            $inUse = size_keys_in_use($pdo);
            $keys = array_column($v, 'key');
            foreach (array_column($cur['sizes'], 'key') as $old) {
                if (!in_array($old, $keys, true) && ($inUse[$old] ?? 0) > 0) {
                    $n = $inUse[$old];
                    $errors['sizes'] = "Size '" . $old . "' is used by " . $n . ' variant' . ($n === 1 ? '' : 's') . ' — rename it, do not remove it.';
                }
            }
            if (!isset($errors['sizes'])) { $set('sizes', $v); }
        }
    }
    if (req_has('attribute_keys')) {
        $v = settings_attributes_from_json((string) req_val('attribute_keys'), $errors);
        if ($v !== null) { $set('attribute_keys', $v); }
    }
    return $f;
}

/** The sizes textarea: [{key, name, synonyms[]}], keys ^[a-z][a-z0-9_]*$ and unique, names required, synonyms lower-cased and unique across every size. Null (and an error on `sizes`) when it is not. */
function settings_sizes_from_json(string $text, array &$errors): ?array
{
    $data = json_decode($text, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data) || !array_is_list($data)) {
        $errors['sizes'] = 'The sizes are a JSON list of {key, name, synonyms} — ' . (json_last_error() !== JSON_ERROR_NONE ? json_last_error_msg() : 'a list is expected') . '.';
        return null;
    }
    $out = [];
    $keys = [];
    $syn = [];
    foreach ($data as $i => $s) {
        $n = $i + 1;
        if (!is_array($s)) { $errors['sizes'] = 'Size ' . $n . ' is not an object {key, name, synonyms}.'; return null; }
        $key = (string) ($s['key'] ?? '');
        $name = trim((string) ($s['name'] ?? ''));
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $key)) { $errors['sizes'] = 'Size ' . $n . ': the key is lower-case letters, digits and "_", starting with a letter.'; return null; }
        if (isset($keys[$key])) { $errors['sizes'] = "Size key '" . $key . "' appears twice."; return null; }
        if ($name === '' || mb_strlen($name) > 80) { $errors['sizes'] = "Size '" . $key . "' needs a name (80 characters at most)."; return null; }
        $keys[$key] = true;
        $list = [];
        foreach ((array) ($s['synonyms'] ?? []) as $sy) {
            if (!is_scalar($sy)) { $errors['sizes'] = "Size '" . $key . "': a synonym is text."; return null; }
            $w = mb_strtolower(trim((string) preg_replace('/\s+/', ' ', (string) $sy)));
            if ($w === '') { continue; }
            if (isset($syn[$w]) && $syn[$w] !== $key) { $errors['sizes'] = "The synonym '" . $w . "' belongs to both '" . $syn[$w] . "' and '" . $key . "'."; return null; }
            $syn[$w] = $key;
            if (!in_array($w, $list, true)) { $list[] = $w; }
        }
        $out[] = ['key' => $key, 'name' => $name, 'synonyms' => $list];
    }
    return $out;
}

/** The attribute keys textarea: [{key, name, kind (choice · number · text · multi), choices[]?}], keys unique, choices required for choice and multi. */
function settings_attributes_from_json(string $text, array &$errors): ?array
{
    $data = json_decode($text, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data) || !array_is_list($data)) {
        $errors['attribute_keys'] = 'The attribute keys are a JSON list of {key, name, kind, choices} — ' . (json_last_error() !== JSON_ERROR_NONE ? json_last_error_msg() : 'a list is expected') . '.';
        return null;
    }
    $out = [];
    $keys = [];
    foreach ($data as $i => $a) {
        $n = $i + 1;
        if (!is_array($a)) { $errors['attribute_keys'] = 'Attribute ' . $n . ' is not an object {key, name, kind}.'; return null; }
        $key = (string) ($a['key'] ?? '');
        $name = trim((string) ($a['name'] ?? ''));
        $kind = (string) ($a['kind'] ?? '');
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $key)) { $errors['attribute_keys'] = 'Attribute ' . $n . ': the key is lower-case letters, digits and "_", starting with a letter.'; return null; }
        if (isset($keys[$key])) { $errors['attribute_keys'] = "Attribute key '" . $key . "' appears twice."; return null; }
        if ($name === '' || mb_strlen($name) > 80) { $errors['attribute_keys'] = "Attribute '" . $key . "' needs a name (80 characters at most)."; return null; }
        if (!in_array($kind, ['choice', 'number', 'text', 'multi'], true)) { $errors['attribute_keys'] = "Attribute '" . $key . "': the kind is choice, number, text or multi."; return null; }
        $keys[$key] = true;
        $o = ['key' => $key, 'name' => $name, 'kind' => $kind];
        if (in_array($kind, ['choice', 'multi'], true)) {
            $choices = array_values(array_unique(array_filter(array_map(static fn ($c): string => trim((string) $c), (array) ($a['choices'] ?? [])), static fn (string $c): bool => $c !== '')));
            if ($choices === []) { $errors['attribute_keys'] = "Attribute '" . $key . "' is a " . $kind . ' and needs its choices.'; return null; }
            $o['choices'] = $choices;
        }
        $out[] = $o;
    }
    return $out;
}

/** The settings change as the log holds it: before/after of the changed fields only; the user-agent as "changed"; sizes and attribute keys as a count and the keys added or removed. */
function settings_loggable(array $res): array
{
    $before = $res['before'];
    $after = $res['after'];
    foreach (['sizes', 'attribute_keys'] as $k) {
        if (!array_key_exists($k, $after)) { continue; }
        $old = array_column($before[$k] ?? [], 'key');
        $new = array_column($after[$k], 'key');
        $before[$k] = ['count' => count($old)];
        $after[$k] = ['count' => count($new), 'added' => array_values(array_diff($new, $old)), 'removed' => array_values(array_diff($old, $new))];
    }
    if (array_key_exists('crawl_user_agent', $after)) { $before['crawl_user_agent'] = 'changed'; $after['crawl_user_agent'] = 'changed'; }
    return ['before' => $before, 'after' => $after + ['changed' => $res['changed']]];
}

/** The group a changed field lives in (the landing's anchor). */
function settings_group_of(string $column): string
{
    foreach (settings_groups() as $g => [, , $cols]) { if (in_array($column, $cols, true)) { return $g; } }
    return 'business';
}

// ---- sequences, tax rates, reason codes: the request -------------------------------------------------------------------------------------------

/** The sequence form: prefix (^[A-Z][A-Z0-9]{0,7}-?$), next_value (a whole number ≥ 1), padding (1–12); $cur is the sequence's row. */
function sequence_from_request(array $cur, array &$errors): array
{
    $prefix = req_has('prefix') ? (string) req_val('prefix') : (string) $cur['prefix'];
    if (!preg_match('/^[A-Z][A-Z0-9]{0,7}-?$/', $prefix)) { $errors['prefix'] = 'A prefix is one to eight capital letters or digits starting with a letter, and may end in a dash (SO-).'; }
    $next = inv_int('next_value', (int) $cur['next_value'], 1, 999999999999, 'The next value', $errors);
    if (!isset($errors['next_value']) && $next < (int) $cur['next_value']) { $errors['next_value'] = 'The next value cannot go below ' . (int) $cur['next_value'] . ' — a number is never reused.'; }
    $padding = inv_int('padding', (int) $cur['padding'], 1, 12, 'Padding', $errors);
    return ['prefix' => $prefix, 'next_value' => $next, 'padding' => $padding];
}

/** The tax-rate form: name (1–80, required), rate (0–99.9999, four decimals), is_default. $cur is the existing row (or null). */
function tax_rate_from_request(?array $cur, array &$errors): array
{
    $name = inv_text('name', $cur['name'] ?? null, 80, 'The name', $errors, false);
    if (!req_has('name') && $cur === null) { $errors['name'] = 'The name is required.'; }
    $rate = inv_decimal('rate', $cur['rate'] ?? '0.0000', 0, 99.9999, 4, 'The rate', $errors);
    if ($cur === null && !req_has('rate')) { $errors['rate'] = 'The rate is required.'; }
    if (!isset($errors['rate']) && (float) $rate >= 100) { $errors['rate'] = 'The rate is a percent under 100.'; }
    $default = inv_yes('is_default', $cur['is_default'] ?? false);
    return ['name' => $name, 'rate' => $rate, 'is_default' => $default];
}

/** The reason-code form: name (1–80, required), code (new only: ^[a-z][a-z0-9_]{0,39}$, from the name when blank), applies_to[] ⊆ adjustment · return · transaction (at least one), affects_qty, sort_order (0–999), active. */
function reason_code_from_request(?array $cur, array &$errors, array &$notes): array
{
    $name = inv_text('name', $cur['name'] ?? null, 80, 'The name', $errors, false);
    if (!req_has('name') && $cur === null) { $errors['name'] = 'The name is required.'; }
    $code = $cur['code'] ?? '';
    if ($cur === null) {
        $posted = req_has('code') ? strtolower((string) req_val('code')) : '';
        $code = $posted !== '' ? $posted : code_from_name((string) $name);
        if ($code === '' || !preg_match('/^[a-z][a-z0-9_]{0,39}$/', $code)) { $errors['code'] = 'A code is lower-case letters, digits and "_", starting with a letter (40 at most).'; }
    } elseif (req_has('code') && strtolower((string) req_val('code')) !== $cur['code'] && (string) req_val('code') !== '') {
        $notes[] = 'the code stays ' . $cur['code'];
    }
    $applies = $cur['applies_to'] ?? ['adjustment'];
    if (req_has('applies_to')) {
        $applies = array_values(array_unique(array_filter(array_map(static fn (string $x): string => trim($x, '{}" '), (array) (request_list('applies_to') ?? [])), static fn (string $x): bool => $x !== '')));
        if ($applies === [] || array_diff($applies, ['adjustment', 'return', 'transaction']) !== []) { $errors['applies_to'] = 'Choose where it applies: adjustment, return (at least one).'; }
    }
    $affects = inv_yes('affects_qty', $cur['affects_qty'] ?? true);
    $sort = inv_int('sort_order', $cur['sort_order'] ?? 0, 0, 999, 'The sort order', $errors);
    $active = inv_yes('active', $cur['active'] ?? true);
    return ['name' => $name, 'code' => $code, 'applies_to' => $applies, 'affects_qty' => $affects, 'sort_order' => $sort, 'active' => $active];
}

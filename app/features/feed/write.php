<?php
declare(strict_types=1);

/**
 * The feed's writes, request side (feed.md "The keys"): the readers that turn a form (or an agent's call) into mint_feed_key()'s and save_price_list()'s fields with a field error per mistake, the copy-once box
 * (a raw key is held in the session for ONE render — never in a URL, a log row, a notice, an HTMX header or the JSON of any read; JSON mode gets it once in the action's own reply) and the loggable shapes.
 */

/** The fields of mint_feed_key() from the request; field errors collect in $errors. */
function feed_key_from_request(PDO $pdo, array &$errors): array
{
    $f = [];
    $f['label'] = (string) (req_val('label') ?? '');
    if ($f['label'] === '' || mb_strlen($f['label']) > 80) { $errors['label'] = 'A label is required (up to 80 characters) — what will use this key.'; }
    $f['consumer_kind'] = 'website';
    if (req_has('consumer_kind') && (string) req_val('consumer_kind') !== '') {
        $k = (string) req_val('consumer_kind');
        if (!isset(FEED_CONSUMER_KINDS[$k])) { $errors['consumer_kind'] = 'The key is for a website, another installation or a partner store.'; } else { $f['consumer_kind'] = $k; }
    }
    $f['price_list_id'] = inv_ref($pdo, 'price_list', null, 'SELECT 1 FROM mcp_price_lists WHERE price_list_id = :id', 'price list', $errors);
    if ($f['price_list_id'] !== null && !isset($errors['price_list'])) {
        if ($f['consumer_kind'] !== 'partner') {
            $errors['price_list'] = "A price list goes on a partner's key.";
        } elseif (!db_bool($pdo, 'SELECT active FROM mcp_price_lists WHERE price_list_id = :id', ['id' => $f['price_list_id']])) {
            $errors['price_list'] = 'That price list is inactive.';
        }
    }
    if ($f['consumer_kind'] === 'partner' && $f['price_list_id'] === null && !isset($errors['price_list'])) {
        $errors['price_list'] = "A partner's key names the price list it answers with.";
    }
    $f['rate_per_minute'] = inv_int('rate_per_minute', null, 1, 100000, 'The calls per minute', $errors, true);
    $f['rate_per_day'] = inv_int('rate_per_day', null, 1, 10000000, 'The calls per day', $errors, true);
    $f['expires_on'] = null;
    if (req_has('expires_at') && (string) req_val('expires_at') !== '') {
        $d = (string) req_val('expires_at');
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
        if ($dt === false || $dt->format('Y-m-d') !== $d) {
            $errors['expires_at'] = 'The expiry is a date (year-month-day).';
        } else {
            $today = (string) one_value($pdo, 'SELECT (now() AT TIME ZONE timezone)::date FROM inv_settings WHERE id = 1');
            if ($d <= $today) { $errors['expires_at'] = 'The expiry is a date from tomorrow on.'; } else { $f['expires_on'] = $d; }
        }
    }
    return $f;
}

/** The fields of save_price_list() from the request; $cur is the BASE row (an update) or null. */
function price_list_from_request(?array $cur, array &$errors): array
{
    $f = [];
    $f['name'] = req_has('name') ? (string) req_val('name') : (string) ($cur['name'] ?? '');
    if ($f['name'] === '' || mb_strlen($f['name']) > 80) { $errors['name'] = 'Give the price list a name of up to 80 characters.'; }
    $f['percent_off_retail'] = (string) ($cur['percent_off_retail'] ?? '0.00');
    $pct = req_has('percent_off_retail') ? (string) req_val('percent_off_retail') : ($cur === null ? '' : $f['percent_off_retail']);
    if ($pct === '') {
        $errors['percent_off_retail'] = 'The percent off retail is required (0 to 99.99).';
    } elseif (preg_match('/^\d+(\.\d+)?$/', $pct) !== 1) {
        $errors['percent_off_retail'] = 'The percent off retail is a number from 0 to 99.99.';
    } elseif (preg_match('/^\d+\.\d{3,}$/', $pct) === 1 && (float) $pct != round((float) $pct, 2)) {
        $errors['percent_off_retail'] = 'The percent off retail has two decimals at most.';
    } elseif ((float) $pct < 0 || (float) $pct > 99.99) {
        $errors['percent_off_retail'] = 'The percent off retail is from 0 to 99.99 — 100 would give the goods away.';
    } else {
        $f['percent_off_retail'] = number_format((float) $pct, 2, '.', '');
    }
    $f['notes'] = $cur['notes'] ?? null;
    if (req_has('notes')) {
        $n = (string) req_val('notes');
        if (mb_strlen($n) > 2000) { $errors['notes'] = 'The notes are up to 2,000 characters.'; } else { $f['notes'] = $n === '' ? null : $n; }
    }
    $f['active'] = inv_yes('active', $cur === null ? true : (bool) $cur['active']);
    return $f;
}

/** The copy-once value: held for the next render of the keys page only. */
function feed_key_stash(int $keyId, string $raw, ?string $oldExpiresAt = null): void
{
    $_SESSION['minted_feed_key'] = ['key_id' => $keyId, 'raw' => $raw, 'old_expires_at' => $oldExpiresAt];
}

/** …and taken (so a reload never shows it again). */
function feed_key_unstash(): ?array
{
    $v = $_SESSION['minted_feed_key'] ?? null;
    unset($_SESSION['minted_feed_key']);
    return is_array($v) ? $v : null;
}

/** What a log row may say of a key: its label and facts — never the key or its hash. */
function feed_key_loggable(array $k): array
{
    return ['label' => $k['label'], 'consumer_kind' => $k['consumer_kind'], 'price_list_id' => $k['price_list_id'], 'rate_per_minute' => $k['rate_per_minute'], 'rate_per_day' => $k['rate_per_day'], 'expires_at' => $k['expires_at']];
}

function price_list_loggable(array $p): array
{
    return ['name' => $p['name'], 'percent_off_retail' => $p['percent_off_retail'], 'active' => (bool) $p['active']];
}

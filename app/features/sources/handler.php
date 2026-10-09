<?php
declare(strict_types=1);

/**
 * Sources' prelude (sources.md "Handlers"): the readers of the source form (common fields + the chosen connector's sub-form, or an agent's
 * JSON `settings` filtered to the connector's keys — an unknown key refused in words), the honest user-agent rule, the feed mapping checked
 * against the file, source_log() — `source_id` on every row.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__) . '/catalog/handler.php';
require_once dirname(__DIR__, 2) . '/attachments.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/write.php';

function source_write_begin(string $right = 'sources.write'): void
{
    inv_handler_begin();
    require_right($right);
}

/** log_activity() with source_id. */
function source_log(PDO $pdo, string $action, string $entityType, int $entityId, int $sourceId, array $after, array $opts = []): void
{
    log_activity($pdo, $action, $entityType, $entityId, ['source_id' => $sourceId, 'after' => $after] + $opts);
}

function source_or_404(PDO $pdo, ?int $id): array
{
    $s = $id === null ? null : find_source($pdo, $id);
    if ($s === null) { refuse(404, 'Source not found.'); }
    return $s;
}

/** The raw settings of a source (the handler's own read; the view nulls them below sources.write, which every writer holds). */
function source_settings_raw(PDO $pdo, int $id): array
{
    return json_decode((string) one_value($pdo, 'SELECT settings FROM sources WHERE id = :id', ['id' => $id]), true) ?: [];
}

/** An honest user-agent names the business and a contact: "(+" then a URL or a mailto:. */
function honest_user_agent(string $ua): bool
{
    return preg_match('~\(\+\s*(https?://|mailto:)~i', $ua) === 1;
}

/** Lines of a textarea → a list (trimmed, empties dropped). */
function lines_list(?string $text): array
{
    return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $text) ?: []), static fn (string $s): bool => $s !== ''));
}

/**
 * The source form's fields. $cur the row when changing (a field left out stays). Returns the fields for save_source(); $errors by field.
 */
function source_from_request(PDO $pdo, ?array $cur, array &$errors): array
{
    $f = [];
    $f['connector'] = $cur['connector'] ?? (string) (req_val('connector') ?? '');
    if ($cur === null && !isset(inv_connectors()[$f['connector']])) { $errors['connector'] = 'Choose a connector: ' . implode(', ', array_keys(inv_connectors())) . '.'; }
    if ($cur !== null && req_has('connector') && (string) req_val('connector') !== '' && req_val('connector') !== $cur['connector']) {
        $errors['connector'] = 'A source keeps its connector — make a new source for another.';
    }
    $f['name'] = req_has('name') ? trim((string) req_val('name')) : (string) ($cur['name'] ?? '');
    if ($f['name'] === '' || mb_strlen($f['name']) > 120) { $errors['name'] = 'Give the source a name of up to 120 characters.'; }
    $f['role'] = $cur['role'] ?? 'reference';
    if (req_has('role') && (string) req_val('role') !== '') {
        if (!isset(SOURCE_ROLES[(string) req_val('role')])) { $errors['role'] = 'The role is supplier or reference.'; } else { $f['role'] = (string) req_val('role'); }
    }
    $f['supplier_id'] = inv_ref($pdo, 'supplier', $cur['supplier_id'] ?? null, 'SELECT 1 FROM mcp_suppliers WHERE supplier_id = :id', 'the supplier', $errors);
    $f['base_url'] = $cur['base_url'] ?? null;
    if (req_has('base_url')) {
        $u = trim((string) req_val('base_url'));
        $f['base_url'] = $u === '' ? null : rtrim($u, '/');
    }
    if (in_array($f['connector'], ['feed', 'manual'], true)) {
        $f['base_url'] = $f['base_url'] === null ? null : $f['base_url'];          // kept as a note; the connector ignores it
    } elseif ($f['base_url'] === null) {
        if (!isset($errors['connector'])) { $errors['base_url'] = 'Give the store\'s address (https://…).'; }
    } elseif (!preg_match('~^https?://[^/\s]+~i', $f['base_url']) || filter_var($f['base_url'], FILTER_VALIDATE_URL) === false) {
        $errors['base_url'] = 'The address is an absolute http(s) URL.';
    }
    $f['schedule_minutes'] = $cur['schedule_minutes'] ?? null;
    if (req_has('schedule_minutes')) {
        $v = trim((string) req_val('schedule_minutes'));
        if ($v === '') { $f['schedule_minutes'] = null; }
        elseif (filter_var($v, FILTER_VALIDATE_INT) === false || (int) $v < 0 || (int) $v > 525600) { $errors['schedule_minutes'] = 'The schedule is a number of minutes (0 = manual), or blank for the default.'; }
        else { $f['schedule_minutes'] = (int) $v; }
    }
    $f['rate_per_second'] = $cur['rate_per_second'] ?? null;
    if (req_has('rate_per_second') && trim((string) req_val('rate_per_second')) !== '') {
        $v = (string) req_val('rate_per_second');
        if (!is_numeric($v) || (float) $v < 0.1 || (float) $v > 10) { $errors['rate_per_second'] = 'The rate is between 0.1 and 10 requests a second.'; } else { $f['rate_per_second'] = round((float) $v, 2); }
    }
    $f['user_agent'] = $cur['user_agent'] ?? null;
    if (req_has('user_agent')) {
        $v = trim((string) req_val('user_agent'));
        if ($v === '') { $f['user_agent'] = null; }
        elseif (mb_strlen($v) > 200 || !honest_user_agent($v)) { $errors['user_agent'] = 'An honest user-agent names the business and a contact — "Name/1.0 (+https://… or +mailto:…)".'; }
        else { $f['user_agent'] = $v; }
    }
    $f['active'] = inv_yes('active', $cur['active'] ?? true);
    return $f;
}

/**
 * The settings: an agent's JSON `settings` (filtered to the connector's keys; an unknown key refused in words; merged onto the current on a
 * change), or the form's `settings_<connector>[…]` sub-form, or (neither) the current settings. $errors by field.
 */
function source_settings_from_request(string $connector, array $current, array &$errors): array
{
    $keys = connector_settings_keys($connector);
    if (req_has('settings') && (string) req_val('settings') !== '' && !isset($_POST['settings_' . $connector])) {
        $raw = $_POST['settings'];
        $in = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (!is_array($in) || ($in !== [] && array_is_list($in))) { $errors['settings'] = 'settings is a JSON object.'; return $current; }
        $unknown = array_diff(array_keys($in), $keys);
        if ($unknown !== []) {
            $errors['settings'] = 'The ' . $connector . ' connector does not read ' . implode(', ', $unknown) . ' — its settings are ' . implode(', ', $keys) . '.';
            return $current;
        }
        $out = array_merge($current, $in);
        source_settings_check($connector, $out, $errors);
        return array_filter($out, static fn ($v) => $v !== null && $v !== '' && $v !== []);
    }
    $form = $_POST['settings_' . $connector] ?? null;
    if (!is_array($form)) { return $current; }
    $g = static fn (string $k): string => trim((string) ($form[$k] ?? ''));
    $out = [];
    switch ($connector) {
        case 'shopify':
            $out['collections'] = array_values(array_filter(array_map(static fn ($h) => strtolower((string) preg_replace('~[^a-z0-9\-_]~i', '', $h)), lines_list($form['collections'] ?? ''))));
            $out['currency'] = strtoupper($g('currency'));
            $out['api_version'] = $g('api_version');
            $out['max_products'] = $g('max_products') === '' ? null : (int) $g('max_products');
            break;
        case 'woocommerce':
            $out['currency'] = strtoupper($g('currency'));
            $out['vendor'] = $g('vendor');
            $out['max_products'] = $g('max_products') === '' ? null : (int) $g('max_products');
            break;
        case 'jsonld':
            $out['sitemap_url'] = $g('sitemap_url');
            $out['urls'] = lines_list($form['urls'] ?? '');
            $out['url_pattern'] = $g('url_pattern');
            $out['max_pages'] = $g('max_pages') === '' ? null : (int) $g('max_pages');
            $out['currency'] = strtoupper($g('currency'));
            break;
        case 'feed':
            if (($form['transport'] ?? 'https') === 'sftp') {
                $out['sftp'] = array_filter(['host' => $g('sftp_host'), 'port' => $g('sftp_port') === '' ? 22 : (int) $g('sftp_port'), 'user' => $g('sftp_user'), 'path' => $g('sftp_path')], static fn ($v) => $v !== '');
            } else {
                $out['url'] = $g('url');
            }
            foreach (['format', 'delimiter', 'encoding'] as $k) { if ($g($k) !== '' && $g($k) !== 'auto') { $out[$k] = $g($k); } }
            $out['has_header'] = ($form['has_header'] ?? '') === 'no' ? false : true;
            if ($g('skip_rows') !== '' && (int) $g('skip_rows') > 0) { $out['skip_rows'] = (int) $g('skip_rows'); }
            $out['currency'] = strtoupper($g('currency'));
            $out['vendor'] = $g('vendor');
            if ($g('lead_time_days') !== '') { $out['lead_time_days'] = (int) $g('lead_time_days'); }
            $map = [];
            foreach (InvConnectorFeed::FIELDS as $field) {
                $v = trim((string) ($form['mapping'][$field] ?? ''));
                if ($v !== '') { $map[$field] = $v; }
            }
            if ($map !== []) { $out['mapping'] = $map; } elseif (isset($current['mapping'])) { $out['mapping'] = $current['mapping']; }
            break;
        case 'manual':
            $rows = [];
            foreach ((array) ($form['listings'] ?? []) as $r) {
                if (!is_array($r)) { continue; }
                $r = array_map(static fn ($v) => is_string($v) ? trim($v) : $v, $r);
                if (($r['title'] ?? '') === '' && ($r['sku'] ?? '') === '' && ($r['gtin'] ?? '') === '') { continue; }
                $entry = array_filter(['title' => $r['title'] ?? null, 'brand' => $r['brand'] ?? null, 'product_type' => $r['product_type'] ?? null, 'sku' => $r['sku'] ?? null, 'gtin' => $r['gtin'] ?? null,
                    'mpn' => $r['mpn'] ?? null, 'size' => $r['size'] ?? null, 'price' => $r['price'] ?? null, 'compare_at_price' => $r['compare_at_price'] ?? null, 'cost' => $r['cost'] ?? null,
                    'qty' => ($r['qty'] ?? '') === '' ? null : (int) $r['qty'], 'availability' => $r['availability'] ?? null, 'lead_time_days' => ($r['lead_time_days'] ?? '') === '' ? null : (int) $r['lead_time_days'],
                    'ships_how' => $r['ships_how'] ?? null, 'url' => $r['url'] ?? null, 'currency' => $r['currency'] ?? null, 'recorded_on' => ($r['recorded_on'] ?? '') ?: date('Y-m-d'),
                    'note' => $r['note'] ?? null, 'recorded_by' => (string) (current_member()['display_name'] ?? '')], static fn ($v) => $v !== null && $v !== '');
                $rows[] = $entry;
            }
            $out['listings'] = $rows;
            break;
    }
    source_settings_check($connector, $out, $errors);
    if ($connector === 'manual') { return ['listings' => $out['listings'] ?? []]; }
    return array_filter($out, static fn ($v) => $v !== null && $v !== '' && $v !== []);
}

/** The checks the form owes the settings: a compiling url_pattern, an https feed URL (127.0.0.1 / localhost in dev), a sane currency. */
function source_settings_check(string $connector, array $s, array &$errors): void
{
    if (!empty($s['url_pattern']) && @preg_match('~' . str_replace('~', '\~', (string) $s['url_pattern']) . '~i', '') === false) {
        $errors['settings_' . $connector . '.url_pattern'] = 'The URL pattern is not a regular expression that compiles.';
    }
    if ($connector === 'feed' && !empty($s['url'])) {
        $u = (string) $s['url'];
        if (!preg_match('~^https://~i', $u) && !preg_match('~^http://(127\.0\.0\.1|localhost)[:/]~i', $u)) { $errors['settings_feed.url'] = 'A supplier file is read over https only.'; }
    }
    if (!empty($s['currency']) && !preg_match('/^[A-Z]{3}$/', (string) $s['currency'])) { $errors['settings_' . $connector . '.currency'] = 'The currency is three letters (USD).'; }
}

/** A feed's mapping checked against the file (the preview's `missing`) — skipped when the file cannot be read (the probe says why). Field errors by mapping field. */
function feed_mapping_errors(array $settings, ?int $sourceId, PDO $pdo): array
{
    if (empty($settings['mapping']) || (empty($settings['url']) && empty($settings['sftp']))) { return []; }
    $source = ['connector' => 'feed', 'settings' => $settings, 'credential' => null, 'rate_per_second' => 1, 'user_agent' => null, 'timeout' => 60, 'cache_dir' => inv_pull_cache_dir()];
    if ($sourceId !== null) {
        try { $source['credential'] = inv_source_for_connector($pdo, $sourceId)['credential']; } catch (Throwable) { }
    }
    $p = (new InvConnectorFeed())->preview($source, 5);
    if (!$p['ok'] || empty($p['missing'])) { return []; }
    $out = [];
    foreach ($p['missing'] as $m) {
        $field = trim((string) strtok((string) $m, ' '));
        $out['feed-mapping-' . $field] = 'The file has no such column: ' . $m;
    }
    return $out;
}

/** The fields to log of a source (never a mapping's rows, a URL list or a credential). */
function source_loggable(array $f, array $settings): array
{
    return ['name' => $f['name'], 'connector' => $f['connector'], 'role' => $f['role'], 'supplier_id' => $f['supplier_id'], 'base_url' => $f['base_url'],
            'schedule_minutes' => $f['schedule_minutes'], 'rate_per_second' => $f['rate_per_second'], 'user_agent_set' => $f['user_agent'] !== null, 'active' => $f['active'] ?? true,
            'settings_keys' => array_keys($settings)];
}

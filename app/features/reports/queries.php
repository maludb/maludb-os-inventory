<?php
declare(strict_types=1);

/**
 * The reports (reports-admin.md "The reports"): each is one SQL function's rows (db/014, db/016) read AS THE CALLER — the cost wall (`inv_sees_cost()`) stands in every report and every CSV: a column that carries cost comes back null and the
 * screen says "cost withheld". PHP validates the filters, calls the function, totals what a total means, and words nothing the function did not say. Nothing here writes.
 */

require_once dirname(__DIR__) . '/buyer/queries.php';

const REPORT_ROW_LIMIT = 500;               // an HTML or JSON answer; a CSV takes up to REPORT_CSV_LIMIT
const REPORT_CSV_LIMIT = 50000;

/**
 * The seven reports: title, one line on what it answers, who may read it, the filter fields, the groupings, the default grouping, the function, and the columns [key, heading, kind, cost?].
 * Kinds: text, int, money, pct, num1, date, ts, chip (a health or a kind), yesno.
 */
function report_specs(): array
{
    $salesColumns = static fn (bool $costFirst): array => $costFirst
        ? [['group_name', 'Group', 'text', false], ['cogs', 'COGS', 'money', true], ['margin_pct', 'Margin %', 'pct', true], ['revenue', 'Revenue', 'money', false], ['units_sold', 'Units', 'int', false],
           ['orders', 'Orders', 'int', false], ['discount', 'Discount', 'money', false], ['tax', 'Tax', 'money', false]]
        : [['group_name', 'Group', 'text', false], ['orders', 'Orders', 'int', false], ['units_sold', 'Units', 'int', false], ['revenue', 'Revenue', 'money', false], ['discount', 'Discount', 'money', false],
           ['tax', 'Tax', 'money', false], ['cogs', 'COGS', 'money', true], ['margin_pct', 'Margin %', 'pct', true]];
    return [
        'stock-value' => ['title' => 'Stock value', 'blurb' => 'What the stock on hand is worth at cost, as of a moment, by location, brand, type or variant.', 'who' => 'reports.read — the value needs the cost wall',
            'fields' => ['as_of', 'by'], 'bys' => ['location', 'brand', 'type', 'variant'], 'default_by' => 'location', 'cost' => true,
            'columns' => [['group_name', 'Group', 'text', false], ['units', 'Units', 'int', false], ['value', 'Value', 'money', true]]],
        'sell-through' => ['title' => 'Sell-through and cover', 'blurb' => 'What shipped in the last days, by variant, brand, type or salesperson — with the weeks of cover the stock on hand gives.', 'who' => 'reports.read — COGS and margin need the cost wall',
            'fields' => ['days', 'by'], 'bys' => ['variant', 'brand', 'type', 'salesperson'], 'default_by' => 'variant', 'cost' => true,
            'columns' => [['group_name', 'Group', 'text', false], ['units_sold', 'Units sold', 'int', false], ['revenue', 'Revenue', 'money', false], ['cogs', 'COGS', 'money', true], ['margin_pct', 'Margin %', 'pct', true],
                          ['units_on_hand', 'On hand', 'int', false], ['weeks_of_cover', 'Weeks of cover', 'num1', false]]],
        'sales' => ['title' => 'Sales summary', 'blurb' => 'The confirmed orders\' revenue, discount, tax and units by day, week, month, brand, type, salesperson, variant or location.', 'who' => 'reports.read — COGS and margin need the cost wall',
            'fields' => ['from', 'to', 'by'], 'bys' => ['day', 'week', 'month', 'brand', 'type', 'salesperson', 'variant', 'location'], 'default_by' => 'brand', 'cost' => true, 'columns' => $salesColumns(false)],
        'margin' => ['title' => 'Margin', 'blurb' => 'The sales summary with cost first: COGS and margin by brand (or any grouping).', 'who' => 'reports.read — "cost withheld" below the cost wall',
            'fields' => ['from', 'to', 'by'], 'bys' => ['day', 'week', 'month', 'brand', 'type', 'salesperson', 'variant', 'location'], 'default_by' => 'brand', 'cost' => true, 'columns' => $salesColumns(true), 'function' => 'sales'],
        'price-exceptions' => ['title' => 'Price exceptions', 'blurb' => 'Retail under MAP, a reference store undercutting us, a cost that moved.', 'who' => 'reports.read — cost needs the cost wall',
            'fields' => ['kind', 'brand'], 'bys' => [], 'default_by' => '', 'cost' => true,
            'columns' => [['kind', 'Kind', 'chip', false], ['sku', 'SKU', 'text', false], ['product_name', 'Product', 'text', false], ['size_name', 'Size', 'text', false], ['retail_price', 'Retail', 'money', false], ['map_price', 'MAP', 'money', false],
                          ['cost_price', 'Cost', 'money', true], ['reference_price', 'Reference price', 'money', false], ['source_name', 'Source', 'text', false], ['pct', '%', 'num1', false], ['detail', 'Detail', 'text', false]]],
        'source-health' => ['title' => 'Source health and reliability', 'blurb' => 'Every source\'s health and how reliably it pulled over the last days.', 'who' => 'reports.read',
            'fields' => ['health', 'role', 'days'], 'bys' => [], 'default_by' => '', 'cost' => false,
            'columns' => [['name', 'Source', 'text', false], ['connector', 'Connector', 'text', false], ['role', 'Role', 'text', false], ['supplier_name', 'Supplier', 'text', false], ['health', 'Health', 'chip', false], ['last_pull_at', 'Last pull', 'ts', false],
                          ['last_ok_at', 'Last ok', 'ts', false], ['consecutive_failures', 'Failures in a row', 'int', false], ['next_due_at', 'Next due', 'ts', false], ['listings_live', 'Listings live', 'int', false],
                          ['variants_unmatched', 'Unmatched', 'int', false], ['pulls', 'Pulls', 'int', false], ['success_pct', 'Success %', 'num1', false], ['last_error', 'Last error', 'text', false]]],
        'lead-times' => ['title' => 'Lead-time actuals', 'blurb' => 'What each supplier promised and what it did: average days, drift and on-time share.', 'who' => 'reports.read',
            'fields' => ['supplier'], 'bys' => [], 'default_by' => '', 'cost' => false,
            'columns' => [['supplier_name', 'Supplier', 'text', false], ['lines', 'Lines', 'int', false], ['promised_avg_days', 'Promised (days)', 'num1', false], ['actual_avg_days', 'Actual (days)', 'num1', false], ['drift_days', 'Drift (days)', 'num1', false], ['on_time_pct', 'On time %', 'num1', false]]],
    ];
}

function report_spec(string $report): ?array
{
    return report_specs()[$report] ?? null;
}

const REPORT_HEALTHS = ['ok', 'stale', 'failing', 'blocked', 'paused', 'manual', 'never_pulled', 'inactive'];
const REPORT_PRICE_KINDS = ['retail_under_map', 'reference_undercut', 'cost_moved'];

/** The business's time zone. */
function business_tz(PDO $pdo): DateTimeZone
{
    try { return new DateTimeZone((string) (one_value($pdo, 'SELECT timezone FROM inv_settings WHERE id = 1') ?? 'UTC')); } catch (Throwable) { return new DateTimeZone('UTC'); }
}

/**
 * The filters of a report from a request ($src = $_POST or $_GET), each validated; a mistake is a field error in $errors (name => sentence). Defaults: the month so far, 30 days, the report's default grouping. Returns the params the
 * report runs on: by, days, from, to, as_of (+ as_of_ts), kind[], health[], role, brand, supplier, source, location (ids).
 */
function report_params(PDO $pdo, string $report, array $src, array &$errors): array
{
    $spec = report_spec($report) ?? throw new InvalidArgumentException('No such report.');
    $get = static function (string $k) use ($src): ?string { $v = $src[$k] ?? null; return is_array($v) || $v === null ? null : trim((string) $v); };
    $list = static function (string $k) use ($src): array {
        $v = $src[$k] ?? $src[$k . '[]'] ?? [];
        $v = is_array($v) ? $v : explode(',', (string) $v);
        return array_values(array_unique(array_filter(array_map(static fn ($x): string => trim((string) $x), $v), static fn (string $x): bool => $x !== '')));
    };
    $p = ['report' => $report];
    if ($spec['bys'] !== []) {
        $by = $get('by');
        $p['by'] = $by === null || $by === '' ? $spec['default_by'] : $by;
        if (!in_array($p['by'], $spec['bys'], true)) {
            $errors['by'] = 'Group by ' . implode(', ', array_slice($spec['bys'], 0, -1)) . ' or ' . end($spec['bys']) . '.';
            $p['by'] = $spec['default_by'];
        }
    }
    $tz = business_tz($pdo);
    $today = new DateTimeImmutable('now', $tz);
    if (in_array('days', $spec['fields'], true)) {
        $d = $get('days');
        $p['days'] = 30;
        if ($d !== null && $d !== '') {
            if (filter_var($d, FILTER_VALIDATE_INT) === false || (int) $d < 1 || (int) $d > 365) { $errors['days'] = 'Days is a whole number from 1 to 365.'; } else { $p['days'] = (int) $d; }
        }
    }
    if (in_array('from', $spec['fields'], true)) {
        $p['from'] = $today->format('Y-m-01');
        $p['to'] = $today->format('Y-m-d');
        foreach (['from', 'to'] as $k) {
            $v = $get($k);
            if ($v === null || $v === '') { continue; }
            $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
            if ($dt === false || $dt->format('Y-m-d') !== $v) { $errors[$k] = ucfirst($k) . ' is a date, YYYY-MM-DD.'; } else { $p[$k] = $v; }
        }
        if (!isset($errors['from']) && !isset($errors['to'])) {
            if ($p['to'] < $p['from']) {
                $errors['to'] = 'The period ends before it starts.';
            } elseif ((strtotime($p['to']) - strtotime($p['from'])) / 86400 > 366) {
                $errors['to'] = 'A period is at most 366 days.';
            }
        }
    }
    if (in_array('as_of', $spec['fields'], true)) {
        $v = $get('as_of');
        $p['as_of'] = '';
        $p['as_of_ts'] = null;
        if ($v !== null && $v !== '') {
            $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $v, $tz);
            $dateOnly = $dt !== false && $dt->format('Y-m-d') === $v;
            if (!$dateOnly) { $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $v, $tz) ?: DateTimeImmutable::createFromFormat('Y-m-d H:i', $v, $tz); }
            if ($dt === false) {
                $errors['as_of'] = 'As of is a date or a date and time.';
            } else {
                if ($dateOnly) { $dt = $dt->setTime(23, 59, 59); }
                $p['as_of'] = $v;
                $p['as_of_ts'] = $dt->format('c');
            }
        }
    }
    if (in_array('kind', $spec['fields'], true)) {
        $p['kind'] = $list('kind');
        foreach ($p['kind'] as $k) { if (!in_array($k, REPORT_PRICE_KINDS, true)) { $errors['kind'] = 'Kind is retail under MAP, reference undercut or cost moved.'; } }
    }
    if (in_array('health', $spec['fields'], true)) {
        $p['health'] = $list('health');
        foreach ($p['health'] as $k) { if (!in_array($k, REPORT_HEALTHS, true)) { $errors['health'] = 'Health is one of ' . implode(', ', REPORT_HEALTHS) . '.'; } }
        $role = $get('role');
        $p['role'] = $role === null || $role === '' ? null : $role;
        if ($p['role'] !== null && !in_array($p['role'], ['supplier', 'reference', 'own', 'competitor', 'marketplace', 'partner'], true)) {
            $ok = one_value($pdo, 'SELECT 1 FROM mcp_sources WHERE role = :r LIMIT 1', ['r' => $p['role']]);
            if ($ok === null) { $errors['role'] = 'That role is not one a source has.'; }
        }
    }
    if (in_array('days', $spec['fields'], true) === false && $report === 'source-health') { $p['days'] = 30; }
    foreach (['brand' => 'SELECT 1 FROM mcp_brands WHERE brand_id = :id', 'supplier' => 'SELECT 1 FROM mcp_suppliers WHERE supplier_id = :id', 'source' => 'SELECT 1 FROM mcp_sources WHERE source_id = :id',
              'location' => 'SELECT 1 FROM mcp_locations WHERE location_id = :id'] as $k => $sql) {
        $v = $get($k);
        $p[$k] = null;
        if ($v === null || $v === '') { continue; }
        if (filter_var($v, FILTER_VALIDATE_INT) === false || one_value($pdo, $sql, ['id' => (int) $v]) === null) { $errors[$k] = 'Choose a ' . $k . ' from the list.'; continue; }
        $p[$k] = (int) $v;
    }
    return $p;
}

/** The loggable form of the params (never rows): by, days, from, to, as_of, kind. */
function report_params_loggable(array $p): array
{
    $out = [];
    foreach (['by', 'days', 'from', 'to', 'as_of', 'kind', 'health', 'role', 'brand', 'supplier', 'source'] as $k) {
        if (isset($p[$k]) && $p[$k] !== '' && $p[$k] !== []) { $out[$k] = $p[$k]; }
    }
    return $out;
}

/** The rows as the caller may see them, with the totals: ['rows', 'totals', 'cost_withheld', 'truncated']. $params['_limit'] caps the rows (default 500). */
function report_rows(PDO $pdo, string $report, array $params): array
{
    $spec = report_spec($report) ?? throw new InvalidArgumentException('No such report.');
    $limit = (int) ($params['_limit'] ?? REPORT_ROW_LIMIT);
    $sees = sees_cost();
    $totals = null;
    switch ($report) {
        case 'stock-value':
            $st = $pdo->prepare('SELECT * FROM inv_stock_value(CAST(:a AS timestamptz), :by)');
            $st->execute(['a' => $params['as_of_ts'] ?? null, 'by' => $params['by']]);
            $rows = $st->fetchAll();
            $totals = ['group_name' => 'Total', 'units' => array_sum(array_map('intval', array_column($rows, 'units'))), 'value' => $sees ? round(array_sum(array_map('floatval', array_column($rows, 'value'))), 2) : null];
            break;
        case 'sell-through':
            $st = $pdo->prepare('SELECT * FROM inv_sell_through(:d, :by)');
            $st->execute(['d' => $params['days'], 'by' => $params['by']]);
            $rows = $st->fetchAll();
            $rev = round(array_sum(array_map('floatval', array_column($rows, 'revenue'))), 2);
            $cogs = $sees ? round(array_sum(array_map('floatval', array_column($rows, 'cogs'))), 2) : null;
            $totals = ['group_name' => 'Total', 'units_sold' => array_sum(array_map('intval', array_column($rows, 'units_sold'))), 'revenue' => $rev, 'cogs' => $cogs,
                       'margin_pct' => $sees && $rev > 0 ? round(($rev - $cogs) / $rev * 100, 1) : null, 'units_on_hand' => $params['by'] === 'variant' ? array_sum(array_map('intval', array_column($rows, 'units_on_hand'))) : null, 'weeks_of_cover' => null];
            break;
        case 'sales':
        case 'margin':
            $st = $pdo->prepare('SELECT * FROM inv_sales_summary(CAST(:f AS date), CAST(:t AS date), :by)');
            $st->execute(['f' => $params['from'], 't' => $params['to'], 'by' => $params['by']]);
            $rows = $st->fetchAll();
            $rev = round(array_sum(array_map('floatval', array_column($rows, 'revenue'))), 2);
            $cogs = $sees ? round(array_sum(array_map('floatval', array_column($rows, 'cogs'))), 2) : null;
            $exclusive = in_array($params['by'], ['day', 'week', 'month', 'salesperson', 'location'], true);          // an order is in one group; by brand, type or variant it can be in several
            $totals = ['group_name' => 'Total', 'orders' => $exclusive ? array_sum(array_map('intval', array_column($rows, 'orders'))) : null, 'units_sold' => array_sum(array_map('intval', array_column($rows, 'units_sold'))),
                       'revenue' => $rev, 'discount' => round(array_sum(array_map('floatval', array_column($rows, 'discount'))), 2), 'tax' => round(array_sum(array_map('floatval', array_column($rows, 'tax'))), 2),
                       'cogs' => $cogs, 'margin_pct' => $sees && $rev > 0 ? round(($rev - $cogs) / $rev * 100, 1) : null];
            break;
        case 'price-exceptions':
            $rows = price_exceptions($pdo, (array) ($params['kind'] ?? []), $params['brand'] ?? null);
            break;
        case 'source-health':
            $rows = source_health_report($pdo, (array) ($params['health'] ?? []), $params['role'] ?? null, $params['source'] ?? null);
            $rel = source_reliability($pdo, (int) ($params['days'] ?? 30));
            foreach ($rows as &$r) {
                $x = $rel[(int) $r['source_id']] ?? ['pulls' => 0, 'failures' => 0, 'blocks' => 0, 'success_pct' => null, 'last_failure_at' => null, 'last_error' => null];
                $r['pulls'] = $x['pulls'];
                $r['failures'] = $x['failures'];
                $r['blocks'] = $x['blocks'];
                $r['success_pct'] = $x['success_pct'];
                $r['last_failure_at'] = $x['last_failure_at'];
                $r['last_error'] = $x['last_error'] ?? ($r['last_error'] !== null ? mb_substr((string) $r['last_error'], 0, 200) : null);
            }
            unset($r);
            break;
        case 'lead-times':
            $rows = lead_time_actuals($pdo, $params['supplier'] ?? null);
            break;
        default:
            throw new InvalidArgumentException('No such report.');
    }
    $truncated = count($rows) > $limit;
    $withheld = false;
    if ($spec['cost']) {
        $withheld = !$sees;
        foreach ($rows as $r) { if (!empty($r['cost_withheld'])) { $withheld = true; break; } }
    }
    return ['rows' => array_slice($rows, 0, $limit), 'totals' => $totals, 'cost_withheld' => $withheld, 'truncated' => $truncated, 'count' => count($rows)];
}

/** inv_stock_value() — by location, brand, type or variant, as of a moment ([group_id, group_name, units, value, cost_withheld]). */
function stock_value(PDO $pdo, ?string $asOf = null, string $by = 'location'): array
{
    $st = $pdo->prepare('SELECT * FROM inv_stock_value(CAST(:a AS timestamptz), :by)');
    $st->execute(['a' => $asOf, 'by' => $by]);
    $rows = $st->fetchAll();
    return ['rows' => $rows, 'totals' => ['units' => array_sum(array_map('intval', array_column($rows, 'units'))), 'value' => sees_cost() ? round(array_sum(array_map('floatval', array_column($rows, 'value'))), 2) : null]];
}

function sell_through(PDO $pdo, int $days = 30, string $by = 'variant'): array
{
    $st = $pdo->prepare('SELECT * FROM inv_sell_through(:d, :by)');
    $st->execute(['d' => $days, 'by' => $by]);
    return $st->fetchAll();
}

function sales_summary(PDO $pdo, string $from, string $to, string $by = 'brand'): array
{
    $st = $pdo->prepare('SELECT * FROM inv_sales_summary(CAST(:f AS date), CAST(:t AS date), :by)');
    $st->execute(['f' => $from, 't' => $to, 'by' => $by]);
    return $st->fetchAll();
}

/** inv_price_exceptions() filtered in PHP by kind and by brand (the function takes neither). */
function price_exceptions(PDO $pdo, array $kinds = [], ?int $brandId = null): array
{
    $rows = $pdo->query('SELECT * FROM inv_price_exceptions()')->fetchAll();
    if ($kinds !== []) { $rows = array_values(array_filter($rows, static fn (array $r): bool => in_array($r['kind'], $kinds, true))); }
    if ($brandId !== null) {
        $st = $pdo->prepare('SELECT v.variant_id FROM mcp_product_variants v JOIN mcp_products p ON p.product_id = v.product_id WHERE p.brand_id = :b');
        $st->execute(['b' => $brandId]);
        $byVariant = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
        $rows = array_values(array_filter($rows, static fn (array $r): bool => isset($byVariant[(int) $r['variant_id']])));
    }
    return $rows;
}

/** inv_source_health() filtered by health and role (and one source). */
function source_health_report(PDO $pdo, array $healths = [], ?string $role = null, ?int $sourceId = null): array
{
    $rows = $pdo->query('SELECT * FROM inv_source_health()')->fetchAll();
    return array_values(array_filter($rows, static fn (array $r): bool => ($healths === [] || in_array($r['health'], $healths, true)) && ($role === null || $r['role'] === $role) && ($sourceId === null || (int) $r['source_id'] === $sourceId)));
}

/**
 * How reliably each source pulled over the last $days: from the log's source.pull_done / pull_fail / pull_blocked rows per source — pulls, failures, blocks, success_pct (done over all three), the last failure and its error (200 characters).
 * Keyed by source_id.
 */
function source_reliability(PDO $pdo, int $days = 30): array
{
    $st = $pdo->prepare("SELECT source_id, action, occurred_at, after->>'error' AS error FROM mcp_activity_log
                          WHERE source_id IS NOT NULL AND action IN ('source.pull_done', 'source.pull_fail', 'source.pull_blocked') AND occurred_at > now() - make_interval(days => :d)
                          ORDER BY occurred_at DESC, activity_id DESC");
    $st->execute(['d' => max(1, min(365, $days))]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $s = (int) $r['source_id'];
        $out[$s] ??= ['pulls' => 0, 'done' => 0, 'failures' => 0, 'blocks' => 0, 'success_pct' => null, 'last_failure_at' => null, 'last_error' => null];
        $out[$s]['pulls']++;
        if ($r['action'] === 'source.pull_done') { $out[$s]['done']++; }
        else {
            $out[$s][$r['action'] === 'source.pull_fail' ? 'failures' : 'blocks']++;
            if ($out[$s]['last_failure_at'] === null) { $out[$s]['last_failure_at'] = $r['occurred_at']; $out[$s]['last_error'] = $r['error'] === null ? null : mb_substr((string) $r['error'], 0, 200); }
        }
    }
    foreach ($out as &$o) { $o['success_pct'] = $o['pulls'] > 0 ? round($o['done'] / $o['pulls'] * 100, 1) : null; }
    unset($o);
    return $out;
}

function lead_time_actuals(PDO $pdo, ?int $supplierId = null): array
{
    $st = $pdo->prepare('SELECT * FROM inv_lead_time_actuals(:s)');
    $st->execute(['s' => $supplierId]);
    return $st->fetchAll();
}

/** The brands, suppliers and sources a filter offers. */
function report_pick_lists(PDO $pdo): array
{
    return [
        'brands' => $pdo->query('SELECT brand_id, name FROM mcp_brands WHERE active ORDER BY lower(name)')->fetchAll(),
        'suppliers' => $pdo->query('SELECT supplier_id, name FROM mcp_suppliers WHERE active ORDER BY lower(name)')->fetchAll(),
        'sources' => $pdo->query('SELECT source_id, name FROM mcp_sources ORDER BY lower(name)')->fetchAll(),
    ];
}

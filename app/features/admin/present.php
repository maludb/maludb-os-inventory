<?php
declare(strict_types=1);

/** The admin's JSON (whitelists) and the little words the screens use. */

function present_settings(array $s): array
{
    $o = [];
    foreach (settings_columns() as $k) {
        $v = $s[$k];
        $o[$k] = $k === 'max_attachment_bytes' ? (int) $v : $v;
    }
    $o['max_attachment_mb'] = (int) round($s['max_attachment_bytes'] / 1048576);
    $o['updated_at'] = json_ts($s['updated_at']);
    return $o;
}

function present_sequence(array $s): array
{
    return ['kind' => $s['kind'], 'label' => $s['label'], 'prefix' => $s['prefix'], 'next_value' => $s['next_value'], 'padding' => $s['padding'], 'next_number' => $s['next_number'], 'updated_at' => json_ts($s['updated_at'])];
}

function present_tax_rate(array $t): array
{
    return ['tax_rate_id' => $t['tax_rate_id'], 'name' => $t['name'], 'rate' => (float) $t['rate'], 'is_default' => $t['is_default'], 'archived' => $t['archived_at'] !== null, 'archived_at' => json_ts($t['archived_at']),
            'orders_using' => $t['orders_using'], 'customers_using' => $t['customers_using']];
}

function present_reason_code(array $r): array
{
    return ['reason_code_id' => $r['reason_code_id'], 'code' => $r['code'], 'name' => $r['name'], 'applies_to' => $r['applies_to'], 'affects_qty' => $r['affects_qty'], 'sort_order' => $r['sort_order'], 'active' => $r['active']];
}

/** A tax rate's loggable form (name, rate, default). */
function tax_rate_loggable(array $t): array
{
    return ['name' => $t['name'], 'rate' => $t['rate'], 'is_default' => $t['is_default']];
}

function reason_code_loggable(array $r): array
{
    return ['code' => $r['code'], 'name' => $r['name'], 'applies_to' => $r['applies_to'], 'affects_qty' => $r['affects_qty'], 'sort_order' => $r['sort_order'], 'active' => $r['active']];
}

/** A cron line in words for the shapes a duty uses: "30 6 * * *" → "every day at 06:30"; "0 7 * * 1-5" → "weekdays at 07:00"; "0 9 * * 1" → "every Monday at 09:00"; anything else as it is. */
function cron_in_words(string $cron): string
{
    if (!preg_match('/^(\d{1,2}) (\d{1,2}) \* \* (\*|[0-7](?:-[0-7])?)$/', trim($cron), $m)) { return $cron; }
    $at = sprintf('%02d:%02d', (int) $m[2], (int) $m[1]);
    if ($m[3] === '*') { return 'every day at ' . $at; }
    if ($m[3] === '1-5') { return 'weekdays at ' . $at; }
    $days = [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
    return ctype_digit($m[3]) ? 'every ' . $days[(int) $m[3]] . ' at ' . $at : $cron;
}

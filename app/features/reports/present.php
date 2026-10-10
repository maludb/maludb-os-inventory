<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/catalog/present.php';

/** How a report's cells are worded: money with two decimals, a percent, a count, a date; a chip for a health or a kind. */

const REPORT_CHIPS = [
    'ok' => 'success', 'stale' => 'warning', 'failing' => 'warning', 'blocked' => 'danger', 'paused' => 'secondary', 'manual' => 'secondary', 'never_pulled' => 'dark', 'inactive' => 'secondary',
    'retail_under_map' => 'danger', 'reference_undercut' => 'warning', 'cost_moved' => 'info',
];

/** One cell as HTML-safe text (escaped) — a chip is a badge. */
function report_cell_html(string $kind, mixed $v, string $tz, bool $withheld = false): string
{
    if ($withheld) { return '<span class="text-muted" title="cost withheld">—</span>'; }
    if ($v === null || $v === '') { return '<span class="text-muted">—</span>'; }
    return match ($kind) {
        'money' => e(number_format((float) $v, 2)),
        'pct' => e(number_format((float) $v, 1)) . '%',
        'num1' => e(rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.') ?: '0'),
        'int' => e(number_format((float) $v)),
        'date' => e(format_date((string) $v)),
        'ts' => e(format_ts((string) $v, $tz, 'M j, g:i A')),
        'chip' => '<span class="badge bg-soft-' . (REPORT_CHIPS[(string) $v] ?? 'secondary') . ' text-' . (REPORT_CHIPS[(string) $v] ?? 'secondary') . '">' . e(str_replace('_', ' ', (string) $v)) . '</span>',
        default => e($v),
    };
}

/** A cell's class: numbers right-aligned; a margin below zero red; weeks of cover under 2 amber. */
function report_cell_class(string $key, string $kind, mixed $v): string
{
    $c = in_array($kind, ['money', 'pct', 'num1', 'int'], true) ? 'text-end' : '';
    if ($key === 'margin_pct' && $v !== null && (float) $v < 0) { $c .= ' text-danger fw-semibold'; }
    if ($key === 'weeks_of_cover' && $v !== null && (float) $v < 2) { $c .= ' text-warning fw-semibold'; }
    return trim($c);
}

/** The JSON answer of a report run: the rows (whitelisted by the spec's columns), the totals, the notes. */
function present_report(string $report, array $result, array $params): array
{
    $spec = report_spec($report);
    $rows = [];
    foreach ($result['rows'] as $r) {
        $o = [];
        foreach ($spec['columns'] as [$c, , $kind]) {
            $v = $r[$c] ?? null;
            $o[$c] = $v === null ? null : match ($kind) { 'money', 'pct', 'num1' => (float) $v, 'int' => (int) $v, 'ts' => json_ts((string) $v), default => $v };
        }
        foreach (['group_id', 'source_id', 'variant_id', 'supplier_id'] as $idc) { if (isset($r[$idc])) { $o[$idc] = (int) $r[$idc]; } }
        $rows[] = $o;
    }
    return ['report' => $report, 'params' => report_params_loggable($params), 'rows' => $rows, 'totals' => $result['totals'], 'cost_withheld' => $result['cost_withheld'], 'truncated' => $result['truncated'], 'count' => $result['count']];
}

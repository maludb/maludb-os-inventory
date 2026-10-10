<?php
declare(strict_types=1);

/** A report as CSV: a UTF-8 BOM, the columns as headed, amounts with two decimals, dates ISO; a cost column below the wall is empty. */

function report_csv_value(string $kind, mixed $v): string
{
    if ($v === null) { return ''; }
    return match ($kind) {
        'money' => number_format((float) $v, 2, '.', ''),
        'pct', 'num1' => rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.') ?: '0',
        'int' => (string) (int) $v,
        'date' => substr((string) $v, 0, 10),
        'ts' => (new DateTimeImmutable((string) $v))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        default => (string) $v,
    };
}

/** The whole CSV text of a result. */
function report_csv(string $report, array $result): string
{
    $spec = report_spec($report);
    $out = fopen('php://temp', 'w+');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_map(static fn (array $c): string => $c[1], $spec['columns']), ',', '"', '');
    foreach ($result['rows'] as $r) {
        fputcsv($out, array_map(static fn (array $c): string => report_csv_value($c[2], $r[$c[0]] ?? null), $spec['columns']), ',', '"', '');
    }
    if ($result['totals'] !== null) {
        fputcsv($out, array_map(static fn (array $c): string => report_csv_value($c[2], $result['totals'][$c[0]] ?? null), $spec['columns']), ',', '"', '');
    }
    rewind($out);
    $text = (string) stream_get_contents($out);
    fclose($out);
    return $text;
}

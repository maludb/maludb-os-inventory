<?php
/**
 * The chart component (catalog.md "The chart component"; the dataviz rules): an inline SVG — a step line per series (a price holds until
 * it changes), a marker at every change point, four recessive gridlines, the unit on the y axis, the first / middle / last dates on the x
 * axis, the last value direct-labelled; a legend with ≥ 2 series; the availability band (slice 3) under the plot; the table beneath is the
 * same data (the accessibility channel). series-chart.js adds the crosshair and the tooltip; without JavaScript the labels and the table read.
 * Data: id, series = [['key', 'label', 'points' => [[iso8601, number, reason?], …]]], bands = [['from', 'to'|null, 'state']], unit, table_id,
 * empty, title?, tz?
 */
$bands = $bands ?? [];
$unit = $unit ?? '';
$title = $title ?? implode(', ', array_column($series, 'label'));
$tz = $tz ?? 'UTC';
$points = [];
foreach ($series as $s) { foreach ($s['points'] as $p) { $points[] = $p; } }
if ($points === []): ?>
<figure class="viz-root" id="<?= e($id) ?>"><div class="text-muted fs-12 py-3" id="<?= e($id) ?>-empty"><?= e($empty ?? 'Nothing to chart yet.') ?></div></figure>
<?php return; endif;
$times = array_map(static fn (array $p): int => (int) strtotime((string) $p[0]), $points);
foreach ($bands as $b) { $times[] = (int) strtotime((string) $b['from']); if (!empty($b['to'])) { $times[] = (int) strtotime((string) $b['to']); } }
$values = array_map(static fn (array $p): float => (float) $p[1], $points);
$tMin = min($times); $tMax = max($times); if ($tMax === $tMin) { $tMax = $tMin + 86400; $tMin -= 86400; }
$now = max($tMax, time());
$tMax = $now;                                                   // a step line holds until now
$vMin = min($values); $vMax = max($values);
if ($vMax === $vMin) { $vMin = max(0, $vMin - 1); $vMax += 1; }
$pad = ($vMax - $vMin) * 0.1; $vMin = max(0, $vMin - $pad); $vMax += $pad;
$x0 = 60; $y0 = 20; $w = 560; $h = 160;
$X = static fn (int $t): float => $x0 + ($t - $tMin) / max(1, $tMax - $tMin) * $w;
$Y = static fn (float $v): float => $y0 + $h - ($v - $vMin) / ($vMax - $vMin) * $h;
$fmtV = static fn (float $v): string => number_format($v, $vMax - $vMin < 20 ? 2 : 0);
$fmtD = static fn (int $t): string => date('M j', $t);
$slot = 0;
?>
<figure class="viz-root" id="<?= e($id) ?>" tabindex="0" data-viz-series='<?= e(json_encode(array_map(static fn (array $s): array => ['key' => $s['key'], 'label' => $s['label'], 'points' => array_map(static fn (array $p): array => ['t' => (int) strtotime((string) $p[0]), 'x' => round($X((int) strtotime((string) $p[0])), 1), 'v' => (float) $p[1], 'reason' => $p[2] ?? null], $s['points'])], $series), JSON_UNESCAPED_SLASHES)) ?>' data-viz-unit="<?= e($unit) ?>" data-viz-plot="<?= $x0 ?>,<?= $y0 ?>,<?= $w ?>,<?= $h ?>">
    <figcaption id="<?= e($id) ?>-caption"><?= e($title) ?><?= $unit !== '' ? ' · ' . e($unit) : '' ?></figcaption>
    <svg viewBox="0 0 640 240" width="100%" preserveAspectRatio="xMidYMid meet" role="img" aria-labelledby="<?= e($id) ?>-caption">
        <g class="viz-axis">
            <?php for ($i = 0; $i <= 3; $i++): $v = $vMin + ($vMax - $vMin) * $i / 3; $y = $Y($v); ?>
                <line class="viz-grid" x1="<?= $x0 ?>" y1="<?= round($y, 1) ?>" x2="<?= $x0 + $w ?>" y2="<?= round($y, 1) ?>" />
                <text x="<?= $x0 - 6 ?>" y="<?= round($y + 4, 1) ?>" text-anchor="end"><?= e($fmtV($v)) ?></text>
            <?php endfor; ?>
            <?php foreach ([$tMin, (int) (($tMin + $tMax) / 2), $tMax] as $k => $t): ?>
                <text x="<?= round($X($t), 1) ?>" y="<?= $y0 + $h + 16 ?>" text-anchor="<?= $k === 0 ? 'start' : ($k === 2 ? 'end' : 'middle') ?>"><?= e($fmtD($t)) ?></text>
            <?php endforeach; ?>
        </g>
        <?php foreach ($bands as $b): $bf = (int) strtotime((string) $b['from']); $bt = !empty($b['to']) ? (int) strtotime((string) $b['to']) : $tMax; ?>
            <rect class="viz-band viz-band-<?= e($b['state']) ?>" x="<?= round($X($bf), 1) ?>" y="212" width="<?= round(max(1, $X($bt) - $X($bf)), 1) ?>" height="10"><title><?= e(str_replace('_', ' ', $b['state'])) ?> from <?= e($fmtD($bf)) ?></title></rect>
        <?php endforeach; ?>
        <?php foreach ($series as $s): $slot++; $pts = $s['points']; if ($pts === []) { continue; } $d = ''; $prevY = null; ?>
            <?php foreach ($pts as $i => $p): $t = (int) strtotime((string) $p[0]); $x = round($X($t), 1); $y = round($Y((float) $p[1]), 1);
                $d .= $i === 0 ? "M $x $y" : " H $x V $y"; $prevY = $y; endforeach; $d .= ' H ' . round($X($tMax), 1); ?>
            <path class="viz-line viz-series-<?= $slot ?>" d="<?= e($d) ?>" />
            <?php foreach ($pts as $p): ?><circle class="viz-marker viz-series-<?= $slot ?>" r="4" cx="<?= round($X((int) strtotime((string) $p[0])), 1) ?>" cy="<?= round($Y((float) $p[1]), 1) ?>"><title><?= e($s['label']) ?> <?= e($fmtV((float) $p[1])) ?> on <?= e($fmtD((int) strtotime((string) $p[0]))) ?><?= !empty($p[2]) ? ' · ' . e($p[2]) : '' ?></title></circle><?php endforeach; ?>
            <?php $last = end($pts); ?><text class="viz-label" x="<?= $x0 + $w + 4 ?>" y="<?= round($Y((float) $last[1]) + 4, 1) ?>"><?= e($fmtV((float) $last[1])) ?></text>
        <?php endforeach; ?>
        <rect class="viz-hit" x="<?= $x0 ?>" y="<?= $y0 ?>" width="<?= $w ?>" height="<?= $h ?>" />
        <line class="viz-crosshair" x1="0" y1="<?= $y0 ?>" x2="0" y2="<?= $y0 + $h ?>" hidden />
    </svg>
    <div class="viz-tooltip" hidden></div>
    <?php if (count($series) >= 2 || $bands !== []): ?>
    <ul class="viz-legend" id="<?= e($id) ?>-legend">
        <?php $slot = 0; foreach ($series as $s): $slot++; ?><li><span class="viz-swatch viz-swatch-<?= $slot ?>"></span><?= e($s['label']) ?></li><?php endforeach; ?>
        <?php if ($bands !== []): ?><li><span class="viz-swatch viz-swatch-band" style="background: var(--viz-good)"></span>● In stock</li><li><span class="viz-swatch viz-swatch-band" style="background: var(--viz-warning)"></span>▲ Limited / back order</li><li><span class="viz-swatch viz-swatch-band" style="background: var(--viz-serious)"></span>■ Out of stock</li><li><span class="viz-swatch viz-swatch-band" style="background: var(--viz-neutral)"></span>○ Unknown</li><?php endif; ?>
    </ul>
    <?php endif; ?>
</figure>

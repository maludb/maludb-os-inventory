<?php
/**
 * The results of a report (`#report-results`): a table (`report-results-table`, `report-row-{n}`) with the totals row, the "cost withheld" note below the wall, the period and the grouping shown. Data: report, spec, params, result, tz
 */
$cols = $spec['columns'];
$sees = !$result['cost_withheld'];
$period = match (true) {
    isset($params['from']) => format_date($params['from']) . ' to ' . format_date($params['to']),
    isset($params['days']) && $report !== 'source-health' => 'the last ' . (int) $params['days'] . ' days',
    $report === 'source-health' => 'pulls over the last ' . (int) ($params['days'] ?? 30) . ' days',
    $report === 'stock-value' => ($params['as_of'] ?? '') !== '' ? 'as of ' . $params['as_of'] : 'as of now',
    default => '',
};
?>
<div class="card" id="report-results" data-report="<?= e($report) ?>">
    <div class="card-header d-flex flex-wrap align-items-center gap-2">
        <h5 class="card-title mb-0"><?= e($spec['title']) ?></h5>
        <span class="fs-12 text-muted" id="report-results-period"><?= e($period) ?><?= isset($params['by']) ? ' · by ' . e($params['by']) : '' ?> · <?= (int) $result['count'] ?> row<?= (int) $result['count'] === 1 ? '' : 's' ?></span>
    </div>
    <?php if ($result['cost_withheld']): ?>
        <div class="alert alert-warning fs-12 mx-3 mt-3 mb-0" id="report-cost-withheld"><i class="feather-lock me-1"></i>Cost withheld — your role sees units and revenue, not what the goods cost. The admin or the Buyer reads the cost columns.</div>
    <?php endif; ?>
    <?php if ($result['rows'] === []): ?>
        <div class="card-body text-center text-muted" id="report-results-empty">Nothing matches these filters.</div>
    <?php else: ?>
    <div class="card-body p-0"><div class="table-responsive">
        <table class="table table-hover mb-0 fs-12" id="report-results-table">
            <thead class="thead-light"><tr><?php foreach ($cols as [$key, $label, $kind, $cost]): ?><th class="<?= in_array($kind, ['money', 'pct', 'num1', 'int'], true) ? 'text-end' : '' ?> text-nowrap"><?= e($label) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php foreach ($result['rows'] as $n => $r): ?>
                <tr id="report-row-<?= $n + 1 ?>">
                <?php foreach ($cols as [$key, $label, $kind, $cost]): $v = $r[$key] ?? null; ?>
                    <td class="<?= e(report_cell_class($key, $kind, $v)) ?>"><?= report_cell_html($kind, $v, $tz, $cost && $v === null && !$sees) ?></td>
                <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php if ($result['totals'] !== null): ?>
            <tfoot><tr class="fw-semibold" id="report-row-total">
                <?php foreach ($cols as [$key, $label, $kind, $cost]): $v = $result['totals'][$key] ?? null; ?>
                    <td class="<?= e(report_cell_class($key, $kind, $v)) ?>"><?= $key === 'group_name' ? 'Total' : report_cell_html($kind, $v, $tz, $cost && $v === null && !$sees) ?></td>
                <?php endforeach; ?>
            </tr></tfoot>
            <?php endif; ?>
        </table>
    </div></div>
    <?php if ($result['truncated']): ?><div class="card-footer fs-12 text-muted" id="report-results-truncated">The first <?= (int) REPORT_ROW_LIMIT ?> rows of <?= (int) $result['count'] ?> — the CSV has them all.</div><?php endif; ?>
    <?php endif; ?>
</div>

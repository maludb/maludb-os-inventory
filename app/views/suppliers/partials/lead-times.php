<?php /** The lead-time actuals (`supplier-lead-times`): inv_lead_time_actuals(id) for reports.read and the last shipped lines. Data: lead (row or null), shipped, mayReports, here */ ?>
<div class="card mb-3" id="supplier-lead-times"><div class="card-header fw-semibold">Promised and actual</div><div class="card-body fs-12">
    <?php if (!$mayReports): ?><span class="text-muted" id="supplier-lead-times-none">—</span>
    <?php elseif ($lead === null): ?><span class="text-muted" id="supplier-lead-times-none">No shipped line with an expected date yet.</span>
    <?php else: ?>
        <div class="row g-2" id="supplier-lead-times-row">
            <div class="col-6 col-md-3"><span class="text-muted">Lines</span><div class="fw-semibold"><?= (int) $lead['lines'] ?></div></div>
            <div class="col-6 col-md-3"><span class="text-muted">Promised, days</span><div class="fw-semibold"><?= e((string) $lead['promised_avg_days']) ?></div></div>
            <div class="col-6 col-md-3"><span class="text-muted">Actual, days</span><div class="fw-semibold"><?= e((string) $lead['actual_avg_days']) ?></div></div>
            <div class="col-6 col-md-3"><span class="text-muted">On time</span><div class="fw-semibold"><?= e((string) $lead['on_time_pct']) ?>%</div></div>
        </div>
    <?php endif; ?>
</div></div>
<div class="card" id="supplier-shipped"><div class="card-header fw-semibold">The last lines they shipped</div><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12"><thead class="thead-light"><tr><th>Purchase order</th><th>SKU</th><th>Expected</th><th>Shipped</th><th class="text-end">Days late</th></tr></thead><tbody>
    <?php if ($shipped === []): ?><tr><td colspan="5" class="text-center text-muted py-3">Nothing shipped yet.</td></tr><?php endif; ?>
    <?php foreach ($shipped as $l): ?><tr id="supplier-shipped-<?= (int) $l['purchase_order_line_id'] ?>"><td><?= hx_link(with_back('/purchasing/' . (int) $l['purchase_order_id'], $here), e($l['purchase_order_number']), 'fw-semibold') ?> · <?= (int) $l['line_no'] ?></td><td><?= e($l['sku']) ?></td>
        <td class="text-nowrap"><?= $l['expected_on'] ? e(format_date($l['expected_on'])) : '—' ?></td><td class="text-nowrap"><?= e(format_date(substr((string) $l['shipped_at'], 0, 10))) ?></td>
        <td class="text-end"><?php if ($l['days_late'] === null): ?>—<?php elseif ((int) $l['days_late'] > 0): ?><span class="text-danger"><?= (int) $l['days_late'] ?></span><?php else: ?><?= (int) $l['days_late'] ?><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div></div></div>

<?php /** The payments (`order-payments`) for payments.record or reports.read. Data: o, tz, may */ ?>
<div class="card mb-3" id="order-payments"><div class="card-header fw-semibold">Payments</div><div class="card-body p-0"><div class="table-responsive">
    <table class="table mb-0 fs-12"><thead class="thead-light"><tr><th>When</th><th>Kind</th><th class="text-end">Amount</th><th>Method</th><th>Reference</th><th>By</th></tr></thead><tbody>
        <?php if ($o['payments'] === []): ?><tr><td colspan="6" class="text-center text-muted py-3" id="order-payments-empty">Nothing recorded yet.</td></tr><?php endif; ?>
        <?php foreach ($o['payments'] as $p): ?><tr id="order-payment-<?= (int) $p['payment_id'] ?>"><td class="text-nowrap"><?= e(format_ts($p['taken_at'], $tz, 'M j, g:i A')) ?></td><td><?= e(ucfirst($p['kind'])) ?></td>
            <td class="text-end text-nowrap"><?= $p['kind'] === 'refund' ? '−' : '' ?><?= money($p['amount']) ?></td><td><?= e(PAYMENT_METHODS[$p['method']] ?? $p['method']) ?></td><td><?= e($p['reference'] ?? '') ?></td><td><?= e($p['taken_by_name'] ?? '') ?></td></tr><?php endforeach; ?>
    </tbody></table>
</div></div>
<div class="card-footer fs-12 d-flex justify-content-between"><span>Paid <strong id="order-payments-paid"><?= money($o['amount_paid']) ?></strong> of <?= money($o['total']) ?></span><span>Balance due <strong id="order-payments-balance"><?= money($o['balance_due']) ?></strong></span></div></div>

<?php /** Lines at risk (`#home-at-risk`): the order, the line, the customer, why, the salesperson; each opens the order. Data: s */
$a = $s['at_risk'];
?>
<div class="card" id="home-at-risk">
    <div class="card-header"><h5 class="card-title mb-0"><i class="feather-alert-triangle me-2"></i>Lines at risk <span class="badge bg-soft-<?= $a['count'] > 0 ? 'danger text-danger' : 'secondary text-dark' ?> ms-1" id="home-at-risk-count"><?= (int) $a['count'] ?></span></h5></div>
    <?php if ($a['rows'] === []): ?>
        <div class="card-body text-muted fs-12" id="home-at-risk-empty">Order lines whose source is gone, whose price moved or whose supplier is late appear here.</div>
    <?php else: ?>
    <div class="list-group list-group-flush">
    <?php foreach ($a['rows'] as $r): ?>
        <div class="list-group-item" id="home-at-risk-row-<?= (int) $r['line_id'] ?>">
            <div class="d-flex justify-content-between gap-2">
                <div><?= hx_link(with_back('/orders/' . (int) $r['sales_order_id'], '/'), e($r['order_number']) . ' · line ' . (int) $r['line_no'], 'fw-semibold text-dark') ?> <span class="text-muted fs-12"><?= e($r['sku']) ?></span></div>
                <span class="badge bg-soft-danger text-danger align-self-start"><?= e(str_replace('_', ' ', (string) $r['risk'])) ?></span>
            </div>
            <div class="fs-12 text-muted"><?= e($r['customer_name']) ?><?= $r['promised_on'] !== null ? ' · promised ' . e(format_date($r['promised_on'])) : '' ?><?= $r['detail'] ? ' · ' . e($r['detail']) : '' ?></div>
        </div>
    <?php endforeach; ?>
    <?php if ($a['count'] > count($a['rows'])): ?><div class="list-group-item fs-12 text-muted">and <?= (int) $a['count'] - count($a['rows']) ?> more — <?= hx_link('/orders/', 'every order') ?></div><?php endif; ?>
    </div>
    <?php endif; ?>
</div>

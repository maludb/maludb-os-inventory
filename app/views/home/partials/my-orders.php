<?php /** For Sales (`#home-my-orders`, orders.write): my open orders and their next step, late first. Data: s */
$a = $s['my_orders'];
?>
<div class="card" id="home-my-orders">
    <div class="card-header"><h5 class="card-title mb-0"><i class="feather-shopping-cart me-2"></i>My open orders <span class="badge bg-soft-secondary text-dark ms-1"><?= (int) $a['count'] ?></span></h5></div>
    <?php if ($a['rows'] === []): ?>
        <div class="card-body text-muted fs-12" id="home-my-orders-empty">Your quotes and orders with their next step — confirm, record a deposit, ship, deliver — appear here.</div>
    <?php else: ?>
    <div class="list-group list-group-flush">
    <?php foreach ($a['rows'] as $o): ?>
        <div class="list-group-item" id="home-my-order-<?= (int) $o['sales_order_id'] ?>">
            <div class="d-flex justify-content-between gap-2">
                <div><?= hx_link(with_back('/orders/' . (int) $o['sales_order_id'], '/'), e($o['number']), 'fw-semibold text-dark') ?> <span class="text-muted fs-12"><?= e($o['customer_name']) ?></span></div>
                <span class="badge bg-soft-<?= $o['days_late'] > 0 ? 'danger text-danger' : 'primary text-primary' ?> align-self-start" id="home-my-order-<?= (int) $o['sales_order_id'] ?>-step"><?= e($o['next_step']) ?></span>
            </div>
            <div class="fs-12 text-muted"><?= e(str_replace('_', ' ', (string) $o['status'])) ?><?= $o['promised_on'] !== null ? ' · promised ' . e(format_date($o['promised_on'])) : '' ?><?= $o['days_late'] > 0 ? ' · then ' . e(lcfirst($o['step'])) : '' ?></div>
        </div>
    <?php endforeach; ?>
    <div class="list-group-item"><?= hx_link('/orders/?salesperson=' . (int) current_member_id(), 'Every order', 'fs-12 fw-semibold') ?></div>
    </div>
    <?php endif; ?>
</div>

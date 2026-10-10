<?php /** One customer card (`customer-card-{id}`). Data: c (a find_customers row), here */
$id = (int) $c['customer_id'];
$url = with_back('/customers/' . $id, $here);
?>
<div class="card h-100<?= $c['archived_at'] === null ? '' : ' bg-soft-dark' ?>" id="customer-card-<?= $id ?>">
    <div class="card-body">
        <div class="fw-semibold"><?= hx_link($url, e($c['name']), 'text-dark', 'id="customer-card-' . $id . '-name"') ?></div>
        <div class="chip-row mt-1"><?= customer_source_chip($c['source']) ?><?php if ($c['archived_at'] !== null): ?><span class="badge bg-soft-dark text-dark">archived</span><?php endif; ?></div>
        <?php if ($c['email']): ?><div class="fs-12 mt-2 text-truncate" id="customer-card-<?= $id ?>-email"><i class="feather-mail me-1 text-muted"></i><?= e($c['email']) ?></div><?php endif; ?>
        <?php if ($c['phone']): ?><div class="fs-12" id="customer-card-<?= $id ?>-phone"><i class="feather-phone me-1 text-muted"></i><?= e($c['phone']) ?></div><?php endif; ?>
        <div class="fs-12 mt-2 text-muted">
            <span id="customer-card-<?= $id ?>-open"><span class="fw-semibold text-dark"><?= (int) $c['open_orders'] ?></span> open order<?= (int) $c['open_orders'] === 1 ? '' : 's' ?></span>
            · <span id="customer-card-<?= $id ?>-last"><?= $c['last_order_on'] ? 'last ' . e(format_date($c['last_order_on'])) : 'no order yet' ?></span>
        </div>
    </div>
</div>

<?php /** Purchase orders awaiting acknowledgment (`#home-po-ack`): sent and not acknowledged past ack_days; each opens the order. Data: s */
$a = $s['po_ack'];
?>
<div class="card" id="home-po-ack">
    <div class="card-header"><h5 class="card-title mb-0"><i class="feather-clipboard me-2"></i>Awaiting acknowledgment <span class="badge bg-soft-<?= $a['count'] > 0 ? 'warning text-warning' : 'secondary text-dark' ?> ms-1"><?= (int) $a['count'] ?></span></h5></div>
    <?php if ($a['rows'] === []): ?>
        <div class="card-body text-muted fs-12" id="home-po-ack-empty">Purchase orders sent and not yet acknowledged by the supplier appear here.</div>
    <?php else: ?>
    <div class="list-group list-group-flush">
    <?php foreach ($a['rows'] as $r): ?>
        <div class="list-group-item d-flex justify-content-between gap-2" id="home-po-ack-row-<?= (int) $r['purchase_order_id'] ?>">
            <div><?= hx_link(with_back('/purchasing/' . (int) $r['purchase_order_id'], '/'), e($r['number']), 'fw-semibold text-dark') ?><div class="fs-12 text-muted"><?= e($r['supplier_name']) ?><?= $r['sales_order_number'] ? ' · for ' . e($r['sales_order_number']) : '' ?></div></div>
            <span class="badge bg-soft-warning text-warning align-self-start"><?= (int) $r['days_waiting'] ?> day<?= (int) $r['days_waiting'] === 1 ? '' : 's' ?></span>
        </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php /** For Warehouse (`#home-warehouse`): to receive, to pick, to count. Data: s */
$w = $s['warehouse'];
$empty = $w['to_receive']['count'] === 0 && $w['to_pick'] === [] && $w['to_count'] === [];
?>
<div class="card" id="home-warehouse">
    <div class="card-header"><h5 class="card-title mb-0"><i class="feather-layers me-2"></i>To receive, to pick, to count</h5></div>
    <?php if ($empty): ?>
        <div class="card-body text-muted fs-12" id="home-warehouse-empty">Draft receipts and stock purchase orders due, the lines to pick today and the open counts appear here.</div>
    <?php else: ?>
    <div class="list-group list-group-flush">
        <?php foreach ($w['to_receive']['receipts'] as $r): ?>
            <div class="list-group-item" id="home-warehouse-receive-<?= (int) $r['goods_receipt_id'] ?>"><span class="badge bg-soft-info text-info me-1">receive</span><?= hx_link(with_back('/receipts/' . (int) $r['goods_receipt_id'], '/'), e($r['number']), 'fw-semibold text-dark') ?> <span class="fs-12 text-muted"><?= e($r['supplier_name'] ?? '') ?> · <?= e($r['location_name']) ?></span></div>
        <?php endforeach; ?>
        <?php foreach ($w['to_receive']['purchase_orders'] as $r): ?>
            <div class="list-group-item" id="home-warehouse-po-<?= (int) $r['purchase_order_id'] ?>"><span class="badge bg-soft-info text-info me-1">due</span><?= hx_link(with_back('/purchasing/' . (int) $r['purchase_order_id'], '/'), e($r['number']), 'fw-semibold text-dark') ?> <span class="fs-12 text-muted"><?= e($r['supplier_name']) ?> · expected <?= e(format_date($r['expected_on'])) ?></span></div>
        <?php endforeach; ?>
        <?php foreach ($w['to_pick'] as $p): ?>
            <div class="list-group-item" id="home-warehouse-pick-<?= (int) ($p['location_id'] ?? 0) ?>"><span class="badge bg-soft-warning text-warning me-1">pick</span><?= hx_link(with_back('/orders/today' . ($p['location_id'] !== null ? '?location=' . (int) $p['location_id'] : ''), '/'), e($p['location_name'] ?? 'No location'), 'fw-semibold text-dark') ?> <span class="fs-12 text-muted"><?= (int) $p['qty'] ?> unit<?= (int) $p['qty'] === 1 ? '' : 's' ?> on <?= (int) $p['lines'] ?> line<?= (int) $p['lines'] === 1 ? '' : 's' ?> · <?= e(str_replace('_', ' ', (string) $p['delivery_method'])) ?></span></div>
        <?php endforeach; ?>
        <?php foreach ($w['to_count'] as $c): ?>
            <div class="list-group-item" id="home-warehouse-count-<?= (int) $c['count_id'] ?>"><span class="badge bg-soft-secondary text-dark me-1">count</span><?= hx_link(with_back('/counts/' . (int) $c['count_id'], '/'), e($c['number']), 'fw-semibold text-dark') ?> <span class="fs-12 text-muted"><?= e($c['location_name']) ?> · <?= (int) $c['line_count'] ?> line<?= (int) $c['line_count'] === 1 ? '' : 's' ?></span></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

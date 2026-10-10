<?php /** One supplier card (`supplier-card-{id}`). Data: s (a find_suppliers row), here */
$id = (int) $s['supplier_id'];
$url = with_back('/suppliers/' . $id, $here);
?>
<div class="card h-100<?= $s['active'] ? '' : ' bg-soft-dark' ?>" id="supplier-card-<?= $id ?>">
    <div class="card-body">
        <div class="fw-semibold"><?= hx_link($url, e($s['name']), 'text-dark', 'id="supplier-card-' . $id . '-name"') ?></div>
        <div class="chip-row mt-1"><?= supplier_kind_chip($s['kind']) ?><?php if ($s['dropships']): ?> <span id="supplier-card-<?= $id ?>-dropships"><?= dropships_chip() ?></span><?php endif; ?><?php if (!$s['active']): ?> <span class="badge bg-soft-dark text-dark">archived</span><?php endif; ?></div>
        <div class="fs-12 mt-2 text-muted">
            <span id="supplier-card-<?= $id ?>-lead"><?= $s['lead_time_days'] !== null ? (int) $s['lead_time_days'] . ' day' . ($s['lead_time_days'] === 1 ? '' : 's') . ' lead time' : 'no lead time given' ?></span>
            · <?= e(SUPPLIER_ORDER_METHODS[$s['order_method']] ?? $s['order_method']) ?>
        </div>
        <div class="fs-12 mt-1">
            <span id="supplier-card-<?= $id ?>-open"><span class="fw-semibold"><?= (int) $s['open_orders'] ?></span> open purchase order<?= (int) $s['open_orders'] === 1 ? '' : 's' ?></span>
            · <span id="supplier-card-<?= $id ?>-sources"><span class="fw-semibold"><?= (int) $s['source_count'] ?></span> source<?= (int) $s['source_count'] === 1 ? '' : 's' ?></span>
        </div>
    </div>
</div>

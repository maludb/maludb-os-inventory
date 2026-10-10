<?php /** Today's deliveries and pickups (`#home-today`): per location and delivery method. Data: s */
$t = $s['today'];
?>
<div class="card" id="home-today">
    <div class="card-header"><h5 class="card-title mb-0"><i class="feather-truck me-2"></i>Today's deliveries and pickups <span class="badge bg-soft-info text-info ms-1"><?= (int) $t['lines'] ?></span></h5></div>
    <?php if ($t['groups'] === []): ?>
        <div class="card-body text-muted fs-12" id="home-today-empty">What goes out today by location and delivery method appears here.</div>
    <?php else: ?>
    <div class="list-group list-group-flush">
    <?php foreach ($t['groups'] as $g): ?>
        <div class="list-group-item d-flex justify-content-between gap-2" id="home-today-<?= (int) ($g['location_id'] ?? 0) ?>-<?= e($g['delivery_method']) ?>">
            <div><?= hx_link(with_back('/orders/today' . ($g['location_id'] !== null ? '?location=' . (int) $g['location_id'] : ''), '/'), e($g['location_name'] ?? 'No location'), 'fw-semibold text-dark') ?><div class="fs-12 text-muted"><?= e(str_replace('_', ' ', (string) $g['delivery_method'])) ?></div></div>
            <span class="badge bg-soft-info text-info align-self-start"><?= (int) $g['orders'] ?> order<?= (int) $g['orders'] === 1 ? '' : 's' ?> · <?= (int) $g['lines'] ?> line<?= (int) $g['lines'] === 1 ? '' : 's' ?></span>
        </div>
    <?php endforeach; ?>
    <?php if ((int) $t['dropships_expected'] > 0): ?><div class="list-group-item fs-12"><?= (int) $t['dropships_expected'] ?> drop-ship line<?= (int) $t['dropships_expected'] === 1 ? '' : 's' ?> expected from suppliers</div><?php endif; ?>
    <div class="list-group-item"><?= hx_link('/orders/today', 'Fulfilment today', 'fs-12 fw-semibold') ?></div>
    </div>
    <?php endif; ?>
</div>

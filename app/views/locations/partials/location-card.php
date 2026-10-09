<?php /** One location card (`location-card-{id}`). Data: l (a find_locations row), here */
$id = (int) $l['location_id'];
$url = with_back('/locations/' . $id, $here);
$addr = trim((string) strtok((string) ($l['address'] ?? ''), "\n"));
?>
<div class="card h-100<?= $l['active'] ? '' : ' bg-soft-dark' ?>" id="location-card-<?= $id ?>">
    <div class="card-body">
        <div class="fw-semibold"><?= hx_link($url, e($l['name']), 'text-dark', 'id="location-card-' . $id . '-name"') ?></div>
        <div class="chip-row mt-1">
            <?= location_kind_chip($l['kind']) ?>
            <?= $l['is_sellable'] ? '<span class="badge bg-soft-success text-success">sellable</span>' : '<span class="badge bg-soft-secondary text-dark">not sellable</span>' ?>
            <?php if ($l['allow_negative']): ?><span class="badge bg-soft-warning text-warning">allows negative</span><?php endif; ?>
            <?php if (!$l['active']): ?><span class="badge bg-soft-dark text-dark">archived</span><?php endif; ?>
        </div>
        <?php if ($l['department_name'] ?? null): ?><div class="fs-12 text-muted mt-2" id="location-card-<?= $id ?>-department"><?= e($l['department_name']) ?></div><?php endif; ?>
        <?php if ($addr !== ''): ?><div class="fs-12 text-muted"><?= e($addr) ?></div><?php endif; ?>
        <div class="fs-12 mt-2" id="location-card-<?= $id ?>-units"><span class="fw-semibold"><?= number_format((int) $l['units_on_hand']) ?></span> on hand</div>
    </div>
</div>

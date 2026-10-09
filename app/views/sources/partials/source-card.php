<?php /** One source card (`source-card-{id}`). Data: s, here */
$id = (int) $s['source_id'];
$url = with_back('/sources/' . $id, $here);
?>
<div class="card h-100<?= $s['active'] ? '' : ' bg-soft-dark' ?>" id="source-card-<?= $id ?>">
    <div class="card-body">
        <div class="fw-semibold"><?= hx_link($url, e($s['name']), 'text-dark', 'id="source-card-' . $id . '-name"') ?></div>
        <div class="chip-row mt-1"><?= connector_badge($s['connector']) ?> <?= role_chip($s['role']) ?> <?= health_chip($s['health'], 'source-card-' . $id . '-health') ?></div>
        <?php if ($s['supplier_id'] !== null): ?><div class="fs-12 mt-2"><?= hx_link(with_back('/suppliers/' . $s['supplier_id'], $here), e($s['supplier_name'] ?? ''), 'text-dark') ?></div><?php endif; ?>
        <div class="fs-12 text-muted mt-2" id="source-card-<?= $id ?>-last">
            <?= $s['last_pull_at'] ? 'last pull ' . e(ago($s['last_pull_at'])) . ' · ' . e((string) $s['last_status']) : 'never pulled' ?> · <?= number_format($s['listings_live']) ?> listings
        </div>
        <div class="fs-12 mt-1"><?= number_format($s['variants_live']) ?> variants live<?= $s['variants_unmatched'] > 0 ? ' · <span class="text-warning" id="source-card-' . $id . '-unmatched">' . number_format($s['variants_unmatched']) . ' unmatched</span>' : '' ?></div>
        <div class="fs-12 text-muted mt-1"><?= e(schedule_words($s['schedule_minutes'])) ?><?= $s['next_due_at'] ? ' · next ' . e(date('M j H:i', (int) strtotime((string) $s['next_due_at']))) . ' UTC' : '' ?></div>
        <?php if ($s['paused_at'] !== null): ?><div class="fs-12 text-dark mt-1">Paused: <?= e((string) $s['paused_reason']) ?></div><?php endif; ?>
    </div>
</div>

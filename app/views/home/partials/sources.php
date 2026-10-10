<?php /** Pulls failed or blocked (`#home-sources`): failing, blocked and paused sources; each opens the source. Data: s, tz */
$a = $s['sources'];
$chip = ['failing' => 'warning', 'blocked' => 'danger', 'paused' => 'secondary'];
?>
<div class="card" id="home-sources">
    <div class="card-header"><h5 class="card-title mb-0"><i class="feather-rss me-2"></i>Pulls failed or blocked <span class="badge bg-soft-<?= $a['count'] > 0 ? 'warning text-warning' : 'secondary text-dark' ?> ms-1"><?= (int) $a['count'] ?></span></h5></div>
    <?php if ($a['rows'] === []): ?>
        <div class="card-body text-muted fs-12" id="home-sources-empty">Sources whose last pull failed, that blocked us, or that are paused appear here.</div>
    <?php else: ?>
    <div class="list-group list-group-flush">
    <?php foreach ($a['rows'] as $r): ?>
        <div class="list-group-item d-flex justify-content-between gap-2" id="home-sources-row-<?= (int) $r['source_id'] ?>">
            <div><?= hx_link(with_back('/sources/' . (int) $r['source_id'], '/'), e($r['name']), 'fw-semibold text-dark') ?><div class="fs-12 text-muted"><?= $r['last_pull_at'] !== null ? 'last pull ' . e(format_ts($r['last_pull_at'], $tz, 'M j, g:i A')) : 'never pulled' ?><?= $r['last_error'] ? ' · ' . e(mb_substr((string) $r['last_error'], 0, 80)) : '' ?></div></div>
            <span class="badge bg-soft-<?= e($chip[$r['health']] ?? 'secondary') ?> text-<?= e($chip[$r['health']] ?? 'secondary') ?> align-self-start"><?= e($r['health']) ?></span>
        </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

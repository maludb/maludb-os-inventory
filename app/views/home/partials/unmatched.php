<?php /** Unmatched listings (`#home-unmatched`, listings.match only): the count and the five newest. Data: s */
$a = $s['unmatched'];
?>
<div class="card" id="home-unmatched">
    <div class="card-header"><h5 class="card-title mb-0"><i class="feather-link me-2"></i>Unmatched listings <span class="badge bg-soft-<?= $a['count'] > 0 ? 'warning text-warning' : 'secondary text-dark' ?> ms-1" id="home-unmatched-count"><?= (int) $a['count'] ?></span></h5></div>
    <?php if ($a['rows'] === []): ?>
        <div class="card-body text-muted fs-12" id="home-unmatched-empty">Listings nothing in the catalog matches — the count and the five newest — appear here.</div>
    <?php else: ?>
    <div class="list-group list-group-flush">
    <?php foreach ($a['rows'] as $r): ?>
        <div class="list-group-item" id="home-unmatched-row-<?= (int) $r['listing_variant_id'] ?>">
            <div class="fw-semibold"><?= hx_link(with_back('/matching/', '/'), e($r['variant_title'] ?: $r['title'] ?: ($r['sku'] ?: 'a listing')), 'text-dark') ?></div>
            <div class="fs-12 text-muted"><?= e($r['source_name']) ?><?= $r['sku'] ? ' · ' . e($r['sku']) : '' ?></div>
        </div>
    <?php endforeach; ?>
    <div class="list-group-item"><?= hx_link('/matching/', 'The match queue', 'fs-12 fw-semibold') ?></div>
    </div>
    <?php endif; ?>
</div>

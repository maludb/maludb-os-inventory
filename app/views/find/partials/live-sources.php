<?php /** The live fan-out (`find-live`'s content): a placeholder card per searchable source, each loading its own answer. Data: sources, q, size */ ?>
<h6 class="mb-2">Asked live</h6>
<?php if ($sources === []): ?><div class="text-muted fs-12" id="find-live-none">No source can be asked live right now.</div><?php endif; ?>
<div class="row g-3">
<?php foreach ($sources as $s): $sid = $s['source_id']; $url = '/find/source-search?' . http_build_query(array_filter(['source' => $sid, 'q' => $q, 'size' => $size])); ?>
    <div class="col-12 col-md-6">
        <div class="card" id="find-live-<?= $sid ?>" hx-get="<?= e($url) ?>" hx-trigger="load" hx-swap="outerHTML">
            <div class="card-body d-flex align-items-center gap-2">
                <span class="fw-semibold"><?= e($s['name']) ?></span> <?= connector_badge($s['connector']) ?>
                <span class="spinner-border spinner-border-sm text-primary htmx-indicator ms-auto" role="status" aria-label="Asking"></span>
                <noscript><a href="<?= e($url) ?>" class="btn btn-light btn-sm btn-touch ms-auto">Ask</a></noscript>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>

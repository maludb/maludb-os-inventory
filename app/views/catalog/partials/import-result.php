<?php /** Step 3 of the import: the counts and the skipped rows (≤ 50). Data: result */ ?>
<div class="card" id="import-result">
    <div class="card-header"><h5 class="card-title mb-0">Step 3 — the result</h5></div>
    <div class="card-body">
        <div class="row g-3 text-center mb-3">
            <div class="col-6 col-md-3"><div class="fs-3 fw-bold" id="import-result-products"><?= (int) $result['products_created'] ?></div><div class="fs-12 text-muted">products created</div></div>
            <div class="col-6 col-md-3"><div class="fs-3 fw-bold" id="import-result-created"><?= (int) $result['variants_created'] ?></div><div class="fs-12 text-muted">variants created</div></div>
            <div class="col-6 col-md-3"><div class="fs-3 fw-bold" id="import-result-updated"><?= (int) $result['variants_updated'] ?></div><div class="fs-12 text-muted">variants updated</div></div>
            <div class="col-6 col-md-3"><div class="fs-3 fw-bold" id="import-result-skipped"><?= count($result['skipped']) ?></div><div class="fs-12 text-muted">rows skipped</div></div>
        </div>
        <?php if ($result['skipped'] !== []): ?>
        <div class="table-responsive"><table class="table mb-0 fs-12" id="import-result-skipped-table"><thead class="thead-light"><tr><th>Row</th><th>Why</th></tr></thead><tbody>
            <?php foreach (array_slice($result['skipped'], 0, 50) as $s): ?><tr><td><?= (int) $s['row'] ?></td><td><?= e($s['reason']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php if (count($result['skipped']) > 50): ?><div class="fs-12 text-muted mt-1">… and <?= count($result['skipped']) - 50 ?> more.</div><?php endif; ?>
        <?php endif; ?>
    </div>
    <div class="card-footer d-flex gap-2"><?= hx_link('/products/', 'Back to products', 'btn btn-primary btn-touch', 'id="import-result-products-btn"') ?><?= hx_link('/catalog/import', 'Import another file', 'btn btn-light btn-touch') ?></div>
</div>

<?php /** The floor models (screen `floor-model-list`). Data: rows, location, locations, mayWrite, here, notice */ ?>
<?= view('shared/header.php', ['id' => 'floor-model-list', 'title' => 'Floor models', 'crumbs' => [['Home', '/'], ['Stock', '/stock/'], ['Floor models', null]], 'back' => back_link()]) ?>
<div class="main-content" id="floor-model-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($mayWrite): ?>
    <form method="post" action="/stock/floor-model.php" hx-post="/stock/floor-model.php" hx-target="#flash" class="card mb-3" id="floor-form">
        <?= csrf_field() ?>
        <div class="card-header"><h5 class="card-title mb-0">Take a floor model in or out</h5></div>
        <div class="card-body row g-2 align-items-end">
            <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="floor-form-field-variant-open">Variant</label><?= picker_field(['id' => 'floor-form-field-variant', 'name' => 'variant', 'source' => 'variant', 'required' => true, 'params' => ['single' => '1']]) ?></div>
            <div class="col-12 col-md-3"><label class="form-label fs-12 text-muted" for="floor-form-field-location">Location</label><select name="location" id="floor-form-field-location" class="form-select btn-touch" required><option value="">Choose…</option><?php foreach ($locations as $l): ?><option value="<?= (int) $l['location_id'] ?>" <?= (int) $location === (int) $l['location_id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="floor-form-field-direction">Direction</label><select name="direction" id="floor-form-field-direction" class="form-select btn-touch"><option value="in">Take in</option><option value="out">Take out</option></select></div>
            <div class="col-6 col-md-1"><label class="form-label fs-12 text-muted" for="floor-form-field-qty">Qty</label><input type="number" name="qty" id="floor-form-field-qty" class="form-control btn-touch" min="1" value="1" inputmode="numeric"></div>
            <div class="col-12 col-md-2"><button type="submit" class="btn btn-primary btn-touch w-100" id="floor-form-save-btn">Record</button></div>
            <div class="col-12"><label class="form-label fs-12 text-muted" for="floor-form-field-note">Note</label><input type="text" name="note" id="floor-form-field-note" class="form-control btn-touch" maxlength="1000"></div>
        </div>
    </form>
    <?php endif; ?>
    <form method="get" action="/stock/floor-models" hx-get="/stock/floor-models" hx-target="#page-content" hx-push-url="true" class="mb-3" id="floor-filters">
        <select name="location" class="form-select btn-touch" id="floor-filters-location" aria-label="Location" hx-get="/stock/floor-models" hx-trigger="change" hx-target="#page-content" hx-push-url="true"><option value="">Every location</option><?php foreach ($locations as $l): ?><option value="<?= (int) $l['location_id'] ?>" <?= (int) $location === (int) $l['location_id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?></select>
        <noscript><button type="submit" class="btn btn-light btn-touch mt-2">Filter</button></noscript>
    </form>
    <div class="card" id="floor-card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="floor-table">
        <thead class="thead-light"><tr><th>SKU</th><th>Product</th><th class="d-none d-md-table-cell">Size</th><th>Location</th><th class="text-end">On the floor</th><th class="text-end">On hand</th></tr></thead><tbody>
        <?php if ($rows === []): ?><tr><td colspan="6" class="text-center text-muted py-4" id="floor-table-empty">No floor models<?= $location ? ' here' : '' ?>.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): $rid = 'floor-row-' . (int) $r['variant_id'] . '-' . (int) $r['location_id']; ?>
            <tr id="<?= $rid ?>"><td><?= hx_link(with_back('/variants/' . (int) $r['variant_id'], $here), e($r['sku']), 'fw-semibold') ?></td><td><?= e($r['product_name']) ?></td><td class="d-none d-md-table-cell"><?= e($r['size_name'] ?? '') ?></td>
                <td><?= hx_link(with_back('/locations/' . (int) $r['location_id'], $here), e($r['location_name']), 'text-dark') ?></td><td class="text-end fw-semibold" id="<?= $rid ?>-floor"><?= (int) $r['qty_floor_model'] ?></td><td class="text-end"><?= (int) $r['qty_on_hand'] ?></td></tr>
        <?php endforeach; ?>
    </tbody></table></div></div></div>
</div>

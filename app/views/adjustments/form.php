<?php /** An adjustment's header (screens `adjustment-add`, `adjustment-edit`; `adjustment-form`). Data: cur, reasons, locations, locationId, reason, variant, seesCost */
$id = $cur['adjustment_id'] ?? null;
$screen = $cur === null ? 'adjustment-add' : 'adjustment-edit';
$back = $id === null ? '/adjustments/' : '/adjustments/' . (int) $id;
?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'New adjustment' : 'Change ' . $cur['number'], 'crumbs' => array_merge([['Home', '/'], ['Adjustments', '/adjustments/']], $cur === null ? [['New', null]] : [[$cur['number'], $back], ['Edit', null]]), 'back' => back_link() ?? [$back, $cur === null ? 'Adjustments' : $cur['number']]]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/adjustments/save.php" hx-post="/adjustments/save.php" hx-target="#flash" id="adjustment-form" class="card">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="adjustment" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="card-body row g-2">
            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="adjustment-form-field-location">Location</label>
                <select name="location" id="adjustment-form-field-location" class="form-select btn-touch" required><option value="">Choose…</option><?php foreach ($locations as $l): ?><option value="<?= (int) $l['location_id'] ?>" <?= (int) $locationId === (int) $l['location_id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?></select>
                <?php if ($cur !== null && (int) $cur['line_count'] > 0): ?><div class="fs-12 text-muted mt-1">The location changes only while the adjustment has no lines.</div><?php endif; ?></div>
            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="adjustment-form-field-reason">Reason</label>
                <select name="reason" id="adjustment-form-field-reason" class="form-select btn-touch" required><option value="">Choose…</option><?php foreach ($reasons as $rc): ?><option value="<?= e($rc['code']) ?>" <?= $reason === $rc['code'] ? 'selected' : '' ?>><?= e($rc['name']) ?><?= $rc['affects_qty'] ? '' : ' (moves the floor flag, not the count)' ?></option><?php endforeach; ?></select></div>
            <?php if ($variant !== null): ?>
            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted">Variant</label><div class="form-control btn-touch bg-light" id="adjustment-form-variant"><?= e($variant['sku']) ?></div><input type="hidden" name="variant" value="<?= (int) $variant['variant_id'] ?>"></div>
            <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="adjustment-form-field-qty_delta">Change (+/−)</label><input type="number" name="qty_delta" id="adjustment-form-field-qty_delta" class="form-control btn-touch" required autofocus placeholder="-1"></div>
            <?php if ($seesCost): ?><div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="adjustment-form-field-unit_cost">Unit cost</label><input type="text" name="unit_cost" id="adjustment-form-field-unit_cost" class="form-control btn-touch" inputmode="decimal"></div><?php endif; ?>
            <?php endif; ?>
            <div class="col-12"><label class="form-label fs-12 text-muted" for="adjustment-form-field-notes">Notes</label><textarea name="notes" id="adjustment-form-field-notes" class="form-control" rows="2" maxlength="2000"><?= e($cur['notes'] ?? '') ?></textarea></div>
        </div>
        <div class="card-footer d-flex gap-2"><button type="submit" class="btn btn-primary btn-touch" id="adjustment-form-save-btn"><?= $cur === null ? 'Create' : 'Save' ?></button><?= hx_link($back, 'Cancel', 'btn btn-light btn-touch', 'id="adjustment-form-cancel-link"') ?></div>
    </form>
</div>

<?php /** Make or change a location (screens `location-add`, `location-edit`). Data: cur, departments */
$id = $cur['location_id'] ?? null;
$screen = $cur === null ? 'location-add' : 'location-edit';
$back = $id === null ? '/locations/' : '/locations/' . (int) $id;
?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'New location' : 'Change ' . $cur['name'], 'crumbs' => array_merge([['Home', '/'], ['Locations', '/locations/']], $cur === null ? [['New', null]] : [[$cur['name'], $back], ['Edit', null]]), 'back' => back_link() ?? [$back, $cur === null ? 'Locations' : $cur['name']]]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/locations/save.php" hx-post="/locations/save.php" hx-target="#flash" id="location-form" class="card">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="location" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="card-body">
            <label class="form-label fs-12 text-muted" for="location-form-field-name">Name</label>
            <input type="text" name="name" id="location-form-field-name" class="form-control btn-touch" maxlength="120" required value="<?= e($cur['name'] ?? '') ?>">
            <label class="form-label fs-12 text-muted mt-3" for="location-form-field-kind">Kind</label>
            <select name="kind" id="location-form-field-kind" class="form-select btn-touch"><?php foreach (LOCATION_KINDS as $k => $w): ?><option value="<?= $k ?>" <?= ($cur['kind'] ?? 'warehouse') === $k ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select>
            <label class="form-label fs-12 text-muted mt-3" for="location-form-field-address">Address</label>
            <textarea name="address" id="location-form-field-address" class="form-control" rows="3" maxlength="1000"><?= e($cur['address'] ?? '') ?></textarea>
            <label class="form-label fs-12 text-muted mt-3" for="location-form-field-department">The department that runs it</label>
            <select name="department" id="location-form-field-department" class="form-select btn-touch"><option value="">— none —</option><?php foreach ($departments as $d): ?><option value="<?= (int) $d['department_id'] ?>" <?= (int) ($cur['department_id'] ?? 0) === (int) $d['department_id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select>
            <input type="hidden" name="is_sellable" value="no">
            <label class="d-flex align-items-center gap-2 border rounded px-3 mt-3 btn-touch" for="location-form-field-is_sellable"><input type="checkbox" class="form-check-input mt-0" name="is_sellable" value="yes" id="location-form-field-is_sellable" <?= ($cur['is_sellable'] ?? true) ? 'checked' : '' ?>>Sellable — stock here may be allocated to a sale</label>
            <input type="hidden" name="allow_negative" value="no">
            <label class="d-flex align-items-center gap-2 border rounded px-3 mt-2 btn-touch" for="location-form-field-allow_negative"><input type="checkbox" class="form-check-input mt-0" name="allow_negative" value="yes" id="location-form-field-allow_negative" <?= ($cur['allow_negative'] ?? false) ? 'checked' : '' ?>>Allow negative stock</label>
            <?php if ($cur !== null): ?>
            <input type="hidden" name="active" value="no">
            <label class="d-flex align-items-center gap-2 border rounded px-3 mt-2 btn-touch" for="location-form-field-active"><input type="checkbox" class="form-check-input mt-0" name="active" value="yes" id="location-form-field-active" <?= $cur['active'] ? 'checked' : '' ?>>Active</label>
            <?php endif; ?>
        </div>
        <div class="card-footer d-flex gap-2"><button type="submit" class="btn btn-primary btn-touch" id="location-form-save-btn"><?= $cur === null ? 'Make the location' : 'Save' ?></button><?= hx_link($back, 'Cancel', 'btn btn-light btn-touch', 'id="location-form-cancel-link"') ?></div>
    </form>
</div>

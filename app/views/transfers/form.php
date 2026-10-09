<?php /** A transfer's header (screens `transfer-add`, `transfer-edit`; `transfer-form`). Data: cur, locations, variant, fromId, toId */
$id = $cur['transfer_id'] ?? null;
$screen = $cur === null ? 'transfer-add' : 'transfer-edit';
$back = $id === null ? '/transfers/' : '/transfers/' . (int) $id;
$sel = static function (string $name, ?int $chosen) use ($locations): string {
    $h = '<option value="">Choose…</option>';
    foreach ($locations as $l) { $h .= '<option value="' . (int) $l['location_id'] . '"' . ((int) $chosen === (int) $l['location_id'] ? ' selected' : '') . '>' . e($l['name']) . '</option>'; }
    return $h;
};
?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'New transfer' : 'Change ' . $cur['number'], 'crumbs' => array_merge([['Home', '/'], ['Transfers', '/transfers/']], $cur === null ? [['New', null]] : [[$cur['number'], $back], ['Edit', null]]), 'back' => back_link() ?? [$back, $cur === null ? 'Transfers' : $cur['number']]]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/transfers/save.php" hx-post="/transfers/save.php" hx-target="#flash" id="transfer-form" class="card">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="transfer" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="card-body row g-2">
            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="transfer-form-field-from_location">From</label><select name="from_location" id="transfer-form-field-from_location" class="form-select btn-touch" required><?= $sel('from_location', $fromId === null ? null : (int) $fromId) ?></select></div>
            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="transfer-form-field-to_location">To</label><select name="to_location" id="transfer-form-field-to_location" class="form-select btn-touch" required><?= $sel('to_location', $toId === null ? null : (int) $toId) ?></select></div>
            <?php if ($variant !== null): ?>
            <div class="col-8 col-md-6"><label class="form-label fs-12 text-muted">Variant</label><div class="form-control btn-touch bg-light" id="transfer-form-variant"><?= e($variant['sku']) ?></div><input type="hidden" name="variant" value="<?= (int) $variant['variant_id'] ?>"></div>
            <div class="col-4 col-md-2"><label class="form-label fs-12 text-muted" for="transfer-form-field-qty">Qty</label><input type="number" name="qty" id="transfer-form-field-qty" class="form-control btn-touch" min="1" value="1" inputmode="numeric"></div>
            <?php endif; ?>
            <div class="col-12"><label class="form-label fs-12 text-muted" for="transfer-form-field-notes">Notes</label><textarea name="notes" id="transfer-form-field-notes" class="form-control" rows="2" maxlength="2000"><?= e($cur['notes'] ?? '') ?></textarea></div>
        </div>
        <div class="card-footer d-flex gap-2"><button type="submit" class="btn btn-primary btn-touch" id="transfer-form-save-btn"><?= $cur === null ? 'Create' : 'Save' ?></button><?= hx_link($back, 'Cancel', 'btn btn-light btn-touch', 'id="transfer-form-cancel-link"') ?></div>
    </form>
</div>

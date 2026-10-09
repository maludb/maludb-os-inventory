<?php /** Make or change a variant (screens `variant-add`, `variant-edit`). Data: product, cur, own (variant_own_columns), vocab, mayCost, here, errors? */
$id = $cur['variant_id'] ?? null;
$pid = (int) $product['product_id'];
$screen = $cur === null ? 'variant-add' : 'variant-edit';
$back = $cur === null ? ['/products/' . $pid, $product['name']] : ['/variants/' . (int) $id, $cur['sku']];
$errors = $errors ?? [];
$units = $vocab['units'];
$values = $cur['option_values'] ?? [];
$err = static fn (string $k): string => isset($errors[$k]) ? '<div class="invalid-feedback d-block" id="variant-form-error-' . e($k) . '">' . e($errors[$k]) . '</div>' : '';
$sizeNames = array_column($vocab['sizes'], 'name');
?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'New variant of ' . $product['name'] : 'Change ' . $cur['sku'], 'crumbs' => [['Home', '/'], ['Products', '/products/'], [$product['name'], '/products/' . $pid], [$cur === null ? 'New variant' : $cur['sku'], $cur === null ? null : '/variants/' . (int) $id]], 'back' => back_link() ?? $back]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/variants/save.php" hx-post="/variants/save.php" hx-target="#flash" id="variant-form">
        <?= csrf_field() ?><input type="hidden" name="product" value="<?= $pid ?>"><?php if ($id !== null): ?><input type="hidden" name="variant" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0"><?= e($product['name']) ?><?= $product['brand'] ? ' · ' . e($product['brand']) : '' ?></h5></div><div class="card-body">
            <div class="row g-2">
                <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="variant-form-field-sku">SKU</label><input type="text" name="sku" id="variant-form-field-sku" class="form-control btn-touch<?= isset($errors['sku']) ? ' is-invalid' : '' ?>" maxlength="100" required value="<?= e($cur['sku'] ?? ($_POST['sku'] ?? '')) ?>"><?= $err('sku') ?></div>
                <?php foreach ($product['options'] as $opt): $fid = 'variant-form-field-option-' . preg_replace('/[^a-z0-9]+/i', '-', strtolower($opt)); $val = (string) ($values[$opt] ?? ($_POST['option'][$opt] ?? '')); ?>
                <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="<?= e($fid) ?>"><?= e($opt) ?></label>
                    <?php if (strcasecmp($opt, 'Size') === 0): $known = in_array($val, $sizeNames, true); ?>
                        <select name="option[<?= e($opt) ?>]" id="<?= e($fid) ?>" class="form-select btn-touch<?= isset($errors['option_values']) ? ' is-invalid' : '' ?>" data-other="<?= e($fid) ?>-other"><option value="">Choose…</option><?php foreach ($sizeNames as $s): ?><option value="<?= e($s) ?>" <?= $val === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?><option value="__other" <?= $val !== '' && !$known ? 'selected' : '' ?>>Other…</option></select>
                        <input type="text" name="option_other[<?= e($opt) ?>]" id="<?= e($fid) ?>-other" class="form-control btn-touch mt-1" maxlength="60" placeholder="The size, in words" value="<?= e($val !== '' && !$known ? $val : '') ?>" <?= $val !== '' && !$known ? '' : 'hidden' ?>>
                    <?php else: ?>
                        <input type="text" name="option[<?= e($opt) ?>]" id="<?= e($fid) ?>" class="form-control btn-touch" maxlength="60" value="<?= e($val) ?>">
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?= $err('option_values') ?>
            </div>
            <div class="row g-2 mt-2">
                <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="variant-form-field-barcode">Barcode (GTIN-8/12/13/14)</label><input type="text" name="barcode" id="variant-form-field-barcode" class="form-control btn-touch<?= isset($errors['barcode']) ? ' is-invalid' : '' ?>" inputmode="numeric" value="<?= e($cur['barcode'] ?? ($_POST['barcode'] ?? '')) ?>"><?= $err('barcode') ?></div>
                <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="variant-form-field-mpn">MPN</label><input type="text" name="mpn" id="variant-form-field-mpn" class="form-control btn-touch" maxlength="100" value="<?= e($cur['mpn'] ?? ($_POST['mpn'] ?? '')) ?>"></div>
                <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="variant-form-field-ships_how">How it ships</label><select name="ships_how" id="variant-form-field-ships_how" class="form-select btn-touch"><option value="">Inherit (<?= e($product['ships_how'] ? SHIPS_HOW[$product['ships_how']] : 'not set') ?>)</option><?php foreach (SHIPS_HOW as $k => $w): ?><option value="<?= $k ?>" <?= ($own['ships_how_own'] ?? ($_POST['ships_how'] ?? '')) === $k ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
            </div>
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Weight and dimensions (<?= $units === 'metric' ? 'grams and millimetres' : 'pounds and inches' ?>)</h5></div><div class="card-body row g-2">
            <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="variant-form-field-weight">Weight (<?= $units === 'metric' ? 'g' : 'lb' ?>)</label><input type="number" step="<?= $units === 'metric' ? '1' : '0.01' ?>" min="0" name="weight" id="variant-form-field-weight" class="form-control btn-touch" value="<?= e(weight_form_value($cur['weight_g'] ?? null, $units)) ?>"><?= $err('weight') ?></div>
            <?php foreach (['length' => 'Length', 'width' => 'Width', 'height' => 'Height'] as $k => $label): ?>
            <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="variant-form-field-<?= $k ?>"><?= $label ?> (<?= $units === 'metric' ? 'mm' : 'in' ?>)</label><input type="number" step="<?= $units === 'metric' ? '1' : '0.1' ?>" min="0" name="<?= $k ?>" id="variant-form-field-<?= $k ?>" class="form-control btn-touch" value="<?= e(length_form_value($cur[$k . '_mm'] ?? null, $units)) ?>"><?= $err($k) ?></div>
            <?php endforeach; ?>
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Prices (<?= e($vocab['currency']) ?>)</h5></div><div class="card-body row g-2">
            <?php if ($cur === null): ?>
                <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="variant-form-field-retail_price">Retail</label><input type="number" step="0.01" min="0" name="retail_price" id="variant-form-field-retail_price" class="form-control btn-touch" value="<?= e($_POST['retail_price'] ?? '') ?>"><?= $err('retail_price') ?></div>
                <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="variant-form-field-map_price">MAP</label><input type="number" step="0.01" min="0" name="map_price" id="variant-form-field-map_price" class="form-control btn-touch" value="<?= e($_POST['map_price'] ?? '') ?>"><?= $err('map_price') ?></div>
                <?php if ($mayCost): ?><div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="variant-form-field-cost_price">Cost</label><input type="number" step="0.01" min="0" name="cost_price" id="variant-form-field-cost_price" class="form-control btn-touch" value="<?= e($_POST['cost_price'] ?? '') ?>"><?= $err('cost_price') ?></div><?php endif; ?>
                <div class="col-12 fs-12 text-muted">The prices on a create are remembered as "created"; later changes carry a reason on the variant's page.</div>
            <?php else: ?>
                <div class="col-12 fs-12" id="variant-form-prices-readonly">Retail <strong><?= money($cur['retail_price']) ?></strong> · MAP <strong><?= money($cur['map_price']) ?></strong> · Cost <strong><?= $cur['cost_withheld'] ? '—' : money($cur['cost_price']) ?></strong> — <span class="text-muted">set on the variant's page with a reason.</span></div>
            <?php endif; ?>
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Reorder</h5></div><div class="card-body row g-2">
            <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="variant-form-field-reorder_point">Reorder point</label><input type="number" min="0" name="reorder_point" id="variant-form-field-reorder_point" class="form-control btn-touch" placeholder="<?= e((string) ($product['reorder_point'] ?? 'the product\'s')) ?>" value="<?= e((string) ($own['reorder_point_own'] ?? ($_POST['reorder_point'] ?? ''))) ?>"><?= $err('reorder_point') ?></div>
            <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="variant-form-field-reorder_qty">Reorder quantity</label><input type="number" min="1" name="reorder_qty" id="variant-form-field-reorder_qty" class="form-control btn-touch" value="<?= e((string) ($cur['reorder_qty'] ?? ($_POST['reorder_qty'] ?? ''))) ?>"><?= $err('reorder_qty') ?></div>
            <?php if ($cur !== null): ?><div class="col-12 col-md-6"><input type="hidden" name="active" value="no"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-0 mt-md-4" for="variant-form-field-active"><input type="checkbox" class="form-check-input mt-0" name="active" value="yes" id="variant-form-field-active" <?= $cur['active'] ? 'checked' : '' ?>>Active — sold and matched</label></div><?php endif; ?>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="variant-form-save-btn"><?= $cur === null ? 'Make the variant' : 'Save' ?></button>
        <?= hx_link($back[0], 'Cancel', 'btn btn-light btn-touch w-100', 'id="variant-form-cancel-link"') ?>
    </form>
</div>
<script>
(function () {
    document.querySelectorAll('#variant-form select[data-other]').forEach(function (s) {
        var other = document.getElementById(s.dataset.other); if (!other) return;
        s.addEventListener('change', function () { if (s.value === '__other') { other.removeAttribute('hidden'); other.focus(); } else { other.setAttribute('hidden', ''); other.value = ''; } });
    });
})();
</script>

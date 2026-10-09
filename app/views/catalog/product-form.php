<?php /** Make or change a product (screens `product-add`, `product-edit`). Data: cur, vocab, types, prefill (brand, type), here, errors? */
$id = $cur['product_id'] ?? null;
$screen = $cur === null ? 'product-add' : 'product-edit';
$back = $cur === null ? ['/products/', 'Products'] : ['/products/' . (int) $id, $cur['name']];
$errors = $errors ?? [];
$attrs = $cur['attributes'] ?? [];
$options = $cur['options'] ?? ['Size'];
$typeNow = $cur['product_type_id'] ?? ($prefill['type'] ?? null);
$brandNow = $cur['brand_id'] ?? ($prefill['brand'] ?? null);
$err = static fn (string $k): string => isset($errors[$k]) ? '<div class="invalid-feedback d-block" id="product-form-error-' . e($k) . '">' . e($errors[$k]) . '</div>' : '';
?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'New product' : 'Change ' . $cur['name'], 'crumbs' => [['Home', '/'], ['Products', '/products/'], [$cur === null ? 'New' : $cur['name'], $cur === null ? null : '/products/' . (int) $id]], 'back' => back_link() ?? $back]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/products/save.php" hx-post="/products/save.php" hx-target="#flash" id="product-form">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="product" value="<?= (int) $id ?>"><?php endif; ?><input type="hidden" name="return_to" value="">
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">The product</h5></div><div class="card-body">
            <label class="form-label fs-12 text-muted" for="product-form-field-name">Name</label>
            <input type="text" name="name" id="product-form-field-name" class="form-control btn-touch mb-1<?= isset($errors['name']) ? ' is-invalid' : '' ?>" maxlength="200" required value="<?= e($cur['name'] ?? ($_POST['name'] ?? '')) ?>"><?= $err('name') ?>
            <div class="row g-2 mt-2">
                <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="product-form-field-brand-open">Brand</label><?= picker_field(['id' => 'product-form-field-brand', 'name' => 'brand', 'source' => 'brand', 'value' => $brandNow, 'placeholder' => '— none —', 'invalid' => isset($errors['brand']), 'create' => ['label' => 'New brand', 'url' => '/brands/new']]) ?><?= $err('brand') ?></div>
                <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="product-form-field-type">Type</label>
                    <select name="type" id="product-form-field-type" class="form-select btn-touch<?= isset($errors['type']) ? ' is-invalid' : '' ?>" required><option value="">Choose…</option><?php foreach ($types as $t): ?><option value="<?= $t['product_type_id'] ?>" <?= (string) $typeNow === (string) $t['product_type_id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select><?= $err('type') ?></div>
            </div>
            <label class="form-label fs-12 text-muted mt-3" for="product-form-field-description">Description</label>
            <textarea name="description" id="product-form-field-description" class="form-control" rows="4" maxlength="4000"><?= e($cur['description'] ?? ($_POST['description'] ?? '')) ?></textarea>
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Attributes</h5></div><div class="card-body" id="product-form-attributes">
            <?php if ($vocab['attribute_keys'] === []): ?><div class="fs-12 text-muted">No attribute keys are declared in the settings.</div><?php endif; ?>
            <div class="row g-2">
            <?php foreach ($vocab['attribute_keys'] as $a): $k = (string) $a['key']; $v = $attrs[$k] ?? ($_POST['attr'][$k] ?? null); $fid = 'product-form-field-attr-' . $k; ?>
                <div class="col-12 col-md-6">
                    <label class="form-label fs-12 text-muted" for="<?= e($fid) ?>"><?= e($a['name'] ?? $k) ?></label>
                    <?php if (($a['kind'] ?? 'text') === 'choice'): ?>
                        <select name="attr[<?= e($k) ?>]" id="<?= e($fid) ?>" class="form-select btn-touch"><option value="">—</option><?php foreach ($a['choices'] ?? [] as $c): ?><option value="<?= e($c) ?>" <?= (string) $v === (string) $c ? 'selected' : '' ?>><?= e(str_replace('_', ' ', (string) $c)) ?></option><?php endforeach; ?></select>
                    <?php elseif (($a['kind'] ?? 'text') === 'multi'): ?>
                        <select name="attr[<?= e($k) ?>][]" id="<?= e($fid) ?>" class="form-select" multiple size="<?= min(5, count($a['choices'] ?? [])) ?>"><?php foreach ($a['choices'] ?? [] as $c): ?><option value="<?= e($c) ?>" <?= in_array((string) $c, array_map('strval', (array) ($v ?? [])), true) ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select>
                    <?php elseif (($a['kind'] ?? 'text') === 'number'): ?>
                        <input type="number" step="any" name="attr[<?= e($k) ?>]" id="<?= e($fid) ?>" class="form-control btn-touch" value="<?= e((string) ($v ?? '')) ?>">
                    <?php else: ?>
                        <input type="text" name="attr[<?= e($k) ?>]" id="<?= e($fid) ?>" class="form-control btn-touch" maxlength="200" value="<?= e((string) ($v ?? '')) ?>">
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div>
            <?= $err('attributes') ?>
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Kind and options</h5></div><div class="card-body">
            <div class="fs-12 text-muted mb-1">Kind</div>
            <?php foreach (['single' => 'Single — a product sold as itself, in sizes', 'bundle' => 'Bundle — a set whose variants list component variants (a Queen set: mattress + foundation)'] as $k => $words): ?>
                <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="product-form-field-kind-<?= $k ?>"><input type="radio" class="form-check-input mt-0" name="kind" value="<?= $k ?>" id="product-form-field-kind-<?= $k ?>" <?= ($cur['kind'] ?? ($_POST['kind'] ?? 'single')) === $k ? 'checked' : '' ?>><span class="fs-12"><?= e($words) ?></span></label>
            <?php endforeach; ?>
            <?= $err('kind') ?>
            <div class="fs-12 text-muted mb-1 mt-3">Options — the names a variant is chosen by; Size first</div>
            <div id="product-form-options">
                <?php foreach ([0, 1, 2] as $n): $val = $options[$n] ?? ''; ?>
                <div class="mb-2 product-form-option-row" <?= $n > 0 && $val === '' ? 'hidden' : '' ?>>
                    <input type="text" name="options[]" id="product-form-field-option-<?= $n ?>" class="form-control btn-touch" maxlength="40" value="<?= e($n === 0 ? 'Size' : $val) ?>" <?= $n === 0 ? 'readonly' : '' ?> placeholder="<?= $n === 0 ? 'Size' : 'Thickness, Firmness…' ?>">
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-light btn-touch" id="product-form-add-option-btn">Add an option</button>
            <?= $err('options') ?>
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Shipping and reorder</h5></div><div class="card-body row g-2">
            <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="product-form-field-ships_how">How it ships</label>
                <select name="ships_how" id="product-form-field-ships_how" class="form-select btn-touch"><option value="">— not set —</option><?php foreach (SHIPS_HOW as $k => $w): ?><option value="<?= $k ?>" <?= ($cur['ships_how'] ?? ($_POST['ships_how'] ?? '')) === $k ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select><?= $err('ships_how') ?></div>
            <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="product-form-field-reorder_point">Reorder point (the variants' default)</label><input type="number" min="0" name="reorder_point" id="product-form-field-reorder_point" class="form-control btn-touch" value="<?= e((string) ($cur['reorder_point'] ?? ($_POST['reorder_point'] ?? ''))) ?>"><?= $err('reorder_point') ?></div>
            <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="product-form-field-tags">Tags (comma-separated)</label><input type="text" name="tags" id="product-form-field-tags" class="form-control btn-touch" value="<?= e(implode(', ', $cur['tags'] ?? [])) ?>"><?= $err('tags') ?></div>
        </div></div>
        <?php if ($cur !== null && $cur['status'] !== 'discontinued'): ?>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Status</h5></div><div class="card-body">
            <?php foreach (['draft' => 'Draft — not yet sold, not found by Find', 'active' => 'Active — sold and found'] as $k => $words): ?>
                <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="product-form-field-status-<?= $k ?>"><input type="radio" class="form-check-input mt-0" name="status" value="<?= $k ?>" id="product-form-field-status-<?= $k ?>" <?= $cur['status'] === $k ? 'checked' : '' ?>><span class="fs-12"><?= e($words) ?></span></label>
            <?php endforeach; ?>
            <div class="fs-12 text-muted">Discontinued is set by <strong>Discontinue</strong> on the product's page, with a confirmation.</div><?= $err('status') ?>
        </div></div>
        <?php elseif ($cur === null): ?><input type="hidden" name="status" value="<?= e($_POST['status'] ?? 'draft') ?>"><?php endif; ?>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="product-form-save-btn"><?= $cur === null ? 'Make the product' : 'Save' ?></button>
        <?= hx_link($back[0], 'Cancel', 'btn btn-light btn-touch w-100', 'id="product-form-cancel-link"') ?>
    </form>
</div>
<script>
(function () {
    var btn = document.getElementById('product-form-add-option-btn');
    if (!btn) return;
    btn.addEventListener('click', function () {
        var rows = document.querySelectorAll('#product-form-options .product-form-option-row[hidden]');
        if (rows.length) { rows[0].removeAttribute('hidden'); rows[0].querySelector('input').focus(); }
        if (rows.length <= 1) { btn.setAttribute('disabled', ''); }
    });
    if (!document.querySelector('#product-form-options .product-form-option-row[hidden]')) { btn.setAttribute('disabled', ''); }
})();
</script>

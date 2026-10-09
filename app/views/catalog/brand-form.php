<?php /** Make or change a brand (screens `brand-add`, `brand-edit`). Data: cur, errors? */
$id = $cur['brand_id'] ?? null;
$screen = $cur === null ? 'brand-add' : 'brand-edit';
$errors = $errors ?? [];
$err = static fn (string $k): string => isset($errors[$k]) ? '<div class="invalid-feedback d-block" id="brand-form-error-' . e($k) . '">' . e($errors[$k]) . '</div>' : '';
?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'New brand' : 'Change ' . $cur['name'], 'crumbs' => [['Home', '/'], ['Brands', '/brands/'], [$cur === null ? 'New' : $cur['name'], null]], 'back' => back_link() ?? ['/brands/', 'Brands']]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/brands/save.php" hx-post="/brands/save.php" hx-target="#flash" id="brand-form" class="card">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="brand" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="card-body">
            <label class="form-label fs-12 text-muted" for="brand-form-field-name">Name</label>
            <input type="text" name="name" id="brand-form-field-name" class="form-control btn-touch mb-1<?= isset($errors['name']) ? ' is-invalid' : '' ?>" maxlength="120" required value="<?= e($cur['name'] ?? ($_POST['name'] ?? '')) ?>"><?= $err('name') ?>
            <label class="form-label fs-12 text-muted mt-3" for="brand-form-field-website">Website</label>
            <input type="url" name="website" id="brand-form-field-website" class="form-control btn-touch mb-1" maxlength="300" placeholder="https://" value="<?= e($cur['website'] ?? ($_POST['website'] ?? '')) ?>"><?= $err('website') ?>
            <label class="form-label fs-12 text-muted mt-3" for="brand-form-field-supplier-open">The supplier that is its dealer program (optional)</label>
            <?= picker_field(['id' => 'brand-form-field-supplier', 'name' => 'supplier', 'source' => 'supplier', 'value' => $cur['supplier_id'] ?? ($_POST['supplier'] ?? null), 'placeholder' => '— none —', 'invalid' => isset($errors['supplier'])]) ?><?= $err('supplier') ?>
            <input type="hidden" name="active" value="no">
            <label class="d-flex align-items-center gap-2 border rounded px-3 mt-3 btn-touch" for="brand-form-field-active"><input type="checkbox" class="form-check-input mt-0" name="active" value="yes" id="brand-form-field-active" <?= ($cur['active'] ?? true) ? 'checked' : '' ?>>Active</label>
        </div>
        <div class="card-footer d-flex gap-2"><button type="submit" class="btn btn-primary btn-touch" id="brand-form-save-btn"><?= $cur === null ? 'Make the brand' : 'Save' ?></button><?= hx_link('/brands/', 'Cancel', 'btn btn-light btn-touch', 'id="brand-form-cancel-link"') ?></div>
    </form>
</div>

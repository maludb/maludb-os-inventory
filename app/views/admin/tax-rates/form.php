<?php /** Add or change a tax rate (screens `tax-rate-add`, `tax-rate-edit`; the form `tax-rate-form`). Data: cur */
$id = $cur['tax_rate_id'] ?? null;
$screen = $cur === null ? 'tax-rate-add' : 'tax-rate-edit';
$back = '/admin/tax-rates/';
$v = static fn (string $k, $d = '') => e($cur[$k] ?? $d);
?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'Add a tax rate' : 'Change ' . $cur['name'], 'crumbs' => [['Home', '/'], ['Tax rates', $back], [$cur === null ? 'New' : $cur['name'], null]], 'back' => back_link() ?? [$back, 'Tax rates']]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/admin/tax-rates/save.php" hx-post="/admin/tax-rates/save.php" hx-target="#flash" id="tax-rate-form" class="card">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="tax_rate" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="page-header-form d-flex align-items-center justify-content-between gap-2 px-3 py-2 border-bottom" id="tax-rate-form-header">
            <div class="fw-semibold"><?= $cur === null ? 'A new tax rate' : e($cur['name']) ?></div>
            <div class="d-flex gap-2"><?= hx_link($back, 'Cancel', 'btn btn-light btn-touch', 'id="tax-rate-form-cancel-btn"') ?><button type="submit" class="btn btn-primary btn-touch" id="tax-rate-form-save-btn">Save</button></div>
        </div>
        <div class="card-body row g-2">
            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="tax-rate-form-field-name">Name</label><input type="text" name="name" id="tax-rate-form-field-name" class="form-control btn-touch" maxlength="80" required placeholder="Cook County 10.25 %" value="<?= $v('name') ?>"><div class="fs-11 text-muted mt-1">Unique among the live rates.</div></div>
            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="tax-rate-form-field-rate">Rate (%)</label><input type="text" name="rate" id="tax-rate-form-field-rate" class="form-control btn-touch" inputmode="decimal" required placeholder="10.2500" value="<?= $cur === null ? '' : e(rtrim(rtrim($cur['rate'], '0'), '.') ?: '0') ?>"><div class="fs-11 text-muted mt-1">A percent from 0 to 99.9999, four decimals at most.</div></div>
            <div class="col-12">
                <input type="hidden" name="is_default" value="no">
                <label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch" for="tax-rate-form-field-is_default"><input type="checkbox" class="form-check-input mt-0" name="is_default" value="yes" id="tax-rate-form-field-is_default" <?= !empty($cur['is_default']) ? 'checked' : '' ?>>The default rate</label>
                <div class="fs-11 text-muted mt-1">The default applies to a new order with no rate of its own. Making this the default takes it from the one that has it.</div>
            </div>
        </div>
    </form>
</div>

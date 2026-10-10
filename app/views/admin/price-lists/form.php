<?php /** Add or change a price list (screens `price-list-add`, `price-list-edit`; the form `price-list-form`). Data: cur */
$id = $cur['price_list_id'] ?? null;
$screen = $cur === null ? 'price-list-add' : 'price-list-edit';
$back = '/admin/price-lists/';
$v = static fn (string $k, $d = '') => e($cur[$k] ?? $d);
?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'Add a price list' : 'Change ' . $cur['name'], 'crumbs' => [['Home', '/'], ['Price lists', $back], [$cur === null ? 'New' : $cur['name'], null]], 'back' => back_link() ?? [$back, 'Price lists']]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/admin/price-lists/save.php" hx-post="/admin/price-lists/save.php" hx-target="#flash" id="price-list-form" class="card">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="price_list" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="page-header-form d-flex align-items-center justify-content-between gap-2 px-3 py-2 border-bottom" id="price-list-form-header">
            <div class="fw-semibold"><?= $cur === null ? 'A new price list' : e($cur['name']) ?></div>
            <div class="d-flex gap-2"><?= hx_link($back, 'Cancel', 'btn btn-light btn-touch', 'id="price-list-form-cancel-btn"') ?><button type="submit" class="btn btn-primary btn-touch" id="price-list-form-save-btn">Save</button></div>
        </div>
        <div class="card-body row g-2">
            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="price-list-form-field-name">Name</label><input type="text" name="name" id="price-list-form-field-name" class="form-control btn-touch" maxlength="80" required placeholder="Dealer 20" value="<?= $v('name') ?>"></div>
            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="price-list-form-field-percent_off_retail">Percent off retail</label><input type="text" name="percent_off_retail" id="price-list-form-field-percent_off_retail" class="form-control btn-touch" inputmode="decimal" required placeholder="20.00" value="<?= $v('percent_off_retail') ?>"><div class="fs-11 text-muted mt-1">0 to 99.99, two decimals at most.</div></div>
            <div class="col-12"><label class="form-label fs-12 text-muted" for="price-list-form-field-notes">Notes</label><textarea name="notes" id="price-list-form-field-notes" class="form-control" rows="3" maxlength="2000"><?= $v('notes') ?></textarea></div>
            <div class="col-12">
                <input type="hidden" name="active" value="no">
                <label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch" for="price-list-form-field-active"><input type="checkbox" class="form-check-input mt-0" name="active" value="yes" id="price-list-form-field-active" <?= $cur === null || $cur['active'] ? 'checked' : '' ?>>Active</label>
                <div class="fs-11 text-muted mt-1">An inactive list stops pricing its keys' answers: the partner price becomes the retail price.</div>
            </div>
        </div>
    </form>
</div>

<?php /** A supplier's price sheet (screen `supplier-item-list`). Data: rows, supplier, suppliers, q, mayWrite, seesCost, here, notice */ ?>
<?= view('shared/header.php', ['id' => 'supplier-item-list', 'title' => 'Price sheets', 'crumbs' => [['Home', '/'], ['Sources', '/sources/'], ['Price sheets', null]], 'back' => back_link()]) ?>
<div class="main-content" id="supplier-item-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/supplier-items/" hx-get="/supplier-items/" hx-target="#page-content" hx-push-url="true" class="row g-2 mb-3" id="supplier-item-filters">
        <div class="col-12 col-md-5"><select name="supplier" class="form-select btn-touch" id="supplier-item-filter-supplier" aria-label="Supplier"><?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['supplier_id'] ?>" <?= (int) $supplier === (int) $s['supplier_id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-8 col-md-5"><input type="search" name="q" class="form-control btn-touch" placeholder="SKU or product" value="<?= e($q) ?>" id="supplier-item-filter-q" aria-label="Search"></div>
        <div class="col-4 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="supplier-item-filter-btn">Show</button></div>
    </form>
    <?php if ($mayWrite && $supplier !== null): ?>
    <form method="post" action="/supplier-items/save.php" hx-post="/supplier-items/save.php" hx-target="#flash" class="card mb-3" id="supplier-item-form">
        <?= csrf_field() ?><input type="hidden" name="supplier" value="<?= (int) $supplier ?>">
        <div class="card-header"><h5 class="card-title mb-0">Add a row</h5></div>
        <div class="card-body row g-2 align-items-end">
            <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="supplier-item-form-field-variant-open">Our variant</label><?= picker_field(['id' => 'supplier-item-form-field-variant', 'name' => 'variant', 'source' => 'variant', 'params' => ['single' => '1'], 'required' => true]) ?></div>
            <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="supplier-item-form-field-supplier_sku">Their SKU</label><input type="text" name="supplier_sku" id="supplier-item-form-field-supplier_sku" class="form-control btn-touch" maxlength="100"></div>
            <?php if ($seesCost): ?><div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="supplier-item-form-field-cost">Cost</label><input type="text" name="cost" id="supplier-item-form-field-cost" class="form-control btn-touch" inputmode="decimal"></div><?php endif; ?>
            <div class="col-6 col-md-1"><label class="form-label fs-12 text-muted" for="supplier-item-form-field-lead_time_days">Lead</label><input type="number" name="lead_time_days" id="supplier-item-form-field-lead_time_days" class="form-control btn-touch" min="0"></div>
            <div class="col-6 col-md-1"><label class="form-label fs-12 text-muted" for="supplier-item-form-field-moq">MOQ</label><input type="number" name="moq" id="supplier-item-form-field-moq" class="form-control btn-touch" min="1" value="1"></div>
            <div class="col-12 col-md-2"><button type="submit" class="btn btn-primary btn-touch w-100" id="supplier-item-form-save-btn">Add</button></div>
        </div>
    </form>
    <?php endif; ?>
    <div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="supplier-items-table">
        <thead class="thead-light"><tr><th>Their SKU</th><th>Ours</th><th class="text-end">Cost</th><th class="text-end">Lead</th><th class="text-end">MOQ</th><th>Active</th><th class="d-none d-md-table-cell">Last seen</th><th class="d-none d-md-table-cell">Kept by</th><th></th></tr></thead><tbody>
        <?php if ($rows === []): ?><tr><td colspan="9" class="text-center text-muted py-4" id="supplier-items-empty">Nothing on this price sheet yet.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?><?= view('supplier_items/partials/item-row.php', ['r' => $r, 'mayWrite' => $mayWrite, 'seesCost' => $seesCost, 'here' => $here]) ?><?php endforeach; ?>
    </tbody></table></div></div></div>
</div>

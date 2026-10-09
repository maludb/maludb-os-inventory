<?php /** Product types (screen `product-type-list`): the add form at the top and a per-row inline form (Pattern C). Data: types, mayWrite, notice */ ?>
<?= view('shared/header.php', ['id' => 'product-type-list', 'title' => 'Product types', 'crumbs' => [['Home', '/'], ['Product types', null]], 'back' => back_link()]) ?>
<div class="main-content" id="product-type-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($mayWrite): ?>
    <form method="post" action="/product-types/save.php" hx-post="/product-types/save.php" hx-target="#flash" class="card mb-3" id="product-type-form">
        <?= csrf_field() ?>
        <div class="card-header"><h5 class="card-title mb-0">Add a type</h5></div>
        <div class="card-body row g-2 align-items-end">
            <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="product-type-form-field-name">Name</label><input type="text" name="name" id="product-type-form-field-name" class="form-control btn-touch" maxlength="80" required></div>
            <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="product-type-form-field-key">Key (from the name by default)</label><input type="text" name="key" id="product-type-form-field-key" class="form-control btn-touch" maxlength="40" pattern="[a-z][a-z0-9_]*"></div>
            <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="product-type-form-field-sort_order">Sort order</label><input type="number" min="0" name="sort_order" id="product-type-form-field-sort_order" class="form-control btn-touch" value="<?= count($types) + 1 ?>"></div>
            <div class="col-12 col-md-3"><button type="submit" class="btn btn-primary btn-touch w-100" id="product-type-form-save-btn">Add</button></div>
        </div>
    </form>
    <?php endif; ?>
    <div class="card" id="product-type-card"><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12" id="product-type-table">
        <thead class="thead-light"><tr><th>Name</th><th>Key</th><th>Order</th><th>Active</th><th class="text-end">Products</th><th></th></tr></thead>
        <tbody><?php foreach ($types as $t): ?><?= view('catalog/partials/product-type-row.php', ['t' => $t, 'mayWrite' => $mayWrite]) ?><?php endforeach; ?></tbody>
    </table></div></div></div>
</div>

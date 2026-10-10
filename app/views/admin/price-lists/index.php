<?php /** The partner price lists (screen `price-list-list`): a table at 992 px and over, cards under. Data: rows, here, notice */ ?>
<?= view('shared/header.php', ['id' => 'price-list-list', 'title' => 'Price lists', 'crumbs' => [['Home', '/'], ['Price lists', null]], 'back' => back_link(),
    'action' => hx_link('/admin/price-lists/new', '<i class="feather-plus me-1"></i>Add a price list', 'btn btn-primary btn-touch', 'id="price-list-list-add-btn"')]) ?>
<div class="main-content" id="price-list-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="fs-12 text-muted mb-2" id="price-list-list-count"><?= count($rows) ?> price list<?= count($rows) === 1 ? '' : 's' ?> — a partner store's key answers with its list's percent off retail</div>
    <?php if ($rows === []): ?>
        <div class="card"><div class="card-body text-center text-muted" id="price-list-list-empty">No price lists yet — add one to give a partner store its price.</div></div>
    <?php else: ?>
    <div class="card d-none d-lg-block"><div class="card-body p-0"><div class="table-responsive">
        <table class="table table-hover mb-0 fs-12" id="price-list-list-table">
            <thead class="thead-light"><tr><th>Name</th><th class="text-end">Off retail</th><th>Active</th><th class="text-end">Keys using it</th><th>Notes</th><th class="text-end"></th></tr></thead>
            <tbody><?php foreach ($rows as $p): ?><?= view('admin/price-lists/partials/row.php', ['p' => $p, 'as' => 'row']) ?><?php endforeach; ?></tbody>
        </table>
    </div></div></div>
    <div class="d-lg-none" id="price-list-list-cards"><?php foreach ($rows as $p): ?><?= view('admin/price-lists/partials/row.php', ['p' => $p, 'as' => 'card']) ?><?php endforeach; ?></div>
    <?php endif; ?>
</div>

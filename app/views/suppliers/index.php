<?php /** The suppliers (screen `supplier-list`): cards. Data: rows, total, page, filters, mayWrite, here, notice */
$f = $filters;
?>
<?= view('shared/header.php', ['id' => 'supplier-list', 'title' => 'Suppliers', 'crumbs' => [['Home', '/'], ['Suppliers', null]], 'back' => back_link(),
    'action' => $mayWrite ? hx_link('/suppliers/new', '<i class="feather-plus me-1"></i>New supplier', 'btn btn-primary btn-touch', 'id="supplier-list-new-btn"') : '']) ?>
<div class="main-content" id="supplier-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/suppliers/" hx-get="/suppliers/" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="true" class="row g-2 mb-3" id="supplier-list-filters">
        <div class="col-12 col-md-6"><input type="search" name="q" class="form-control btn-touch" placeholder="Name or contact — a search also shows archived suppliers" value="<?= e($f['q']) ?>" id="supplier-list-search" aria-label="Search suppliers"></div>
        <div class="col-6 col-md-3"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch" for="supplier-list-filter-dropships"><input type="checkbox" class="form-check-input mt-0" name="dropships" value="1" id="supplier-list-filter-dropships" <?= $f['dropships'] ? 'checked' : '' ?>>Drop-ships</label></div>
        <div class="col-6 col-md-3"><button type="submit" class="btn btn-light btn-touch w-100" id="supplier-list-filter-btn">Filter</button></div>
    </form>
    <div class="fs-12 text-muted mb-2" id="supplier-list-count"><?= (int) $total ?> supplier<?= (int) $total === 1 ? '' : 's' ?></div>
    <div class="row g-3" id="supplier-list">
        <?php if ($rows === []): ?><div class="col-12"><div class="card"><div class="card-body text-center text-muted" id="supplier-list-empty"><?= $f['q'] !== '' || $f['dropships'] ? 'No supplier matches.' : 'No suppliers yet' . ($mayWrite ? ' — make the first one.' : '.') ?></div></div></div><?php endif; ?>
        <?php foreach ($rows as $s): ?><div class="col-12 col-md-6 col-xl-4"><?= view('suppliers/partials/supplier-card.php', ['s' => $s, 'here' => $here]) ?></div><?php endforeach; ?>
    </div>
    <?php if ($total > SUPPLIER_PAGE): ?>
    <div class="d-flex gap-2 mt-3" id="supplier-list-pages">
        <?php if ($page > 1): ?><?= hx_link('/suppliers/?' . http_build_query(array_filter(['q' => $f['q'], 'dropships' => $f['dropships'] ? 1 : null, 'page' => $page - 1])), 'Previous', 'btn btn-light btn-touch', 'id="supplier-list-prev"') ?><?php endif; ?>
        <?php if ($page * SUPPLIER_PAGE < $total): ?><?= hx_link('/suppliers/?' . http_build_query(array_filter(['q' => $f['q'], 'dropships' => $f['dropships'] ? 1 : null, 'page' => $page + 1])), 'Next', 'btn btn-light btn-touch', 'id="supplier-list-next"') ?><?php endif; ?>
    </div>
    <?php endif; ?>
</div>

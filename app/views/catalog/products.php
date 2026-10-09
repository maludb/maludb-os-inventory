<?php /** Products (screen `product-list`): cards by brand and type with their variant count, stock and best state; the filters in one row; 48 a page. Data: rows, total, page, filters, brands, types, mayWrite, tz */
$pages = max(1, (int) ceil($total / PRODUCT_PAGE));
$qs = static fn (int $p): string => '/products/?' . http_build_query(array_filter($filters + ['page' => $p], static fn ($v) => $v !== '' && $v !== null));
?>
<?= view('shared/header.php', ['id' => 'product-list', 'title' => 'Products', 'crumbs' => [['Home', '/'], ['Products', null]], 'back' => back_link(),
    'action' => $mayWrite ? hx_link('/products/new', '<i class="feather-plus me-1"></i>New product', 'btn btn-primary btn-touch', 'id="product-list-new-btn"') : '']) ?>
<div class="main-content" id="product-list-content">
    <?= view('shared/notice.php', ['notice' => $notice ?? null]) ?>
    <form method="get" action="/products/" hx-get="/products/" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="true" hx-trigger="change, submit" class="card mb-3" id="product-list-filters">
        <div class="card-body row g-2 align-items-end">
            <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="product-list-filter-q">Search</label><input type="search" name="q" id="product-list-filter-q" class="form-control btn-touch" placeholder="Name, brand, SKU, GTIN, MPN" value="<?= e($filters['q']) ?>"></div>
            <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="product-list-filter-brand">Brand</label><select name="brand" id="product-list-filter-brand" class="form-select btn-touch"><option value="">Any</option><?php foreach ($brands as $b): ?><option value="<?= $b['brand_id'] ?>" <?= (string) $filters['brand'] === (string) $b['brand_id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="product-list-filter-type">Type</label><select name="type" id="product-list-filter-type" class="form-select btn-touch"><option value="">Any</option><?php foreach ($types as $t): ?><option value="<?= $t['product_type_id'] ?>" <?= (string) $filters['type'] === (string) $t['product_type_id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="product-list-filter-status">Status</label><select name="status" id="product-list-filter-status" class="form-select btn-touch"><option value="">Not discontinued</option><?php foreach (PRODUCT_STATUSES as $k => $w): ?><option value="<?= $k ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?><option value="all" <?= $filters['status'] === 'all' ? 'selected' : '' ?>>Everything</option></select></div>
            <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="product-list-filter-kind">Kind</label><select name="kind" id="product-list-filter-kind" class="form-select btn-touch"><option value="">Any</option><?php foreach (PRODUCT_KINDS as $k => $w): ?><option value="<?= $k ?>" <?= $filters['kind'] === $k ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
            <noscript><div class="col-12"><button type="submit" class="btn btn-light btn-touch">Filter</button></div></noscript>
        </div>
    </form>
    <?php if ($rows === []): ?>
        <div class="card" id="product-list-empty-card"><div class="card-body"><div class="empty-state" id="product-list-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-package"></i></span>
            <div><div class="fw-semibold"><?= $filters['q'] !== '' ? 'Nothing matches “' . e($filters['q']) . '”' : 'No products yet' ?></div><div class="fs-12 text-muted"><?= $mayWrite ? 'Make the first one, or import the business\'s SKUs from a CSV.' : 'The Buyer adds products.' ?></div></div></div></div></div>
    <?php else: ?>
        <div class="fs-12 text-muted mb-2" id="product-list-count"><?= (int) $total ?> product<?= $total === 1 ? '' : 's' ?></div>
        <div class="row g-3" id="product-list">
            <?php foreach ($rows as $p): ?><div class="col-12 col-sm-6 col-lg-4 col-xxl-3"><?= view('catalog/partials/product-card.php', ['p' => $p]) ?></div><?php endforeach; ?>
        </div>
        <?php if ($pages > 1): ?>
        <nav class="mt-3" id="product-list-pagination"><ul class="pagination mb-0">
            <?php if ($page > 1): ?><li class="page-item"><?= hx_link($qs($page - 1), 'Newer', 'page-link') ?></li><?php endif; ?>
            <li class="page-item disabled"><span class="page-link">Page <?= $page ?> of <?= $pages ?></span></li>
            <?php if ($page < $pages): ?><li class="page-item"><?= hx_link($qs($page + 1), 'Older', 'page-link') ?></li><?php endif; ?>
        </ul></nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

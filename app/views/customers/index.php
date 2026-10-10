<?php /** The customers (screen `customer-list`): cards. Data: rows, total, page, filters, mayWrite, here, notice */
$f = $filters;
?>
<?= view('shared/header.php', ['id' => 'customer-list', 'title' => 'Customers', 'crumbs' => [['Home', '/'], ['Customers', null]], 'back' => back_link(),
    'action' => $mayWrite ? hx_link('/customers/new', '<i class="feather-plus me-1"></i>New customer', 'btn btn-primary btn-touch', 'id="customer-list-new-btn"') : '']) ?>
<div class="main-content" id="customer-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/customers/" hx-get="/customers/" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="true" class="row g-2 mb-3" id="customer-list-filters">
        <div class="col-12 col-md-5"><input type="search" name="q" class="form-control btn-touch" placeholder="Name, email or phone" value="<?= e($f['q']) ?>" id="customer-list-search" aria-label="Search customers"></div>
        <div class="col-6 col-md-3"><select name="source" class="form-select btn-touch" id="customer-list-filter-source" aria-label="How they came"><option value="">Every source</option><?php foreach (CUSTOMER_SOURCES as $k => $w): ?><option value="<?= $k ?>" <?= $f['source'] === $k ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch" for="customer-list-filter-archived"><input type="checkbox" class="form-check-input mt-0" name="archived" value="1" id="customer-list-filter-archived" <?= $f['archived'] ? 'checked' : '' ?>>Archived too</label></div>
        <div class="col-12 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="customer-list-filter-btn">Filter</button></div>
    </form>
    <div class="fs-12 text-muted mb-2" id="customer-list-count"><?= (int) $total ?> customer<?= (int) $total === 1 ? '' : 's' ?></div>
    <div class="row g-3" id="customer-list">
        <?php if ($rows === []): ?><div class="col-12"><div class="card"><div class="card-body text-center text-muted" id="customer-list-empty"><?= $f['q'] !== '' || $f['source'] !== '' ? 'No customer matches.' : 'No customers yet' . ($mayWrite ? ' — make the first one.' : '.') ?></div></div></div><?php endif; ?>
        <?php foreach ($rows as $c): ?><div class="col-12 col-md-6 col-xl-4"><?= view('customers/partials/customer-card.php', ['c' => $c, 'here' => $here]) ?></div><?php endforeach; ?>
    </div>
    <?php if ($total > CUSTOMER_PAGE): ?>
    <div class="d-flex gap-2 mt-3" id="customer-list-pages">
        <?php if ($page > 1): ?><?= hx_link('/customers/?' . http_build_query(array_filter(['q' => $f['q'], 'source' => $f['source'], 'archived' => $f['archived'] ? 1 : null, 'page' => $page - 1])), 'Previous', 'btn btn-light btn-touch', 'id="customer-list-prev"') ?><?php endif; ?>
        <?php if ($page * CUSTOMER_PAGE < $total): ?><?= hx_link('/customers/?' . http_build_query(array_filter(['q' => $f['q'], 'source' => $f['source'], 'archived' => $f['archived'] ? 1 : null, 'page' => $page + 1])), 'Next', 'btn btn-light btn-touch', 'id="customer-list-next"') ?><?php endif; ?>
    </div>
    <?php endif; ?>
</div>

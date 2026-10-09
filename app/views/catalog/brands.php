<?php /** Brands (screen `brand-list`): the table with product counts and the dealer program. Data: brands, q, mayWrite, here, notice */ ?>
<?= view('shared/header.php', ['id' => 'brand-list', 'title' => 'Brands', 'crumbs' => [['Home', '/'], ['Brands', null]], 'back' => back_link(),
    'action' => $mayWrite ? hx_link('/brands/new', '<i class="feather-plus me-1"></i>New brand', 'btn btn-primary btn-touch', 'id="brand-list-new-btn"') : '']) ?>
<div class="main-content" id="brand-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/brands/" hx-get="/brands/" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="true" class="mb-3" id="brand-list-filters">
        <input type="search" name="q" class="form-control btn-touch" placeholder="Search brands" value="<?= e($q) ?>" id="brand-list-filter-q" hx-get="/brands/" hx-trigger="input changed delay:300ms, search" hx-target="#page-content" hx-push-url="true">
    </form>
    <div class="card" id="brand-card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="brand-table">
        <thead class="thead-light"><tr><th>Brand</th><th class="d-none d-md-table-cell">Website</th><th>Dealer program</th><th class="text-end">Products</th><th>Active</th><th></th></tr></thead><tbody>
        <?php if ($brands === []): ?><tr><td colspan="6" class="text-center text-muted py-4" id="brand-table-empty"><?= $q !== '' ? 'No brand matches “' . e($q) . '”.' : 'No brands yet.' ?></td></tr><?php endif; ?>
        <?php foreach ($brands as $b): $bid = $b['brand_id']; ?>
            <tr id="brand-row-<?= $bid ?>" class="<?= $b['active'] ? '' : 'text-muted' ?>">
                <td><?= hx_link(with_back('/products/?brand=' . $bid . '&status=all', $here), e($b['name']), 'fw-semibold', 'id="brand-row-' . $bid . '-name"') ?></td>
                <td class="d-none d-md-table-cell"><?= $b['website'] ? '<a href="' . e($b['website']) . '" target="_blank" rel="noopener">' . e(preg_replace('#^https?://#', '', $b['website'])) . '</a>' : '' ?></td>
                <td><?= $b['supplier_id'] ? hx_link(with_back('/suppliers/' . (int) $b['supplier_id'], $here), e($b['supplier_name'] ?? 'supplier #' . (int) $b['supplier_id'])) : '<span class="text-muted">—</span>' ?></td>
                <td class="text-end"><?= $b['product_count'] ?></td>
                <td><?= $b['active'] ? '<span class="badge bg-soft-success text-success">active</span>' : '<span class="badge bg-soft-dark text-dark">inactive</span>' ?></td>
                <td class="text-end"><?= $mayWrite ? hx_link(with_back('/brands/' . $bid . '/edit', $here), 'Edit', 'btn btn-light btn-touch', 'id="brand-row-' . $bid . '-edit-btn"') : '' ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody></table></div></div></div>
</div>

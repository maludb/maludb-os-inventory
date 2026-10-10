<?php /** The orders (screen `order-list`): a table. Data: rows, total, page, filters, locations, salespeople, mayQuote, here, notice */
$f = $filters;
$qs = static fn (array $over = []): string => http_build_query(array_filter(array_merge(['status' => $f['status'], 'customer' => $f['customer'], 'salesperson' => $f['salesperson'], 'location' => $f['location'], 'late' => $f['late'] ? 1 : null,
    'from' => $f['from'], 'to' => $f['to'], 'q' => $f['q']], $over), static fn ($v) => $v !== '' && $v !== null));
$statusOptions = ['' => 'Open (confirmed, in fulfilment, shipped)', 'all' => 'Every status'] + ORDER_STATUSES;
?>
<?= view('shared/header.php', ['id' => 'order-list', 'title' => 'Orders', 'crumbs' => [['Home', '/'], ['Orders', null]], 'back' => back_link(),
    'action' => $mayQuote ? hx_link('/orders/new', '<i class="feather-plus me-1"></i>New quote', 'btn btn-primary btn-touch', 'id="order-list-new-btn"') : '']) ?>
<div class="main-content" id="order-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/orders/" hx-get="/orders/" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="true" class="row g-2 mb-3" id="order-list-filters">
        <div class="col-12 col-md-3"><input type="search" name="q" class="form-control btn-touch" placeholder="Number, customer or reference" value="<?= e($f['q']) ?>" id="order-list-filter-q" aria-label="Search orders"></div>
        <div class="col-6 col-md-3"><select name="status" class="form-select btn-touch" id="order-list-filter-status" aria-label="Status"><?php foreach ($statusOptions as $k => $w): ?><option value="<?= e($k) ?>" <?= (string) $f['status'] === (string) $k ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><select name="location" class="form-select btn-touch" id="order-list-filter-location" aria-label="Store"><option value="">Every store</option><?php foreach ($locations as $l): ?><option value="<?= (int) $l['location_id'] ?>" <?= (int) $f['location'] === (int) $l['location_id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><select name="salesperson" class="form-select btn-touch" id="order-list-filter-salesperson" aria-label="Salesperson"><option value="">Anyone</option><?php foreach ($salespeople as $p): ?><option value="<?= (int) $p['member_id'] ?>" <?= (int) $f['salesperson'] === (int) $p['member_id'] ? 'selected' : '' ?>><?= e($p['display_name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch" for="order-list-filter-late"><input type="checkbox" class="form-check-input mt-0" name="late" value="1" id="order-list-filter-late" <?= $f['late'] ? 'checked' : '' ?>>Late only</label></div>
        <div class="col-6 col-md-3"><label class="visually-hidden" for="order-list-filter-from">Ordered from</label><input type="date" name="from" class="form-control btn-touch" id="order-list-filter-from" value="<?= e($f['from']) ?>" title="Ordered from"></div>
        <div class="col-6 col-md-3"><label class="visually-hidden" for="order-list-filter-to">Ordered to</label><input type="date" name="to" class="form-control btn-touch" id="order-list-filter-to" value="<?= e($f['to']) ?>" title="Ordered to"></div>
        <div class="col-12 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="order-list-filter-btn">Filter</button></div>
    </form>
    <?php if ($f['customer'] !== ''): ?><div class="fs-12 mb-2" id="order-list-customer-filter">For one customer. <?= hx_link('/orders/?' . $qs(['customer' => null]), 'Show everyone') ?></div><?php endif; ?>
    <div class="fs-12 text-muted mb-2" id="order-list-count"><?= (int) $total ?> order<?= (int) $total === 1 ? '' : 's' ?></div>
    <div class="card"><div class="card-body p-0"><div class="table-responsive">
        <table class="table mb-0 fs-12" id="order-list-table"><thead class="thead-light"><tr><th>Order</th><th>Customer</th><th class="d-none d-lg-table-cell">Salesperson</th><th class="d-none d-lg-table-cell">Store</th><th class="d-none d-md-table-cell">Ordered</th><th>Promised</th><th>Status</th><th class="text-end">Total</th><th class="d-none d-md-table-cell">Payment</th><th class="text-end d-none d-md-table-cell">Balance</th></tr></thead>
        <tbody>
            <?php if ($rows === []): ?><tr><td colspan="10" class="text-center text-muted py-4" id="order-list-empty">No orders match.</td></tr><?php endif; ?>
            <?php foreach ($rows as $o): ?><?= view('orders/partials/order-row.php', ['o' => $o, 'here' => $here]) ?><?php endforeach; ?>
        </tbody></table>
    </div></div></div>
    <?php if ($total > ORDER_PAGE): ?>
    <div class="d-flex gap-2 mt-3" id="order-list-pages">
        <?php if ($page > 1): ?><?= hx_link('/orders/?' . $qs(['page' => $page - 1]), 'Previous', 'btn btn-light btn-touch', 'id="order-list-prev"') ?><?php endif; ?>
        <?php if ($page * ORDER_PAGE < $total): ?><?= hx_link('/orders/?' . $qs(['page' => $page + 1]), 'Next', 'btn btn-light btn-touch', 'id="order-list-next"') ?><?php endif; ?>
    </div>
    <?php endif; ?>
</div>

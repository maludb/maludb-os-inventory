<?php /** The purchase orders (screen `purchase-order-list`): a table. Data: rows, total, page, filters, suppliers, mayDraft, seesCost, here, notice */
$f = $filters;
$qs = static fn (array $over = []): string => http_build_query(array_filter(array_merge(['status' => $f['status'], 'kind' => $f['kind'], 'supplier' => $f['supplier'], 'awaiting_ack' => $f['awaiting_ack'] ? 1 : null,
    'no_tracking' => $f['no_tracking'] ? 1 : null, 'order' => $f['order'], 'q' => $f['q']], $over), static fn ($v) => $v !== '' && $v !== null));
$statusOptions = ['' => 'Open (draft, sent, acknowledged, partial)', 'all' => 'Every status'] + PO_STATUSES;
?>
<?= view('shared/header.php', ['id' => 'purchase-order-list', 'title' => 'Purchase orders', 'crumbs' => [['Home', '/'], ['Purchase orders', null]], 'back' => back_link(),
    'action' => $mayDraft ? hx_link('/purchasing/new', '<i class="feather-plus me-1"></i>New purchase order', 'btn btn-primary btn-touch', 'id="purchase-order-list-new-btn"') : '']) ?>
<div class="main-content" id="purchase-order-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/purchasing/" hx-get="/purchasing/" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="true" class="row g-2 mb-3" id="purchase-order-list-filters">
        <div class="col-12 col-md-3"><input type="search" name="q" class="form-control btn-touch" placeholder="Number, supplier or their reference" value="<?= e($f['q']) ?>" id="purchase-order-list-filter-q" aria-label="Search purchase orders"></div>
        <div class="col-6 col-md-3"><select name="status" class="form-select btn-touch" id="purchase-order-list-filter-status" aria-label="Status"><?php foreach ($statusOptions as $k => $w): ?><option value="<?= e($k) ?>" <?= (string) $f['status'] === (string) $k ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><select name="kind" class="form-select btn-touch" id="purchase-order-list-filter-kind" aria-label="Kind"><option value="">Any kind</option><?php foreach (PO_KINDS as $k => $w): ?><option value="<?= $k ?>" <?= $f['kind'] === $k ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><select name="supplier" class="form-select btn-touch" id="purchase-order-list-filter-supplier" aria-label="Supplier"><option value="">Any supplier</option><?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['supplier_id'] ?>" <?= (int) $f['supplier'] === (int) $s['supplier_id'] ? 'selected' : '' ?>><?= e($s['supplier_name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="purchase-order-list-filter-btn">Filter</button></div>
        <div class="col-6 col-md-3"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch" for="purchase-order-list-filter-ack"><input type="checkbox" class="form-check-input mt-0" name="awaiting_ack" value="1" id="purchase-order-list-filter-ack" <?= $f['awaiting_ack'] ? 'checked' : '' ?>>Awaiting acknowledgment</label></div>
        <div class="col-6 col-md-3"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch" for="purchase-order-list-filter-tracking"><input type="checkbox" class="form-check-input mt-0" name="no_tracking" value="1" id="purchase-order-list-filter-tracking" <?= $f['no_tracking'] ? 'checked' : '' ?>>No tracking past the date</label></div>
    </form>
    <div class="fs-12 text-muted mb-2" id="purchase-order-list-count"><?= (int) $total ?> purchase order<?= (int) $total === 1 ? '' : 's' ?></div>
    <div class="card"><div class="card-body p-0"><div class="table-responsive">
        <table class="table mb-0 fs-12" id="po-list-table"><thead class="thead-light"><tr><th>Number</th><th>Supplier</th><th>Kind</th><th>Status</th><th class="d-none d-md-table-cell">Ordered</th><th>Expected</th><th class="d-none d-lg-table-cell">Acknowledged</th><th class="d-none d-md-table-cell text-center">Tracking</th><th class="text-end">Total</th></tr></thead>
        <tbody>
            <?php if ($rows === []): ?><tr><td colspan="9" class="text-center text-muted py-4" id="purchase-order-list-empty">No purchase order matches.</td></tr><?php endif; ?>
            <?php foreach ($rows as $o): ?><?= view('purchasing/partials/po-row.php', ['o' => $o, 'seesCost' => $seesCost, 'here' => $here]) ?><?php endforeach; ?>
        </tbody></table>
    </div></div></div>
    <?php if ($total > PO_PAGE): ?>
    <div class="d-flex gap-2 mt-3" id="purchase-order-list-pages">
        <?php if ($page > 1): ?><?= hx_link('/purchasing/?' . $qs(['page' => $page - 1]), 'Previous', 'btn btn-light btn-touch', 'id="purchase-order-list-prev"') ?><?php endif; ?>
        <?php if ($page * PO_PAGE < $total): ?><?= hx_link('/purchasing/?' . $qs(['page' => $page + 1]), 'Next', 'btn btn-light btn-touch', 'id="purchase-order-list-next"') ?><?php endif; ?>
    </div>
    <?php endif; ?>
</div>

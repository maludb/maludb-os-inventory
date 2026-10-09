<?php /** The goods receipts (screen `receipt-list`). Data: rows, more, page, filters, suppliers, locations, tz, here, notice */
$q = array_filter(['status' => $filters['status'], 'supplier' => $filters['supplier'], 'location' => $filters['location']], static fn ($v) => $v !== null && $v !== '');
$qs = static fn (array $extra): string => '/receipts/?' . http_build_query($q + $extra);
?>
<?= view('shared/header.php', ['id' => 'receipt-list', 'title' => 'Receipts', 'crumbs' => [['Home', '/'], ['Stock', '/stock/'], ['Receipts', null]], 'back' => back_link(),
    'action' => hx_link('/receipts/new', '<i class="feather-plus me-1"></i>New receipt', 'btn btn-primary btn-touch', 'id="receipt-list-new-btn"')]) ?>
<div class="main-content" id="receipt-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/receipts/" hx-get="/receipts/" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="true" class="row g-2 mb-3" id="receipt-list-filters">
        <div class="col-6 col-md-3"><select name="status" class="form-select btn-touch" id="receipt-list-filter-status" aria-label="Status"><option value="">Every status</option><?php foreach (DOC_STATUSES as $k => $w): ?><option value="<?= $k ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><select name="supplier" class="form-select btn-touch" id="receipt-list-filter-supplier" aria-label="Supplier"><option value="">Every supplier</option><?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['supplier_id'] ?>" <?= (int) $filters['supplier'] === (int) $s['supplier_id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><select name="location" class="form-select btn-touch" id="receipt-list-filter-location" aria-label="Location"><option value="">Every location</option><?php foreach ($locations as $l): ?><option value="<?= (int) $l['location_id'] ?>" <?= (int) $filters['location'] === (int) $l['location_id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><button type="submit" class="btn btn-light btn-touch w-100" id="receipt-list-filter-btn">Filter</button></div>
    </form>
    <div class="card" id="receipts-card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="receipts-table">
        <thead class="thead-light"><tr><th>Number</th><th>Status</th><th>Supplier</th><th class="d-none d-md-table-cell">Purchase order</th><th class="d-none d-md-table-cell">Location</th><th>Received</th><th class="text-end">Lines · units</th><th class="d-none d-lg-table-cell">Posted</th></tr></thead><tbody>
        <?php if ($rows === []): ?><tr><td colspan="8" class="text-center text-muted py-4" id="receipts-table-empty">No receipts<?= $q !== [] ? ' match' : ' yet' ?>.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?><?= view('receipts/partials/receipt-row.php', ['r' => $r, 'tz' => $tz, 'here' => $here]) ?><?php endforeach; ?>
    </tbody></table></div></div></div>
    <?php if ($page > 1 || $more): ?><nav class="mt-3" id="receipt-list-pagination"><ul class="pagination mb-0">
        <?php if ($page > 1): ?><li class="page-item"><?= hx_link($qs(['page' => $page - 1]), 'Previous', 'page-link') ?></li><?php endif; ?>
        <?php if ($more): ?><li class="page-item"><?= hx_link($qs(['page' => $page + 1]), 'Next', 'page-link') ?></li><?php endif; ?>
    </ul></nav><?php endif; ?>
</div>

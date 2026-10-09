<?php /** The movement ledger (screen `movement-list`). Data: rows, more, filters, page, locations, variant, mayReverse, seesCost, seesReceiptCost, tz, here, notice */
$type = $filters['txn_type'][0] ?? '';
$q = array_filter(['variant' => $filters['variant'], 'location' => $filters['location'], 'type' => $type, 'from' => $filters['from'], 'to' => $filters['to']], static fn ($v) => $v !== null && $v !== '');
$qs = static fn (array $extra): string => '/stock/movements?' . http_build_query($q + $extra);
?>
<?= view('shared/header.php', ['id' => 'movement-list', 'title' => 'Movements', 'crumbs' => [['Home', '/'], ['Stock', '/stock/'], ['Movements', null]], 'back' => back_link()]) ?>
<div class="main-content" id="movement-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/stock/movements" hx-get="/stock/movements" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="true" class="row g-2 mb-3" id="movements-filters">
        <?php if ($variant !== null): ?><input type="hidden" name="variant" value="<?= (int) $variant['variant_id'] ?>"><?php endif; ?>
        <div class="col-6 col-md-3"><select name="location" class="form-select btn-touch" id="movements-filters-location" aria-label="Location"><option value="">Every location</option><?php foreach ($locations as $l): ?><option value="<?= (int) $l['location_id'] ?>" <?= (int) $filters['location'] === (int) $l['location_id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><select name="type" class="form-select btn-touch" id="movements-filters-type" aria-label="Type"><option value="">Every type</option><?php foreach (TXN_TYPES as $k => $w): ?><option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><input type="date" name="from" class="form-control btn-touch" value="<?= e($filters['from'] ?? '') ?>" id="movements-filters-from" aria-label="From"></div>
        <div class="col-6 col-md-2"><input type="date" name="to" class="form-control btn-touch" value="<?= e($filters['to'] ?? '') ?>" id="movements-filters-to" aria-label="To"></div>
        <div class="col-12 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="movements-filters-btn">Filter</button></div>
    </form>
    <div class="fs-12 text-muted mb-2" id="movements-window"><?= $variant !== null ? 'For ' . hx_link('/variants/' . (int) $variant['variant_id'], e($variant['sku']), 'fw-semibold') . ' · ' : '' ?><?= $filters['from'] === null ? 'The last 30 days — choose a From date to look further back.' : 'From ' . e($filters['from']) . ($filters['to'] ? ' to ' . e($filters['to']) : '') . '.' ?></div>
    <?= view('stock/partials/movements-table.php', ['rows' => $rows, 'here' => $here, 'tz' => $tz, 'mayReverse' => $mayReverse, 'seesCost' => $seesCost, 'seesReceiptCost' => $seesReceiptCost, 'empty' => 'No movements in this window.']) ?>
    <?php if ($page > 1 || $more): ?>
    <nav class="mt-3" id="movements-pagination"><ul class="pagination mb-0">
        <?php if ($page > 1): ?><li class="page-item"><?= hx_link($qs(['page' => $page - 1]), 'Newer', 'page-link') ?></li><?php endif; ?>
        <?php if ($more): ?><li class="page-item"><?= hx_link($qs(['page' => $page + 1]), 'Older', 'page-link') ?></li><?php endif; ?>
    </ul></nav>
    <?php endif; ?>
</div>

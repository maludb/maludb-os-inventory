<?php /** The levels (screen `stock-levels`). Data: rows, totals, filters, page, pages, locations, brands, types, variant, here */
$q = array_filter(['location' => $filters['location'], 'brand' => $filters['brand'], 'type' => $filters['product_type'], 'q' => $filters['q'], 'variant' => $filters['variant'], 'below_reorder' => $filters['below_reorder'] ? '1' : null], static fn ($v) => $v !== null && $v !== '' && $v !== false);
$qs = static fn (array $extra): string => '/stock/?' . http_build_query($q + $extra);
?>
<?= view('shared/header.php', ['id' => 'stock-levels', 'title' => 'Stock levels', 'crumbs' => [['Home', '/'], ['Stock levels', null]], 'back' => back_link(),
    'action' => '<a href="' . e($qs(['format' => 'csv'])) . '" class="btn btn-light btn-touch" id="stock-levels-csv" download><i class="feather-download me-1"></i>CSV</a>']) ?>
<div class="main-content" id="stock-levels-content">
    <form method="get" action="/stock/" hx-get="/stock/" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="true" class="row g-2 mb-3" id="levels-filters">
        <?php if ($variant !== null): ?><input type="hidden" name="variant" value="<?= (int) $variant['variant_id'] ?>"><?php endif; ?>
        <div class="col-12 col-md-3"><input type="search" name="q" class="form-control btn-touch" placeholder="SKU, product or barcode" value="<?= e($filters['q']) ?>" id="levels-filters-q" aria-label="Search"></div>
        <div class="col-6 col-md-2"><select name="location" class="form-select btn-touch" id="levels-filters-location" aria-label="Location"><option value="">Every location</option><?php foreach ($locations as $l): ?><option value="<?= (int) $l['location_id'] ?>" <?= (int) $filters['location'] === (int) $l['location_id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><select name="brand" class="form-select btn-touch" id="levels-filters-brand" aria-label="Brand"><option value="">Every brand</option><?php foreach ($brands as $b): ?><option value="<?= (int) $b['brand_id'] ?>" <?= (int) $filters['brand'] === (int) $b['brand_id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><select name="type" class="form-select btn-touch" id="levels-filters-type" aria-label="Type"><option value="">Every type</option><?php foreach ($types as $t): ?><option value="<?= e($t['key']) ?>" <?= $filters['product_type'] === $t['key'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch" for="levels-filters-below_reorder"><input type="checkbox" class="form-check-input mt-0" name="below_reorder" value="1" id="levels-filters-below_reorder" <?= $filters['below_reorder'] ? 'checked' : '' ?>>Below reorder</label></div>
        <div class="col-12 col-md-1"><button type="submit" class="btn btn-light btn-touch w-100" id="levels-filters-btn">Filter</button></div>
    </form>
    <?php if ($variant !== null): ?><div class="fs-12 mb-2" id="levels-variant">Showing <?= hx_link('/variants/' . (int) $variant['variant_id'], e($variant['sku']), 'fw-semibold') ?> · <?= hx_link('/stock/', 'every variant') ?></div><?php endif; ?>
    <div class="fs-12 text-muted mb-2" id="levels-count"><?= number_format($totals['rows']) ?> row<?= $totals['rows'] === 1 ? '' : 's' ?> · <?= number_format($totals['on_hand']) ?> on hand · <?= number_format($totals['available']) ?> available</div>
    <?= view('stock/partials/levels-table.php', ['rows' => $rows, 'totals' => $totals, 'showLocation' => true, 'here' => $here, 'footer' => $variant !== null || $filters['location']]) ?>
    <?php if ($pages > 1): ?>
    <nav class="mt-3" id="levels-pagination"><ul class="pagination mb-0">
        <?php if ($page > 1): ?><li class="page-item"><?= hx_link($qs(['page' => $page - 1]), 'Previous', 'page-link') ?></li><?php endif; ?>
        <li class="page-item disabled"><span class="page-link">Page <?= $page ?> of <?= $pages ?></span></li>
        <?php if ($page < $pages): ?><li class="page-item"><?= hx_link($qs(['page' => $page + 1]), 'Next', 'page-link') ?></li><?php endif; ?>
    </ul></nav>
    <?php endif; ?>
</div>

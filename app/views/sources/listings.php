<?php /** A source's listings (screen `source-listings`). Data: s, rows, more, page, filters, seesCost, here */
$sid = (int) $s['source_id'];
$q = array_filter(['match' => $filters['matched'], 'q' => $filters['q'], 'availability' => $filters['availability'], 'removed' => $filters['include_removed'] ? '1' : null], static fn ($v) => $v !== null && $v !== '');
$qs = static fn (array $x): string => '/sources/' . $sid . '/listings?' . http_build_query($q + $x);
$live = array_values(array_filter($rows, static fn ($l) => $l['removed_at'] === null));
$gone = array_values(array_filter($rows, static fn ($l) => $l['removed_at'] !== null));
$table = static function (array $ls, string $tid) use ($seesCost, $here): string {
    $h = '<div class="card mb-3"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="' . $tid . '"><thead class="thead-light"><tr><th>Title</th><th class="d-none d-md-table-cell">Vendor</th><th class="d-none d-lg-table-cell">Type</th><th class="text-end">Variants</th><th>Match</th><th class="text-end">Price</th><th>Availability</th><th class="d-none d-md-table-cell">Last seen</th></tr></thead><tbody>';
    if ($ls === []) { $h .= '<tr><td colspan="8" class="text-center text-muted py-4">No listings.</td></tr>'; }
    foreach ($ls as $l) { $h .= view('sources/partials/listing-row.php', ['l' => $l, 'seesCost' => $seesCost, 'here' => $here]); }
    return $h . '</tbody></table></div></div></div>';
};
?>
<?= view('shared/header.php', ['id' => 'source-listings', 'title' => 'Listings of ' . $s['name'], 'crumbs' => [['Home', '/'], ['Sources', '/sources/'], [$s['name'], '/sources/' . $sid], ['Listings', null]], 'back' => back_link() ?? ['/sources/' . $sid, $s['name']]]) ?>
<div class="main-content" id="source-listings-content">
    <form method="get" action="/sources/<?= $sid ?>/listings" hx-get="/sources/<?= $sid ?>/listings" hx-target="#page-content" hx-push-url="true" class="row g-2 mb-3" id="source-listings-filters">
        <div class="col-12 col-md-4"><input type="search" name="q" class="form-control btn-touch" placeholder="Title, SKU or GTIN" value="<?= e($filters['q']) ?>" id="source-listings-filter-q" aria-label="Search"></div>
        <div class="col-6 col-md-2"><select name="match" class="form-select btn-touch" id="source-listings-filter-match" aria-label="Match"><option value="">All</option><option value="matched" <?= $filters['matched'] === 'matched' ? 'selected' : '' ?>>Matched</option><option value="unmatched" <?= $filters['matched'] === 'unmatched' ? 'selected' : '' ?>>Unmatched</option></select></div>
        <div class="col-6 col-md-2"><select name="availability" class="form-select btn-touch" id="source-listings-filter-availability" aria-label="Availability"><option value="">Any availability</option><?php foreach (['in_stock', 'limited', 'pre_order', 'back_order', 'out_of_stock', 'discontinued', 'unknown'] as $a): ?><option value="<?= $a ?>" <?= $filters['availability'] === $a ? 'selected' : '' ?>><?= str_replace('_', ' ', $a) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch" for="source-listings-filter-removed"><input type="checkbox" class="form-check-input mt-0" name="removed" value="1" id="source-listings-filter-removed" <?= $filters['include_removed'] ? 'checked' : '' ?>>Removed too</label></div>
        <div class="col-6 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="source-listings-filter-btn">Filter</button></div>
    </form>
    <?= $table($live, 'listings-table') ?>
    <?php if ($gone !== []): ?><h6 class="mb-2">Removed</h6><?= $table($gone, 'listings-removed-table') ?><?php endif; ?>
    <?php if ($page > 1 || $more): ?><nav><ul class="pagination mb-0"><?php if ($page > 1): ?><li class="page-item"><?= hx_link($qs(['page' => $page - 1]), 'Previous', 'page-link') ?></li><?php endif; ?><?php if ($more): ?><li class="page-item"><?= hx_link($qs(['page' => $page + 1]), 'Next', 'page-link') ?></li><?php endif; ?></ul></nav><?php endif; ?>
</div>

<?php /** A source's pulls (screen `source-pulls`). Data: s, rows, more, page, status, tz, here */
$qs = static fn (array $x): string => '/sources/' . (int) $s['source_id'] . '/pulls?' . http_build_query(array_filter(['status' => $status]) + $x);
?>
<?= view('shared/header.php', ['id' => 'source-pulls', 'title' => 'Pulls of ' . $s['name'], 'crumbs' => [['Home', '/'], ['Sources', '/sources/'], [$s['name'], '/sources/' . (int) $s['source_id']], ['Pulls', null]], 'back' => back_link() ?? ['/sources/' . (int) $s['source_id'], $s['name']]]) ?>
<div class="main-content" id="source-pulls-content">
    <form method="get" action="/sources/<?= (int) $s['source_id'] ?>/pulls" hx-get="/sources/<?= (int) $s['source_id'] ?>/pulls" hx-target="#page-content" hx-push-url="true" class="mb-3" id="source-pulls-filters">
        <select name="status" class="form-select btn-touch" aria-label="Status" id="source-pulls-filter-status" hx-get="/sources/<?= (int) $s['source_id'] ?>/pulls" hx-trigger="change" hx-target="#page-content" hx-push-url="true"><option value="">Every status</option><?php foreach (PULL_STATUSES as $k => $w): ?><option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select>
        <noscript><button type="submit" class="btn btn-light btn-touch mt-2">Filter</button></noscript>
    </form>
    <div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="pulls-table">
        <thead class="thead-light"><tr><th>Started</th><th>Kind</th><th>Status</th><th>Counts</th><th class="d-none d-md-table-cell">Requests</th><th class="d-none d-lg-table-cell">Bytes</th><th class="d-none d-lg-table-cell">Took</th><th class="d-none d-lg-table-cell">By</th><th>Error · policy</th></tr></thead><tbody>
        <?php if ($rows === []): ?><tr><td colspan="9" class="text-center text-muted py-4">No pulls.</td></tr><?php endif; ?>
        <?php foreach ($rows as $p): ?><?= view('sources/partials/pull-row.php', ['p' => $p, 'tz' => $tz, 'full' => true]) ?><?php endforeach; ?>
    </tbody></table></div></div></div>
    <?php if ($page > 1 || $more): ?><nav class="mt-3"><ul class="pagination mb-0"><?php if ($page > 1): ?><li class="page-item"><?= hx_link($qs(['page' => $page - 1]), 'Newer', 'page-link') ?></li><?php endif; ?><?php if ($more): ?><li class="page-item"><?= hx_link($qs(['page' => $page + 1]), 'Older', 'page-link') ?></li><?php endif; ?></ul></nav><?php endif; ?>
</div>

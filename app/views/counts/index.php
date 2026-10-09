<?php /** The counts (screen `count-list`). Data: rows, more, page, filters, locations, tz, here, notice */
$q = array_filter($filters, static fn ($v) => $v !== null && $v !== '');
$qs = static fn (array $extra): string => '/counts/?' . http_build_query($q + $extra);
?>
<?= view('shared/header.php', ['id' => 'count-list', 'title' => 'Counts', 'crumbs' => [['Home', '/'], ['Stock', '/stock/'], ['Counts', null]], 'back' => back_link(),
    'action' => hx_link('/counts/new', '<i class="feather-plus me-1"></i>Start a count', 'btn btn-primary btn-touch', 'id="count-list-new-btn"')]) ?>
<div class="main-content" id="count-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/counts/" hx-get="/counts/" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="true" class="row g-2 mb-3" id="count-list-filters">
        <div class="col-6 col-md-4"><select name="status" class="form-select btn-touch" id="count-list-filter-status" aria-label="Status"><option value="">Every status</option><?php foreach (COUNT_STATUSES as $k => $w): ?><option value="<?= $k ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-4"><select name="location" class="form-select btn-touch" id="count-list-filter-location" aria-label="Location"><option value="">Every location</option><?php foreach ($locations as $l): ?><option value="<?= (int) $l['location_id'] ?>" <?= (int) $filters['location'] === (int) $l['location_id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12 col-md-4"><button type="submit" class="btn btn-light btn-touch w-100" id="count-list-filter-btn">Filter</button></div>
    </form>
    <div class="card" id="counts-card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="counts-table">
        <thead class="thead-light"><tr><th>Number</th><th>Status</th><th>Location</th><th class="d-none d-md-table-cell">Started</th><th class="text-end">Lines</th><th class="text-end">Differing</th><th class="d-none d-md-table-cell">Posted</th></tr></thead><tbody>
        <?php if ($rows === []): ?><tr><td colspan="7" class="text-center text-muted py-4" id="counts-table-empty">No counts<?= $q !== [] ? ' match' : ' yet' ?>.</td></tr><?php endif; ?>
        <?php foreach ($rows as $c): $id = (int) $c['count_id']; ?>
            <tr id="count-row-<?= $id ?>"><td class="text-nowrap"><?= hx_link(with_back('/counts/' . $id, $here), e($c['number']), 'fw-semibold', 'id="count-row-' . $id . '-number"') ?></td><td><?= doc_status_chip($c['status']) ?></td>
                <td><?= hx_link(with_back('/locations/' . (int) $c['location_id'], $here), e($c['location_name']), 'text-dark') ?></td>
                <td class="d-none d-md-table-cell"><?= e(($c['started_by_name'] ?? '') . ' · ' . format_ts($c['started_at'], $tz, 'M j, g:i A')) ?></td>
                <td class="text-end"><?= (int) $c['lines_counted'] ?>/<?= (int) $c['line_count'] ?></td><td class="text-end"><?= (int) $c['lines_differing'] ?></td>
                <td class="d-none d-md-table-cell"><?= $c['posted_at'] ? e(($c['posted_by_name'] ?? '') . ' · ' . format_ts($c['posted_at'], $tz, 'M j, g:i A')) : '' ?></td></tr>
        <?php endforeach; ?>
    </tbody></table></div></div></div>
    <?php if ($page > 1 || $more): ?><nav class="mt-3"><ul class="pagination mb-0">
        <?php if ($page > 1): ?><li class="page-item"><?= hx_link($qs(['page' => $page - 1]), 'Previous', 'page-link') ?></li><?php endif; ?>
        <?php if ($more): ?><li class="page-item"><?= hx_link($qs(['page' => $page + 1]), 'Next', 'page-link') ?></li><?php endif; ?>
    </ul></nav><?php endif; ?>
</div>

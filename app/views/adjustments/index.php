<?php /** The adjustments (screen `adjustment-list`). Data: rows, more, page, filters, reasons, locations, tz, here, notice */
$q = array_filter($filters, static fn ($v) => $v !== null && $v !== '');
$qs = static fn (array $extra): string => '/adjustments/?' . http_build_query($q + $extra);
?>
<?= view('shared/header.php', ['id' => 'adjustment-list', 'title' => 'Adjustments', 'crumbs' => [['Home', '/'], ['Stock', '/stock/'], ['Adjustments', null]], 'back' => back_link(),
    'action' => hx_link('/adjustments/new', '<i class="feather-plus me-1"></i>New adjustment', 'btn btn-primary btn-touch', 'id="adjustment-list-new-btn"')]) ?>
<div class="main-content" id="adjustment-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/adjustments/" hx-get="/adjustments/" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="true" class="row g-2 mb-3" id="adjustment-list-filters">
        <div class="col-6 col-md-3"><select name="status" class="form-select btn-touch" id="adjustment-list-filter-status" aria-label="Status"><option value="">Every status</option><?php foreach (DOC_STATUSES as $k => $w): ?><option value="<?= $k ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><select name="location" class="form-select btn-touch" id="adjustment-list-filter-location" aria-label="Location"><option value="">Every location</option><?php foreach ($locations as $l): ?><option value="<?= (int) $l['location_id'] ?>" <?= (int) $filters['location'] === (int) $l['location_id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><select name="reason" class="form-select btn-touch" id="adjustment-list-filter-reason" aria-label="Reason"><option value="">Every reason</option><?php foreach ($reasons as $rc): ?><option value="<?= e($rc['code']) ?>" <?= $filters['reason'] === $rc['code'] ? 'selected' : '' ?>><?= e($rc['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><button type="submit" class="btn btn-light btn-touch w-100" id="adjustment-list-filter-btn">Filter</button></div>
    </form>
    <div class="card" id="adjustments-card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="adjustments-table">
        <thead class="thead-light"><tr><th>Number</th><th>Status</th><th>Location</th><th>Reason</th><th class="text-end">Lines · change</th><th class="d-none d-md-table-cell">Posted</th></tr></thead><tbody>
        <?php if ($rows === []): ?><tr><td colspan="6" class="text-center text-muted py-4" id="adjustments-table-empty">No adjustments<?= $q !== [] ? ' match' : ' yet' ?>.</td></tr><?php endif; ?>
        <?php foreach ($rows as $a): $id = (int) $a['adjustment_id']; ?>
            <tr id="adjustment-row-<?= $id ?>"><td class="text-nowrap"><?= hx_link(with_back('/adjustments/' . $id, $here), e($a['number']), 'fw-semibold', 'id="adjustment-row-' . $id . '-number"') ?></td><td><?= doc_status_chip($a['status']) ?></td>
                <td><?= hx_link(with_back('/locations/' . (int) $a['location_id'], $here), e($a['location_name']), 'text-dark') ?></td><td><?= e($a['reason_name']) ?></td>
                <td class="text-end"><?= (int) $a['line_count'] ?> · <?= signed_qty($a['units_delta']) ?></td><td class="d-none d-md-table-cell"><?= $a['posted_at'] ? e(($a['posted_by_name'] ?? '') . ' · ' . format_ts($a['posted_at'], $tz, 'M j, g:i A')) : '' ?></td></tr>
        <?php endforeach; ?>
    </tbody></table></div></div></div>
    <?php if ($page > 1 || $more): ?><nav class="mt-3"><ul class="pagination mb-0">
        <?php if ($page > 1): ?><li class="page-item"><?= hx_link($qs(['page' => $page - 1]), 'Previous', 'page-link') ?></li><?php endif; ?>
        <?php if ($more): ?><li class="page-item"><?= hx_link($qs(['page' => $page + 1]), 'Next', 'page-link') ?></li><?php endif; ?>
    </ul></nav><?php endif; ?>
</div>

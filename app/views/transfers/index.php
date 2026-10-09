<?php /** The transfers (screen `transfer-list`). Data: rows, more, page, filters, locations, tz, here, notice */
$q = array_filter($filters, static fn ($v) => $v !== null && $v !== '');
$qs = static fn (array $extra): string => '/transfers/?' . http_build_query($q + $extra);
?>
<?= view('shared/header.php', ['id' => 'transfer-list', 'title' => 'Transfers', 'crumbs' => [['Home', '/'], ['Stock', '/stock/'], ['Transfers', null]], 'back' => back_link(),
    'action' => hx_link('/transfers/new', '<i class="feather-plus me-1"></i>New transfer', 'btn btn-primary btn-touch', 'id="transfer-list-new-btn"')]) ?>
<div class="main-content" id="transfer-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/transfers/" hx-get="/transfers/" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="true" class="row g-2 mb-3" id="transfer-list-filters">
        <div class="col-6 col-md-4"><select name="status" class="form-select btn-touch" id="transfer-list-filter-status" aria-label="Status"><option value="">Every status</option><?php foreach (TRANSFER_STATUSES as $k => $w): ?><option value="<?= $k ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-4"><select name="location" class="form-select btn-touch" id="transfer-list-filter-location" aria-label="Location"><option value="">Every location</option><?php foreach ($locations as $l): ?><option value="<?= (int) $l['location_id'] ?>" <?= (int) $filters['location'] === (int) $l['location_id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12 col-md-4"><button type="submit" class="btn btn-light btn-touch w-100" id="transfer-list-filter-btn">Filter</button></div>
    </form>
    <div class="card" id="transfers-card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="transfers-table">
        <thead class="thead-light"><tr><th>Number</th><th>Status</th><th>From → to</th><th class="text-end">Lines · units</th><th class="d-none d-md-table-cell">Sent</th><th class="d-none d-md-table-cell">Received</th></tr></thead><tbody>
        <?php if ($rows === []): ?><tr><td colspan="6" class="text-center text-muted py-4" id="transfers-table-empty">No transfers<?= $q !== [] ? ' match' : ' yet' ?>.</td></tr><?php endif; ?>
        <?php foreach ($rows as $t): $id = (int) $t['transfer_id']; ?>
            <tr id="transfer-row-<?= $id ?>"><td class="text-nowrap"><?= hx_link(with_back('/transfers/' . $id, $here), e($t['number']), 'fw-semibold', 'id="transfer-row-' . $id . '-number"') ?></td><td><?= doc_status_chip($t['status']) ?></td>
                <td><?= e($t['from_location']) ?> → <?= e($t['to_location']) ?></td><td class="text-end"><?= (int) $t['line_count'] ?> · <?= (int) $t['units'] ?></td>
                <td class="d-none d-md-table-cell"><?= $t['shipped_at'] ? e(($t['shipped_by_name'] ?? '') . ' · ' . format_ts($t['shipped_at'], $tz, 'M j, g:i A')) : '' ?></td>
                <td class="d-none d-md-table-cell"><?= $t['received_at'] ? e(($t['received_by_name'] ?? '') . ' · ' . format_ts($t['received_at'], $tz, 'M j, g:i A')) : '' ?></td></tr>
        <?php endforeach; ?>
    </tbody></table></div></div></div>
    <?php if ($page > 1 || $more): ?><nav class="mt-3"><ul class="pagination mb-0">
        <?php if ($page > 1): ?><li class="page-item"><?= hx_link($qs(['page' => $page - 1]), 'Previous', 'page-link') ?></li><?php endif; ?>
        <?php if ($more): ?><li class="page-item"><?= hx_link($qs(['page' => $page + 1]), 'Next', 'page-link') ?></li><?php endif; ?>
    </ul></nav><?php endif; ?>
</div>

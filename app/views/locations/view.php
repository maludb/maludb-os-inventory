<?php /** One location (screen `location-view`). Data: l, tab, tabs, tabdata (levels, totals, movements, transfers, counts, trail), mayWrite, mayAdjust, seesCost, tz, here, notice */
$id = (int) $l['location_id'];
$tabUrl = static fn (string $t): string => '/locations/' . $id . ($t === 'levels' ? '' : '?tab=' . $t);
$actions = '';
if ($mayWrite) {
    $actions .= hx_link(with_back('/locations/' . $id . '/edit', $here), '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-light btn-touch', 'id="location-view-edit-btn"') . ' ';
    $actions .= '<form method="post" action="/locations/archive.php" hx-post="/locations/archive.php" hx-target="#flash" class="d-inline" hx-confirm="' . e(($l['active'] ? 'Archive ' : 'Restore ') . $l['name'] . '?') . '">' . csrf_field()
        . '<input type="hidden" name="location" value="' . $id . '"><input type="hidden" name="active" value="' . ($l['active'] ? 'no' : 'yes') . '">'
        . '<button type="submit" class="btn btn-light btn-touch" id="location-view-archive-btn">' . ($l['active'] ? 'Archive' : 'Restore') . '</button></form>';
}
?>
<?= view('shared/header.php', ['id' => 'location-view', 'title' => $l['name'], 'crumbs' => [['Home', '/'], ['Locations', '/locations/'], [$l['name'], null]], 'back' => back_link() ?? ['/locations/', 'Locations'], 'action' => $actions]) ?>
<div class="main-content" id="location-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="location-view-summary"><div class="card-body">
        <div class="chip-row"><?= location_kind_chip($l['kind']) ?><?= $l['is_sellable'] ? ' <span class="badge bg-soft-success text-success">sellable</span>' : ' <span class="badge bg-soft-secondary text-dark">not sellable</span>' ?><?= $l['allow_negative'] ? ' <span class="badge bg-soft-warning text-warning">allows negative</span>' : '' ?><?= $l['active'] ? '' : ' <span class="badge bg-soft-dark text-dark" id="location-view-archived">archived</span>' ?></div>
        <?php if ($l['department_name']): ?><div class="fs-12 mt-2">Run by <span class="fw-semibold"><?= e($l['department_name']) ?></span></div><?php endif; ?>
        <?php if ($l['address']): ?><div class="fs-12 text-muted mt-1" style="white-space: pre-line"><?= e($l['address']) ?></div><?php endif; ?>
        <div class="fs-12 mt-2"><span class="fw-semibold" id="location-view-units"><?= number_format((int) $l['units_on_hand']) ?></span> units on hand</div>
    </div></div>
    <div class="d-flex flex-wrap gap-1 mb-3" id="location-view-tabs">
        <?php foreach ($tabs as $k => $label): ?><?= hx_link($tabUrl($k), e($label), 'btn btn-touch ' . ($tab === $k ? 'btn-primary' : 'btn-light'), 'id="location-view-tab-' . $k . '"') ?><?php endforeach; ?>
    </div>
    <?php if ($tab === 'levels'): ?>
        <?= view('stock/partials/levels-table.php', ['rows' => $tabdata['levels'], 'totals' => $tabdata['totals'], 'showLocation' => false, 'here' => $here, 'footer' => true]) ?>
    <?php elseif ($tab === 'movements'): ?>
        <?= view('stock/partials/movements-table.php', ['rows' => $tabdata['movements'], 'here' => $here, 'tz' => $tz, 'mayReverse' => $mayAdjust, 'seesCost' => $seesCost, 'seesReceiptCost' => sees_receipt_cost(), 'empty' => 'Nothing has moved here yet.']) ?>
        <div class="mt-2"><?= hx_link('/stock/movements?location=' . $id, 'Every movement here', 'fs-12 fw-semibold', 'id="location-view-all-movements"') ?></div>
    <?php elseif ($tab === 'transfers'): ?>
        <div class="card" id="location-view-transfers"><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12"><thead class="thead-light"><tr><th>Number</th><th>Status</th><th>From → to</th><th class="text-end">Units</th></tr></thead><tbody>
            <?php if ($tabdata['transfers'] === []): ?><tr><td colspan="4" class="text-center text-muted py-4">No open transfer from or to here.</td></tr><?php endif; ?>
            <?php foreach ($tabdata['transfers'] as $t): ?><tr id="location-view-transfer-<?= (int) $t['transfer_id'] ?>"><td><?= hx_link(with_back('/transfers/' . (int) $t['transfer_id'], $here), e($t['number']), 'fw-semibold') ?></td><td><?= doc_status_chip($t['status']) ?></td><td><?= e($t['from_location']) ?> → <?= e($t['to_location']) ?></td><td class="text-end"><?= (int) $t['units'] ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div></div>
    <?php elseif ($tab === 'counts'): ?>
        <div class="card" id="location-view-counts"><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12"><thead class="thead-light"><tr><th>Number</th><th>Status</th><th>Started</th><th class="text-end">Lines</th><th class="text-end">Differing</th></tr></thead><tbody>
            <?php if ($tabdata['counts'] === []): ?><tr><td colspan="5" class="text-center text-muted py-4">No counts here yet.</td></tr><?php endif; ?>
            <?php foreach ($tabdata['counts'] as $c): ?><tr id="location-view-count-<?= (int) $c['count_id'] ?>"><td><?= hx_link(with_back('/counts/' . (int) $c['count_id'], $here), e($c['number']), 'fw-semibold') ?></td><td><?= doc_status_chip($c['status']) ?></td><td><?= e(format_ts($c['started_at'], $tz, 'M j, Y')) ?></td><td class="text-end"><?= (int) $c['line_count'] ?></td><td class="text-end"><?= (int) $c['lines_differing'] ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div></div>
    <?php else: ?>
        <div class="card" id="location-view-trail"><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12"><tbody>
            <?php if ($tabdata['trail'] === []): ?><tr><td class="text-muted py-3 text-center">Nothing yet.</td></tr><?php endif; ?>
            <?php foreach ($tabdata['trail'] as $r): ?><tr id="location-view-trail-row-<?= (int) $r['activity_id'] ?>"><td class="text-nowrap"><?= e(format_ts($r['occurred_at'], $tz, 'M j, g:i A')) ?></td><td><?= e(activity_sentence($r)) ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div></div>
    <?php endif; ?>
</div>

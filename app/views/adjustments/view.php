<?php /** One adjustment (screen `adjustment-view`). Data: a, lines, movements, seesCost, trail, focusDelta, tz, here, notice */
$id = (int) $a['adjustment_id'];
$draft = $a['status'] === 'draft';
$delta = array_sum(array_map(static fn (array $l): int => (int) $l['qty_delta'], $lines));
$actions = '';
if ($draft) {
    $actions = '<form method="post" action="/adjustments/post.php" hx-post="/adjustments/post.php" hx-target="#flash" class="d-inline" hx-confirm="' . e('Post ' . $a['number'] . ': ' . ($delta > 0 ? '+' : '') . $delta . ' units at ' . $a['location_name'] . ' (' . $a['reason_name'] . ')?') . '">' . csrf_field() . '<input type="hidden" name="adjustment" value="' . $id . '"><button type="submit" class="btn btn-primary btn-touch" id="adjustment-post"' . ($lines === [] ? ' disabled' : '') . '>Post</button></form> '
        . '<form method="post" action="/adjustments/cancel.php" hx-post="/adjustments/cancel.php" hx-target="#flash" class="d-inline" hx-confirm="' . e('Cancel ' . $a['number'] . '? Nothing has been posted.') . '">' . csrf_field() . '<input type="hidden" name="adjustment" value="' . $id . '"><button type="submit" class="btn btn-light btn-touch" id="adjustment-cancel">Cancel</button></form>';
}
?>
<?= view('shared/header.php', ['id' => 'adjustment-view', 'title' => $a['number'], 'crumbs' => [['Home', '/'], ['Adjustments', '/adjustments/'], [$a['number'], null]], 'back' => back_link() ?? ['/adjustments/', 'Adjustments'], 'action' => $actions === '' ? '' : '<div class="doc-actions">' . $actions . '</div>']) ?>
<div class="main-content" id="adjustment-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="adjustment-view-summary"><div class="card-body fs-12 d-flex flex-wrap align-items-center gap-2">
        <?= doc_status_chip($a['status'], 'adjustment-view-status') ?><span>at <?= hx_link(with_back('/locations/' . (int) $a['location_id'], $here), e($a['location_name']), 'fw-semibold') ?></span>
        <span class="badge bg-soft-warning text-warning" id="adjustment-view-reason"><?= e($a['reason_name']) ?></span><?= !$a['affects_qty'] ? '<span class="text-muted">moves the floor flag — on hand is unchanged</span>' : '' ?>
        <?php if ($a['posted_at']): ?><span class="text-muted">posted by <?= e($a['posted_by_name'] ?? '') ?> · <?= e(format_ts($a['posted_at'], $tz)) ?></span><?php endif; ?>
        <?php if ($draft): ?><?= hx_link(with_back('/adjustments/' . $id . '/edit', $here), 'Edit header', 'ms-auto', 'id="adjustment-view-edit"') ?><?php endif; ?>
        <?php if ($a['notes']): ?><div class="w-100 text-muted"><?= nl2br(e($a['notes'])) ?></div><?php endif; ?>
    </div></div>
    <?php if ($draft): ?>
        <div class="scan-sticky"><?= view('stock/partials/scan-field.php', ['prefix' => 'adjustment', 'action' => '/adjustments/lines/save.php', 'hidden' => ['adjustment' => $id], 'label' => 'Scan, then type the change', 'qty' => 'Change']) ?></div>
        <?= view('stock/partials/line-form.php', ['kind' => 'adjustment', 'docId' => $id, 'seesCost' => $seesCost, 'locations' => [], 'poLines' => [], 'open' => false]) ?>
    <?php endif; ?>
    <div class="card mb-3" id="adjustment-lines-card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="adjustment-lines-table">
        <thead class="thead-light"><tr><th class="d-none d-md-table-cell">#</th><th>SKU</th><th class="d-none d-md-table-cell">Product</th><th class="text-end">Change</th><th class="text-end">Unit cost</th><th class="d-none d-lg-table-cell">Note</th><th></th></tr></thead><tbody>
        <?php if ($lines === []): ?><tr><td colspan="7" class="text-center text-muted py-4" id="adjustment-lines-empty">No lines yet.</td></tr><?php endif; ?>
        <?php foreach ($lines as $l): ?><?= view('adjustments/partials/line-row.php', ['l' => $l, 'a' => $a, 'seesCost' => $seesCost, 'here' => $here]) ?><?php endforeach; ?>
    </tbody></table></div></div></div>
    <?php if ($a['status'] === 'posted'): ?>
        <h6 class="mt-3 mb-2">The movements it posted</h6>
        <?= view('stock/partials/movements-table.php', ['rows' => $movements, 'here' => $here, 'tz' => $tz, 'mayReverse' => true, 'seesCost' => $seesCost, 'seesReceiptCost' => sees_receipt_cost(), 'empty' => 'Nothing posted.']) ?>
    <?php endif; ?>
    <div class="card mt-3" id="adjustment-trail"><div class="card-header"><h5 class="card-title mb-0">Trail</h5></div><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12"><tbody>
        <?php if ($trail === []): ?><tr><td class="text-muted py-3 text-center">Nothing yet.</td></tr><?php endif; ?>
        <?php foreach ($trail as $t): ?><tr id="adjustment-trail-row-<?= (int) $t['activity_id'] ?>"><td class="text-nowrap"><?= e(format_ts($t['occurred_at'], $tz, 'M j, g:i A')) ?></td><td><?= e(activity_sentence($t)) ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div></div>
    <?= view('shared/attachments.php', ['recordType' => 'inventory_adjustment', 'recordId' => $id, 'tz' => $tz]) ?>
</div>

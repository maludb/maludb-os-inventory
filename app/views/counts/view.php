<?php /** The counting screen (screen `count-view`). Data: c, lines, movements, trail, mayReverse, tz, here, notice */
$id = (int) $c['count_id'];
$open = $c['status'] === 'open';
$counted = count(array_filter($lines, static fn (array $l): bool => $l['counted_qty'] !== null));
$differing = count(array_filter($lines, static fn (array $l): bool => $l['counted_qty'] !== null && (int) $l['counted_qty'] !== (int) $l['system_qty']));
$actions = '';
if ($open) {
    $actions = '<form method="post" action="/counts/post.php" hx-post="/counts/post.php" hx-target="#flash" class="d-inline" hx-confirm="' . e('Post ' . $c['number'] . ': ' . $differing . ' correction' . ($differing === 1 ? '' : 's') . '? Each is counted against on hand now.') . '">' . csrf_field() . '<input type="hidden" name="count" value="' . $id . '"><button type="submit" class="btn btn-primary btn-touch" id="count-post">Post</button></form> '
        . '<form method="post" action="/counts/cancel.php" hx-post="/counts/cancel.php" hx-target="#flash" class="d-inline" hx-confirm="' . e('Cancel ' . $c['number'] . '? Nothing is posted.') . '">' . csrf_field() . '<input type="hidden" name="count" value="' . $id . '"><button type="submit" class="btn btn-light btn-touch" id="count-cancel">Cancel</button></form>';
}
?>
<?= view('shared/header.php', ['id' => 'count-view', 'title' => $c['number'], 'crumbs' => [['Home', '/'], ['Counts', '/counts/'], [$c['number'], null]], 'back' => back_link() ?? ['/counts/', 'Counts'], 'action' => $actions === '' ? '' : '<div class="doc-actions">' . $actions . '</div>']) ?>
<div class="main-content" id="count-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="count-view-summary"><div class="card-body fs-12 d-flex flex-wrap align-items-center gap-2">
        <?= doc_status_chip($c['status'], 'count-view-status') ?><span>at <?= hx_link(with_back('/locations/' . (int) $c['location_id'], $here), e($c['location_name']), 'fw-semibold') ?></span>
        <span class="text-muted">started by <?= e($c['started_by_name'] ?? '') ?> · <?= e(format_ts($c['started_at'], $tz)) ?></span>
        <?php if ($c['posted_at']): ?><span class="text-muted">posted by <?= e($c['posted_by_name'] ?? '') ?> · <?= e(format_ts($c['posted_at'], $tz)) ?></span><?php endif; ?>
        <?php if ($c['notes']): ?><div class="w-100 text-muted"><?= nl2br(e($c['notes'])) ?></div><?php endif; ?>
    </div></div>
    <?php if ($open): ?><div class="scan-sticky"><?= view('stock/partials/scan-field.php', ['prefix' => 'count', 'action' => '/counts/lines/save.php', 'hidden' => ['count' => $id], 'label' => 'Scan each unit on the shelf', 'qty' => 'Counted']) ?></div><?php endif; ?>
    <div class="card mb-3" id="count-lines-card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="count-lines-table">
        <thead class="thead-light"><tr><th>SKU</th><th class="d-none d-md-table-cell">Product</th><th class="d-none d-md-table-cell">Size</th><th class="text-end">System</th><th class="text-end">Counted</th><th class="text-end">Difference</th><th class="d-none d-md-table-cell">By</th></tr></thead><tbody>
        <?php if ($lines === []): ?><tr><td colspan="7" class="text-center text-muted py-4" id="count-lines-empty">Nothing is held here — scan what is on the shelf.</td></tr><?php endif; ?>
        <?php foreach ($lines as $l): ?><?= view('counts/partials/count-line-row.php', ['l' => $l, 'c' => $c, 'here' => $here]) ?><?php endforeach; ?>
        </tbody>
        <tfoot><tr class="fw-semibold" id="count-lines-totals"><td colspan="3" class="d-none d-md-table-cell"></td><td colspan="4" class="text-end"><span id="count-lines-counted"><?= $counted ?></span> of <?= count($lines) ?> counted · <span id="count-lines-differing"><?= $differing ?></span> differing</td></tr></tfoot>
    </table></div></div></div>
    <?php if ($c['status'] === 'posted'): ?>
        <h6 class="mt-3 mb-2">The corrections it posted</h6>
        <?= view('stock/partials/movements-table.php', ['rows' => $movements, 'here' => $here, 'tz' => $tz, 'mayReverse' => $mayReverse, 'seesCost' => sees_cost(), 'seesReceiptCost' => sees_receipt_cost(), 'empty' => 'Every counted line agreed — nothing to correct.']) ?>
    <?php endif; ?>
    <div class="card mt-3" id="count-trail"><div class="card-header"><h5 class="card-title mb-0">Trail</h5></div><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12"><tbody>
        <?php if ($trail === []): ?><tr><td class="text-muted py-3 text-center">Nothing yet.</td></tr><?php endif; ?>
        <?php foreach ($trail as $r): ?><tr id="count-trail-row-<?= (int) $r['activity_id'] ?>"><td class="text-nowrap"><?= e(format_ts($r['occurred_at'], $tz, 'M j, g:i A')) ?></td><td><?= e(activity_sentence($r)) ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div></div>
</div>

<?php /** The receiving screen (screen `receipt-view`). Data: r, lines, movements, poLines, amount, seesCost, locations, attachments, trail, mayReverse, tz, here, notice */
$id = (int) $r['goods_receipt_id'];
$draft = $r['status'] === 'draft';
$units = array_sum(array_map(static fn (array $l): int => (int) $l['qty'], $lines));
$actions = '';
if ($draft) {
    $actions = '<form method="post" action="/receipts/post.php" hx-post="/receipts/post.php" hx-target="#flash" class="d-inline" hx-confirm="' . e('Post ' . $r['number'] . ': ' . $units . ' units into ' . $r['location_name'] . '?') . '">' . csrf_field() . '<input type="hidden" name="receipt" value="' . $id . '"><button type="submit" class="btn btn-primary btn-touch" id="receipt-post"' . ($lines === [] ? ' disabled' : '') . '>Post</button></form> '
        . '<form method="post" action="/receipts/cancel.php" hx-post="/receipts/cancel.php" hx-target="#flash" class="d-inline" hx-confirm="' . e('Cancel ' . $r['number'] . '? Nothing has been posted.') . '">' . csrf_field() . '<input type="hidden" name="receipt" value="' . $id . '"><button type="submit" class="btn btn-light btn-touch" id="receipt-cancel">Cancel</button></form>';
}
?>
<?= view('shared/header.php', ['id' => 'receipt-view', 'title' => $r['number'], 'crumbs' => [['Home', '/'], ['Receipts', '/receipts/'], [$r['number'], null]], 'back' => back_link() ?? ['/receipts/', 'Receipts'], 'action' => $actions === '' ? '' : '<div class="doc-actions">' . $actions . '</div>']) ?>
<div class="main-content" id="receipt-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="receipt-view-summary"><div class="card-body fs-12">
        <div class="d-flex flex-wrap align-items-center gap-2"><?= doc_status_chip($r['status'], 'receipt-view-status') ?>
            <span>into <?= hx_link(with_back('/locations/' . (int) $r['location_id'], $here), e($r['location_name']), 'fw-semibold') ?></span>
            <span><?= $r['supplier_id'] !== null ? 'from ' . hx_link(with_back('/suppliers/' . (int) $r['supplier_id'], $here), e($r['supplier_name'] ?? ''), 'fw-semibold') : 'a free receipt' ?></span>
            <?php if ($r['purchase_order_id'] !== null): ?><span>against <?= hx_link(with_back('/purchasing/' . (int) $r['purchase_order_id'], $here), e($r['purchase_order_number'] ?? ''), 'fw-semibold') ?></span><?php endif; ?>
            <span class="text-muted">received <?= e(format_date($r['received_on'])) ?></span>
            <?php if ($r['delivery_note_ref']): ?><span class="text-muted">· delivery note <?= e($r['delivery_note_ref']) ?></span><?php endif; ?>
            <?php if ($draft): ?><?= hx_link(with_back('/receipts/' . $id . '/edit', $here), 'Edit header', 'ms-auto', 'id="receipt-view-edit"') ?><?php endif; ?>
        </div>
        <?php if ($r['notes']): ?><div class="text-muted mt-1"><?= nl2br(e($r['notes'])) ?></div><?php endif; ?>
        <?php if ($r['posted_at']): ?><div class="text-muted mt-1">Posted by <?= e($r['posted_by_name'] ?? '') ?> · <?= e(format_ts($r['posted_at'], $tz)) ?></div><?php endif; ?>
    </div></div>
    <?php if ($draft): ?>
        <div class="scan-sticky"><?= view('stock/partials/scan-field.php', ['prefix' => 'receipt', 'action' => '/receipts/lines/save.php', 'hidden' => ['receipt' => $id], 'label' => 'Scan a carton', 'qty' => null]) ?></div>
        <?= view('stock/partials/line-form.php', ['kind' => 'receipt', 'docId' => $id, 'seesCost' => $seesCost, 'locations' => $locations, 'poLines' => $poLines, 'open' => false]) ?>
    <?php endif; ?>
    <div class="card mb-3" id="receipt-lines-card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="receipt-lines-table">
        <thead class="thead-light"><tr><th class="d-none d-md-table-cell">#</th><th>SKU</th><th class="d-none d-md-table-cell">Product</th><th class="d-none d-md-table-cell">Size</th><th class="text-end">Qty</th><th class="text-end">Unit cost</th><th class="d-none d-lg-table-cell">Put away</th><th class="d-none d-lg-table-cell">Discrepancy</th><th></th></tr></thead>
        <tbody>
        <?php if ($lines === []): ?><tr><td colspan="9" class="text-center text-muted py-4" id="receipt-lines-empty">No lines yet — scan the first carton.</td></tr><?php endif; ?>
        <?php foreach ($lines as $l): ?><?= view('receipts/partials/line-row.php', ['l' => $l, 'r' => $r, 'seesCost' => $seesCost, 'locations' => $locations, 'here' => $here]) ?><?php endforeach; ?>
        </tbody>
        <?php if ($lines !== []): ?><tfoot><tr class="fw-semibold" id="receipt-lines-totals"><td class="d-none d-md-table-cell"></td><td>Total</td><td class="d-none d-md-table-cell"></td><td class="d-none d-md-table-cell"></td><td class="text-end" id="receipt-lines-units"><?= $units ?></td><td class="text-end" id="receipt-lines-amount"><?= cost_cell(number_format($amount, 2, '.', ''), $seesCost) ?></td><td class="d-none d-lg-table-cell"></td><td class="d-none d-lg-table-cell"></td><td></td></tr></tfoot><?php endif; ?>
    </table></div></div></div>
    <?php if (!$draft && $r['status'] === 'posted'): ?>
        <h6 class="mt-3 mb-2">The movements it posted</h6>
        <?= view('stock/partials/movements-table.php', ['rows' => $movements, 'here' => $here, 'tz' => $tz, 'mayReverse' => $mayReverse, 'seesCost' => sees_cost(), 'seesReceiptCost' => $seesCost, 'empty' => 'Nothing posted.']) ?>
        <?= view('shared/attachments.php', ['recordType' => 'goods_receipt', 'recordId' => (int) $r['goods_receipt_id'], 'tz' => $tz]) ?>
    <?php endif; ?>
    <div class="card mt-3" id="receipt-trail"><div class="card-header"><h5 class="card-title mb-0">Trail</h5></div><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12"><tbody>
        <?php if ($trail === []): ?><tr><td class="text-muted py-3 text-center">Nothing yet.</td></tr><?php endif; ?>
        <?php foreach ($trail as $t): ?><tr id="receipt-trail-row-<?= (int) $t['activity_id'] ?>"><td class="text-nowrap"><?= e(format_ts($t['occurred_at'], $tz, 'M j, g:i A')) ?></td><td><?= e(activity_sentence($t)) ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div></div>
</div>

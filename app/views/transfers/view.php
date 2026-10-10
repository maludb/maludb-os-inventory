<?php /** One transfer (screen `transfer-view`). Data: t, lines, movements, trail, mayReverse, tz, here, notice */
$id = (int) $t['transfer_id'];
$units = (int) $t['units'];
$left = $t['status'] === 'received' ? $units - (int) $t['units_received'] : 0;
$actions = '';
if ($t['status'] === 'draft') {
    $actions = '<form method="post" action="/transfers/send.php" hx-post="/transfers/send.php" hx-target="#flash" class="d-inline" hx-confirm="' . e('Send ' . $t['number'] . ': ' . $units . ' units leave ' . $t['from_location'] . '?') . '">' . csrf_field() . '<input type="hidden" name="transfer" value="' . $id . '"><button type="submit" class="btn btn-primary btn-touch" id="transfer-send"' . ($lines === [] ? ' disabled' : '') . '>Send</button></form> '
        . '<form method="post" action="/transfers/cancel.php" hx-post="/transfers/cancel.php" hx-target="#flash" class="d-inline" hx-confirm="' . e('Cancel ' . $t['number'] . '? Nothing has moved.') . '">' . csrf_field() . '<input type="hidden" name="transfer" value="' . $id . '"><button type="submit" class="btn btn-light btn-touch" id="transfer-cancel">Cancel</button></form>';
} elseif ($t['status'] === 'in_transit') {
    $actions = '<button type="submit" form="transfer-receive-form" class="btn btn-primary btn-touch" id="transfer-receive">Receive</button>';
}
?>
<?= view('shared/header.php', ['id' => 'transfer-view', 'title' => $t['number'], 'crumbs' => [['Home', '/'], ['Transfers', '/transfers/'], [$t['number'], null]], 'back' => back_link() ?? ['/transfers/', 'Transfers'], 'action' => $actions === '' ? '' : '<div class="doc-actions">' . $actions . '</div>']) ?>
<div class="main-content" id="transfer-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="transfer-view-summary"><div class="card-body fs-12 d-flex flex-wrap align-items-center gap-2">
        <?= doc_status_chip($t['status'], 'transfer-view-status') ?>
        <span><?= hx_link(with_back('/locations/' . (int) $t['from_location_id'], $here), e($t['from_location']), 'fw-semibold') ?> → <?= hx_link(with_back('/locations/' . (int) $t['to_location_id'], $here), e($t['to_location']), 'fw-semibold') ?></span>
        <?php if ($t['shipped_at']): ?><span class="text-muted">sent by <?= e($t['shipped_by_name'] ?? '') ?> · <?= e(format_ts($t['shipped_at'], $tz)) ?></span><?php endif; ?>
        <?php if ($t['received_at']): ?><span class="text-muted">received by <?= e($t['received_by_name'] ?? '') ?> · <?= e(format_ts($t['received_at'], $tz)) ?></span><?php endif; ?>
        <?php if ($t['status'] === 'draft'): ?><?= hx_link(with_back('/transfers/' . $id . '/edit', $here), 'Edit header', 'ms-auto', 'id="transfer-view-edit"') ?><?php endif; ?>
        <?php if ($t['notes']): ?><div class="w-100 text-muted"><?= nl2br(e($t['notes'])) ?></div><?php endif; ?>
    </div></div>
    <?php if ($left > 0): ?><div class="alert alert-warning fs-12" id="transfer-view-left">The document holds <?= $left ?> unit<?= $left === 1 ? '' : 's' ?> left in transit — adjust or reverse them; a stock loss is never silent.</div><?php endif; ?>
    <?php if ($t['status'] === 'draft'): ?>
        <div class="scan-sticky"><?= view('stock/partials/scan-field.php', ['prefix' => 'transfer', 'action' => '/transfers/lines/save.php', 'hidden' => ['transfer' => $id], 'label' => 'Scan what moves', 'qty' => null]) ?></div>
        <?= view('stock/partials/line-form.php', ['kind' => 'transfer', 'docId' => $id, 'seesCost' => false, 'locations' => [], 'poLines' => [], 'open' => false]) ?>
    <?php elseif ($t['status'] === 'in_transit'): ?>
        <form method="post" action="/transfers/receive.php" hx-post="/transfers/receive.php" hx-target="#flash" id="transfer-receive-form" hx-confirm="<?= e('Receive ' . $t['number'] . ' into ' . $t['to_location'] . '? A short line stays in transit on the document.') ?>"><?= csrf_field() ?><input type="hidden" name="transfer" value="<?= $id ?>"></form>
        <div class="fs-12 text-muted mb-2" id="transfer-view-receive-help">Type what arrived on each line, then Receive.</div>
    <?php endif; ?>
    <div class="card mb-3" id="transfer-lines-card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="transfer-lines-table">
        <thead class="thead-light"><tr><th class="d-none d-md-table-cell">#</th><th>SKU</th><th class="d-none d-md-table-cell">Product</th><th class="text-end">Qty</th><th class="text-end">Received</th><th></th></tr></thead><tbody>
        <?php if ($lines === []): ?><tr><td colspan="6" class="text-center text-muted py-4" id="transfer-lines-empty">No lines yet — scan what moves.</td></tr><?php endif; ?>
        <?php foreach ($lines as $l): ?><?= view('transfers/partials/line-row.php', ['l' => $l, 't' => $t, 'here' => $here]) ?><?php endforeach; ?>
    </tbody></table></div></div></div>
    <?php if ($movements !== []): ?>
        <h6 class="mt-3 mb-2">The movements it posted</h6>
        <?= view('stock/partials/movements-table.php', ['rows' => $movements, 'here' => $here, 'tz' => $tz, 'mayReverse' => $mayReverse, 'seesCost' => sees_cost(), 'seesReceiptCost' => sees_receipt_cost(), 'empty' => 'Nothing posted.']) ?>
    <?php endif; ?>
    <div class="card mt-3" id="transfer-trail"><div class="card-header"><h5 class="card-title mb-0">Trail</h5></div><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12"><tbody>
        <?php if ($trail === []): ?><tr><td class="text-muted py-3 text-center">Nothing yet.</td></tr><?php endif; ?>
        <?php foreach ($trail as $r): ?><tr id="transfer-trail-row-<?= (int) $r['activity_id'] ?>"><td class="text-nowrap"><?= e(format_ts($r['occurred_at'], $tz, 'M j, g:i A')) ?></td><td><?= e(activity_sentence($r)) ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div></div>
    <?= view('shared/attachments.php', ['recordType' => 'inventory_transfer', 'recordId' => $id, 'tz' => $tz]) ?>
</div>

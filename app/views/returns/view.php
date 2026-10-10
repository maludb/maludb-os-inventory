<?php
/** One return (screen `return-view`). Data: r (find_return), lines, may, refundShown, timeline, locations, tz, here, notice */
$rid = (int) $r['return_id'];
$st = $r['status'];
$open = in_array($st, ['requested', 'approved'], true);
$btn = static fn (string $url, string $label, string $icon, string $id, string $cls = 'btn-light'): string => hx_link(with_back($url, $here), '<i class="' . $icon . ' me-1"></i>' . e($label), 'btn ' . $cls . ' btn-touch', 'id="' . $id . '"');
$who = static fn (?string $name, ?string $at): string => ($name !== null ? e($name) : 'someone') . ($at !== null ? ' · ' . e(format_ts($at, $tz, 'M j, g:i A')) : '');
$name = static function (?int $m): ?string { return $m === null ? null : (string) (one_value(db(), 'SELECT display_name FROM mcp_members WHERE member_id = :m', ['m' => $m]) ?? 'a former member'); };
?>
<?= view('shared/header.php', ['id' => 'return-view', 'title' => $r['number'], 'crumbs' => [['Home', '/'], ['Returns', '/returns/'], [$r['number'], null]], 'back' => back_link() ?? ['/returns/', 'Returns']]) ?>
<div class="main-content" id="return-view-content" data-entity="return_authorization">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="return-view-summary"><div class="card-body row g-2 fs-12">
        <div class="col-12 d-flex flex-wrap align-items-center gap-2"><span class="fs-16 fw-semibold" id="return-view-number"><?= e($r['number']) ?></span> <?= return_status_chip($st, 'return-view-status') ?></div>
        <div class="col-6 col-md-3"><span class="text-muted">Order</span><div><?= hx_link(with_back('/orders/' . (int) $r['sales_order_id'], $here), e($r['order_number']), 'fw-semibold', 'id="return-view-order"') ?></div></div>
        <div class="col-6 col-md-3"><span class="text-muted">Customer</span><div><?= hx_link(with_back('/customers/' . (int) $r['customer_id'], $here), e($r['customer_name']), 'fw-semibold', 'id="return-view-customer"') ?></div></div>
        <div class="col-6 col-md-3"><span class="text-muted">How it comes back</span><div id="return-view-method"><?= e(return_when_words($r)) ?></div></div>
        <div class="col-6 col-md-3"><span class="text-muted">Comes back to</span><div id="return-view-location"><?= $r['location_id'] !== null ? hx_link(with_back('/locations/' . (int) $r['location_id'], $here), e($r['location_name'] ?? '—')) : '—' ?></div></div>
        <div class="col-12 col-md-6"><span class="text-muted">Requested</span><div><?= $who($r['requested_by_name'] ?? null, $r['created_at']) ?></div></div>
        <?php if ($r['approved_at'] !== null): ?><div class="col-12 col-md-6"><span class="text-muted">Approved</span><div><?= $who($name($r['approved_by']), $r['approved_at']) ?></div></div><?php endif; ?>
        <?php if ($r['received_at'] !== null): ?><div class="col-12 col-md-6"><span class="text-muted">Received</span><div><?= $who($name($r['received_by']), $r['received_at']) ?></div></div><?php endif; ?>
        <?php if ($r['closed_at'] !== null): ?><div class="col-12 col-md-6"><span class="text-muted">Closed</span><div><?= $who($name($r['closed_by']), $r['closed_at']) ?></div></div><?php endif; ?>
        <?php if ($r['denied_at'] !== null): ?><div class="col-12 col-md-6"><span class="text-muted">Denied</span><div><?= $who($name($r['denied_by']), $r['denied_at']) ?></div></div>
            <div class="col-12 text-danger" id="return-view-deny-reason">Denied: <?= e($r['deny_reason'] ?? '') ?></div><?php endif; ?>
        <?php if (in_array($st, ['closed'], true) || (float) $r['refund_amount'] > 0 || (float) $r['restocking_fee'] > 0): ?>
        <div class="col-6 col-md-3"><span class="text-muted">Refund recorded</span><div class="fw-semibold" id="return-view-refund"><?= money($r['refund_amount']) ?></div></div>
        <div class="col-6 col-md-3"><span class="text-muted">Restocking fee</span><div class="fw-semibold" id="return-view-fee"><?= money($r['restocking_fee']) ?></div></div>
        <?php endif; ?>
        <?php if ($r['notes']): ?><div class="col-12" style="white-space: pre-line"><span class="text-muted">Notes</span><div><?= e($r['notes']) ?></div></div><?php endif; ?>
    </div></div>

    <div class="d-flex flex-wrap gap-2 mb-3" id="return-view-actions">
        <?php if ($st === 'requested' && $may['authorize']): ?>
            <form method="post" action="/returns/approve.php" hx-post="/returns/approve.php" hx-target="#flash" class="d-inline" hx-confirm="<?= e('Approve ' . $r['number'] . '?') ?>"><?= csrf_field() ?><input type="hidden" name="return" value="<?= $rid ?>">
                <button type="submit" class="btn btn-primary btn-touch" id="return-view-approve-btn"><i class="feather-check me-1"></i>Approve</button></form>
        <?php endif; ?>
        <?php if ($st === 'approved' && $may['receive']): ?><?= $btn('/returns/' . $rid . '/receive', 'Receive', 'feather-download', 'return-view-receive-btn', 'btn-primary') ?><?php endif; ?>
        <?php if ($open && $may['write']): ?><?= $btn('/returns/' . $rid . '/edit', 'Edit', 'feather-edit-2', 'return-view-edit-btn') ?><?php endif; ?>
        <?php if ($st === 'closed' && $may['pay'] && (float) $r['refund_amount'] > 0 && $refundShown === false): ?>
            <?= $btn('/orders/' . (int) $r['sales_order_id'] . '/payment?kind=refund&amount=' . rawurlencode(number_format((float) $r['refund_amount'], 2, '.', '')), 'Record the refund', 'feather-credit-card', 'return-view-refund-btn', 'btn-primary') ?>
        <?php endif; ?>
    </div>
    <?php if ($open && $may['authorize']): ?>
    <details class="card mb-3" id="return-view-deny"><summary class="card-body py-2 fw-semibold d-flex text-danger" id="return-view-deny-btn" style="cursor: pointer">Deny the return</summary><?= view('returns/partials/deny-form.php', ['r' => $r]) ?></details>
    <?php endif; ?>
    <?php if ($st === 'received' && $may['authorize']): ?>
    <details class="card mb-3" id="return-view-close" open><summary class="card-body py-2 fw-semibold d-flex" id="return-view-close-btn" style="cursor: pointer">Close the return</summary><?= view('returns/partials/close-form.php', ['r' => $r]) ?></details>
    <?php endif; ?>

    <div class="card mb-3" id="return-view-lines"><div class="card-header fw-semibold">Lines</div><div class="card-body p-0"><div class="table-responsive">
        <table class="table mb-0 fs-12" id="return-view-lines-table"><thead class="thead-light"><tr><th>#</th><th>Item</th><th class="text-end">Qty</th><th class="text-end">Received</th><th>Reason</th><th>Disposition</th><th class="d-none d-md-table-cell">Location</th><th class="d-none d-md-table-cell">Condition</th><th></th></tr></thead><tbody>
            <?php if ($lines === []): ?><tr><td colspan="9" class="text-center text-muted py-3">No lines.</td></tr><?php endif; ?>
            <?php foreach ($lines as $l): ?><?= view('returns/partials/line-row.php', ['l' => $l, 'r' => $r, 'may' => $may, 'open' => $open, 'locations' => $locations, 'here' => $here]) ?><?php endforeach; ?>
        </tbody></table>
    </div></div></div>

    <?= view('shared/notes.php', ['recordType' => 'return_authorization', 'recordId' => $rid, 'tz' => $tz]) ?>
    <?= view('shared/attachments.php', ['recordType' => 'return', 'recordId' => $rid, 'tz' => $tz]) ?>
    <?= view('returns/partials/timeline.php', ['rows' => $timeline, 'tz' => $tz]) ?>
</div>

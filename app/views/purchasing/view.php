<?php
/** One purchase order (screen `purchase-order-view`). Data: o (find_purchase_order), tab, tabs, extra (notes, attachments, trail), may, tz, here, notice */
$poid = (int) $o['purchase_order_id'];
$st = $o['status'];
$tabUrl = static fn (string $t): string => '/purchasing/' . $poid . ($t === 'po' ? '' : '?tab=' . $t);
$btn = static fn (string $url, string $label, string $icon, string $id, string $cls = 'btn-light'): string => hx_link(with_back($url, $here), '<i class="' . $icon . ' me-1"></i>' . e($label), 'btn ' . $cls . ' btn-touch', 'id="' . $id . '"');
$post = static fn (string $action, string $label, string $id, array $fields = [], string $confirm = '', string $cls = 'btn-light'): string => '<form method="post" action="' . e($action) . '" hx-post="' . e($action) . '" hx-target="#flash" class="d-inline"'
    . ($confirm !== '' ? ' hx-confirm="' . e($confirm) . '"' : '') . '>' . csrf_field() . '<input type="hidden" name="purchase_order" value="' . $poid . '">'
    . implode('', array_map(static fn ($k, $v) => '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">', array_keys($fields), $fields)) . '<button type="submit" class="btn ' . $cls . ' btn-touch" id="' . e($id) . '">' . e($label) . '</button></form>';
$sent = in_array($st, PO_SENT_STATUSES, true);
$actions = '';
if ($may['write']) {
    if ($st === 'draft') { $actions .= $btn('/purchasing/' . $poid . '/edit', 'Edit', 'feather-edit-2', 'po-view-edit-btn') . ' ' . $btn('/purchasing/' . $poid . '/send', 'Send / Place', 'feather-send', 'po-view-send-btn', 'btn-primary') . ' '; }
    if ($st === 'sent') { $actions .= $btn('/purchasing/' . $poid . '/send', 'Place', 'feather-check', 'po-view-place-btn') . ' '; }
    if ($sent) { $actions .= '<a href="#po-ack" class="btn btn-light btn-touch" id="po-view-ack-btn"><i class="feather-check-circle me-1"></i>Acknowledge</a> '; }
}
if ($may['receive'] && $o['kind'] === 'stock' && $sent) { $actions .= $btn('/purchasing/' . $poid . '/receive', 'Receive', 'feather-download', 'po-view-receive-btn', 'btn-primary') . ' '; }
if ($may['write']) {
    if (in_array($st, ['sent', 'acknowledged', 'partial', 'received'], true)) { $actions .= $post('/purchasing/close.php', 'Close', 'po-view-close-btn', [], 'Close ' . $o['number'] . '? Lines not received close short.') . ' '; }
    if ($st !== 'received' && !in_array($st, ['closed', 'closed_short', 'cancelled'], true)) { $actions .= '<a href="#po-cancel" class="btn btn-light-danger btn-touch" id="po-view-cancel-btn"><i class="feather-x-circle me-1"></i>Cancel</a> '; }
    if ($st !== 'draft' && !in_array($st, ['received', 'closed', 'closed_short', 'cancelled'], true)) { $actions .= $post('/purchasing/link-rotate.php', 'Rotate link', 'po-view-rotate-btn', [], "Stop the supplier's current link? The new one is emailed to them at once."); }
}
$cancellable = $may['write'] && in_array($st, ['draft', 'sent', 'acknowledged'], true);
?>
<?= view('shared/header.php', ['id' => 'purchase-order-view', 'title' => $o['number'], 'crumbs' => [['Home', '/'], ['Purchase orders', '/purchasing/'], [$o['number'], null]], 'back' => back_link() ?? ['/purchasing/', 'Purchase orders']]) ?>
<div class="main-content" id="purchase-order-view-content" data-entity="purchase_order">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="po-view-summary"><div class="card-body row g-2 fs-12">
        <div class="col-12 d-flex flex-wrap align-items-center gap-2"><span class="fs-16 fw-semibold" id="po-view-number"><?= e($o['number']) ?></span> <?= po_status_chip($st, 'po-view-status') ?> <?= po_kind_chip($o['kind']) ?><?= po_overdue_badge($o) ?><?= po_awaiting_badge($o) ?></div>
        <div class="col-6 col-md-3"><span class="text-muted">Supplier</span><div><?= hx_link(with_back('/suppliers/' . (int) $o['supplier_id'], $here), e($o['supplier_name']), 'fw-semibold', 'id="po-view-supplier"') ?></div>
            <?php if ($may['write'] && $o['supplier'] !== null): ?><div class="text-muted" id="po-view-supplier-facts"><?= e(SUPPLIER_ORDER_METHODS[$o['supplier']['order_method']] ?? $o['supplier']['order_method']) ?><?= $o['supplier']['account_number'] ? ' · account ' . e($o['supplier']['account_number']) : '' ?></div><?php endif; ?></div>
        <?php if ($o['sales_order_id'] !== null): ?><div class="col-6 col-md-3"><span class="text-muted">For the sales order</span><div><?= hx_link(with_back('/orders/' . (int) $o['sales_order_id'], $here), e((string) $o['sales_order_number']), 'fw-semibold', 'id="po-view-order"') ?></div><div class="text-muted"><?= e((string) $o['customer_name']) ?></div></div><?php endif; ?>
        <div class="col-6 col-md-3"><span class="text-muted">Ordered</span><div><?= e(format_date($o['ordered_on'])) ?></div></div>
        <div class="col-6 col-md-3"><span class="text-muted">Expected</span><div id="po-view-expected"><?= $o['expected_on'] ? e(format_date($o['expected_on'])) : '—' ?></div></div>
        <div class="col-6 col-md-3"><span class="text-muted">Their reference</span><div id="po-view-ref"><?= e($o['supplier_order_ref'] ?? '—') ?></div></div>
        <div class="col-6 col-md-3"><span class="text-muted">Sent</span><div id="po-view-sent"><?= $o['sent_at'] ? e(format_ts($o['sent_at'], $tz, 'M j, g:i A')) . ' via ' . e(PO_VIA[$o['sent_via']] ?? (string) $o['sent_via']) . ($o['sent_by_name'] ? ' · ' . e($o['sent_by_name']) : '') : '—' ?></div></div>
        <div class="col-6 col-md-3"><span class="text-muted">Acknowledged</span><div id="po-view-acknowledged"><?= $o['acknowledged_at'] ? e(format_ts($o['acknowledged_at'], $tz, 'M j, g:i A')) : '—' ?></div></div>
        <div class="col-6 col-md-3"><span class="text-muted">Total</span><div class="fw-semibold" id="po-view-total"><?= $o['cost_withheld'] ? '—' : money($o['total']) ?></div></div>
        <?php if ($o['cancel_reason']): ?><div class="col-12 text-danger" id="po-view-cancel-reason">Cancelled: <?= e($o['cancel_reason']) ?></div><?php endif; ?>
        <?php if ($o['notes']): ?><div class="col-12" style="white-space: pre-line"><span class="text-muted">Notes to the supplier</span><div id="po-view-notes-text"><?= e($o['notes']) ?></div></div><?php endif; ?>
        <?php if ($o['internal_notes']): ?><div class="col-12" style="white-space: pre-line"><span class="text-muted">Internal notes (not shown to the supplier)</span><div id="po-view-internal-notes"><?= e($o['internal_notes']) ?></div></div><?php endif; ?>
    </div></div>
    <div class="d-flex flex-wrap gap-2 mb-3" id="po-view-actions"><?= $actions ?></div>
    <div class="d-flex flex-wrap gap-1 mb-3" id="po-view-tabs">
        <?php foreach ($tabs as $k => $label): ?><?= hx_link($tabUrl($k), e($label), 'btn btn-touch ' . ($tab === $k ? 'btn-primary' : 'btn-light'), 'id="po-view-tab-' . $k . '"') ?><?php endforeach; ?>
    </div>
    <?php if ($tab === 'po'): ?>
        <?= view('purchasing/partials/lines.php', ['o' => $o, 'editable' => false, 'seesCost' => $may['cost'], 'form' => false, 'may' => $may, 'here' => $here]) ?>
        <div class="row g-3">
            <div class="col-12 col-lg-5 order-lg-2"><div id="po-totals"><?= view('purchasing/partials/totals.php', ['o' => $o, 'seesCost' => $may['cost']]) ?></div></div>
            <div class="col-12 col-lg-7 order-lg-1">
                <?php if ($may['write'] && $sent): ?><?= view('purchasing/partials/ack-form.php', ['o' => $o]) ?><?php endif; ?>
                <?= view('purchasing/partials/events.php', ['o' => $o, 'tz' => $tz]) ?>
                <?= view('purchasing/partials/receipts.php', ['o' => $o, 'here' => $here, 'may' => $may]) ?>
                <?php if ($may['write']): ?><?= view('purchasing/partials/link-state.php', ['o' => $o, 'tz' => $tz, 'may' => $may]) ?><?php endif; ?>
                <?= view('purchasing/partials/ship-to.php', ['o' => $o, 'may' => $may]) ?>
                <?php if ($cancellable): ?>
                <details class="card mb-3" id="po-cancel"><summary class="card-body py-2 fw-semibold d-flex text-danger">Cancel the purchase order</summary><div class="card-body pt-0">
                    <form method="post" action="/purchasing/cancel.php" hx-post="/purchasing/cancel.php" hx-target="#flash" id="po-cancel-form" hx-confirm="Cancel <?= e($o['number']) ?>?"><?= csrf_field() ?><input type="hidden" name="purchase_order" value="<?= $poid ?>">
                        <?php if ($st !== 'draft'): ?><div class="fs-12 text-muted mb-2">A sent order is cancelled with the supplier by you — tell them.</div><?php endif; ?>
                        <label class="form-label fs-12 text-muted" for="po-cancel-reason">Why</label><input type="text" name="reason" id="po-cancel-reason" class="form-control btn-touch" maxlength="500" required>
                        <button type="submit" class="btn btn-light-danger btn-touch mt-2" id="po-cancel-btn">Cancel the purchase order</button></form></div></details>
                <?php endif; ?>
            </div>
        </div>
        <div id="po-notes"><?php if ($o['note_count'] > 0): ?><div class="fs-12 text-muted mb-1"><?= hx_link($tabUrl('notes'), (int) $o['note_count'] . ' note' . ($o['note_count'] === 1 ? '' : 's')) ?></div><?php endif; ?></div>
        <div id="po-attachments"><?php if ($o['attachment_count'] > 0): ?><div class="fs-12 text-muted mb-1"><?= hx_link($tabUrl('attachments'), (int) $o['attachment_count'] . ' attachment' . ($o['attachment_count'] === 1 ? '' : 's')) ?></div><?php endif; ?></div>
    <?php elseif ($tab === 'notes'): ?>
        <div class="card" id="po-notes"><div class="card-body"><?php if ($extra['notes'] === []): ?><div class="text-muted text-center">No notes yet.</div><?php endif; ?>
            <?php foreach ($extra['notes'] as $n): ?><div class="border-bottom py-2 fs-12"><div class="text-muted"><?= e($n['member_name'] ?? '') ?> · <?= e(format_ts($n['created_at'], $tz, 'M j, g:i A')) ?></div><div style="white-space: pre-line"><?= e($n['body']) ?></div></div><?php endforeach; ?>
            <?php if (is_file(dirname(__DIR__) . '/shared/note-form.php')): ?><?= view('shared/note-form.php', ['recordType' => 'purchase_order', 'recordId' => $poid]) ?><?php endif; ?></div></div>
    <?php elseif ($tab === 'attachments'): ?>
        <div class="card" id="po-attachments"><div class="card-body"><?php if ($extra['attachments'] === []): ?><div class="text-muted text-center">No attachments yet.</div><?php endif; ?>
            <?php foreach ($extra['attachments'] as $a): ?><div class="py-1 fs-12"><a href="/files/<?= (int) $a['attachment_id'] ?>"><?= e($a['filename']) ?></a> <span class="text-muted"><?= e(number_format($a['byte_size'] / 1024, 0)) ?> kB</span></div><?php endforeach; ?>
            <?php if (is_file(dirname(__DIR__) . '/shared/attachment-form.php')): ?><?= view('shared/attachment-form.php', ['recordType' => 'purchase_order', 'recordId' => $poid]) ?><?php endif; ?></div></div>
    <?php else: ?>
        <div class="card" id="po-trail"><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12"><tbody>
            <?php if ($extra['trail'] === []): ?><tr><td class="text-muted py-3 text-center">Nothing yet.</td></tr><?php endif; ?>
            <?php foreach ($extra['trail'] as $r): ?><tr id="po-trail-row-<?= (int) $r['activity_id'] ?>"><td class="text-nowrap"><?= e(format_ts($r['occurred_at'], $tz, 'M j, g:i A')) ?></td><td><?= e(activity_sentence($r)) ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div></div>
    <?php endif; ?>
</div>

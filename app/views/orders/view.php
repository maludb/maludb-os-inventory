<?php
/** One order (screen `order-view`). Data: o (find_order), tab, tabs, extra (timeline, notes, attachments, trail), may, tz, here, locations, notice */
$oid = (int) $o['sales_order_id'];
$st = $o['status'];
$tabUrl = static fn (string $t): string => '/orders/' . $oid . ($t === 'order' ? '' : '?tab=' . $t);
$btn = static fn (string $url, string $label, string $icon, string $id, string $cls = 'btn-light'): string => hx_link(with_back($url, $here), '<i class="' . $icon . ' me-1"></i>' . e($label), 'btn ' . $cls . ' btn-touch', 'id="' . $id . '"');
$post = static fn (string $action, string $label, string $id, array $fields = [], string $confirm = '', string $cls = 'btn-light'): string => '<form method="post" action="' . e($action) . '" hx-post="' . e($action) . '" hx-target="#flash" class="d-inline"'
    . ($confirm !== '' ? ' hx-confirm="' . e($confirm) . '"' : '') . '>' . csrf_field() . '<input type="hidden" name="order" value="' . $oid . '">'
    . implode('', array_map(static fn ($k, $v) => '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">', array_keys($fields), $fields)) . '<button type="submit" class="btn ' . $cls . ' btn-touch" id="' . e($id) . '">' . e($label) . '</button></form>';
$undelivered = array_filter($o['shipments'], static fn ($s) => $s['delivered_at'] === null);
$shippable = false;
foreach ($o['lines'] as $l) { if (in_array($l['fulfilment_kind'], ['stock', 'pickup', 'backorder'], true) && $l['status'] !== 'cancelled' && $l['qty_shipped'] < $l['qty']) { $shippable = true; } }
$actions = '';
if ($st === 'quote' && $may['write']) { $actions .= $btn('/orders/' . $oid . '/edit', 'Edit', 'feather-edit-2', 'order-view-edit-btn') . ' ' . $btn('/orders/' . $oid . '/confirm', 'Confirm', 'feather-check', 'order-view-confirm-btn', 'btn-primary') . ' '; }
if ($st !== 'cancelled' && $may['pay']) { $actions .= $btn('/orders/' . $oid . '/payment?kind=' . ((float) $o['amount_paid'] > 0 ? 'balance' : 'deposit'), 'Record payment', 'feather-credit-card', 'order-view-payment-btn') . ' '; }
if ((float) $o['amount_paid'] > 0 && $may['pay']) { $actions .= $btn('/orders/' . $oid . '/payment?kind=refund', 'Refund', 'feather-corner-up-left', 'order-view-refund-btn') . ' '; }
if (!in_array($st, ['quote', 'cancelled'], true) && $may['send']) { $actions .= $btn('/orders/' . $oid . '/send', 'Send', 'feather-send', 'order-view-send-btn') . ' '; }
if (in_array($st, ['confirmed', 'in_fulfilment'], true) && $shippable && $may['ship']) { $actions .= $btn('/orders/' . $oid . '/ship', 'Ship', 'feather-truck', 'order-view-ship-btn', 'btn-primary') . ' '; }
if ($undelivered !== [] && $may['ship']) { $actions .= $post('/orders/deliver.php', 'Deliver', 'order-view-deliver-btn') . ' '; }
if ($st === 'delivered' && $may['write']) { $actions .= $post('/orders/close.php', 'Close', 'order-view-close-btn', [], '', 'btn-primary') . ' '; }
if (!in_array($st, ['quote', 'closed', 'cancelled'], true) && $may['send']) { $actions .= $post('/orders/link-rotate.php', 'Rotate link', 'order-view-rotate-btn', [], 'Stop the customer\'s current link? A new one goes out with the next send.') . ' '; }
$cancellable = $may['write'] && !in_array($st, ['closed', 'cancelled'], true) && !array_filter($o['lines'], static fn ($l) => $l['qty_shipped'] > $l['qty_returned']);
$mayNotify = $may['send'] && !in_array($st, ['quote', 'cancelled'], true);
?>
<?= view('shared/header.php', ['id' => 'order-view', 'title' => $o['number'], 'crumbs' => [['Home', '/'], ['Orders', '/orders/'], [$o['number'], null]], 'back' => back_link() ?? ['/orders/', 'Orders']]) ?>
<div class="main-content" id="order-view-content" data-entity="sales_order">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="order-view-summary"><div class="card-body row g-2 fs-12">
        <div class="col-12 d-flex flex-wrap align-items-center gap-2"><span class="fs-16 fw-semibold" id="order-view-number"><?= e($o['number']) ?></span> <?= order_status_chip($st, 'order-view-status') ?><?= late_badge($o) ?> <?= payment_status_chip($o['payment_status'], 'order-view-payment-status') ?></div>
        <div class="col-6 col-md-3"><span class="text-muted">Customer</span><div><?= hx_link(with_back('/customers/' . (int) $o['customer_id'], $here), e($o['customer_name']), 'fw-semibold', 'id="order-view-customer"') ?></div></div>
        <div class="col-6 col-md-3"><span class="text-muted">Salesperson</span><div><?= e($o['salesperson_name'] ?? '—') ?></div></div>
        <div class="col-6 col-md-3"><span class="text-muted">Store</span><div><?= e($o['location_name'] ?? '—') ?></div></div>
        <div class="col-6 col-md-3"><span class="text-muted">Delivery</span><div><?= e(DELIVERY_METHODS[$o['delivery_method']] ?? $o['delivery_method']) ?></div></div>
        <div class="col-6 col-md-3"><span class="text-muted">Ordered</span><div><?= e(format_date($o['ordered_on'])) ?></div></div>
        <div class="col-6 col-md-3"><span class="text-muted">Promised</span><div id="order-view-promised"><?= $o['promised_on'] ? e(format_date($o['promised_on'])) : '—' ?></div></div>
        <div class="col-6 col-md-3"><span class="text-muted">Total</span><div class="fw-semibold" id="order-view-total"><?= money($o['total']) ?></div></div>
        <div class="col-6 col-md-3"><span class="text-muted">Balance due</span><div class="fw-semibold" id="order-view-balance"><?= $st === 'quote' || $st === 'cancelled' ? '—' : money($o['balance_due']) ?></div></div>
        <?php if ($o['ship_to_city'] || $o['ship_to_name']): ?>
        <div class="col-12" id="order-view-ship-to"><span class="text-muted">Ship to</span><div style="white-space: pre-line"><?= e(implode("\n", array_filter([$o['ship_to_name'], $o['ship_to_address1'], $o['ship_to_address2'], trim(implode(' ', array_filter([$o['ship_to_city'], $o['ship_to_region'], $o['ship_to_postal'], $o['ship_to_country']])))]))) ?><?= $o['ship_to_phone'] ? "\n" . e($o['ship_to_phone']) : '' ?></div><?= $o['ship_to_notes'] ? '<div class="text-muted">' . e($o['ship_to_notes']) . '</div>' : '' ?></div>
        <?php endif; ?>
        <?php if ($o['cancel_reason']): ?><div class="col-12 text-danger" id="order-view-cancel-reason">Cancelled: <?= e($o['cancel_reason']) ?></div><?php endif; ?>
        <?php if ($o['customer_reference']): ?><div class="col-12"><span class="text-muted">Customer's reference</span> <?= e($o['customer_reference']) ?></div><?php endif; ?>
        <?php if ($o['notes']): ?><div class="col-12" style="white-space: pre-line"><span class="text-muted">Notes</span><div><?= e($o['notes']) ?></div></div><?php endif; ?>
    </div></div>
    <div class="d-flex flex-wrap gap-2 mb-3" id="order-view-actions"><?= $actions ?></div>
    <div class="d-flex flex-wrap gap-1 mb-3" id="order-view-tabs">
        <?php foreach ($tabs as $k => $label): ?><?= hx_link($tabUrl($k), e($label), 'btn btn-touch ' . ($tab === $k ? 'btn-primary' : 'btn-light'), 'id="order-view-tab-' . $k . '"') ?><?php endforeach; ?>
    </div>
    <?php if ($tab === 'order'): ?>
        <?= view('orders/partials/lines.php', ['o' => $o, 'editable' => false, 'seesCost' => $may['cost'], 'form' => false, 'locations' => $locations, 'here' => $here, 'mayCancel' => $may['write']]) ?>
        <div class="row g-3">
            <div class="col-12 col-lg-5 order-lg-2"><div id="order-totals"><?= view('orders/partials/totals.php', ['o' => $o]) ?></div></div>
            <div class="col-12 col-lg-7 order-lg-1">
                <?php if ($may['pay'] || $may['reports']): ?><?= view('orders/partials/payments.php', ['o' => $o, 'tz' => $tz, 'may' => $may]) ?><?php endif; ?>
                <?= view('orders/partials/shipments.php', ['o' => $o, 'tz' => $tz, 'may' => $may, 'here' => $here]) ?>
                <?= view('orders/partials/dropships.php', ['o' => $o, 'seesCost' => $may['cost'], 'here' => $here]) ?>
                <?php if ($may['send']): ?><?= view('orders/partials/link-state.php', ['o' => $o, 'tz' => $tz]) ?><?php endif; ?>
                <?php if ($o['returns'] !== []): ?><div class="card mb-3" id="order-returns"><div class="card-header fw-semibold">Returns</div><div class="card-body fs-12"><?php foreach ($o['returns'] as $r): ?><div id="order-return-<?= (int) $r['return_id'] ?>"><?= hx_link(with_back('/returns/' . (int) $r['return_id'], $here), e($r['number']), 'fw-semibold') ?> · <?= e(str_replace('_', ' ', $r['status'])) ?></div><?php endforeach; ?></div></div><?php endif; ?>
                <?php if ($mayNotify): ?>
                <details class="card mb-3" id="order-notify"><summary class="card-body py-2 fw-semibold d-flex">Tell the customer</summary><div class="card-body pt-0">
                    <form method="post" action="/orders/notify.php" hx-post="/orders/notify.php" hx-target="#flash" id="order-notify-form" hx-confirm="Send this notice to the customer?"><?= csrf_field() ?><input type="hidden" name="order" value="<?= $oid ?>">
                        <label class="form-label fs-12 text-muted" for="order-notify-kind">What</label>
                        <select name="kind" id="order-notify-kind" class="form-select btn-touch"><?php foreach (ORDER_NOTIFY_KINDS as $k => $w): ?><option value="<?= $k ?>"><?= e($w) ?></option><?php endforeach; ?></select>
                        <label class="form-label fs-12 text-muted mt-2" for="order-notify-promised">New date (for a date or a delay)</label><input type="date" name="promised_on" id="order-notify-promised" class="form-control btn-touch" min="<?= e($o['ordered_on']) ?>">
                        <label class="form-label fs-12 text-muted mt-2" for="order-notify-message">A message of your own</label><textarea name="message" id="order-notify-message" class="form-control" rows="2" maxlength="2000"></textarea>
                        <button type="submit" class="btn btn-light btn-touch mt-2" id="order-notify-btn">Send the notice</button></form></div></details>
                <?php endif; ?>
                <?php if ($cancellable): ?>
                <details class="card mb-3" id="order-cancel"><summary class="card-body py-2 fw-semibold d-flex text-danger">Cancel the order</summary><div class="card-body pt-0">
                    <form method="post" action="/orders/cancel.php" hx-post="/orders/cancel.php" hx-target="#flash" id="order-cancel-form" hx-confirm="Cancel <?= e($o['number']) ?>?"><?= csrf_field() ?><input type="hidden" name="order" value="<?= $oid ?>">
                        <label class="form-label fs-12 text-muted" for="order-cancel-reason">Why</label><input type="text" name="reason" id="order-cancel-reason" class="form-control btn-touch" maxlength="500" required>
                        <button type="submit" class="btn btn-light-danger btn-touch mt-2" id="order-cancel-btn">Cancel the order</button></form></div></details>
                <?php endif; ?>
            </div>
        </div>
        <div id="order-notes"><?php if ($o['note_count'] > 0): ?><div class="fs-12 text-muted mb-1"><?= hx_link($tabUrl('notes'), (int) $o['note_count'] . ' note' . ($o['note_count'] === 1 ? '' : 's')) ?></div><?php endif; ?></div>
        <div id="order-attachments"><?php if ($o['attachment_count'] > 0): ?><div class="fs-12 text-muted mb-1"><?= hx_link($tabUrl('attachments'), (int) $o['attachment_count'] . ' attachment' . ($o['attachment_count'] === 1 ? '' : 's')) ?></div><?php endif; ?></div>
    <?php elseif ($tab === 'timeline'): ?>
        <?= view('orders/partials/timeline.php', ['rows' => $extra['timeline'], 'tz' => $tz, 'seesCost' => $may['cost']]) ?>
    <?php elseif ($tab === 'notes'): ?>
        <?= view('shared/notes.php', ['recordType' => 'sales_order', 'recordId' => $oid, 'tz' => $tz]) ?>
    <?php elseif ($tab === 'attachments'): ?>
        <?= view('shared/attachments.php', ['recordType' => 'sales_order', 'recordId' => $oid, 'tz' => $tz]) ?>
    <?php else: ?>
        <div class="card" id="order-trail"><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12"><tbody>
            <?php if ($extra['trail'] === []): ?><tr><td class="text-muted py-3 text-center">Nothing yet.</td></tr><?php endif; ?>
            <?php foreach ($extra['trail'] as $r): ?><tr id="order-trail-row-<?= (int) $r['activity_id'] ?>"><td class="text-nowrap"><?= e(format_ts($r['occurred_at'], $tz, 'M j, g:i A')) ?></td><td><?= e(activity_sentence($r)) ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div></div>
    <?php endif; ?>
</div>

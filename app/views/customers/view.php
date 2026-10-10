<?php /** One customer (screen `customer-view`). Data: c, tab, tabs, tabdata (orders, returns, notes, attachments, trail), may, deletable, tz, here, notice */
$id = (int) $c['customer_id'];
$tabUrl = static fn (string $t): string => '/customers/' . $id . ($t === 'orders' ? '' : '?tab=' . $t);
$actions = '';
if ($may['quote']) { $actions .= hx_link(with_back('/orders/new?customer=' . $id, $here), '<i class="feather-plus me-1"></i>New quote', 'btn btn-primary btn-touch', 'id="customer-view-quote-btn"') . ' '; }
if ($may['write']) {
    $actions .= hx_link(with_back('/customers/' . $id . '/edit', $here), '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-light btn-touch', 'id="customer-view-edit-btn"') . ' ';
    $archived = $c['archived_at'] !== null;
    $actions .= '<form method="post" action="/customers/archive.php" hx-post="/customers/archive.php" hx-target="#flash" class="d-inline" hx-confirm="' . e(($archived ? 'Bring back ' : 'Archive ') . $c['name'] . '?') . '">' . csrf_field()
        . '<input type="hidden" name="customer" value="' . $id . '"><input type="hidden" name="archived" value="' . ($archived ? 'no' : 'yes') . '"><button type="submit" class="btn btn-light btn-touch" id="customer-view-archive-btn">' . ($archived ? 'Unarchive' : 'Archive') . '</button></form> ';
}
if ($may['delete'] && $deletable) {
    $actions .= '<form method="post" action="/customers/delete.php" hx-post="/customers/delete.php" hx-target="#flash" class="d-inline" hx-confirm="' . e('Delete ' . $c['name'] . '? This cannot be undone.') . '">' . csrf_field()
        . '<input type="hidden" name="customer" value="' . $id . '"><button type="submit" class="btn btn-light-danger btn-touch" id="customer-view-delete-btn">Delete</button></form>';
}
?>
<?= view('shared/header.php', ['id' => 'customer-view', 'title' => $c['name'], 'crumbs' => [['Home', '/'], ['Customers', '/customers/'], [$c['name'], null]], 'back' => back_link() ?? ['/customers/', 'Customers'], 'action' => $actions]) ?>
<div class="main-content" id="customer-view-content" data-entity="customer">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="customer-view-summary"><div class="card-body row g-2">
        <div class="col-12"><div class="chip-row"><?= customer_source_chip($c['source']) ?><?php if ($c['archived_at'] !== null): ?><span class="badge bg-soft-dark text-dark" id="customer-view-archived">archived</span><?php endif; ?><?php if ($c['email_opt_in']): ?><span class="badge bg-soft-success text-success">email OK</span><?php endif; ?></div></div>
        <?php if ($c['legal_name']): ?><div class="col-12 col-md-6 fs-12"><span class="text-muted">Legal name</span><div><?= e($c['legal_name']) ?></div></div><?php endif; ?>
        <?php if ($c['email']): ?><div class="col-12 col-md-6 fs-12"><span class="text-muted">Email</span><div id="customer-view-email"><?= e($c['email']) ?></div></div><?php endif; ?>
        <?php if ($c['phone']): ?><div class="col-6 col-md-3 fs-12"><span class="text-muted">Phone</span><div id="customer-view-phone"><?= e($c['phone']) ?></div></div><?php endif; ?>
        <?php if ($c['phone_alt']): ?><div class="col-6 col-md-3 fs-12"><span class="text-muted">Second phone</span><div><?= e($c['phone_alt']) ?></div></div><?php endif; ?>
        <?php if ($c['billing_address']): ?><div class="col-12 col-md-6 fs-12"><span class="text-muted">Billing address</span><div style="white-space: pre-line"><?= e($c['billing_address']) ?></div></div><?php endif; ?>
        <?php if ($c['shipping_address']): ?><div class="col-12 col-md-6 fs-12"><span class="text-muted">Shipping address</span><div style="white-space: pre-line"><?= e($c['shipping_address']) ?></div></div><?php endif; ?>
        <div class="col-12 fs-12 text-muted"><?= $c['tax_rate_name'] ? 'Tax: ' . e($c['tax_rate_name']) . ' · ' : '' ?><?= $c['terms_days'] !== null ? 'Terms: ' . (int) $c['terms_days'] . ' days · ' : '' ?><span id="customer-view-orders-count"><?= (int) $c['order_count'] ?></span> order<?= (int) $c['order_count'] === 1 ? '' : 's' ?>, <?= (int) $c['open_orders'] ?> open</div>
        <?php if ($c['notes']): ?><div class="col-12 fs-12"><span class="text-muted">Notes</span><div style="white-space: pre-line"><?= e($c['notes']) ?></div></div><?php endif; ?>
    </div></div>
    <div class="d-flex flex-wrap gap-1 mb-3" id="customer-view-tabs">
        <?php foreach ($tabs as $k => $label): ?><?= hx_link($tabUrl($k), e($label), 'btn btn-touch ' . ($tab === $k ? 'btn-primary' : 'btn-light'), 'id="customer-view-tab-' . $k . '"') ?><?php endforeach; ?>
    </div>
    <?php if ($tab === 'orders'): ?>
        <?php
        $open = array_values(array_filter($tabdata['orders'], static fn ($o) => !in_array($o['status'], ['closed', 'cancelled'], true)));
        $done = array_values(array_filter($tabdata['orders'], static fn ($o) => in_array($o['status'], ['closed', 'cancelled'], true)));
        ?>
        <div class="card" id="customer-view-orders"><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12"><thead class="thead-light"><tr><th>Order</th><th>Ordered</th><th>Status</th><th class="text-end">Total</th><th>Payment</th></tr></thead><tbody>
            <?php if ($tabdata['orders'] === []): ?><tr><td colspan="5" class="text-center text-muted py-4">No orders yet.</td></tr><?php endif; ?>
            <?php foreach (array_merge($open, $done) as $o): ?><tr id="customer-view-order-<?= (int) $o['sales_order_id'] ?>"><td><?= hx_link(with_back('/orders/' . (int) $o['sales_order_id'], $here), e($o['number']), 'fw-semibold') ?></td><td><?= e(format_date($o['ordered_on'])) ?></td><td><?= order_status_chip($o['status']) ?><?= late_badge($o) ?></td><td class="text-end"><?= money($o['total']) ?></td><td><?= payment_status_chip($o['payment_status']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div></div>
    <?php elseif ($tab === 'returns'): ?>
        <div class="card" id="customer-view-returns"><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12"><thead class="thead-light"><tr><th>Return</th><th>Order</th><th>Status</th><th class="text-end">Refund</th></tr></thead><tbody>
            <?php if ($tabdata['returns'] === []): ?><tr><td colspan="4" class="text-center text-muted py-4">No returns.</td></tr><?php endif; ?>
            <?php foreach ($tabdata['returns'] as $r): ?><tr><td><?= e($r['number']) ?></td><td><?= hx_link(with_back('/orders/' . (int) $r['sales_order_id'], $here), e($r['order_number'])) ?></td><td><?= e(str_replace('_', ' ', $r['status'])) ?></td><td class="text-end"><?= money($r['refund_amount']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div></div>
    <?php elseif ($tab === 'notes'): ?>
        <div class="card" id="customer-view-notes"><div class="card-body"><?php if ($tabdata['notes'] === []): ?><div class="text-muted text-center">No notes yet.</div><?php endif; ?>
            <?php foreach ($tabdata['notes'] as $n): ?><div class="border-bottom py-2 fs-12"><div class="text-muted"><?= e($n['member_name'] ?? '') ?> · <?= e(format_ts($n['created_at'], $tz, 'M j, g:i A')) ?></div><div style="white-space: pre-line"><?= e($n['body']) ?></div></div><?php endforeach; ?>
            <?php if (is_file(dirname(__DIR__) . '/shared/note-form.php')): ?><?= view('shared/note-form.php', ['recordType' => 'customer', 'recordId' => $id]) ?><?php endif; ?></div></div>
    <?php elseif ($tab === 'attachments'): ?>
        <div class="card" id="customer-view-attachments"><div class="card-body"><?php if ($tabdata['attachments'] === []): ?><div class="text-muted text-center">No attachments yet.</div><?php endif; ?>
            <?php foreach ($tabdata['attachments'] as $a): ?><div class="py-1 fs-12"><a href="/files/<?= (int) $a['attachment_id'] ?>"><?= e($a['filename']) ?></a> <span class="text-muted"><?= e(number_format($a['byte_size'] / 1024, 0)) ?> kB · <?= e(format_ts($a['created_at'], $tz, 'M j, Y')) ?></span></div><?php endforeach; ?>
            <?php if (is_file(dirname(__DIR__) . '/shared/attachment-form.php')): ?><?= view('shared/attachment-form.php', ['recordType' => 'customer', 'recordId' => $id]) ?><?php endif; ?></div></div>
    <?php else: ?>
        <div class="card" id="customer-view-trail"><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12"><tbody>
            <?php if ($tabdata['trail'] === []): ?><tr><td class="text-muted py-3 text-center">Nothing yet.</td></tr><?php endif; ?>
            <?php foreach ($tabdata['trail'] as $r): ?><tr id="customer-view-trail-row-<?= (int) $r['activity_id'] ?>"><td class="text-nowrap"><?= e(format_ts($r['occurred_at'], $tz, 'M j, g:i A')) ?></td><td><?= e(activity_sentence($r)) ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div></div>
    <?php endif; ?>
</div>

<?php /** One supplier (screen `supplier-view`). Data: s, tab, tabs, tabdata (items, open, lead, shipped, sources, notes, attachments, trail), may, tz, here, seesCost, notice */
$id = (int) $s['supplier_id'];
$tabUrl = static fn (string $t): string => '/suppliers/' . $id . ($t === 'items' ? '' : '?tab=' . $t);
$actions = '';
if ($may['po'] && $s['active']) { $actions .= hx_link(with_back('/purchasing/new?supplier=' . $id, $here), '<i class="feather-plus me-1"></i>New purchase order', 'btn btn-primary btn-touch', 'id="supplier-view-po-btn"') . ' '; }
if ($may['write']) {
    $actions .= hx_link(with_back('/suppliers/' . $id . '/edit', $here), '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-light btn-touch', 'id="supplier-view-edit-btn"') . ' ';
    $archived = !$s['active'];
    $actions .= '<form method="post" action="/suppliers/archive.php" hx-post="/suppliers/archive.php" hx-target="#flash" class="d-inline" hx-confirm="' . e(($archived ? 'Bring back ' : 'Archive ') . $s['name'] . '?') . '">' . csrf_field()
        . '<input type="hidden" name="supplier" value="' . $id . '"><input type="hidden" name="active" value="' . ($archived ? 'yes' : 'no') . '"><button type="submit" class="btn btn-light btn-touch" id="supplier-view-archive-btn">' . ($archived ? 'Unarchive' : 'Archive') . '</button></form> ';
}
?>
<?= view('shared/header.php', ['id' => 'supplier-view', 'title' => $s['name'], 'crumbs' => [['Home', '/'], ['Suppliers', '/suppliers/'], [$s['name'], null]], 'back' => back_link() ?? ['/suppliers/', 'Suppliers'], 'action' => $actions]) ?>
<div class="main-content" id="supplier-view-content" data-entity="supplier">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="supplier-view-summary"><div class="card-body row g-2">
        <div class="col-12"><div class="chip-row"><?= supplier_kind_chip($s['kind']) ?><?php if ($s['dropships']): ?> <?= dropships_chip() ?><?php endif; ?><?php if (!$s['active']): ?> <span class="badge bg-soft-dark text-dark" id="supplier-view-archived">archived</span><?php endif; ?></div></div>
        <?php if ($s['contact_name']): ?><div class="col-6 col-md-3 fs-12"><span class="text-muted">Contact</span><div><?= e($s['contact_name']) ?></div></div><?php endif; ?>
        <?php if ($s['email']): ?><div class="col-12 col-md-4 fs-12"><span class="text-muted">Email</span><div id="supplier-view-email"><?= e($s['email']) ?></div></div><?php endif; ?>
        <?php if ($s['phone']): ?><div class="col-6 col-md-3 fs-12"><span class="text-muted">Phone</span><div id="supplier-view-phone"><?= e($s['phone']) ?></div></div><?php endif; ?>
        <?php if ($s['website']): ?><div class="col-12 col-md-4 fs-12"><span class="text-muted">Website</span><div><a href="<?= e($s['website']) ?>" target="_blank" rel="noopener noreferrer"><?= e($s['website']) ?></a></div></div><?php endif; ?>
        <?php if ($s['address']): ?><div class="col-12 col-md-4 fs-12"><span class="text-muted">Address</span><div style="white-space: pre-line"><?= e($s['address']) ?></div></div><?php endif; ?>
        <?php if ($s['account_number'] !== null && $s['account_number'] !== ''): ?><div class="col-6 col-md-3 fs-12"><span class="text-muted">Our account with them</span><div id="supplier-view-account"><?= e($s['account_number']) ?></div></div><?php endif; ?>
        <div class="col-6 col-md-3 fs-12"><span class="text-muted">We order by</span><div id="supplier-view-method"><?= e(SUPPLIER_ORDER_METHODS[$s['order_method']] ?? $s['order_method']) ?><?= $s['order_email'] ? ' · ' . e($s['order_email']) : '' ?></div></div>
        <?php if ($s['portal_url']): ?><div class="col-12 col-md-4 fs-12"><span class="text-muted">Their portal</span><div><a href="<?= e($s['portal_url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($s['portal_url']) ?></a></div></div><?php endif; ?>
        <div class="col-12 fs-12 text-muted"><?= $s['terms'] ? 'Terms: ' . e($s['terms']) . ' · ' : '' ?><?= $s['lead_time_days'] !== null ? 'Lead time: ' . (int) $s['lead_time_days'] . ' days · ' : '' ?><?= $s['min_order'] !== null ? 'Minimum order: ' . money($s['min_order']) . ' · ' : '' ?><span id="supplier-view-open-count"><?= (int) $s['open_orders'] ?> open purchase order<?= (int) $s['open_orders'] === 1 ? '' : 's' ?></span></div>
        <?php if ($s['notes']): ?><div class="col-12 fs-12"><span class="text-muted">Notes</span><div style="white-space: pre-line"><?= e($s['notes']) ?></div></div><?php endif; ?>
    </div></div>
    <?php if ($may['po'] && $s['active']): ?>
    <details class="card mb-3" id="supplier-message"><summary class="card-body py-2 fw-semibold d-flex">Email the supplier</summary><div class="card-body pt-0">
        <form method="post" action="/suppliers/message.php" hx-post="/suppliers/message.php" hx-target="#flash" id="supplier-message-form" hx-confirm="Email <?= e($s['name']) ?>?"><?= csrf_field() ?><input type="hidden" name="supplier" value="<?= $id ?>">
            <label class="form-label fs-12 text-muted" for="supplier-message-subject">Subject</label><input type="text" name="subject" id="supplier-message-subject" class="form-control btn-touch" maxlength="200" required>
            <label class="form-label fs-12 text-muted mt-2" for="supplier-message-body">Message</label><textarea name="body" id="supplier-message-body" class="form-control" rows="4" maxlength="5000" required></textarea>
            <div class="fs-12 text-muted mt-1">To the order address<?= $s['order_email'] || $s['email'] ? ' (' . e($s['order_email'] ?: $s['email']) . ')' : ' — this supplier has none' ?>. A purchase order itself is sent from its own page.</div>
            <button type="submit" class="btn btn-light btn-touch mt-2" id="supplier-message-btn">Send</button></form></div></details>
    <?php endif; ?>
    <div class="d-flex flex-wrap gap-1 mb-3" id="supplier-view-tabs">
        <?php foreach ($tabs as $k => $label): ?><?= hx_link($tabUrl($k), e($label), 'btn btn-touch ' . ($tab === $k ? 'btn-primary' : 'btn-light'), 'id="supplier-view-tab-' . $k . '"') ?><?php endforeach; ?>
    </div>
    <?php if ($tab === 'items'): ?>
        <?= view('suppliers/partials/items.php', ['s' => $s, 'items' => $tabdata['items'], 'seesCost' => $seesCost, 'may' => $may, 'here' => $here]) ?>
    <?php elseif ($tab === 'open'): ?>
        <div class="card" id="supplier-open-orders"><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12"><thead class="thead-light"><tr><th>Purchase order</th><th>Kind</th><th>Status</th><th>Expected</th><th class="text-end">Total</th><th></th></tr></thead><tbody>
            <?php if ($tabdata['open'] === []): ?><tr><td colspan="6" class="text-center text-muted py-4" id="supplier-open-orders-empty">No open purchase order.</td></tr><?php endif; ?>
            <?php foreach ($tabdata['open'] as $o): ?><tr id="supplier-open-order-<?= (int) $o['purchase_order_id'] ?>"><td><?= hx_link(with_back('/purchasing/' . (int) $o['purchase_order_id'], $here), e($o['number']), 'fw-semibold') ?></td><td><?= po_kind_chip($o['kind']) ?></td><td><?= po_status_chip($o['status']) ?></td>
                <td class="text-nowrap"><?= $o['expected_on'] ? e(format_date($o['expected_on'])) : '—' ?><?= !empty($o['overdue']) ? ' <span class="badge bg-soft-danger text-danger">overdue</span>' : '' ?></td>
                <td class="text-end"><?= $o['cost_withheld'] ? '—' : money($o['total']) ?></td><td><?= !empty($o['awaiting_ack']) ? '<span class="badge bg-soft-warning text-warning">awaiting acknowledgment</span>' : '' ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div></div>
    <?php elseif ($tab === 'lead'): ?>
        <?= view('suppliers/partials/lead-times.php', ['lead' => $tabdata['lead'], 'shipped' => $tabdata['shipped'], 'mayReports' => $may['reports'], 'here' => $here]) ?>
    <?php elseif ($tab === 'sources'): ?>
        <div class="card" id="supplier-sources"><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12"><thead class="thead-light"><tr><th>Source</th><th>Connector</th><th>Status</th><th>Last pull</th></tr></thead><tbody>
            <?php if ($tabdata['sources'] === []): ?><tr><td colspan="4" class="text-center text-muted py-4" id="supplier-sources-empty">No source reads this supplier.</td></tr><?php endif; ?>
            <?php foreach ($tabdata['sources'] as $r): ?><tr id="supplier-source-<?= (int) $r['source_id'] ?>"><td><?= hx_link(with_back('/sources/' . (int) $r['source_id'], $here), e($r['name']), 'fw-semibold') ?></td><td><?= e($r['connector']) ?></td>
                <td><?= !$r['active'] ? '<span class="badge bg-soft-dark text-dark">off</span>' : ($r['paused_at'] ? '<span class="badge bg-soft-warning text-warning">paused</span>' : '<span class="badge bg-soft-success text-success">active</span>') ?></td>
                <td class="text-nowrap"><?= $r['last_ok_at'] ? e(format_ts($r['last_ok_at'], $tz, 'M j, g:i A')) : '—' ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div></div>
    <?php elseif ($tab === 'notes'): ?>
        <?= view('shared/notes.php', ['recordType' => 'supplier', 'recordId' => $id, 'tz' => $tz]) ?>
    <?php elseif ($tab === 'attachments'): ?>
        <?= view('shared/attachments.php', ['recordType' => 'supplier', 'recordId' => $id, 'tz' => $tz]) ?>
    <?php else: ?>
        <div class="card" id="supplier-view-trail"><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12"><tbody>
            <?php if ($tabdata['trail'] === []): ?><tr><td class="text-muted py-3 text-center">Nothing yet.</td></tr><?php endif; ?>
            <?php foreach ($tabdata['trail'] as $r): ?><tr id="supplier-view-trail-row-<?= (int) $r['activity_id'] ?>"><td class="text-nowrap"><?= e(format_ts($r['occurred_at'], $tz, 'M j, g:i A')) ?></td><td><?= e(activity_sentence($r)) ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div></div>
    <?php endif; ?>
</div>

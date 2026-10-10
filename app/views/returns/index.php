<?php /** The returns (screen `return-list`): a table. Data: rows, total, page, pages, status, filters, dispositions, may, here, notice */
$qs = static fn (array $over = []): string => http_build_query(array_filter(array_merge(['status' => $status, 'customer' => $filters['customer'], 'awaiting_disposition' => $filters['awaiting_disposition'] ? 1 : null], $over), static fn ($v) => $v !== '' && $v !== null));
?>
<?= view('shared/header.php', ['id' => 'return-list', 'title' => 'Returns', 'crumbs' => [['Home', '/'], ['Returns', null]], 'back' => back_link(),
    'action' => $may['request'] ? hx_link('/returns/new', '<i class="feather-plus me-1"></i>Request a return', 'btn btn-primary btn-touch', 'id="return-list-add-btn"') : '']) ?>
<div class="main-content" id="return-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex flex-wrap gap-1 mb-3" id="return-list-chips">
        <?= hx_link('/returns/?' . $qs(['status' => null, 'page' => null]), 'All', 'btn btn-touch ' . ($status === '' ? 'btn-primary' : 'btn-light'), 'id="return-list-chip-all"') ?>
        <?php foreach (RETURN_STATUSES as $k => $w): ?><?= hx_link('/returns/?' . $qs(['status' => $k, 'page' => null]), e($w), 'btn btn-touch ' . ($status === $k ? 'btn-primary' : 'btn-light'), 'id="return-list-chip-' . $k . '"') ?><?php endforeach; ?>
        <?= hx_link('/returns/?' . $qs(['awaiting_disposition' => $filters['awaiting_disposition'] ? null : 1, 'page' => null]), 'Awaiting disposition', 'btn btn-touch ' . ($filters['awaiting_disposition'] ? 'btn-primary' : 'btn-light'), 'id="return-list-chip-awaiting"') ?>
    </div>
    <form method="get" action="/returns/" hx-get="/returns/" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="true" class="row g-2 mb-3" id="return-list-filters">
        <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?><?php if ($filters['awaiting_disposition']): ?><input type="hidden" name="awaiting_disposition" value="1"><?php endif; ?>
        <?php if ($may['request']): ?>
        <div class="col-12 col-md-6"><?= view('shared/customer-select.php', ['id' => 'return-list-filter-customer', 'name' => 'customer', 'value' => $filters['customer'] === '' ? null : $filters['customer']]) ?></div>
        <div class="col-12 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="return-list-filter-btn">Filter</button></div>
        <?php elseif ($filters['customer'] !== ''): ?><input type="hidden" name="customer" value="<?= (int) $filters['customer'] ?>"><?php endif; ?>
    </form>
    <div class="fs-12 text-muted mb-2" id="return-list-count"><?= (int) $total ?> return<?= (int) $total === 1 ? '' : 's' ?></div>
    <div class="card"><div class="card-body p-0"><div class="table-responsive">
        <table class="table mb-0 fs-12" id="return-list-table"><thead class="thead-light"><tr><th>Return</th><th>Status</th><th>Order</th><th>Customer</th><th class="text-end d-none d-md-table-cell">Lines</th><th class="d-none d-md-table-cell">Method</th><th class="d-none d-lg-table-cell">Scheduled</th><th class="d-none d-lg-table-cell">Dispositions</th><th class="text-end">Refund</th></tr></thead>
        <tbody>
            <?php if ($rows === []): ?><tr><td colspan="9" class="text-center text-muted py-4" id="return-list-empty">No returns match.</td></tr><?php endif; ?>
            <?php foreach ($rows as $r): $id = (int) $r['return_id']; ?>
            <tr id="return-row-<?= $id ?>">
                <td class="text-nowrap"><?= hx_link(with_back('/returns/' . $id, $here), e($r['number']), 'fw-semibold') ?></td>
                <td><?= return_status_chip($r['status']) ?></td>
                <td><?= hx_link(with_back('/orders/' . (int) $r['sales_order_id'], $here), e($r['order_number'])) ?></td>
                <td><?= hx_link(with_back('/customers/' . (int) $r['customer_id'], $here), e($r['customer_name'])) ?></td>
                <td class="text-end d-none d-md-table-cell"><?= (int) $r['line_count'] ?></td>
                <td class="d-none d-md-table-cell"><?= e(RETURN_METHODS[$r['method']] ?? $r['method']) ?></td>
                <td class="d-none d-lg-table-cell text-nowrap"><?= $r['scheduled_on'] ? e(format_date($r['scheduled_on'])) : '<span class="text-muted">—</span>' ?></td>
                <td class="d-none d-lg-table-cell"><span class="d-inline-flex flex-wrap gap-1"><?php foreach (array_values(array_unique($dispositions[$id] ?? [])) as $d): ?><?= disposition_chip($d) ?><?php endforeach; ?></span></td>
                <td class="text-end text-nowrap"><?= (float) $r['refund_amount'] > 0 ? money($r['refund_amount']) : '<span class="text-muted">—</span>' ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody></table>
    </div></div></div>
    <?php if ($pages > 1): ?>
    <div class="d-flex gap-2 mt-3" id="return-list-pages">
        <?php if ($page > 1): ?><?= hx_link('/returns/?' . $qs(['page' => $page - 1]), 'Previous', 'btn btn-light btn-touch', 'id="return-list-prev"') ?><?php endif; ?>
        <?php if ($page < $pages): ?><?= hx_link('/returns/?' . $qs(['page' => $page + 1]), 'Next', 'btn btn-light btn-touch', 'id="return-list-next"') ?><?php endif; ?>
    </div>
    <?php endif; ?>
</div>

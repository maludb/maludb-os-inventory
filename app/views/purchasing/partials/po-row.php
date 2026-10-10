<?php /** One row of the purchase-order table (`po-row-{id}`). Data: o (a find_purchase_orders row), seesCost, here */
$id = (int) $o['purchase_order_id'];
?>
<tr id="po-row-<?= $id ?>">
    <td class="text-nowrap"><?= hx_link(with_back('/purchasing/' . $id, $here), e($o['number']), 'fw-semibold') ?></td>
    <td><?= hx_link(with_back('/suppliers/' . (int) $o['supplier_id'], $here), e($o['supplier_name'])) ?></td>
    <td><?= po_kind_chip($o['kind']) ?><?php if ($o['sales_order_id'] !== null): ?><div class="fs-11"><?= hx_link(with_back('/orders/' . (int) $o['sales_order_id'], $here), e((string) $o['sales_order_number']), '', 'id="po-row-' . $id . '-order"') ?><?= $o['customer_name'] ? ' · ' . e($o['customer_name']) : '' ?></div><?php endif; ?></td>
    <td><?= po_status_chip($o['status']) ?><?= po_awaiting_badge($o) ?></td>
    <td class="d-none d-md-table-cell text-nowrap"><?= e(format_date($o['ordered_on'])) ?></td>
    <td class="text-nowrap"><?= $o['expected_on'] ? e(format_date($o['expected_on'])) : '<span class="text-muted">—</span>' ?><?= po_overdue_badge($o) ?></td>
    <td class="d-none d-lg-table-cell text-nowrap"><?= $o['acknowledged_at'] ? e(format_date(substr((string) $o['acknowledged_at'], 0, 10))) : '<span class="text-muted">—</span>' ?></td>
    <td class="d-none d-md-table-cell text-center"><?= $o['has_tracking'] ? '<i class="feather-truck text-primary" title="Tracking recorded" id="po-row-' . $id . '-tracking"></i>' : '<span class="text-muted">—</span>' ?></td>
    <td class="text-end text-nowrap"><?= $o['cost_withheld'] ? '<span class="text-muted">—</span>' : money($o['total']) ?></td>
</tr>

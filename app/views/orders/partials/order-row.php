<?php /** One row of the order table (`order-row-{id}`). Data: o (a find_orders row), here */
$id = (int) $o['sales_order_id'];
?>
<tr id="order-row-<?= $id ?>">
    <td class="text-nowrap"><?= hx_link(with_back('/orders/' . $id, $here), e($o['number']), 'fw-semibold') ?></td>
    <td><?= hx_link(with_back('/customers/' . (int) $o['customer_id'], $here), e($o['customer_name'])) ?></td>
    <td class="d-none d-lg-table-cell"><?= e($o['salesperson_name'] ?? '') ?></td>
    <td class="d-none d-lg-table-cell"><?= e($o['location_name'] ?? '') ?></td>
    <td class="d-none d-md-table-cell text-nowrap"><?= e(format_date($o['ordered_on'])) ?></td>
    <td class="text-nowrap"><?= $o['promised_on'] ? e(format_date($o['promised_on'])) : '<span class="text-muted">—</span>' ?><?= late_badge($o) ?></td>
    <td><?= order_status_chip($o['status']) ?></td>
    <td class="text-end text-nowrap"><?= money($o['total']) ?></td>
    <td class="d-none d-md-table-cell"><?= payment_status_chip($o['payment_status']) ?></td>
    <td class="text-end text-nowrap d-none d-md-table-cell"><?= (float) $o['balance_due'] > 0.004 && $o['status'] !== 'quote' && $o['status'] !== 'cancelled' ? money($o['balance_due']) : '<span class="text-muted">—</span>' ?></td>
</tr>

<?php /** One receipt row (`receipt-row-{id}`). Data: r, tz, here */ $id = (int) $r['goods_receipt_id']; ?>
<tr id="receipt-row-<?= $id ?>">
    <td class="text-nowrap"><?= hx_link(with_back('/receipts/' . $id, $here), e($r['number']), 'fw-semibold', 'id="receipt-row-' . $id . '-number"') ?></td>
    <td><?= doc_status_chip($r['status'], 'receipt-row-' . $id . '-status') ?></td>
    <td><?= $r['supplier_id'] !== null ? hx_link(with_back('/suppliers/' . (int) $r['supplier_id'], $here), e($r['supplier_name'] ?? ''), 'text-dark') : '<span class="text-muted">free receipt</span>' ?></td>
    <td class="d-none d-md-table-cell"><?= $r['purchase_order_id'] !== null ? hx_link(with_back('/purchasing/' . (int) $r['purchase_order_id'], $here), e($r['purchase_order_number'] ?? ''), 'text-dark') : '' ?></td>
    <td class="d-none d-md-table-cell"><?= hx_link(with_back('/locations/' . (int) $r['location_id'], $here), e($r['location_name']), 'text-dark') ?></td>
    <td class="text-nowrap"><?= e(format_date($r['received_on'])) ?></td>
    <td class="text-end"><?= (int) $r['line_count'] ?> · <?= (int) $r['units'] ?></td>
    <td class="d-none d-lg-table-cell"><?= $r['posted_at'] ? e(($r['posted_by_name'] ?? '') . ' · ' . format_ts($r['posted_at'], $tz, 'M j, g:i A')) : '' ?></td>
</tr>

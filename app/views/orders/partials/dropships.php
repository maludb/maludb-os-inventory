<?php /** The drop-ship purchase orders of this order (`order-dropships`); the Buyer's screens are slice 6's. Data: o, seesCost, here */ ?>
<div class="card mb-3" id="order-dropships"><div class="card-header fw-semibold">Drop-ships</div><div class="card-body p-0"><div class="table-responsive">
    <table class="table mb-0 fs-12"><thead class="thead-light"><tr><th>Purchase order</th><th>Supplier</th><th>Status</th><th>Expected</th><th>Tracking</th></tr></thead><tbody>
        <?php if ($o['dropships'] === []): ?><tr><td colspan="5" class="text-center text-muted py-3" id="order-dropships-empty">No drop-ship purchase order.</td></tr><?php endif; ?>
        <?php foreach ($o['dropships'] as $p): ?><tr id="order-dropship-<?= (int) $p['purchase_order_id'] ?>"><td><?= hx_link(with_back('/purchasing/' . (int) $p['purchase_order_id'], $here), e($p['number']), 'fw-semibold') ?></td><td><?= e($p['supplier_name']) ?></td><td><?= po_status_chip($p['status']) ?></td>
            <td class="text-nowrap"><?= $p['expected_on'] ? e(format_date($p['expected_on'])) : '—' ?></td>
            <td><?php $tr = array_filter(array_map(static fn ($l) => $l['tracking_number'] ? trim(($l['tracking_carrier'] ?? '') . ' ' . $l['tracking_number']) : null, $p['lines'])); echo $tr === [] ? '<span class="text-muted">—</span>' : e(implode('; ', $tr)); ?></td></tr><?php endforeach; ?>
    </tbody></table>
</div></div></div>

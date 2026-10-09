<?php /** One levels row (`level-row-{variant}-{location}`). Data: r (a stock_levels row), here, showLocation */
$rid = 'level-row-' . (int) $r['variant_id'] . '-' . (int) $r['location_id'];
?>
<tr id="<?= $rid ?>">
    <td class="text-nowrap"><?= hx_link(with_back('/variants/' . (int) $r['variant_id'], $here), e($r['sku']), 'fw-semibold', 'id="' . $rid . '-sku"') ?><?= $r['below_reorder'] ? ' <span class="badge bg-soft-warning text-warning" id="' . $rid . '-reorder" title="available + on order is at or under the reorder point">below reorder</span>' : '' ?></td>
    <td><?= hx_link(with_back('/products/' . (int) $r['product_id'], $here), e($r['product_name']), 'text-dark') ?></td>
    <td class="d-none d-md-table-cell"><?= e($r['size_name'] ?? '') ?></td>
    <?php if ($showLocation): ?><td><?= hx_link(with_back('/locations/' . (int) $r['location_id'], $here), e($r['location_name']), 'text-dark') ?></td><?php endif; ?>
    <td class="text-end" id="<?= $rid ?>-on-hand"><?= signed_qty_plain((int) $r['qty_on_hand']) ?></td>
    <td class="text-end d-none d-md-table-cell"><?= number_format((int) $r['qty_allocated']) ?></td>
    <td class="text-end d-none d-md-table-cell"><?= number_format((int) $r['qty_floor_model']) ?></td>
    <td class="text-end fw-semibold" id="<?= $rid ?>-available"><?= signed_qty_plain((int) $r['qty_available']) ?></td>
</tr>

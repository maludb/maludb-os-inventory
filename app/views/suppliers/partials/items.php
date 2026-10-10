<?php /** The price sheet (`supplier-items`), read here and edited by slice 3's screen. Data: s, items, seesCost, may, here */
$sid = (int) $s['supplier_id'];
?>
<div class="card" id="supplier-items"><div class="card-body p-0">
    <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom fs-12"><span class="text-muted"><?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?> on the price sheet</span>
        <?= hx_link(with_back('/supplier-items/?supplier=' . $sid, $here), 'Open the price sheet', 'fw-semibold', 'id="supplier-items-link"') ?></div>
    <div class="table-responsive"><table class="table mb-0 fs-12"><thead class="thead-light"><tr><th>SKU</th><th>Product</th><th>Supplier SKU</th><?php if ($seesCost): ?><th class="text-end">Cost</th><?php endif; ?><th class="text-end">Lead</th><th class="text-end d-none d-md-table-cell">MOQ</th><th class="d-none d-md-table-cell">Last seen</th></tr></thead><tbody>
        <?php if ($items === []): ?><tr><td colspan="7" class="text-center text-muted py-4" id="supplier-items-empty">Nothing on the price sheet yet.</td></tr><?php endif; ?>
        <?php foreach ($items as $i): ?><tr id="supplier-item-<?= (int) $i['supplier_item_id'] ?>"<?= $i['active'] ? '' : ' class="text-muted"' ?>>
            <td class="text-nowrap"><?= hx_link(with_back('/variants/' . (int) $i['variant_id'], $here), e($i['sku']), 'fw-semibold') ?></td>
            <td><?= e($i['product_name']) ?><?= $i['size_name'] ? ', ' . e($i['size_name']) : '' ?></td>
            <td class="text-nowrap"><?= e($i['supplier_sku'] ?? '') ?></td>
            <?php if ($seesCost): ?><td class="text-end text-nowrap"><?= money($i['cost']) ?></td><?php endif; ?>
            <td class="text-end"><?= $i['lead_time_days'] !== null ? (int) $i['lead_time_days'] . ' d' : '—' ?></td>
            <td class="text-end d-none d-md-table-cell"><?= (int) $i['moq'] ?></td>
            <td class="text-nowrap d-none d-md-table-cell"><?= $i['last_seen_at'] ? e(format_date(substr((string) $i['last_seen_at'], 0, 10))) : '—' ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
</div></div>

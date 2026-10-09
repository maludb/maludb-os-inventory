<?php /** The product's variants table (`product-variants-table`, `variant-row-{id}`). Data: variants (with availability), product, seesCost, vocab, here */ ?>
<div class="card" id="product-variants"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="product-variants-table">
    <thead class="thead-light"><tr><th>SKU</th><th>Size</th><th class="d-none d-md-table-cell">Options</th><th class="d-none d-lg-table-cell">Barcode</th><th class="d-none d-lg-table-cell">MPN</th><th class="text-end">On hand</th><th class="text-end">Available</th><th>Best offer</th><th class="text-end">Retail</th><th class="text-end">MAP</th><th class="text-end">Cost</th><th></th></tr></thead>
    <tbody>
    <?php if ($variants === []): ?><tr><td colspan="12" class="text-center text-muted py-4" id="product-variants-empty">No variants yet. <strong>Add variant</strong> makes the first size.</td></tr><?php endif; ?>
    <?php foreach ($variants as $v): $vid = $v['variant_id']; $bo = $v['best_offer'] ?? null; ?>
        <tr id="variant-row-<?= $vid ?>" class="<?= $v['active'] ? '' : 'text-muted' ?>">
            <td><?= hx_link(with_back('/variants/' . $vid, $here), '<code>' . e($v['sku']) . '</code>', '', 'id="variant-row-' . $vid . '-sku"') ?></td>
            <td><?= e($v['size_name'] ?? '') ?></td>
            <td class="d-none d-md-table-cell"><?= e(option_words(array_diff_key($v['option_values'], ['Size' => 1, 'size' => 1]))) ?></td>
            <td class="d-none d-lg-table-cell"><?= e($v['barcode'] ?? '') ?></td>
            <td class="d-none d-lg-table-cell"><?= e($v['mpn'] ?? '') ?></td>
            <td class="text-end"><?= $product['kind'] === 'bundle' ? '—' : (int) $v['qty_on_hand'] ?></td>
            <td class="text-end"><?= $product['kind'] === 'bundle' ? (int) ($v['sets_available'] ?? 0) . ' sets' : (int) $v['qty_available'] ?></td>
            <td><?= $bo === null ? '<span class="text-muted">none</span>' : e($bo['supplier'] ?? $bo['source']) . ' ' . availability_chip($bo['availability'] ?? null) . ($bo['lead_time_days'] !== null ? ' ' . (int) $bo['lead_time_days'] . ' d' : '') . ' · ' . ($seesCost ? money($bo['cost'] ?? null) : '<span title="cost withheld">—</span>') . (!empty($bo['stale']) ? ' <span class="badge bg-soft-warning text-warning">stale</span>' : '') ?></td>
            <td class="text-end"><?= money($v['retail_price']) ?></td>
            <td class="text-end"><?= money($v['map_price']) ?></td>
            <td class="text-end"><?= $v['cost_withheld'] ? '<span title="cost withheld">—</span>' : money($v['cost_price']) ?></td>
            <td><?= $v['active'] ? '<span class="badge bg-soft-success text-success">active</span>' : '<span class="badge bg-soft-dark text-dark">inactive</span>' ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div></div></div>

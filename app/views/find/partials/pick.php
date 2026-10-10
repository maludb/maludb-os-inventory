<?php /** The pick list (`pick-results`). Data: rows (inv_find() rows), short (q under 2 characters) */ ?>
<div class="list-group" id="pick-results">
    <?php if ($short): ?><div class="list-group-item text-muted fs-12" id="pick-hint">Type a SKU, GTIN or a name</div>
    <?php elseif ($rows === []): ?><div class="list-group-item text-muted fs-12" id="pick-empty">Nothing found.</div><?php endif; ?>
    <?php foreach ($rows as $r): $label = $r['product_name'] . ($r['size_name'] ? ', ' . $r['size_name'] : ''); [$sw] = FIND_STATES[$r['state']] ?? [$r['state']]; ?>
        <button type="button" class="list-group-item list-group-item-action btn-touch justify-content-start fs-12" id="pick-<?= $r['variant_id'] ?>" data-variant-id="<?= $r['variant_id'] ?>"
                data-sku="<?= e($r['sku']) ?>" data-label="<?= e($label) ?>" data-retail="<?= e((string) $r['retail_price']) ?>" data-state="<?= e($r['state']) ?>"><?= e($r['sku'] . ' — ' . $label) ?> · <?= money($r['retail_price']) ?> · <?= e(strtolower($sw)) ?></button>
    <?php endforeach; ?>
</div>

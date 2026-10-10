<?php
/**
 * What a purchase-order line starts from (the fragment of /purchasing/line-defaults and the offer select of every line). Data: d (po_line_defaults()), n (the row key), field (the input-name prefix: lines[3], l12 — default lines[n]), selected? (the offer already chosen)
 * The data-* attributes are what assets/js/purchasing.js copies into the cost and supplier-SKU boxes of a freshly picked variant.
 */
$field = ($field ?? '') !== '' ? $field : 'lines[' . $n . ']';
$selected = $selected ?? $d['listing_variant_id'];
$cost = !empty($d['cost_visible']) || !array_key_exists('cost_visible', $d);
?>
<div id="po-line-<?= e($n) ?>-defaults" data-default-cost="<?= e($d['unit_cost']) ?>" data-default-sku="<?= e((string) ($d['supplier_sku'] ?? '')) ?>" data-moq="<?= e((string) ($d['moq'] ?? '')) ?>" data-cost-from="<?= e($d['cost_from']) ?>" data-default-offer="<?= e((string) ($d['listing_variant_id'] ?? '')) ?>">
    <label class="form-label fs-12 text-muted" for="po-line-<?= e($n) ?>-offer">The offer it is ordered against</label>
    <select name="<?= e($field) ?>[listing_variant]" id="po-line-<?= e($n) ?>-offer" class="form-select btn-touch">
        <option value="">— none —</option>
        <?php foreach ($d['offers'] as $o): ?><option value="<?= (int) $o['listing_variant_id'] ?>"<?= (int) $selected === (int) $o['listing_variant_id'] ? ' selected' : '' ?>><?= e($o['source'] . ' · ' . ($o['availability'] ?? '?') . ($o['price'] !== null ? ' · ' . number_format((float) $o['price'], 2) : '') . ($o['lead_time_days'] !== null ? ' · ' . (int) $o['lead_time_days'] . ' d' : '') . (!empty($o['stale']) ? ' · stale' : '')) ?></option><?php endforeach; ?>
    </select>
    <div class="fs-11 text-muted mt-1" id="po-line-<?= e($n) ?>-hint"><?= $d['moq'] !== null ? 'Their minimum is ' . (int) $d['moq'] . '. ' : '' ?><?= $d['supplier_sku'] ? 'Their SKU: ' . e($d['supplier_sku']) . '. ' : '' ?><?= $d['offers'] === [] ? 'No offer from this supplier for the variant.' : '' ?></div>
</div>

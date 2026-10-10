<?php /** The ship-to (`po-ship-to`): a location, or the customer for a drop-ship. Data: o (find_purchase_order), may */
$ship = $o['ship_to'];
$city = trim(implode(' ', array_filter([$o['ship_to_city'], $o['ship_to_region'], $o['ship_to_postal'], $o['ship_to_country']])));
?>
<div class="card mb-3" id="po-ship-to"><div class="card-header fw-semibold">Ship to</div><div class="card-body fs-12">
<?php if ($o['ship_to_kind'] === 'location'): ?>
    <div class="fw-semibold"><?= $o['location_id'] ? hx_link('/locations/' . (int) $o['location_id'], e((string) $o['location_name'])) : '—' ?></div>
<?php else: ?>
    <div class="text-muted mb-1">Ship to our customer<?= $o['sales_order_id'] ? ' (' . hx_link('/orders/' . (int) $o['sales_order_id'], e((string) $o['sales_order_number'])) . ')' : '' ?>:</div>
    <?php if ($ship !== null): ?>
        <div style="white-space: pre-line" id="po-ship-to-address"><?= e(implode("\n", array_filter([$ship['ship_to_name'], $ship['ship_to_address1'], $ship['ship_to_address2'], trim(implode(' ', array_filter([$ship['ship_to_city'], $ship['ship_to_region'], $ship['ship_to_postal'], $ship['ship_to_country']])))]))) ?></div>
        <?php if ($ship['ship_to_notes']): ?><div class="text-muted mt-1"><?= e($ship['ship_to_notes']) ?></div><?php endif; ?>
        <?php if ($ship['ship_to_phone']): ?><div class="mt-1" id="po-ship-to-phone"><?= e($ship['ship_to_phone']) ?> <span class="badge bg-soft-<?= $o['shows_phone'] ? 'info text-info' : 'secondary text-dark' ?>" id="po-ship-to-phone-mark"><?= $o['shows_phone'] ? 'shown to the supplier' : 'not shown to the supplier' ?></span></div><?php endif; ?>
    <?php else: ?>
        <div><?= e($city !== '' ? $city : '—') ?></div>
    <?php endif; ?>
<?php endif; ?>
</div></div>

<?php /** The ship-to block (`order-form-ship-to`): the nine ship-to fields. Data: ship (the values: ship_to_name … ship_to_notes) */
$f = static fn (string $k, string $label, string $extra = '', int $max = 200, string $col = 'col-12 col-md-6'): string => '<div class="' . $col . '"><label class="form-label fs-12 text-muted" for="order-form-field-' . $k
    . '">' . e($label) . '</label><input type="text" name="' . $k . '" id="order-form-field-' . $k . '" class="form-control btn-touch" maxlength="' . $max . '" value="' . e($ship[$k] ?? '') . '"' . $extra . '></div>';
?>
<div class="row g-2" id="order-form-ship-to">
    <?= $f('ship_to_name', 'Ship to — name') ?>
    <?= $f('ship_to_phone', 'Phone', ' inputmode="tel"', 40) ?>
    <?= $f('ship_to_address1', 'Address') ?>
    <?= $f('ship_to_address2', 'Address, second line') ?>
    <?= $f('ship_to_city', 'City', '', 100, 'col-6 col-md-3') ?>
    <?= $f('ship_to_region', 'State or region', '', 100, 'col-6 col-md-3') ?>
    <?= $f('ship_to_postal', 'Postal code', '', 20, 'col-6 col-md-3') ?>
    <?= $f('ship_to_country', 'Country (2 letters)', ' style="text-transform: uppercase"', 2, 'col-6 col-md-3') ?>
    <?= $f('ship_to_notes', 'Delivery notes (stairs, a dog, call first)', '', 500, 'col-12') ?>
</div>

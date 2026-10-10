<?php /** One live tax rate: a table row (`tax-rate-row-{id}`) or a card (`tax-rate-card-{id}`). Data: t, as */
$id = (int) $t['tax_rate_id'];
$base = $as === 'row' ? 'tax-rate-row-' . $id : 'tax-rate-card-' . $id;
$rate = rtrim(rtrim($t['rate'], '0'), '.') ?: '0';
$edit = hx_link('/admin/tax-rates/' . $id . '/edit', 'Edit', 'btn btn-light btn-touch', 'id="' . $base . '-edit-btn"');
$archive = $t['is_default'] ? '' : '<form method="post" action="/admin/tax-rates/archive.php" class="d-inline" onsubmit="return confirm(\'Archive this tax rate? Orders and customers that name it keep it.\')">' . csrf_field()
    . '<input type="hidden" name="tax_rate" value="' . $id . '"><button type="submit" class="btn btn-light btn-touch" id="' . $base . '-archive-btn">Archive</button></form>';
$default = $t['is_default'] ? '<span class="badge bg-soft-success text-success">default</span>' : '';
?>
<?php if ($as === 'row'): ?>
<tr id="<?= $base ?>">
    <td class="fw-semibold"><?= e($t['name']) ?></td>
    <td class="text-end"><?= e($rate) ?>%</td>
    <td><?= $default ?></td>
    <td class="text-end"><?= (int) $t['orders_using'] ?> order<?= $t['orders_using'] === 1 ? '' : 's' ?> · <?= (int) $t['customers_using'] ?> customer<?= $t['customers_using'] === 1 ? '' : 's' ?></td>
    <td class="text-end text-nowrap"><?= $edit ?> <?= $archive ?></td>
</tr>
<?php else: ?>
<div class="card mb-2" id="<?= $base ?>"><div class="card-body">
    <div class="d-flex justify-content-between align-items-start gap-2"><div class="fw-semibold"><?= e($t['name']) ?></div><div><?= $default ?></div></div>
    <div class="fs-12 text-muted mt-1"><?= e($rate) ?>% · <?= (int) $t['orders_using'] ?> order<?= $t['orders_using'] === 1 ? '' : 's' ?> · <?= (int) $t['customers_using'] ?> customer<?= $t['customers_using'] === 1 ? '' : 's' ?></div>
    <div class="mt-2 d-flex gap-2"><?= $edit ?> <?= $archive ?></div>
</div></div>
<?php endif; ?>

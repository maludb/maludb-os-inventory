<?php /** One price list: a table row (`price-list-row-{id}`) or a card (`price-list-card-{id}`). Data: p (a find_price_lists row), as */
$id = (int) $p['price_list_id'];
$base = $as === 'row' ? 'price-list-row-' . $id : 'price-list-card-' . $id;
$edit = hx_link('/admin/price-lists/' . $id . '/edit', 'Edit', 'btn btn-light btn-touch', 'id="' . $base . '-edit-btn"');
$active = $p['active'] ? feed_chip('active', 'success') : feed_chip('inactive', 'secondary');
?>
<?php if ($as === 'row'): ?>
<tr id="<?= $base ?>" class="<?= $p['active'] ? '' : 'text-muted' ?>">
    <td class="fw-semibold"><?= e($p['name']) ?></td>
    <td class="text-end"><?= e($p['percent_off_retail']) ?>%</td>
    <td><?= $active ?></td>
    <td class="text-end"><?= $p['keys_using'] > 0 ? hx_link('/admin/feed-keys/', (string) $p['keys_using'], '', 'id="' . $base . '-keys"') : '0' ?></td>
    <td class="fs-11 text-muted"><?= e(mb_strimwidth((string) ($p['notes'] ?? ''), 0, 120, '…')) ?></td>
    <td class="text-end text-nowrap"><?= $edit ?></td>
</tr>
<?php else: ?>
<div class="card mb-2<?= $p['active'] ? '' : ' bg-soft-dark' ?>" id="<?= $base ?>"><div class="card-body">
    <div class="d-flex justify-content-between align-items-start gap-2"><div class="fw-semibold"><?= e($p['name']) ?></div><div><?= $active ?></div></div>
    <div class="fs-12 text-muted mt-1"><?= e($p['percent_off_retail']) ?>% off retail · <?= (int) $p['keys_using'] ?> key<?= $p['keys_using'] === 1 ? '' : 's' ?></div>
    <?php if ((string) ($p['notes'] ?? '') !== ''): ?><div class="fs-11 text-muted mt-1"><?= e(mb_strimwidth((string) $p['notes'], 0, 160, '…')) ?></div><?php endif; ?>
    <div class="mt-2"><?= $edit ?></div>
</div></div>
<?php endif; ?>

<?php /** One count line (`count-line-row-{id}`): on an open count the counted input saves on change (Pattern C). Data: l, c, here */
$lid = (int) $l['count_line_id'];
$open = $c['status'] === 'open';
$diff = $l['counted_qty'] === null ? null : (int) $l['counted_qty'] - (int) $l['system_qty'];
?>
<tr id="count-line-row-<?= $lid ?>" data-sku="<?= e($l['sku']) ?>">
    <td class="text-nowrap"><?= hx_link(with_back('/variants/' . (int) $l['variant_id'], $here), e($l['sku']), 'fw-semibold') ?><div class="d-md-none text-muted"><?= e($l['product_name']) ?></div></td>
    <td class="d-none d-md-table-cell"><?= e($l['product_name']) ?></td>
    <td class="d-none d-md-table-cell"><?= e($l['size_name'] ?? '') ?></td>
    <td class="text-end" id="count-line-row-<?= $lid ?>-system"><?= (int) $l['system_qty'] ?></td>
    <td class="text-end">
        <?php if ($open): ?>
            <form method="post" action="/counts/lines/save.php" hx-post="/counts/lines/save.php" hx-target="closest tr" hx-swap="outerHTML" hx-trigger="change" class="d-inline-flex gap-1 justify-content-end" id="count-line-row-<?= $lid ?>-form">
                <?= csrf_field() ?><input type="hidden" name="count" value="<?= (int) $c['count_id'] ?>"><input type="hidden" name="variant" value="<?= (int) $l['variant_id'] ?>">
                <input type="number" name="counted_qty" min="0" value="<?= $l['counted_qty'] === null ? '' : (int) $l['counted_qty'] ?>" class="form-control btn-touch text-end" style="width: 5rem" inputmode="numeric" aria-label="Counted" id="count-line-row-<?= $lid ?>-counted">
                <noscript><button type="submit" class="btn btn-light btn-touch">Save</button></noscript>
            </form>
        <?php else: ?><span id="count-line-row-<?= $lid ?>-counted"><?= $l['counted_qty'] === null ? '<span class="text-muted">—</span>' : (int) $l['counted_qty'] ?></span><?php endif; ?>
    </td>
    <td class="text-end" id="count-line-row-<?= $lid ?>-difference"><?= $diff === null ? '' : ($diff === 0 ? '<span class="text-success">0</span>' : signed_qty($diff)) ?></td>
    <td class="d-none d-md-table-cell"><?= e($l['counted_by_name'] ?? '') ?><?= !$open && $l['correction_qty'] !== null ? ' · correction ' . signed_qty($l['correction_qty']) : '' ?></td>
</tr>

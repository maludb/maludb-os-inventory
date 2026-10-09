<?php /** One product type row (`product-type-row-{id}`) with its inline form (Pattern C: hx-target="closest tr"). Data: t, mayWrite */ $tid = $t['product_type_id']; ?>
<tr id="product-type-row-<?= $tid ?>" class="<?= $t['active'] ? '' : 'text-muted' ?>">
    <?php if ($mayWrite): ?>
    <td><input form="product-type-row-<?= $tid ?>-form" type="text" name="name" class="form-control btn-touch" maxlength="80" required value="<?= e($t['name']) ?>" aria-label="Name" id="product-type-row-<?= $tid ?>-name"></td>
    <td><code><?= e($t['key']) ?></code></td>
    <td><input form="product-type-row-<?= $tid ?>-form" type="number" min="0" name="sort_order" class="form-control btn-touch" style="width: 5rem" value="<?= $t['sort_order'] ?>" aria-label="Sort order" id="product-type-row-<?= $tid ?>-sort"></td>
    <td><input form="product-type-row-<?= $tid ?>-form" type="hidden" name="active" value="no"><input form="product-type-row-<?= $tid ?>-form" type="checkbox" class="form-check-input" name="active" value="yes" <?= $t['active'] ? 'checked' : '' ?> aria-label="Active" id="product-type-row-<?= $tid ?>-active"></td>
    <td class="text-end"><?= $t['product_count'] ?></td>
    <td class="text-end"><form method="post" action="/product-types/save.php" hx-post="/product-types/save.php" hx-target="closest tr" hx-swap="outerHTML" id="product-type-row-<?= $tid ?>-form" class="d-inline"><?= csrf_field() ?><input type="hidden" name="product_type" value="<?= $tid ?>"><button type="submit" class="btn btn-light btn-touch" id="product-type-row-<?= $tid ?>-save-btn">Save</button></form></td>
    <?php else: ?>
    <td><?= e($t['name']) ?></td><td><code><?= e($t['key']) ?></code></td><td><?= $t['sort_order'] ?></td><td><?= $t['active'] ? 'yes' : 'no' ?></td><td class="text-end"><?= $t['product_count'] ?></td><td></td>
    <?php endif; ?>
</tr>

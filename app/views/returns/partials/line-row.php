<?php /** One line of a return (`return-line-{id}`), with the inline disposition form while the return is open. Data: l, r, may, open, locations, here */
$lid = (int) $l['return_line_id'];
?>
<tr id="return-line-<?= $lid ?>">
    <td><?= (int) $l['line_no'] ?></td>
    <td><span class="fw-semibold"><?= e($l['sku']) ?></span><div class="text-muted"><?= e($l['product_name']) ?><?= $l['size_name'] ? ', ' . e($l['size_name']) : '' ?></div></td>
    <td class="text-end"><?= (int) $l['qty'] ?></td>
    <td class="text-end"><?= in_array($r['status'], ['received', 'closed'], true) ? (int) $l['qty_received'] : '<span class="text-muted">—</span>' ?></td>
    <td><?= e($l['reason_name']) ?></td>
    <td><?= disposition_chip($l['disposition']) ?></td>
    <td class="d-none d-md-table-cell"><?= $l['location_name'] ? e($l['location_name']) : ($r['location_name'] ? '<span class="text-muted">' . e($r['location_name']) . '</span>' : '—') ?></td>
    <td class="d-none d-md-table-cell"><?= e($l['condition_note'] ?? '') ?></td>
    <td class="text-end">
    <?php if ($open && $may['authorize']): ?>
        <details id="return-line-<?= $lid ?>-disposition" class="text-start"><summary class="btn btn-light btn-sm d-inline-block" id="return-line-<?= $lid ?>-disposition-btn" style="cursor: pointer"><i class="feather-sliders me-1"></i>Disposition</summary>
            <form method="post" action="/returns/disposition.php" hx-post="/returns/disposition.php" hx-target="#flash" id="return-line-<?= $lid ?>-form" class="mt-2" style="min-width: 240px"><?= csrf_field() ?><input type="hidden" name="return_line" value="<?= $lid ?>">
                <label class="form-label fs-12 text-muted" for="return-line-<?= $lid ?>-field-disposition">Disposition</label>
                <select name="disposition" id="return-line-<?= $lid ?>-field-disposition" class="form-select btn-touch"><?php foreach (RETURN_DISPOSITIONS as $k => $w): ?><option value="<?= $k ?>"<?= $l['disposition'] === $k ? ' selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select>
                <label class="form-label fs-12 text-muted mt-2" for="return-line-<?= $lid ?>-field-location">Where it lands (a restock or a floor model)</label>
                <select name="location" id="return-line-<?= $lid ?>-field-location" class="form-select btn-touch"><option value="">The return's location</option><?php foreach ($locations as $loc): ?><option value="<?= (int) $loc['location_id'] ?>"<?= (int) ($l['location_id'] ?? 0) === (int) $loc['location_id'] ? ' selected' : '' ?>><?= e($loc['name']) ?></option><?php endforeach; ?></select>
                <button type="submit" class="btn btn-primary btn-touch mt-2 w-100" id="return-line-<?= $lid ?>-disposition-save">Save</button>
            </form></details>
    <?php endif; ?>
    </td>
</tr>

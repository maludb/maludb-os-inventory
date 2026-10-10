<?php
/**
 * One order line, three ways. Data: mode ('view' a table row on the order page | 'saved' a line of a quote with its own form, saved on its own | 'new' a row of the new-quote form, posted with it),
 *   l (a line, view and saved), o (the order, saved and view), n (the row index, new), first (a prefill: variant_id, sku, product_name, size_name, retail_price, qty, choose — new), seesCost, mayCancel
 * The picker of a row is slice 4's compact availability partial (radios stock:/pickup:/dropship:/backorder named by the row's field).
 */
if ($mode === 'view'):
    $lid = (int) $l['line_id'];
    $dead = $l['status'] === 'cancelled';
?>
<tr id="order-line-<?= $lid ?>" class="<?= $dead ? 'text-muted' : '' ?>"<?= $dead ? ' style="text-decoration: line-through"' : '' ?>>
    <td class="text-muted"><?= (int) $l['line_no'] ?></td>
    <td><div class="fw-semibold text-nowrap"><?= hx_link(with_back('/variants/' . (int) $l['variant_id'], $here), e($l['sku'])) ?></div><div class="text-muted"><?= e($l['product_name']) ?><?= $l['size_name'] ? ', ' . e($l['size_name']) : '' ?></div><?php if ($l['notes']): ?><div class="text-muted fst-italic"><?= e($l['notes']) ?></div><?php endif; ?></td>
    <td class="text-end"><?= (int) $l['qty'] ?></td>
    <td class="text-end text-nowrap"><?= money($l['unit_price']) ?></td>
    <td class="text-end text-nowrap d-none d-md-table-cell"><?= (float) $l['discount'] > 0 ? money($l['discount']) : '' ?></td>
    <td class="text-end text-nowrap"><?= money($l['line_total']) ?></td>
    <td><?= fulfilment_chip($l['fulfilment_kind']) ?><div class="text-muted" id="order-line-<?= $lid ?>-where"><?= e(line_where($l)) ?></div></td>
    <td><?= line_status_chip($l['status']) ?></td>
    <td class="text-nowrap fs-11 d-none d-md-table-cell">alloc <?= (int) $l['qty_allocated'] ?> · shipped <?= (int) $l['qty_shipped'] ?></td>
    <td class="text-end"><?php if (!empty($mayCancel) && !$dead && (int) $l['qty_shipped'] === 0 && in_array($o['status'], ['confirmed', 'in_fulfilment'], true)): ?>
        <form method="post" action="/orders/lines/cancel.php" hx-post="/orders/lines/cancel.php" hx-target="#flash" hx-confirm="<?= e('Cancel line ' . $l['line_no'] . ' (' . $l['sku'] . ')?') ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="line" value="<?= $lid ?>"><button type="submit" class="btn btn-light btn-touch" id="order-line-<?= $lid ?>-cancel-btn" aria-label="Cancel line <?= (int) $l['line_no'] ?>"><i class="feather-x"></i></button></form>
    <?php endif; ?></td>
</tr>
<?php elseif ($mode === 'saved'):
    $lid = (int) $l['line_id'];
    $dead = $l['status'] === 'cancelled';
    $fid = 'order-line-' . $lid . '-form';
    $choose = match ($l['fulfilment_kind']) { 'stock', 'pickup' => $l['fulfilment_kind'] . ':' . $l['location_id'], 'dropship' => 'dropship:' . $l['listing_variant_id'], default => 'backorder' };
?>
<div class="card mb-2" id="order-line-<?= $lid ?>">
    <div class="card-body p-2"<?= $dead ? ' style="text-decoration: line-through; opacity: .6"' : '' ?>>
        <div class="d-flex justify-content-between align-items-start gap-2">
            <div class="min-w-0"><div class="fs-12 text-muted">Line <?= (int) $l['line_no'] ?> <?= line_status_chip($l['status']) ?></div>
                <div class="fw-semibold text-truncate" id="order-line-<?= $lid ?>-label"><?= e($l['sku']) ?> — <?= e($l['product_name']) ?><?= $l['size_name'] ? ', ' . e($l['size_name']) : '' ?></div>
                <div class="fs-12 text-muted"><?= fulfilment_chip($l['fulfilment_kind']) ?> <?= e(line_where($l)) ?> · <?= money($l['line_total']) ?></div></div>
            <?php if (!$dead): ?>
            <form method="post" action="/orders/lines/cancel.php" hx-post="/orders/lines/cancel.php" hx-target="#order-lines" hx-swap="outerHTML" hx-confirm="<?= e('Cancel line ' . $l['line_no'] . ' (' . $l['sku'] . ')?') ?>"><?= csrf_field() ?><input type="hidden" name="line" value="<?= $lid ?>"><button type="submit" class="btn btn-light btn-touch" id="order-line-<?= $lid ?>-cancel-btn" aria-label="Cancel line <?= (int) $l['line_no'] ?>"><i class="feather-x"></i></button></form>
            <?php endif; ?>
        </div>
        <?php if (!$dead): ?>
        <form method="post" action="/orders/lines/save.php" hx-post="/orders/lines/save.php" hx-target="#order-lines" hx-swap="outerHTML" id="<?= $fid ?>" class="mt-2">
            <?= csrf_field() ?><input type="hidden" name="line" value="<?= $lid ?>"><input type="hidden" name="rowkey" value="l<?= $lid ?>">
            <div class="row g-2">
                <div class="col-4"><label class="form-label fs-12 text-muted" for="order-line-<?= $lid ?>-qty">Qty</label><input type="number" name="qty" id="order-line-<?= $lid ?>-qty" class="form-control btn-touch" min="1" max="9999" inputmode="numeric" value="<?= (int) $l['qty'] ?>" data-line-qty></div>
                <div class="col-4"><label class="form-label fs-12 text-muted" for="order-line-<?= $lid ?>-price">Unit price</label><input type="text" name="unit_price" id="order-line-<?= $lid ?>-price" class="form-control btn-touch" inputmode="decimal" value="<?= e($l['unit_price']) ?>"></div>
                <div class="col-4"><label class="form-label fs-12 text-muted" for="order-line-<?= $lid ?>-discount">Discount</label><input type="text" name="discount" id="order-line-<?= $lid ?>-discount" class="form-control btn-touch" inputmode="decimal" value="<?= e($l['discount']) ?>"></div>
            </div>
            <div class="mt-2" id="order-line-<?= $lid ?>-fulfilment" data-variant="<?= (int) $l['variant_id'] ?>" data-field="l<?= $lid ?>" data-choose="<?= e($choose) ?>" data-store="<?= (int) ($l['location_id'] ?? 0) ?>">
                <?= compact_picker_html(db(), (int) $l['variant_id'], (int) $l['qty'], 'l' . $lid, $choose, $l['location_id']) ?>
            </div>
            <div class="mt-2"><label class="form-label fs-12 text-muted" for="order-line-<?= $lid ?>-notes">Note</label><input type="text" name="notes" id="order-line-<?= $lid ?>-notes" class="form-control btn-touch" maxlength="200" value="<?= e($l['notes'] ?? '') ?>"></div>
            <div class="mt-2"><button type="submit" class="btn btn-light btn-touch" id="order-line-<?= $lid ?>-save-btn">Save the line</button></div>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php else:
    $n = $n ?? 0;
    $fv = $first ?? null;
    $p = 'lines[' . $n . ']';
    $label = $fv ? $fv['sku'] . ' — ' . $fv['product_name'] . ($fv['size_name'] ? ', ' . $fv['size_name'] : '') : '';
?>
<div class="card mb-2 order-line-row" id="order-line-<?= e($n) ?>" data-line-n="<?= e($n) ?>">
    <div class="card-body p-2">
        <div class="d-flex justify-content-between align-items-start gap-2">
            <div class="min-w-0"><div class="fs-12 text-muted">Line</div><div class="fw-semibold text-truncate" id="order-line-<?= e($n) ?>-label" data-empty="Choose a variant below"><?= $fv ? e($label) : 'Choose a variant below' ?></div></div>
            <button type="button" class="btn btn-light btn-touch" id="order-line-<?= e($n) ?>-remove" data-remove-line aria-label="Remove this line"><i class="feather-x"></i></button>
        </div>
        <input type="hidden" name="<?= $p ?>[variant]" id="order-line-<?= e($n) ?>-variant" value="<?= $fv ? (int) $fv['variant_id'] : '' ?>">
        <div class="mt-2">
            <label class="form-label fs-12 text-muted" for="order-line-<?= e($n) ?>-search">Find a variant</label>
            <input type="search" id="order-line-<?= e($n) ?>-search" name="q" class="form-control btn-touch" placeholder="A SKU, a GTIN or a name" autocomplete="off"
                   hx-get="/find/pick" hx-trigger="keyup changed delay:300ms" hx-target="#order-line-<?= e($n) ?>-pick" hx-swap="innerHTML" hx-sync="this:replace">
            <div id="order-line-<?= e($n) ?>-pick" class="mt-1"></div>
        </div>
        <div class="mt-2"><label class="form-label fs-12 text-muted" for="order-line-<?= e($n) ?>-code">Or type or scan a SKU or barcode</label><input type="text" name="<?= $p ?>[variant_code]" id="order-line-<?= e($n) ?>-code" class="form-control btn-touch" autocomplete="off" maxlength="60"></div>
        <div class="row g-2 mt-1">
            <div class="col-4"><label class="form-label fs-12 text-muted" for="order-line-<?= e($n) ?>-qty">Qty</label><input type="number" name="<?= $p ?>[qty]" id="order-line-<?= e($n) ?>-qty" class="form-control btn-touch" min="1" max="9999" inputmode="numeric" value="<?= $fv ? (int) $fv['qty'] : 1 ?>" data-line-qty></div>
            <div class="col-4"><label class="form-label fs-12 text-muted" for="order-line-<?= e($n) ?>-price">Unit price</label><input type="text" name="<?= $p ?>[unit_price]" id="order-line-<?= e($n) ?>-price" class="form-control btn-touch" inputmode="decimal" placeholder="<?= $fv ? e(number_format((float) $fv['retail_price'], 2, '.', '')) : 'the retail' ?>"></div>
            <div class="col-4"><label class="form-label fs-12 text-muted" for="order-line-<?= e($n) ?>-discount">Discount</label><input type="text" name="<?= $p ?>[discount]" id="order-line-<?= e($n) ?>-discount" class="form-control btn-touch" inputmode="decimal" placeholder="0.00"></div>
        </div>
        <div class="mt-2" id="order-line-<?= e($n) ?>-fulfilment" data-variant="<?= $fv ? (int) $fv['variant_id'] : '' ?>" data-field="<?= e($p) ?>" data-choose="<?= e($fv['choose'] ?? '') ?>">
            <?php if ($fv && $fv['kind'] !== 'bundle'): ?><?= compact_picker_html(db(), (int) $fv['variant_id'], (int) $fv['qty'], $p, $fv['choose'] !== '' ? $fv['choose'] : null, null) ?>
            <?php elseif ($fv): ?><div class="fs-12 text-muted">Sold as its components — they are added as lines when you save.</div>
            <?php else: ?><div class="fs-12 text-muted">Where it is filled from appears here once a variant is chosen.</div><?php endif; ?>
        </div>
        <div class="mt-2"><label class="form-label fs-12 text-muted" for="order-line-<?= e($n) ?>-notes">Note</label><input type="text" name="<?= $p ?>[notes]" id="order-line-<?= e($n) ?>-notes" class="form-control btn-touch" maxlength="200"></div>
    </div>
</div>
<?php endif; ?>

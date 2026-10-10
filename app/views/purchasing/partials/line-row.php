<?php
/**
 * One purchase-order line, three ways. Data: mode ('view' a table row on the order page | 'saved' a line of a draft with its own form, saved on its own | 'new' a row of the new-order form, posted with it),
 *   l (a po_lines row — view and saved), o (the order — view and saved), n (the row index — new), fv (a prefill: variant_id, sku, label, qty, unit_cost, listing_variant_id — new), supplierId (new), seesCost, here
 * The offer select of a row is partials/line-defaults.php (the supplier's offers for the variant).
 */
if ($mode === 'view'):
    $lid = (int) $l['purchase_order_line_id'];
    $dead = in_array($l['status'], ['declined', 'cancelled'], true);
?>
<tr id="po-line-<?= $lid ?>" class="<?= $dead ? 'text-muted' : '' ?>"<?= $dead ? ' style="text-decoration: line-through"' : '' ?>>
    <td class="text-muted"><?= (int) $l['line_no'] ?></td>
    <td><div class="fw-semibold text-nowrap"><?= hx_link(with_back('/variants/' . (int) $l['variant_id'], $here), e($l['sku'])) ?></div><div class="text-muted"><?= e($l['product_name']) ?><?= $l['size_name'] ? ', ' . e($l['size_name']) : '' ?></div></td>
    <td class="d-none d-md-table-cell text-nowrap"><?= e((string) ($l['supplier_sku'] ?? '')) ?></td>
    <td class="text-end"><?= (int) $l['qty_ordered'] ?></td>
    <td class="text-end"><?= (int) $l['qty_received'] ?></td>
    <?php if ($seesCost): ?><td class="text-end text-nowrap"><?= money($l['unit_cost'], $l['cost_withheld']) ?></td><td class="text-end text-nowrap d-none d-md-table-cell"><?= money($l['line_cost'], $l['cost_withheld']) ?></td><?php endif; ?>
    <td class="text-nowrap"><?= $l['expected_on'] ? e(format_date($l['expected_on'])) : '—' ?></td>
    <td><?= po_line_status_chip($l['status']) ?><?= $l['supplier_note'] ? '<div class="text-muted fst-italic">' . e($l['supplier_note']) . '</div>' : '' ?></td>
    <td class="text-nowrap" id="po-line-<?= $lid ?>-tracking-state"><?php if ($l['tracking_number']): ?><i class="feather-truck me-1"></i><?= e(trim(($l['tracking_carrier'] ?? '') . ' ' . $l['tracking_number'])) ?><div class="text-muted"><?= $l['shipped_at'] ? e(format_date(substr((string) $l['shipped_at'], 0, 10))) : '' ?></div><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
    <td class="d-none d-lg-table-cell"><?php if ($l['offer_source']): ?><?= e($l['offer_source']) ?><div class="text-muted"><?= e(implode(' · ', array_filter([$l['offer_price'] !== null ? money($l['offer_price']) : null, $l['offer_availability'], $l['offer_as_of'] ? 'as of ' . format_date(substr((string) $l['offer_as_of'], 0, 10)) : null]))) ?></div><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
    <td class="d-none d-lg-table-cell"><?php if ($l['sales_order_line_id'] !== null && $l['sales_order_id'] !== null): ?><?= hx_link(with_back('/orders/' . (int) $l['sales_order_id'], $here), e((string) $l['sales_order_number'])) ?> · line <?= (int) $l['sales_line_no'] ?><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
</tr>
<?php elseif ($mode === 'saved'):
    $lid = (int) $l['purchase_order_line_id'];
    $fixed = $o['kind'] === 'dropship';
    $f = 'l' . $lid;
    $d = po_line_defaults(db(), (int) $o['supplier_id'], (int) $l['variant_id']);
?>
<div class="card mb-2" id="po-line-<?= $lid ?>">
    <div class="card-body p-2">
        <div class="d-flex justify-content-between align-items-start gap-2">
            <div class="min-w-0"><div class="fs-12 text-muted">Line <?= (int) $l['line_no'] ?> <?= po_line_status_chip($l['status']) ?></div>
                <div class="fw-semibold text-truncate" id="po-line-<?= $lid ?>-label"><?= e($l['sku']) ?> — <?= e($l['product_name']) ?><?= $l['size_name'] ? ', ' . e($l['size_name']) : '' ?></div>
                <div class="fs-12 text-muted"><?= $l['cost_withheld'] ? '' : money($l['line_cost']) ?></div></div>
            <?php if (!$fixed): ?>
            <form method="post" action="/purchasing/lines/remove.php" hx-post="/purchasing/lines/remove.php" hx-target="#po-lines" hx-swap="outerHTML" hx-confirm="<?= e('Remove line ' . $l['line_no'] . ' (' . $l['sku'] . ')?') ?>"><?= csrf_field() ?><input type="hidden" name="line" value="<?= $lid ?>"><button type="submit" class="btn btn-light btn-touch" id="po-line-<?= $lid ?>-remove-btn" aria-label="Remove line <?= (int) $l['line_no'] ?>"><i class="feather-x"></i></button></form>
            <?php endif; ?>
        </div>
        <form method="post" action="/purchasing/lines/save.php" hx-post="/purchasing/lines/save.php" hx-target="#po-lines" hx-swap="outerHTML" id="po-line-<?= $lid ?>-form" class="mt-2">
            <?= csrf_field() ?><input type="hidden" name="line" value="<?= $lid ?>"><input type="hidden" name="rowkey" value="<?= $f ?>">
            <div class="row g-2">
                <div class="col-4"><label class="form-label fs-12 text-muted" for="po-line-<?= $lid ?>-qty">Qty</label><input type="number" name="qty" id="po-line-<?= $lid ?>-qty" class="form-control btn-touch" min="1" max="99999" inputmode="numeric" value="<?= (int) $l['qty_ordered'] ?>"<?= $fixed ? ' readonly' : '' ?>></div>
                <?php if ($seesCost): ?><div class="col-4"><label class="form-label fs-12 text-muted" for="po-line-<?= $lid ?>-cost">Unit cost</label><input type="text" name="unit_cost" id="po-line-<?= $lid ?>-cost" class="form-control btn-touch" inputmode="decimal" value="<?= e((string) $l['unit_cost']) ?>"></div><?php endif; ?>
                <div class="col-<?= $seesCost ? 4 : 8 ?>"><label class="form-label fs-12 text-muted" for="po-line-<?= $lid ?>-expected">Expected</label><input type="date" name="expected_on" id="po-line-<?= $lid ?>-expected" class="form-control btn-touch" value="<?= e((string) ($l['expected_on'] ?? '')) ?>"></div>
                <div class="col-12"><label class="form-label fs-12 text-muted" for="po-line-<?= $lid ?>-sku">Their SKU</label><input type="text" name="supplier_sku" id="po-line-<?= $lid ?>-sku" class="form-control btn-touch" maxlength="60" value="<?= e((string) ($l['supplier_sku'] ?? '')) ?>"></div>
            </div>
            <?php if (!$fixed): ?><div class="mt-2"><?= view('purchasing/partials/line-defaults.php', ['d' => $d, 'n' => $lid, 'field' => $f, 'selected' => $l['listing_variant_id']]) ?></div><?php endif; ?>
            <div class="mt-2"><button type="submit" class="btn btn-light btn-touch" id="po-line-<?= $lid ?>-save-btn">Save the line</button></div>
        </form>
    </div>
</div>
<?php else:
    $n = $n ?? 0;
    $fv = $fv ?? null;
    $p = 'lines[' . $n . ']';
    $sup = (int) ($supplierId ?? 0);
    $d = $fv && $sup > 0 ? po_line_defaults(db(), $sup, (int) $fv['variant_id']) : null;
?>
<div class="card mb-2 po-line-row" id="po-line-<?= e($n) ?>" data-line-n="<?= e($n) ?>">
    <div class="card-body p-2">
        <div class="d-flex justify-content-between align-items-start gap-2">
            <div class="min-w-0"><div class="fs-12 text-muted">Line</div><div class="fw-semibold text-truncate" id="po-line-<?= e($n) ?>-label" data-empty="Choose a variant below"><?= $fv ? e($fv['sku'] . ' — ' . $fv['label']) : 'Choose a variant below' ?></div></div>
            <button type="button" class="btn btn-light btn-touch" id="po-line-<?= e($n) ?>-remove" data-remove-line aria-label="Remove this line"><i class="feather-x"></i></button>
        </div>
        <input type="hidden" name="<?= $p ?>[variant]" id="po-line-<?= e($n) ?>-variant" value="<?= $fv ? (int) $fv['variant_id'] : '' ?>">
        <div class="mt-2">
            <label class="form-label fs-12 text-muted" for="po-line-<?= e($n) ?>-search">Find a variant</label>
            <input type="search" id="po-line-<?= e($n) ?>-search" name="q" class="form-control btn-touch" placeholder="A SKU, a GTIN or a name" autocomplete="off"
                   hx-get="/find/pick" hx-trigger="keyup changed delay:300ms" hx-target="#po-line-<?= e($n) ?>-pick" hx-swap="innerHTML" hx-sync="this:replace">
            <div id="po-line-<?= e($n) ?>-pick" class="mt-1"></div>
        </div>
        <div class="mt-2"><label class="form-label fs-12 text-muted" for="po-line-<?= e($n) ?>-code">Or type or scan a SKU or barcode</label><input type="text" name="<?= $p ?>[variant_code]" id="po-line-<?= e($n) ?>-code" class="form-control btn-touch" autocomplete="off" maxlength="60"></div>
        <div class="row g-2 mt-1">
            <div class="col-4"><label class="form-label fs-12 text-muted" for="po-line-<?= e($n) ?>-qty">Qty</label><input type="number" name="<?= $p ?>[qty]" id="po-line-<?= e($n) ?>-qty" class="form-control btn-touch" min="1" max="99999" inputmode="numeric" value="<?= $fv ? (int) $fv['qty'] : 1 ?>"></div>
            <?php if ($seesCost): ?><div class="col-4"><label class="form-label fs-12 text-muted" for="po-line-<?= e($n) ?>-cost">Unit cost</label><input type="text" name="<?= $p ?>[unit_cost]" id="po-line-<?= e($n) ?>-cost" class="form-control btn-touch" inputmode="decimal" placeholder="their price" value="<?= $fv && $fv['unit_cost'] !== null ? e($fv['unit_cost']) : '' ?>"></div><?php endif; ?>
            <div class="col-<?= $seesCost ? 4 : 8 ?>"><label class="form-label fs-12 text-muted" for="po-line-<?= e($n) ?>-expected">Expected</label><input type="date" name="<?= $p ?>[expected_on]" id="po-line-<?= e($n) ?>-expected" class="form-control btn-touch"></div>
            <div class="col-12"><label class="form-label fs-12 text-muted" for="po-line-<?= e($n) ?>-sku">Their SKU</label><input type="text" name="<?= $p ?>[supplier_sku]" id="po-line-<?= e($n) ?>-sku" class="form-control btn-touch" maxlength="60" placeholder="from their price sheet"></div>
        </div>
        <div class="mt-2" id="po-line-<?= e($n) ?>-offerbox" data-variant="<?= $fv ? (int) $fv['variant_id'] : '' ?>" data-field="<?= e($p) ?>">
            <?php if ($d !== null): ?><?= view('purchasing/partials/line-defaults.php', ['d' => $d, 'n' => $n, 'field' => $p, 'selected' => $fv['listing_variant_id'] ?? null]) ?>
            <?php else: ?><div class="fs-12 text-muted">The cost, their SKU and the offers load once a variant is chosen.</div><?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

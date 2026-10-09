<?php /** The scan field (`{prefix}-form-field-scan`): its own form, so it works with no JavaScript; stock-scan.js keeps the focus and posts on Enter.
 * Data: prefix (receipt / adjustment / transfer / count), action, hidden (name => value), label, qty (bool — a typed quantity beside it; count: counted_qty) */
$fid = $prefix . '-form-field-scan';
?>
<form method="post" action="<?= e($action) ?>" hx-post="<?= e($action) ?>" hx-target="#flash" class="card mb-3 scan-card" id="<?= e($prefix) ?>-scan-form" data-scan-form>
    <?= csrf_field() ?><?php foreach ($hidden as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string) $v) ?>"><?php endforeach; ?>
    <div class="card-body d-flex gap-2 align-items-end">
        <div class="flex-grow-1"><label class="form-label fs-12 text-muted mb-1" for="<?= e($fid) ?>"><i class="feather-maximize me-1"></i><?= e($label) ?></label>
            <input type="text" name="barcode" id="<?= e($fid) ?>" class="form-control btn-touch scan-input" autocomplete="off" autocapitalize="off" spellcheck="false" inputmode="text" enterkeyhint="send" autofocus data-scan-input required placeholder="Scan or type a barcode or SKU"></div>
        <?php if (!empty($qty)): $qn = ['count' => 'counted_qty', 'adjustment' => 'qty_delta'][$prefix] ?? 'qty'; ?><div style="width: 6.5rem"><label class="form-label fs-12 text-muted mb-1" for="<?= e($prefix) ?>-form-field-scan-qty"><?= e($qty) ?></label><input type="number" name="<?= $qn ?>" id="<?= e($prefix) ?>-form-field-scan-qty" class="form-control btn-touch" <?= $prefix === 'count' ? 'min="0" placeholder="+1"' : ($prefix === 'adjustment' ? 'placeholder="-1" data-scan-required' : 'min="1" placeholder="1"') ?> <?= $prefix === 'adjustment' ? '' : 'inputmode="numeric"' ?> data-scan-qty></div><?php endif; ?>
        <button type="submit" class="btn btn-primary btn-touch" id="<?= e($prefix) ?>-scan-btn" aria-label="Add">Add</button>
    </div>
</form>

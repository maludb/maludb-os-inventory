<?php
/**
 * Receive a return (screen `return-receive`), built at 375 px: the scan field at the top, a card a line with a 44 px stepper, the Receive button pinned in the header bar. Posts to /returns/receive.php; with JavaScript off it is a plain form.
 * Data: r, lines, locations, here, notice
 */
$rid = (int) $r['return_id'];
$ready = $r['status'] === 'approved';
?>
<?= view('shared/header.php', ['id' => 'return-receive', 'title' => 'Receive ' . $r['number'], 'crumbs' => [['Home', '/'], ['Returns', '/returns/'], [$r['number'], '/returns/' . $rid], ['Receive', null]], 'back' => back_link() ?? ['/returns/' . $rid, $r['number']]]) ?>
<div class="main-content" id="return-receive-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if (!$ready): ?><div class="alert alert-warning" id="return-receive-refusal"><?= e($r['number']) ?> is <?= e($r['status']) ?> — an approved return is received.</div><?php endif; ?>
    <form method="post" action="/returns/receive.php" hx-post="/returns/receive.php" hx-target="#flash" hx-confirm="This writes the stock movements." id="return-receive-form" data-return-receive><?= csrf_field() ?><input type="hidden" name="return" value="<?= $rid ?>">
        <div class="page-header-form d-flex align-items-center justify-content-between gap-2 px-3 py-2 border rounded mb-3" id="return-receive-header">
            <div class="fw-semibold"><?= e($r['number']) ?> <span class="text-muted fs-12 fw-normal">· <?= e($r['customer_name']) ?></span></div>
            <button type="submit" class="btn btn-primary btn-touch" id="return-receive-save-btn"<?= $ready ? '' : ' disabled' ?>><i class="feather-download me-1"></i>Receive</button>
        </div>
        <div class="mb-3">
            <label class="form-label fs-12 text-muted" for="return-receive-scan">Scan a barcode or type a SKU to find its line</label>
            <input type="text" id="return-receive-scan" class="form-control btn-touch" autocomplete="off" autocapitalize="off" inputmode="text" data-return-scan placeholder="Scan…">
            <div class="fs-12 text-danger mt-1" id="return-receive-scan-miss" role="status" hidden>That is not on this return.</div>
        </div>
        <?php foreach ($lines as $l): $lid = (int) $l['return_line_id']; $loc = $l['location_id'] ?? $r['location_id']; $needs = in_array($l['disposition'], ['restock', 'floor_model'], true) && $loc === null; ?>
        <div class="card mb-2" id="return-receive-line-<?= $lid ?>" data-return-line data-sku="<?= e(strtolower($l['sku'])) ?>" data-barcode="<?= e(strtolower((string) ($l['barcode'] ?? ''))) ?>">
            <div class="card-body">
                <div class="d-flex justify-content-between gap-2"><div class="min-w-0"><div class="fw-semibold"><?= e($l['sku']) ?></div><div class="fs-12 text-muted"><?= e($l['product_name']) ?><?= $l['size_name'] ? ', ' . e($l['size_name']) : '' ?></div></div>
                    <div class="text-end fs-12"><?= disposition_chip($l['disposition']) ?><div class="text-muted mt-1">asked for <?= (int) $l['qty'] ?></div></div></div>
                <div class="d-flex align-items-center gap-2 mt-2">
                    <span class="fs-12 text-muted me-auto" id="return-receive-line-<?= $lid ?>-where"><?= $loc !== null ? 'Lands at ' . e((string) ($l['location_name'] ?? $r['location_name'] ?? '')) : ($needs ? 'Choose where it lands' : 'No stock moves') ?></span>
                    <button type="button" class="btn btn-light btn-touch px-3" data-step="-1" aria-label="One less" id="return-receive-line-<?= $lid ?>-minus">−</button>
                    <input type="number" name="lines[<?= $lid ?>][qty_received]" id="return-receive-line-<?= $lid ?>-qty_received" class="form-control btn-touch text-center" style="max-width: 5rem" min="0" max="<?= (int) $l['qty'] ?>" step="1" inputmode="numeric" value="<?= (int) $l['qty'] ?>" aria-label="Received of <?= e($l['sku']) ?>">
                    <button type="button" class="btn btn-light btn-touch px-3" data-step="1" aria-label="One more" id="return-receive-line-<?= $lid ?>-plus">+</button>
                </div>
                <?php if ($needs): ?>
                <div class="mt-2"><label class="form-label fs-12 text-muted" for="return-receive-line-<?= $lid ?>-location">Where <?= e($l['sku']) ?> comes back to</label>
                    <select name="lines[<?= $lid ?>][location]" id="return-receive-line-<?= $lid ?>-location" class="form-select btn-touch" required><option value="">Choose…</option><?php foreach ($locations as $lc): ?><option value="<?= (int) $lc['location_id'] ?>"><?= e($lc['name']) ?></option><?php endforeach; ?></select></div>
                <?php endif; ?>
                <div class="mt-2"><label class="form-label fs-12 text-muted" for="return-receive-line-<?= $lid ?>-condition_note">Condition</label>
                    <input type="text" name="lines[<?= $lid ?>][condition_note]" id="return-receive-line-<?= $lid ?>-condition_note" class="form-control btn-touch" maxlength="500" value="<?= e($l['condition_note'] ?? '') ?>" placeholder="Wrapped, stained, as new…"></div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if ($lines === []): ?><div class="card"><div class="card-body text-center text-muted">This return has no lines.</div></div><?php endif; ?>
        <noscript><div class="fs-12 text-muted mt-2">Receiving writes the stock movements as soon as you press Receive.</div></noscript>
    </form>
</div>

<?php /** Send the purchase order (screen `purchase-order-send`). Data: o, preview (subject, text, html), refusal, to, supplier, showPhone, here, notice */
$poid = (int) $o['purchase_order_id'];
$method = $supplier['order_method'] ?? 'email';
?>
<?= view('shared/header.php', ['id' => 'purchase-order-send', 'title' => 'Send ' . $o['number'], 'crumbs' => [['Home', '/'], ['Purchase orders', '/purchasing/'], [$o['number'], '/purchasing/' . $poid], ['Send', null]], 'back' => back_link() ?? ['/purchasing/' . $poid, $o['number']]]) ?>
<div class="main-content" id="purchase-order-send-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($refusal !== null): ?><div class="alert alert-warning" id="po-send-refusal"><?= e($refusal) ?></div><?php endif; ?>
    <div class="card mb-3" id="po-send-preview"><div class="card-header fw-semibold">The email the supplier will get</div><div class="card-body fs-12">
        <div class="text-muted">To: <?= $to !== '' ? e($to) : 'the supplier — no email address on file' ?> · <?= e(SUPPLIER_ORDER_METHODS[$method] ?? $method) ?> is how we order</div>
        <div class="fw-semibold mb-2" id="po-send-subject"><?= e($preview['subject']) ?></div>
        <pre class="mb-0" style="white-space: pre-wrap; font-family: inherit" id="po-send-text"><?= e(str_replace(str_repeat('0', 48), '…', $preview['text'])) ?></pre>
        <div class="text-muted mt-2">The link in the email is made when you send; the supplier's earlier link, if any, stops. <?= $o['kind'] === 'dropship' ? ($showPhone ? "The customer's phone is included for this shipping kind." : "The customer's phone is not included for this shipping kind.") : '' ?></div>
    </div></div>
    <form method="post" action="/purchasing/send.php" hx-post="/purchasing/send.php" hx-target="#flash" id="po-send-form" class="card mb-3" hx-confirm="Email <?= e($o['number']) ?> to the supplier?"><?= csrf_field() ?><input type="hidden" name="purchase_order" value="<?= $poid ?>"><input type="hidden" name="via" value="email">
        <div class="card-body"><label class="form-label fs-12 text-muted" for="po-send-message">A message of your own, above the order</label><textarea name="message" id="po-send-message" class="form-control" rows="3" maxlength="2000"></textarea></div>
        <div class="card-footer d-flex gap-2"><button type="submit" class="btn btn-primary btn-touch" id="po-send-submit"<?= $refusal !== null ? ' disabled' : '' ?>>Send by email</button><?= hx_link('/purchasing/' . $poid, 'Cancel', 'btn btn-light btn-touch', 'id="po-send-cancel-link"') ?></div>
    </form>
    <?php if ($refusal === null): ?>
    <?php if ($method === 'phone'): ?>
    <form method="post" action="/purchasing/send.php" hx-post="/purchasing/send.php" hx-target="#flash" id="po-send-phone-form" class="card mb-3" hx-confirm="Record <?= e($o['number']) ?> as sent by phone? Nothing is emailed."><?= csrf_field() ?><input type="hidden" name="purchase_order" value="<?= $poid ?>"><input type="hidden" name="via" value="phone">
        <div class="card-body"><div class="fw-semibold">Ordered by phone</div><div class="fs-12 text-muted">Records the order as sent. Nothing is emailed and no link is made — you record what they say by hand.</div></div>
        <div class="card-footer"><button type="submit" class="btn btn-light btn-touch" id="po-send-phone">Sent by phone</button></div>
    </form>
    <?php endif; ?>
    <form method="post" action="/purchasing/place.php" hx-post="/purchasing/place.php" hx-target="#flash" id="po-place-form" class="card" hx-confirm="Record <?= e($o['number']) ?> as placed with the supplier?"><?= csrf_field() ?><input type="hidden" name="purchase_order" value="<?= $poid ?>">
        <div class="card-body"><div class="fw-semibold mb-1">Placed on their portal</div>
            <div class="fs-12 text-muted mb-2">Place it on the supplier's own site, then record their reference here. No email is sent.<?php $pu = one_value(db(), 'SELECT portal_url FROM suppliers WHERE id = :id', ['id' => $o['supplier_id']]); if ($pu): ?> <a href="<?= e($pu) ?>" target="_blank" rel="noopener noreferrer" id="po-place-portal-link">Open their portal</a><?php endif; ?></div>
            <div class="row g-2">
                <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="po-place-ref">Their order reference</label><input type="text" name="supplier_ref" id="po-place-ref" class="form-control btn-touch" maxlength="100" required></div>
                <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="po-place-via">How</label><select name="via" id="po-place-via" class="form-select btn-touch"><option value="portal">On their portal</option><option value="api">By API</option><option value="edi">By EDI</option></select></div>
            </div></div>
        <div class="card-footer"><button type="submit" class="btn btn-light btn-touch" id="po-place-submit">Mark placed</button></div>
    </form>
    <?php endif; ?>
</div>

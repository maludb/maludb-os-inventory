<?php /** Send the confirmation (screen `order-send`). Data: o, preview (subject, text, html), refusal, email, here, notice */
$oid = (int) $o['sales_order_id'];
?>
<?= view('shared/header.php', ['id' => 'order-send', 'title' => 'Send ' . $o['number'], 'crumbs' => [['Home', '/'], ['Orders', '/orders/'], [$o['number'], '/orders/' . $oid], ['Send', null]], 'back' => back_link() ?? ['/orders/' . $oid, $o['number']]]) ?>
<div class="main-content" id="order-send-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($refusal !== null): ?><div class="alert alert-warning" id="order-send-refusal"><?= e($refusal) ?></div><?php endif; ?>
    <div class="card mb-3" id="order-send-preview"><div class="card-header fw-semibold">The email the customer will get</div><div class="card-body fs-12">
        <div class="text-muted">To: the customer<?= $email !== '' ? ' (' . e($email) . ')' : '' ?></div>
        <div class="fw-semibold mb-2" id="order-send-subject"><?= e($preview['subject']) ?></div>
        <pre class="mb-0" style="white-space: pre-wrap; font-family: inherit" id="order-send-text"><?= e(str_replace(str_repeat('0', 48), '…', $preview['text'])) ?></pre>
        <div class="text-muted mt-2">The link in the email is made when you send; the order's earlier link, if any, stops.</div>
    </div></div>
    <form method="post" action="/orders/send.php" hx-post="/orders/send.php" hx-target="#flash" id="order-send-form" class="card" hx-confirm="Email <?= e($o['number']) ?> to the customer?"><?= csrf_field() ?><input type="hidden" name="order" value="<?= $oid ?>">
        <div class="card-body"><label class="form-label fs-12 text-muted" for="order-send-message">A message of your own, above the order</label><textarea name="message" id="order-send-message" class="form-control" rows="3" maxlength="2000"></textarea></div>
        <div class="card-footer d-flex gap-2"><button type="submit" class="btn btn-primary btn-touch" id="order-send-submit"<?= $refusal !== null ? ' disabled' : '' ?>>Send</button><?= hx_link('/orders/' . $oid, 'Cancel', 'btn btn-light btn-touch', 'id="order-send-cancel-link"') ?></div>
    </form>
</div>

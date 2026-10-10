<?php /** Record a payment or a refund (screen `order-payment`). Data: o, kind, here */
$oid = (int) $o['sales_order_id'];
$refund = $kind === 'refund';
$action = $refund ? '/orders/payments/refund.php' : '/orders/payments/save.php';
$suggest = $refund ? $o['amount_paid'] : ($kind === 'balance' && (float) $o['balance_due'] > 0 ? $o['balance_due'] : '');
?>
<?= view('shared/header.php', ['id' => 'order-payment', 'title' => 'Payment for ' . $o['number'], 'crumbs' => [['Home', '/'], ['Orders', '/orders/'], [$o['number'], '/orders/' . $oid], ['Payment', null]], 'back' => back_link() ?? ['/orders/' . $oid, $o['number']]]) ?>
<div class="main-content" id="order-payment-content">
    <div class="d-flex flex-wrap gap-1 mb-3" id="order-payment-kinds"><?php foreach (['deposit' => 'A deposit', 'balance' => 'The balance', 'refund' => 'A refund'] as $k => $w): ?><?= hx_link('/orders/' . $oid . '/payment?kind=' . $k, e($w), 'btn btn-touch ' . ($kind === $k ? 'btn-primary' : 'btn-light'), 'id="order-payment-kind-' . $k . '"') ?><?php endforeach; ?></div>
    <div class="card mb-3"><div class="card-body fs-12">Total <strong><?= money($o['total']) ?></strong> · paid <strong><?= money($o['amount_paid']) ?></strong> · balance due <strong id="order-payment-balance"><?= money($o['balance_due']) ?></strong>. This is a record of money taken — nothing is charged here.</div></div>
    <form method="post" action="<?= $action ?>" hx-post="<?= $action ?>" hx-target="#flash" id="order-payment-form" class="card"><?= csrf_field() ?><input type="hidden" name="order" value="<?= $oid ?>"><?php if (!$refund): ?><input type="hidden" name="kind" value="<?= e($kind) ?>"><?php endif; ?>
        <div class="card-body row g-2">
            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="order-payment-field-amount"><?= $refund ? 'Amount given back' : 'Amount' ?></label><input type="text" name="amount" id="order-payment-field-amount" class="form-control btn-touch" inputmode="decimal" required value="<?= e($suggest === '' ? '' : number_format((float) $suggest, 2, '.', '')) ?>"></div>
            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="order-payment-field-method">Method</label><select name="method" id="order-payment-field-method" class="form-select btn-touch"><?php foreach (PAYMENT_METHODS as $k => $w): ?><option value="<?= $k ?>"><?= e($w) ?></option><?php endforeach; ?></select></div>
            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="order-payment-field-reference">Reference (the last four, a check number)</label><input type="text" name="reference" id="order-payment-field-reference" class="form-control btn-touch" maxlength="100" autocomplete="off"></div>
            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="order-payment-field-taken_at"><?= $refund ? 'When' : 'When it was taken' ?> (now when empty)</label><input type="datetime-local" name="taken_at" id="order-payment-field-taken_at" class="form-control btn-touch"></div>
            <div class="col-12"><label class="form-label fs-12 text-muted" for="order-payment-field-note">Note</label><textarea name="note" id="order-payment-field-note" class="form-control" rows="2" maxlength="500"></textarea></div>
        </div>
        <div class="card-footer d-flex gap-2"><button type="submit" class="btn btn-primary btn-touch" id="order-payment-submit"><?= $refund ? 'Record the refund' : 'Record the ' . ($kind === 'balance' ? 'balance' : 'deposit') ?></button><?= hx_link('/orders/' . $oid, 'Cancel', 'btn btn-light btn-touch', 'id="order-payment-cancel-link"') ?></div>
    </form>
</div>

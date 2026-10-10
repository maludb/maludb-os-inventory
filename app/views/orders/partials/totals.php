<?php /** The totals panel (`order-totals`'s content — the server's figures, never computed in the browser). Data: o (subtotal, discount_total, tax_total, shipping_charge, total, amount_paid, balance_due, status) */ ?>
<div class="card"><div class="card-body fs-12">
    <div class="d-flex justify-content-between"><span class="text-muted">Subtotal</span><span id="order-totals-subtotal"><?= money($o['subtotal']) ?></span></div>
    <?php if ((float) $o['discount_total'] > 0): ?><div class="d-flex justify-content-between"><span class="text-muted">Discounts included</span><span id="order-totals-discount"><?= money($o['discount_total']) ?></span></div><?php endif; ?>
    <div class="d-flex justify-content-between"><span class="text-muted">Tax</span><span id="order-totals-tax"><?= money($o['tax_total']) ?></span></div>
    <div class="d-flex justify-content-between"><span class="text-muted">Shipping</span><span id="order-totals-shipping"><?= money($o['shipping_charge']) ?></span></div>
    <div class="d-flex justify-content-between fw-semibold fs-14 border-top mt-2 pt-2"><span>Total</span><span id="order-totals-total"><?= money($o['total']) ?></span></div>
    <?php if (($o['status'] ?? 'quote') !== 'quote'): ?><div class="d-flex justify-content-between mt-1"><span class="text-muted">Paid</span><span id="order-totals-paid"><?= money($o['amount_paid']) ?></span></div>
    <div class="d-flex justify-content-between fw-semibold"><span>Balance due</span><span id="order-totals-balance"><?= money($o['balance_due']) ?></span></div><?php endif; ?>
</div></div>

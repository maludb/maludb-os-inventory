<?php /** The totals panel (`po-totals`'s content — the server's figures, never computed in the browser). Data: o (subtotal, shipping_cost, total, cost_withheld), seesCost */ ?>
<div class="card"><div class="card-body fs-12">
<?php if (!empty($o['cost_withheld'])): ?><div class="text-muted" id="po-totals-withheld">Cost is not shown to you.</div><?php else: ?>
    <div class="d-flex justify-content-between"><span class="text-muted">Subtotal</span><span id="po-totals-subtotal"><?= money($o['subtotal']) ?></span></div>
    <div class="d-flex justify-content-between"><span class="text-muted">Shipping</span><span id="po-totals-shipping"><?= money($o['shipping_cost']) ?></span></div>
    <div class="d-flex justify-content-between fw-semibold fs-14 border-top mt-2 pt-2"><span>Total</span><span id="po-totals-total"><?= money($o['total']) ?></span></div>
<?php endif; ?>
</div></div>

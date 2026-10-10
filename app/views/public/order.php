<?php
/** The customer's order page (screen `customer-door`). Plain HTML, no script, whole at 375 px. Data: o (public_order()). NEVER a cost, a source, a supplier, a note or the salesperson. */
$s = $o['settings'];
$cur = (string) ($s['currency'] ?? 'USD');
$m = static fn ($v): string => ($cur === 'USD' ? '$' : $cur . ' ') . number_format((float) $v, 2);
$pickup = $o['delivery_method'] === 'pickup';
$subject = 'Order ' . $o['number'];
ob_start();
?>
<div class="mb-3">
    <div class="fw-semibold fs-5" id="public-order-business"><?= e($s['business_name'] ?? '') ?></div>
    <div class="text-muted small"><?php if (!empty($s['business_contact_email'])): ?><a href="mailto:<?= e($s['business_contact_email']) ?>"><?= e($s['business_contact_email']) ?></a><?php endif; ?><?php if (!empty($s['business_phone'])): ?> · <?= e($s['business_phone']) ?><?php endif; ?></div>
</div>
<div class="card mb-3"><div class="card-body">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div><div class="fs-4 fw-semibold" id="public-order-number"><?= e($o['number']) ?></div><div class="text-muted small">Ordered <?= e(format_date($o['ordered_on'])) ?></div></div>
        <div class="text-end"><span class="badge bg-primary fs-6" id="public-order-status"><?= e(customer_status_word($o['status'])) ?></span></div>
    </div>
</div></div>
<div class="card mb-3" id="public-order-lines"><div class="card-header fw-semibold">Your items</div><div class="card-body p-0"><div class="table-responsive">
    <table class="table mb-0 small"><thead><tr><th>Item</th><th class="text-end">Qty</th><th class="text-end">Price</th><th class="text-end">Total</th></tr></thead><tbody>
    <?php foreach ($o['lines'] as $l): ?>
        <tr><td><?= e($l['product_name']) ?><?= $l['size_name'] ? ', ' . e($l['size_name']) : '' ?><div class="text-muted"><?= e(customer_fulfilment_word($l)) ?></div></td><td class="text-end"><?= (int) $l['qty'] ?></td><td class="text-end"><?= e($m($l['unit_price'])) ?></td><td class="text-end"><?= e($m($l['line_total'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
</div></div></div>
<div class="card mb-3" id="public-order-totals"><div class="card-body small">
    <div class="d-flex justify-content-between"><span>Subtotal</span><span><?= e($m($o['subtotal'])) ?></span></div>
    <div class="d-flex justify-content-between"><span>Tax</span><span><?= e($m($o['tax_total'])) ?></span></div>
    <div class="d-flex justify-content-between"><span>Shipping</span><span><?= e($m($o['shipping_charge'])) ?></span></div>
    <div class="d-flex justify-content-between fw-semibold border-top mt-2 pt-2"><span>Total</span><span id="public-order-total"><?= e($m($o['total'])) ?></span></div>
    <div class="d-flex justify-content-between mt-2"><span>Payment</span><span id="public-order-payment"><?= e(PAYMENT_STATUSES[$o['payment_status']] ?? $o['payment_status']) ?></span></div>
    <?php if (!in_array($o['status'], ['quote', 'cancelled'], true)): ?><div class="d-flex justify-content-between fw-semibold"><span>Balance due</span><span id="public-order-balance"><?= e($m($o['balance_due'])) ?></span></div><?php endif; ?>
</div></div>
<div class="card mb-3" id="public-order-delivery"><div class="card-header fw-semibold"><?= $pickup ? 'Pickup' : 'Delivery' ?></div><div class="card-body small">
    <div><?= e(DELIVERY_METHODS[$o['delivery_method']] ?? $o['delivery_method']) ?><?= $o['promised_on'] ? ' — promised for ' . e(format_date($o['promised_on'])) : '' ?></div>
    <?php if ($pickup): ?>
        <?php if ($o['store']): ?><div class="mt-1"><strong><?= e($o['store']['name']) ?></strong><?php if ($o['store']['address']): ?><div style="white-space: pre-line"><?= e($o['store']['address']) ?></div><?php endif; ?></div><?php endif; ?>
    <?php elseif ($o['ship_to_name'] || $o['ship_to_address1']): ?>
        <div class="mt-1" id="public-order-ship-to" style="white-space: pre-line"><?= e(implode("\n", array_filter([$o['ship_to_name'], $o['ship_to_address1'], $o['ship_to_address2'], trim(implode(' ', array_filter([$o['ship_to_city'], $o['ship_to_region'], $o['ship_to_postal'], $o['ship_to_country']])))]))) ?></div>
        <?php if ($o['ship_to_notes']): ?><div class="text-muted mt-1"><?= e($o['ship_to_notes']) ?></div><?php endif; ?>
    <?php endif; ?>
</div></div>
<?php if ($o['shipments'] !== []): ?>
<div class="card mb-3" id="public-order-shipments"><div class="card-header fw-semibold">Shipments</div><div class="card-body small">
    <?php foreach ($o['shipments'] as $sh): ?>
    <div class="mb-2"><strong><?= e(['own_delivery' => 'Our delivery', 'parcel' => 'Parcel', 'ltl' => 'Freight', 'dropship' => 'Shipped to you', 'pickup' => 'Pickup'][$sh['kind']] ?? $sh['kind']) ?></strong>
        <?php if ($sh['carrier']): ?> · <?= e($sh['carrier']) ?><?php endif; ?>
        <?php if ($sh['tracking_number']): ?> · <?= $sh['tracking_url'] ? '<a href="' . e($sh['tracking_url']) . '" target="_blank" rel="noopener noreferrer">' . e($sh['tracking_number']) . '</a>' : e($sh['tracking_number']) ?><?php endif; ?>
        <div class="text-muted">Shipped <?= e(format_date(substr((string) $sh['shipped_at'], 0, 10))) ?><?= $sh['delivered_at'] ? ' · delivered ' . e(format_date(substr((string) $sh['delivered_at'], 0, 10))) : '' ?></div></div>
    <?php endforeach; ?>
</div></div>
<?php endif; ?>
<?php if ($o['returns'] !== []): ?>
<div class="card mb-3" id="public-order-returns"><div class="card-header fw-semibold">Returns</div><div class="card-body small"><?php foreach ($o['returns'] as $r): ?><div><?= e($r['number']) ?> · <?= e(str_replace('_', ' ', $r['status'])) ?></div><?php endforeach; ?></div></div>
<?php endif; ?>
<?php if (!empty($s['business_contact_email'])): ?>
<p class="text-center"><a class="btn btn-primary" id="public-order-message" href="mailto:<?= e($s['business_contact_email']) ?>?subject=<?= rawurlencode($subject) ?>">Message us</a></p>
<?php endif; ?>
<?php
echo view('public/layout.php', ['title' => 'Order ' . $o['number'], 'content' => ob_get_clean(), 'settings' => $s]);

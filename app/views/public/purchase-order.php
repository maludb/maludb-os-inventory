<?php
/**
 * The supplier's purchase-order page (screen `supplier-door`). Plain HTML, no script, whole at 375 px. Data: po (public_purchase_order()), flash ([kind, sentence] or null).
 * NEVER the internal notes, another order or a source; the customer's phone only when inv_po_shows_phone() says so.
 */
$s = $po['settings'];
$cur = (string) ($s['currency'] ?? 'USD');
$m = static fn ($v): string => ($cur === 'USD' ? '$' : $cur . ' ') . number_format((float) $v, 2);
$token = (string) ($_GET['token'] ?? '');
$open = door_open($po);
$live = $po['lines'];
$ackable = array_values(array_filter($po['lines'], static fn (array $l): bool => $l['status'] === 'open'));
$declinable = array_values(array_filter($po['lines'], static fn (array $l): bool => in_array($l['status'], ['open', 'acknowledged'], true)));
$trackable = array_values(array_filter($po['lines'], static fn (array $l): bool => !in_array($l['status'], ['declined', 'cancelled', 'received', 'closed_short'], true)));
$lineLabel = static fn (array $l): string => 'Line ' . $l['line_no'] . ' — ' . ($l['supplier_sku'] ?: $l['sku']) . ' × ' . (int) $l['qty_ordered'];
$ship = $po['ship_to_kind'] === 'customer';
$city = trim(implode(' ', array_filter([$po['ship_to_city'], $po['ship_to_region'], $po['ship_to_postal'], $po['ship_to_country']])));
ob_start();
?>
<div class="mb-3">
    <div class="fw-semibold fs-5" id="public-po-business"><?= e($s['business_name'] ?? '') ?></div>
    <div class="text-muted small"><?php if (!empty($s['business_contact_email'])): ?><a href="mailto:<?= e($s['business_contact_email']) ?>"><?= e($s['business_contact_email']) ?></a><?php endif; ?><?php if (!empty($s['business_phone'])): ?> · <?= e($s['business_phone']) ?><?php endif; ?></div>
    <?php if (!empty($po['supplier']['account_number'])): ?><div class="small mt-1" id="public-po-account">Your account for us: <strong><?= e($po['supplier']['account_number']) ?></strong></div><?php endif; ?>
</div>
<?php if ($flash !== null): ?><div class="alert alert-<?= e($flash[0]) ?>" role="alert" id="public-po-flash"><?= e($flash[1]) ?></div><?php endif; ?>
<div class="card mb-3" id="public-po"><div class="card-body">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div><div class="fs-4 fw-semibold" id="public-po-number"><?= e($po['number']) ?></div><div class="text-muted small">Dated <?= e(format_date($po['ordered_on'])) ?><?= $po['expected_on'] ? ' · expected ' . e(format_date($po['expected_on'])) : '' ?></div></div>
        <div class="text-end"><span class="badge bg-primary fs-6" id="public-po-status"><?= e(supplier_status_word($po['status'])) ?></span></div>
    </div>
</div></div>
<div class="card mb-3" id="public-po-lines"><div class="card-header fw-semibold">The lines</div><div class="card-body p-0"><div class="table-responsive">
    <table class="table mb-0 small"><thead><tr><th>Your SKU</th><th>Item</th><th class="text-end">Qty</th><th class="text-end">Cost</th><th class="text-end">Total</th></tr></thead><tbody>
    <?php foreach ($live as $l): $struck = in_array($l['status'], ['declined', 'cancelled'], true); ?>
        <tr id="public-po-line-<?= (int) $l['line_id'] ?>"<?= $struck ? ' class="text-muted" style="text-decoration: line-through"' : '' ?>>
            <td><?= e((string) ($l['supplier_sku'] ?? '')) ?><div class="text-muted">our <?= e($l['sku']) ?> · line <?= (int) $l['line_no'] ?></div></td>
            <td><?= e($l['product_name']) ?><?= $l['size_name'] ? ', ' . e($l['size_name']) : '' ?>
                <div class="text-muted"><?= e(supplier_line_word($l['status'])) ?><?= $l['expected_on'] ? ' · expected ' . e(format_date($l['expected_on'])) : '' ?><?= $l['tracking_number'] ? ' · ' . e(trim(($l['tracking_carrier'] ?? '') . ' ' . $l['tracking_number'])) : '' ?></div></td>
            <td class="text-end"><?= (int) $l['qty_ordered'] ?></td><td class="text-end"><?= e($m($l['unit_cost'])) ?></td><td class="text-end"><?= e($m($l['line_cost'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
</div></div></div>
<div class="card mb-3" id="public-po-totals"><div class="card-body small">
    <div class="d-flex justify-content-between"><span>Shipping</span><span><?= e($m($po['shipping_cost'])) ?></span></div>
    <div class="d-flex justify-content-between fw-semibold border-top mt-2 pt-2"><span>Total</span><span id="public-po-total"><?= e($m($po['total'])) ?></span></div>
</div></div>
<div class="card mb-3" id="public-po-ship-to"><div class="card-header fw-semibold"><?= $ship ? 'Ship to our customer:' : 'Ship to:' ?></div><div class="card-body small" style="white-space: pre-line"><?php
    if ($ship) {
        echo e(implode("\n", array_filter([$po['ship_to_name'], $po['ship_to_address1'], $po['ship_to_address2'], $city])));
        if ($po['shows_phone'] && $po['ship_to_phone']) { echo "\n" . e('Phone: ' . $po['ship_to_phone']); }
        if ($po['ship_to_notes']) { echo "\n" . e('Delivery notes: ' . $po['ship_to_notes']); }
    } else {
        echo e(implode("\n", array_filter([$po['location_name'], $po['location_address']])));
    }
?></div></div>
<?php if ($po['notes']): ?><div class="card mb-3" id="public-po-notes"><div class="card-header fw-semibold">Notes</div><div class="card-body small" style="white-space: pre-line"><?= e($po['notes']) ?></div></div><?php endif; ?>
<?php if (!$open): ?>
<div class="alert alert-secondary" id="public-po-closed">This order is <?= e(strtolower(supplier_status_word($po['status']))) ?>; nothing more to do here.</div>
<?php else: $act = '/s/' . rawurlencode($token); ?>
<div class="card mb-3" id="public-po-ack"><div class="card-header fw-semibold">Acknowledge</div><div class="card-body">
    <form method="post" action="<?= e($act) ?>/acknowledge" id="public-po-ack-form"><?= csrf_field() ?><div style="position: absolute; left: -9999px" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
        <label class="form-label small" for="public-po-ack-ref">Your order reference</label><input type="text" name="supplier_ref" id="public-po-ack-ref" class="form-control mb-2" maxlength="100" style="min-height: 44px">
        <label class="form-label small" for="public-po-ack-expected">Expected to ship or arrive</label><input type="date" name="expected_on" id="public-po-ack-expected" class="form-control mb-2" style="min-height: 44px">
        <label class="form-label small" for="public-po-ack-line">What you are acknowledging</label>
        <select name="line" id="public-po-ack-line" class="form-select mb-2" style="min-height: 44px"><option value="">The whole order</option><?php foreach ($ackable as $l): ?><option value="<?= (int) $l['line_id'] ?>"><?= e($lineLabel($l)) ?></option><?php endforeach; ?></select>
        <button type="submit" class="btn btn-primary w-100" id="public-po-ack-submit" style="min-height: 44px">Acknowledge</button></form>
</div></div>
<div class="card mb-3" id="public-po-decline"><div class="card-header fw-semibold">Cannot fill a line</div><div class="card-body">
    <form method="post" action="<?= e($act) ?>/decline" id="public-po-decline-form"><?= csrf_field() ?><div style="position: absolute; left: -9999px" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
        <label class="form-label small" for="public-po-decline-line">Which line</label>
        <select name="line" id="public-po-decline-line" class="form-select mb-2" required style="min-height: 44px"><option value="">Choose the line</option><?php foreach ($declinable as $l): ?><option value="<?= (int) $l['line_id'] ?>"><?= e($lineLabel($l)) ?></option><?php endforeach; ?></select>
        <label class="form-label small" for="public-po-decline-reason">Why</label><input type="text" name="reason" id="public-po-decline-reason" class="form-control mb-2" maxlength="500" required style="min-height: 44px">
        <button type="submit" class="btn btn-light w-100" id="public-po-decline-submit" style="min-height: 44px">Decline the line</button></form>
</div></div>
<div class="card mb-3" id="public-po-tracking"><div class="card-header fw-semibold">Add tracking</div><div class="card-body">
    <form method="post" action="<?= e($act) ?>/tracking" id="public-po-tracking-form"><?= csrf_field() ?><div style="position: absolute; left: -9999px" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
        <label class="form-label small" for="public-po-tracking-line">Which line</label>
        <select name="line" id="public-po-tracking-line" class="form-select mb-2" required style="min-height: 44px"><option value="">Choose the line</option><?php foreach ($trackable as $l): ?><option value="<?= (int) $l['line_id'] ?>"><?= e($lineLabel($l)) ?></option><?php endforeach; ?></select>
        <label class="form-label small" for="public-po-tracking-carrier">Carrier</label><input type="text" name="carrier" id="public-po-tracking-carrier" class="form-control mb-2" maxlength="60" style="min-height: 44px">
        <label class="form-label small" for="public-po-tracking-number">Tracking number</label><input type="text" name="tracking" id="public-po-tracking-number" class="form-control mb-2" maxlength="100" required style="min-height: 44px">
        <label class="form-label small" for="public-po-tracking-shipped">Shipped on</label><input type="datetime-local" name="shipped_at" id="public-po-tracking-shipped" class="form-control mb-2" style="min-height: 44px">
        <button type="submit" class="btn btn-light w-100" id="public-po-tracking-submit" style="min-height: 44px">Add the tracking</button></form>
</div></div>
<?php endif; ?>
<?php if (!empty($s['business_contact_email'])): ?>
<p class="text-center"><a class="btn btn-light" id="public-po-message" href="mailto:<?= e($s['business_contact_email']) ?>?subject=<?= rawurlencode('Purchase order ' . $po['number']) ?>">Message us</a></p>
<?php endif; ?>
<?php
echo view('public/layout.php', ['title' => 'Purchase order ' . $po['number'], 'content' => ob_get_clean(), 'settings' => $s]);

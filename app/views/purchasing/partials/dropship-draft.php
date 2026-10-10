<?php /** The drop-ship draft (`po-form-dropship-{supplier_id}` per supplier): the sales order's open drop-ship lines grouped by supplier and one Draft button — the database drafts them. Data: so (the sales order), dropships (order_open_dropship_lines()), seesCost */ ?>
<div class="card" id="po-form-dropships"><div class="card-body">
    <div class="fw-semibold mb-1"><?= e($so['number']) ?> · <?= e((string) $so['customer_name']) ?> <?= order_status_chip($so['status']) ?></div>
    <?php if ($dropships === []): ?><div class="text-muted fs-12" id="po-form-dropships-none"><?= e($so['number']) ?> has no open drop-ship line — every one already has a purchase order.</div>
    <?php else: ?>
        <?php foreach ($dropships as $g): ?>
        <div class="border rounded p-2 mb-2" id="po-form-dropship-<?= (int) $g['supplier_id'] ?>">
            <div class="fw-semibold fs-12"><?= e($g['supplier_name']) ?> <span class="text-muted fw-normal">— one purchase order</span></div>
            <?php foreach ($g['lines'] as $l): ?><div class="fs-12" id="po-form-dropship-line-<?= (int) $l['line_id'] ?>">Line <?= (int) $l['line_no'] ?> · <?= e($l['sku']) ?> × <?= (int) $l['qty'] ?><?= $seesCost && !$l['cost_withheld'] && $l['offer_cost'] !== null ? ' · cost ' . money($l['offer_cost']) : '' ?><?= $l['offer_lead_time_days'] !== null ? ' · ' . (int) $l['offer_lead_time_days'] . ' days' : '' ?></div><?php endforeach; ?>
        </div>
        <?php endforeach; ?>
        <form method="post" action="/purchasing/save.php" hx-post="/purchasing/save.php" hx-target="#flash" id="po-form-draft-form"><?= csrf_field() ?><input type="hidden" name="kind" value="dropship"><input type="hidden" name="order" value="<?= (int) $so['sales_order_id'] ?>">
            <button type="submit" class="btn btn-primary btn-touch" id="po-form-draft">Draft the purchase order<?= count($dropships) === 1 ? '' : 's' ?></button></form>
    <?php endif; ?>
</div></div>

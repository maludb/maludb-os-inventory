<?php /** The supplier's link (`po-link`) for purchasing.write. Data: o, tz, may */
$k = $o['link'];
?>
<div class="card mb-3" id="po-link"><div class="card-header fw-semibold">The supplier's link</div><div class="card-body fs-12">
    <?php if ($k === null): ?><span id="po-link-state">None yet — it is made when you send the purchase order by email.</span>
    <?php elseif ($k['is_live']): ?><span class="badge bg-soft-success text-success" id="po-link-state">live</span> <?= $k['expires_at'] ? 'expires ' . e(format_date(substr((string) $k['expires_at'], 0, 10))) : 'no end date yet (it ends 90 days after the order closes)' ?>
    <?php else: ?><span class="badge bg-soft-secondary text-dark" id="po-link-state">stopped</span> <?= $o['status'] === 'draft' ? 'A new one is made when you send.' : 'Rotate it to send the supplier a new one.' ?><?php endif; ?>
    <?php if ($k !== null): ?><div class="text-muted mt-1" id="po-link-views">Opened <?= (int) $k['view_count'] ?> time<?= (int) $k['view_count'] === 1 ? '' : 's' ?><?= $k['last_used_at'] ? ' · last ' . e(format_ts($k['last_used_at'], $tz, 'M j, g:i A')) : '' ?></div><?php endif; ?>
</div></div>

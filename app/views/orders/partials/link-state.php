<?php /** The customer's link (`order-link`) for orders.send. Data: o, tz */
$k = $o['link'];
?>
<div class="card mb-3" id="order-link"><div class="card-header fw-semibold">The customer's link</div><div class="card-body fs-12">
    <?php if ($k === null): ?><span id="order-link-state">None yet — it is made when you send the confirmation.</span>
    <?php elseif ($k['is_live']): ?><span class="badge bg-soft-success text-success" id="order-link-state">live</span> <?= $k['expires_at'] ? 'expires ' . e(format_date(substr((string) $k['expires_at'], 0, 10))) : 'no end date yet (it ends 180 days after the order closes)' ?>
    <?php else: ?><span class="badge bg-soft-secondary text-dark" id="order-link-state">stopped</span> A new one goes out with the next send.<?php endif; ?>
    <?php if ($k !== null): ?><div class="text-muted mt-1" id="order-link-views">Opened <?= (int) $k['view_count'] ?> time<?= (int) $k['view_count'] === 1 ? '' : 's' ?><?= $k['last_used_at'] ? ' · last ' . e(format_ts($k['last_used_at'], $tz, 'M j, g:i A')) : '' ?></div><?php endif; ?>
</div></div>

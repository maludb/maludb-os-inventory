<?php /** Unread (`#home-bell`): the five newest unread notifications. Data: s, tz */ ?>
<div class="card" id="home-bell"><div class="card-header"><h5 class="card-title mb-0"><i class="feather-bell me-2"></i>Unread</h5></div>
    <div class="list-group list-group-flush" id="home-bell-list">
    <?php if ($s['bell'] === []): ?>
        <div class="list-group-item text-muted fs-12" id="home-bell-empty">Nothing unread. A watch firing, a line at risk, a pull failing, a supplier's acknowledgment and the morning note land here.</div>
    <?php endif; ?>
    <?php foreach ($s['bell'] as $n): $u = record_url($n['record_type'], $n['record_id']); ?>
        <div class="list-group-item" id="home-bell-<?= (int) $n['notification_id'] ?>">
            <div class="fw-semibold"><?= $u !== null ? hx_link(with_back($u, '/'), e($n['title']), 'text-dark') : e($n['title']) ?></div>
            <div class="fs-12 text-muted"><span class="badge bg-soft-<?= e(notification_kind_colour((string) $n['kind'])) ?> text-<?= notification_kind_colour((string) $n['kind']) === 'light' ? 'dark' : e(notification_kind_colour((string) $n['kind'])) ?> me-1"><?= e(str_replace('_', ' ', (string) $n['kind'])) ?></span><?= e(format_ts($n['created_at'], $tz, 'M j, g:i A')) ?></div>
        </div>
    <?php endforeach; ?>
    <?php if ($s['bell'] !== []): ?><div class="list-group-item"><?= hx_link('/notifications', 'Every notification', 'fs-12 fw-semibold') ?></div><?php endif; ?>
    </div>
</div>

<?php /** Notifications (screen `notifications`): unread first, newest first; kind chip, title, body, when; Mark read per row, Mark all read. Data: rows, more, page, tz, unreadOnly */ ?>
<?= view('shared/header.php', ['id' => 'notifications', 'title' => 'Notifications', 'crumbs' => [['Home', '/'], ['Notifications', null]],
    'action' => $rows !== [] ? '<form method="post" action="/settings/notifications/read.php" hx-post="/settings/notifications/read.php" hx-target="#page-content" hx-swap="innerHTML" id="notifications-mark-all-form">' . csrf_field()
        . '<button type="submit" class="btn btn-light btn-touch" id="notifications-mark-all">Mark all read</button></form>' : '']) ?>
<div class="main-content" id="notifications-content">
    <div class="d-flex flex-wrap gap-1 mb-3" id="notifications-filters">
        <?= hx_link('/notifications', 'Everything', 'btn btn-touch ' . ($unreadOnly ? 'btn-light' : 'btn-primary'), 'id="notifications-filter-all"') ?>
        <?= hx_link('/notifications?unread=1', 'Unread', 'btn btn-touch ' . ($unreadOnly ? 'btn-primary' : 'btn-light'), 'id="notifications-filter-unread"') ?>
    </div>
    <?php if ($rows === []): ?>
        <div class="card" id="notifications-card"><div class="card-body">
            <div class="empty-state" id="notifications-empty">
                <span class="avatar-text avatar-lg rounded"><i class="feather-bell"></i></span>
                <div><div class="fw-semibold">Nothing yet</div>
                    <div class="fs-12 text-muted">A watch firing, a line at risk, a pull failing, a supplier's acknowledgment or tracking, a return and the morning note are listed here. <?= hx_link('/settings/', 'Choose what you are told', 'fw-semibold') ?>.</div></div>
            </div>
        </div></div>
    <?php else: ?>
        <?php foreach ($rows as $n): $id = (int) $n['notification_id']; $unread = $n['read_at'] === null; $k = notification_kind((string) $n['kind']); $colour = $k['color']; $u = notification_record_url($n); ?>
            <div class="card mb-2<?= $unread ? ' border-primary' : '' ?>" id="notification-row-<?= $id ?>">
                <div class="card-body d-flex align-items-start gap-3 py-3">
                    <span class="avatar-text avatar-md rounded <?= $unread ? 'bg-soft-' . e($colour) . ' text-' . e($colour) : '' ?>" id="notification-row-<?= $id ?>-icon"><i class="<?= e($k['icon']) ?>"></i></span>
                    <div class="min-w-0 flex-grow-1">
                        <div class="fw-semibold"><?= $u !== null ? hx_link(with_back($u, '/notifications'), e($n['title']), 'text-dark') : e($n['title']) ?></div>
                        <?php if (($n['body'] ?? '') !== ''): ?><div class="fs-12 text-muted"><?= e($n['body']) ?></div><?php endif; ?>
                        <div class="fs-11 text-muted"><span class="badge bg-soft-<?= e($colour) ?> text-<?= $colour === 'light' ? 'dark' : e($colour) ?> me-1"><?= e($k['label']) ?></span><?= e(format_ts($n['created_at'], $tz, 'M j, g:i A')) ?><?= $unread ? '' : ' · read' ?></div>
                    </div>
                    <?php if ($unread): ?>
                        <form method="post" action="/settings/notifications/read.php" hx-post="/settings/notifications/read.php" hx-target="#page-content" hx-swap="innerHTML"><?= csrf_field() ?><input type="hidden" name="notification" value="<?= $id ?>">
                            <button type="submit" class="btn btn-light btn-touch" id="notification-row-<?= $id ?>-read" aria-label="Mark read"><i class="feather-check"></i></button></form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if ($page > 1 || $more): $q = $unreadOnly ? '&unread=1' : ''; ?>
        <nav class="py-2" id="notifications-pagination"><ul class="pagination mb-0">
            <?php if ($page > 1): ?><li class="page-item"><?= hx_link('/notifications?page=' . ($page - 1) . $q, 'Newer', 'page-link') ?></li><?php endif; ?>
            <?php if ($more): ?><li class="page-item"><?= hx_link('/notifications?page=' . ($page + 1) . $q, 'Older', 'page-link') ?></li><?php endif; ?>
        </ul></nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

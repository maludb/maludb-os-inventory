<?php
/**
 * Home (screen `home`, sso-shell.md): the regions of design §9, each a card `#home-{region}` with an empty state naming the slice that
 * fills it (slice 9 makes them real); the bell's five newest unread are real now. The order on a phone: the note, at risk, my orders or
 * the warehouse block, today, sources, unmatched, POs, the admin. Data: s (home_summary), tz, seesCost
 */
$may = $s['may'];
$coming = static fn (string $id, string $icon, string $title, string $text, string $slice, string $href = ''): string =>
    '<div class="card mb-3" id="home-' . e($id) . '"><div class="card-body"><div class="empty-state" id="home-' . e($id) . '-empty"><span class="avatar-text avatar-lg rounded"><i class="' . e($icon) . '"></i></span>'
    . '<div><div class="fw-semibold">' . ($href !== '' ? hx_link($href, e($title), 'text-dark') : e($title)) . '</div><div class="fs-12 text-muted">' . e($text) . ' <span class="text-muted">(' . e($slice) . ')</span></div></div></div></div></div>';
?>
<?= view('shared/header.php', ['id' => 'home', 'title' => 'Home', 'crumbs' => [['Home', null]]]) ?>
<div class="main-content" id="home-content">
    <div class="row g-3">
        <div class="col-lg-6">
            <?= $s['note'] === null ? $coming('note', 'feather-sunrise', 'The morning note', "The Stock Buyer's seven headings — at risk, reorder, prices, unmatched, sources, purchase orders, returns — each a count that opens its list.", 'slice 9') : '' ?>
            <?= $s['at_risk'] === null ? $coming('at-risk', 'feather-alert-triangle', 'Lines at risk', 'Order lines whose source is gone, whose price moved or whose supplier is late appear here.', 'slice 9', '/orders/') : '' ?>
            <?php if ($may['sales']): ?><?= $s['my_orders'] === null ? $coming('my-orders', 'feather-shopping-cart', 'My open orders', 'Your quotes and orders with their next step — confirm, record a deposit, ship, deliver — appear here.', 'slice 5', '/orders/') : '' ?><?php endif; ?>
            <?php if ($may['warehouse']): ?><?= $s['warehouse'] === null ? $coming('warehouse', 'feather-layers', 'To receive, to pick, to count', 'Draft receipts and stock purchase orders due, the lines to pick today and the open counts appear here.', 'slice 2 and 5', '/stock/') : '' ?><?php endif; ?>
            <?= $s['today'] === null ? $coming('today', 'feather-truck', "Today's deliveries and pickups", 'What goes out today by location and delivery method appears here.', 'slice 5', '/orders/today') : '' ?>
        </div>
        <div class="col-lg-6">
            <div class="card mb-3" id="home-bell"><div class="card-header"><h5 class="card-title mb-0">Unread</h5></div>
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
            <?= $s['sources'] === null ? $coming('sources', 'feather-rss', 'Pulls failed or blocked', 'Sources whose last pull failed, that blocked us, or that are paused appear here.', 'slice 3', '/sources/') : '' ?>
            <?php if ($may['match']): ?><?= $s['unmatched'] === null ? $coming('unmatched', 'feather-link', 'Unmatched listings', 'Listings nothing in the catalog matches — the count and the five newest — appear here.', 'slice 3', '/matching/') : '' ?><?php endif; ?>
            <?= $s['po_ack'] === null ? $coming('po-ack', 'feather-clipboard', 'Purchase orders awaiting acknowledgment', 'Purchase orders sent and not yet acknowledged by the supplier appear here.', 'slice 6', '/purchasing/') : '' ?>
            <?php if ($may['admin']): ?><?= $s['admin'] === null ? $coming('admin', 'feather-shield', 'For the admin', "The feed's usage today and the agents' dispatches (pending, running, awaiting approval, failed) appear here.", 'slice 7 and 8', '/admin/dispatches') : '' ?><?php endif; ?>
        </div>
    </div>
</div>

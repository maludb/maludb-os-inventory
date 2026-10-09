<?php
declare(strict_types=1);
/** / — home (screen `home`, Phase 2: the regions as empty states naming their slice; the bell real). Slice 9 replaces this with the real one (reports-admin.md). */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/home/queries.php';
require_once dirname(__DIR__) . '/app/features/settings/present.php';
require_login();
require_human();
$pdo = db();
$me = (int) current_member_id();
log_screen_view($pdo, 'home');
$s = home_summary($pdo, $me);
if (wants_json()) {
    respond_screen(['note' => null, 'at_risk' => null, 'sources' => null, 'unmatched' => null, 'po_ack' => null, 'today' => null, 'my_orders' => null, 'warehouse' => null, 'admin' => null,
        'bell' => array_map('present_notification', $s['bell']), 'may' => $s['may'], 'phase' => 'Phase 2: the regions are slice 9\'s']);
}
render_screen('Home', view('home/dashboard.php', ['s' => $s, 'tz' => member_timezone(), 'seesCost' => sees_cost()]), ['activeNav' => 'home', 'screen' => 'home']);

<?php
declare(strict_types=1);
/** / — home (screen `home`, reports-admin.md): the morning note's seven headings as counts that open their lists, the lines at risk, the pulls failed or blocked, the unmatched listings, the purchase orders awaiting acknowledgment, today's deliveries and pickups, and the role's own block. Writes only `screen.view`. */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/home/queries.php';
require_once dirname(__DIR__) . '/app/features/home/present.php';
require_once dirname(__DIR__) . '/app/features/settings/present.php';
require_login();
require_human();
$pdo = db();
$me = (int) current_member_id();
log_screen_view($pdo, 'home');
$s = home_summary($pdo, $me);
if (wants_json()) {
    respond_screen(present_home($s));
}
render_screen('Home', view('home/dashboard.php', ['s' => $s, 'tz' => member_timezone(), 'seesCost' => sees_cost()]), ['activeNav' => 'home', 'screen' => 'home']);

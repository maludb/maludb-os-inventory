<?php
declare(strict_types=1);
/** /notifications — my notices, unread first (screen `notifications`; ?unread=1 only the unread; ?page=; ?count=1 the bell's count alone — Pattern A, no log). Slices 3–8 write them. People only. */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/settings/queries.php';
require_once dirname(__DIR__) . '/app/features/settings/present.php';
require_login();
require_human();
$pdo = db();
$me = (int) current_member_id();
if (request_string('count') === '1') {
    header('Vary: HX-Request');
    echo view('shared/bell.php', ['unread' => bell_count($pdo, $me)]);
    exit;
}
$unreadOnly = request_bool('unread');
$page = max(1, (int) (request_integer('page') ?? 1));
$r = find_my_notifications($pdo, $me, $page, $unreadOnly);
log_screen_view($pdo, 'notifications');
if (wants_json()) {
    respond_screen(['unread_only' => $unreadOnly, 'page' => $page, 'more' => $r['more'], 'unread' => bell_count($pdo, $me), 'notifications' => array_map('present_notification', $r['rows'])]);
}
render_screen('Notifications', view('settings/notifications.php', ['rows' => $r['rows'], 'more' => $r['more'], 'page' => $page, 'tz' => member_timezone(), 'unreadOnly' => $unreadOnly]),
    ['activeNav' => 'notifications', 'screen' => 'notifications', 'entity' => 'notification']);

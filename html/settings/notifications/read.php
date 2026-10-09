<?php
declare(strict_types=1);
/** Action `notification_read` (log `notification.read`, after.count): mark one of my notifications read (`notification`), or every unread one (empty). Own rows only. Location /notifications; refresh notificationChanged. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/queries.php';
inv_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('notification');
$n = inv_guard($pdo, static function () use ($pdo, $me, $id): int {
    $pdo->beginTransaction();
    $n = mark_notifications_read($pdo, $me, $id);
    log_activity($pdo, 'notification.read', $id === null ? null : 'notification', $id, ['after' => ['count' => $n, 'all' => $id === null]]);
    $pdo->commit();
    return $n;
});
$did = $id === null ? 'Marked ' . $n . ' notification' . ($n === 1 ? '' : 's') . ' read' : ($n === 1 ? 'Marked the notification read' : 'That notification was already read, or is not yours');
inv_done($did, $id, '/notifications', 'notificationChanged', ['count' => $n]);

<?php
/**
 * A proof's way into the application's own functions as a member (the proofs' other doors are HTTP): `php call.php <member|0> <function> '<json args>'` loads the application the way the worker does, acts as that
 * member (app.member_id; 0 = nobody, as a worker pass), calls the function and prints {"result": …} or {"error": …} as JSON. Only the functions this slice's proofs name.
 */
if (PHP_SAPI !== 'cli') { exit(1); }
require dirname(__DIR__, 3) . '/app/bootstrap.php';
foreach (['buyer/queries', 'buyer/present', 'buyer/write', 'returns/queries', 'orders/queries', 'notes/queries', 'files/queries', 'agents/queries', 'agents/present', 'notify/send', 'agents/dispatch'] as $f) { require_once APP_ROOT . "/app/features/$f.php"; }
$member = (int) ($argv[1] ?? 0);
$fn = (string) ($argv[2] ?? '');
$args = json_decode((string) ($argv[3] ?? '[]'), true) ?: [];
$allowed = ['morning_note', 'morning_note_markdown', 'morning_note_counts', 'notify', 'buyer_member', 'notify_buyer', 'notification_record_url', 'notification_kind', 'find_buyer_proposals', 'recently_dismissed', 'send_morning_note', 'dispatch_utterance',
            'find_dispatches', 'dispatch_run_url', 'returnable_lines', 'orders_with_returnable_lines', 'find_returns', 'return_reasons', 'note_record', 'attachment_record', 'thumb_path', 'find_notes', 'find_attachments', 'business_today', 'render_notice_mail'];
if (!in_array($fn, $allowed, true)) { echo json_encode(['error' => 'not allowed: ' . $fn]); exit(2); }
if ($member > 0) { $_SESSION = ['member_id' => $member]; }
$GLOBALS['__public_door'] = 'cron';
$pdo = db();
try {
    $withPdo = ['morning_note', 'notify', 'buyer_member', 'notify_buyer', 'find_buyer_proposals', 'recently_dismissed', 'send_morning_note', 'find_dispatches', 'returnable_lines', 'orders_with_returnable_lines', 'find_returns', 'return_reasons', 'find_notes', 'find_attachments', 'business_today', 'dispatch_utterance'];
    $r = in_array($fn, $withPdo, true) ? $fn($pdo, ...$args) : $fn(...$args);
    echo json_encode(['result' => $r], JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    echo json_encode(['error' => $e->getMessage(), 'class' => get_class($e)]);
}

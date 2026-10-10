<?php
declare(strict_types=1);
/**
 * Action `morning_note_send` (log `buyer.note`: note_date, counts — the seven, computed here, not read from the body —, sent_to, queued): agents.settings (the admin), or an AGENT holding reports.read (the Buyer
 * agent). `body` (1–20,000 characters: the seven headings as the agent wrote them), `to_member` (the settings' Buyer by default; else the first super-admin). The note is queued as a `morning_note` notice — the bell
 * and the e-mail by the person's preferences — once a day to each person: a second send is "already sent today". The kernel's message_send is the agent's own tool and is not called here (DECISION 6).
 * Location /proposals/?date=; refresh notificationChanged.
 */
require_once dirname(__DIR__, 2) . '/app/features/orders/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/buyer/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/buyer/present.php';
require_once dirname(__DIR__, 2) . '/app/features/buyer/write.php';
inv_handler_begin();
$agent = (current_member()['member_kind'] ?? '') === 'agent';
if (!has_right('agents.settings') && !($agent && has_right('reports.read'))) { refuse(403, "The morning note is the Buyer agent's or the admin's."); }
$pdo = db();
$body = trim((string) (req_val('body') ?? ''));
if ($body === '') { inv_refuse_fields(['body' => 'Write the note.']); }
if (mb_strlen($body) > 20000) { inv_refuse_fields(['body' => 'A morning note is up to 20,000 characters.']); }
$to = null;
if (req_has('to_member') && trim((string) req_val('to_member')) !== '') {
    $tv = trim((string) req_val('to_member'));
    if (!ctype_digit($tv)) { inv_refuse_fields(['to_member' => 'Choose a member from the list.']); }
    $to = (int) $tv;
}
$r = inv_guard($pdo, static function () use ($pdo, $body, $to): array {
    $pdo->beginTransaction();
    $r = send_morning_note($pdo, $body, $to, (int) current_member_id());
    $note = morning_note($pdo, $r['date']);
    log_activity($pdo, 'buyer.note', 'morning_note', (int) str_replace('-', '', $r['date']), ['after' => ['note_date' => $r['date'], 'counts' => morning_note_counts($note), 'sent_to' => $r['to'],
        'sent_to_buyer' => $r['to'] === buyer_member($pdo), 'queued' => $r['queued']]]);
    $pdo->commit();
    return $r;
});
inv_done($r['queued'] ? 'Sent the morning note for ' . $r['date'] : 'already sent today', $r['notification_id'], inv_land('/proposals/?date=' . $r['date']), 'notificationChanged',
    ['queued' => $r['queued'], 'to' => $r['to'], 'note_date' => $r['date']]);

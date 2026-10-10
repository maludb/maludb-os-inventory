<?php
declare(strict_types=1);

/**
 * The sender — the worker's `outbox` pass (returns-worker.md "The sender"): the queued rows of notification_outbox, oldest first, each its own try. E-mail through MaluMail
 * (malumail_send), texts to MEMBERS through the kernel (kernel_send_text — K6; the application never holds a Twilio key and never sees a phone number). Outcomes:
 *   sent     → provider_ref (MaluMail's message id, the kernel's `kernel:<id>`), sent_at
 *   skipped  → a reason that will not change (no address, an address MaluMail rejects or has suppressed, a K6 refusal, no key): never retried, the e-mail row of the same notice stands
 *   retried  → attempts + 1 and send_after = now + 2^attempts minutes (2, 4, 8, 16); the fifth failure is failed
 *   failed   → a bad key at once (401/403), or the fifth transient failure
 * Every outcome is logged (`notification.send` | `notification.skip` | `notification.fail`: kind, channel, record type and id, the code, the attempts) — NEVER a body, never an address.
 */

require_once __DIR__ . '/present.php';
require_once dirname(__DIR__) . '/orders/mail.php';          // inv_public_url()

const OUTBOX_MAX_ATTEMPTS = 5;

/** The pass: ['sent', 'skipped', 'retried', 'failed']. */
function outbox_send_batch(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    $st = $pdo->prepare("SELECT id, channel, member_id, to_email, kind, record_type, record_id, subject, body, body_html, attempts FROM notification_outbox
                          WHERE status = 'queued' AND send_after <= CAST(:now AS timestamptz) ORDER BY created_at, id LIMIT " . max(1, min(1000, $limit)));
    $st->execute(['now' => $now->format('Y-m-d H:i:s.uP')]);
    $out = ['sent' => 0, 'skipped' => 0, 'retried' => 0, 'failed' => 0];
    foreach ($st->fetchAll() as $row) {
        try {
            $o = $row['channel'] === 'text' ? outbox_send_text($pdo, $row) : outbox_send_email($pdo, $row);
        } catch (Throwable $e) {
            error_log('outbox row ' . $row['id'] . ': ' . $e->getMessage());
            $o = ['retry', 'error', null];
        }
        [$what, $code, $ref] = $o;
        if ($what === 'retry') {
            $attempts = (int) $row['attempts'] + 1;
            if ($attempts >= OUTBOX_MAX_ATTEMPTS) { $what = 'failed'; $code = (string) $code; $o = ['failed', $code, null]; }
            else {
                $pdo->prepare("UPDATE notification_outbox SET attempts = :a, detail = :d, send_after = CAST(:t AS timestamptz) WHERE id = :id")
                    ->execute(['a' => $attempts, 'd' => $code, 't' => $now->modify('+' . (2 ** $attempts) . ' minutes')->format('Y-m-d H:i:s.uP'), 'id' => $row['id']]);
                $out['retried']++;
                continue;
            }
        }
        if ($what === 'sent') {
            $pdo->prepare("UPDATE notification_outbox SET status = 'sent', provider_ref = :r, sent_at = CAST(:t AS timestamptz), detail = NULL, attempts = attempts + 1 WHERE id = :id")
                ->execute(['r' => $ref, 't' => $now->format('Y-m-d H:i:s.uP'), 'id' => $row['id']]);
            $out['sent']++;
        } else {
            $status = $what === 'skipped' ? 'skipped' : 'failed';
            $pdo->prepare('UPDATE notification_outbox SET status = :s, detail = :d, attempts = attempts + 1 WHERE id = :id')->execute(['s' => $status, 'd' => (string) $code, 'id' => $row['id']]);
            $out[$status]++;
        }
        if ($what === 'skipped' && $code === 'unconfigured' && outbox_unconfigured_logged_today($pdo)) { continue; }      // once a day, the rest silent
        $action = $what === 'sent' ? 'notification.send' : ($what === 'skipped' ? 'notification.skip' : 'notification.fail');
        log_activity($pdo, $action, 'notification_outbox', (int) $row['id'], ['source' => 'cron', 'actor_member_id' => null,
            'after' => ['kind' => $row['kind'], 'channel' => $row['channel'], 'record_type' => $row['record_type'], 'record_id' => $row['record_id'] === null ? null : (int) $row['record_id'],
                        'code' => $what === 'sent' ? null : $code, 'attempts' => (int) $row['attempts'] + 1]]);
    }
    return $out;
}

function outbox_unconfigured_logged_today(PDO $pdo): bool
{
    return one_value($pdo, "SELECT 1 FROM activity_log WHERE action = 'notification.skip' AND after->>'code' = 'unconfigured' AND occurred_at > now() - interval '24 hours' LIMIT 1") !== null;
}

/** An e-mail: [outcome, code, provider_ref]; outcome is sent | skipped | failed | retry. */
function outbox_send_email(PDO $pdo, array $row): array
{
    $to = (string) ($row['to_email'] ?? '');
    if ($to === '') { $to = (string) (one_value($pdo, 'SELECT email FROM members WHERE id = :m', ['m' => (int) $row['member_id']]) ?? ''); }
    if ($to === '') { return ['skipped', 'no_address', null]; }
    if ((string) env('MALUMAIL_API_KEY', '') === '') { return ['skipped', 'unconfigured', null]; }
    $url = notification_record_url(['record_type' => $row['record_type'], 'record_id' => $row['record_id']], false);
    $abs = $url === null ? null : inv_public_url($url);
    $subject = (string) ($row['subject'] ?? '');
    $subject = $subject === '' ? 'A notice from ' . app_name() : $subject;
    $html = (string) ($row['body_html'] ?? '') !== '' ? (string) $row['body_html'] : render_notice_mail($row, $abs);
    $text = (string) $row['body'] . ($abs !== null ? "\n\n" . $abs : '');
    $mail = ['from' => (string) env('MAIL_FROM', ''), 'from_name' => (string) (env('MAIL_FROM_NAME') ?: app_name()), 'to' => $to, 'subject' => $subject, 'text' => $text, 'html' => $html];
    try {
        $r = malumail_send($mail);
    } catch (Throwable $e) {
        return ['retry', 'transport', null];                        // a transport error: try again later
    }
    $status = (int) $r['status'];
    $body = $r['body'];
    $rejected = (array) ($body['rejected'] ?? []);
    if ($status === 401 || $status === 403) { return ['failed', 'bad_key', null]; }
    if ($status === 429 || $status >= 500 || $status === 0) { return ['retry', 'http_' . $status, null]; }
    if ($status >= 200 && $status < 300 && (!isset($body['accepted']) || $body['accepted'] !== []) ) { return ['sent', null, $r['message_id']]; }
    if ($rejected !== [] || ($status >= 200 && $status < 300)) { return ['skipped', mb_substr((string) ($rejected[0]['reason'] ?? 'rejected'), 0, 120), null]; }
    return ['failed', 'http_' . $status, null];
}

/** A text to a member through the kernel (K6): the kernel decides whether they may be texted. [outcome, code, provider_ref]. */
function outbox_send_text(PDO $pdo, array $row): array
{
    [$what, $code, $ref] = kernel_send_text((int) $row['member_id'], mb_substr((string) $row['body'], 0, 480), $row['kind'] . ':' . ($row['record_type'] ?? '') . ':' . ($row['record_id'] ?? ''));
    if ($what === 'unconfigured') { return ['skipped', 'unconfigured', null]; }
    return $what === 'retry' ? ['retry', (string) $code, null] : [$what, $code, $ref];
}

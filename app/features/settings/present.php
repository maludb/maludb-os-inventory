<?php
declare(strict_types=1);

/** The JSON shapes of a person's settings — whitelists, never a raw row; never a token's value or hash. */

function present_prefs(array $p): array
{
    return ['email_enabled' => (bool) $p['email_enabled'], 'text_enabled' => (bool) $p['text_enabled'], 'kinds' => array_values($p['kinds']),
            'text_kinds' => array_values($p['text_kinds']), 'saved' => (bool) ($p['saved'] ?? true)];
}

function present_token(array $t): array
{
    return ['token_id' => (int) $t['id'], 'label' => $t['label'], 'scope' => $t['scope'], 'created_at' => json_ts($t['created_at']),
            'last_used_at' => json_ts($t['last_used_at'] ?? null), 'expires_at' => json_ts($t['expires_at'] ?? null), 'revoked' => ($t['revoked_at'] ?? null) !== null,
            'state' => token_state($t)];
}

/** live | revoked | expired (sso-shell.md "Status vocabulary"). */
function token_state(array $t): string
{
    if (($t['revoked_at'] ?? null) !== null) {
        return 'revoked';
    }
    if (($t['expires_at'] ?? null) !== null && strtotime((string) $t['expires_at']) < time()) {
        return 'expired';
    }
    return 'live';
}

function present_notification(array $n): array
{
    return ['notification_id' => (int) $n['notification_id'], 'kind' => $n['kind'], 'record_type' => $n['record_type'],
            'record_id' => $n['record_id'] !== null ? (int) $n['record_id'] : null, 'title' => $n['title'], 'body' => $n['body'],
            'read' => $n['read_at'] !== null, 'created_at' => json_ts($n['created_at']), 'url' => record_url($n['record_type'], $n['record_id'])];
}

/** The chip colour of a notification kind (sso-shell.md "Status vocabulary"). */
function notification_kind_colour(string $kind): string
{
    return match ($kind) {
        'watch', 'mention' => 'primary', 'line_at_risk', 'po_decline' => 'danger', 'pull_failed', 'pull_blocked', 'unmatched' => 'warning',
        'po_ack', 'po_tracking', 'order' => 'info', 'return', 'agent_drafted' => 'secondary', 'morning_note' => 'dark', default => 'secondary',
    };
}

<?php
declare(strict_types=1);

/**
 * A person's own settings (sso-shell.md): how they are told (notification_prefs, db/013), their in-app notifications (the bell), their
 * access tokens for the two MCP servers (mcp_access_tokens, db/003) — screens `my-settings`, `notifications`, `tokens`; actions
 * prefs_save, notification_read, token_mint, token_revoke. The time zone is the directory's (members.timezone), read-only here.
 * The business's settings (settings_save) are slice 9's, in app/features/admin.
 */

/** Every event a person can be told about, with the sentence the settings screen shows (db/013's thirteen notification kinds). */
const NOTICE_KINDS = [
    'watch'         => 'A watch I set fires (back in stock, a price or cost below my threshold, MAP breached, a lead time over, a listing removed)',
    'line_at_risk'  => 'A line on an order I own is at risk (the source is gone, the price moved, the supplier is late)',
    'pull_failed'   => 'A pull of a source fails',
    'pull_blocked'  => 'A source blocks us and the worker backs off',
    'po_ack'        => 'A supplier acknowledges a purchase order',
    'po_decline'    => 'A supplier declines a purchase order',
    'po_tracking'   => 'A supplier gives tracking for a purchase order',
    'return'        => 'A return is authorized, received or dispositioned',
    'morning_note'  => "The Stock Buyer's morning note",
    'mention'       => 'Someone mentions me in a note',
    'order'         => 'An order of mine changes (confirmed, paid, shipped, delivered)',
    'agent_drafted' => 'An agent drafts something for me to look at (a purchase order, a match, a quote)',
    'unmatched'     => 'A listing arrives that nothing in the catalog matches',
];

/** The person's choices, or the table's defaults when they never saved any (`saved` false). */
function find_prefs(PDO $pdo, int $memberId): array
{
    $st = $pdo->prepare('SELECT email_enabled, text_enabled, kinds, text_kinds FROM notification_prefs WHERE member_id = :m');
    $st->execute(['m' => $memberId]);
    $r = $st->fetch();
    if ($r === false) {
        $d = $pdo->query("SELECT column_name, column_default FROM information_schema.columns WHERE table_name = 'notification_prefs' AND column_name IN ('kinds', 'text_kinds')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $lit = static fn (string $def): string => preg_match("/'(\{[^']*\})'/", $def, $m) ? $m[1] : '{}';
        return ['email_enabled' => true, 'text_enabled' => false, 'kinds' => pg_text_array($lit((string) ($d['kinds'] ?? ''))),
                'text_kinds' => pg_text_array($lit((string) ($d['text_kinds'] ?? ''))), 'saved' => false];
    }
    return ['email_enabled' => (bool) $r['email_enabled'], 'text_enabled' => (bool) $r['text_enabled'], 'kinds' => pg_text_array((string) $r['kinds']),
            'text_kinds' => pg_text_array((string) $r['text_kinds']), 'saved' => true];
}

/** The same, by the spec's name. */
function my_prefs(PDO $pdo, int $memberId): array
{
    return find_prefs($pdo, $memberId);
}

/** Save the choices ($fields present are changed; absent are kept). Answers ['before' => …, 'after' => …] of the changed keys and `prefs`. INSERT … ON CONFLICT. */
function save_prefs(PDO $pdo, int $memberId, array $fields): array
{
    $before = find_prefs($pdo, $memberId);
    unset($before['saved']);
    $after = array_intersect_key($fields, $before) + $before;
    $lit = static fn (array $a): string => '{' . implode(',', array_map(static fn (string $k): string => preg_replace('/[^a-z_]/', '', $k), $a)) . '}';
    $pdo->prepare('INSERT INTO notification_prefs (member_id, email_enabled, text_enabled, kinds, text_kinds) VALUES (:m, :e, :t, CAST(:k AS text[]), CAST(:tk AS text[]))
                   ON CONFLICT (member_id) DO UPDATE SET email_enabled = EXCLUDED.email_enabled, text_enabled = EXCLUDED.text_enabled, kinds = EXCLUDED.kinds, text_kinds = EXCLUDED.text_kinds, updated_at = now()')
        ->execute(['m' => $memberId, 'e' => $after['email_enabled'] ? 't' : 'f', 't' => $after['text_enabled'] ? 't' : 'f', 'k' => $lit($after['kinds']), 'tk' => $lit($after['text_kinds'])]);
    $changed = array_keys(array_filter($after, static fn ($v, string $k): bool => $v !== $before[$k], ARRAY_FILTER_USE_BOTH));
    return ['before' => array_intersect_key($before, array_flip($changed)), 'after' => array_intersect_key($after, array_flip($changed)), 'prefs' => $after];
}

/**
 * What the kernel last said about this person's texts, from our own outbox (this application never sees a phone number):
 * 'no_verified_phone', 'opted_out', 'no_sender' or null when the latest text was sent (or none was ever tried).
 */
function last_text_refusal(PDO $pdo, int $memberId): ?string
{
    $st = $pdo->prepare("SELECT status, detail FROM notification_outbox WHERE member_id = :m AND channel = 'text' AND status IN ('sent', 'skipped', 'failed') ORDER BY id DESC LIMIT 1");
    $st->execute(['m' => $memberId]);
    $r = $st->fetch();
    if ($r === false || $r['status'] === 'sent') {
        return null;
    }
    foreach (['no_verified_phone', 'opted_out', 'no_sender'] as $code) {
        if (str_contains((string) $r['detail'], $code)) {
            return $code;
        }
    }
    return null;
}

// ---- notifications (the bell) ----------------------------------------------------------------------------------------
/** My notices, unread first then newest first (mcp_notifications), 50 a page. */
function find_my_notifications(PDO $pdo, int $memberId, int $page = 1, bool $unreadOnly = false, int $limit = 50): array
{
    $limit = max(1, min(200, $limit));
    $st = $pdo->prepare('SELECT notification_id, kind, record_type, record_id, title, body, read_at, created_at FROM mcp_notifications WHERE member_id = :m'
        . ($unreadOnly ? ' AND read_at IS NULL' : '') . ' ORDER BY (read_at IS NULL) DESC, created_at DESC LIMIT ' . ($limit + 1) . ' OFFSET ' . (max(1, $page) - 1) * $limit);
    $st->execute(['m' => $memberId]);
    $rows = $st->fetchAll();
    return ['rows' => array_slice($rows, 0, $limit), 'more' => count($rows) > $limit];
}

/** Mark one of the member's own notifications read, or every unread one (null). Returns how many changed. */
function mark_notifications_read(PDO $pdo, int $memberId, ?int $id): int
{
    if ($id === null) {
        $st = $pdo->prepare('UPDATE notifications SET read_at = now() WHERE member_id = :m AND read_at IS NULL');
        $st->execute(['m' => $memberId]);
    } else {
        $st = $pdo->prepare('UPDATE notifications SET read_at = now() WHERE member_id = :m AND id = :id AND read_at IS NULL');
        $st->execute(['m' => $memberId, 'id' => $id]);
    }
    return $st->rowCount();
}

// ---- tokens -----------------------------------------------------------------------------------------------------------
/** My tokens (mcp_access_tokens_mine), newest first. */
function my_tokens(PDO $pdo, int $memberId): array
{
    return $pdo->query('SELECT token_id AS id, label, scope, last_used_at, expires_at, revoked_at, created_at FROM mcp_access_tokens_mine ORDER BY created_at DESC')->fetchAll();
}

function find_my_token(PDO $pdo, int $memberId, int $tokenId): ?array
{
    $st = $pdo->prepare('SELECT token_id AS id, label, scope, last_used_at, expires_at, revoked_at, created_at FROM mcp_access_tokens_mine WHERE token_id = :id');
    $st->execute(['id' => $tokenId]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** Mint a token: `mcp_` + 48 hex, shown once; only the sha256 is stored (as bin/mint_mcp_token.php does). Returns id, raw, label, scope. */
function mint_token(PDO $pdo, int $memberId, string $label, string $scope): array
{
    $raw = 'mcp_' . bin2hex(random_bytes(24));
    $st = $pdo->prepare('INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope) VALUES (:m, :l, :h, :s) RETURNING id');
    $st->execute(['m' => $memberId, 'l' => $label, 'h' => hash('sha256', $raw), 's' => $scope]);
    return ['id' => (int) $st->fetchColumn(), 'raw' => $raw, 'label' => $label, 'scope' => $scope];
}

/** Revoke one of the member's own live tokens (revoked_at = now()). False when there is none such. */
function revoke_token(PDO $pdo, int $memberId, int $tokenId): bool
{
    $st = $pdo->prepare('UPDATE mcp_access_tokens SET revoked_at = now() WHERE id = :id AND member_id = :m AND revoked_at IS NULL RETURNING id');
    $st->execute(['id' => $tokenId, 'm' => $memberId]);
    return $st->fetchColumn() !== false;
}

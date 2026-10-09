<?php
declare(strict_types=1);

/**
 * What the shell reads on every render (sso-shell.md "Query functions"): the bell's count (mcp_notifications), the business name
 * (inv_settings) and my roles (inv_member_roles()). Each is cached for the request — one query per render, whatever the screen.
 */

/** The bell: how many of my notifications are unread. */
function bell_count(PDO $pdo, int $memberId): int
{
    static $cache = null;
    return $cache ??= (int) one_value($pdo, 'SELECT count(*) FROM mcp_notifications WHERE read_at IS NULL AND member_id = :m', ['m' => $memberId]);
}

/** The business's name on the header (inv_settings.business_name), else the application's. */
function business_name(PDO $pdo): string
{
    static $cache = null;
    return $cache ??= (string) (one_value($pdo, 'SELECT business_name FROM inv_settings WHERE id = 1') ?: app_name());
}

/** The roles the member effectively holds, in the roles' order: [{role_key, name}] (inv_member_roles(), db/004). */
function my_roles(PDO $pdo, int $memberId): array
{
    static $cache = [];
    if (!isset($cache[$memberId])) {
        $st = $pdo->prepare('SELECT r.role_key, r.name FROM inv_roles r WHERE r.role_key = ANY (inv_member_roles(:m)) ORDER BY r.sort_order DESC');
        $st->execute(['m' => $memberId]);
        $cache[$memberId] = $st->fetchAll();
    }
    return $cache[$memberId];
}

<?php
declare(strict_types=1);
/** Action `token_revoke` (log `token.revoke`; confirm). The token's owner only — another person's answers 404. Location /settings/tokens/. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/queries.php';
inv_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('token') ?? request_integer('id');
$row = $id === null ? null : find_my_token($pdo, $me, $id);
if ($row === null || $row['revoked_at'] !== null || !revoke_token($pdo, $me, $id)) {
    refuse(404, 'Token not found.');
}
log_activity($pdo, 'token.revoke', 'mcp_access_token', $id, ['before' => ['label' => $row['label'], 'scope' => $row['scope']], 'token_id' => $id]);
inv_done('Revoked the token "' . $row['label'] . '"', $id, '/settings/tokens/', 'tokenChanged');

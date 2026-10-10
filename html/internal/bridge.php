<?php
declare(strict_types=1);
/**
 * POST /internal/bridge.php — the records server's door to the live connectors (find.md "The bridge"): loopback, `X-INV-Bridge: {ts}.{hmac}`,
 * a member admitted here holding orders.write; op `source_search` only. Answers JSON always. The public vhost answers 404 under /internal/.
 * The member is set for this request alone (no session survives it); the rows the live function logs say `mcp` or `agent`.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/sources/write.php';
require_once dirname(__DIR__, 2) . '/app/features/find/bridge.php';
header_remove('Set-Cookie');
$out = static function (array $body, int $status = 200): never {
    if (session_status() === PHP_SESSION_ACTIVE) { $_SESSION = []; session_destroy(); }
    header_remove('Set-Cookie');
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};
$fail = static fn (string $code, string $message, int $status) => $out(['ok' => false, 'error' => ['code' => $code, 'message' => $message]], $status);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Allow: POST'); $fail('method_not_allowed', 'POST only.', 405); }
$raw = (string) file_get_contents('php://input');
if (bridge_verify($raw, (string) ($_SERVER['HTTP_X_INV_BRIDGE'] ?? ''), (string) ($_SERVER['REMOTE_ADDR'] ?? ''), time()) !== null) { $fail('unauthorized', 'Not a call the bridge accepts.', 401); }
$body = json_decode($raw, true);
if (!is_array($body)) { $fail('invalid_argument', 'The body is JSON.', 422); }
if (($body['op'] ?? '') !== 'source_search') { $fail('no_such_op', 'The bridge answers source_search only.', 404); }
$pdo = db();
$member = bridge_caller($pdo, $body);
if ($member === null) { $fail('unknown_member', 'That caller is not a member here.', 403); }
$_SESSION['member_id'] = $member;
db_apply_context($pdo);
$door = ($body['door'] ?? '') === 'agent' ? 'agent' : 'mcp';
$GLOBALS['__activity_source'] = $door;
if ($door === 'agent' && is_int($body['run_id'] ?? null)) { $GLOBALS['__agent_run_id'] = $body['run_id']; }
if (is_string($body['request_id'] ?? null)) { $_SERVER['HTTP_X_REQUEST_ID'] = $body['request_id']; }
if (!has_right('orders.write')) { $fail('insufficient_right', 'Asking the sources needs the right to ' . RIGHT_WORDS['orders.write'] . '.', 403); }
set_time_limit(70);
$res = bridge_run_source_search($pdo, is_array($body['args'] ?? null) ? $body['args'] : [], $member, !empty($body['is_eval']));
if (!$res['ok']) { $out(['ok' => false, 'error' => $res['error']], $res['status']); }
$out($res);

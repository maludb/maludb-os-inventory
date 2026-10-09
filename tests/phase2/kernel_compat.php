<?php
/**
 * Proof: Inventory speaks the kernel's REAL wire formats. The kernel's own functions (read from /var/www/app/auth.php, never modified,
 * run under a shim that supplies the scratch ACTION_TOKEN_KEY) mint a hand-off token, its signed claims, a sign-out notice, a person's
 * action token and a kernel token; this application must accept exactly those. Catches drift between the copied verifiers here and
 * the kernel's minting side. Also the run-facts gate (valid: false → refused; the kernel down → refused) and the approval replay header.
 */
require __DIR__ . '/lib.php';
$src = file_get_contents('/var/www/app/auth.php');
$grab = function (string $name) use ($src): string {
    if (!preg_match('/^function ' . preg_quote($name, '/') . '\(.*?^}\n/ms', $src, $m)) { fwrite(STDERR, "cannot find $name in the kernel's app/auth.php\n"); exit(2); }
    return $m[0];
};
if (!function_exists('env')) { function env(string $k, ?string $d = null): ?string { $v = getenv($k); return $v === false ? $d : $v; } }
$code = '';
foreach (['base64url_encode', 'mint_sso_token', 'sign_sso_claims', 'mint_sso_logout_notice', 'mint_action_token', 'mint_kernel_token', 'action_token_key'] as $fn) {
    $code .= preg_replace('/^function ' . $fn . '\(/m', 'function kernel_' . $fn . '(', $grab($fn)) . "\n";
}
$code = preg_replace('/\b(base64url_encode|mint_sso_token|sign_sso_claims|mint_sso_logout_notice|mint_action_token|mint_kernel_token|action_token_key)\(/', 'kernel_$1(', $code);
$code = preg_replace('/function kernel_kernel_/', 'function kernel_', $code);
eval($code);

echo "The kernel mints; Inventory accepts\n";
$claims = ['member_id' => 40, 'display_name' => 'Kernel Minted', 'email' => 'km@example.invalid', 'business_role' => 'user', 'is_external' => false, 'status' => 'active', 'departments' => [],
    'capability' => 'write', 'role' => 'buyer', 'roles' => ['buyer', 'user', 'warehouse'], 'rights' => ['cost.read'], 'scopes' => [], 'scope' => null];
$url = '/sso?' . http_build_query(['token' => kernel_mint_sso_token(40, APP, 60), 'claims' => kernel_sign_sso_claims($claims)]);
$j = jar();
$r = req('GET', $url, ['jar' => $j]);
ok($r['code'] === 302 && $r['location'] === '/', "a token and claims minted by the kernel's mint_sso_token() and sign_sso_claims(): 302 to / ({$r['code']})");
ok(q('SELECT display_name, roles FROM members WHERE id = 40')[0] === ['display_name' => 'Kernel Minted', 'roles' => '{buyer,user,warehouse}'], 'the mirror follows the claims: the name, roles {buyer,user,warehouse}');
ok(right_of(40, 'cost.read') === true && badge_of($j) === 'Buyer', 'inv_has_right(cost.read) is true for her and the badge reads Buyer');
$r = req('GET', '/sso?' . http_build_query(['token' => kernel_mint_sso_token(40, APP, 60), 'claims' => kernel_sign_sso_claims(['roles' => ['user']] + $claims)]), ['jar' => $j2 = jar()]);
ok($r['code'] === 302 && right_of(40, 'cost.read') === false && badge_of($j2) === 'Sales', 'claims.roles = [user]: cost.read false, badge Sales');
$r = req('GET', '/sso?' . http_build_query(['token' => kernel_mint_sso_token(1, APP, 60), 'claims' => kernel_sign_sso_claims(['member_id' => 1, 'display_name' => 'SMOKE Owner', 'business_role' => 'super_admin', 'status' => 'active', 'capability' => 'admin', 'roles' => []])]), ['jar' => $j3 = jar()]);
ok($r['code'] === 302 && right_of(1, 'settings.manage') === true && page($j3, '/admin/settings')['code'] === 200, 'roles {} with capability admin: every right');
$r = req('GET', '/sso?' . http_build_query(['token' => kernel_mint_sso_token(40, 'hr', 60), 'claims' => kernel_sign_sso_claims($claims)]), ['jar' => jar()]);
ok($r['code'] === 403, 'the kernel\'s token for another application: 403');
$notice = kernel_mint_sso_logout_notice(40, APP);
$r = req('POST', '/sso/logout', ['form' => ['notice' => $notice]]);
ok($r['code'] === 204 && page($j, '/')['code'] === 302, "the kernel's mint_sso_logout_notice(): 204 and the session ended");
ok(req('POST', '/sso/logout', ['form' => ['notice' => 'not.a.notice']])['code'] === 204, '/sso/logout answers 204 on a bad notice too');
$t = kernel_mint_action_token(41, 300);
$r = req('GET', '/trail', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $t]]);
ok($r['code'] === 200, "the kernel's mint_action_token() is honoured as a person's action token (200)");
$kt = kernel_mint_kernel_token(APP, 60);
ok(count(explode('.', $kt)) === 5 && explode('.', $kt)[0] === 'kernel' && hash_equals(hash_hmac('sha256', 'kernel:' . implode('.', array_slice(explode('.', $kt), 1, 3)), need('ACTION_TOKEN_KEY')), explode('.', $kt)[4]),
   'the kernel token has the shape the records MCP will verify in Phase 4 (kernel.exp.app.nonce.hmac over "kernel:…")');

echo "The run-facts gate and the approval replay\n";
// an agent the feed introduces with no grant (member 901): admitted only when the kernel vouches; valid:false and a kernel down both refuse
kernel_state(function ($s) { $s['incremental'] = incr(['members' => [['id' => 901, 'member_kind' => 'agent', 'display_name' => 'SMOKE Other agent', 'email' => null, 'business_role' => 'user', 'is_external' => false, 'status' => 'active', 'updated_at' => '2026-01-01T00:00:00Z', 'departments' => []]]], '2026-01-01T00:05:00.000000Z'); return $s; });
sync();
$tok = run_token(901, 88);
kernel_state(function ($s) { $s['facts']['88'] = ['valid' => false]; return $s; });
ok(req('GET', '/trail', ['headers' => as_agent($tok)])['code'] === 401 && q('SELECT capability FROM members WHERE id = 901')[0]['capability'] === null, 'run-facts valid:false → refused, no admission');
$pid = (int) file_get_contents(need('INV_DEV_STATE') . '/kernel.pid');
posix_kill($pid, 15);
usleep(400000);
kernel_state(function ($s) { $s['facts']['88'] = ['valid' => true, 'is_agent' => true, 'member_id' => 901, 'run_id' => 88, 'request_id' => 'req-run-88', 'trigger' => 'chat', 'endpoints' => [['name' => 'Records MCP']]]; return $s; });
ok(req('GET', '/trail', ['headers' => as_agent($tok)])['code'] === 401 && q('SELECT capability FROM members WHERE id = 901')[0]['capability'] === null, 'the kernel down → refused (fail closed), no admission');
$cmd = 'sh -c ' . escapeshellarg('cd ' . escapeshellarg(dirname(__DIR__, 2)) . ' && exec php -S 127.0.0.1:8602 tests/fake_kernel.php >' . escapeshellarg(need('INV_DEV_STATE') . '/kernel.log') . ' 2>&1') . ' & echo $!';   // exec: the pid is php's own
file_put_contents(need('INV_DEV_STATE') . '/kernel.pid', trim((string) shell_exec($cmd)));
for ($i = 0; $i < 30; $i++) { usleep(200000); if (req('GET', 'http://127.0.0.1:8602/api/v1/runs/facts.php')['code'] > 0) { break; } }
ok(req('GET', '/trail', ['headers' => as_agent($tok)])['code'] === 200 && q('SELECT capability FROM members WHERE id = 901')[0]['capability'] === 'write', 'the kernel back and vouching → 200, admitted');
$since = last_activity_id();
$r = req('GET', '/trail', ['headers' => array_merge(as_agent($tok), ['X-Screen-View: 1'])]);
$row = q("SELECT source, agent_run_id, request_id FROM activity_log WHERE action = 'screen.view' AND actor_member_id = 901 AND id > :s ORDER BY id DESC LIMIT 1", ['s' => $since])[0] ?? [];
ok(($row['source'] ?? '') === 'agent' && (int) ($row['agent_run_id'] ?? 0) === 88 && ($row['request_id'] ?? '') === 'req-run-88', 'a signed run token with the relay acts as the agent: source agent, agent_run_id 88, the run\'s request id');
// the approval replay: the requester's 120 s token without the relay, with X-Approval-Replay
$replayTok = run_token(901, 88, 120);
$r = req('POST', '/settings/prefs.php', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $replayTok, 'X-Approval-Replay: 1'], 'form' => ['email_enabled' => 'no']]);
ok($r['code'] === 200 && (json_decode($r['body'], true)['ok'] ?? false) === true && one('SELECT email_enabled FROM notification_prefs WHERE member_id = 901') === false, 'an approval replay (the token without the relay + X-Approval-Replay) acts as the requester for one request');
ok(req('POST', '/settings/prefs.php', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $replayTok], 'form' => ['email_enabled' => 'yes']])['code'] === 401, 'the same token without the replay header and without the relay: 401');
kernel_state(function ($s) { unset($s['incremental'], $s['facts']); return $s; });
sign_on(40);   // the fixture's claims again (name, roles) for the proofs that follow
finish();

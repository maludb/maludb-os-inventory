<?php
/**
 * Proof: the directory sync (bin/directory_sync.php) against the fake kernel — the full pass applies the fixture (members, departments,
 * access[] roles), the incremental pass applies nothing and moves the cursor, a role change reaches members.roles and the badge in one
 * pass (Sam → buyer), an access row with capability null ends the member's sessions, a suspended member's sessions end, a department
 * delivered and a member admitted appear in the mirror, --from-file, and a failing kernel is reported (sso-shell.md "Proof: sync").
 */
require __DIR__ . '/lib.php';
$state = fn () => q('SELECT * FROM directory_sync_state WHERE id = 1')[0];
$L = 'https://app.example.invalid/launcher?app=inventory';

echo "1. The full pass\n";
ok((int) one('SELECT count(*) FROM members WHERE capability IS NOT NULL AND id IN (1, 40, 41, 42, 43, 44, 45)') === 7 && (int) one('SELECT count(*) FROM departments') === 4, 'the run.sh full pass admitted the seven fixture members (six people and the expert) and the four departments');
ok(q('SELECT roles FROM members WHERE id = 40')[0]['roles'] === '{buyer,user,warehouse}' && q('SELECT roles FROM members WHERE id = 45')[0]['roles'] === '{user}' && q('SELECT roles FROM members WHERE id = 44')[0]['roles'] === '{viewer}' && q('SELECT capability FROM members WHERE id = 46')[0]['capability'] === null, 'access[] roles landed (Nora buyer,user,warehouse; the expert user; Ann viewer); Omar holds nothing');
$st = $state();
ok($st['full_at'] !== null && $st['next_cursor'] !== null && $st['last_error'] === null, 'the sync state records the full pass (full_at, a cursor, no error)');
$out = sync('--full');
ok(str_contains($out, '(full)') && (int) one('SELECT count(*) FROM department_members WHERE left_at IS NULL') === 7, "running --full again changes nothing: $out");
ok(count(activity('directory.sync', 0)) >= 1 && activity('directory.sync', 0)[0]['source'] === 'cron', 'directory.sync is logged (source cron)');

echo "2. The incremental pass applies nothing and advances the cursor\n";
kernel_state(function ($s) { $s['feed']['next'] = '2026-03-01T00:00:00.000000Z'; unset($s['incremental']); return $s; });
$before = $state()['next_cursor'];
$out = sync();
ok(str_contains($out, 'applied 0 members, 0 departments, 0 memberships, 0 holdings') && !str_contains($out, '(full)'), "no change: $out");
ok($state()['next_cursor'] !== $before && str_starts_with($state()['next_cursor'], '2026-03-01'), 'the cursor moved to the kernel\'s next (' . $state()['next_cursor'] . ')');

echo "3. A role change reaches members.roles and the badge in one pass\n";
[$js, ] = sign_on(41);
ok(badge_of($js) === 'Sales' && page($js, '/receipts/')['code'] === 403 && page($js, '/matching/')['code'] === 403, 'Sam is Sales (Receive 403, the match queue 403)');
kernel_state(function ($s) { $s['incremental'] = incr(['access' => [['member_id' => 41, 'role' => 'buyer', 'roles' => ['buyer', 'user'], 'capability' => 'write', 'scopes' => []]]], '2026-03-02T00:00:00.000000Z'); return $s; });
$out = sync();
ok(str_contains($out, '1 holdings') && q('SELECT roles FROM members WHERE id = 41')[0]['roles'] === '{buyer,user}', "one holding applied, roles now buyer,user: $out");
ok(badge_of($js) === 'Buyer' && right_of(41, 'cost.read') === true && page($js, '/receipts/')['code'] === 200 && page($js, '/matching/')['code'] === 200, 'his open session wears Buyer at once, sees cost and opens Receive and the match queue — no new sign-on needed');
kernel_state(function ($s) { $s['incremental'] = incr(['access' => [['member_id' => 41, 'role' => 'admin', 'roles' => ['admin'], 'capability' => 'admin', 'scopes' => []]]], '2026-03-02T00:01:00.000000Z'); return $s; });
sync();
ok(badge_of($js) === 'Inventory admin' && page($js, '/admin/settings')['code'] === 200 && str_contains(page($js, '/')['body'], 'id="nav-group-admin"'), 'made an Inventory admin by the feed: the badge, the Admin group and the admin screens follow');
kernel_state(function ($s) { $s['incremental'] = incr(['access' => [['member_id' => 41, 'role' => 'user', 'roles' => ['user'], 'capability' => 'write', 'scopes' => []]]], '2026-03-03T00:00:00.000000Z'); return $s; });
sync();
ok(badge_of($js) === 'Sales' && page($js, '/admin/settings')['code'] === 403 && q('SELECT roles FROM members WHERE id = 41')[0]['roles'] === '{user}', 'and back to Sales: admin settings 403 again');

echo "4. Access withdrawn ends the member's sessions in the same pass (the fixture's incremental: Vera's grant revoked)\n";
[$jv, ] = sign_on(43);
ok(page($jv, '/')['code'] === 200, 'Vera is signed in');
kernel_state(function ($s) { $s['incremental'] = fixture()['incremental']; $s['incremental']['next'] = '2026-03-04T00:00:00.000000Z'; return $s; });
sync();
$r = page($jv, '/');
ok($r['code'] === 302 && $r['location'] === $L && (int) one('SELECT count(*) FROM member_sessions WHERE member_id = 43 AND ended_at IS NULL') === 0, 'her next request goes to the launcher; no live session left');
ok(q("SELECT ended_by FROM member_sessions WHERE member_id = 43 ORDER BY created_at DESC LIMIT 1")[0]['ended_by'] === 'directory', "ended_by = 'directory'");
ok(q('SELECT capability, roles FROM members WHERE id = 43')[0] === ['capability' => null, 'roles' => '{}'], 'the mirror holds no capability and no roles for her');
kernel_state(function ($s) { $s['incremental'] = incr(['access' => [['member_id' => 43, 'role' => 'viewer', 'roles' => ['viewer'], 'capability' => 'read', 'scopes' => []]]], '2026-03-05T00:00:00.000000Z'); return $s; });
sync();
ok(q('SELECT capability, roles FROM members WHERE id = 43')[0] === ['capability' => 'read', 'roles' => '{viewer}'], 'granted again for the later proofs');

echo "5. A suspended member's sessions end in the same pass\n";
[$jw, ] = sign_on(42);
ok(page($jw, '/')['code'] === 200, 'Wes is signed in');
kernel_state(function ($s) { $s['incremental'] = incr(['members' => [['id' => 42, 'member_kind' => 'human', 'display_name' => 'SMOKE Wes', 'email' => 'wes@example.invalid', 'business_role' => 'user',
    'is_external' => false, 'status' => 'suspended', 'updated_at' => '2026-03-06T00:00:00Z', 'departments' => []]]], '2026-03-06T00:00:00.000000Z'); return $s; });
$out = sync();
ok(str_contains($out, '1 members'), "the member row applied: $out");
ok((int) one('SELECT count(*) FROM member_sessions WHERE member_id = 42 AND ended_at IS NULL') === 0 && q("SELECT ended_by FROM member_sessions WHERE member_id = 42 ORDER BY created_at DESC LIMIT 1")[0]['ended_by'] === 'directory', 'in that pass: his sessions ended (ended_by directory)');
ok(page($jw, '/')['code'] === 302 && q('SELECT status FROM members WHERE id = 42')[0]['status'] === 'inactive', 'his next request goes to the launcher and the mirror says inactive');
ok(req('GET', handoff(42, ['claims' => ['status' => 'suspended'] + fixture()['claims']['42']]), ['jar' => jar()])['code'] === 403, 'a hand-off for him is refused too');

echo "6. A department delivered and a member admitted appear in the mirror\n";
kernel_state(function ($s) { $s['incremental'] = incr(['departments' => [['id' => 12, 'name' => 'Warehouse B', 'description' => null, 'parent_id' => 1, 'manager_member_id' => null, 'is_system' => false, 'system_key' => null, 'archived_at' => null, 'updated_at' => '2026-03-07T00:00:00Z']]], '2026-03-07T00:00:00.000000Z'); return $s; });
$out = sync();
ok(str_contains($out, '1 departments') && (int) one("SELECT count(*) FROM departments WHERE id = 12 AND name = 'Warehouse B'") === 1, "a department delivered → in the mirror: $out");
pdo()->exec("SELECT set_config('app.member_id', '40', false)");
ok((int) one('SELECT count(*) FROM mcp_departments WHERE department_id = 12') === 1, 'and in mcp_departments for a member here');
kernel_state(function ($s) { $s['incremental'] = incr(['members' => [['id' => 47, 'member_kind' => 'human', 'display_name' => 'SMOKE Noor', 'email' => 'noor@example.invalid', 'business_role' => 'user', 'is_external' => false, 'status' => 'active',
    'job_title' => 'Warehouse hand', 'timezone' => 'America/Chicago', 'updated_at' => '2026-03-08T00:00:00Z', 'departments' => [['id' => 12, 'name' => 'Warehouse B', 'is_admin' => false, 'is_primary' => true]]]],
    'memberships' => [['member_id' => 47, 'department_id' => 12, 'is_admin' => false, 'is_primary' => true, 'joined_at' => '2026-03-08T00:00:00Z', 'left_at' => null]],
    'access' => [['member_id' => 47, 'role' => 'warehouse', 'roles' => ['warehouse'], 'rights' => [], 'capability' => 'write', 'scopes' => []]]], '2026-03-08T00:00:00.000000Z'); return $s; });
$out = sync();
ok(q('SELECT capability, roles, timezone FROM members WHERE id = 47')[0] === ['capability' => 'write', 'roles' => '{warehouse}', 'timezone' => 'America/Chicago'], "a new member with an access row is admitted, with the directory's time zone: $out");
ok((int) one('SELECT count(*) FROM department_members WHERE member_id = 47 AND department_id = 12 AND left_at IS NULL') === 1, 'and is in Warehouse B');
[$jn, ] = sign_on(47, ['claims' => ['display_name' => 'SMOKE Noor', 'email' => 'noor@example.invalid', 'business_role' => 'user', 'is_external' => false, 'status' => 'active', 'departments' => [['id' => 12, 'name' => 'Warehouse B', 'is_admin' => false]], 'capability' => 'write', 'role' => 'warehouse', 'roles' => ['warehouse'], 'rights' => [], 'scopes' => [], 'scope' => null]]);
ok(badge_of($jn) === 'Warehouse' && str_contains(page($jn, '/settings/')['body'], 'id="settings-timezone-value">America/Chicago<'), 'Noor signs on as Warehouse and her settings show the directory\'s time zone');
$d = json_decode(page($jn, '/settings/', ['headers' => ['Accept: application/json']])['body'], true)['data'];
ok($d['timezone'] === 'America/Chicago', 'the JSON says the same');

echo "7. A fixture (--from-file) and a kernel that fails\n";
$out = sync('--from-file ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/dev_directory.json'));
ok(str_contains($out, '(full)') && str_contains($out, '8 members') && str_contains($out, '7 holdings') && q('SELECT status FROM members WHERE id = 42')[0]['status'] === 'active', "--from-file applies the fixture's feed (Wes active again): $out");
$out = (string) shell_exec('OS_INTERNAL_URL=http://127.0.0.1:1 php ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/directory_sync.php') . ' 2>&1; echo "exit=$?"');
ok(str_contains($out, 'the kernel did not answer') && str_contains($out, 'exit=1'), 'an unreachable kernel: the run says so and exits 1');
ok(($state()['last_error'] ?? '') !== '' && $state()['last_error'] !== null, 'and directory_sync_state.last_error holds the reason (' . $state()['last_error'] . ')');
$h = json_decode(req('GET', '/api/v1/health')['body'], true);
ok(($h['directory']['error'] ?? '') !== '', 'health reports it: directory.error');
kernel_state(function ($s) { $s['incremental'] = incr([], '2026-03-09T00:00:00.000000Z'); return $s; });
sync();
ok($state()['last_error'] === null && str_starts_with($state()['next_cursor'], '2026-03-09'), 'the next good pass clears the error and moves the cursor');
kernel_state(function ($s) { unset($s['incremental']); return $s; });
finish();

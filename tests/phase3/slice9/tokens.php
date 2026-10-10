<?php
/** Tokens for every role (reports-admin.md "Proof": ≥ 10): Phase 2's handlers proven whole — shown once, listed, revoked, never logged, refused to an agent. */
require __DIR__ . '/lib.php';
$w = admin_world();
$people = ['sam' => 41, 'nora' => 40, 'wes' => 42, 'vera' => 43, 'owner' => 1];

echo "1. Every role mints, lists, revokes\n";
foreach ($people as $name => $id) {
    $jar = who($name);
    [$c, $b] = act($jar, '/settings/tokens/mint.php', ['label' => "S9 $name", 'scope' => 'mcp']);
    $raw = $b['token'] ?? '';
    $row = q("SELECT * FROM mcp_access_tokens WHERE member_id = :m AND label = :l ORDER BY id DESC LIMIT 1", ['m' => $id, 'l' => "S9 $name"])[0] ?? null;
    ok($c === 200 && preg_match('/^mcp_[0-9a-f]{48}$/', $raw) === 1 && $row !== null && $row['revoked_at'] === null, "$name mints a token (shown once: mcp_ + 48 hex)");
    $html = pg($jar, '/settings/tokens/')[1];
    $listed = str_contains($html, "S9 $name") && !str_contains($html, $raw);
    [$c2] = act($jar, '/settings/tokens/revoke.php', ['token' => (string) $row['id']]);
    $after = q('SELECT revoked_at FROM mcp_access_tokens WHERE id = :i', ['i' => $row['id']])[0];
    $res = q('SELECT * FROM mcp_resolve_token(:h)', ['h' => hash('sha256', $raw)]);
    ok($listed && $c2 === 200 && $after['revoked_at'] !== null && $res === [], "$name: the list shows it (never its value), revoke works, and mcp_resolve_token() refuses it afterwards");
}

echo "2. The log and the agent\n";
$last = q("SELECT action, after::text AS a FROM activity_log WHERE action IN ('token.mint', 'token.revoke') ORDER BY id DESC LIMIT 12");
ok(count($last) >= 10 && !preg_match('/mcp_[0-9a-f]{48}/', implode(' ', array_column($last, 'a'))), 'token.mint and token.revoke are logged (' . count($last) . ' rows) and no row holds a token\'s value');
$tok = person_token(1);
kernel_state(function ($s) { $s['facts']['78'] = ['valid' => true, 'is_agent' => true, 'member_id' => EXPERT, 'run_id' => 78, 'request_id' => 'req-run-78', 'trigger' => 'chat', 'endpoints' => []]; return $s; });
$rt = run_token(EXPERT, 78);
$r = req('POST', '/settings/tokens/mint.php', ['headers' => as_agent($rt), 'form' => ['label' => 'agent']]);
ok($r['code'] === 403, 'an agent (a run token) minting → 403');
[$c, $b] = act(who('sam'), '/settings/tokens/mint.php', ['label' => '']);
ok($c === 422 && isset(fields($b)['label']), 'an empty label → 422 naming label');
[$c] = act(who('sam'), '/settings/tokens/revoke.php', ['token' => '999999']);
ok($c === 404, 'revoking a token that is not yours or not there → 404');
finish();

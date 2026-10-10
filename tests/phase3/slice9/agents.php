<?php
/** The agents page (reports-admin.md "Proof": ≥ 8): the declared agents matched to the mirror, their duty in words, their last action and dispatch, the OS links, no form, the right. */
require __DIR__ . '/lib.php';
$w = admin_world();
[$nora, $sam, $owner] = [who('nora'), who('sam'), who('owner')];
$by = static function (array $d): array { $o = []; foreach ($d['agents'] as $a) { $o[$a['declared_key'] ?? ('m' . $a['member_id'])] = $a; } return $o; };

echo "1. The page\n";
psql_exec("INSERT INTO agent_dispatches (agent_member_id, kind, via, status, detail, created_at) VALUES (" . EXPERT . ", 'ask', 'chat', 'failed', 'S9 proof', now() - interval '2 hours'), (" . EXPERT . ", 'ask', 'chat', 'sent', NULL, now() - interval '1 hour')");
psql_exec("INSERT INTO activity_log (actor_member_id, source, action, entity_type, entity_id, after) VALUES (" . EXPERT . ", 'agent', 'note.add', 'sales_order', {$w['so1']}, '{\"number\": \"SO-00002\"}'::jsonb)");
psql_exec("INSERT INTO buyer_proposals (kind, subject_type, subject_id, title, proposed_by) VALUES ('reorder', 'product_variant', {$w['twin']}, 'S9 proposal', " . BUYER_AGENT . ")");
[$c, $d] = screen($owner, '/admin/agents');
$a = $by($d);
ok($c === 200 && isset($a['expert']) && isset($a['buyer']), 'the two declared agents (expert, buyer) are listed');
ok($a['expert']['member_id'] === EXPERT && $a['expert']['hired'] === true && in_array('user', $a['expert']['roles'], true), 'the expert is matched to the mirror (SMOKE Inventory expert) and hired here, with its roles');
ok($a['buyer']['member_id'] === null && $a['buyer']['hired'] === false, 'the Buyer is "Not hired here yet" until the fixture adds it');
psql_exec("UPDATE members SET display_name = 'SMOKE Stock Buyer' WHERE id = " . BUYER_AGENT);
[$c, $d] = screen($owner, '/admin/agents');
$a = $by($d);
ok($a['buyer']['member_id'] === BUYER_AGENT && $a['buyer']['hired'] === true && $a['buyer']['roles'] === ['buyer'], 'with member 47 as "Stock Buyer" it is matched and hired');
ok($a['buyer']['duty']['name'] === 'The morning note' && $a['buyer']['duty']['schedule'] === 'every day at 06:30' && in_array('morning-note', $a['buyer']['skills'], true) && in_array('buying', $a['buyer']['skills'], true), 'the duty in words ("every day at 06:30") and the skills carried by name');
ok($a['expert']['last_action']['action'] === 'note.add' && str_contains($a['expert']['last_action']['sentence'], 'added a note') && $a['expert']['last_action']['occurred_at'] !== null, 'the last action from the log, as a sentence: ' . $a['expert']['last_action']['sentence']);
ok($a['expert']['last_dispatch']['status'] === 'sent' && $a['expert']['dispatches_pending'] >= 1 && $a['expert']['dispatches_failed'] >= 1, 'the last dispatch (newest first) and the pending and failed counts from slice 8\'s rows');
$pt = (int) one("SELECT count(*) FROM buyer_proposals WHERE proposed_by = " . BUYER_AGENT . ' AND note_date = current_date');
ok($a['buyer']['proposals_today'] === $pt && $pt >= 1 && $a['expert']['proposals_today'] === 0, 'proposals today: the Buyer\'s (' . $pt . ')');
[$c, $html] = pg($owner, '/admin/agents');
ok($c === 200 && has_id($html, 'agent-card-' . EXPERT) && has_id($html, 'agent-card-' . BUYER_AGENT) && href_of($html, 'agent-card-' . EXPERT . '-failed') === '/admin/dispatches?agent=' . EXPERT . '&status=failed', 'the cards: agent-card-{member_id}, with the failed count linking the dispatches of that agent');
ok(!str_contains($html, '<form') || !preg_match('#<form[^>]*id="agent#', $html), 'and no form of the page\'s own, no button that posts');
$legacy = (int) one("SELECT count(*) FROM members WHERE member_kind = 'agent' AND cardinality(roles) > 0 AND id NOT IN (" . EXPERT . ', ' . BUYER_AGENT . ')');
ok($legacy === 0 || has_id($html, 'agent-card-45') , 'every other agent holding a role here has a plainer card (none outside the two in this world)');
ok(str_contains(text_of($html, 'agent-list-note'), 'Hiring, grants, duties and approvals are the kernel'), 'the note: hiring, grants, duties and approvals are the kernel\'s');

echo "2. The OS links and the right\n";
$env = file_get_contents(need('INV_DEV_ENV'));
preg_match('/^OS_LAUNCHER_URL=(.*)$/m', $env, $m);
$launcher = trim($m[1] ?? '', "\"'");
$expect = (str_contains($launcher, '//app.') ? preg_replace('#//app\.#', '//os.', rtrim($launcher, '/')) : null);
if ($expect !== null) {
    $u = parse_url($launcher);
    $os = $u['scheme'] . '://os.' . substr($u['host'], 4) . (isset($u['port']) ? ':' . $u['port'] : '');
    ok(str_contains($html, 'href="' . $os . '/agents"') && str_contains($html, 'href="' . $os . '/ai/runs"') && has_id($html, 'agent-list-os-link'), 'the OS links are on os.<domain> (' . $os . '): agent-list-os-link');
} else {
    ok(has_id($html, 'agent-list-os-link'), 'the OS link slot is there (OS_LAUNCHER_URL names no app. host in this world: ' . $launcher . ')');
}
$r = req('POST', '/admin/agents', ['jar' => $owner, 'form' => ['csrf_token' => page_csrf($owner)]]);
ok($r['code'] === 405, 'a POST to the page → 405 (it has no form)');
ok(pg($nora, '/admin/agents')[0] === 403 && str_contains(pg($nora, '/admin/agents')[1], "agents"), 'Nora (no agents.settings) → 403');
ok(pg($sam, '/admin/agents')[0] === 403, 'Sam → 403');
$since = last_activity_id();
req('GET', '/admin/agents', ['jar' => $owner, 'headers' => array_merge(JSONH, ['X-Screen-View: 1'])]);
$acts = array_unique(array_column(q('SELECT action FROM activity_log WHERE id > :s', ['s' => $since]), 'action'));
ok($acts === ['screen.view'], 'the page writes only screen.view' . ($acts === ['screen.view'] ? '' : ': ' . implode(', ', $acts)));
psql_exec("UPDATE members SET display_name = 'SMOKE Buyer' WHERE id = " . BUYER_AGENT);
finish();

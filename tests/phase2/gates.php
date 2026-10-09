<?php
/**
 * Proof: rights per role on every screen of the manifest (200, the placeholder with its slice named, 403 in the right's words, 302 anonymous),
 * the menu per role, CSRF, My settings, the tokens screen, notifications and the bell, the trail's visibility, the command bar through the
 * kernel's chat endpoint (a reply, a refusal in the kernel's words, an approval, a navigate), the action-token / run-token gate, cost as the
 * wall, health (sso-shell.md "The shell", "Handlers", "Proof: gates"). Run after sso.php through tests/phase2/run.sh.
 */
require __DIR__ . '/lib.php';
$L = 'https://app.example.invalid/launcher?app=inventory';
$json = ['headers' => ['Accept: application/json']];

echo "1. Rights per role — every screen of the manifest answers 200 or 403 for the seven members\n";
// route => the roles that get 200; owner 1, nora 40 (buyer+user+warehouse), sam 41 (user), wes 42 (warehouse), vera 43 (viewer), ann 44 (external viewer); the expert 45 is checked apart (an agent).
$all = 'owner nora sam wes vera ann';
$write = 'owner nora sam wes';
$matrix = [
    '/' => $all, '/find' => $all, '/products/' => $all, '/brands/' => $all, '/product-types/' => $all, '/catalog/gaps' => 'owner nora', '/catalog/import' => 'owner nora',
    '/stock/' => $all, '/locations/' => $all, '/stock/movements' => $all, '/receipts/' => 'owner nora wes', '/adjustments/' => 'owner nora wes', '/transfers/' => 'owner nora wes',
    '/counts/' => 'owner nora wes', '/stock/floor-models' => $all,
    '/sources/' => $all, '/sources/templates' => 'owner nora', '/matching/' => 'owner nora', '/supplier-items/' => $all, '/watches/' => $write,
    '/orders/' => $all, '/orders/today' => $all, '/shipments/' => $all, '/customers/' => $all, '/purchasing/' => $all, '/suppliers/' => $all,
    '/returns/' => $all, '/proposals/' => 'owner nora', '/reports/' => 'owner nora', '/exports/' => 'owner nora',
    '/settings/' => $all, '/notifications' => $all, '/settings/tokens/' => $all, '/trail' => $all,
    '/admin/settings' => 'owner', '/admin/sequences' => 'owner', '/admin/tax-rates/' => 'owner', '/admin/reason-codes/' => 'owner', '/admin/feed-keys/' => 'owner',
    '/admin/price-lists/' => 'owner', '/admin/agents' => 'owner', '/admin/dispatches' => 'owner', '/admin/connections' => 'owner',
];
$jars = [];
foreach (['owner' => 1, 'nora' => 40, 'sam' => 41, 'wes' => 42, 'vera' => 43, 'ann' => 44] as $who => $id) { [$jars[$who], ] = sign_on($id); }
$wrong = [];
$n = 0;
foreach ($matrix as $route => $allowed) {
    foreach ($jars as $role => $jarf) {
        $want = in_array($role, explode(' ', $allowed), true) ? 200 : 403;
        $code = page($jarf, $route)['code'];
        $n++;
        if ($code !== $want) { $wrong[] = "$role $route wanted $want got $code"; }
    }
}
ok($wrong === [], "$n role × screen checks (" . count($matrix) . ' screens × 6 people) answer 200 or 403 as the rights say' . ($wrong ? ': ' . implode('; ', array_slice($wrong, 0, 8)) : ''));
ok(str_contains(page($jars['vera'], '/receipts/')['body'], 'You may not receive goods.'), 'a refusal says what the person may not do, in words ("You may not receive goods.")');
ok(str_contains(page($jars['vera'], '/matching/')['body'], 'You may not match listings.') && str_contains(page($jars['sam'], '/counts/')['body'], 'You may not count stock.') && str_contains(page($jars['vera'], '/admin/settings')['body'], 'You may not change the settings.'), 'Vera on the match queue, Sam on counts, Vera on the admin settings: each in that right\'s sentence');
$stubs = trim((string) shell_exec('grep -rl "render_nav_stub(" ' . escapeshellarg(dirname(__DIR__, 2) . '/html') . ' 2>/dev/null | wc -l'));
ok((int) $stubs === 21, "the placeholders stand: $stubs controllers name their slice (38 at Phase 2, five built by slice 1, eight by slice 2, four by slice 3)");
ok(str_contains(page($jars['sam'], '/orders/')['body'], 'slice 5 builds this screen') && str_contains(page($jars['nora'], '/watches/')['body'], 'slice 4 builds this screen') && str_contains(page($jars['owner'], '/admin/connections')['body'], 'slice 7 builds this screen') && str_contains(page($jars['owner'], '/admin/dispatches')['body'], 'slice 8 builds this screen'), 'each placeholder names its slice (orders 5, watches 4, connections 7, dispatches 8)');
ok(str_contains(page($jars['sam'], '/orders/')['body'], 'id="order-list-coming"') && str_contains(page($jars['sam'], '/orders/')['body'], 'data-screen="order-list"'), 'a placeholder renders inside the shell with its screen id stamped');
ok(req('POST', '/orders/', ['jar' => $jars['sam'], 'form' => ['csrf_token' => page_csrf($jars['sam'])]])['code'] === 501, 'a POST to a placeholder: 501');
$r = req('GET', '/orders/', ['jar' => $jars['sam']] + $json);
ok($r['code'] === 501 && (json_decode($r['body'], true)['error']['code'] ?? '') === 'not_built', 'JSON to a placeholder: 501 not_built');
$menu = fn (string $j): array => (preg_match_all('/id="nav-((?!group-)[a-z-]+)"/', page($j, '/')['body'], $m) ? $m[1] : []);
$groups = fn (string $j): array => (preg_match_all('/nxl-caption" id="nav-group-[a-z]+"><label>([^<]+)</', page($j, '/')['body'], $m) ? $m[1] : []);
ok(count($menu($jars['vera'])) === 22 && !in_array('receipt-list', $menu($jars['vera']), true) && !in_array('watch-list', $menu($jars['vera']), true) && $groups($jars['vera']) === ['Inventory', 'Catalog', 'Stock', 'Sources', 'Orders', 'Purchasing', 'Returns', 'Me'], 'Vera sees 22 items (every inventory.read item) and no Admin or Reports group: ' . implode(', ', $groups($jars['vera'])));
ok(count($menu($jars['nora'])) === 34 && $groups($jars['nora']) === ['Inventory', 'Catalog', 'Stock', 'Sources', 'Orders', 'Purchasing', 'Returns', 'Reports', 'Me'], 'Nora sees every non-admin item (34) and no Admin group: ' . implode(', ', $groups($jars['nora'])));
ok(count($menu($jars['owner'])) === 43 && array_slice($groups($jars['owner']), -1)[0] === 'Admin', 'the owner sees everything (43 items) with the Admin group last');
ok(in_array('receipt-list', $menu($jars['wes']), true) && !in_array('match-queue', $menu($jars['wes']), true) && !in_array('watch-list', $menu($jars['vera']), true) && in_array('watch-list', $menu($jars['wes']), true), 'Wes sees Receive and Watches, not the Match queue; Vera no Watches');
$r = page(jar(), '/products/');
ok($r['code'] === 302 && $r['location'] === $L, 'no session: a screen goes to the launcher with ?app=inventory');
$r = req('GET', '/products/', $json);
ok($r['code'] === 401 && (json_decode($r['body'], true)['error']['code'] ?? '') === 'unauthorized', 'no session, JSON: 401 unauthorized');
$r = req('GET', '/', ['jar' => $jars['nora']] + $json);
$d = json_decode($r['body'], true)['data'] ?? [];
ok($r['code'] === 200 && $d['may']['sales'] === true && $d['may']['warehouse'] === true && $d['may']['cost'] === true && $d['may']['admin'] === false && array_key_exists('note', $d) && array_key_exists('at_risk', $d) && array_key_exists('today', $d) && is_array($d['bell']), 'the home answers JSON: the shape (may, note, at_risk, today, bell …)');
$h = page($jars['sam'], '/')['body'];
ok(str_contains($h, 'id="home-note"') && str_contains($h, 'id="home-at-risk"') && str_contains($h, 'id="home-my-orders"') && !str_contains($h, 'id="home-warehouse"') && !str_contains($h, 'id="home-unmatched"') && !str_contains($h, 'id="home-admin"'), 'Sam\'s home: the note, at risk, my orders; no warehouse block, no unmatched, nothing for the admin');
ok(str_contains(page($jars['wes'], '/')['body'], 'id="home-warehouse"') && !str_contains(page($jars['wes'], '/')['body'], 'id="home-my-orders"') && str_contains(page($jars['owner'], '/')['body'], 'id="home-admin"') && str_contains(page($jars['nora'], '/')['body'], 'id="home-unmatched"'), 'Wes\'s shows the warehouse block and not my orders; the owner\'s the admin card; Nora\'s the unmatched listings');
ok(str_contains(page($jars['vera'], '/')['body'], 'id="home-bell-empty"') && str_contains(page($jars['vera'], '/')['body'], 'slice 5'), 'a Viewer\'s home: the bell\'s card and the regions naming their slices');

echo "2. My settings — how I am told, who I am here, my time zone\n";
$t = csrf_of(page($jars['sam'], '/')['body']);
$d = json_decode(page($jars['sam'], '/settings/', $json)['body'], true)['data'];
ok($d['notify']['email_enabled'] === true && $d['notify']['text_enabled'] === false && $d['notify']['saved'] === false && in_array('watch', $d['notify']['kinds'], true) && in_array('morning_note', $d['notify']['kinds'], true) && $d['notify']['text_kinds'] === ['watch', 'line_at_risk'], 'the defaults before any save: email on, text off, the ten default kinds, texts for watch and line_at_risk');
ok($d['timezone'] === 'UTC' && $d['badge'] === 'Sales' && array_column($d['roles'], 'role_key') === ['user'] && $d['sees_cost'] === false && count($d['kinds']) === 13, 'the time zone is the directory\'s (UTC); the badge Sales, the one role, cost withheld; the thirteen kinds');
$r = req('POST', '/settings/prefs.php', ['jar' => $jars['sam'], 'form' => ['email_enabled' => 'yes', 'text_enabled' => 'yes', 'kinds' => ['watch', 'order'], 'text_kinds' => ['watch']]]);
ok($r['code'] === 403, 'saving without the CSRF token: 403');
$since = last_activity_id();
$r = req('POST', '/settings/prefs.php', ['jar' => $jars['sam'], 'form' => ['email_enabled' => 'yes', 'text_enabled' => 'yes', 'kinds' => ['watch', 'order'], 'text_kinds' => ['watch'], 'csrf_token' => $t, 'return_to' => '/settings/']]);
ok($r['code'] === 302 && str_starts_with($r['location'], '/settings/?notice=prefs_saved'), 'with it: 302 back to the settings with the notice');
$row = q('SELECT email_enabled, text_enabled, kinds, text_kinds FROM notification_prefs WHERE member_id = 41')[0] ?? null;
ok($row && $row['text_enabled'] && $row['kinds'] === '{watch,order}' && $row['text_kinds'] === '{watch}', 'the row holds the choices (text on, two kinds, one text kind)');
$log = activity('prefs.save', $since);
ok(count($log) === 1 && str_contains((string) $log[0]['after'], 'text_enabled') && str_contains((string) $log[0]['before'], 'kinds'), 'prefs.save logs before and after of the changed keys');
$r = req('POST', '/settings/prefs.php', ['jar' => $jars['sam'], 'form' => ['kinds' => ['watch', 'nonsense'], 'csrf_token' => $t]]);
ok($r['code'] === 422 && str_contains($r['body'], 'Unknown event: nonsense'), 'an unknown event kind: 422 in words');
$r = req('POST', '/settings/prefs.php', ['jar' => $jars['sam'], 'form' => ['text_enabled' => 'maybe', 'csrf_token' => $t]]);
ok($r['code'] === 422, 'a yes/no field that is neither: 422');
$r = req('POST', '/settings/prefs.php', ['jar' => $jars['sam'], 'form' => ['timezone' => 'Europe/Paris', 'csrf_token' => $t]]);
ok($r['code'] === 422 && str_contains($r['body'], 'directory'), 'the time zone is refused in words (it is the directory\'s)');
$r = req('POST', '/settings/prefs.php', ['jar' => $jars['sam'], 'form' => ['text_enabled' => 'no', 'csrf_token' => $t]]);
ok($r['code'] === 302 && q('SELECT text_enabled, kinds, text_kinds FROM notification_prefs WHERE member_id = 41')[0] === ['text_enabled' => false, 'kinds' => '{watch,order}', 'text_kinds' => '{watch}'], 'a field left out stays as it was (text off, the kinds kept)');
$r = req('POST', '/settings/prefs.php', ['jar' => $jars['sam'], 'form' => ['kinds' => [''], 'csrf_token' => $t]]);
ok($r['code'] === 302 && q('SELECT kinds FROM notification_prefs WHERE member_id = 41')[0]['kinds'] === '{}', 'every kind unticked (the form\'s empty sentinel) persists as none');
$h = page($jars['sam'], '/settings/')['body'];
ok(str_contains($h, 'id="prefs-text-hint"') && str_contains($h, 'id="settings-timezone-value"') && str_contains($h, 'id="settings-role-badge">Sales<') && str_contains($h, 'id="settings-cost-note"') && str_contains($h, 'withheld'), 'the settings screen shows where texts go, the time zone read-only, the badge and that cost is withheld');
ok(str_contains(page($jars['nora'], '/settings/')['body'], 'You see cost and margin'), 'Nora\'s says she sees cost');
$r = req('POST', '/settings/prefs.php', ['jar' => $jars['sam'], 'headers' => ['HX-Request: true'], 'form' => ['email_enabled' => 'yes', 'csrf_token' => $t]]);
ok($r['code'] === 200 && str_contains($r['headers'], 'HX-Location') && str_contains($r['headers'], 'prefsChanged'), 'under HTMX: HX-Location to the settings and the prefsChanged trigger');

echo "3. CSRF and the tokens screen\n";
$r = req('POST', '/settings/tokens/mint.php', ['jar' => $jars['sam'], 'form' => ['label' => 'proof']]);
ok($r['code'] === 403, 'minting a token without the CSRF token: 403');
$before = (int) one('SELECT count(*) FROM mcp_access_tokens WHERE member_id = 41');
$r = req('POST', '/settings/tokens/mint.php', ['jar' => $jars['sam'], 'form' => ['label' => 'proof', 'csrf_token' => $t]]);
ok($r['code'] === 302 && $r['location'] === '/settings/tokens/', 'with it: 302 to the tokens screen');
$r = page($jars['sam'], '/settings/tokens/');
preg_match('/id="token-minted-value">(mcp_[0-9a-f]{48})</', $r['body'], $m);
$raw = $m[1] ?? '';
ok($raw !== '' && str_contains($r['body'], 'id="token-minted"') && !str_contains(page($jars['sam'], '/settings/tokens/')['body'], $raw), 'the new token is shown once (mcp_ + 48 hex, in a warning box) and never again');
$row = q('SELECT id, token_hash, label, scope FROM mcp_access_tokens WHERE member_id = 41 ORDER BY id DESC LIMIT 1')[0];
ok($row['token_hash'] === hash('sha256', $raw) && $row['token_hash'] !== $raw && $row['scope'] === 'mcp' && (int) one('SELECT count(*) FROM mcp_access_tokens WHERE member_id = 41') === $before + 1, 'only its SHA-256 is stored; scope mcp');
$logged = q("SELECT after::text AS a, token_id FROM activity_log WHERE action = 'token.mint' ORDER BY id DESC LIMIT 1")[0];
ok(!str_contains($logged['a'], $raw) && str_contains($logged['a'], 'proof') && (int) $logged['token_id'] === (int) $row['id'], 'token.mint logs the label and the token_id key, never the token');
$mine = json_decode(page($jars['sam'], '/settings/tokens/', $json)['body'], true)['data']['tokens'];
ok($mine !== [] && !isset($mine[0]['token_hash']) && !str_contains(json_encode($mine), $raw) && isset($mine[0]['token_id']) && $mine[0]['state'] === 'live', 'the tokens screen\'s JSON lists tokens without the value or the hash, with their state');
pdo()->exec("SELECT set_config('app.member_id', '41', false)");
$resolved = q("SELECT * FROM mcp_resolve_token(:h, 'mcp')", ['h' => hash('sha256', $raw)]);
ok(count($resolved) === 1 && (int) $resolved[0]['member_id'] === 41 && $resolved[0]['member_kind'] === 'human', 'mcp_resolve_token() resolves the minted token to Sam (what the records server will do in Phase 4)');
$r = req('POST', '/settings/tokens/mint.php', ['jar' => $jars['sam'], 'form' => ['label' => 'x', 'scope' => 'other', 'csrf_token' => $t]]);
ok($r['code'] === 422, 'a scope that is neither mcp nor api: 422');
$r = req('POST', '/settings/tokens/mint.php', ['jar' => $jars['sam'], 'form' => ['label' => str_repeat('x', 81), 'csrf_token' => $t]]);
ok($r['code'] === 422, 'a label over 80 characters: 422');
$id = (int) $row['id'];
$r = req('POST', '/settings/tokens/revoke.php', ['jar' => $jars['nora'], 'form' => ['token' => $id, 'csrf_token' => csrf_of(page($jars['nora'], '/')['body'])]]);
ok($r['code'] === 404 && one('SELECT revoked_at FROM mcp_access_tokens WHERE id = :i', ['i' => $id]) === null && str_contains($r['body'], 'Token not found.'), 'another person cannot revoke it: 404 "Token not found.", still live');
$r = req('POST', '/settings/tokens/revoke.php', ['jar' => $jars['sam'], 'form' => ['token' => $id, 'csrf_token' => $t]]);
ok($r['code'] === 302 && one('SELECT revoked_at FROM mcp_access_tokens WHERE id = :i', ['i' => $id]) !== null, 'the owner revokes it: 302, revoked_at set');
ok(count(q("SELECT 1 FROM activity_log WHERE action = 'token.revoke' AND entity_id = :i", ['i' => $id])) === 1, 'token.revoke is logged');
ok(q("SELECT * FROM mcp_resolve_token(:h, 'mcp')", ['h' => hash('sha256', $raw)]) === [], 'and the revoked token no longer resolves');
ok(str_contains(page($jars['sam'], '/settings/tokens/')['body'], '>revoked<'), 'the screen marks it revoked');
$r = req('POST', '/settings/tokens/revoke.php', ['jar' => $jars['sam'], 'form' => ['token' => $id, 'csrf_token' => $t]]);
ok($r['code'] === 404, 'revoking it again: 404');

echo "4. Notifications and the bell\n";
ok(str_contains(page($jars['sam'], '/notifications')['body'], 'id="notifications-empty"') && !str_contains(page($jars['sam'], '/')['body'], 'id="bell-count"'), 'the empty shape ("Nothing yet"); no count on the bell');
pdo()->exec("INSERT INTO notifications (member_id, kind, record_type, record_id, title, body) VALUES (41, 'watch', 'watch', 7, 'SMOKE Back in stock: Dreamer Queen', 'at Layla'), (41, 'order', 'sales_order', 3, 'SMOKE Order 1003 shipped', null), (40, 'po_ack', 'purchase_order', 5, 'SMOKE for Nora', null)");
$h = page($jars['sam'], '/')['body'];
ok(preg_match('/id="bell-count">2</', $h) === 1, 'two unread: the bell shows 2');
ok(str_contains($h, 'id="home-bell-') && str_contains($h, 'SMOKE Back in stock') && !str_contains($h, 'SMOKE for Nora'), 'the home\'s Unread card lists his two, never Nora\'s');
$d = json_decode(page($jars['sam'], '/notifications', $json)['body'], true)['data'];
ok(count($d['notifications']) === 2 && $d['notifications'][0]['read'] === false && $d['unread'] === 2 && !str_contains(json_encode($d), 'for Nora') && $d['notifications'][1]['url'] === '/orders/3', 'the screen lists his two with their records (an order links to /orders/3), never Nora\'s');
$h = page($jars['sam'], '/notifications')['body'];
ok(str_contains($h, 'href="/watches/7?back=') && str_contains($h, 'href="/orders/3?back=') && preg_match('/badge bg-soft-primary[^>]*>watch</', $h) === 1 && preg_match('/badge bg-soft-info[^>]*>order</', $h) === 1, 'a notification links to its record with the way back; the kind chips wear their colours (watch primary, order info)');
$nid = (int) one("SELECT id FROM notifications WHERE member_id = 41 AND kind = 'watch'");
$since = last_activity_id();
$r = req('POST', '/settings/notifications/read.php', ['jar' => $jars['sam'], 'form' => ['notification' => $nid, 'csrf_token' => $t]]);
ok($r['code'] === 302 && $r['location'] === '/notifications' && one('SELECT read_at FROM notifications WHERE id = :i', ['i' => $nid]) !== null && preg_match('/id="bell-count">1</', page($jars['sam'], '/')['body']) === 1, 'marking one read: 302, read_at set, the bell shows 1');
$mid = (int) one("SELECT id FROM notifications WHERE member_id = 40");
$r = req('POST', '/settings/notifications/read.php', ['jar' => $jars['sam'], 'form' => ['notification' => $mid, 'csrf_token' => $t]]);
ok($r['code'] === 302 && one('SELECT read_at FROM notifications WHERE id = :i', ['i' => $mid]) === null, 'marking Nora\'s: nothing changes (own rows only)');
$r = req('POST', '/settings/notifications/read.php', ['jar' => $jars['sam'], 'form' => ['csrf_token' => $t]]);
ok($r['code'] === 302 && (int) one('SELECT count(*) FROM notifications WHERE member_id = 41 AND read_at IS NULL') === 0 && count(activity('notification.read', $since)) === 3, 'no id marks all of his read; notification.read logged each time with the count');
ok(count(json_decode(page($jars['sam'], '/notifications?unread=1', $json)['body'], true)['data']['notifications']) === 0, '?unread=1 now lists none');
$r = page($jars['sam'], '/notifications?count=1', ['headers' => ['HX-Request: true']]);
ok($r['code'] === 200 && str_starts_with(trim($r['body']), '<span id="bell-count-wrap"') && !str_contains($r['body'], '<html'), 'the bell re-fetches its count alone (Pattern A)');

echo "5. The trail — the caller's own rows, or a record's by its key\n";
$rows = json_decode(page($jars['sam'], '/trail', $json)['body'], true)['data']['rows'] ?? [];
ok($rows !== [] && count(array_unique(array_map(fn ($x) => $x['actor']['member_id'], $rows))) === 1 && $rows[0]['actor']['member_id'] === 41, 'Sam sees only his own rows (' . count($rows) . ', all by member 41)');
ok(str_contains(json_encode($rows), 'changed how they are told') && str_contains(json_encode($rows), 'made an access token') && str_contains(json_encode($rows), 'opened notifications'), 'each row has its sentence (prefs, a token, a screen opened)');
$rows = json_decode(page($jars['owner'], '/trail?action=member.', $json)['body'], true)['data']['rows'] ?? [];
ok($rows !== [] && count(array_unique(array_map(fn ($x) => $x['actor']['member_id'], $rows))) === 1 && $rows[0]['actor']['member_id'] === 1, 'the trail is the member\'s OWN by default — even the super-admin\'s shows only their rows here');
$r = page($jars['sam'], '/trail');
ok($r['code'] === 200 && str_contains($r['body'], 'id="trail-list"') && preg_match('/id="trail-row-\d+"/', $r['body']) === 1, 'the screen renders (Pattern B table, trail-row-{id})');
$r = page($jars['sam'], '/trail?period=7', ['headers' => ['HX-Request: true', 'HX-Target: trail-results']]);
ok($r['code'] === 200 && str_starts_with(trim($r['body']), '<div id="trail-results"') && !str_contains($r['body'], '<html'), 'the filter swaps only #trail-results');
pdo()->exec("INSERT INTO activity_log (actor_member_id, source, action, entity_type, entity_id, source_id, after) VALUES (40, 'web', 'source.update', 'source', 9, 9, '{\"name\": \"SMOKE Layla\"}'), (NULL, 'cron', 'pull.run', 'source_pull', 1, 9, '{\"listings\": 3}')");
$rows = json_decode(page($jars['sam'], '/trail?source=9', $json)['body'], true)['data']['rows'] ?? [];
ok(count($rows) === 2 && $rows[0]['source_id'] === 9 && $rows[0]['url'] === '/sources/9' && str_contains(json_encode($rows), 'the worker pull run'), '?source= answers that source\'s rows by the audit key (a person\'s and the worker\'s), each linking to the source');
pdo()->exec("INSERT INTO activity_log (actor_member_id, source, action, entity_type, entity_id, sales_order_id, after) VALUES (41, 'web', 'order.confirm', 'sales_order', 3, 3, '{\"number\": \"SO-1003\"}'), (40, 'web', 'product.update', 'product', 12, NULL, '{\"name\": \"Dreamer\"}')");
ok(count(json_decode(page($jars['vera'], '/trail?order=3', $json)['body'], true)['data']['rows']) === 1 && count(json_decode(page($jars['vera'], '/trail?product=12', $json)['body'], true)['data']['rows']) === 1, '?order= and ?product= answer their rows to any reader (the view admits records anyone here may see)');
ok(page($jars['sam'], '/trail?member=40')['code'] === 403 && page($jars['owner'], '/trail?member=40')['code'] === 200 && page($jars['nora'], '/trail?member=41')['code'] === 200, 'another person\'s trail: 403 for Sam, 200 for the admin and for Nora (reports.read)');
ok(page($jars['sam'], '/trail?member=45')['code'] === 200, 'an agent\'s trail opens to any reader');
ok((json_decode(page($jars['sam'], '/trail?action=token.', $json)['body'], true)['data']['rows'] ?? []) !== [] && (json_decode(page($jars['sam'], '/trail?action=nonsense%20x', $json)['body'], true)['data']['filters']['action'] ?? 'x') === 'x', 'the action prefix filters; a malformed one is dropped');
ok(json_decode(page($jars['sam'], '/trail', $json)['body'], true)['data']['rows'][0]['sentence'] !== '' && !str_contains(json_encode(json_decode(page($jars['vera'], '/trail?member=1', $json)['body'] ?? '')), 'mcp_'), 'no token value anywhere in a trail');

echo "6. The command bar goes through the kernel's chat endpoint\n";
$r = req('POST', '/assistant/ask.php', ['jar' => $jars['sam'], 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'What is in stock in queen?', 'screen' => 'home', 'csrf_token' => $t]]);
ok($r['code'] === 200 && str_contains($r['body'], 'Hello from the fake expert'), 'the utterance is answered with the kernel\'s reply');
$a = activity('assistant.ask', 0);
$la = json_decode((string) end($a)['after'], true);
ok($a !== [] && ($la['length'] ?? 0) === 26 && ($la['run_id'] ?? 0) === 1 && !str_contains((string) end($a)['after'], 'queen'), 'assistant.ask logs the length and the run id, never the text');
$chatLines = array_values(array_filter(explode("\n", (string) file_get_contents(need('FAKE_KERNEL_STATE') . '.chat'))));
$chatLog = json_decode((string) end($chatLines), true);
ok(($chatLog['agent'] ?? '') === 'expert' && (int) ($chatLog['acting'] ?? 0) === 41, 'the kernel saw agent=expert and X-Acting-Member 41');
$r = req('POST', '/assistant/ask.php', ['jar' => $jars['sam'], 'form' => ['utterance' => 'hi']]);
ok($r['code'] === 403, 'without the CSRF token: 403');
kernel_state(function ($s) { $s['chat_status'] = 404; return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $jars['sam'], 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'hi', 'csrf_token' => $t]]);
ok($r['code'] === 404 && str_contains($r['body'], 'No expert for this application.'), 'the kernel answers 404: the bar shows the kernel\'s words');
kernel_state(function ($s) { $s['chat_status'] = 409; $s['chat_message'] = 'The expert is busy with another turn.'; return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $jars['sam'], 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'hi', 'csrf_token' => $t]]);
ok($r['code'] === 409 && str_contains($r['body'], 'busy with another turn'), 'a 409: shown in the kernel\'s words');
kernel_state(function ($s) { unset($s['chat_status'], $s['chat_message']); $s['chat'] = ['run_id' => 2, 'status' => 'awaiting_approval', 'finished' => true, 'reply' => 'I drafted the purchase order; sending it waits for approval.',
    'actions' => [['tool' => 'po_send', 'status' => 'awaiting_approval', 'record_id' => 5], ['tool' => 'po_draft', 'status' => 'ok', 'record_id' => 5]], 'approval_request_id' => 77]; return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $jars['sam'], 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'order it', 'csrf_token' => $t]]);
ok($r['code'] === 200 && str_contains($r['body'], 'waits for approval') && str_contains($r['body'], 'request #77') && str_contains($r['body'], 'po_draft') && str_contains($r['headers'], 'HX-Trigger: purchase_orderChanged'), 'a paused action is shown as "waits for approval"; the succeeded one fires purchase_orderChanged');
kernel_state(function ($s) { $s['chat'] = ['run_id' => 3, 'status' => 'succeeded', 'finished' => true, 'reply' => 'Opening the products.', 'actions' => [], 'navigate' => '/products/']; return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $jars['sam'], 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'open the products', 'csrf_token' => $t]]);
ok($r['code'] === 200 && str_contains($r['headers'], 'HX-Location') && str_contains($r['headers'], '/products/') && str_contains($r['body'], 'id="assistant-reply-navigate"'), 'a navigate answer is followed (HX-Location to /products/)');
kernel_state(function ($s) { $s['chat'] = ['run_id' => 4, 'status' => 'succeeded', 'finished' => true, 'reply' => 'x', 'actions' => [], 'navigate' => 'https://evil.invalid/x']; return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $jars['sam'], 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'go', 'csrf_token' => $t]]);
ok($r['code'] === 200 && !str_contains($r['headers'], 'HX-Location'), 'a navigate that is not a local path is ignored');
kernel_state(function ($s) { unset($s['chat']); return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $jars['sam'], 'headers' => ['HX-Request: true'], 'form' => ['utterance' => str_repeat('x', 2100), 'csrf_token' => $t]]);
ok($r['code'] === 422, 'an utterance over 2,000 characters: 422');
$r = req('POST', '/assistant/ask.php', ['jar' => $jars['sam'], 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'hi', 'csrf_token' => $t]]);
ok($r['code'] === 200, 'fake kernel restored');

echo "7. Action tokens and run tokens — the gate for the actions server and the MCP servers; an agent stays off the person-only screens\n";
$r = req('GET', '/trail', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . person_token(41)]]);
$rows = json_decode($r['body'], true)['data']['rows'] ?? [];
ok($r['code'] === 200 && $rows !== [] && $rows[0]['actor']['member_id'] === 41 && !str_contains($r['headers'], 'Set-Cookie'), 'a person\'s action token acts as that member for one request (200, his rows, no Set-Cookie)');
ok(req('GET', '/trail', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . person_token(41, -5)]])['code'] === 401, 'an expired action token: 401');
$r = req('GET', '/trail', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . (function (string $t): string { return substr($t, 0, -1) . ($t[-1] === '0' ? '1' : '0'); })(person_token(41))]]);
ok($r['code'] === 401, 'a tampered action token: 401');
$r = req('POST', '/settings/tokens/mint.php', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . person_token(41)], 'form' => ['label' => 'by action token']]);
$d = json_decode($r['body'], true);
ok($r['code'] === 200 && str_starts_with($d['token'] ?? '', 'mcp_') && ($d['record_id'] ?? 0) > 0 && str_ends_with((string) $d['location'], '#token-row-' . $d['record_id']), 'a write under the action token needs no CSRF token: JSON carries the token once, record_id and a location ending in the id');
$r = req('POST', '/settings/prefs.php', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . person_token(41)], 'form' => ['text_enabled' => 'yes']]);
$d = json_decode($r['body'], true);
ok($r['code'] === 200 && $d['ok'] === true && $d['did'] === 'Saved how you are told' && one('SELECT text_enabled FROM notification_prefs WHERE member_id = 41'), 'prefs_save under the action token answers from emit_action_status (ok, did)');
$r = req('POST', '/settings/notifications/read.php', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . person_token(41)], 'form' => []]);
$d = json_decode($r['body'], true);
ok($r['code'] === 200 && $d['ok'] === true && isset($d['count']) && $d['refresh'] === 'notificationChanged', 'notification_read under the action token: ok, the count, the refresh event');
// the expert (45): a run token with the relay acts as it; the person-only screens refuse it
$tok = run_token(45, 77);
ok(req('GET', '/trail', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $tok]])['code'] === 401, 'a run token WITHOUT the relay: 401');
ok(req('GET', '/trail', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $tok, 'X-Action-Relay: ' . str_repeat('0', 64)]])['code'] === 401, 'a run token with a wrong relay: 401');
kernel_state(function ($s) { $s['facts']['77'] = ['valid' => true, 'is_agent' => true, 'member_id' => 45, 'run_id' => 77, 'request_id' => 'req-run-77', 'trigger' => 'chat', 'endpoints' => [['name' => 'Records MCP']]]; return $s; });
$since = last_activity_id();
$r = req('GET', '/trail', ['headers' => array_merge(as_agent($tok), ['X-Screen-View: 1'])]);
ok($r['code'] === 200, 'the expert with the relay: 200 (its own trail)');
$row = q("SELECT source, agent_run_id, request_id FROM activity_log WHERE action = 'screen.view' AND actor_member_id = 45 AND id > :s ORDER BY id DESC LIMIT 1", ['s' => $since])[0] ?? [];
ok(($row['source'] ?? '') === 'agent' && (int) ($row['agent_run_id'] ?? 0) === 77 && ($row['request_id'] ?? '') === 'req-run-77', 'its rows are source agent, run 77, with the run\'s own request id from the kernel');
ok(req('GET', '/', ['headers' => as_agent($tok)])['code'] === 403 && req('POST', '/settings/tokens/mint.php', ['headers' => as_agent($tok), 'form' => ['label' => 'agent']])['code'] === 403 && req('POST', '/assistant/ask.php', ['headers' => as_agent($tok), 'form' => ['utterance' => 'hi']])['code'] === 403, 'an agent may not open the home, mint a person\'s token or use the command bar: 403');
$r = req('POST', '/settings/prefs.php', ['headers' => as_agent($tok), 'form' => ['text_enabled' => 'no']]);
ok($r['code'] === 200 && one('SELECT text_enabled FROM notification_prefs WHERE member_id = 45') === false, 'but an agent may save its own notification choices (prefs_save is "own")');
ok(req('GET', '/orders/', ['headers' => as_agent($tok)])['code'] === 501 && req('POST', '/orders/', ['headers' => as_agent($tok), 'form' => []])['code'] === 501, 'an agent on a placeholder: 501 not_built (GET and POST)');
// an agent the feed introduces with no grant: admitted at first contact only when the kernel vouches
kernel_state(function ($s) { $s['incremental'] = incr(['members' => [['id' => 900, 'member_kind' => 'agent', 'display_name' => 'SMOKE Stock Buyer', 'email' => null, 'business_role' => 'user', 'is_external' => false, 'status' => 'active', 'updated_at' => '2026-01-01T00:00:00Z', 'departments' => []]]], '2026-01-01T00:03:00.000000Z'); return $s; });
sync();
ok(q('SELECT member_kind, capability FROM members WHERE id = 900')[0] === ['member_kind' => 'agent', 'capability' => null], 'the feed introduced an agent (member 900) with no grant yet');
$tok2 = run_token(900, 78);
$r = req('GET', '/trail', ['headers' => as_agent($tok2)]);
ok($r['code'] === 401 && q('SELECT capability FROM members WHERE id = 900')[0]['capability'] === null, 'a run token with the relay but a run the kernel does not vouch for: 401, no admission recorded');
kernel_state(function ($s) { $s['facts']['78'] = ['valid' => true, 'is_agent' => true, 'member_id' => 900, 'run_id' => 78, 'request_id' => 'req-run-78', 'trigger' => 'chat', 'endpoints' => [['name' => 'Records MCP']]]; return $s; });
$r = req('GET', '/trail', ['headers' => as_agent($tok2)]);
ok($r['code'] === 200 && q('SELECT capability FROM members WHERE id = 900')[0]['capability'] === 'write', 'once the kernel vouches (valid, an agent, this application\'s endpoints): 200 and the admission is recorded');
kernel_state(function ($s) { $s['facts']['79'] = ['valid' => false]; return $s; });
ok(req('GET', '/trail', ['headers' => as_agent(run_token(900, 79))])['code'] === 200, 'an admitted agent on a later run needs no vouching again (the mirror remembers)');
kernel_state(function ($s) { unset($s['incremental'], $s['facts']); return $s; });

echo "8. Cost is the wall\n";
ok(right_of(41, 'cost.read') === false && right_of(40, 'cost.read') === true && right_of(42, 'cost.read') === false, 'inv_has_right(cost.read): Nora yes, Sam and Wes no');
pdo()->exec("SELECT set_config('app.member_id', '41', false)");
ok(one('SELECT inv_sees_cost()') === false, 'Sam does not see cost (sales_sees_cost off)');
pdo()->exec("UPDATE inv_settings SET sales_sees_cost = true WHERE id = 1");
ok(one('SELECT inv_sees_cost()') === true && json_decode(page($jars['sam'], '/settings/', $json)['body'], true)['data']['sees_cost'] === true, 'with the setting on, Sales sees cost — the shell reads the same function');
pdo()->exec("UPDATE inv_settings SET sales_sees_cost = false WHERE id = 1");
pdo()->exec("SELECT set_config('app.member_id', '42', false)");
ok(one('SELECT inv_sees_cost()') === false && one('SELECT inv_sees_receipt_cost()') === true, 'Wes sees cost on receipts only');
ok(str_contains(page($jars['sam'], '/')['body'], 'data-sees-cost="0"') && str_contains(page($jars['nora'], '/')['body'], 'data-sees-cost="1"'), 'the shell stamps data-sees-cost on the body (0 for Sam, 1 for Nora)');

echo "9. Health\n";
$r = req('GET', '/api/v1/health');
$h = json_decode($r['body'], true);
ok($r['code'] === 200 && $h['ok'] === true && $h['application'] === 'inventory' && $h['database'] === 'ok', 'GET /api/v1/health: ok, application inventory, database ok');
ok(array_key_exists('ingest_lag', $h) && is_array($h['directory']) && $h['directory']['synced'] === true && $h['directory']['error'] === null, 'it carries ingest_lag and the directory sync state (synced, no error)');
ok(req('POST', '/api/v1/health')['code'] === 405, 'POST to health: 405');
finish();

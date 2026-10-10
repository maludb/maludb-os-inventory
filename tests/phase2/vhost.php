<?php
/*
 * Proof: the deploy vhost's URL rules (deploy/apache-inventory.conf). Meaningful under INV_APP=apache tests/phase2/run.sh, where a real
 * Apache serves the template rendered as the installer renders it (and the internal vhost on 8607 answers the same); under php -S the
 * same checks run against tests/dev_router.php, which mirrors the rewrites. Nothing outside html/ is reachable, /api is blocked but for
 * health and the feed, the MCP proxies answer 502 until Phase 4 (under Apache), the two doors and the feed have no handler yet (404),
 * the attachment door needs a session (401).
 */
require __DIR__ . '/lib.php';
[$j, ] = sign_on(40);
echo "URL rules\n";
foreach (['/sso' => 403, '/sso/logout' => 405] as $path => $want) {
    ok(req('GET', $path)['code'] === $want, "GET $path → $want (the receivers answer at their extension-less names)");
}
ok(page($j, '/settings/tokens/')['code'] === 200 && page($j, '/settings/tokens')['code'] === 200, '/settings/tokens/ and /settings/tokens both reach settings/tokens/index.php');
ok(page($j, '/orders/')['code'] === 200 && page($j, '/orders')['code'] === 200 && page($j, '/products')['code'] === 200, '/orders/ and /orders reach orders/index.php; /products the real list');
ok(page($j, '/stock/movements')['code'] === 200 && page($j, '/orders/today')['code'] === 200 && page($j, '/sources/templates')['code'] === 200 && page($j, '/catalog/gaps')['code'] === 200, '/stock/movements, /orders/today, /sources/templates and /catalog/gaps resolve to their own files');
ok(page($j, '/trail')['code'] === 200 && page($j, '/find')['code'] === 200 && page($j, '/notifications')['code'] === 200, '/trail, /notifications and /find resolve');
ok(page($j, '/admin/settings')['code'] === 403 && page($j, '/admin/feed-keys/')['code'] === 403 && page($j, '/admin/tax-rates')['code'] === 403, '/admin/settings, /admin/feed-keys/ and /admin/tax-rates resolve (403 for Nora, by the right)');
ok(page($j, '/purchasing/new')['code'] === 200 && page($j, '/suppliers/new')['code'] === 200 && page($j, '/purchasing/1')['code'] === 404 && page($j, '/purchasing/1/edit')['code'] === 404 && page($j, '/purchasing/12/lines')['code'] === 404 && page($j, '/orders/new')['code'] === 200, '/purchasing/new and /suppliers/new (slice 6) resolve to their forms; /purchasing/1, /purchasing/1/edit and /purchasing/12/lines are rewritten to the real files, which find no such purchase order: 404');
ok(req('GET', '/nosuchscreen', ['jar' => $j])['code'] === 404, 'an unknown path: 404');
$tok = str_repeat('ab', 24);
ok(req('GET', "/o/$tok")['code'] === 404 && req('GET', "/s/$tok")['code'] === 404 && req('GET', "/s/$tok/acknowledge")['code'] === 404, 'the two doors /o/{token} and /s/{token} (and the supplier\'s actions) are rewritten to o.php / s.php (slices 5 and 6) and answer 404 (a dead token)');
ok(req('GET', '/o/short')['code'] === 404 && req('GET', '/s/' . str_repeat('zz', 24))['code'] === 404, 'a door token of the wrong shape: 404');
ok(req('GET', '/api/v1/availability')['code'] === 401 && req('GET', '/api/v1/availability?q=x')['code'] === 401 && req('POST', '/api/v1/availability')['code'] === 405 && req('GET', '/api/v1/nosuch')['code'] === 404, '/api/v1/availability is rewritten to its handler (slice 7): a call with no key is 401, a POST 405 — and anything else under /api/ is 404');
ok(req('GET', '/files/1')['code'] === 401, '/files/1 without a session: 401 (never a redirect — a file URL sits in an <img>)');
ok(page($j, '/files/1')['code'] === 404 && page($j, '/files/1/thumb')['code'] === 404, '/files/1 with a session: 404 (no attachment yet); /files/1/thumb the same');
$h = req('GET', '/api/v1/health');
ok($h['code'] === 200 && json_decode($h['body'], true)['application'] === 'inventory', '/api/v1/health answers');
ok(req('GET', '/api/v1/health.php')['code'] === 200, '/api/v1/health.php answers too');
ok(req('GET', '/api/v1/other')['code'] === 404 && req('GET', '/api/')['code'] === 404 && req('GET', '/api/anything')['code'] === 404, 'anything else under /api is 404');
if (getenv('INV_APP') === 'apache') {
    ok(in_array(req('GET', '/mcp/records')['code'], [502, 503], true) && in_array(req('GET', '/mcp/activity')['code'], [502, 503], true), 'the MCP proxies are in the vhost and answer 502 until Phase 4 starts the servers');
    $i = req('GET', 'http://127.0.0.1:8607/api/v1/health');
    ok($i['code'] === 200 && json_decode($i['body'], true)['application'] === 'inventory', 'the internal vhost on 8607 answers health the same');
    ok(req('GET', 'http://127.0.0.1:8607/products/', ['jar' => $j])['code'] === 200 && req('GET', 'http://127.0.0.1:8607/files/1')['code'] === 401, 'and the canonical rewrites and the attachment door');
}
$m = req('GET', '/manifest.webmanifest');
ok($m['code'] === 200 && preg_match('/^Content-Type: application\/(manifest\+)?json/mi', $m['headers']) === 1 && json_decode($m['body'], true)['name'] === 'Inventory', '/manifest.webmanifest is served as JSON and names Inventory');
$s = req('GET', '/assets/css/theme.min.css');
ok($s['code'] === 200 && preg_match('/^Content-Type: text\/css/mi', $s['headers']) === 1, 'static assets are served with their type');
foreach (['/config/.env', '/db/001_roles_and_identity.sql', '/app/bootstrap.php', '/bin/directory_sync.php', '/mcp/db.py', '/CLAUDE.md', '/maludb-os.json', '/.git/config', '/tests/phase2/lib.php', '/deploy/ROOT_STEPS.sh', '/storage/attachments/x'] as $p) {
    $r = req('GET', $p);
    ok(in_array($r['code'], [403, 404], true) && !str_contains($r['body'], 'ACTION_TOKEN_KEY') && !str_contains($r['body'], 'CREATE TABLE'), "$p is not served ({$r['code']})");
}
finish();

<?php
/** Connections (feed.md "Proof" ≈ 8): the five shares with their documents, "nothing read yet", then the share.read rows db/017's logger writes — grouped by application and tool, and listed with their counts and request ids — what this application reads of theirs, the way to the OS, and who may open it. */
require __DIR__ . '/lib.php';
$w = feed_world();
$owner = $w['owner'];
$since = last_activity_id();

echo "1. Before anything is read\n";
$r = req('GET', '/admin/connections', ['jar' => $owner]);
$b = $r['body'];
ok($r['code'] === 200 && str_contains($b, 'id="connections-shares"') && substr_count($b, 'id="connections-share-') === 5, 'the admin sees the five shares this application declares');
$all = true;
foreach (['sales_closed' => 'os.inventory-sales/1', 'purchases_received' => 'os.inventory-purchases/1', 'stock_valuation' => 'os.inventory-valuation/1', 'availability_index' => 'os.inventory-availability/1', 'customer_orders' => 'os.inventory-orders/1'] as $tool => $doc) {
    if (!preg_match('~id="connections-share-' . $tool . '".*?' . preg_quote($doc, '~') . '~s', $b)) { $all = false; }
}
ok($all, '…each with its tool name and its document');
ok(str_contains($b, 'id="connections-readers-empty"') && str_contains($b, 'id="share-reads-empty"') && str_contains($b, 'Nothing read yet.'), 'with no share.read rows the page says "Nothing read yet."');
ok(str_contains($b, 'id="connections-reads-none"') && str_contains($b, 'Inventory reads nothing of another application in version 1.'), 'what Inventory reads of theirs: nothing in version 1');
ok(str_contains($b, 'href="https://os.example.invalid/applications"') && str_contains($b, 'id="connections-os-link"') && str_contains($b, 'bin/app_connection.php'), 'the link to the OS\'s applications page is on the os. name (the launcher\'s app. name turned into os.); approving a connection is the super-admin\'s there');

echo "2. After the records server has logged reads\n";
$id1 = (int) one("SELECT inv_log_share_read('sales_closed', 'gl', 120, 'req-1')");
$id2 = (int) one("SELECT inv_log_share_read('customer_orders', 'helpdesk', 3, 'req-2')");
$id3 = (int) one("SELECT inv_log_share_read('sales_closed', 'gl', 30, 'req-3')");
psql_exec("UPDATE activity_log SET occurred_at = now() - interval '3 days' WHERE id = $id2");
$r = req('GET', '/admin/connections', ['jar' => $owner]);
$b = $r['body'];
ok($id1 > 0 && !str_contains($b, 'id="connections-readers-empty"') && substr_count($b, 'id="connections-reader-row-') === 2 && str_contains($b, 'id="connections-readers-table"'), 'the grouped readers: two rows (gl sales_closed, helpdesk customer_orders)');
preg_match('~id="connections-reader-row-1".*?</tr>~s', $b, $m1);
preg_match('~id="connections-reader-row-2".*?</tr>~s', $b, $m2);
$cells = static fn (string $tr): array => preg_match_all('~<td[^>]*>(.*?)</td>~s', $tr, $mm) ? array_map(static fn (string $c): string => trim(strip_tags($c)), $mm[1]) : [];
$gl = $cells($m1[0] ?? '');
$hd = $cells($m2[0] ?? '');
ok(array_slice($gl, 0, 4) === ['gl', 'sales_closed', '2', '150'], 'gl · sales_closed: 2 calls, 150 rows (the sum of the counts)');
ok(array_slice($hd, 0, 4) === ['helpdesk', 'customer_orders', '1', '3'], 'helpdesk · customer_orders: 1 call, 3 rows');
ok(substr_count($b, 'id="share-read-row-') === 3 && str_contains($b, 'id="share-read-row-' . $id1 . '"') && str_contains($b, 'req-1') && str_contains($b, 'req-2') && str_contains($b, 'req-3'), 'the list of reads: the three rows with their request ids');
ok(preg_match('~id="share-read-row-' . $id1 . '".*?bg-soft-success~s', $b) === 1 && preg_match('~id="share-read-row-' . $id2 . '".*?bg-soft-secondary~s', $b) === 1, 'a read within 24 hours is success, an older one secondary');
[$c, $d] = screen($owner, '/admin/connections');
ok($c === 200 && count($d['shares']) === 5 && count($d['readers']) === 2 && count($d['share_reads']) === 3 && $d['declared_reads'] === [] && str_starts_with((string) $d['os_url'], 'https://os.'), 'the JSON read: shares, readers, the reads, no declared reads, the OS link');
$sr = (int) one("SELECT count(*) FROM activity_log WHERE id > :s AND action = 'screen.view' AND screen = 'connection-list'", ['s' => $since]);
ok($sr >= 2, 'screen.view is logged for connection-list');

echo "3. Who may open it\n";
$r = req('GET', '/admin/connections', ['jar' => $w['nora']]);
ok($r['code'] === 403 && str_contains($r['body'], 'You may not change the settings.'), 'Nora (the Buyer): 403 in words');
ok(req('GET', '/admin/connections', ['jar' => $w['wes']])['code'] === 403 && req('GET', '/admin/connections', ['jar' => $w['vera']])['code'] === 403, 'Wes and Vera: 403');
psql_exec("INSERT INTO inv_role_rights (role_key, right_key) VALUES ('warehouse', 'settings.manage') ON CONFLICT DO NOTHING");
$r1 = req('GET', '/admin/connections', ['jar' => $w['wes']]);
psql_exec("DELETE FROM inv_role_rights WHERE role_key = 'warehouse' AND right_key = 'settings.manage'; INSERT INTO inv_role_rights (role_key, right_key) VALUES ('warehouse', 'agents.settings') ON CONFLICT DO NOTHING");
$r2 = req('GET', '/admin/connections', ['jar' => $w['wes']]);
psql_exec("DELETE FROM inv_role_rights WHERE role_key = 'warehouse' AND right_key = 'agents.settings'");
$r3 = req('GET', '/admin/connections', ['jar' => $w['wes']]);
ok($r1['code'] === 200 && $r2['code'] === 200 && $r3['code'] === 403, 'a member holding settings.manage, or agents.settings, may open it; with the right taken back, 403 again');
ok(req('GET', '/admin/connections', ['jar' => $owner, 'headers' => ['Accept: text/html']])['code'] === 200 && trim((string) shell_exec('grep -c "render_nav_stub" ' . escapeshellarg(dirname(__DIR__, 3) . '/html/admin/connections.php')) ) === '0', 'the placeholder is gone from the controller');
finish();

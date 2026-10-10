<?php
/** The trail (reports-admin.md "Proof": ≥ 14): one's own rows, a record's history by its audit keys, another person's trail by right, the filters, a sentence for every event, a link that resolves. */
require __DIR__ . '/lib.php';
$w = admin_world();
[$nora, $sam, $wes, $vera, $owner] = [who('nora'), who('sam'), who('wes'), who('vera'), who('owner')];
$trail = static function (string $jar, string $qs = '', int $pages = 1): array {
    $rows = []; $d = [];
    for ($p = 1; $p <= $pages; $p++) {
        [$c, $d] = screen($jar, '/trail?' . $qs . ($p > 1 ? '&page=' . $p : ''));
        if ($c !== 200) { return [$c, [], $d]; }
        $rows = array_merge($rows, $d['rows']);
        if (!$d['more']) { break; }
    }
    return [200, $rows, $d];
};

echo "1. My own trail\n";
[$c, $rows, $d] = $trail($sam, '');
$at = array_column($rows, 'occurred_at');
$sorted = $at; rsort($sorted);
ok($c === 200 && count($rows) >= 5 && $at === $sorted && count(array_unique(array_column(array_column($rows, 'actor'), 'member_id'))) === 1 && $rows[0]['actor']['member_id'] === 41, "Sam's own rows, newest first (" . count($rows) . ' on the first page), all his');
ok(array_reduce($rows, static fn (bool $a, array $r): bool => $a && str_starts_with($r['sentence'], 'SMOKE Sam '), true) && !preg_match('/ did /', implode(' ', array_column($rows, 'sentence'))), 'every row is a sentence beginning with who: "SMOKE Sam …"');
$conf = array_values(array_filter($rows, static fn (array $r): bool => $r['action'] === 'order.confirm'))[0] ?? null;
ok($conf !== null && preg_match('/^SMOKE Sam confirmed order SO-\d+$/', $conf['sentence']) === 1, 'an order\'s confirmation reads "SMOKE Sam confirmed order SO-…": ' . ($conf['sentence'] ?? 'none'));

echo "2. A record's history\n";
[$c, $rows] = $trail($owner, 'order=' . $w['so1'] . '&period=90', 3);
$bad = array_filter($rows, static fn (array $r): bool => (int) $r['sales_order_id'] !== $w['so1']);
$actions = array_unique(array_column($rows, 'action'));
ok($c === 200 && count($rows) >= 4 && $bad === [] && in_array('order.quote', $actions, true) && in_array('order.confirm', $actions, true) && in_array('order.ship', $actions, true), 'order=1: every row has sales_order_id = ' . $w['so1'] . ' — the quote, the confirmation, the shipment (' . implode(', ', $actions) . ')');
[$c, $rows] = $trail($owner, 'source=' . $w['src_malouf'] . '&period=90', 3);
ok($c === 200 && count($rows) >= 2 && array_reduce($rows, static fn (bool $a, array $r): bool => $a && (int) $r['source_id'] === $w['src_malouf'], true), 'source=: every row has the source_id (pulls, probes, edits): ' . count($rows) . ' rows');
$po = po_for($w['so1'], $w['zinus']);
[$c, $rows] = $trail($owner, 'purchase_order=' . $po . '&period=90', 2);
ok($c === 200 && count($rows) >= 1 && array_reduce($rows, static fn (bool $a, array $r): bool => $a && (int) $r['purchase_order_id'] === $po, true), 'purchase_order=: every row has the purchase_order_id: ' . count($rows) . ' rows');
[$c, $rows] = $trail($owner, 'variant=' . $w['queen'] . '&period=90', 3);
$byEntity = array_filter($rows, static fn (array $r): bool => $r['entity_type'] === 'product_variant' && (int) $r['entity_id'] === $w['queen']);
$byAfter = array_filter($rows, static fn (array $r): bool => $r['entity_type'] !== 'product_variant');
ok($c === 200 && count($byEntity) >= 1, 'variant=: the variant\'s own rows (' . count($byEntity) . ') by entity');
psql_exec("INSERT INTO activity_log (actor_member_id, source, action, entity_type, entity_id, after) VALUES (41, 'web', 'watch.set', 'watch', 999, '{\"variant_id\": {$w['queen']}}'::jsonb)");
[$c, $rows] = $trail($owner, 'variant=' . $w['queen'] . '&period=1', 2);
ok(count(array_filter($rows, static fn (array $r): bool => $r['entity_type'] === 'watch')) >= 1, 'and the rows whose payload names the variant (a watch on it) are in its history too');

echo "3. Another person's trail\n";
[$c] = $trail($sam, 'member=40');
ok($c === 403, 'Sam asking for Nora\'s trail: 403');
$r = req('GET', '/trail?member=40', ['jar' => $sam]);
ok(str_contains($r['body'], 'You may not run the reports.'), '… in words (the reports.read sentence)');
[$c, $rows] = $trail($nora, 'member=40');
ok($c === 200 && count($rows) >= 1 && array_unique(array_column(array_column($rows, 'actor'), 'member_id')) === [40], 'Nora asking for her own: her rows');
[$c, $rows] = $trail($owner, 'member=40');
ok($c === 200 && count($rows) >= 1 && array_unique(array_column(array_column($rows, 'actor'), 'member_id')) === [40], 'the admin asking for Nora\'s: hers');
psql_exec("INSERT INTO activity_log (actor_member_id, source, action, entity_type, entity_id, after) VALUES (" . EXPERT . ", 'agent', 'watch.set', 'watch', 998, '{}'::jsonb)");
[$c, $rows] = $trail($sam, 'member=' . EXPERT . '&period=1');
ok($c === 200 && count($rows) >= 1 && $rows[0]['actor']['is_agent'] === true && str_contains($rows[0]['sentence'], '(agent)'), 'Sam asking for the expert\'s (an agent): any reader may — the rows read "(agent)"');

echo "4. The filters\n";
[$c, $rows] = $trail($sam, 'action=order.&period=90', 2);
ok($c === 200 && count($rows) >= 2 && array_reduce($rows, static fn (bool $a, array $r): bool => $a && str_starts_with($r['action'], 'order.'), true), 'action=order. — only the order events');
psql_exec("UPDATE activity_log SET occurred_at = now() - interval '3 days' WHERE id = (SELECT max(id) FROM activity_log WHERE actor_member_id = 41 AND action = 'order.quote')");
[, $day] = $trail($sam, 'action=order.&period=1', 2);
[, $wk] = $trail($sam, 'action=order.&period=7', 2);
ok(count($day) < count($wk) && count($wk) > 0, 'period=1 leaves out a row three days old that period=7 keeps (' . count($day) . ' < ' . count($wk) . ')');
[, $def, $dd] = $trail($sam, '');
ok($dd['filters']['period'] === 30 || $dd['filters'] === ['period' => 30], 'the period is 30 days by default');
[$c, $rows] = $trail($owner, 'action=settings.&period=90');
ok($c === 200, 'a prefix with a dot is a filter, not an injection (settings. → 200)');
[$c, $rows, $dd] = $trail($owner, 'action=' . urlencode("x' OR 1=1 --"));
ok($c === 200, 'a hostile action filter is dropped, not run');

echo "5. Every event has a sentence\n";
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$events = array_values(array_unique(array_filter(array_column($reg['actions'], 'log_event'))));
$extra = ['source.pull_start', 'source.pull_done', 'source.pull_fail', 'source.pull_blocked', 'source.probe', 'source.search', 'listing.new', 'listing.changed', 'listing.removed', 'offer.change', 'watch.fire', 'agent.dispatch', 'agent.reply', 'agent.fail',
          'feed.read', 'feed.rate_limited', 'share.read', 'member.sign_on', 'member.sign_on.refused', 'member.refused', 'directory.sync', 'notification.send', 'notification.skip', 'notification.fail', 'worker.pass', 'screen.view', 'order.customer_view',
          'purchase_order.supplier_view', 'purchase_order.supplier_ack', 'purchase_order.supplier_decline', 'purchase_order.supplier_tracking'];
$all = array_values(array_unique(array_merge($events, $extra)));
sort($all);
psql_exec("DELETE FROM activity_log WHERE actor_member_id = 41 AND source = 'web' AND route = 'S9 EVENT'");
$vals = [];
foreach ($all as $e) { $vals[] = "(41, 'web', '$e', 'S9 EVENT', " . ($e === 'screen.view' ? "'order-list'" : 'NULL') . ", 'sales_order', {$w['so1']}, '{\"number\": \"SO-00001\"}'::jsonb)"; }
psql_exec('INSERT INTO activity_log (actor_member_id, source, action, route, screen, entity_type, entity_id, after) VALUES ' . implode(', ', $vals));
$sentences = [];
for ($p = 1; $p <= 8; $p++) {
    [$c, $dd2] = screen($owner, '/trail?member=41&period=1&page=' . $p);
    foreach ($dd2['data']['rows'] ?? $dd2['rows'] ?? [] as $r) { $sentences[$r['action']] = $r['sentence']; }
    if (!($dd2['more'] ?? false)) { break; }
}
$gaps = array_filter($all, static fn (string $e): bool => !isset($sentences[$e]) || preg_match('/ did ' . preg_quote($e, '/') . ' on /', $sentences[$e]) === 1);
ok(count($all) >= 150 && $gaps === [], count($all) . ' events (' . count($events) . ' in the registry + ' . count($extra) . ' of the worker, the doors, the feed and the kit): every one has a sentence' . ($gaps ? ' — gaps: ' . implode(', ', $gaps) : ''));
ok(!str_contains(implode('|', $sentences), 'unknown') && str_contains($sentences['order.confirm'] ?? '', 'SO-00001') && str_contains($sentences['token.mint'] ?? '', 'access token'), 'and the sentences name the record: "' . ($sentences['order.confirm'] ?? '') . '"');

echo "6. A link that resolves\n";
psql_exec("DELETE FROM activity_log WHERE route = 'S9 EVENT'");
[$c, $rows] = $trail($owner, 'period=90', 1);
$allRows = [];
for ($p = 1; $p <= 12; $p++) {
    [$c, $rs, $dd2] = $trail($owner, 'member=41&period=90&page=' . $p);
    $allRows = array_merge($allRows, $rs);
    if (!$dd2['more']) { break; }
}
foreach (['member=40', 'member=42', 'member=1'] as $q) { [, $rs] = $trail($owner, $q . '&period=90', 3); $allRows = array_merge($allRows, $rs); }
$withUrl = array_values(array_filter($allRows, static fn (array $r): bool => $r['url'] !== null));
$byUrl = [];
foreach ($withUrl as $r) { $byUrl[$r['url']] ??= $r; }
$sample = array_slice(array_values($byUrl), 0, 20);
$dead = [];
foreach ($sample as $r) { $code = pg($owner, $r['url'])[0]; if ($code !== 200) { $dead[] = $r['url'] . ' (' . $r['action'] . ') ' . $code; } }
ok(count($sample) >= 12 && $dead === [], count($sample) . ' sampled links answer 200' . ($dead ? ': ' . implode('; ', $dead) : ''));
[$c, $html] = pg($owner, '/trail?order=' . $w['so1'] . '&period=90');
ok($c === 200 && str_contains($html, 'id="trail-results"') && str_contains($html, 'id="trail-row-') && str_contains($html, 'history'), 'the page for a record\'s history renders its rows (trail-results, trail-row-{id})');
$since = last_activity_id();
pg($owner, '/trail?order=' . $w['so1']);
$lg = last_log('screen.view', $since);
ok($lg !== null && (after_of($lg)['order'] ?? null) === $w['so1'] && (after_of($lg)['screen'] ?? '') === 'trail', 'opening a record\'s history logs screen.view with the record key');
finish();

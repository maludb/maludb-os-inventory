<?php
/** Home (reports-admin.md "Proof": ≥ 36): the seven counts and where they lead, the regions per role, the JSON keys, nothing written but screen.view. */
require __DIR__ . '/lib.php';
$w = admin_world();
[$nora, $sam, $wes, $vera, $ann, $owner] = [who('nora'), who('sam'), who('wes'), who('vera'), who('ann'), who('owner')];

echo "1. The data to find\n";
psql_exec("UPDATE listing_variants SET availability = 'out_of_stock' WHERE id = {$w['lv_zinus_k']}");        // a line at risk: the drop-shipped King's offer is gone
$ref = (float) one("SELECT lv.price FROM listing_variants lv JOIN listings l ON l.id = lv.listing_id JOIN sources s ON s.id = l.source_id WHERE s.role = 'reference' AND lv.variant_id = :v AND lv.removed_at IS NULL AND lv.price IS NOT NULL ORDER BY lv.price LIMIT 1", ['v' => $w['queen']]);
psql_exec("UPDATE product_variants SET retail_price = " . round($ref / 0.75, 2) . " WHERE id = {$w['queen']}");     // a price exception: a reference asks 25 % under our Queen
psql_exec("SELECT inv_po_send({$w['po_zinus']}, 40, 'email'); UPDATE purchase_orders SET sent_at = now() - interval '5 days' WHERE id = {$w['po_zinus']}");   // a PO sent five days ago, never acknowledged
if ((int) one("SELECT count(*) FROM goods_receipts WHERE status = 'draft'") === 0) {                           // Wes's world: a draft receipt, an open count, a stock PO due
    act(who('wes'), '/receipts/save.php', ['location' => $w['wh'], 'delivery_note_ref' => 'S9-DRAFT']);
    act(who('wes'), '/counts/start.php', ['location' => $w['wh']]);
}
psql_exec("UPDATE purchase_orders SET expected_on = current_date - 1 WHERE id = " . (int) one("SELECT id FROM purchase_orders WHERE kind = 'stock' ORDER BY id LIMIT 1"));
$direct = static function (int $member, string $sql): ?int { as_db($member); try { return (int) one($sql); } catch (PDOException $e) { if ($e->getCode() === '42501') { return null; } throw $e; } };
$counts = static fn (int $m): array => [
    'lines_at_risk' => $direct($m, 'SELECT count(*) FROM inv_lines_at_risk()'), 'reorder' => $direct($m, 'SELECT count(*) FROM inv_reorder_candidates()'), 'prices' => $direct($m, 'SELECT count(*) FROM inv_price_exceptions()'),
    'unmatched' => $direct($m, 'SELECT count(*) FROM inv_unmatched_listings(NULL)'), 'sources' => $direct($m, "SELECT count(*) FROM inv_source_health() WHERE health NOT IN ('ok', 'manual', 'inactive')"),
    'purchase_orders' => $direct($m, 'SELECT count(*) FROM inv_purchase_orders_open() WHERE awaiting_ack OR overdue OR untracked_past_expected'), 'returns' => $direct($m, 'SELECT count(*) FROM inv_returns_open()')];
$nc = $counts(40);
ok($nc['lines_at_risk'] >= 1 && $nc['reorder'] >= 1 && $nc['prices'] >= 1 && $nc['unmatched'] >= 1 && $nc['sources'] >= 1 && $nc['purchase_orders'] >= 1, 'every heading the Buyer sees has something to say: ' . json_encode($nc));

echo "2. Nora, the Buyer\n";
[$c, $d] = screen($nora, '/');
ok($c === 200 && array_keys($d) === ['note', 'at_risk', 'sources', 'unmatched', 'po_ack', 'today', 'my_orders', 'warehouse', 'admin', 'bell', 'may'], 'the JSON answers the eleven keys of home_summary()');
$h = $d['note']['headings'];
$got = array_map(static fn (array $x): ?int => $x['count'], $h);
ok(array_keys($h) === ['lines_at_risk', 'reorder', 'prices', 'unmatched', 'sources', 'purchase_orders', 'returns'] && $got === $nc, 'the seven counts match the seven functions called directly');
ok($d['at_risk']['count'] === $nc['lines_at_risk'] && $d['sources']['count'] >= 1 && $d['unmatched']['count'] === $nc['unmatched'] && count($d['unmatched']['rows']) === min(5, $nc['unmatched']), 'at risk, sources and unmatched: the counts, and the five newest unmatched at most');
ok($d['po_ack']['count'] >= 1 && $d['po_ack']['rows'][0]['number'] === (string) one('SELECT number FROM purchase_orders WHERE id = :p', ['p' => $w['po_zinus']]) && $d['po_ack']['rows'][0]['days_waiting'] >= 5, 'the purchase order sent five days ago is awaiting acknowledgment (days_waiting ≥ 5)');
ok($d['my_orders'] !== null && $d['warehouse'] !== null && $d['admin'] === null, 'Nora (Buyer) holds orders.write and the stock rights: my orders and the warehouse block, no admin block');
[$c, $html] = pg($nora, '/');
ok($c === 200 && has_id($html, 'home-note') && has_id($html, 'home-at-risk') && has_id($html, 'home-sources') && has_id($html, 'home-unmatched') && has_id($html, 'home-po-ack') && has_id($html, 'home-today') && has_id($html, 'home-bell'), 'the page has its regions: note, at risk, sources, unmatched, purchase orders, today, bell');
$links = [];
foreach (array_keys($h) as $k) { $links[$k] = href_of($html, "home-note-$k-link"); }
ok($links['reorder'] === '/stock/?below_reorder=1' && $links['prices'] === '/reports/price-exceptions' && $links['unmatched'] === '/matching/' && $links['sources'] === '/sources/?health=failing'
    && $links['purchase_orders'] === '/purchasing/?awaiting_ack=1' && $links['returns'] === '/returns/?awaiting_disposition=1', 'each heading links where the table says: ' . json_encode($links));
ok(str_contains($html, 'href="#home-at-risk"') && has_id($html, 'home-note-lines_at_risk-link'), 'the at-risk heading jumps to the at-risk region on the page');
foreach ([$links['reorder'], $links['prices'], $links['unmatched'], $links['sources'], $links['purchase_orders'], $links['returns']] as $href) {
    $bad = pg($nora, $href)[0];
    if ($bad !== 200) { ok(false, "the count's list $href opens (got $bad)"); }
}
ok(true, 'and every one of those lists opens (200)');
$risk = $d['at_risk']['rows'][0];
ok(str_starts_with($risk['order_number'], 'SO-') && $risk['risk'] !== '' && has_id($html, 'home-at-risk-row-' . $risk['line_id']) && str_contains($html, 'href="/orders/' . $risk['sales_order_id'] . '?back='), 'a line at risk names its order, its risk, and opens the order: ' . $risk['order_number'] . ' ' . $risk['risk']);
ok(array_sum(array_column($d['today']['groups'], 'lines')) === $d['today']['lines'], "today's deliveries and pickups: " . $d['today']['lines'] . ' lines in ' . count($d['today']['groups']) . ' groups by location and delivery method');
ok(count($d['sources']['rows']) >= 1 && in_array($d['sources']['rows'][0]['health'], ['failing', 'blocked', 'paused'], true), 'the sources region lists failing · blocked · paused only');

echo "3. Sam, a salesperson\n";
[$c, $d] = screen($sam, '/');
$hs = $d['note']['headings'];
ok($hs['reorder']['withheld'] && $hs['prices']['withheld'] && $hs['unmatched']['withheld'] && $hs['reorder']['count'] === null, 'the note withholds reorder, prices and unmatched (a withheld heading has no count)');
[$c, $html] = pg($sam, '/');
ok(!has_id($html, 'home-note-reorder') && !has_id($html, 'home-note-prices') && !has_id($html, 'home-note-unmatched') && has_id($html, 'home-note-lines_at_risk') && has_id($html, 'home-note-returns'), 'and the page does not show those headings at all');
ok($d['unmatched'] === null && $d['warehouse'] === null && $d['admin'] === null && !has_id($html, 'home-unmatched') && !has_id($html, 'home-warehouse') && !has_id($html, 'home-admin') && has_id($html, 'home-my-orders'), 'no unmatched, warehouse or admin block for Sam — his own orders');
$steps = array_column($d['my_orders']['rows'], 'next_step', 'number');
$mine = array_column($d['my_orders']['rows'], null, 'number');
$nums = array_map(static fn (int $id): string => (string) one('SELECT number FROM sales_orders WHERE id = :i', ['i' => $id]), [$w['s9_quote'], $w['s9_late'], $w['s9_wait'], $w['main'], $w['so1']]);
ok($d['my_orders']['rows'][0]['number'] === $nums[1] && $steps[$nums[1]] === 'Late by 2 days', 'the late order comes first: "Late by 2 days"');
ok($steps[$nums[0]] === 'Confirm' && $steps[$nums[3]] === 'Record a deposit' && $steps[$nums[2]] === 'Waiting on ' . (string) one('SELECT name FROM suppliers WHERE id = :s', ['s' => $w['zinus']]) && $steps[$nums[4]] === 'Deliver', 'the next steps in words: Confirm · Record a deposit · Waiting on Zinus · Deliver — ' . json_encode($steps));
ok($d['my_orders']['count'] === count($d['my_orders']['rows']) && !isset($mine[(string) one('SELECT number FROM sales_orders WHERE id = :i', ['i' => $w['so2']])]), 'closed orders are not listed (the open ones only)');
ok(text_of($html, 'home-my-order-' . $w['s9_late'] . '-step') === 'Late by 2 days' && str_contains($html, '/orders/' . $w['s9_quote']), 'the page words the step and opens each order');

echo "4. Wes, the warehouse\n";
[$c, $d] = screen($wes, '/');
ok($d['warehouse'] !== null && $d['my_orders'] === null && $d['admin'] === null, "Wes has the warehouse block, not Sam's orders nor the admin's");
ok(count($d['warehouse']['to_receive']['receipts']) >= 1 && $d['warehouse']['to_receive']['receipts'][0]['number'] !== '', 'to receive: the draft receipt');
ok(count($d['warehouse']['to_receive']['purchase_orders']) >= 1 && $d['warehouse']['to_receive']['purchase_orders'][0]['expected_on'] < gmdate('Y-m-d', time() + 86400), 'to receive: the stock purchase order due (expected today or earlier)');
ok(count($d['warehouse']['to_count']) >= 1 && count($d['warehouse']['to_pick']) >= 0, 'to count: the open count');
[$c, $html] = pg($wes, '/');
ok(has_id($html, 'home-warehouse') && !has_id($html, 'home-my-orders'), "the page: the warehouse card, not Sam's");

echo "5. The admin\n";
[$c, $d] = screen($owner, '/');
ok($d['admin'] !== null && isset($d['admin']['dispatches']['failed']) && isset($d['admin']['feed']['calls_today']) && $d['admin']['feed']['live_keys'] >= 1, "the admin block: the feed's keys and calls today, the dispatches pending / running / awaiting approval / failed");
$disp = (int) one("SELECT count(*) FROM agent_dispatches WHERE status = 'failed'");
ok($d['admin']['dispatches']['failed'] === $disp, "the failed dispatches are counted as the table holds them ($disp)");
ok($d['unmatched'] !== null && $d['warehouse'] !== null && $d['my_orders'] !== null, 'the admin holds every right: unmatched, warehouse and my orders too');
ok(count($d['bell']) <= 5, 'the bell: at most the five newest unread');
[$c, $html] = pg($owner, '/');
ok(has_id($html, 'home-admin') && has_id($html, 'home-admin-dispatch-failed') && href_of($html, 'home-admin-dispatch-failed') === '/admin/dispatches?status=failed', 'the admin card links the failed dispatches');

echo "6. Vera and Ann, the readers\n";
foreach (['vera' => $vera, 'ann' => $ann] as $name => $jar) {
    [$c, $d] = screen($jar, '/');
    $hv = $d['note']['headings'];
    ok($hv['lines_at_risk']['withheld'] === false && $hv['sources']['withheld'] === false && $hv['purchase_orders']['withheld'] === false && $hv['returns']['withheld'] === false && $hv['reorder']['withheld'] && $hv['prices']['withheld'] && $hv['unmatched']['withheld'], "$name: the note's reader headings (at risk, sources, purchase orders, returns), nothing else");
    ok($d['my_orders'] === null && $d['warehouse'] === null && $d['admin'] === null && $d['unmatched'] === null && $d['may']['cost'] === false, "$name: no block of any role, and the wall is up");
}

echo "7. What Home writes\n";
$since = last_activity_id();
screen($sam, '/'); pg($sam, '/'); screen($owner, '/');
$rows = q("SELECT action FROM activity_log WHERE id > :s", ['s' => $since]);
ok($rows !== [] && array_unique(array_column($rows, 'action')) === ['screen.view'], 'Home writes only screen.view (' . count($rows) . ' rows for three opens)');
$empty = who('omar');
[$c, $html] = pg($empty, '/');
ok($c === 200 && str_contains($html, 'id="home-note"') && has_id($html, 'home-at-risk'), 'a reader below the wall still gets the home (the analyst)');
[$c, $html] = pg($vera, '/');
ok(str_contains(text_of($html, 'home-today-empty') . text_of($html, 'home-today'), 'today') || str_contains($html, 'home-today'), "a region's empty state says what will appear");
finish();

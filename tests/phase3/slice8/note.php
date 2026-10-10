<?php
/** The morning note (returns-worker.md "Proof": the data ≥ 22, the note sent ≥ 12): the seven headings as the caller may see them, withheld where a right is missing, the markdown; then sending it to the Buyer. */
require __DIR__ . '/lib.php';
$w = returns_world();
[$nora, $sam, $wes, $vera, $owner] = [$w['nora'], $w['sam'], $w['wes'], $w['vera'], $w['owner']];
kernel_clean();

echo "1. The data to find\n";
// a line at risk: the King drop-shipped by Zinus on the open order S6-MAIN, the offer gone
psql_exec("UPDATE listing_variants SET availability = 'out_of_stock' WHERE id = {$w['lv_zinus_k']}");
// a price exception: a reference asks 25 % under our Queen
$ref = (float) one("SELECT lv.price FROM listing_variants lv JOIN listings l ON l.id = lv.listing_id JOIN sources s ON s.id = l.source_id WHERE s.role = 'reference' AND lv.variant_id = :v AND lv.removed_at IS NULL AND lv.price IS NOT NULL ORDER BY lv.price LIMIT 1", ['v' => $w['queen']]);
psql_exec("UPDATE product_variants SET retail_price = " . round($ref / 0.75, 2) . " WHERE id = {$w['queen']}");
// six unmatched listings (more than the five a heading shows)
psql_exec("INSERT INTO listing_variants (listing_id, external_variant_id, title, sku) SELECT l.id, 'S8-UNM-' || g, 'S8 unmatched ' || g, 'S8U' || g FROM (SELECT id FROM listings WHERE source_id = {$w['src_malouf']} ORDER BY id LIMIT 1) l, generate_series(1, 6) g ON CONFLICT DO NOTHING");
// a purchase order sent five days ago and never acknowledged (ack_days 3)
psql_exec("SELECT inv_po_send({$w['po_zinus']}, 40, 'email'); UPDATE purchase_orders SET sent_at = now() - interval '5 days' WHERE id = {$w['po_zinus']}");
psql_exec("INSERT INTO buyer_proposals (kind, subject_type, subject_id, title, proposed_by, note_date, status, decided_by, decided_at, detail) VALUES ('reorder', 'product_variant', {$w['twin']}, 'S8 older note', 47, '2026-10-04', 'accepted', 40, '2026-10-04',  '{\"best_cost\": 321.5}')");
$direct = static function (int $member, string $sql): ?int { as_db($member); try { return (int) one($sql); } catch (PDOException $e) { if ($e->getCode() === '42501') { return null; } throw $e; } };
$counts = static fn (int $m): array => [
    'lines_at_risk' => $direct($m, 'SELECT count(*) FROM inv_lines_at_risk()'), 'reorder' => $direct($m, 'SELECT count(*) FROM inv_reorder_candidates()'), 'prices' => $direct($m, 'SELECT count(*) FROM inv_price_exceptions()'),
    'unmatched' => $direct($m, 'SELECT count(*) FROM inv_unmatched_listings(NULL)'), 'sources' => $direct($m, "SELECT count(*) FROM inv_source_health() WHERE health NOT IN ('ok', 'manual', 'inactive')"),
    'purchase_orders' => $direct($m, 'SELECT count(*) FROM inv_purchase_orders_open() WHERE awaiting_ack OR overdue OR untracked_past_expected'), 'returns' => $direct($m, 'SELECT count(*) FROM inv_returns_open()')];
$nc = $counts(40);
ok($nc['lines_at_risk'] >= 1 && $nc['reorder'] >= 1 && $nc['prices'] >= 1 && $nc['unmatched'] >= 6 && $nc['sources'] >= 1 && $nc['purchase_orders'] >= 1 && $nc['returns'] >= 1, 'every heading has something to say for the Buyer: ' . json_encode($nc));

echo "2. As the Buyer\n";
$n = call_fn(40, 'morning_note', [null, 5])['result'];
$h = $n['headings'];
ok(array_keys($h) === ['lines_at_risk', 'reorder', 'prices', 'unmatched', 'sources', 'purchase_orders', 'returns'] && $n['date'] === gmdate('Y-m-d'), 'morning_note() as Nora: the seven headings, today\'s date');
$got = array_map(static fn (array $x): ?int => $x['count'], $h);
ok($got === $nc && array_unique(array_column($h, 'withheld')) === [false], 'the counts match the seven functions called directly');
ok(count($h['unmatched']['rows']) === 5 && $h['unmatched']['count'] >= 6 && count($h['lines_at_risk']['rows']) === min(5, $nc['lines_at_risk']), 'rows are capped at 5 a heading (the count is the whole)');
$h5 = call_fn(40, 'morning_note', [null, 2])['result']['headings'];
ok(count($h5['unmatched']['rows']) === 2, 'and rowsPerHeading changes the cap');
ok($h['purchase_orders']['awaiting_ack'] >= 1 && is_int($h['purchase_orders']['overdue']) && is_int($h['purchase_orders']['untracked']), 'purchase_orders carries awaiting_ack, overdue and untracked');
$md = $n['markdown'];
ok(str_starts_with($md, '# Morning note — ' . gmdate('Y-m-d')) && str_contains($md, 'At risk ' . $nc['lines_at_risk'] . ' · Reorder ' . $nc['reorder'] . ' · Prices ' . $nc['prices'] . ' · Unmatched ' . $nc['unmatched'] . ' · Sources ' . $nc['sources'] . ' · Purchase orders ' . $nc['purchase_orders'] . ' · Returns ' . $nc['returns']) && str_contains($md, "Needs a hand today:\n"), 'the markdown: the date line, the seven counts on one line, "Needs a hand today:"');
preg_match_all('/^- (.+)$/m', explode('Needs a hand today:', $md)[1] ?? '', $mm);
$items = array_slice($mm[1], 0, 5);
ok(count($items) === 5 && str_contains($items[0], 'is at risk') && str_contains(implode("\n", $items), 'Reorder SMOKE-NW-CR-T') === (count($items) > 1 && $nc['lines_at_risk'] < 5), 'five things, at risk first: ' . json_encode(array_map(static fn ($s) => mb_substr($s, 0, 40), $items)));
ok(str_contains($md, 'at cost ') , 'Nora sees cost, so the reorder line names the supplier\'s cost');
ok(!str_contains($md, '312-555') && !str_contains($md, 'Elm') && !str_contains($md, 'Alvarez') && !str_contains($md, 'Malouf') && !str_contains($md, 'Zinus'), 'no customer, no phone, no address — and no supplier\'s name beside them');

echo "3. As a salesperson\n";
$s = call_fn(41, 'morning_note', [null, 5])['result'];
$hs = $s['headings'];
ok($hs['reorder']['withheld'] === true && $hs['reorder']['count'] === null && $hs['reorder']['rows'] === [] && $hs['prices']['withheld'] === true && $hs['unmatched']['withheld'] === true, 'Sam: reorder, prices and unmatched are withheld (count null) — not a failure of the note');
$sc = $counts(41);
ok($hs['lines_at_risk']['withheld'] === false && $hs['lines_at_risk']['count'] === $sc['lines_at_risk'] && $hs['sources']['count'] === $sc['sources'] && $hs['returns']['count'] === $sc['returns'], 'lines at risk, sources, purchase orders and returns are there for him');
ok(str_contains($s['markdown'], 'Reorder —') && str_contains($s['markdown'], 'Prices —') && !str_contains($s['markdown'], 'at cost '), 'his markdown shows a dash for what is withheld and names no cost');
$v = call_fn(43, 'morning_note', [null, 5])['result'];
ok($v['headings']['reorder']['withheld'] === true && $v['headings']['lines_at_risk']['withheld'] === false, 'a Viewer reads the same as Sam');

echo "4. A day\n";
$old = call_fn(40, 'morning_note', ['2026-10-04', 5])['result'];
ok($old['date'] === '2026-10-04' && count($old['proposals']) === 1 && $old['proposals'][0]['title'] === 'S8 older note' && $old['drafted'] === [], 'morning_note(\'2026-10-04\') lists the proposals of that date only');
$old = call_fn(0, 'morning_note', ['2026-10-04', 5]);
ok(isset($old['result']) && $old['result']['proposals'] === [] || isset($old['error']), 'without anyone acting there is nothing to see');

echo "5. Sending the note\n";
$body = trim($n['markdown']);
$tok = run_token(47, 321);
$n0 = last_notification_id(); $o0 = last_outbox_id(); $since = last_activity_id();
[$c, $b] = act_token('/proposals/morning-note.php', ['body' => $body], as_agent($tok));
$bell = q("SELECT * FROM notifications WHERE id > :s AND kind = 'morning_note'", ['s' => $n0]);
$mail = outbox_rows($o0);
ok($c === 200 && $b['queued'] === true && (int) $b['to'] === 40 && count($bell) === 1 && (int) $bell[0]['member_id'] === 40 && $bell[0]['body'] === $body && $bell[0]['title'] === 'Morning note — ' . gmdate('Y-m-d'), 'the Buyer agent sends the note: queued for Nora, her bell holds it with the body');
ok(count($mail) === 1 && $mail[0]['channel'] === 'email' && $mail[0]['subject'] === 'Morning note — ' . gmdate('Y-m-d') && $mail[0]['dedupe_key'] === 'morning_note:' . gmdate('Y-m-d') . ':40:email', 'and an e-mail is queued: "Morning note — <date>"');
$lg = last_log('buyer.note', $since); $a = after_of($lg);
ok($lg !== null && $lg['source'] === 'agent' && $a['counts'] == $nc && (int) $a['sent_to'] === 40 && $a['sent_to_buyer'] === true && $a['queued'] === true && !str_contains($lg['after'], 'Needs a hand'), 'logged buyer.note (source agent) with the seven counts computed by the handler, sent_to and queued — not the body');
[$c, $b] = act_token('/proposals/morning-note.php', ['body' => $body], as_agent($tok));
ok($c === 200 && $b['queued'] === false && $b['did'] === 'already sent today' && count(q("SELECT id FROM notifications WHERE id > :s AND kind = 'morning_note' AND member_id = 40", ['s' => $n0])) === 1 && count(outbox_rows($o0)) === 1, 'sending again the same day: queued false, "already sent today", no second row');
[$c, $b] = act_token('/proposals/morning-note.php', ['body' => $body, 'to_member' => 41], as_agent($tok));
ok($c === 200 && $b['queued'] === true && count(notifications_for(41, 'morning_note', $n0)) === 1, 'to_member 41: Sam\'s bell gets it');
[$c, $b] = act($owner, '/proposals/morning-note.php', ['body' => 'A note from the admin', 'to_member' => 42]);
ok($c === 200 && $b['queued'] === true && count(notifications_for(42, 'morning_note', $n0)) === 1, 'the admin may send one');
[$c] = act($sam, '/proposals/morning-note.php', ['body' => 'x']);
ok($c === 403, 'Sam (a person without agents.settings): 403');
[$c] = act($nora, '/proposals/morning-note.php', ['body' => 'x']);
ok($c === 403, 'Nora, a person without agents.settings: 403 (the agent path is for an agent)');
[$c, $b] = act_token('/proposals/morning-note.php', ['body' => ''], as_agent($tok));
ok($c === 422 && isset(fields($b)['body']), 'an empty body: a field error');
[$c, $b] = act_token('/proposals/morning-note.php', ['body' => str_repeat('x', 20001)], as_agent($tok));
ok($c === 422 && isset(fields($b)['body']), 'a body over 20,000 characters: refused');
[$c, $b] = act_token('/proposals/morning-note.php', ['body' => 'x', 'to_member' => 999999], as_agent($tok));
ok($c === 422, 'a recipient who is not a member: refused');
psql_exec('UPDATE inv_settings SET buyer_member_id = NULL WHERE id = 1');
$n1 = last_notification_id();
[$c, $b] = act_token('/proposals/morning-note.php', ['body' => $body], as_agent($tok));
ok($c === 200 && (int) $b['to'] === 1 && count(notifications_for(1, 'morning_note', $n1)) === 1, 'with no Buyer set the note goes to the first super-admin (member 1)');
psql_exec("UPDATE members SET status = 'inactive' WHERE id = 1");
[$c, $b] = act_token('/proposals/morning-note.php', ['body' => $body], as_agent($tok));
ok($c === 422 && str_contains(msg($b), 'No Buyer is set'), 'with no super-admin either: 422 "No Buyer is set"');
psql_exec("UPDATE members SET status = 'active' WHERE id = 1; UPDATE inv_settings SET buyer_member_id = 40 WHERE id = 1");
psql_exec("UPDATE listing_variants SET availability = 'in_stock' WHERE id = {$w['lv_zinus_k']}");
finish();

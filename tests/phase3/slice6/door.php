<?php
/** The supplier's door (purchasing.md "Proof" ≈ 40): the page and what it never says, the three CSRF-protected POSTs and their refusals, the Buyer's and the salesperson's notices, a replayed token, an expired one, a cancelled order's, the limits. */
require __DIR__ . '/lib.php';
$w = purchasing_world();
$nora = $w['nora'];
$sam = $w['sam'];
$run = substr(md5((string) microtime(true)), 0, 6);
$D = dropship_pair($w, "S6-DOOR-$run");
$pm = $D['malouf'];
$pz = $D['zinus'];
$tm = $D['tok_malouf'];
$tz = $D['tok_zinus'];
$pmNo = po_row($pm)['number'];
$pzNo = po_row($pz)['number'];
$ordNo = order_row($D['order'])['number'];
$written = static fn (): int => (int) one("SELECT (SELECT count(*) FROM purchase_order_events) + (SELECT count(*) FROM activity_log WHERE action IN ('purchase_order.supplier_ack', 'purchase_order.supplier_decline', 'purchase_order.supplier_tracking'))");

// ---- the page
ok($tm !== null && $tz !== null && $tm !== $tz, 'the two purchase orders were sent by e-mail: a token each');
$r = req('GET', "/s/$tm");
$html = $r['body'];
$t = preg_replace('/\s+/', ' ', strip_tags($html));
ok($r['code'] === 200 && str_contains($html, 'name="robots" content="noindex, nofollow"') && preg_match('/^X-Robots-Tag: noindex/mi', $r['headers']) === 1, 'the live token answers 200 with noindex (the meta and the header)');
ok(str_contains($t, $pmNo) && str_contains($t, 'New — please acknowledge') && str_contains($t, 'Your account for us: DLR-1001') && str_contains($html, 'id="public-po-line-' . $D['ck_line'] . '"') && str_contains($t, 'NW-CR-CK') && str_contains($t, '1,299.00'), 'the number, "New — please acknowledge", the account number, the line with the supplier SKU and the agreed cost');
ok(str_contains($t, 'Ship to our customer:') && str_contains($t, '22 Elm Street') && str_contains($t, 'SMOKE Alvarez') && str_contains($t, 'Phone: 312-555-0188'), 'the ship-to is the customer\'s with the phone — the Cal King ships ltl');
$r2 = req('GET', "/s/$tz");
$t2 = preg_replace('/\s+/', ' ', strip_tags($r2['body']));
ok($r2['code'] === 200 && str_contains($t2, '22 Elm Street') && !str_contains($r2['body'], '312-555-0188'), '…and without the phone on the King\'s order (parcel)');
$lower = strtolower($html . $r2['body']);
ok(!str_contains($lower, 'internal') && !str_contains($lower, 'casper') && !str_contains($lower, 'smoke sam') && !str_contains($lower, 'source'), 'never anything internal, a competitor, the salesperson or a source (grep of both pages)');
ok(!str_contains($html, '<script') && !str_contains($html, 'htmx'), 'plain HTML: no script, no HTMX');
ok(str_contains($html, 'id="public-po-ack-form"') && str_contains($html, 'id="public-po-decline-form"') && str_contains($html, 'id="public-po-tracking-form"') && substr_count($html, 'name="csrf_token"') === 3 && substr_count($html, 'name="website"') === 3, 'the three forms, each with a CSRF field and a honeypot');
$vl = last_log('purchase_order.supplier_view');
ok($vl !== null && $vl['source'] === 'portal' && $vl['actor_member_id'] === null && (int) $vl['purchase_order_id'] === $pz && after_of($vl)['number'] === $pzNo && after_of($vl)['view_count'] >= 1, 'purchase_order.supplier_view is logged: source portal, no actor, the order, number and view count');
$dead = req('GET', '/s/' . str_repeat('a', 48));
ok($dead['code'] === 404 && str_contains($dead['body'], 'This link has expired. Ask the business for a new one.') && !str_contains($dead['body'], str_repeat('a', 48)) && req('GET', '/s/zzzz')['code'] === 404, 'an unknown or malformed token: the one dead page (404), the token never echoed');

// ---- acknowledge
$jarM = jar();
[$c, $page, $csrf] = door_get($tm, $jarM);
ok($c === 200 && strlen($csrf) === 64, 'the page opens the anonymous session and carries its CSRF token');
$e0 = $written();
$r = door_post($tm, 'acknowledge', ['supplier_ref' => 'M-5001', 'expected_on' => date('Y-m-d', strtotime('+8 days'))], $jarM, null);
ok($r['code'] === 403 && str_contains($r['body'], 'The form expired — reload the page.') && $written() === $e0 && po_row($pm)['status'] === 'sent', 'a POST without the CSRF token: 403 with the page "The form expired — reload the page." and nothing written');
$r = door_post($tm, 'acknowledge', ['supplier_ref' => 'M-5001', 'website' => 'http://spam.example'], $jarM, $csrf);
ok($r['code'] === 200 && $written() === $e0 && po_row($pm)['status'] === 'sent', 'the honeypot filled: 200, the page unchanged, nothing written, nothing logged');
$nsince = last_notification_id();
$since = last_activity_id();
$ackDate = date('Y-m-d', strtotime('+8 days'));
$r = door_post($tm, 'acknowledge', ['supplier_ref' => 'M-5001', 'expected_on' => $ackDate, 'line' => ''], $jarM, $csrf);
ok($r['code'] === 200 && str_contains($r['body'], 'id="public-po-flash"') && str_contains($r['body'], 'Acknowledged — thank you.') && po_row($pm)['status'] === 'acknowledged' && po_lines_of($pm)[0]['status'] === 'acknowledged' && po_row($pm)['supplier_order_ref'] === 'M-5001', 'acknowledge the whole order: 200, the success box, the order and its line acknowledged, their reference kept');
$ev = array_values(array_filter(po_events_of($pm), static fn ($e) => $e['kind'] === 'acknowledge'));
ok(count($ev) === 1 && $ev[0]['source'] === 'portal' && $ev[0]['member_id'] === null && $ev[0]['supplier_order_ref'] === 'M-5001', 'the `acknowledge` event: source portal, member_id null');
$al = last_log('purchase_order.supplier_ack', $since);
ok($al !== null && $al['source'] === 'portal' && $al['actor_member_id'] === null && (int) $al['purchase_order_id'] === $pm && after_of($al)['supplier_order_ref'] === 'M-5001' && !str_contains((string) $al['after'], $tm), 'purchase_order.supplier_ack is logged: source portal, no actor, the reference — never the token');
$n = notifications_for(40, 'po_ack', $nsince);
ok(count($n) === 1 && $n[0]['title'] === "$pmNo acknowledged by SMOKE Malouf — ref M-5001, expected " . date('M j, Y', strtotime($ackDate)), 'Nora (the Buyer) is told: "PO-… acknowledged by SMOKE Malouf — ref M-5001, expected …"');
$r = req('GET', "/purchasing/$pm", ['jar' => $nora]);
ok(str_contains($r['body'], 'by the supplier') && str_contains($r['body'], 'M-5001'), 'the Buyer\'s page shows the event with its "by the supplier" chip');

// ---- decline
$e0 = $written();
$r = door_post($tm, 'decline', ['line' => (string) $D['k_line'], 'reason' => 'not mine'], $jarM, $csrf);
ok($r['code'] === 422 && str_contains($r['body'], 'That line is not on this purchase order.') && str_contains($r['body'], 'alert-danger') && $written() === $e0, 'a line of another order: the danger box, 422, nothing written');
$r = door_post($tm, 'decline', ['line' => (string) $D['ck_line'], 'reason' => ''], $jarM, $csrf);
ok($r['code'] === 422 && str_contains($r['body'], 'Say why you cannot fill the line.') && $written() === $e0, 'no reason: the danger box naming the field');
$r = door_post($tm, 'decline', ['line' => '', 'reason' => 'x'], $jarM, $csrf);
ok($r['code'] === 422 && str_contains($r['body'], 'Choose the line.'), 'no line chosen: the danger box');
$nsince = last_notification_id();
$r = door_post($tm, 'decline', ['line' => (string) $D['ck_line'], 'reason' => 'Out of the mill run'], $jarM, $csrf);
$sl = q('SELECT * FROM sales_order_lines WHERE id = :id', ['id' => po_lines_of($pm)[0]['sales_order_line_id']])[0];
ok($r['code'] === 200 && str_contains($r['body'], 'Line 1 declined.') && po_lines_of($pm)[0]['status'] === 'declined' && $sl['status'] === 'open', 'decline line 1 with a reason: 200, "Line 1 declined.", the line declined, the customer\'s line open again');
$nb = notifications_for(40, 'po_decline', $nsince);
$ns = notifications_for(41, 'line_at_risk', $nsince);
ok(count($nb) === 1 && $nb[0]['title'] === "SMOKE Malouf declined line 1 of $pmNo: Out of the mill run" && count($ns) === 1 && str_contains($ns[0]['title'], "was declined by SMOKE Malouf: Out of the mill run") && (int) $ns[0]['record_id'] === $D['order'], 'Nora\'s `po_decline` and Sam\'s `line_at_risk` (the salesperson) are queued');
$dl = q("SELECT * FROM activity_log WHERE action = 'purchase_order.supplier_decline' AND purchase_order_id = :p ORDER BY id DESC LIMIT 1", ['p' => $pm])[0];
ok($dl['source'] === 'portal' && $dl['actor_member_id'] === null && (int) $dl['sales_order_id'] === $D['order'], 'purchase_order.supplier_decline is logged: source portal, no actor');
$r = door_post($tm, 'tracking', ['line' => (string) $D['ck_line'], 'carrier' => 'UPS', 'tracking' => '1ZNO'], $jarM, $csrf);
ok($r['code'] === 422 && str_contains($r['body'], 'is declined') && str_contains($r['body'], 'alert-danger'), 'tracking on the declined line: the SQL\'s sentence in the danger box');

// ---- tracking (the King, on Zinus' door)
$jarZ = jar();
[, , $csrfZ] = door_get($tz, $jarZ);
$e0 = $written();
$r = door_post($tz, 'tracking', ['line' => (string) $D['k_line'], 'carrier' => 'UPS', 'tracking' => ''], $jarZ, $csrfZ);
ok($r['code'] === 422 && str_contains($r['body'], 'Give the tracking number.') && $written() === $e0, 'tracking without a number: the danger box');
$r = door_post($tz, 'tracking', ['line' => (string) $D['k_line'], 'carrier' => 'UPS', 'tracking' => '1Z777', 'shipped_at' => 'when it suits'], $jarZ, $csrfZ);
ok($r['code'] === 422 && str_contains($r['body'], 'The ship date is a date and time.') && $written() === $e0, 'a ship date that is not a date: the danger box');
$nsince = last_notification_id();
$r = door_post($tz, 'tracking', ['line' => (string) $D['k_line'], 'carrier' => 'UPS', 'tracking' => '1Z777', 'shipped_at' => date('Y-m-d\TH:i', strtotime('-2 hours'))], $jarZ, $csrfZ);
$sh = q("SELECT * FROM shipments WHERE sales_order_id = :o AND kind = 'dropship' AND tracking_number = '1Z777'", ['o' => $D['order']]);
ok($r['code'] === 200 && str_contains($r['body'], 'Tracking added for line 1.') && po_lines_of($pz)[0]['status'] === 'shipped' && count($sh) === 1 && $sh[0]['tracking_url'] === 'https://www.ups.com/track?tracknum=1Z777', 'tracking on line 1: 200, "Tracking added for line 1.", the line shipped, the customer\'s `dropship` shipment with its tracking link');
$nt = notifications_for(40, 'po_tracking', $nsince);
ok(count($nt) === 1 && $nt[0]['title'] === "SMOKE Zinus shipped line 1 of $pzNo: UPS 1Z777", 'Nora\'s `po_tracking`: "SMOKE Zinus shipped line 1 of PO-…: UPS 1Z777"');
$ev = array_values(array_filter(po_events_of($pz), static fn ($e) => $e['kind'] === 'tracking'));
ok(count($ev) === 1 && $ev[0]['source'] === 'portal' && $ev[0]['member_id'] === null, 'the `tracking` event: source portal, member_id null');
$vc = (int) q('SELECT view_count FROM supplier_links_secure WHERE purchase_order_id = :p AND rotated_at IS NULL', ['p' => $pz])[0]['view_count'];
ok($vc >= 2, 'the link counts its views (' . $vc . ')');

// ---- after the order is closed the forms are gone
act($nora, '/purchasing/close.php', ['purchase_order' => $pz]);
$r = req('GET', "/s/$tz");
$t = preg_replace('/\s+/', ' ', strip_tags($r['body']));
ok($r['code'] === 200 && str_contains($t, 'nothing more to do here') && !str_contains($r['body'], 'id="public-po-ack-form"') && str_contains($t, 'Closed'), 'a closed order: "…nothing more to do here", no forms, the status "Closed"');
$e0 = $written();
$r = door_post($tz, 'acknowledge', ['supplier_ref' => 'late'], $jarZ, $csrfZ);
ok($r['code'] === 422 && str_contains($r['body'], 'nothing more to do here') && $written() === $e0, 'a POST to a closed order: refused in the same words, nothing written');

// ---- a replayed token: rotated by the Buyer, the new link mailed
[$pr, $tr] = sent_po($w, $w['zinus'], [['variant' => $w['pillow_v'], 'qty' => 3, 'unit_cost' => '20']], "S6-ROT-$run");
$jarR = jar();
[, , $csrfR] = door_get($tr, $jarR);
$n = count(mail_log());
$since = last_activity_id();
[$c, $b] = act($nora, '/purchasing/link-rotate.php', ['purchase_order' => $pr]);
$mails = mail_log();
$rm = end($mails);
$tr2 = token_from_po_mail($rm);
ok($c === 200 && $b['mailed'] === true && count($mails) === $n + 1 && $rm['subject'] === 'Updated link for ' . po_row($pr)['number'] && $rm['to'] === 'dealers@zinus.example.invalid' && $tr2 !== null && $tr2 !== $tr && str_contains($rm['text'], 'no longer works'), 'purchase_order_link_rotate on a sent order: the new link is MAILED at once ("Updated link for PO-…", the old one named as dead)');
$e0 = $written();
$codes = [req('GET', "/s/$tr")['code']];
foreach (['acknowledge' => ['supplier_ref' => 'R'], 'decline' => ['line' => (string) po_lines_of($pr)[0]['id'], 'reason' => 'x'], 'tracking' => ['line' => (string) po_lines_of($pr)[0]['id'], 'carrier' => 'UPS', 'tracking' => '1']] as $do => $f) {
    $codes[] = door_post($tr, $do, $f, $jarR, $csrfR)['code'];
}
ok($codes === [404, 404, 404, 404] && $written() === $e0, 'the OLD token: 404 on the GET and on each of the three POSTs, nothing written');
$jar2 = jar();
[$c2, , $csrf2] = door_get($tr2, $jar2);
ok($c2 === 200, 'the new link opens');
$rl = last_log('purchase_order.link_rotate', $since);
ok($rl !== null && after_of($rl)['mailed'] === true && !str_contains((string) $rl['after'], $tr2) && !str_contains((string) $rl['after'], $tr), 'purchase_order.link_rotate is logged with `mailed` — never a token');
ok((int) one("SELECT count(*) FROM activity_log WHERE after::text ~ '[a-f0-9]{48}'") === 0, 'no activity row anywhere carries a 48-hex token');
// a draft's rotation keeps no live link
[, $b] = make_po($nora, $w['zinus'], [['variant' => $w['pillow_v'], 'qty' => 1, 'unit_cost' => '20']]);
$dr = (int) $b['record_id'];
[$c, $b] = act($nora, '/purchasing/link-rotate.php', ['purchase_order' => $dr]);
ok($c === 200 && $b['mailed'] === false && (int) one('SELECT count(*) FROM supplier_links_secure WHERE purchase_order_id = :p AND rotated_at IS NULL', ['p' => $dr]) === 0, 'rotating a draft\'s link mails nothing and leaves no live link');
// a supplier with no address: refused before anything changes
$nm = $w['nomail'];
[, $b] = make_po($nora, $nm, [['variant' => $w['pillow_v'], 'qty' => 1, 'unit_cost' => '20']]);
$nmp = (int) $b['record_id'];
act($nora, '/purchasing/send.php', ['purchase_order' => $nmp, 'via' => 'phone']);
[$c, $b] = act($nora, '/purchasing/link-rotate.php', ['purchase_order' => $nmp]);
ok($c === 422 && msg($b) === 'The supplier has no email address — the old link is gone; there is nowhere to send the new one.' && (int) one('SELECT count(*) FROM supplier_links_secure WHERE purchase_order_id = :p', ['p' => $nmp]) === 0, 'rotate a sent order of a supplier with no address: 422 before anything changes (no link row made)');
[$c, $b] = act($nora, '/purchasing/link-rotate.php', ['purchase_order' => $pz]);
ok($c === 422 && str_contains(msg($b), 'its link is not rotated'), 'rotate the link of a closed order: 422 in words');
[$c, $b] = act($sam, '/purchasing/link-rotate.php', ['purchase_order' => $pr]);
ok($c === 403, 'purchase_order_link_rotate: Sam 403');

// ---- an expired token
[$px, $tx] = sent_po($w, $w['zinus'], [['variant' => $w['pillow_v'], 'qty' => 1, 'unit_cost' => '20']], "S6-EXP-$run");
act($nora, '/purchasing/close.php', ['purchase_order' => $px]);
ok(req('GET', "/s/$tx")['code'] === 200, 'the closed order\'s link still opens (its 90 days run)');
psql_exec("UPDATE supplier_links_secure SET expires_at = now() - interval '1 minute' WHERE purchase_order_id = $px AND rotated_at IS NULL");
ok(req('GET', "/s/$tx")['code'] === 404, 'with expires_at in the past: 404');
$rep = worker('links_expire');
ok(q('SELECT rotated_at FROM supplier_links_secure WHERE purchase_order_id = :p', ['p' => $px])[0]['rotated_at'] !== null, 'the worker\'s links_expire pass marks it rotated (slice 5\'s pass covers the supplier\'s links too)');
// a cancelled order's link
[$pc, $tc] = sent_po($w, $w['zinus'], [['variant' => $w['pillow_v'], 'qty' => 1, 'unit_cost' => '20']], "S6-CAN-$run");
ok(req('GET', "/s/$tc")['code'] === 200, 'a sent order\'s link opens');
act($nora, '/purchasing/cancel.php', ['purchase_order' => $pc, 'reason' => 'door test']);
ok(req('GET', "/s/$tc")['code'] === 404, 'a cancelled order\'s link: 404 at once');

// ---- the limits
[$pl, $tl] = sent_po($w, $w['zinus'], [['variant' => $w['pillow_v'], 'qty' => 1, 'unit_cost' => '20']], "S6-LIM-$run");
$codes = [];
for ($i = 1; $i <= 61; $i++) { $codes[$i] = req('GET', "/s/$tl")['code']; }
ok(count(array_filter(array_slice($codes, 0, 60, true), static fn ($x) => $x === 200)) === 60 && $codes[61] === 429, '60 views in an hour are served; the 61st is 429');
$r = req('GET', "/s/$tl");
ok($r['code'] === 429 && str_contains($r['body'], 'Too many requests — try again in a few minutes.') && (int) one("SELECT count(*) FROM activity_log WHERE action = 'purchase_order.supplier_view' AND purchase_order_id = :p", ['p' => $pl]) === 60, '…in words; the refused views are not logged');
[$pp, $tp] = sent_po($w, $w['zinus'], [['variant' => $w['pillow_v'], 'qty' => 1, 'unit_cost' => '20']], "S6-LIMP-$run");
$jp = jar();
[, , $cp] = door_get($tp, $jp);
$codes = [];
for ($i = 1; $i <= 31; $i++) { $codes[$i] = door_post($tp, 'acknowledge', ['supplier_ref' => "L-$i"], $jp, $cp)['code']; }
ok(count(array_filter(array_slice($codes, 0, 30, true), static fn ($x) => $x === 200)) === 30 && $codes[31] === 429, '30 POSTs in an hour are served; the 31st is 429');
psql_exec("INSERT INTO activity_log (action, source, purchase_order_id, ip_address) SELECT 'purchase_order.supplier_view', 'portal', 777777777, '203.0.113.9' FROM generate_series(1, 300)");
$cli = static fn (string $fn, string $ip): string => trim((string) shell_exec('cd ' . escapeshellarg(dirname(__DIR__, 3)) . ' && php -r ' . escapeshellarg('require "app/bootstrap.php"; require "app/features/purchasing/handler.php"; require "app/features/public/purchase_order.php"; echo ' . $fn . '(db(), 888888888, "' . $ip . '") ? "yes" : "no";')));
ok($cli('door_view_rate_ok', '203.0.113.9') === 'no' && $cli('door_view_rate_ok', '203.0.113.10') === 'yes', 'door_view_rate_ok(): 300 views from one address in an hour close the door to it, another address is not affected');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
ok($reg['screens']['supplier-door']['built'] === true && !isset($reg['actions']['door_acknowledge']), 'the registry: the door is a built screen; its three POSTs are not actions');
finish();

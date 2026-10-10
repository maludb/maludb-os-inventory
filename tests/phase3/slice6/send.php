<?php
/** Send and place (purchasing.md "Proof" ≈ 30): the preview and what the e-mail says and never says, the customer's address and the phone by shipping kind, the refusals of MaluMail and of a missing address or key, sent by phone, placed on a portal. */
require __DIR__ . '/lib.php';
$w = purchasing_world();
$nora = $w['nora'];
$sam = $w['sam'];
$wes = $w['wes'];
$run = substr(md5((string) microtime(true)), 0, 6);
$since = last_activity_id();

// ---- a stock order for Zinus, with internal notes that must never leave
$exp = date('Y-m-d', strtotime('+12 days'));
[, $b] = make_po($nora, $w['zinus'], [['variant' => $w['twin'], 'qty' => 2], ['variant' => $w['king'], 'qty' => 1]], ['notes' => "Dock door 4 — S6-SEND-$run", 'internal_notes' => "INTERNAL-ONLY-$run", 'expected_on' => $exp, 'shipping_cost' => '15']);
$po = (int) $b['record_id'];
$num = po_row($po)['number'];
$r = req('GET', "/purchasing/$po/send", ['jar' => $nora]);
$t = preg_replace('/\s+/', ' ', strip_tags($r['body']));
ok($r['code'] === 200 && str_contains($r['body'], 'id="po-send-preview"') && str_contains($r['body'], 'id="po-send-message"') && str_contains($r['body'], 'id="po-send-submit"') && str_contains($r['body'], 'id="po-place-form"') && !preg_match('#/s/[a-f0-9]{48}#', $r['body']), 'the send screen previews the e-mail (message box, Send by email, Mark placed) and shows no token');
ok(str_contains($t, "Purchase order $num from SMOKE Business") && str_contains($t, 'NW-CR-T') && str_contains($t, 'NW-CR-K') && str_contains($t, 'Your account for us: ZN-5005') && str_contains($t, 'Dock door 4') && str_contains($t, 'SMOKE Warehouse') && !str_contains($t, 'INTERNAL-ONLY'), 'the preview carries the lines with the supplier SKUs, the account number, the notes and the ship-to — and not the internal notes');
$r = req('GET', "/purchasing/$po/send", ['jar' => $sam]);
ok($r['code'] === 403, 'the send screen is Sam\'s 403');
[$c, $b] = act($sam, '/purchasing/send.php', ['purchase_order' => $po]);
ok($c === 403, 'purchase_order_send: Sam 403');
[$c, $b] = act($wes, '/purchasing/send.php', ['purchase_order' => $po]);
ok($c === 403, '…and Wes (warehouse) 403');

// ---- the first send
$n = count(mail_log());
[$c, $b] = act($nora, '/purchasing/send.php', ['purchase_order' => $po, 'message' => 'Please ship to door 4.']);
$mails = mail_log();
$mail = end($mails);
$row = po_row($po);
ok($c === 200 && count($mails) === $n + 1 && (int) $b['link_id'] > 0 && $b['sent_via'] === 'email' && str_starts_with((string) $b['message_id'], '<mm-') && $b['refresh'] === 'purchaseOrderChanged', 'purchase_order_send: one MaluMail call; the link id, sent_via email and the message id in the answer');
ok($mail['to'] === 'dealers@zinus.example.invalid' && $mail['subject'] === "Purchase order $num from SMOKE Business" && ($mail['reply_to'] ?? '') === 'hello@smoke-business.example.invalid' && ($mail['from'] ?? '') !== '', 'to the order e-mail of the supplier, the subject, the business contact as reply_to');
$tok = token_from_po_mail($mail);
ok($tok !== null && substr_count((string) $mail['text'], '/s/' . $tok) === 1 && str_contains((string) $mail['html'], '/s/' . $tok), 'the text carries /s/<48 hex> once (and the html the same link)');
$all = strtolower($mail['text'] . ' ' . $mail['html']);
ok(str_contains($mail['text'], 'Please ship to door 4.') && str_contains($mail['text'], 'Your account for us: ZN-5005') && str_contains($mail['text'], 'NW-CR-T (our SMOKE-NW-CR-T)') && str_contains($mail['text'], 'SMOKE Warehouse') && str_contains($mail['text'], 'Dock door 4'), 'the sender\'s words, the account number, the supplier SKU beside ours, the Warehouse as the ship-to, the notes to the supplier');
ok(!str_contains($all, 'internal') && !str_contains($all, 'only-' . strtolower($run)), 'the e-mail never carries the internal notes (no "internal" anywhere)');
ok($row['status'] === 'sent' && $row['sent_via'] === 'email' && (int) $row['sent_by'] === 40 && $row['sent_at'] !== null && (int) $row['approved_by'] === 40, 'the order is sent: status, sent_via email, sent_by Nora, sent_at, approved_by');
$ev = po_events_of($po);
ok(count($ev) === 1 && $ev[0]['kind'] === 'sent' && (int) $ev[0]['member_id'] === 40, 'one `sent` event, by Nora');
$log = last_log('purchase_order.send', $since);
$a = after_of($log);
ok($log !== null && (int) $log['purchase_order_id'] === $po && (int) $log['location_id'] === $w['wh'] && $a['sent_via'] === 'email' && (int) $a['link_id'] === (int) $b['link_id'] && $a['number'] === $num && !str_contains((string) $log['after'], 'example.invalid') && !str_contains((string) $log['after'], $tok), 'purchase_order.send is logged with link_id and sent_via — never the address, never the token');
[, $d] = screen($nora, "/purchasing/$po");
ok($d['purchase_order']['link']['is_live'] === true && $d['purchase_order']['link']['view_count'] === 0, 'the link card says live, 0 views');
$r = req('GET', "/purchasing/$po", ['jar' => $nora]);
ok(str_contains($r['body'], 'id="po-link"') && str_contains($r['body'], 'id="po-link-state"') && !str_contains($r['body'], $tok), 'the purchase-order page has the link card and never the token');
[$c, $b] = act($nora, '/purchasing/send.php', ['purchase_order' => $po]);
ok($c === 422 && msg($b) === "Purchase order $num is sent — only a draft is sent", 'sending twice: the SQL\'s sentence, 422');

// ---- the drop-ships: the customer's address, and the phone only for the shipping kinds the setting names
$pm = $w['po_malouf'];
$pz = $w['po_zinus'];
$sam41 = q('SELECT status FROM sales_order_lines WHERE purchase_order_line_id IN (SELECT id FROM purchase_order_lines WHERE purchase_order_id = :p)', ['p' => $pm]);
ok($sam41[0]['status'] === 'open', 'before the send, the customer\'s Cal King line is open');
[$c, $b] = act($nora, '/purchasing/send.php', ['purchase_order' => $pm]);
$mm = last_mail();
ok($c === 200 && $mm['to'] === 'orders@malouf.example.invalid' && str_contains($mm['text'], 'Ship to our customer:') && str_contains($mm['text'], '22 Elm Street') && str_contains($mm['text'], 'SMOKE Alvarez') && str_contains($mm['text'], 'Phone: 312-555-0188'), 'Malouf\'s drop-ship (the Cal King ships ltl): the customer\'s name and address AND the phone');
[$c, $b] = act($nora, '/purchasing/send.php', ['purchase_order' => $pz]);
$mz = last_mail();
ok($c === 200 && $mz['to'] === 'dealers@zinus.example.invalid' && str_contains($mz['text'], 'Ship to our customer:') && str_contains($mz['text'], '22 Elm Street') && !str_contains($mz['text'], '312-555-0188') && !str_contains((string) $mz['html'], '312-555-0188'), 'Zinus\' drop-ship (the King ships parcel): the address and NO phone');
ok(!str_contains(strtolower($mm['text'] . $mm['html'] . $mz['text'] . $mz['html']), 'internal'), 'neither mentions anything internal');
$lines = q("SELECT status FROM sales_order_lines WHERE purchase_order_line_id IN (SELECT id FROM purchase_order_lines WHERE purchase_order_id IN (:a, :b))", ['a' => $pm, 'b' => $pz]);
ok(count($lines) === 2 && $lines[0]['status'] === 'ordered' && $lines[1]['status'] === 'ordered', 'the customer\'s two drop-ship lines became `ordered`');
$sl = last_log('purchase_order.send', $since);
ok($sl !== null && $sl['sales_order_id'] !== null && !str_contains((string) $sl['after'], 'Elm') && !str_contains((string) $sl['after'], '312-555'), 'the drop-ship\'s log row carries the sales order and never the customer\'s address or phone');

// ---- MaluMail's refusals, an address that is not there
$mk = static function (int $supplier, string $tag) use ($nora, $w): int { [, $b] = make_po($nora, $supplier, [['variant' => $w['pillow_v'], 'qty' => 1, 'unit_cost' => '20']], ['notes' => "S6-$tag"]); return (int) $b['record_id']; };
$nlinks = static fn (int $p): int => (int) one('SELECT count(*) FROM supplier_links_secure WHERE purchase_order_id = :p', ['p' => $p]);
$s1 = $mk($w['suppressed'], "SUPP-$run");
[$c, $b] = act($nora, '/purchasing/send.php', ['purchase_order' => $s1]);
ok($c === 422 && str_contains(msg($b), 'MaluMail refused the address') && $nlinks($s1) === 0 && po_row($s1)['status'] === 'draft' && count(po_events_of($s1)) === 0, 'a suppressed address: 422 "MaluMail refused the address…", no link row, the order still a draft (the rollback)');
$s2 = $mk($w['flaky'], "FLAKY-$run");
[$c, $b] = act($nora, '/purchasing/send.php', ['purchase_order' => $s2]);
ok($c === 503 && msg($b) === 'Mail could not be sent — try again.' && $nlinks($s2) === 0 && po_row($s2)['status'] === 'draft', 'a transport error: 503 "Mail could not be sent — try again.", nothing changed');
$s3 = $mk($w['nomail'], "NOMAIL-$run");
$n = count(mail_log());
[$c, $b] = act($nora, '/purchasing/send.php', ['purchase_order' => $s3]);
ok($c === 422 && msg($b) === 'The supplier has no email address — mark it placed or sent by phone.' && $nlinks($s3) === 0 && po_row($s3)['status'] === 'draft' && count(mail_log()) === $n, 'a supplier with no address: 422 "…mark it placed or sent by phone." and nothing changed');
$r = req('GET', "/purchasing/$s3/send", ['jar' => $nora]);
ok(str_contains($r['body'], 'id="po-send-phone"') && str_contains($r['body'], 'no email address on file'), 'its send screen offers "Sent by phone" (the supplier orders by phone) and says there is no address');
[$c, $b] = act($nora, '/purchasing/send.php', ['purchase_order' => $s3, 'via' => 'phone']);
ok($c === 200 && $b['sent_via'] === 'phone' && $b['link_id'] === null && po_row($s3)['status'] === 'sent' && po_row($s3)['sent_via'] === 'phone' && $nlinks($s3) === 0 && count(mail_log()) === $n, 'sent by phone: sent, nothing mailed, no link minted');
[$c, $b] = act($nora, '/purchasing/send.php', ['purchase_order' => $s1, 'via' => 'carrier pigeon']);
ok($c === 422 && isset(fields($b)['via']), 'an unknown way to send: a field error');
// no key: a second application server with MALUMAIL_API_KEY blank
$pid = (int) shell_exec('cd ' . escapeshellarg(dirname(__DIR__, 3)) . ' && MALUMAIL_API_KEY= nohup php -S 127.0.0.1:8604 -t html tests/dev_router.php >/dev/null 2>&1 & echo $!');
for ($i = 0; $i < 30; $i++) { if (@file_get_contents('http://127.0.0.1:8604/api/v1/health') !== false) { break; } usleep(200000); }
$j = jar();
req('GET', 'http://127.0.0.1:8604' . handoff(40), ['jar' => $j]);
$ctok = csrf_of(req('GET', 'http://127.0.0.1:8604/', ['jar' => $j])['body']);
$r = req('POST', 'http://127.0.0.1:8604/purchasing/send.php', ['jar' => $j, 'headers' => JSONH, 'form' => ['purchase_order' => $s1, 'csrf_token' => $ctok]]);
$body = json_decode($r['body'], true) ?? [];
if ($pid > 0) { posix_kill($pid, SIGTERM); }
ok($r['code'] === 503 && msg($body) === "Email is not configured — the installer's mail step writes the key." && $nlinks($s1) === 0, 'without MALUMAIL_API_KEY: 503 "Email is not configured — the installer\'s mail step writes the key." and no link');

// ---- place: on their portal, with their reference
$pl = $mk($w['malouf'], "PLACE-$run");
$n = count(mail_log());
$since2 = last_activity_id();
[$c, $b] = act($nora, '/purchasing/place.php', ['purchase_order' => $pl]);
ok($c === 422 && isset(fields($b)['supplier_ref']), 'place without their reference: 422');
[$c, $b] = act($nora, '/purchasing/place.php', ['purchase_order' => $pl, 'supplier_ref' => 'M-88213']);
$row = po_row($pl);
ok($c === 200 && $row['status'] === 'sent' && $row['supplier_order_ref'] === 'M-88213' && $row['sent_via'] === 'portal' && (int) $row['sent_by'] === 40 && count(mail_log()) === $n && $nlinks($pl) === 0, 'purchase_order_place on a draft: sent, their reference M-88213, via portal; no mail, no link');
$kinds = array_column(po_events_of($pl), 'kind');
ok($kinds === ['sent', 'placed'], 'the events: `sent` then `placed`');
$pg = last_log('purchase_order.place', $since2);
ok($pg !== null && after_of($pg)['supplier_order_ref'] === 'M-88213' && after_of($pg)['sent_via'] === 'portal' && (int) $pg['purchase_order_id'] === $pl, 'purchase_order.place is logged with the reference and the way');
[$c, $b] = act($nora, '/purchasing/acknowledge.php', ['purchase_order' => $pl, 'supplier_ref' => 'M-88213']);
[$c, $b] = act($nora, '/purchasing/place.php', ['purchase_order' => $pl, 'supplier_ref' => 'M-1']);
ok($c === 422 && str_contains(msg($b), 'is acknowledged — a sent order is placed'), 'place on an acknowledged order: the SQL\'s sentence');
[$c, $b] = act($sam, '/purchasing/place.php', ['purchase_order' => $mk($w['malouf'], "PLACE2-$run"), 'supplier_ref' => 'x']);
ok($c === 403, 'purchase_order_place: Sam 403');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
ok($reg['actions']['purchase_order_send']['approval'] === 'money_out' && $reg['actions']['purchase_order_place']['approval'] === 'money_out', 'purchase_order_send and purchase_order_place are money_out in the registry');
finish();

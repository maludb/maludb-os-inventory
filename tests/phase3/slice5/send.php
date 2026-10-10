<?php
/** Send, the link and the door (orders.md "Proof" ≈ 35): the confirmation e-mail and what it never says, the refusals of MaluMail and of a missing key, rotation, the customer's page, its limits, the notices. */
require __DIR__ . '/lib.php';
$w = order_world();
$sam = $w['sam'];
$main = ref_order('S5-MAIN');
$since = last_activity_id();
$bal = number_format((float) order_row($main)['total'] - (float) order_row($main)['amount_paid'], 2);      // the balance due (the deposit of 1,000 is paid)

// ---- send needs a confirmed order, an address and orders.send
[$c, $b] = make_quote($sam, $w['alvarez'], [['variant' => $w['pillow_v'], 'qty' => 1, 'fulfilment_kind' => 'backorder', 'location' => $w['wh']]], ['customer_reference' => 'S5-QUOTE']);
$qid = (int) $b['record_id'];
$n = count(mail_log());
[$c, $b] = act($sam, '/orders/send.php', ['order' => $qid]);
ok($c === 422 && msg($b) === 'Confirm it first — a quote is not sent in v1.' && count(mail_log()) === $n, 'send on a quote: 422 "Confirm it first — a quote is not sent in v1." and nothing goes out');
[$c, $b] = act($w['wes'], '/orders/send.php', ['order' => $main]);
ok($c === 403 && count(mail_log()) === $n, 'Wes (no orders.send): 403');
[$c, $b] = act($w['vera'], '/orders/send.php', ['order' => $main]);
ok($c === 403, 'Vera: 403');
// the screen
$r = req('GET', "/orders/$main/send", ['jar' => $sam]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="order-send-preview"') && str_contains($r['body'], 'id="order-send-message"') && str_contains($r['body'], 'id="order-send-submit"') && !preg_match('#/o/[a-f0-9]{48}#', $r['body']) && str_contains($r['body'], 'Your order ' . order_row($main)['number'] . ' from SMOKE Business'), 'the send screen previews the e-mail (subject, message box, Send) and shows no token');

// ---- the first send
[$c, $b] = act($sam, '/orders/send.php', ['order' => $main, 'message' => 'Thanks for visiting the showroom!']);
$mails = mail_log();
$mail = end($mails);
ok($c === 200 && count($mails) === $n + 1 && $b['rotated'] === false && (int) $b['link_id'] > 0, 'order_send on the confirmed order: one MaluMail call; rotated false; the link id in the answer');
$num = order_row($main)['number'];
ok(($mail['subject'] ?? '') === "Your order $num from SMOKE Business" && $mail['to'] === $w['alvarez_email'] && ($mail['reply_to'] ?? '') === 'hello@smoke-business.example.invalid' && ($mail['from'] ?? '') !== '', 'the subject, the customer\'s address and the business contact as reply_to');
$tok1 = token_from_mail($mail);
ok($tok1 !== null && substr_count((string) $mail['text'], '/o/' . $tok1) === 1 && str_contains((string) $mail['html'], '/o/' . $tok1), 'the text carries /o/<48 hex> once (and the html the same link)');
$all = strtolower($mail['text'] . ' ' . $mail['html']);
ok(str_contains($mail['text'], '$999.00') && str_contains($mail['text'], 'Balance due: $' . $bal) && str_contains($mail['text'], 'Thanks for visiting the showroom!'), 'the lines at retail, the balance due and the sender\'s words');
ok(!str_contains($all, 'malouf') && !str_contains($all, 'zinus') && !str_contains($all, 'cost') && !str_contains($all, 'source') && !str_contains($all, 'supplier') && !str_contains($all, 'smoke sam'), 'the e-mail never says Malouf, Zinus, cost, source, supplier or the salesperson');
$log = last_log('order.send', $since);
$a = after_of($log);
ok($log !== null && (int) $log['sales_order_id'] === $main && $a['rotated'] === false && (int) $a['link_id'] === (int) $b['link_id'] && $a['to'] === "the customer's email" && !str_contains((string) $log['after'], 'example.invalid') && !str_contains((string) $log['after'], $tok1), 'order.send is logged with link_id and rotated false — never the address, never the token');
[$cc, $d] = screen($sam, "/orders/$main");
ok($d['order']['link']['is_live'] === true && $d['order']['link']['view_count'] === 0, 'the link card says live, 0 views');
$r = req('GET', "/orders/$main", ['jar' => $sam]);
ok(str_contains($r['body'], 'id="order-link"') && str_contains($r['body'], 'id="order-link-state"') && !str_contains($r['body'], $tok1), 'the order page has the link card and never the token');

// ---- the door
$r = req('GET', "/o/$tok1");
$t = preg_replace('/\s+/', ' ', strip_tags($r['body']));
ok($r['code'] === 200 && str_contains($r['body'], 'name="robots" content="noindex, nofollow"') && preg_match('/^X-Robots-Tag: noindex/mi', $r['headers']) === 1, 'the live token answers 200 with noindex (the meta and the header)');
ok(str_contains($t, $num) && str_contains($t, 'Confirmed') && str_contains($t, 'from stock') && substr_count($t, 'ships from our supplier') === 2 && str_contains($t, 'backordered'), 'the number, "Confirmed", and the lines: "from stock", "ships from our supplier" twice, "backordered"');
ok(str_contains($t, 'Balance due') && str_contains($t, '$' . $bal) && str_contains($t, 'SMOKE Alvarez') && str_contains($t, '22 Elm Street') && str_contains($r['body'], 'mailto:hello@smoke-business.example.invalid?subject=' . rawurlencode("Order $num")), 'the totals and "Balance due", the ship-to name and address, the mailto');
$lower = strtolower($r['body']);
ok(!str_contains($r['body'], '312-555-0188') && !str_contains($lower, 'malouf') && !str_contains($lower, 'zinus') && !str_contains($lower, 'casper') && !str_contains($lower, 'cost') && !str_contains($lower, 'source') && !str_contains($lower, 'smoke sam') && !str_contains($r['body'], '649'), 'never the phone, a supplier, a cost, a source or the salesperson (grep of the HTML)');
ok(!str_contains($r['body'], 'htmx') && !str_contains($r['body'], '<script'), 'plain HTML: no HTMX, no script');
$link = q('SELECT * FROM order_links_secure WHERE sales_order_id = :o AND rotated_at IS NULL', ['o' => $main])[0];
$vlog = last_log('order.customer_view');
ok((int) $link['view_count'] === 1 && $vlog !== null && $vlog['source'] === 'portal' && $vlog['actor_member_id'] === null && (int) $vlog['sales_order_id'] === $main && after_of($vlog)['number'] === $num && after_of($vlog)['view_count'] === 1, 'view_count 1; order.customer_view is logged: source portal, no actor, the order, number and link id');
ok(req('GET', '/o/not-a-token')['code'] === 404 || req('GET', '/o/' . str_repeat('a', 48))['code'] === 404, 'an unknown token: the dead page (404)');
$dead = req('GET', '/o/' . str_repeat('a', 48));
ok($dead['code'] === 404 && str_contains($dead['body'], 'This link has expired. Ask the business for a new one.') && !str_contains($dead['body'], str_repeat('a', 48)), '…"This link has expired. Ask the business for a new one." — and the token is never echoed');
ok(req('GET', '/o/zzzz')['code'] === 404, 'a malformed token: 404');

// ---- a second send mints anew; the first link is dead
[$c, $b] = act($sam, '/orders/send.php', ['order' => $main]);
$mails = mail_log();
$mail2 = end($mails);
$tok2 = token_from_mail($mail2);
ok($c === 200 && $b['rotated'] === true && $tok2 !== null && $tok2 !== $tok1, 'a second send: a new link, rotated true');
ok(req('GET', "/o/$tok1")['code'] === 404 && req('GET', "/o/$tok2")['code'] === 200, 'the first link is dead (404); the new one opens');
ok((int) one('SELECT count(*) FROM order_links_secure WHERE sales_order_id = :o AND rotated_at IS NULL', ['o' => $main]) === 1, 'exactly one live link');

// ---- MaluMail's refusals roll the mint back
$run = substr(md5((string) microtime(true)), 0, 6);
$tryOrder = static function (string $email) use ($sam, $w): int {
    [, $b] = act($sam, '/customers/save.php', ['name' => "SMOKE Mail $email", 'email' => $email]);
    $cid = (int) $b['record_id'];
    [, $b] = make_quote($sam, $cid, [['variant' => $w['pillow_v'], 'qty' => 1, 'fulfilment_kind' => 'backorder', 'location' => $w['wh']]]);
    act($sam, '/orders/confirm.php', ['order' => (int) $b['record_id']]);
    return (int) $b['record_id'];
};
$bo = $tryOrder("suppressed-$run@example.invalid");
[$c, $b] = act($sam, '/orders/send.php', ['order' => $bo]);
ok($c === 422 && str_contains(msg($b), 'MaluMail refused the address') && (int) one('SELECT count(*) FROM order_links_secure WHERE sales_order_id = :o', ['o' => $bo]) === 0, 'a suppressed address: 422 "MaluMail refused the address…" and no link row is left (the rollback)');
$bo = $tryOrder("flaky-$run@example.invalid");
[$c, $b] = act($sam, '/orders/send.php', ['order' => $bo]);
ok($c === 503 && msg($b) === 'Mail could not be sent — try again.' && (int) one('SELECT count(*) FROM order_links_secure WHERE sales_order_id = :o', ['o' => $bo]) === 0, 'a transport error (502): 503 "Mail could not be sent — try again." and no link row');
$bo = $tryOrder("rejected-$run@example.invalid");
[$c, $b] = act($sam, '/orders/send.php', ['order' => $bo]);
ok($c === 422 && str_contains(msg($b), 'invalid_address') && (int) one('SELECT count(*) FROM order_links_secure WHERE sales_order_id = :o', ['o' => $bo]) === 0, 'an address MaluMail rejects with a 200: 422 with its reason, no link row');
// a customer with no email
[, $b] = make_quote($sam, $w['birch'], [['variant' => $w['pillow_v'], 'qty' => 1, 'fulfilment_kind' => 'backorder', 'location' => $w['wh']]]);
$nb = (int) $b['record_id'];
act($sam, '/orders/confirm.php', ['order' => $nb]);
[$c, $b] = act($sam, '/orders/send.php', ['order' => $nb]);
ok($c === 422 && msg($b) === 'The customer has no email address.', 'a customer with no email: 422 "The customer has no email address."');
$r = req('GET', "/orders/$nb/send", ['jar' => $sam]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="order-send-refusal"') && str_contains($r['body'], 'id="order-send-submit" disabled'), 'the send screen says so and disables Send');
// no key: a second application server with MALUMAIL_API_KEY blank
$pid = (int) shell_exec('cd ' . escapeshellarg(dirname(__DIR__, 3)) . ' && MALUMAIL_API_KEY= nohup php -S 127.0.0.1:8604 -t html tests/dev_router.php >/dev/null 2>&1 & echo $!');
for ($i = 0; $i < 30; $i++) { if (@file_get_contents('http://127.0.0.1:8604/api/v1/health') !== false) { break; } usleep(200000); }
$j = jar();
req('GET', 'http://127.0.0.1:8604' . handoff(41), ['jar' => $j]);
$tok = csrf_of(req('GET', 'http://127.0.0.1:8604/', ['jar' => $j])['body']);
$r = req('POST', 'http://127.0.0.1:8604/orders/send.php', ['jar' => $j, 'headers' => JSONH, 'form' => ['order' => $main, 'csrf_token' => $tok]]);
$body = json_decode($r['body'], true) ?? [];
if ($pid > 0) { posix_kill($pid, SIGTERM); }
ok($r['code'] === 503 && msg($body) === "Email is not configured — the installer's mail step writes the key.", 'without MALUMAIL_API_KEY: 503 "Email is not configured — the installer\'s mail step writes the key."');

// ---- the notices
$n = count(mail_log());
$newDate = date('Y-m-d', strtotime('+12 days'));
$since2 = last_activity_id();
[$c, $b] = act($sam, '/orders/notify.php', ['order' => $main, 'kind' => 'delay', 'promised_on' => $newDate, 'message' => 'The mill is behind.']);
$mails = mail_log();
$nm = end($mails);
ok($c === 200 && count($mails) === $n + 1 && order_row($main)['promised_on'] === $newDate && str_contains($nm['subject'], 'a delay') && str_contains($nm['text'], 'The mill is behind.'), 'order_notify delay with a new date: the notice goes out, promised_on moves');
ok(!str_contains($nm['text'] . $nm['html'], '/o/') && str_contains($nm['text'], 'See your order page from your confirmation email'), 'the notice carries no link');
$nl = last_log('order.notify', $since2);
ok($nl !== null && after_of($nl)['kind'] === 'delay' && after_of($nl)['promised_on'] === $newDate && !str_contains((string) $nl['after'], 'mill'), 'order.notify is logged with the kind and the date — not the message');
[$c, $b] = act($sam, '/orders/notify.php', ['order' => $main, 'kind' => 'gossip']);
ok($c === 422 && isset(fields($b)['kind']), 'an unknown kind: 422');
[$c, $b] = act($sam, '/orders/notify.php', ['order' => $qid, 'kind' => 'shipped']);
ok($c === 422 && str_contains(msg($b), 'Confirm it first'), 'a quote is not notified');

// ---- rotation keeps no token
$since3 = last_activity_id();
[$c, $b] = act($sam, '/orders/link-rotate.php', ['order' => $main]);
ok($c === 200 && req('GET', "/o/$tok2")['code'] === 404 && (int) one('SELECT count(*) FROM order_links_secure WHERE sales_order_id = :o AND rotated_at IS NULL', ['o' => $main]) === 0, 'order_link_rotate: the live link dies at once and no live link rests unseen');
$rl = last_log('order.link_rotate', $since3);
ok($rl !== null && (int) $rl['sales_order_id'] === $main && (int) one("SELECT count(*) FROM activity_log WHERE after::text ~ '[a-f0-9]{48}'") === 0, 'order.link_rotate is logged; no activity row anywhere carries a 48-hex token');
$state = screen($sam, "/orders/$main")[1]['order']['link'];
ok($state['is_live'] === false, 'the link card now says stopped — a new link goes out with the next send');
[$c, $b] = act($sam, '/orders/send.php', ['order' => $main]);
$mails = mail_log();
$tok3 = token_from_mail(end($mails));
ok($c === 200 && $b['rotated'] === false && $tok3 !== null && req('GET', "/o/$tok3")['code'] === 200, 'the next send mints a fresh live link that opens');

// ---- the door's limits
$ml = (int) one("SELECT id FROM sales_orders WHERE customer_reference = 'S5-MONEY'");
$raw = psql_exec("SELECT inv_order_link_mint($ml)");
$codes = [];
for ($i = 1; $i <= 61; $i++) { $codes[$i] = req('GET', "/o/$raw")['code']; }
ok(count(array_filter(array_slice($codes, 0, 60, true), static fn ($x) => $x === 200)) === 60 && $codes[61] === 429, '60 views in an hour are served; the 61st is 429');
$r = req('GET', "/o/$raw");
ok($r['code'] === 429 && str_contains($r['body'], 'Too many requests — try again in a few minutes.') && (int) one("SELECT count(*) FROM activity_log WHERE action = 'order.customer_view' AND sales_order_id = :o", ['o' => $ml]) === 60, '…in words; the refused views are not logged');
psql_exec("INSERT INTO activity_log (action, source, sales_order_id, ip_address) SELECT 'order.customer_view', 'portal', 777777777, '203.0.113.9' FROM generate_series(1, 300)");
$cli = static fn (string $ip): string => trim((string) shell_exec('cd ' . escapeshellarg(dirname(__DIR__, 3)) . ' && php -r ' . escapeshellarg('require "app/bootstrap.php"; require "app/features/public/order.php"; echo door_rate_ok(db(), 888888888, "' . $ip . '") ? "yes" : "no";')));
ok($cli('203.0.113.9') === 'no' && $cli('203.0.113.10') === 'yes', 'door_rate_ok(): 300 views from one address in an hour close the door to it (300 a limit), another address is not affected');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
ok($reg['actions']['order_send']['approval'] === 'external_send' && $reg['actions']['order_notify']['approval'] === 'external_send' && $reg['screens']['customer-door']['built'] === true, 'the registry lists order_send and order_notify as external_send; the door built');
finish();

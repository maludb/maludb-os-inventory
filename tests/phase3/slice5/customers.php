<?php
/** Customers (orders.md "Customers", "Proof" ≈ 25): create with every field, the guard's words, partial updates, the log that says "email changed", the view's walls, search, archive, delete, the tabs. */
require __DIR__ . '/lib.php';
$w = order_world();
$sam = $w['sam'];
$nora = $w['nora'];
$vera = $w['vera'];
$run = substr(md5((string) microtime(true)), 0, 6);
$phone = '312-555-' . random_int(1000, 9999) . random_int(10, 99);       // unique per run: the search by phone is exact
$since = last_activity_id();

// create with every field
[$c, $b] = act($sam, '/customers/save.php', ['name' => "SMOKE Customer $run", 'legal_name' => "Customer $run Inc", 'email' => "cust-$run@example.invalid", 'phone' => '312-555-0101', 'phone_alt' => '312-555-0102',
    'billing_address' => "1 Bill St\nChicago", 'shipping_address' => "2 Ship Ave\nEvanston", 'source' => 'referral', 'tax_rate' => $w['cook'], 'terms_days' => '30', 'tax_id' => 'TX-1', 'email_opt_in' => 'yes', 'notes' => 'a note']);
$cid = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $cid > 0 && ($b['customer_id'] ?? 0) === $cid, 'customer_create with every field answers record_id and customer_id');
$row = q('SELECT * FROM customers WHERE id = :id', ['id' => $cid])[0] ?? [];
ok(($row['source'] ?? '') === 'referral' && (int) ($row['tax_rate_id'] ?? 0) === $w['cook'] && (int) ($row['terms_days'] ?? 0) === 30 && ($row['email_opt_in'] ?? false) === true && ($row['tax_id'] ?? '') === 'TX-1' && (int) $row['created_by'] === 41, 'every field is stored (source, tax rate, terms, tax id, opt-in, created_by Sam)');
ok(str_ends_with((string) ($b['location'] ?? ''), "/customers/$cid?notice=created"), 'the location is the customer, with the notice');
$log = last_log('customer.create', $since);
ok($log !== null && after_of($log)['name'] === "SMOKE Customer $run" && (int) $log['entity_id'] === $cid && $log['entity_type'] === 'customer', 'customer.create is logged against the customer');
ok($log !== null && !str_contains((string) $log['after'], 'Bill St') && !str_contains((string) $log['after'], "cust-$run") && !str_contains((string) $log['after'], '312-555'), 'the log row carries no address, email or phone');

// the guard's words
[$c, $b] = act($sam, '/customers/save.php', ['name' => "smoke customer $run"]);
ok($c === 422 && msg($b) === 'That name is already taken.', 'a duplicate live name: 422 in the guard\'s words');
[$c, $b] = act($sam, '/customers/save.php', ['name' => "SMOKE Bad $run", 'email' => 'not-an-email']);
ok($c === 422 && isset(fields($b)['email']), 'a bad email: 422 with the field error');
[$c, $b] = act($sam, '/customers/save.php', ['name' => "SMOKE Terms $run", 'terms_days' => '400']);
ok($c === 422 && isset(fields($b)['terms_days']), 'terms_days 400: 422 with the field error');
[$c, $b] = act($sam, '/customers/save.php', ['name' => '']);
ok($c === 422 && isset(fields($b)['name']), 'no name: 422');

// update keeps what was left out
$since = last_activity_id();
[$c, $b] = act($sam, '/customers/save.php', ['customer' => $cid, 'phone' => $phone]);
$row = q('SELECT * FROM customers WHERE id = :id', ['id' => $cid])[0];
ok($c === 200 && $row['phone'] === $phone && $row['email'] === "cust-$run@example.invalid" && $row['shipping_address'] === "2 Ship Ave\nEvanston" && $row['source'] === 'referral' && (int) $row['terms_days'] === 30, 'customer_update changes the phone and keeps everything left out');
$log = last_log('customer.update', $since);
ok($log !== null && in_array('phone', after_of($log)['changed'] ?? [], true) && !str_contains((string) $log['after'], '312-555'), 'the update log says "phone changed", never the number');
$since = last_activity_id();
[$c, $b] = act($sam, '/customers/save.php', ['customer' => $cid, 'email' => "new-$run@example.invalid"]);
$log = last_log('customer.update', $since);
ok($c === 200 && $log !== null && in_array('email', after_of($log)['changed'] ?? [], true) && !str_contains((string) $log['after'], "new-$run"), 'the update log says "email changed", never the address');
[$c, $b] = act_token('/customers/save.php', ['customer' => $cid, '_partial' => '1', 'notes' => 'by token'], as_agent(person_token(41)));
$row = q('SELECT * FROM customers WHERE id = :id', ['id' => $cid])[0];
ok($c === 200 && $row['notes'] === 'by token' && $row['billing_address'] === "1 Bill St\nChicago" && $row['legal_name'] === "Customer $run Inc", '_partial=1 under a token keeps the billing address and the legal name');

// the view's walls
[$c, $d] = screen($vera, '/customers/');
$mine = array_values(array_filter($d['customers'] ?? [], static fn ($x) => $x['customer_id'] === $cid))[0] ?? null;
ok($c === 200 && $mine !== null && $mine['email'] === null && $mine['phone'] === null, 'Vera (Viewer) sees the card without email or phone');
[$c, $d] = screen($sam, '/customers/');
$mine = array_values(array_filter($d['customers'] ?? [], static fn ($x) => $x['customer_id'] === $cid))[0] ?? null;
ok($c === 200 && $mine !== null && $mine['email'] === "new-$run@example.invalid" && $mine['phone'] === $phone, 'Sam (Sales) sees the email and phone');
ok(req('GET', '/customers/new', ['jar' => $vera])['code'] === 403 && req('GET', '/customers/new', ['jar' => $sam])['code'] === 200, 'the form is for customers.write: 403 for Vera, 200 for Sam');
[$c, $b] = act($vera, '/customers/save.php', ['name' => 'SMOKE Nope']);
ok($c === 403 && str_contains(msg($b), 'customers'), 'Vera cannot create a customer: 403 in words');

// search
[$c, $d] = screen($sam, '/customers/?q=' . urlencode("new-$run@example.invalid"));
ok($c === 200 && count($d['customers']) === 1 && $d['customers'][0]['customer_id'] === $cid, 'search by an exact email finds the customer');
[$c, $d] = screen($sam, '/customers/?q=' . urlencode('Alvares'));
ok(count(array_filter($d['customers'], static fn ($x) => $x['customer_id'] === $w['alvarez'])) === 1, 'search by a name near-miss (trigram) finds SMOKE Alvarez');
[$c, $d] = screen($sam, '/customers/?q=' . urlencode($phone));
ok(count($d['customers']) === 1 && $d['customers'][0]['customer_id'] === $cid, 'search by an exact phone finds the customer');
[$c, $d] = screen($vera, '/customers/?q=' . urlencode("new-$run@example.invalid"));
ok($d['customers'] === [], 'Vera cannot find a customer by an email she cannot see');
$html = req('GET', '/customers/?q=SMOKE', ['jar' => $sam])['body'];
ok(str_contains($html, 'id="customer-card-' . $cid . '"') && str_contains($html, 'id="customer-list-search"') && str_contains($html, 'id="customer-list-new-btn"'), 'the list is cards with the search box and the New button');

// archive: refused with a quote open, allowed after its cancel
$q = make_quote($sam, $cid, [['variant' => $w['pillow_v'], 'qty' => 1, 'fulfilment_kind' => 'backorder', 'location' => $w['wh']]]);
$qid = (int) $q[1]['record_id'];
[$c, $b] = act($sam, '/customers/archive.php', ['customer' => $cid]);
ok($c === 422 && str_contains(msg($b), 'Close or cancel their 1 open order'), 'archive is refused while a quote is open, in words');
act($sam, '/orders/cancel.php', ['order' => $qid, 'reason' => 'SMOKE test']);
[$c, $b] = act($sam, '/customers/archive.php', ['customer' => $cid]);
ok($c === 200 && q('SELECT archived_at FROM customers WHERE id = :id', ['id' => $cid])[0]['archived_at'] !== null, 'archive is allowed once the quote is cancelled');
[$c, $d] = screen($sam, '/customers/?q=' . urlencode("SMOKE Customer $run"));
[$c2, $d2] = screen($sam, '/customers/?archived=1&q=' . urlencode("SMOKE Customer $run"));
$has = static fn (array $d) => in_array($cid, array_column($d['customers'], 'customer_id'), true);
ok(!$has($d) && $has($d2), 'the archived card is hidden, and shown with archived=1');
[$c, $b] = act($sam, '/customers/archive.php', ['customer' => $cid, 'archived' => 'no']);
ok($c === 200 && q('SELECT archived_at FROM customers WHERE id = :id', ['id' => $cid])[0]['archived_at'] === null, 'unarchive');

// delete
[$c, $b] = act($w['owner'], '/customers/delete.php', ['customer' => $cid]);
ok($c === 422 && msg($b) === "SMOKE Customer $run has orders; archive them instead.", 'delete is refused for a customer with an order, in words');
[, $b] = act($sam, '/customers/save.php', ['name' => "SMOKE Disposable $run"]);
$did = (int) $b['record_id'];
[$c, $b] = act($nora, '/customers/delete.php', ['customer' => $did]);
ok($c === 403, 'delete needs records.delete: Nora is refused');
$since = last_activity_id();
[$c, $b] = act($w['owner'], '/customers/delete.php', ['customer' => $did]);
ok($c === 200 && customer_id_of("SMOKE Disposable $run") === null && last_log('customer.delete', $since) !== null, 'the owner deletes a customer with no order; customer.delete is logged');

// the view and its tabs
$bad = [];
foreach (['orders', 'returns', 'notes', 'attachments', 'trail'] as $tab) {
    $r = req('GET', "/customers/{$w['alvarez']}?tab=$tab", ['jar' => $sam]);
    if ($r['code'] !== 200 || !str_contains($r['body'], 'id="customer-view-tab-' . $tab . '"')) { $bad[] = $tab; }
}
ok($bad === [], 'the customer page opens on every tab (orders, returns, notes, attachments, trail)');
$r = req('GET', "/customers/$cid", ['jar' => $sam]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="customer-view-quote-btn"') && str_contains($r['body'], 'id="customer-view-orders"') && str_contains($r['body'], "/orders/new?customer=$cid"), 'the page has New quote (to the order form with the customer) and the Orders tab');
$r = req('GET', "/customers/$cid", ['jar' => $vera]);
ok($r['code'] === 200 && !str_contains($r['body'], 'customer-view-quote-btn') && !str_contains($r['body'], "new-$run@example.invalid"), 'Vera\'s page has no New quote and no email');
finish();

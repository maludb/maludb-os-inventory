<?php
/** Suppliers (purchasing.md "Proof" ≈ 25): the form and its refusals, the card, the account number behind the right, update keeps what was left out, archive, the tabs and the message to a supplier. */
require __DIR__ . '/lib.php';
$w = purchasing_world();
$nora = $w['nora'];
$sam = $w['sam'];
$vera = $w['vera'];
$since = last_activity_id();
$run = substr(md5((string) microtime(true)), 0, 6);

// ---- create with every field
$fields = ['name' => "SMOKE Vendor $run", 'kind' => 'distributor', 'contact_name' => 'Dana Dealer', 'email' => "sales-$run@vendor.example.invalid", 'phone' => '555-0100', 'address' => "1 Mill Road\nGary IN", 'website' => 'https://vendor.example.invalid',
           'account_number' => 'ACC-9-' . $run, 'terms' => 'Net 45', 'dropships' => 'yes', 'lead_time_days' => '7', 'order_method' => 'email', 'order_email' => "orders-$run@vendor.example.invalid",
           'portal_url' => 'https://portal.vendor.example.invalid', 'min_order' => '250', 'notes' => 'Ships Tuesdays.'];
[$c, $b] = act($nora, '/suppliers/save.php', $fields);
$sid = (int) ($b['record_id'] ?? 0);
$row = $sid ? supplier_row($sid) : [];
ok($c === 200 && $sid > 0 && $b['refresh'] === 'supplierChanged' && str_contains((string) $b['location'], "/suppliers/$sid"), 'supplier_create with every field: 200, the record id, location /suppliers/{id}, refresh supplierChanged');
ok(($row['kind'] ?? '') === 'distributor' && $row['dropships'] === true && (int) $row['lead_time_days'] === 7 && $row['order_method'] === 'email' && (float) $row['min_order'] === 250.0 && $row['account_number'] === 'ACC-9-' . $run && $row['terms'] === 'Net 45', 'every field is stored as given (kind, drop-ships, lead 7, e-mail, minimum 250, account number, terms)');
$log = last_log('supplier.create', $since);
$a = after_of($log);
ok($log !== null && $a['name'] === "SMOKE Vendor $run" && $a['dropships'] === true && $a['order_method'] === 'email' && (int) $a['lead_time_days'] === 7, 'supplier.create is logged with name, drop-ships, order method and lead time');
ok($log !== null && !str_contains((string) $log['after'], 'ACC-9') && !str_contains((string) $log['after'], 'Mill Road') && !str_contains((string) $log['after'], 'Tuesdays'), 'the log row never carries the account number, the address or the notes');

// ---- the refusals
[$c, $b] = act($nora, '/suppliers/save.php', ['name' => "SMOKE Vendor $run"]);
ok($c === 422 && msg($b) === 'That name is already taken.', 'a duplicate live name: 422 "That name is already taken."');
[$c, $b] = act($nora, '/suppliers/save.php', ['name' => "SMOKE Bad $run", 'website' => 'not a url', 'email' => 'nobody', 'order_email' => 'also bad']);
ok($c === 422 && isset(fields($b)['website']) && isset(fields($b)['email']) && isset(fields($b)['order_email']), 'a bad URL and two bad e-mails: 422 with a field error each');
[$c, $b] = act($nora, '/suppliers/save.php', ['name' => '', 'kind' => 'wizard', 'lead_time_days' => '999', 'order_method' => 'carrier pigeon', 'min_order' => '-5']);
ok($c === 422 && isset(fields($b)['name']) && isset(fields($b)['kind']) && isset(fields($b)['lead_time_days']) && isset(fields($b)['order_method']) && isset(fields($b)['min_order']), 'no name, an unknown kind and method, 999 days and a negative minimum: a field error each');
[$c, $b] = act($sam, '/suppliers/save.php', ['name' => "SMOKE Sam $run"]);
ok($c === 403 && str_contains(msg($b), 'change suppliers'), 'Sam (no suppliers.write): 403 in words');

// ---- the card and the page
$r = req('GET', '/suppliers/?q=' . rawurlencode("Vendor $run"), ['jar' => $nora]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="supplier-card-' . $sid . '"') && str_contains($r['body'], 'id="supplier-card-' . $sid . '-dropships"') && str_contains($r['body'], '7 days lead time'), 'the card shows "drop-ships" and the lead time');
$r = req('GET', "/suppliers/$sid", ['jar' => $nora]);
ok($r['code'] === 200 && str_contains($r['body'], 'ACC-9-' . $run) && str_contains($r['body'], 'id="supplier-view-account"'), "Nora's page shows the account number");
$r = req('GET', "/suppliers/$sid", ['jar' => $vera]);
ok($r['code'] === 200 && !str_contains($r['body'], 'ACC-9') && !str_contains($r['body'], 'id="supplier-view-account"'), "Vera's page shows no account number");
[, $d] = screen($vera, "/suppliers/$sid");
ok($d['supplier']['account_number'] === null && $d['may']['write'] === false, "…and Vera's JSON carries none");
$r = req('GET', "/suppliers/$sid/edit", ['jar' => $sam]);
ok($r['code'] === 403, 'the edit form is Sam\'s 403');
$r = req('GET', "/suppliers/$sid/edit", ['jar' => $nora]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="supplier-form"') && str_contains($r['body'], 'id="supplier-form-field-account_number"') && str_contains($r['body'], 'value="ACC-9-' . $run . '"'), 'the edit form carries every field, the account number included (Nora holds purchasing.write)');

// ---- update keeps what was left out
$before = supplier_row($sid);
$since2 = last_activity_id();
[$c, $b] = act($nora, '/suppliers/save.php', ['supplier' => $sid, 'phone' => '555-0199']);
$after = supplier_row($sid);
$keptAll = true;
foreach (['name', 'kind', 'contact_name', 'email', 'address', 'website', 'account_number', 'terms', 'dropships', 'lead_time_days', 'order_method', 'order_email', 'portal_url', 'min_order', 'notes'] as $k) { if ($before[$k] !== $after[$k]) { $keptAll = false; } }
ok($c === 200 && $after['phone'] === '555-0199' && $keptAll, 'supplier_update with only the phone: the phone changes and every other field stays');
$log = last_log('supplier.update', $since2);
ok($log !== null && after_of($log)['changed'] === ['phone'] && !str_contains((string) $log['after'], '555-0199'), 'supplier.update logs the fields changed by name — not their values');
$seesAccount = right_of(41, 'purchasing.write');
ok($seesAccount === false, 'Sam holds no purchasing.write — an account number he sends would be ignored and never read back');
[$c, $b] = act($sam, '/suppliers/save.php', ['supplier' => $sid, 'account_number' => 'HACK']);
ok($c === 403 && supplier_row($sid)['account_number'] === 'ACC-9-' . $run, "Sam cannot change a supplier at all; the account number is untouched");

// ---- archive
[, $b] = make_po($nora, $sid, [['variant' => $w['queen'], 'qty' => 2, 'unit_cost' => '500']], ['notes' => "S6-ARCH-$run"]);
$apo = (int) $b['record_id'];
ok($apo > 0, 'a purchase order for the new supplier (a draft) is drafted');
[$c, $b] = act($nora, '/suppliers/archive.php', ['supplier' => $sid]);
ok($c === 422 && msg($b) === 'Close or cancel their 1 open purchase order first.' && supplier_row($sid)['active'] === true, 'archive refused with an open purchase order, in words');
act($nora, '/purchasing/cancel.php', ['purchase_order' => $apo, 'reason' => 'test']);
$since3 = last_activity_id();
[$c, $b] = act($nora, '/suppliers/archive.php', ['supplier' => $sid]);
ok($c === 200 && supplier_row($sid)['active'] === false && last_log('supplier.archive', $since3) !== null, 'after its cancel the supplier archives; supplier.archive is logged');
$r = req('GET', '/suppliers/', ['jar' => $nora]);
ok(!str_contains($r['body'], 'id="supplier-card-' . $sid . '"'), 'an archived supplier is not on the list…');
$r = req('GET', '/suppliers/?q=' . rawurlencode("Vendor $run"), ['jar' => $nora]);
ok(str_contains($r['body'], 'id="supplier-card-' . $sid . '"') && str_contains($r['body'], 'archived'), '…a search shows it, marked archived');
[$c, $b] = act($nora, '/suppliers/archive.php', ['supplier' => $sid, 'active' => 'yes']);
ok($c === 200 && supplier_row($sid)['active'] === true, 'archive with active=yes brings it back');

// ---- the tabs
$r = req('GET', "/suppliers/{$w['zinus']}", ['jar' => $nora]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="supplier-items"') && str_contains($r['body'], '>Cost<') && str_contains($r['body'], 'NW-CR-T') && str_contains($r['body'], '349.50'), "the Items tab lists Zinus' price sheet with cost for Nora");
$r = req('GET', "/suppliers/{$w['zinus']}", ['jar' => $sam]);
ok($r['code'] === 200 && str_contains($r['body'], 'NW-CR-T') && !str_contains($r['body'], '>Cost<') && !str_contains($r['body'], '349.50'), '…without cost for Sam');
$r = req('GET', "/suppliers/{$w['zinus']}?tab=open", ['jar' => $nora]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="supplier-open-orders"') && str_contains($r['body'], 'id="supplier-open-order-' . $w['po_zinus'] . '"'), 'the Open orders tab lists the drop-ship draft of S6-MAIN');
$r = req('GET', "/suppliers/{$w['zinus']}?tab=lead", ['jar' => $nora]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="supplier-lead-times"') && str_contains($r['body'], 'id="supplier-shipped"'), 'the Lead times tab for Nora (reports.read): the actuals card and the shipped lines');
$r = req('GET', "/suppliers/{$w['zinus']}?tab=lead", ['jar' => $sam]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="supplier-lead-times-none"') && !str_contains($r['body'], 'supplier-lead-times-row'), '…and "—" for Sam (no reports.read)');
$r = req('GET', "/suppliers/{$w['zinus']}?tab=sources", ['jar' => $nora]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="supplier-source-' . $w['src_zinus_feed'] . '"'), 'the Sources tab lists the feed that reads Zinus');

// ---- supplier_message
$n = count(mail_log());
$notesBefore = count(array_filter(po_events_of($w['po_zinus']), static fn ($e) => $e['kind'] === 'note'));
$zpo = po_row($w['po_zinus']);
[$c, $b] = act($nora, '/suppliers/message.php', ['supplier' => $w['zinus'], 'subject' => 'Lead times', 'body' => 'How soon can you ship the King? SECRET-BODY-' . $run, 'purchase_order' => $w['po_zinus']]);
$mails = mail_log();
$mail = end($mails);
ok($c === 200 && count($mails) === $n + 1 && $mail['to'] === 'dealers@zinus.example.invalid' && $mail['subject'] === $zpo['number'] . ': Lead times' && str_contains($mail['text'], 'SECRET-BODY-' . $run), 'supplier_message: one MaluMail call to the order e-mail, the PO number in the subject, the body in the text');
$log = last_log('supplier.message', $since);
$a = after_of($log);
ok($log !== null && (int) $log['purchase_order_id'] === $w['po_zinus'] && $a['subject'] === 'Lead times' && $a['length'] === mb_strlen('How soon can you ship the King? SECRET-BODY-' . $run) && !str_contains((string) $log['after'], 'SECRET-BODY'), 'supplier.message is logged with the subject and the length — never the body');
$ev = array_values(array_filter(po_events_of($w['po_zinus']), static fn ($e) => $e['kind'] === 'note'));
ok(count($ev) === $notesBefore + 1 && end($ev)['source'] === 'email' && end($ev)['note'] === 'emailed: Lead times' && (int) end($ev)['member_id'] === 40, 'a `note` event (source email, "emailed: Lead times") is written on the named purchase order');
$n = count(mail_log());
[$c, $b] = act($nora, '/suppliers/message.php', ['supplier' => $w['nomail'], 'subject' => 'Hello', 'body' => 'Anyone there?']);
ok($c === 422 && msg($b) === 'The supplier has no email address.' && count(mail_log()) === $n, 'no e-mail address: 422 "The supplier has no email address." and nothing goes out');
[$c, $b] = act($nora, '/suppliers/message.php', ['supplier' => $w['suppressed'], 'subject' => 'Hello', 'body' => 'Anyone there?']);
ok($c === 422 && str_contains(msg($b), 'MaluMail refused the address'), 'a suppressed address: 422 "MaluMail refused the address…"');
[$c, $b] = act($nora, '/suppliers/message.php', ['supplier' => $w['flaky'], 'subject' => 'Hello', 'body' => 'Anyone there?']);
ok($c === 503 && msg($b) === 'Mail could not be sent — try again.', 'a transport error: 503 "Mail could not be sent — try again."');
[$c, $b] = act($nora, '/suppliers/message.php', ['supplier' => $w['zinus'], 'subject' => '', 'body' => '']);
ok($c === 422 && isset(fields($b)['subject']) && isset(fields($b)['body']), 'no subject and no body: a field error each');
[$c, $b] = act($sam, '/suppliers/message.php', ['supplier' => $w['zinus'], 'subject' => 'x', 'body' => 'y']);
ok($c === 403, 'Sam may not e-mail a supplier (purchasing.write): 403');
[$c, $b] = act($nora, '/suppliers/message.php', ['supplier' => $w['zinus'], 'subject' => 'x', 'body' => 'y', 'purchase_order' => $w['po_malouf']]);
ok($c === 422 && isset(fields($b)['purchase_order']), 'a purchase order of another supplier: a field error');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
ok($reg['actions']['supplier_message']['approval'] === 'external_send', 'supplier_message is external_send in the registry');
finish();

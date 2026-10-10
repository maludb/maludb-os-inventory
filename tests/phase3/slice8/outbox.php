<?php
/** The worker's outbox pass (returns-worker.md "Proof": the outbox ≥ 28): e-mail through MaluMail, texts through the kernel's K6, every refusal and retry. */
require __DIR__ . '/lib.php';
$w = returns_world();
$ra1 = (int) one("SELECT id FROM return_authorizations WHERE notes = 'SMOKE S8-RA1'");
kernel_clean();
psql_exec("UPDATE notification_prefs SET text_enabled = true, text_kinds = '{watch,line_at_risk,return}' WHERE member_id = 41");

echo "1. The first pass sends what slices 4–8 queued\n";
$queued = (int) one("SELECT count(*) FROM notification_outbox WHERE status = 'queued'");
$ml0 = count(mail_log()); $since = last_activity_id();
$r = wk('outbox');
$ob = $r['outbox'] ?? [];
ok($r['_']['exit'] === 0 && ($ob['sent'] ?? 0) + ($ob['skipped'] ?? 0) + ($ob['retried'] ?? 0) + ($ob['failed'] ?? 0) === $queued && array_keys($ob) === ['sent', 'skipped', 'retried', 'failed'] && $ob['sent'] > 5 && $ob['retried'] === 0, "the pass answers {sent, skipped, retried, failed} and handles all $queued queued rows: " . json_encode($ob));
$mail = mail_to('nora@example.invalid', $ml0);
$req = array_values(array_filter($mail, static fn ($m) => str_contains($m['subject'], 'requested on')));
ok($req !== [] && str_contains($req[0]['subject'], 'Return RA-') && str_contains($req[0]['text'], "http://127.0.0.1:8601/returns/") && $req[0]['from'] === 'inventory@example.invalid' && str_contains($req[0]['html'], 'Open it') , 'Nora\'s e-mail went to the fake MaluMail: her address, the subject, the record\'s absolute link, the sender and an HTML body');
ok(!str_contains($req[0]['text'] . $req[0]['html'], 'SMOKE Sam') && !str_contains($req[0]['text'] . $req[0]['html'], '312-555') && !str_contains($req[0]['text'] . $req[0]['html'], 'sam@example'), 'and nothing of another person: no name, no phone, no address');
$row = q("SELECT * FROM notification_outbox WHERE member_id = 40 AND subject LIKE '%requested on%' ORDER BY id LIMIT 1")[0];
ok($row['status'] === 'sent' && preg_match('/^<mm-\d+@fake\.malumail>$/', (string) $row['provider_ref']) === 1 && $row['sent_at'] !== null && $row['detail'] === null, 'sent: provider_ref is MaluMail\'s message id, sent_at set');
$lg = q("SELECT * FROM activity_log WHERE action = 'notification.send' AND entity_id = :i", ['i' => $row['id']])[0] ?? null;
$a = $lg ? json_decode($lg['after'], true) : [];
ok($lg !== null && $lg['source'] === 'cron' && $lg['actor_member_id'] === null && $a['kind'] === 'return' && $a['channel'] === 'email' && $a['record_type'] === 'return' && !array_key_exists('body', $a) && !array_key_exists('to', $a) && !str_contains($lg['after'], '@'), 'logged notification.send (cron) with kind, channel and record — never a body or an address');
$txt = q("SELECT * FROM notification_outbox WHERE channel = 'text' AND member_id = 41 AND status = 'sent' ORDER BY id");
ok($txt !== [] && preg_match('/^kernel:9\d+$/', (string) $txt[0]['provider_ref']) === 1 && count(sms_log()) === count($txt) && (int) sms_log()[0]['member_id'] === 41 && str_starts_with(sms_log()[0]['reference'], 'watch:watch:'), 'the texts went through K6: sent with `kernel:<id>`, the member, a reference naming the notice');
ok(array_unique(array_column(q("SELECT status FROM notification_outbox WHERE id <= :m", ['m' => last_outbox_id()]), 'status')) !== ['queued'] && (int) one("SELECT count(*) FROM notification_outbox WHERE status = 'queued'") === 0, 'nothing is left queued');
$r = wk('outbox');
ok(($r['outbox']['sent'] ?? -1) === 0 && ($r['outbox']['retried'] ?? -1) === 0, 'a second pass finds nothing to send (idempotent)');

echo "2. K6's refusals skip the text and the e-mail stands\n";
$modes = ['no_sender', 'not_held', 'no_verified_phone', 'opted_out', 'rate_limited'];
foreach ($modes as $i => $mode) {
    kstate(['sms' => ['mode' => $mode]]);
    $o0 = last_outbox_id();
    call_fn(0, 'notify', [41, 'return', 'return', $ra1, "S8 K6 $mode", 'body', "s8:k6:$mode", true]);
    wk('outbox');
    $rows = outbox_rows($o0);
    $byCh = array_column($rows, null, 'channel');
    ok($byCh['text']['status'] === 'skipped' && $byCh['text']['detail'] === $mode && $byCh['email']['status'] === 'sent', "K6 says $mode: the text row is skipped with that code; the e-mail row still sent");
}
ok(last_text_refusal_ok(), 'the settings screen reads the latest refusal from this outbox (opted out / no phone / no sender)');
function last_text_refusal_ok(): bool { return (string) one("SELECT detail FROM notification_outbox WHERE channel = 'text' AND member_id = 41 ORDER BY id DESC LIMIT 1") === 'rate_limited'; }
kstate(['sms' => ['mode' => 'ok']]);

echo "3. Failures and retries\n";
kstate(['sms' => ['mode' => 'server_error']]);
$o0 = last_outbox_id();
call_fn(0, 'notify', [41, 'watch', 'watch', $w['watch_sam'], 'S8 K6 500', 'body', 's8:k6:500', true]);
$base = time();
$t = static fn (int $i): string => gmdate('c', $base + $i * 60);
wk('outbox');                                                                           // e-mail sent, the text fails the first time
$txtRow = static fn (): array => q("SELECT * FROM notification_outbox WHERE id > :s AND channel = 'text'", ['s' => $o0])[0];
$x = $txtRow();
ok($x['status'] === 'queued' && (int) $x['attempts'] === 1 && $x['detail'] === 'server_error' && abs(strtotime($x['send_after']) - ($base + 120)) < 90, 'a K6 failure (500): attempts 1, a reason, the next try ~2 minutes on');
$r = wk('outbox');
ok(($r['outbox']['sent'] ?? 0) === 0 && ($r['outbox']['retried'] ?? 0) === 0 && (int) $txtRow()['attempts'] === 1, 'the pass skips it until due');
$r = wk('outbox', ['INV_WORKER_NOW' => $t(3)]);
$x = $txtRow();
ok(($r['outbox']['retried'] ?? 0) === 1 && (int) $x['attempts'] === 2 && abs(strtotime($x['send_after']) - ($base + 180 + 240)) < 90, 'due, it fails again: attempts 2 and the next try 4 minutes after that');
$r = wk('outbox', ['INV_WORKER_NOW' => $t(8)]); $x = $txtRow();
ok((int) $x['attempts'] === 3, 'attempts 3 (then 8 minutes)');
$r = wk('outbox', ['INV_WORKER_NOW' => $t(17)]); $x = $txtRow();
ok((int) $x['attempts'] === 4 && $x['status'] === 'queued', 'attempts 4 (then 16 minutes)');
$r = wk('outbox', ['INV_WORKER_NOW' => $t(40)]); $x = $txtRow();
$lg = last_log('notification.fail');
ok(($r['outbox']['failed'] ?? 0) === 1 && $x['status'] === 'failed' && $x['detail'] === 'server_error' && (int) $lg['entity_id'] === (int) $x['id'] && after_of($lg)['channel'] === 'text' && after_of($lg)['attempts'] === 5, 'the fifth failure is `failed`, with notification.fail logged');
kstate(['sms' => ['mode' => 'ok']]);

$o0 = last_outbox_id();
psql_exec("INSERT INTO notification_outbox (channel, member_id, to_email, kind, subject, body) VALUES ('email', 41, 'someone-suppressed@example.invalid', 'return', 'S8 suppressed', 'x'), ('email', 41, 'nobody-flaky@example.invalid', 'return', 'S8 flaky', 'x'), ('email', 47, NULL, 'return', 'S8 no address', 'x'), ('email', 41, 'someone-rejected@example.invalid', 'return', 'S8 rejected', 'x')");
$r = wk('outbox');
$rows = array_column(outbox_rows($o0), null, 'subject');
ok($rows['S8 suppressed']['status'] === 'skipped' && $rows['S8 suppressed']['detail'] === 'suppressed:bounce' && $rows['S8 rejected']['status'] === 'skipped' && $rows['S8 rejected']['detail'] === 'invalid_address', 'MaluMail\'s 400 (suppressed) and its rejected address: skipped with the reason, never retried');
ok($rows['S8 flaky']['status'] === 'queued' && (int) $rows['S8 flaky']['attempts'] === 1 && $rows['S8 flaky']['detail'] === 'http_502', 'a 502 from MaluMail: retried (attempts 1, the status in the reason)');
ok($rows['S8 no address']['status'] === 'skipped' && $rows['S8 no address']['detail'] === 'no_address', 'a member with no address: skipped `no_address`');
$r = wk('outbox');
ok(($r['outbox']['skipped'] ?? 0) === 0 && (int) q("SELECT attempts FROM notification_outbox WHERE subject = 'S8 suppressed'")[0]['attempts'] === 1, 'a skipped row is not touched again');

echo "4. A bad key, no key, no transport\n";
$o0 = last_outbox_id();
psql_exec("INSERT INTO notification_outbox (channel, member_id, to_email, kind, subject, body) VALUES ('email', 40, 'nora@example.invalid', 'return', 'S8 bad key', 'x')");
$r = wk('outbox', ['MALUMAIL_API_KEY' => 'a-wrong-key']);
$row = outbox_rows($o0)[0]; $lg = last_log('notification.fail');
ok($row['status'] === 'failed' && $row['detail'] === 'bad_key' && ($r['outbox']['failed'] ?? 0) === 1 && after_of($lg)['code'] === 'bad_key', 'a key MaluMail refuses (401): failed at once, `bad_key`, logged');
$o0 = last_outbox_id(); $since = last_activity_id();
psql_exec("INSERT INTO notification_outbox (channel, member_id, to_email, kind, subject, body) VALUES ('email', 40, 'nora@example.invalid', 'return', 'S8 no key one', 'x'), ('email', 40, 'nora@example.invalid', 'return', 'S8 no key two', 'x')");
$r = wk('outbox', ['MALUMAIL_API_KEY' => '']);
$rows = outbox_rows($o0);
ok(($r['outbox']['skipped'] ?? 0) === 2 && array_unique(array_column($rows, 'detail')) === ['unconfigured'] && array_unique(array_column($rows, 'status')) === ['skipped'], 'no MALUMAIL_API_KEY: skipped `unconfigured`');
ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'notification.skip' AND id > :s AND after->>'code' = 'unconfigured'", ['s' => $since]) === 1, '…logged once a day, the rest silent');
$o0 = last_outbox_id();
psql_exec("INSERT INTO notification_outbox (channel, member_id, to_email, kind, subject, body) VALUES ('email', 40, 'nora@example.invalid', 'return', 'S8 no transport', 'x')");
$r = wk('outbox', ['MALUMAIL_API_URL' => 'http://127.0.0.1:8699']);
$row = outbox_rows($o0)[0];
ok($r['_']['exit'] === 0 && $row['status'] === 'queued' && (int) $row['attempts'] === 1 && $row['detail'] === 'transport' && ($r['outbox']['retried'] ?? 0) === 1, 'MaluMail unreachable: the row is retried, the pass does not throw (exit 0)');
$r = wk('outbox', later(3));
ok(($r['outbox']['sent'] ?? 0) === 1 && q("SELECT status FROM notification_outbox WHERE id = :i", ['i' => $row['id']])[0]['status'] === 'sent', 'and goes when MaluMail is back, once due');

echo "5. --limit\n";
$o0 = last_outbox_id();
psql_exec("INSERT INTO notification_outbox (channel, member_id, to_email, kind, subject, body) VALUES ('email', 40, 'nora@example.invalid', 'return', 'S8 limit a', 'x'), ('email', 40, 'nora@example.invalid', 'return', 'S8 limit b', 'x'), ('email', 40, 'nora@example.invalid', 'return', 'S8 limit c', 'x')");
$r = wk('outbox', [], '--limit=1');
ok(($r['outbox']['sent'] ?? 0) === 1 && (int) one("SELECT count(*) FROM notification_outbox WHERE id > :s AND status = 'queued'", ['s' => $o0]) === 2 && outbox_rows($o0)[0]['status'] === 'sent', '--limit=1 sends one, oldest first, and leaves two');
wk('outbox');
ok((int) one("SELECT count(*) FROM notification_outbox WHERE id > :s AND status = 'queued'", ['s' => $o0]) === 0, 'the next pass sends the rest');
psql_exec("DELETE FROM notification_outbox WHERE status = 'queued' AND subject = 'S8 flaky'");
finish();

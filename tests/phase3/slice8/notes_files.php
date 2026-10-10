<?php
/** Notes and files on every record (returns-worker.md "Proof": notes and files ≥ 22): the notes, the gated door, the types sniffed, the cap, the thumbnail, the deletes. */
require __DIR__ . '/lib.php';
$w = returns_world();
[$nora, $sam, $wes, $vera, $owner] = [$w['nora'], $w['sam'], $w['wes'], $w['vera'], $w['owner']];
$ra1 = (int) one("SELECT id FROM return_authorizations WHERE notes = 'SMOKE S8-RA1'");

foreach (glob(dirname(__DIR__, 3) . '/storage/attachments/.thumbs/*.jpg') ?: [] as $stale) { @unlink($stale); }          // attachment ids restart with the scratch database: a thumbnail cached by an earlier run is not this run's
echo "1. Notes\n";
$since = last_activity_id();
[$c, $b] = act($sam, '/notes/add.php', ['record_type' => 'order', 'record' => $w['so1'], 'body' => "The customer called.\n\nWants a morning delivery."]);
$n1 = (int) ($b['record_id'] ?? 0);
$row = $n1 ? q('SELECT * FROM notes WHERE id = :i', ['i' => $n1])[0] : [];
ok($c === 200 && $row['record_type'] === 'sales_order' && (int) $row['record_id'] === $w['so1'] && (int) $row['member_id'] === 41 && $b['location'] === "/orders/{$w['so1']}#notes", 'Sam adds a note on SO-1 (the manifest\'s word `order` is the table\'s `sales_order`)');
$lg = last_log('note.add', $since); $a = after_of($lg);
ok($lg !== null && (int) $lg['sales_order_id'] === $w['so1'] && $a['length'] === mb_strlen("The customer called.\n\nWants a morning delivery.") && !str_contains($lg['after'], 'customer called') && $a['record_type'] === 'sales_order', 'note.add is logged with the length and the order as the audit key — never the words');
$r = req('GET', "/orders/{$w['so1']}?tab=notes", ['jar' => $nora]);
ok($r['code'] === 200 && str_contains($r['body'], "id=\"note-$n1\"") && str_contains($r['body'], 'Wants a morning delivery.') && str_contains($r['body'], 'id="notes-form-save-btn"'), 'the order\'s notes tab lists it (and has the add form)');
[$c, $b] = act($sam, '/notes/add.php', ['record_type' => 'return', 'record' => $ra1, 'body' => 'Photos to follow.']);
ok($c === 200 && one('SELECT record_type FROM notes WHERE id = :i', ['i' => (int) $b['record_id']]) === 'return_authorization' && $b['location'] === "/returns/$ra1#notes", 'a note on a return is stored as `return_authorization` and lands on the return');
[$c, $b] = act($sam, '/notes/add.php', ['record_type' => 'feed_key', 'record' => 1, 'body' => 'x']);
ok($c === 422 && str_contains(msg($b), 'not a record that takes notes'), 'a record type that takes no notes: "not a record that takes notes"');
[$c, $b] = act($sam, '/notes/add.php', ['record_type' => 'shipment', 'record' => 1, 'body' => 'x']);
ok($c === 422, 'a shipment takes files, not notes');
[$c, $b] = act($sam, '/notes/add.php', ['record_type' => 'order', 'record' => 999999, 'body' => 'x']);
ok($c === 404, 'a record that is not there: 404');
[$c, $b] = act($sam, '/notes/add.php', ['record_type' => 'order', 'record' => $w['so1'], 'body' => '   ']);
ok($c === 422 && isset(fields($b)['body']), 'an empty note: a field error');
[$c] = act($vera, '/notes/add.php', ['record_type' => 'order', 'record' => $w['so1'], 'body' => 'x']);
ok($c === 403, 'Vera (no notes.write): 403');
[$c] = act($nora, '/notes/delete.php', ['note' => $n1]);
ok($c === 403, 'Nora may not delete Sam\'s note');
[$c, $b] = act($sam, '/notes/delete.php', ['note' => $n1]);
ok($c === 200 && one('SELECT 1 FROM notes WHERE id = :i', ['i' => $n1]) === false && after_of(last_log('note.delete'))['length'] > 0, 'Sam deletes his own: gone, logged');
[$c, $b] = act($sam, '/notes/add.php', ['record_type' => 'customer', 'record' => $w['alvarez'], 'body' => 'Prefers the afternoon.']);
$n2 = (int) $b['record_id'];
[$c] = act($owner, '/notes/delete.php', ['note' => $n2]);
ok($c === 200 && one('SELECT 1 FROM notes WHERE id = :i', ['i' => $n2]) === false, 'the admin may delete anyone\'s');
// every word maps to a view
$bad = [];
foreach (['product', 'variant', 'supplier', 'source', 'listing', 'customer', 'order', 'purchase_order', 'receipt', 'return', 'location'] as $word) {
    $r = call_fn(40, 'note_record', [$word])['result'] ?? null;
    if ($r === null) { $bad[] = $word; continue; }
    try { q("SELECT {$r['id_column']} FROM {$r['view']} LIMIT 0"); } catch (Throwable $e) { $bad[] = $word; }
}
ok($bad === [], 'the eleven record words of notes each map to a table type and a view that has its id column' . ($bad ? ': ' . implode(',', $bad) : ''));

echo "2. Files\n";
$png = png(640, 480);
$since = last_activity_id();
[$c, $b, $raw] = act_files($sam, '/files/upload.php', ['record_type' => 'return', 'record' => $ra1], ['file' => [$png, 'return photo.png', 'image/png']]);
$aid = (int) ($b['record_id'] ?? 0);
$at = $aid ? q('SELECT * FROM attachments WHERE id = :i', ['i' => $aid])[0] : [];
$file = dirname(__DIR__, 3) . '/storage/' . ($at['storage_path'] ?? 'x');
ok($c === 200 && $at['record_type'] === 'return' && (int) $at['record_id'] === $ra1 && $at['mime_type'] === 'image/png' && $at['sha256'] === hash_file('sha256', $png) && is_file($file) && str_starts_with($at['storage_path'], "attachments/return/$ra1/$aid-"), 'a PNG attached to the return (a return photo): stored under storage/attachments/return/<id>/, sha256 right: ' . ($at['storage_path'] ?? $raw));
$lg = last_log('attachment.add', $since); $a = after_of($lg);
ok($lg !== null && (int) $lg['sales_order_id'] === $w['so1'] && $a['filename'] === 'return photo.png' && $a['byte_size'] === filesize($png) && $a['mime_type'] === 'image/png' && $b['location'] === "/returns/$ra1#attachments", 'attachment.add logged (filename, size, type; the order as the audit key)');
$r = req('GET', "/files/$aid/thumb", ['jar' => $nora]);
$size = @getimagesizefromstring($r['body']);
ok($r['code'] === 200 && str_contains($r['headers'], 'image/jpeg') && $size !== false && $size[0] === 320 && $size[1] === 240 && is_file(dirname(__DIR__, 3) . "/storage/attachments/.thumbs/$aid.jpg"), 'its thumbnail: a 320 px JPEG, cached under .thumbs/');
$r = req('GET', "/files/$aid", ['jar' => $vera]);
ok($r['code'] === 200 && str_contains($r['headers'], 'Content-Type: image/png') && stripos($r['headers'], 'Content-Disposition: inline') !== false && stripos($r['headers'], 'X-Content-Type-Options: nosniff') !== false && hash('sha256', $r['body']) === hash_file('sha256', $png), 'a Viewer opens the return\'s photo: inline, nosniff, the stored type, the bytes as uploaded');
$r = req('GET', "/files/$aid");
ok($r['code'] === 401, 'without a session the file door answers 401 (Phase 2\'s door; a file URL sits in an <img>, so no redirect)');
$r = req('GET', '/files/999999/thumb', ['jar' => $nora]);
ok($r['code'] === 404, 'a thumbnail of a file that is not there: 404');
$pdf = sys_get_temp_dir() . '/inv-s8.pdf'; file_put_contents($pdf, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
[$c, $b] = act_files($sam, '/files/upload.php', ['record_type' => 'order', 'record' => $w['so1']], ['file' => [$pdf, 'quote.pdf', 'application/pdf']]);
$pid = (int) ($b['record_id'] ?? 0);
$r = req('GET', "/files/$pid", ['jar' => $sam]);
ok($c === 200 && stripos($r['headers'], 'Content-Disposition: inline') !== false && str_contains($r['headers'], 'application/pdf') && req('GET', "/files/$pid/thumb", ['jar' => $sam])['code'] === 404, 'a PDF is stored and served inline; it has no thumbnail (404)');
$csv = sys_get_temp_dir() . '/inv-s8.csv'; file_put_contents($csv, "sku,qty\nA,1\n");
[$c, $b] = act_files($sam, '/files/upload.php', ['record_type' => 'shipment', 'record' => (int) one('SELECT id FROM shipments LIMIT 1')], ['file' => [$csv, 'list.csv', 'text/csv']]);
$cid = (int) ($b['record_id'] ?? 0);
$r = req('GET', "/files/$cid", ['jar' => $sam]);
ok($c === 200 && stripos($r['headers'], 'Content-Disposition: attachment') !== false, 'a CSV on a shipment is served as an attachment (download)');
$exe = sys_get_temp_dir() . '/inv-s8-fake.pdf'; file_put_contents($exe, "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff\x00\x00" . str_repeat("\x00", 200));
[$c, $b] = act_files($sam, '/files/upload.php', ['record_type' => 'return', 'record' => $ra1], ['file' => [$exe, 'invoice.pdf', 'application/pdf']]);
ok($c === 422 && str_contains(msg($b), 'That kind of file is not accepted'), 'an .exe renamed .pdf: 422 — the type is sniffed, not taken from the name or the browser');
$txt = sys_get_temp_dir() . '/inv-s8.txt'; file_put_contents($txt, str_repeat("a line of text\n", 110000));
psql_exec('UPDATE inv_settings SET max_attachment_bytes = 1048576 WHERE id = 1');
[$c, $b] = act_files($sam, '/files/upload.php', ['record_type' => 'return', 'record' => $ra1], ['file' => [$txt, 'big.txt', 'text/plain']]);
ok($c === 422 && str_contains(msg($b), 'A file is at most 1 MB.'), 'a file over the settings\' cap (1 MB): 422 in words');
psql_exec('UPDATE inv_settings SET max_attachment_bytes = 26214400 WHERE id = 1');
$big = sys_get_temp_dir() . '/inv-s8-big.bin'; $fh = fopen($big, 'wb'); fseek($fh, 30 * 1024 * 1024); fwrite($fh, 'x'); fclose($fh);
[$c, $b] = act_files($sam, '/files/upload.php', ['record_type' => 'return', 'record' => $ra1], ['file' => [$big, 'huge.txt', 'text/plain']]);
@unlink($big);
ok($c === 422 && str_contains(msg($b), 'too large'), 'a 30 MB file: 422 ("too large") — PHP drops a body over post_max_size and the handler says so');
[$c, $b] = act_files($sam, '/files/upload.php', ['record_type' => 'order', 'record' => $w['so1']], []);
ok($c === 422 && isset(fields($b)['file']), 'no file chosen: a field error');
[$c, $b] = act_files($vera, '/files/upload.php', ['record_type' => 'return', 'record' => $ra1], ['file' => [$csv, 'v.csv', 'text/csv']]);
ok($c === 403, 'Vera may not attach');
[$c, $b] = act_files($sam, '/files/upload.php', ['record_type' => 'feed_key', 'record' => 1], ['file' => [$csv, 'v.csv', 'text/csv']]);
ok($c === 422, 'a record that takes no files: 422');
$bad = [];
foreach (['product', 'variant', 'supplier', 'source', 'listing', 'customer', 'order', 'purchase_order', 'receipt', 'shipment', 'return', 'adjustment', 'count', 'transfer', 'location'] as $word) {
    $r = call_fn(40, 'attachment_record', [$word])['result'] ?? null;
    if ($r === null) { $bad[] = $word; continue; }
    try { q("SELECT {$r['id_column']} FROM {$r['view']} LIMIT 0"); } catch (Throwable $e) { $bad[] = $word; }
}
ok($bad === [], 'the fifteen record words of attachments (the table\'s CHECK has fifteen, the spec said fourteen) each map to a view');
$r = req('GET', "/returns/$ra1", ['jar' => $vera]);
ok(str_contains($r['body'], "id=\"attachment-$aid\"") && str_contains($r['body'], "/files/$aid/thumb") && !str_contains($r['body'], 'attachments-form') && !str_contains($r['body'], "attachment-$aid-delete-btn"), 'the return\'s page shows the photo with its thumbnail to a Viewer — no upload form, no delete');
$r = req('GET', "/returns/$ra1", ['jar' => $sam]);
ok(str_contains($r['body'], 'id="attachments-form-field-file"') && str_contains($r['body'], 'capture="environment"') && str_contains($r['body'], "id=\"attachment-$aid-delete-btn\""), 'to the uploader: the upload form (a phone offers its camera) and Delete');

echo "3. Delete\n";
[$c] = act($nora, '/files/delete.php', ['attachment' => $aid]);
ok($c === 403, 'Nora may not delete Sam\'s file');
$thumb = dirname(__DIR__, 3) . "/storage/attachments/.thumbs/$aid.jpg";
$since = last_activity_id();
[$c, $b] = act($sam, '/files/delete.php', ['attachment' => $aid]);
ok($c === 200 && one('SELECT 1 FROM attachments WHERE id = :i', ['i' => $aid]) === false && !is_file($file) && !is_file($thumb) && after_of(last_log('attachment.delete', $since))['filename'] === 'return photo.png', 'Sam deletes his own: the row, the file and its thumbnail are gone; logged');
[$c] = act($owner, '/files/delete.php', ['attachment' => $pid]);
ok($c === 200 && one('SELECT 1 FROM attachments WHERE id = :i', ['i' => $pid]) === false, 'the admin may delete anyone\'s');
act($owner, '/files/delete.php', ['attachment' => $cid]);
finish();

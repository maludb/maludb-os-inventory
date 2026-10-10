<?php
/** The world of slice 9 (reports-admin.md "Proof"): slices 1–8 composed; checked once. */
require __DIR__ . '/lib.php';
$w = admin_world();
ok($w['so1'] !== null && $w['so2'] !== null, 'the worlds of slices 1–8 are built (SO-1 and SO-2 exist)');
ok((int) one('SELECT count(*) FROM document_sequences') === 7 && (int) one('SELECT count(*) FROM tax_rates') >= 1 && (int) one('SELECT count(*) FROM reason_codes') >= 10, 'the sequences, the tax rates and the reason codes are seeded');
finish();

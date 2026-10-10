<?php
/** The world of slice 5 (orders.md "Proof"): slice 4's world plus the customers, the tax rate, the business and the Buyer; what the later proofs stand on, checked once. */
require __DIR__ . '/lib.php';
$w = order_world();
ok($w['wh'] && $w['sr'] && $w['src_malouf'] && $w['src_zinus_feed'], "slice 4's world: the locations and the suppliers' sources");
ok($w['alvarez'] !== null && $w['birch'] !== null, 'the customers SMOKE Alvarez and SMOKE Birch (no email)');
ok((string) one('SELECT name FROM tax_rates WHERE is_default AND archived_at IS NULL') === 'Cook County', 'Cook County is the default tax rate');
ok((int) one('SELECT buyer_member_id FROM inv_settings WHERE id = 1') === 40 && one('SELECT business_name FROM inv_settings WHERE id = 1') === 'SMOKE Business', 'the Buyer is Nora; the business is SMOKE Business');
ok($w['lv_malouf_ck'] !== null && $w['lv_zinus_k'] !== null, 'Malouf offers the Cal King and Zinus the King (matched listing variants)');
$o = q('SELECT cost_price, lead_time_days, price, availability FROM listing_variants WHERE id = :id', ['id' => $w['lv_malouf_ck']])[0];
echo "     Malouf Cal King: cost {$o['cost_price']} price {$o['price']} lead {$o['lead_time_days']} {$o['availability']}\n";
$o = q('SELECT cost_price, lead_time_days, price, availability FROM listing_variants WHERE id = :id', ['id' => $w['lv_zinus_k']])[0];
echo "     Zinus King: cost {$o['cost_price']} price {$o['price']} lead {$o['lead_time_days']} {$o['availability']}\n";
$casper = $w['lv_casper_k'] === null ? null : q('SELECT l.source_id, s.supplier_id FROM listing_variants lv JOIN listings l ON l.id = lv.listing_id JOIN sources s ON s.id = l.source_id WHERE lv.id = :id', ['id' => $w['lv_casper_k']])[0];
ok($casper !== null && $casper['supplier_id'] === null, 'the Casper site (a reference source) also lists the King — the "against a reference" refusal has an offer');
finish();

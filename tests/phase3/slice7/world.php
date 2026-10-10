<?php
/** The world of slice 7 (feed.md "Proof"): slice 4's catalog, stock and sources, the two price lists and the three keys minted through the handler; checked once. */
require __DIR__ . '/lib.php';
$w = feed_world();
ok($w['wh'] && $w['sr'] && $w['src_zinus_feed'] && $w['queen'] && $w['king'], "slice 4's world: the locations, the supplier sources, the Queen and the King");
ok($w['dealer20'] !== null && $w['inactive5'] !== null && (float) one('SELECT percent_off_retail FROM price_lists WHERE id = :i', ['i' => $w['dealer20']]) === 20.0 && one('SELECT active FROM price_lists WHERE id = :i', ['i' => $w['inactive5']]) === false, 'the price lists: Dealer 20 (20 %) and Inactive 5 (inactive)');
ok($w['k_website'] > 0 && $w['k_partner'] > 0 && $w['k_sister'] > 0 && preg_match('/^feed_[0-9a-f]{48}$/', feed_raw('website')) === 1, 'the three keys are minted and their raw values were handed back once');
ok(key_row($w['k_partner'])['consumer_kind'] === 'partner' && (int) key_row($w['k_partner'])['price_list_id'] === $w['dealer20'] && (int) key_row($w['k_sister'])['rate_per_day'] === 3, 'Partner Store names Dealer 20 and Sister installation has 3 calls a day');
finish();

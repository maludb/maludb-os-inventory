<?php
/** The world (sources.md "Proof"): slice 1's catalog, the dealer and its price sheet, the King's MPN and the Queen's ASIN — read from the fixtures. Run first. */
require __DIR__ . '/lib.php';
echo "The world\n";
$w = sources_world();
ok($w['queen'] !== null && $w['king'] !== null && $w['ck'] !== null, 'the catalog is there');
ok(one('SELECT barcode FROM product_variants WHERE id = :v', ['v' => $w['queen']]) === '00850123450035', 'the Queen carries the Shopify fixture\'s GTIN (rule 1 will match it)');
ok((int) one('SELECT count(*) FROM supplier_items WHERE supplier_id = :s', ['s' => $w['dealer']]) === 2, 'the dealer\'s price sheet names two Item Numbers of the feed fixture (rule 2)');
ok($w['king_mpn'] !== '' && str_contains((string) file_get_contents(fix_dir() . '/jsonld/page-cloudrest-hybrid.html'), '"mpn":"' . $w['king_mpn'] . '"'), 'the King\'s MPN is the marked-up page\'s (' . $w['king_mpn'] . ', rule 3)');
ok($w['queen_asin'] !== '' && str_contains((string) file_get_contents(fix_dir() . '/woocommerce/products-page1.json'), '"id": ' . $w['queen_asin']) || str_contains((string) file_get_contents(fix_dir() . '/woocommerce/products-page1.json'), '"id":' . $w['queen_asin']), 'the Queen\'s ASIN is the WooCommerce Queen variation\'s id (' . $w['queen_asin'] . ', rule 4)');
ok((int) one('SELECT count(*) FROM sources') === 0 && (int) one('SELECT count(*) FROM source_templates WHERE active') >= 18, 'no source yet; the templates are seeded (' . one('SELECT count(*) FROM source_templates WHERE active') . ')');
finish();

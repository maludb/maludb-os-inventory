<?php
/** The world (catalog.md "Proof"): Nora makes the brands, the mattress with six sizes from the Shopify fixture, the foundation, the topper, the pillow and the set — through the handlers. Run first. */
require __DIR__ . '/lib.php';
echo "The world\n";
$w = catalog_world();
ok($w['brand_cloudrest'] !== null && $w['brand_zinus'] !== null, 'the two brands exist');
ok($w['mattress'] !== null && $w['foundation'] !== null && $w['topper'] !== null && $w['pillow'] !== null && $w['set'] !== null, 'the five products exist');
ok((int) one('SELECT count(*) FROM product_variants WHERE product_id = :p', ['p' => $w['mattress']]) === 6, 'the mattress has six variants');
ok(one('SELECT size_key FROM product_variants WHERE id = :v', ['v' => $w['calking']]) === 'california_king', 'size_key derived: "Cal King" → california_king');
ok(one('SELECT barcode FROM product_variants WHERE id = :v', ['v' => $w['calking']]) === '00850123450059', 'the fixture\'s "8-50123-45005-9" is stored as GTIN-14 00850123450059');
ok((int) one('SELECT count(*) FROM product_variants WHERE barcode IS NOT NULL AND product_id = :p', ['p' => $w['mattress']]) === 6, 'every mattress size carries its GTIN from the fixture (slice 3\'s pulls will match them)');
ok((int) one('SELECT count(*) FROM bundle_components WHERE bundle_variant_id = :b', ['b' => $w['set_q']]) === 2, 'the set holds two components (mattress Queen + foundation Queen)');
ok(one('SELECT options::text FROM products WHERE id = :p', ['p' => $w['topper']]) === '["Size", "Thickness"]', 'the topper has two options, Size first');
ok((int) one('SELECT count(*) FROM price_history') >= 20, 'every priced variant left price_history rows (' . one('SELECT count(*) FROM price_history') . ')');
finish();

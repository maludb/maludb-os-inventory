<?php
declare(strict_types=1);
/** Action `listing_unmatch` (log `listing.unmatch`): the tie to our variant removed; the listing variant returns to the queue. listings.match. */
require_once dirname(__DIR__, 2) . '/app/features/listings/handler.php';
require_matcher();
$pdo = db();
$lv = listing_from_request($pdo);
if ($lv['variant_id'] === null) { refuse(422, 'That listing variant is not matched.'); }
$before = inv_guard($pdo, static function () use ($pdo, $lv): array {
    $pdo->beginTransaction();
    $before = unmatch_listing_variant($pdo, $lv['listing_variant_id']);
    listing_log($pdo, 'listing.unmatch', 'listing_variant', $lv['listing_variant_id'], (int) $before['source_id'], ['listing_variant_id' => $lv['listing_variant_id'], 'listing_id' => $lv['listing_id'],
        'variant_id' => (int) $before['variant_id'], 'sku' => $before['sku'], 'match_kind' => $before['match_kind']]);
    $pdo->commit();
    return $before;
});
inv_done('Unmatched ' . $lv['title'] . ' from ' . $before['sku'], $lv['listing_variant_id'], listing_land($lv, 'unmatched'), 'listingChanged');

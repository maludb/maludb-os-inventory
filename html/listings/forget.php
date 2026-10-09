<?php
declare(strict_types=1);
/** Action `listing_forget` (log `listing.forget`: listing_id, source_id, external_id, title ≤ 200, variants; an agent's pauses — deletion): "Not ours" — every variant unmatched, every open proposal dismissed, hidden from the queue, never matched or scored again; the listing and its offers stay. listings.match. */
require_once dirname(__DIR__, 2) . '/app/features/listings/handler.php';
require_matcher();
$pdo = db();
$l = listing_or_404($pdo, request_integer('listing') ?? request_integer('listing_id'));
$n = inv_guard($pdo, static function () use ($pdo, $l): int {
    $pdo->beginTransaction();
    $n = forget_listing($pdo, $l['listing_id'], (int) current_member_id());
    listing_log($pdo, 'listing.forget', 'listing', $l['listing_id'], $l['source_id'], ['listing_id' => $l['listing_id'], 'source_id' => $l['source_id'], 'external_id' => $l['external_id'],
        'title' => mb_substr((string) $l['title'], 0, 200), 'variants' => $n]);
    $pdo->commit();
    return $n;
});
inv_done('Marked ' . $l['title'] . ' not ours', $l['listing_id'], inv_land(return_path('/listings/' . $l['listing_id']), 'forgotten'), 'listingChanged', ['variants_unmatched' => $n]);

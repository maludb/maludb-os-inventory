<?php
declare(strict_types=1);
/** Action `proposal_dismiss` (log `listing.dismiss`): the pair remembered as dismissed — never scored again. listings.match. */
require_once dirname(dirname(__DIR__), 2) . '/app/features/listings/handler.php';
require_matcher();
$pdo = db();
$p = find_proposal($pdo, (int) (request_integer('proposal') ?? request_integer('proposal_id') ?? 0)) ?? refuse(404, 'Proposal not found.');
$lv = listing_variant_or_404($pdo, $p['listing_variant_id']);
inv_guard($pdo, static function () use ($pdo, $p): void {
    $pdo->beginTransaction();
    dismiss_proposal($pdo, $p['proposal_id'], (int) current_member_id());
    listing_log($pdo, 'listing.dismiss', 'listing_variant', $p['listing_variant_id'], $p['source_id'], ['proposal_id' => $p['proposal_id'], 'listing_variant_id' => $p['listing_variant_id'], 'variant_id' => $p['variant_id'],
        'sku' => $p['sku'], 'confidence' => $p['confidence']]);
    $pdo->commit();
});
inv_done('Dismissed the proposal of ' . $p['sku'], $p['listing_variant_id'], listing_land($lv, 'dismissed'), 'listingChanged', ['proposal_id' => $p['proposal_id']]);

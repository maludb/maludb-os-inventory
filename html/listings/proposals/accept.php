<?php
declare(strict_types=1);
/** Action `proposal_accept` (log `listing.accept`: proposal_id, listing_variant_id, variant_id, sku, confidence, evidence keys, proposed_by): matched `proposed_accepted`; the other open proposals of the listing variant dismissed. An agent never accepts its own, another agent's or the matcher's (the SQL's sentence). listings.match. */
require_once dirname(dirname(__DIR__), 2) . '/app/features/listings/handler.php';
require_matcher();
$pdo = db();
$p = find_proposal($pdo, (int) (request_integer('proposal') ?? request_integer('proposal_id') ?? 0)) ?? refuse(404, 'Proposal not found.');
$lv = listing_variant_or_404($pdo, $p['listing_variant_id']);
$row = inv_guard($pdo, static function () use ($pdo, $p): array {
    $pdo->beginTransaction();
    try {
        $row = accept_proposal($pdo, $p['proposal_id'], (int) current_member_id());
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '42501') { $pdo->rollBack(); refuse(403, db_raise_text($e)); }    // the SQL's insufficient_privilege: an agent on a proposal no person made
        throw $e;
    }
    listing_log($pdo, 'listing.accept', 'listing_variant', $p['listing_variant_id'], $p['source_id'], ['proposal_id' => $p['proposal_id'], 'listing_variant_id' => $p['listing_variant_id'],
        'variant_id' => $p['variant_id'], 'sku' => $row['sku'], 'confidence' => $p['confidence'], 'evidence' => array_keys($p['evidence']), 'proposed_by' => $p['proposed_by'] === null ? null : (int) $p['proposed_by']]);
    $pdo->commit();
    return $row;
});
inv_done('Accepted: matched to ' . $row['sku'], $p['listing_variant_id'], listing_land($lv, 'accepted'), 'listingChanged', ['proposal_id' => $p['proposal_id'], 'variant_id' => $p['variant_id']]);

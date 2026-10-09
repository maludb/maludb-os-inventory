<?php
declare(strict_types=1);
/**
 * Action `listing_match` (log `listing.match`: listing_variant_id, variant_id, sku, match_kind, confidence; source_id): a person's match —
 * inv_listing_match(… 'manual'): never across sizes, never a listing marked not ours. An agent is admitted only by a shared identifier, or a
 * person's pending proposal for the pair (then recorded through inv_proposal_accept()); otherwise 403 in words. listings.match.
 */
require_once dirname(__DIR__, 2) . '/app/features/listings/handler.php';
require_matcher();
$pdo = db();
$lv = listing_from_request($pdo);
$errors = [];
$variantId = listing_variant_from_request($pdo, $errors);
if ($errors !== []) { inv_refuse_fields($errors); }
$me = (int) current_member_id();
$viaProposal = null;
if (current_agent_run_id() !== null) {
    $rule = agent_may_match($pdo, $lv['listing_variant_id'], $variantId);
    if ($rule !== null && str_starts_with($rule, 'proposal:')) { $viaProposal = (int) substr($rule, 9); }
    elseif ($rule !== null) { refuse(403, $rule); }
}
$row = inv_guard($pdo, static function () use ($pdo, $lv, $variantId, $me, $viaProposal): array {
    $pdo->beginTransaction();
    if ($viaProposal !== null) {
        accept_proposal($pdo, $viaProposal, $me);
        $row = listing_variant_match_row($pdo, $lv['listing_variant_id']);
    } else {
        $row = match_listing_variant($pdo, $lv['listing_variant_id'], $variantId, $me);
    }
    listing_log($pdo, 'listing.match', 'listing_variant', $lv['listing_variant_id'], (int) $row['source_id'], ['listing_variant_id' => $lv['listing_variant_id'], 'listing_id' => $lv['listing_id'],
        'variant_id' => $variantId, 'sku' => $row['sku'], 'match_kind' => $row['match_kind'], 'confidence' => $row['match_confidence'], 'proposal_id' => $viaProposal]);
    $pdo->commit();
    return $row;
});
inv_done('Matched ' . $lv['title'] . ' to ' . $row['sku'], $lv['listing_variant_id'], listing_land($lv, 'matched'), 'listingChanged', ['match_kind' => $row['match_kind'], 'variant_id' => $variantId]);

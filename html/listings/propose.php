<?php
declare(strict_types=1);
/**
 * Action `match_propose` (log `listing.propose`: proposal_id, listing_variant_id, variant_id, sku, confidence, evidence keys, proposed_by, written):
 * with `variant` one pair proposed by the asker (`confidence` 0–1, 0.5 by default; `evidence` JSON); without, a fresh scoring by the matcher
 * (proposer NULL). listings.match.
 */
require_once dirname(__DIR__, 2) . '/app/features/listings/handler.php';
require_matcher();
$pdo = db();
$lv = listing_from_request($pdo);
if ($lv['forgotten_at'] !== null) { refuse(422, 'That listing was marked not ours'); }
if ($lv['variant_id'] !== null) { refuse(422, 'That listing variant is matched — unmatch it first.'); }
$errors = [];
$variantId = listing_variant_from_request($pdo, $errors, false);
$confidence = 0.5;
if (req_has('confidence') && (string) req_val('confidence') !== '') {
    $c = (string) req_val('confidence');
    if (!is_numeric($c) || (float) $c < 0 || (float) $c > 1) { $errors['confidence'] = 'The confidence is between 0 and 1.'; } else { $confidence = round((float) $c, 3); }
}
$evidence = [];
if (req_has('evidence') && (string) req_val('evidence') !== '') {
    $evidence = json_decode((string) $_POST['evidence'], true);
    if (!is_array($evidence)) { $errors['evidence'] = 'The evidence is a JSON object.'; $evidence = []; }
}
if ($errors !== []) { inv_refuse_fields($errors); }
$me = (int) current_member_id();
$res = inv_guard($pdo, static function () use ($pdo, $lv, $variantId, $confidence, $evidence, $me): array {
    $pdo->beginTransaction();
    $res = propose_match($pdo, $lv['listing_variant_id'], $variantId, $confidence, $evidence, $variantId === null ? null : $me);
    listing_log($pdo, 'listing.propose', 'listing_variant', $lv['listing_variant_id'], $lv['source_id'], ['proposal_id' => $res['proposal_id'], 'listing_variant_id' => $lv['listing_variant_id'],
        'variant_id' => $variantId, 'sku' => $variantId === null ? null : one_value($pdo, 'SELECT sku FROM product_variants WHERE id = :v', ['v' => $variantId]), 'confidence' => $variantId === null ? null : $confidence,
        'evidence' => array_keys($evidence), 'proposed_by' => $variantId === null ? null : $me, 'written' => $res['written']]);
    $pdo->commit();
    return $res;
});
inv_done($variantId === null ? 'Scored again: ' . $res['written'] . ' proposal' . ($res['written'] === 1 ? '' : 's') . ' written' : 'Proposed the pair', $res['proposal_id'] ?? $lv['listing_variant_id'],
    listing_land($lv, $variantId === null ? 'scored' : 'proposed'), 'listingChanged', $res);

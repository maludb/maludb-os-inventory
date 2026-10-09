<?php
declare(strict_types=1);

/** Listings' writes (sources.md "Writes"): every one is the database's verb; the caller holds the transaction. */

/** A person's match (or an agent's admitted one): inv_listing_match(…, 'manual'). Returns the row after. */
function match_listing_variant(PDO $pdo, int $lvId, int $variantId, int $by): array
{
    $pdo->prepare("SELECT inv_listing_match(:lv, :v, :by, 'manual')")->execute(['lv' => $lvId, 'v' => $variantId, 'by' => $by]);
    return listing_variant_match_row($pdo, $lvId);
}

function unmatch_listing_variant(PDO $pdo, int $lvId): array
{
    $before = listing_variant_match_row($pdo, $lvId);
    $pdo->prepare('SELECT inv_listing_unmatch(:lv)')->execute(['lv' => $lvId]);
    return $before;
}

/** The base-table facts of a listing variant's match (the log's). */
function listing_variant_match_row(PDO $pdo, int $lvId): array
{
    $st = $pdo->prepare('SELECT lv.id, lv.listing_id, l.source_id, lv.variant_id, v.sku, lv.match_kind, lv.match_confidence FROM listing_variants lv JOIN listings l ON l.id = lv.listing_id
                           LEFT JOIN product_variants v ON v.id = lv.variant_id WHERE lv.id = :lv');
    $st->execute(['lv' => $lvId]);
    return $st->fetch() ?: throw new DomainException('Not found.');
}

/**
 * A proposal: with a variant, one row by the asker (confidence 0.5 by default, the evidence as given); without, the matcher's fresh scoring
 * (proposer NULL — the spec's DECISION). Returns {proposal_id|null, written}.
 */
function propose_match(PDO $pdo, int $lvId, ?int $variantId, float $confidence, array $evidence, ?int $by): array
{
    if ($variantId === null) {
        $st = $pdo->prepare('SELECT inv_propose_matches(:lv, NULL)');
        $st->execute(['lv' => $lvId]);
        return ['proposal_id' => null, 'written' => (int) $st->fetchColumn()];
    }
    $existing = one_value($pdo, 'SELECT status FROM match_proposals WHERE listing_variant_id = :lv AND variant_id = :v', ['lv' => $lvId, 'v' => $variantId]);
    if ($existing !== null) { throw new DomainException('That pair is already ' . $existing); }
    $sizes = $pdo->prepare('SELECT inv_sizes_agree(lv.size_key, v.size_key) FROM listing_variants lv, product_variants v WHERE lv.id = :lv AND v.id = :v');
    $sizes->execute(['lv' => $lvId, 'v' => $variantId]);
    if ($sizes->fetchColumn() === false) { throw new DomainException('A match never crosses sizes — propose a variant of the listing\'s size'); }
    $st = $pdo->prepare('INSERT INTO match_proposals (listing_variant_id, variant_id, confidence, evidence, proposed_by) VALUES (:lv, :v, :c, CAST(:e AS jsonb), :by) RETURNING id');
    $st->execute(['lv' => $lvId, 'v' => $variantId, 'c' => $confidence, 'e' => json_encode((object) $evidence), 'by' => $by]);
    return ['proposal_id' => (int) $st->fetchColumn(), 'written' => 1];
}

function accept_proposal(PDO $pdo, int $id, int $by): array
{
    $pdo->prepare('SELECT inv_proposal_accept(:p, :by)')->execute(['p' => $id, 'by' => $by]);
    $st = $pdo->prepare('SELECT mp.*, v.sku FROM match_proposals mp JOIN product_variants v ON v.id = mp.variant_id WHERE mp.id = :p');
    $st->execute(['p' => $id]);
    return $st->fetch();
}

function dismiss_proposal(PDO $pdo, int $id, int $by): void
{
    $pdo->prepare('SELECT inv_proposal_dismiss(:p, :by)')->execute(['p' => $id, 'by' => $by]);
}

/** "Not ours" (db/017): every variant unmatched, every open proposal dismissed, forgotten. Returns how many variants were unmatched. */
function forget_listing(PDO $pdo, int $id, int $by): int
{
    $st = $pdo->prepare('SELECT inv_listing_forget(:l, :by)');
    $st->execute(['l' => $id, 'by' => $by]);
    return (int) $st->fetchColumn();
}

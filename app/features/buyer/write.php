<?php
declare(strict_types=1);

/** The Buyer agent's writes (returns-worker.md): a proposal made, accepted or dismissed; the morning note sent. The database keeps the shape; the rules of "one open proposal per kind and subject" are here. */

/** The subject types a proposal can be about, by the manifest's word: word => [subject_type, view, id column, label]. */
const PROPOSAL_SUBJECTS = [
    'variant'         => ['product_variant', 'mcp_product_variants', 'variant_id', 'variant'],
    'listing_variant' => ['listing_variant', 'mcp_listing_variants', 'listing_variant_id', 'listing variant'],
    'line'            => ['sales_order_line', 'mcp_sales_order_lines', 'line_id', 'order line'],
    'purchase_order'  => ['purchase_order', 'mcp_purchase_orders', 'purchase_order_id', 'purchase order'],
];

/**
 * A proposal. $f: kind, title, subject_type, subject_id, detail (array), drafted_record_type, drafted_record_id. One open proposal per (kind, subject): a proposed one → "Already proposed on <date> (#id)";
 * a dismissed one in the last 30 days → "Dismissed on <date>: <reason>"; an accepted one does not block (a new day, a new reorder). Returns the id.
 */
function make_buyer_proposal(PDO $pdo, array $f, int $by): int
{
    $open = one_row($pdo, "SELECT proposal_id, note_date FROM mcp_buyer_proposals WHERE kind = :k AND subject_type = :t AND subject_id = :s AND status = 'proposed' ORDER BY proposal_id DESC LIMIT 1",
        ['k' => $f['kind'], 't' => $f['subject_type'], 's' => $f['subject_id']]);
    if ($open !== null) { throw new DomainException('Already proposed on ' . format_date((string) $open['note_date']) . ' (#' . $open['proposal_id'] . ')|' . $open['proposal_id']); }
    $gone = one_row($pdo, "SELECT proposal_id, decided_at, detail->>'dismiss_reason' AS reason FROM mcp_buyer_proposals WHERE kind = :k AND subject_type = :t AND subject_id = :s AND status = 'dismissed'
                            AND decided_at > now() - interval '30 days' ORDER BY decided_at DESC LIMIT 1", ['k' => $f['kind'], 't' => $f['subject_type'], 's' => $f['subject_id']]);
    if ($gone !== null) {
        throw new DomainException('Dismissed on ' . format_date(substr((string) $gone['decided_at'], 0, 10)) . ((string) $gone['reason'] !== '' ? ': ' . str_replace('|', '/', (string) $gone['reason']) : '') . '|' . $gone['proposal_id']);
    }
    $st = $pdo->prepare('INSERT INTO buyer_proposals (kind, subject_type, subject_id, title, detail, drafted_record_type, drafted_record_id, proposed_by, note_date)
                         VALUES (:k, :t, :s, :title, CAST(:d AS jsonb), :drt, :dri, :by, CAST(:nd AS date)) RETURNING id');
    $st->execute(['k' => $f['kind'], 't' => $f['subject_type'], 's' => $f['subject_id'], 'title' => $f['title'], 'd' => json_encode($f['detail'] === [] ? new stdClass() : $f['detail'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'drt' => $f['drafted_record_type'] ?? null, 'dri' => $f['drafted_record_id'] ?? null, 'by' => $by, 'nd' => business_today($pdo)]);
    return (int) $st->fetchColumn();
}

/** A person accepts: the proposal is accepted and the drafted record stays drafted (the person sends the purchase order, accepts the match). ['before' => status, 'proposal' => row]. */
function accept_buyer_proposal(PDO $pdo, int $id, int $by): array
{
    $p = find_buyer_proposal($pdo, $id);
    if ($p === null) { throw new DomainException('Not found.'); }
    $st = $pdo->prepare("UPDATE buyer_proposals SET status = 'accepted', decided_by = :by, decided_at = now() WHERE id = :id AND status = 'proposed'");
    $st->execute(['by' => $by, 'id' => $id]);
    if ($st->rowCount() !== 1) { throw new DomainException('That proposal is already ' . $p['status'] . '.'); }
    return ['before' => $p['status'], 'proposal' => find_buyer_proposal($pdo, $id)];
}

/** Dismissed with a reason (kept in detail.dismiss_reason); not made again for 30 days. */
function dismiss_buyer_proposal(PDO $pdo, int $id, ?string $reason, int $by): array
{
    $p = find_buyer_proposal($pdo, $id);
    if ($p === null) { throw new DomainException('Not found.'); }
    $st = $pdo->prepare("UPDATE buyer_proposals SET status = 'dismissed', decided_by = :by, decided_at = now(),
                                detail = CASE WHEN CAST(:r AS text) IS NULL THEN detail ELSE detail || jsonb_build_object('dismiss_reason', CAST(:r AS text)) END WHERE id = :id AND status = 'proposed'");
    $st->execute(['by' => $by, 'r' => $reason, 'id' => $id]);
    if ($st->rowCount() !== 1) { throw new DomainException('That proposal is already ' . $p['status'] . '.'); }
    return ['before' => $p['status'], 'proposal' => find_buyer_proposal($pdo, $id)];
}

/**
 * The morning note to the Buyer: the bell row and the e-mail by the person's preferences, once a day to each person (a second send is a no-op). The recipient: $toMember, else the settings'
 * Buyer, else the first super-admin; none → a refusal in words. ['queued' => bool, 'to' => member id, 'notification_id' => ?int, 'date' => Y-m-d].
 */
function send_morning_note(PDO $pdo, string $body, ?int $toMember, int $by): array
{
    $to = $toMember;
    if ($to !== null && one_value($pdo, "SELECT 1 FROM members WHERE id = :m AND status = 'active' AND capability IS NOT NULL", ['m' => $to]) === null) {
        throw new DomainException('That member is not here.');
    }
    $to ??= buyer_member($pdo);
    if ($to === null) { throw new DomainException('No Buyer is set — Settings › the Buyer.'); }
    $date = business_today($pdo);
    $day = (int) str_replace('-', '', $date);
    $again = one_value($pdo, "SELECT 1 FROM notifications WHERE member_id = :m AND kind = 'morning_note' AND record_id = :d", ['m' => $to, 'd' => $day]) !== null;
    $id = $again ? null : notify($pdo, $to, 'morning_note', 'morning_note', $day, 'Morning note — ' . $date, $body, 'morning_note:' . $date . ':' . $to, false);
    return ['queued' => $id !== null, 'to' => $to, 'notification_id' => $id, 'date' => $date];
}

// ---- the handlers' prelude (html/proposals/*.php) ------------------------------------------------------------------------------------------

function proposals_write_begin(string $right): void
{
    inv_handler_begin();
    require_right($right);
}

function proposal_or_404(PDO $pdo, ?int $id): array
{
    $p = $id === null ? null : find_buyer_proposal($pdo, $id);
    if ($p === null) { refuse(404, 'Proposal not found.'); }
    return $p;
}

/** log_activity() for a proposal: ids, kind, the subject and the drafted record, the title — never the evidence's words. */
function proposal_log(PDO $pdo, string $action, array $p, array $extra = []): void
{
    log_activity($pdo, $action, 'buyer_proposal', (int) $p['proposal_id'], ['after' => ['proposal_id' => (int) $p['proposal_id'], 'kind' => $p['kind'], 'subject_type' => $p['subject_type'], 'subject_id' => (int) $p['subject_id'],
        'title' => $p['title'], 'drafted_record_type' => $p['drafted_record_type'], 'drafted_record_id' => $p['drafted_record_id']] + $extra]);
}

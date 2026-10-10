<?php
declare(strict_types=1);
/**
 * Action `buyer_propose` (log `buyer.propose`: proposal_id, kind, subject_type, subject_id, title, drafted_record_type, drafted_record_id; undo buyer_proposal_dismiss): purchasing.write. `kind` (reorder, match, price,
 * at_risk — the schema also admits source, po_overdue, return), `subject` (what it is about, 1–200), exactly one of `variant` / `listing_variant` / `line` / `purchase_order` (the record, through its view: unseen = 404),
 * `drafted_record` (the purchase order a reorder drafted, the match proposal a match drafted), `evidence` (a JSON object) and `confidence` (0–1) kept in the detail. One open proposal per kind and subject, and a
 * dismissed one is not made again for 30 days — the sentences say which. Location /proposals/#proposal-card-{id}; refresh proposalChanged.
 */
require_once dirname(__DIR__, 2) . '/app/features/orders/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/buyer/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/buyer/present.php';
require_once dirname(__DIR__, 2) . '/app/features/buyer/write.php';
proposals_write_begin('purchasing.write');
$pdo = db();
$errors = [];
$kind = trim((string) (req_val('kind') ?? ''));
if (!isset(PROPOSAL_KINDS[$kind])) { $errors['kind'] = 'The kind is reorder, match, price or at_risk.'; }
$title = trim((string) (req_val('subject') ?? req_val('title') ?? ''));
if ($title === '' || mb_strlen($title) > 200) { $errors['subject'] = 'Say what it is about, in 1 to 200 characters.'; }
$given = array_values(array_filter(array_keys(PROPOSAL_SUBJECTS), static fn (string $w): bool => trim((string) (req_val($w) ?? '')) !== ''));
$subjectType = null;
$subjectId = null;
if (count($given) !== 1) { $errors['variant'] = 'Name exactly one record: a variant, a listing_variant, a line or a purchase_order.'; }
else {
    $w = $given[0];
    [$subjectType, $view, $col] = PROPOSAL_SUBJECTS[$w];
    $v = trim((string) req_val($w));
    if ($w === 'variant') { $row = line_variant($pdo, $v); $subjectId = $row === null ? null : (int) $row['variant_id']; if ($row !== null && one_value($pdo, 'SELECT 1 FROM mcp_product_variants WHERE variant_id = :v', ['v' => $subjectId]) === null) { $subjectId = null; } }
    elseif ($w === 'purchase_order' && !ctype_digit($v)) { $id = one_value($pdo, 'SELECT purchase_order_id FROM mcp_purchase_orders WHERE upper(number) = upper(:n)', ['n' => $v]); $subjectId = $id === null ? null : (int) $id; }
    else { $subjectId = ctype_digit($v) && one_value($pdo, "SELECT 1 FROM $view WHERE $col = :id", ['id' => (int) $v]) !== null ? (int) $v : null; }
    if ($subjectId === null) { refuse(404, 'That ' . PROPOSAL_SUBJECTS[$w][3] . ' is not here.'); }
}
$draftedType = null;
$draftedId = null;
$dv = trim((string) (req_val('drafted_record') ?? ''));
if ($dv !== '') {
    $draftedType = match ($kind) { 'reorder' => 'purchase_order', 'match' => 'match_proposal', default => null };
    if ($draftedType === null) { $errors['drafted_record'] = 'Only a reorder or a match drafts a record.'; }
    else {
        $view = $draftedType === 'purchase_order' ? ['mcp_purchase_orders', 'purchase_order_id'] : ['mcp_match_proposals', 'proposal_id'];
        $id = ctype_digit($dv) ? (int) $dv : ($draftedType === 'purchase_order' ? one_value($pdo, 'SELECT purchase_order_id FROM mcp_purchase_orders WHERE upper(number) = upper(:n)', ['n' => $dv]) : null);
        if ($id === null || one_value($pdo, "SELECT 1 FROM {$view[0]} WHERE {$view[1]} = :id", ['id' => (int) $id]) === null) { $errors['drafted_record'] = 'That drafted record is not here.'; }
        else { $draftedId = (int) $id; }
    }
}
$detail = [];
if (req_has('evidence') && trim((string) req_val('evidence')) !== '') {
    $e = isset($_POST['evidence']) && is_array($_POST['evidence']) ? $_POST['evidence'] : json_decode((string) req_val('evidence'), true);
    if (!is_array($e)) { $errors['evidence'] = 'The evidence is a JSON object.'; } else { $detail['evidence'] = $e; }
}
if (req_has('confidence') && trim((string) req_val('confidence')) !== '') {
    $c = (string) req_val('confidence');
    if (!is_numeric($c) || (float) $c < 0 || (float) $c > 1) { $errors['confidence'] = 'A confidence is between 0 and 1.'; } else { $detail['confidence'] = (float) $c; }
}
foreach (['facts', 'detail'] as $k) { if (req_has($k) && is_array($f = json_decode((string) req_val($k), true))) { $detail += $f; } }
if ($errors !== []) { inv_refuse_fields($errors); }
$id = inv_guard($pdo, static function () use ($pdo, $kind, $title, $subjectType, $subjectId, $detail, $draftedType, $draftedId): int {
    $pdo->beginTransaction();
    $id = make_buyer_proposal($pdo, ['kind' => $kind, 'title' => $title, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'detail' => $detail, 'drafted_record_type' => $draftedType, 'drafted_record_id' => $draftedId], (int) current_member_id());
    proposal_log($pdo, 'buyer.propose', find_buyer_proposal($pdo, $id) ?? [], []);
    $pdo->commit();
    return $id;
});
inv_done('Proposed: ' . $title, $id, '/proposals/#proposal-card-' . $id, 'proposalChanged', ['proposal_id' => $id, 'kind' => $kind, 'drafted_record_type' => $draftedType, 'drafted_record_id' => $draftedId]);

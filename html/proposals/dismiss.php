<?php
declare(strict_types=1);
/**
 * Action `buyer_proposal_dismiss` (log `buyer.dismiss`: the proposal and the reason): purchasing.write; a person or an agent. `reason` (up to 500) is kept in the detail; the same thing is not proposed again for 30
 * days. Location /proposals/#proposal-card-{id}; refresh proposalChanged.
 */
require_once dirname(__DIR__, 2) . '/app/features/orders/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/buyer/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/buyer/present.php';
require_once dirname(__DIR__, 2) . '/app/features/buyer/write.php';
proposals_write_begin('purchasing.write');
$pdo = db();
$p = proposal_or_404($pdo, request_integer('proposal'));
$reason = trim((string) (req_val('reason') ?? ''));
if (mb_strlen($reason) > 500) { inv_refuse_fields(['reason' => 'The reason is up to 500 characters.']); }
inv_guard($pdo, static function () use ($pdo, $p, $reason): void {
    $pdo->beginTransaction();
    dismiss_buyer_proposal($pdo, (int) $p['proposal_id'], $reason === '' ? null : $reason, (int) current_member_id());
    proposal_log($pdo, 'buyer.dismiss', $p, ['status' => 'dismissed', 'reason' => $reason === '' ? null : $reason]);
    $pdo->commit();
});
inv_done('Dismissed: ' . $p['title'], (int) $p['proposal_id'], '/proposals/#proposal-card-' . (int) $p['proposal_id'], 'proposalChanged', ['proposal_id' => (int) $p['proposal_id'], 'status' => 'dismissed']);

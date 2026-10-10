<?php
declare(strict_types=1);
/**
 * Action `buyer_proposal_accept` (log `buyer.accept`): purchasing.write, and a PERSON's act — an agent is refused ("A person accepts a proposal."). The proposal is accepted; the record it drafted stays drafted (the
 * person sends the purchase order from its page, accepts the match in the match queue — nothing else changes here). Location /proposals/#proposal-card-{id}; refresh proposalChanged.
 */
require_once dirname(__DIR__, 2) . '/app/features/orders/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/buyer/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/buyer/present.php';
require_once dirname(__DIR__, 2) . '/app/features/buyer/write.php';
proposals_write_begin('purchasing.write');
if ((current_member()['member_kind'] ?? '') !== 'human') { refuse(403, 'A person accepts a proposal.'); }
$pdo = db();
$p = proposal_or_404($pdo, request_integer('proposal'));
inv_guard($pdo, static function () use ($pdo, $p): void {
    $pdo->beginTransaction();
    accept_buyer_proposal($pdo, (int) $p['proposal_id'], (int) current_member_id());
    proposal_log($pdo, 'buyer.accept', $p, ['status' => 'accepted']);
    $pdo->commit();
});
inv_done('Accepted: ' . $p['title'], (int) $p['proposal_id'], '/proposals/#proposal-card-' . (int) $p['proposal_id'], 'proposalChanged', ['proposal_id' => (int) $p['proposal_id'], 'status' => 'accepted']);

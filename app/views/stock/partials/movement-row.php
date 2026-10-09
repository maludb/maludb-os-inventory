<?php /** One movement (`movement-row-{id}`). Data: r (a stock_movements row), here, tz, mayReverse, seesCost, seesReceiptCost */
$tid = (int) $r['transaction_id'];
$sees = $r['txn_type'] === 'receipt' ? $seesReceiptCost : $seesCost;
$doc = movement_document_url($r);
$docLabel = $r['reference_kind'] === 'reversal' ? 'reverses #' . (int) $r['reference_id'] : ($r['document_number'] ?? $r['reference_kind']);
?>
<tr id="movement-row-<?= $tid ?>">
    <td class="text-nowrap" title="#<?= $tid ?>"><?= e(format_ts($r['occurred_at'], $tz, 'M j, g:i A')) ?></td>
    <td><?= txn_type_chip($r['txn_type']) ?></td>
    <td class="text-nowrap"><?= hx_link(with_back('/variants/' . (int) $r['variant_id'], $here), e($r['sku']), 'fw-semibold') ?></td>
    <td class="d-none d-md-table-cell"><?= hx_link(with_back('/locations/' . (int) $r['location_id'], $here), e($r['location_name']), 'text-dark') ?></td>
    <td class="text-end" id="movement-row-<?= $tid ?>-qty"><?= signed_qty($r['qty']) ?></td>
    <td class="text-end d-none d-md-table-cell" id="movement-row-<?= $tid ?>-cost"><?= cost_cell($r['unit_cost'], $sees) ?></td>
    <td><?= $doc !== null ? hx_link(with_back($doc, $here), e($docLabel), '', 'id="movement-row-' . $tid . '-document"') : e($docLabel) ?></td>
    <td class="d-none d-lg-table-cell"><?= e($r['counterparty_name'] ?? '') ?></td>
    <td class="d-none d-lg-table-cell"><?= e($r['reason_code'] ?? '') ?></td>
    <td class="d-none d-lg-table-cell"><?= e($r['actor_name'] ?? '') ?></td>
    <td class="text-end">
        <?php if ($r['reversed_by'] !== null): ?><span class="badge bg-soft-dark text-dark" id="movement-row-<?= $tid ?>-reversed" title="reversed by #<?= (int) $r['reversed_by'] ?>">reversed</span>
        <?php elseif ($mayReverse && $r['reference_kind'] !== 'reversal'): ?>
            <form method="post" action="/stock/reverse.php" hx-post="/stock/reverse.php" hx-target="#flash" class="d-inline" hx-confirm="Reverse this <?= e(strtolower(TXN_TYPES[$r['txn_type']] ?? $r['txn_type'])) ?> of <?= (int) $r['qty'] ?> <?= e($r['sku']) ?> at <?= e($r['location_name']) ?>? The opposite movement is posted and linked."><?= csrf_field() ?><input type="hidden" name="transaction" value="<?= $tid ?>"><button type="submit" class="btn btn-light btn-touch" id="movement-row-<?= $tid ?>-reverse">Reverse</button></form>
        <?php endif; ?>
    </td>
</tr>

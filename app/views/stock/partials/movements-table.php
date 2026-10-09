<?php /** The movements table (`movements-table`). Data: rows, here, tz, mayReverse, seesCost, seesReceiptCost, empty */ ?>
<div class="card" id="movements-card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="movements-table">
    <thead class="thead-light"><tr><th>When</th><th>Type</th><th>SKU</th><th class="d-none d-md-table-cell">Location</th><th class="text-end">Qty</th><th class="text-end d-none d-md-table-cell">Unit cost</th><th>Document</th><th class="d-none d-lg-table-cell">Counterparty</th><th class="d-none d-lg-table-cell">Reason</th><th class="d-none d-lg-table-cell">By</th><th></th></tr></thead>
    <tbody>
    <?php if ($rows === []): ?><tr><td colspan="11" class="text-center text-muted py-4" id="movements-table-empty"><?= e($empty ?? 'No movements.') ?></td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?><?= view('stock/partials/movement-row.php', ['r' => $r, 'here' => $here, 'tz' => $tz, 'mayReverse' => $mayReverse, 'seesCost' => $seesCost, 'seesReceiptCost' => $seesReceiptCost]) ?><?php endforeach; ?>
    </tbody>
</table></div></div></div>

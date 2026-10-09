<?php /** The levels table (`levels-table`). Data: rows, totals (or null), showLocation, here, footer (bool) */ ?>
<div class="card" id="levels-card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="levels-table">
    <thead class="thead-light"><tr><th>SKU</th><th>Product</th><th class="d-none d-md-table-cell">Size</th><?php if ($showLocation): ?><th>Location</th><?php endif; ?><th class="text-end">On hand</th><th class="text-end d-none d-md-table-cell">Allocated</th><th class="text-end d-none d-md-table-cell">Floor</th><th class="text-end">Available</th></tr></thead>
    <tbody>
    <?php if ($rows === []): ?><tr><td colspan="<?= $showLocation ? 8 : 7 ?>" class="text-center text-muted py-4" id="levels-table-empty">No stock here.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?><?= view('stock/partials/level-row.php', ['r' => $r, 'here' => $here, 'showLocation' => $showLocation]) ?><?php endforeach; ?>
    </tbody>
    <?php if (!empty($footer) && $totals !== null && $rows !== []): ?>
    <tfoot><tr class="fw-semibold" id="levels-table-totals"><td colspan="<?= $showLocation ? 4 : 3 ?>">Total</td><td class="text-end"><?= number_format($totals['on_hand']) ?></td><td class="text-end d-none d-md-table-cell"><?= number_format($totals['allocated']) ?></td><td class="text-end d-none d-md-table-cell"><?= number_format($totals['floor_model']) ?></td><td class="text-end"><?= number_format($totals['available']) ?></td></tr></tfoot>
    <?php endif; ?>
</table></div></div></div>

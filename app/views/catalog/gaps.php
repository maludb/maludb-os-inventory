<?php /** Catalog gaps (screen `catalog-gaps`): the chip row with counts filters the table. Data: rows, counts, gap, here */ ?>
<?= view('shared/header.php', ['id' => 'catalog-gaps', 'title' => 'Catalog gaps', 'crumbs' => [['Home', '/'], ['Products', '/products/'], ['Catalog gaps', null]], 'back' => back_link()]) ?>
<div class="main-content" id="catalog-gaps-content">
    <div class="chip-row mb-3" id="catalog-gaps-chips">
        <?= hx_link('/catalog/gaps', 'All (' . array_sum($counts) . ')', 'btn btn-touch ' . ($gap === null ? 'btn-primary' : 'btn-light'), 'id="catalog-gaps-chip-all"') ?>
        <?php foreach (GAP_KINDS as $k => $w): if ($k === 'no_cost' && !sees_cost()) { continue; } ?><?= hx_link('/catalog/gaps?gap=' . $k, e($w) . ' (' . ($counts[$k] ?? 0) . ')', 'btn btn-touch ' . ($gap === $k ? 'btn-primary' : 'btn-light'), 'id="catalog-gaps-chip-' . $k . '"') ?><?php endforeach; ?>
    </div>
    <div class="card" id="gaps-card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="gaps-table">
        <thead class="thead-light"><tr><th>SKU</th><th>Product</th><th>Size</th><th>Gap</th></tr></thead><tbody>
        <?php if ($rows === []): ?><tr><td colspan="4" class="text-center text-muted py-4" id="gaps-table-empty">Nothing is missing<?= $gap !== null ? ' of that kind' : '' ?>.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?><tr id="gap-row-<?= (int) $r['variant_id'] ?>-<?= e($r['gap']) ?>"><td><?= hx_link(with_back('/variants/' . (int) $r['variant_id'], $here), '<code>' . e($r['sku']) . '</code>') ?></td><td><?= hx_link(with_back('/products/' . (int) $r['product_id'], $here), e($r['product_name'])) ?></td><td><?= e($r['size_name'] ?? '') ?></td><td><?= gap_chip($r['gap']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div></div>
</div>

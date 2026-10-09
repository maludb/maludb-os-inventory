<?php /** One product card (`product-card-{id}`). Data: p (a find_products row with qty sums and `state`) */
$id = (int) $p['product_id'];
$url = with_back('/products/' . $id, here_url());
?>
<div class="card h-100 product-card" id="product-card-<?= $id ?>">
    <a href="<?= e($url) ?>" hx-get="<?= e($url) ?>" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="<?= e($url) ?>" aria-label="<?= e($p['name']) ?>">
        <?php if (!empty($p['primary_image_attachment_id'])): ?><img class="product-card-image" src="/files/<?= (int) $p['primary_image_attachment_id'] ?>/thumb" alt="" loading="lazy">
        <?php else: ?><div class="product-card-icon"><i class="feather-package"></i></div><?php endif; ?>
    </a>
    <div class="card-body">
        <div class="fw-semibold"><?= hx_link($url, e($p['name']), 'text-dark', 'id="product-card-' . $id . '-name"') ?></div>
        <div class="chip-row mt-1">
            <?php if ($p['brand']): ?><span class="badge bg-soft-secondary text-dark"><?= e($p['brand']) ?></span><?php endif; ?>
            <span class="badge bg-soft-light text-dark border"><?= e($p['product_type']) ?></span>
            <?= product_status_chip($p['status']) ?>
            <?php if ($p['kind'] === 'bundle'): ?><span class="badge bg-soft-info text-info">bundle</span><?php endif; ?>
        </div>
        <div class="fs-12 text-muted mt-2"><?= (int) $p['variant_count'] ?> variant<?= (int) $p['variant_count'] === 1 ? '' : 's' ?> · <?= (int) $p['qty_on_hand'] ?> on hand · <?= (int) $p['qty_available'] ?> available</div>
        <div class="fs-12 mt-1 d-flex align-items-center gap-2">
            <?= state_chip($p['state'] ?? null) ?>
            <?php if ($p['retail_from'] !== null): ?><span class="text-muted"><?= money($p['retail_from']) ?><?= $p['retail_to'] !== null && $p['retail_to'] !== $p['retail_from'] ? ' – ' . money($p['retail_to']) : '' ?></span><?php endif; ?>
        </div>
    </div>
</div>

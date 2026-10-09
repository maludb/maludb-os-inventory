<?php /** The product's images (screen `product-images`): the grid and the upload form. Data: product, images, variants, here, notice, max */
$id = (int) $product['product_id'];
$n = count($images);
?>
<?= view('shared/header.php', ['id' => 'product-images', 'title' => 'Images of ' . $product['name'], 'crumbs' => [['Home', '/'], ['Products', '/products/'], [$product['name'], '/products/' . $id], ['Images', null]], 'back' => back_link() ?? ['/products/' . $id, $product['name']]]) ?>
<div class="main-content" id="product-images-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="post" action="/products/images/add.php" hx-post="/products/images/add.php" hx-target="#flash" hx-encoding="multipart/form-data" enctype="multipart/form-data" class="card mb-3" id="product-image-form">
        <?= csrf_field() ?><input type="hidden" name="product" value="<?= $id ?>">
        <div class="card-header"><h5 class="card-title mb-0">Upload an image</h5></div>
        <div class="card-body row g-2 align-items-end">
            <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="product-image-form-field-file">File (JPEG, PNG, WebP, GIF — up to <?= e(fmt_bytes($max)) ?>)</label><input type="file" name="file" id="product-image-form-field-file" class="form-control btn-touch" accept="image/*" required></div>
            <div class="col-12 col-md-3"><label class="form-label fs-12 text-muted" for="product-image-form-field-variant">A size that looks different (optional)</label><select name="variant" id="product-image-form-field-variant" class="form-select btn-touch"><option value="">The product</option><?php foreach ($variants as $v): ?><option value="<?= $v['variant_id'] ?>"><?= e($v['sku'] . ($v['size_name'] ? ' · ' . $v['size_name'] : '')) ?></option><?php endforeach; ?></select></div>
            <div class="col-12 col-md-3"><label class="form-label fs-12 text-muted" for="product-image-form-field-alt_text">Alt text</label><input type="text" name="alt_text" id="product-image-form-field-alt_text" class="form-control btn-touch" maxlength="200"></div>
            <div class="col-12 col-md-2"><input type="hidden" name="is_primary" value="no"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-0" for="product-image-form-field-is_primary"><input type="checkbox" class="form-check-input mt-0" name="is_primary" value="yes" id="product-image-form-field-is_primary" <?= $n === 0 ? 'checked' : '' ?>>Primary</label></div>
            <div class="col-12"><button type="submit" class="btn btn-primary btn-touch" id="product-image-form-save-btn">Upload</button></div>
        </div>
    </form>
    <div class="row g-3" id="product-images-grid">
        <?php if ($images === []): ?><div class="col-12"><div class="card"><div class="card-body text-muted fs-12" id="product-images-empty">No images yet.</div></div></div><?php endif; ?>
        <?php foreach ($images as $i => $im): $iid = $im['image_id']; ?>
        <div class="col-12 col-sm-6 col-lg-3"><div class="card product-image-card h-100" id="product-image-<?= $iid ?>">
            <img src="/files/<?= $im['attachment_id'] ?>/thumb" alt="<?= e($im['alt_text'] ?? '') ?>">
            <div class="card-body fs-12">
                <div class="fw-semibold"><?= $im['is_primary'] ? '<i class="feather-star text-warning me-1" title="primary"></i>' : '' ?><?= e($im['filename']) ?></div>
                <div class="text-muted"><?= e($im['alt_text'] ?? 'no alt text') ?><?= $im['variant_sku'] ? ' · ' . e($im['variant_sku']) : '' ?> · <?= e(fmt_bytes((int) $im['byte_size'])) ?></div>
                <form method="post" action="/products/images/add.php" hx-post="/products/images/add.php" hx-target="#flash" class="mt-2 d-flex flex-wrap gap-1" id="product-image-<?= $iid ?>-form">
                    <?= csrf_field() ?><input type="hidden" name="product" value="<?= $id ?>"><input type="hidden" name="image" value="<?= $iid ?>">
                    <input type="text" name="alt_text" class="form-control btn-touch" style="max-width: 100%" maxlength="200" value="<?= e($im['alt_text'] ?? '') ?>" placeholder="Alt text" aria-label="Alt text">
                    <button type="submit" class="btn btn-light btn-touch" id="product-image-<?= $iid ?>-alt-btn" title="Save the alt text"><i class="feather-check"></i></button>
                    <?php if (!$im['is_primary']): ?><button type="submit" name="is_primary" value="yes" class="btn btn-light btn-touch" id="product-image-<?= $iid ?>-primary-btn" title="Make primary"><i class="feather-star"></i></button><?php endif; ?>
                    <?php if ($i > 0): ?><button type="submit" name="sort_order" value="<?= $images[$i - 1]['sort_order'] ?>" class="btn btn-light btn-touch" id="product-image-<?= $iid ?>-up-btn" title="Move up"><i class="feather-arrow-up"></i></button><?php endif; ?>
                    <?php if ($i < $n - 1): ?><button type="submit" name="sort_order" value="<?= $images[$i + 1]['sort_order'] ?>" class="btn btn-light btn-touch" id="product-image-<?= $iid ?>-down-btn" title="Move down"><i class="feather-arrow-down"></i></button><?php endif; ?>
                </form>
                <form method="post" action="/products/images/remove.php" hx-post="/products/images/remove.php" hx-target="#flash" hx-confirm="Remove <?= e($im['filename']) ?>?" class="mt-1"><?= csrf_field() ?><input type="hidden" name="image" value="<?= $iid ?>"><button type="submit" class="btn btn-outline-danger btn-touch btn-sm" id="product-image-<?= $iid ?>-remove-btn">Remove</button></form>
            </div>
        </div></div>
        <?php endforeach; ?>
    </div>
</div>

<?php
declare(strict_types=1);
/**
 * Action `image_add` (log `product.image_add`: attachment_id, filename, is_primary, variant_id, updated — never a path): catalog.write. With `file`:
 * attachment_store('product', …) then add_image(). With `image` and no file: update_image() — alt_text, is_primary, sort_order, variant (the Primary /
 * Up / Down / alt controls). Location /products/{product}/images#product-image-{id}; refresh productChanged.
 */
require_once dirname(__DIR__, 3) . '/app/features/catalog/handler.php';
require_once dirname(__DIR__, 3) . '/app/attachments.php';
catalog_write_begin('catalog.write');
$pdo = db();
$me = (int) current_member_id();
$p = catalog_product_or_404($pdo, request_integer('product'));
$pid = $p['product_id'];
$errors = [];
$variantId = inv_ref($pdo, 'variant', null, 'SELECT 1 FROM mcp_product_variants WHERE variant_id = :id AND product_id = ' . $pid, 'the variant', $errors);
if ($errors !== []) { inv_refuse_fields($errors); }
$alt = req_has('alt_text') ? (((string) req_val('alt_text') === '') ? null : mb_substr((string) req_val('alt_text'), 0, 200)) : null;
$imageId = request_integer('image');
$hasFile = isset($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
if ($imageId !== null && !$hasFile) {
    $im = find_image($pdo, $imageId);
    if ($im === null || $im['product_id'] !== $pid) { refuse(404, 'Image not found.'); }
    $fields = [];
    if (req_has('alt_text')) { $fields['alt_text'] = $alt; }
    if (req_has('is_primary') && inv_yes('is_primary')) { $fields['is_primary'] = true; }
    if (req_has('sort_order')) { $fields['sort_order'] = inv_int('sort_order', null, 0, 10000, 'The order', $errors, true); }
    if (req_has('variant')) { $fields['variant_id'] = $variantId; }
    if ($errors !== []) { inv_refuse_fields($errors); }
    inv_guard($pdo, static function () use ($pdo, $imageId, $fields, $im, $pid): void {
        $pdo->beginTransaction();
        update_image($pdo, $imageId, $fields);
        catalog_log($pdo, 'product.image_add', 'product', $pid, ['after' => ['product_id' => $pid, 'image_id' => $imageId, 'attachment_id' => $im['attachment_id'], 'filename' => $im['filename'], 'is_primary' => !empty($fields['is_primary']) || $im['is_primary'], 'variant_id' => $fields['variant_id'] ?? $im['variant_id'], 'updated' => true, 'changed' => array_keys($fields)]]);
        $pdo->commit();
    });
    inv_done('Updated the image', $imageId, inv_land('/products/' . $pid . '/images', 'updated', 'product-image-' . $imageId), 'productChanged', ['image_id' => $imageId]);
}
if (!$hasFile) { inv_refuse_fields(['file' => 'Choose an image file (or name an existing image to update).']); }
$primary = inv_yes('is_primary', false);
$imageId = inv_guard($pdo, static function () use ($pdo, $pid, $variantId, $alt, $primary, $me): int {
    $pdo->beginTransaction();
    $attachmentId = attachment_store($pdo, $variantId !== null ? 'product_variant' : 'product', $variantId ?? $pid, $_FILES['file'], $me);
    $st = $pdo->prepare('SELECT mime_type, filename FROM attachments WHERE id = :id');
    $st->execute(['id' => $attachmentId]);
    $a = $st->fetch();
    if (!str_starts_with((string) $a['mime_type'], 'image/')) { attachment_delete($pdo, $attachmentId); throw new DomainException('A product image is an image (JPEG, PNG, WebP or GIF).'); }   // the file goes with the rolled-back row
    $imageId = add_image($pdo, $pid, $variantId, $attachmentId, $alt, $primary, $me);
    catalog_log($pdo, 'product.image_add', 'product', $pid, ['after' => ['product_id' => $pid, 'image_id' => $imageId, 'attachment_id' => $attachmentId, 'filename' => $a['filename'], 'is_primary' => $primary, 'variant_id' => $variantId, 'updated' => false]]);
    $pdo->commit();
    return $imageId;
});
inv_done('Uploaded the image', $imageId, inv_land('/products/' . $pid . '/images', 'added', 'product-image-' . $imageId), 'productChanged', ['image_id' => $imageId]);

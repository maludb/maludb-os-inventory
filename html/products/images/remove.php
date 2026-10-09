<?php
declare(strict_types=1);
/** Action `image_remove` (log `product.image_remove`: attachment_id, filename, is_primary; confirm): remove_image() then attachment_delete(). catalog.write. Location /products/{product}/images. */
require_once dirname(__DIR__, 3) . '/app/features/catalog/handler.php';
require_once dirname(__DIR__, 3) . '/app/attachments.php';
catalog_write_begin('catalog.write');
$pdo = db();
$im = find_image($pdo, request_integer('image') ?? 0);
if ($im === null) { refuse(404, 'Image not found.'); }
inv_guard($pdo, static function () use ($pdo, $im): void {
    $pdo->beginTransaction();
    $attachmentId = remove_image($pdo, $im['image_id']);
    attachment_delete($pdo, $attachmentId);
    catalog_log($pdo, 'product.image_remove', 'product', $im['product_id'], ['after' => ['product_id' => $im['product_id'], 'image_id' => $im['image_id'], 'attachment_id' => $attachmentId, 'filename' => $im['filename'], 'is_primary' => $im['is_primary']]]);
    $pdo->commit();
});
inv_done('Removed the image', $im['image_id'], inv_land('/products/' . $im['product_id'] . '/images', 'removed'), 'productChanged');

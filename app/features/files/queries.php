<?php
declare(strict_types=1);

/**
 * Attachments on any record (returns-worker.md "Notes and attachments"), on top of Phase 2's one contract (app/attachments.php: attachment_store(), inv_can_see_attachment(), attachment_path(),
 * attachment_delete()): the manifest's word for a record mapped to attachments.record_type and the view that decides whether the caller may see the record; the list a record's page shows; the thumbnail.
 * The constant ATTACHMENT_RECORD_TYPES is Phase 2's list of the table's types; the words are ATTACHMENT_WORDS here (fifteen — the manifest's and the table's CHECK agree on fifteen, the spec said fourteen).
 */

require_once dirname(__DIR__, 2) . '/attachments.php';          // attachment_store(), attachment_path(), attachment_delete(), fmt_bytes()

/** word => [attachments.record_type, view, id column]. */
const ATTACHMENT_WORDS = [
    'product'        => ['product', 'mcp_products', 'product_id'],
    'variant'        => ['product_variant', 'mcp_product_variants', 'variant_id'],
    'supplier'       => ['supplier', 'mcp_suppliers', 'supplier_id'],
    'source'         => ['source', 'mcp_sources', 'source_id'],
    'listing'        => ['listing', 'mcp_listings', 'listing_id'],
    'customer'       => ['customer', 'mcp_customers', 'customer_id'],
    'order'          => ['sales_order', 'mcp_sales_orders', 'sales_order_id'],
    'purchase_order' => ['purchase_order', 'mcp_purchase_orders', 'purchase_order_id'],
    'receipt'        => ['goods_receipt', 'mcp_goods_receipts', 'goods_receipt_id'],
    'shipment'       => ['shipment', 'mcp_shipments', 'shipment_id'],
    'return'         => ['return', 'mcp_return_authorizations', 'return_id'],
    'adjustment'     => ['inventory_adjustment', 'mcp_inventory_adjustments', 'adjustment_id'],
    'count'          => ['inventory_count', 'mcp_inventory_counts', 'count_id'],
    'transfer'       => ['inventory_transfer', 'mcp_inventory_transfers', 'transfer_id'],
    'location'       => ['location', 'mcp_locations', 'location_id'],
];

/** The manifest's word (or the table's own record_type) → ['word', 'type', 'view', 'id_column']; a word that takes no files is a 422. */
function attachment_record(string $word): array
{
    $word = strtolower(trim($word));
    if (!isset(ATTACHMENT_WORDS[$word])) {
        foreach (ATTACHMENT_WORDS as $w => $spec) { if ($spec[0] === $word) { $word = $w; break; } }
    }
    if (!isset(ATTACHMENT_WORDS[$word])) { throw new DomainException('That is not a record that takes files.'); }
    [$type, $view, $col] = ATTACHMENT_WORDS[$word];
    return ['word' => $word, 'type' => $type, 'view' => $view, 'id_column' => $col];
}

/** The files of a record, oldest first. [{attachment_id, filename, mime_type, byte_size, uploaded_by, uploaded_by_name, created_at}] */
function find_attachments(PDO $pdo, string $recordType, int $recordId): array
{
    $st = $pdo->prepare('SELECT a.attachment_id, a.filename, a.mime_type, a.byte_size, a.sha256, a.uploaded_by, m.display_name AS uploaded_by_name, a.created_at
                           FROM mcp_attachments a LEFT JOIN mcp_members m ON m.member_id = a.uploaded_by WHERE a.record_type = :t AND a.record_id = :r ORDER BY a.created_at, a.attachment_id');
    $st->execute(['t' => $recordType, 'r' => $recordId]);
    return $st->fetchAll();
}

function thumb_dir(): string
{
    return APP_ROOT . '/storage/attachments/.thumbs';
}

/**
 * The thumbnail of an image (320 px on the long side, a JPEG), made once and cached under storage/attachments/.thumbs/<id>.jpg; null when the attachment is no image or cannot be read.
 * $a is an mcp_attachments row.
 */
function thumb_path(array $a): ?string
{
    if (!str_starts_with((string) $a['mime_type'], 'image/') || !function_exists('imagecreatefromstring')) { return null; }
    $id = (int) $a['attachment_id'];
    $dir = thumb_dir();
    $cached = $dir . '/' . $id . '.jpg';
    if (is_file($cached)) { return $cached; }
    $src = attachment_path($a);
    if ($src === '') { return null; }
    $img = @imagecreatefromstring((string) file_get_contents($src));
    if ($img === false) { return null; }
    $w = imagesx($img);
    $h = imagesy($img);
    $scale = min(1.0, 320 / max(1, max($w, $h)));
    $tw = max(1, (int) round($w * $scale));
    $th = max(1, (int) round($h * $scale));
    $out = imagecreatetruecolor($tw, $th);
    imagefill($out, 0, 0, 0xffffff);
    imagecopyresampled($out, $img, 0, 0, 0, 0, $tw, $th, $w, $h);
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) { return null; }
    imagejpeg($out, $cached, 82);
    @chmod($cached, 0660);
    return is_file($cached) ? $cached : null;
}

/** Remove the cached thumbnail of an attachment (with the attachment). */
function thumb_forget(int $id): void
{
    @unlink(thumb_dir() . '/' . $id . '.jpg');
}

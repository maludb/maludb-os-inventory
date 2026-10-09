<?php
declare(strict_types=1);

/**
 * The one attachment contract (sso-shell.md "Query functions"; returns-worker.md DECISION 16; catalog.md names it): every later slice stores
 * a file with attachment_store(), serves it through html/files.php (the gated door) by inv_can_see_attachment() and attachment_path(), and
 * removes it with attachment_delete(). The size cap is the lesser of inv_settings.max_attachment_bytes and ATTACHMENT_MAX_BYTES; the type
 * is SNIFFED (finfo), never taken from the browser; the allow-list is short (images, PDF, CSV, XLSX, text); the file lives under
 * storage/attachments/<record_type>/<record_id>/<id>-<safe name>; the row carries the sha256. No thumbnailing in version 1 (a thumbnail is
 * the browser's <img> at card size — DECISION). The storage directory is www-data's, 0770, made by deploy/ROOT_STEPS.sh step 0b.
 */

const ATTACHMENT_MIME_ALLOW = ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'application/pdf', 'text/csv', 'text/plain',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
const ATTACHMENT_RECORD_TYPES = ['product', 'product_variant', 'supplier', 'source', 'listing', 'customer', 'sales_order', 'purchase_order', 'goods_receipt',
    'shipment', 'return', 'inventory_adjustment', 'inventory_count', 'inventory_transfer', 'location'];
const ATTACHMENT_REFUSAL = 'That kind of file is not accepted (images, PDF, CSV, XLSX, text).';

/** The cap in bytes: the lesser of the setting (db/005) and ATTACHMENT_MAX_BYTES when set; never under 1 MB. */
function attachment_max_bytes(PDO $pdo): int
{
    $env = (int) (env('ATTACHMENT_MAX_BYTES', '0') ?: 0);
    $set = (int) (one_value($pdo, 'SELECT max_attachment_bytes FROM inv_settings WHERE id = 1') ?: 26214400);
    return max(1048576, $env > 0 ? min($env, $set) : $set);
}

/**
 * Store a file on a record. $file is a $_FILES entry or ['tmp_name', 'name', 'type', 'size'] (a file the worker or an import made).
 * Refuses in a DomainException (inv_guard() turns it into a 422). Returns the attachment id.
 */
function attachment_store(PDO $pdo, string $recordType, int $recordId, array $file, int $by): int
{
    if (!in_array($recordType, ATTACHMENT_RECORD_TYPES, true)) {
        throw new DomainException('That is not a record a file attaches to.');
    }
    if (array_key_exists('error', $file) && (int) $file['error'] !== UPLOAD_ERR_OK) {
        throw new DomainException(in_array((int) $file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'That file is too large.' : 'The upload did not arrive.');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp)) {
        throw new DomainException('The upload did not arrive.');
    }
    $max = attachment_max_bytes($pdo);
    $size = (int) ($file['size'] ?? filesize($tmp));
    if ($size > $max) {
        throw new DomainException('A file is at most ' . intdiv($max, 1048576) . ' MB.');
    }
    if ($size < 1) {
        throw new DomainException('That file is empty.');
    }
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $name = basename(str_replace('\\', '/', (string) ($file['name'] ?? 'file')));
    $name = mb_substr(preg_replace('/[^\w.\- ()]+/u', '_', $name) ?: 'file', 0, 120);
    if (!in_array($mime, ATTACHMENT_MIME_ALLOW, true) || preg_match('/\.(exe|bat|cmd|com|msi|scr|ps1|sh|js|vbs|jar|dll|html?|svg|php|phtml)$/i', $name)) {
        throw new DomainException(ATTACHMENT_REFUSAL);
    }
    $sha = hash_file('sha256', $tmp);
    $st = $pdo->prepare('INSERT INTO attachments (record_type, record_id, filename, mime_type, byte_size, sha256, storage_path, uploaded_by) VALUES (:t, :rid, :n, :m, :s, :h, :p, :by) RETURNING id');
    $st->execute(['t' => $recordType, 'rid' => $recordId, 'n' => $name, 'm' => $mime, 's' => $size, 'h' => $sha, 'p' => 'pending', 'by' => $by]);
    $id = (int) $st->fetchColumn();
    $dir = APP_ROOT . '/storage/attachments/' . $recordType . '/' . $recordId;
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new DomainException('The attachment store is not writable.');
    }
    $path = 'attachments/' . $recordType . '/' . $recordId . '/' . $id . '-' . $name;
    $full = APP_ROOT . '/storage/' . $path;
    if (!(is_uploaded_file($tmp) ? move_uploaded_file($tmp, $full) : rename($tmp, $full))) {
        throw new DomainException('The file could not be stored.');
    }
    @chmod($full, 0660);
    $pdo->prepare('UPDATE attachments SET storage_path = :p WHERE id = :id')->execute(['p' => $path, 'id' => $id]);
    return $id;
}

/** The mcp_attachments row (what the caller may see), or null. The base table's storage_path is read only after the view admitted it. */
function inv_can_see_attachment(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT attachment_id, record_type, record_id, filename, mime_type, byte_size, sha256, uploaded_by, created_at FROM mcp_attachments WHERE attachment_id = :id');
    $st->execute(['id' => $id]);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

/** The absolute path of an attachment's file (the base table's storage_path, under storage/), or '' when it is not there. */
function attachment_path(array $row): string
{
    $id = (int) ($row['attachment_id'] ?? $row['id'] ?? 0);
    $st = db()->prepare('SELECT storage_path FROM attachments WHERE id = :id');
    $st->execute(['id' => $id]);
    $rel = (string) ($st->fetchColumn() ?: '');
    if ($rel === '' || $rel === 'pending') {
        return '';
    }
    $root = realpath(APP_ROOT . '/storage') ?: (APP_ROOT . '/storage');
    $full = realpath($root . '/' . ltrim($rel, '/'));
    return $full !== false && str_starts_with($full, $root . DIRECTORY_SEPARATOR) && is_file($full) ? $full : '';
}

/** Remove the row and the file. The handler reads the record's type and id through inv_can_see_attachment() first, for its location and its log. */
function attachment_delete(PDO $pdo, int $id): void
{
    $st = $pdo->prepare('SELECT storage_path FROM attachments WHERE id = :id');
    $st->execute(['id' => $id]);
    $rel = (string) ($st->fetchColumn() ?: '');
    $pdo->prepare('DELETE FROM attachments WHERE id = :id')->execute(['id' => $id]);
    if ($rel !== '' && $rel !== 'pending') {
        $root = realpath(APP_ROOT . '/storage') ?: (APP_ROOT . '/storage');
        $full = realpath($root . '/' . ltrim($rel, '/'));
        if ($full !== false && str_starts_with($full, $root . DIRECTORY_SEPARATOR) && is_file($full)) {
            @unlink($full);
        }
    }
}

/** Bytes in words: 12 KB, 3.4 MB. */
function fmt_bytes(int $n): string
{
    if ($n >= 1048576) { return rtrim(rtrim(number_format($n / 1048576, 1, '.', ''), '0'), '.') . ' MB'; }
    if ($n >= 1024) { return (int) round($n / 1024) . ' KB'; }
    return $n . ' B';
}

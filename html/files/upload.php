<?php
declare(strict_types=1);
/**
 * Action `attachment_add` (log `attachment.add`: attachment_id, record_type, record_id, filename, byte_size, mime_type; undo attachment_delete): notes.write; multipart `file` (or `photo`, a phone's camera), `record_type` the
 * manifest's word (product … location, fifteen), `record` its id (visible to the caller: unseen = 404). The cap is the lesser of the settings' and ATTACHMENT_MAX_BYTES; the type is SNIFFED, never the browser's.
 * Location: the record's page, #attachments; refresh attachmentChanged.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/files/queries.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $_POST === [] && $_FILES === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    require_login();                                                // PHP dropped a body over post_max_size: nothing arrived, not even the CSRF token
    refuse(422, 'That file is too large.');
}
inv_handler_begin();
require_right('notes.write');
$pdo = db();
$me = (int) current_member_id();
$rec = inv_guard($pdo, static fn (): array => attachment_record(request_string('record_type')));
$rid = request_integer('record');
if ($rid === null || one_value($pdo, "SELECT 1 FROM {$rec['view']} WHERE {$rec['id_column']} = :id", ['id' => $rid]) === null) { refuse(404, 'That record is not here.'); }
$file = $_FILES['file'] ?? null;
if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { $file = $_FILES['photo'] ?? null; }
if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { inv_refuse_fields(['file' => 'Choose a file to attach.']); }
$id = inv_guard($pdo, static function () use ($pdo, $rec, $rid, $file, $me): int {
    $pdo->beginTransaction();
    $id = attachment_store($pdo, $rec['type'], $rid, $file, $me);
    $a = inv_can_see_attachment($pdo, $id);
    $keys = match ($rec['type']) {
        'sales_order' => ['sales_order_id' => $rid], 'purchase_order' => ['purchase_order_id' => $rid], 'source' => ['source_id' => $rid],
        'return' => ['sales_order_id' => (int) one_value($pdo, 'SELECT sales_order_id FROM return_authorizations WHERE id = :r', ['r' => $rid])],
        default => [],
    };
    log_activity($pdo, 'attachment.add', 'attachment', $id, $keys + ['after' => ['attachment_id' => $id, 'record_type' => $rec['type'], 'record_id' => $rid, 'filename' => $a['filename'], 'byte_size' => (int) $a['byte_size'], 'mime_type' => $a['mime_type']]]);
    $pdo->commit();
    return $id;
});
inv_done('Attached a file', $id, inv_land((string) record_url($rec['type'], $rid), null, 'attachments'), 'attachmentChanged', ['attachment_id' => $id, 'record_type' => $rec['type'], 'record_id' => $rid]);

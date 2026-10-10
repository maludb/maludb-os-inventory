<?php
declare(strict_types=1);
/**
 * Action `attachment_delete` (log `attachment.delete`: attachment_id, record_type, record_id, filename; confirm): the uploader, or records.delete. The row goes, then the file and its thumbnail.
 * Location: the record's page, #attachments; refresh attachmentChanged.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/files/queries.php';
inv_handler_begin();
require_login();
$pdo = db();
$me = (int) current_member_id();
$a = inv_can_see_attachment($pdo, request_integer('attachment') ?? 0);
if ($a === null) { refuse(404, 'File not found.'); }
if (!has_right('records.delete') && ($a['uploaded_by'] === null || (int) $a['uploaded_by'] !== $me)) { refuse(403, 'You may delete the files you attached.'); }
inv_guard($pdo, static function () use ($pdo, $a, $me): void {
    $pdo->beginTransaction();
    $keys = match ($a['record_type']) {
        'sales_order' => ['sales_order_id' => (int) $a['record_id']], 'purchase_order' => ['purchase_order_id' => (int) $a['record_id']], 'source' => ['source_id' => (int) $a['record_id']],
        'return' => ['sales_order_id' => (int) one_value($pdo, 'SELECT sales_order_id FROM return_authorizations WHERE id = :r', ['r' => (int) $a['record_id']])],
        default => [],
    };
    log_activity($pdo, 'attachment.delete', 'attachment', (int) $a['attachment_id'], $keys + ['after' => ['attachment_id' => (int) $a['attachment_id'], 'record_type' => $a['record_type'], 'record_id' => (int) $a['record_id'], 'filename' => $a['filename']]]);
    attachment_delete($pdo, (int) $a['attachment_id']);
    $pdo->commit();
});
thumb_forget((int) $a['attachment_id']);
inv_done('Deleted ' . $a['filename'], (int) $a['attachment_id'], inv_land((string) record_url($a['record_type'], (int) $a['record_id']), null, 'attachments'), 'attachmentChanged',
    ['attachment_id' => (int) $a['attachment_id'], 'record_type' => $a['record_type'], 'record_id' => (int) $a['record_id']]);

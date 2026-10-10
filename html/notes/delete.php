<?php
declare(strict_types=1);
/**
 * Action `note_delete` (log `note.delete`: note_id, record_type, record_id, length; confirm): the note's author, or records.delete (the admin: anyone's). Location: the record's page, #notes; refresh noteChanged.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/notes/queries.php';
inv_handler_begin();
require_login();
$pdo = db();
$me = (int) current_member_id();
$n = find_note($pdo, request_integer('note') ?? 0);
if ($n === null) { refuse(404, 'Note not found.'); }
if (!has_right('records.delete') && ($n['member_id'] === null || (int) $n['member_id'] !== $me)) { refuse(403, 'You may delete your own notes.'); }
$r = inv_guard($pdo, static function () use ($pdo, $n, $me): array {
    $pdo->beginTransaction();
    $r = delete_note($pdo, (int) $n['note_id'], $me);
    $keys = match ($r['record_type']) {
        'sales_order' => ['sales_order_id' => $r['record_id']], 'purchase_order' => ['purchase_order_id' => $r['record_id']], 'source' => ['source_id' => $r['record_id']],
        'return_authorization' => ['sales_order_id' => (int) one_value($pdo, 'SELECT sales_order_id FROM return_authorizations WHERE id = :r', ['r' => $r['record_id']])],
        default => [],
    };
    log_activity($pdo, 'note.delete', 'note', (int) $n['note_id'], $keys + ['after' => ['note_id' => (int) $n['note_id']] + $r]);
    $pdo->commit();
    return $r;
});
inv_done('Deleted a note', (int) $n['note_id'], inv_land((string) record_url($r['record_type'], $r['record_id']), null, 'notes'), 'noteChanged', ['note_id' => (int) $n['note_id'], 'record_type' => $r['record_type'], 'record_id' => $r['record_id']]);

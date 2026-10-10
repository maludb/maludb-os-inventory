<?php
declare(strict_types=1);
/**
 * Action `note_add` (log `note.add`: note_id, record_type, record_id, length — the words are the record's, in `notes`, never in the log; undo note_delete): notes.write. `record_type` is the manifest's word
 * (product, variant, supplier, source, listing, customer, order, purchase_order, receipt, return, location), `record` its id (the record must be visible to the caller: unseen = 404), `body` 1–5,000.
 * Location: the record's page, #notes; refresh noteChanged.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/notes/queries.php';
inv_handler_begin();
require_right('notes.write');
$pdo = db();
$me = (int) current_member_id();
$rec = inv_guard($pdo, static fn (): array => note_record(request_string('record_type')));
$rid = request_integer('record');
if ($rid === null || one_value($pdo, "SELECT 1 FROM {$rec['view']} WHERE {$rec['id_column']} = :id", ['id' => $rid]) === null) { refuse(404, 'That record is not here.'); }
$body = trim((string) (req_val('body') ?? ''));
if ($body === '') { inv_refuse_fields(['body' => 'Write the note.']); }
if (mb_strlen($body) > 5000) { inv_refuse_fields(['body' => 'A note is up to 5,000 characters.']); }
$id = inv_guard($pdo, static function () use ($pdo, $rec, $rid, $body, $me): int {
    $pdo->beginTransaction();
    $id = add_note($pdo, $rec['type'], $rid, $body, $me);
    $keys = match ($rec['type']) {
        'sales_order' => ['sales_order_id' => $rid], 'purchase_order' => ['purchase_order_id' => $rid], 'source' => ['source_id' => $rid],
        'return_authorization' => ['sales_order_id' => (int) one_value($pdo, 'SELECT sales_order_id FROM return_authorizations WHERE id = :r', ['r' => $rid])],
        default => [],
    };
    log_activity($pdo, 'note.add', 'note', $id, $keys + ['after' => ['note_id' => $id, 'record_type' => $rec['type'], 'record_id' => $rid, 'length' => mb_strlen($body)]]);
    $pdo->commit();
    return $id;
});
inv_done('Added a note', $id, inv_land((string) record_url($rec['type'], $rid), null, 'notes'), 'noteChanged', ['note_id' => $id, 'record_type' => $rec['type'], 'record_id' => $rid]);

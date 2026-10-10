<?php /** Notes on a record (returns-worker.md): the list, who and when, the words as paragraphs, Delete for the author or the admin, and the add form. Data: recordType (notes.record_type), recordId, notes? (find_notes — fetched when absent), tz? */
require_once dirname(__DIR__, 2) . '/features/notes/queries.php';
$notes ??= find_notes(db(), $recordType, (int) $recordId);
$tz ??= member_timezone();
$me = (int) current_member_id();
$admin = has_right('records.delete');
?>
<div class="card mb-3" id="notes" data-record-type="<?= e($recordType) ?>"><div class="card-header fw-semibold">Notes <span class="text-muted fs-12 fw-normal">(<?= count($notes) ?>)</span></div>
    <div class="card-body">
        <?php if ($notes === []): ?><div class="text-muted text-center fs-12" id="notes-empty">No notes yet.</div><?php endif; ?>
        <?php foreach ($notes as $n): ?>
            <div class="border-bottom py-2 fs-12" id="note-<?= (int) $n['note_id'] ?>">
                <div class="d-flex justify-content-between gap-2"><div class="text-muted"><?= e($n['member_name'] ?? 'Someone who has left') ?> · <?= e(format_ts($n['created_at'], $tz, 'M j, g:i A')) ?></div>
                <?php if ($admin || ($n['member_id'] !== null && (int) $n['member_id'] === $me)): ?>
                    <form method="post" action="/notes/delete.php" hx-post="/notes/delete.php" hx-target="#flash" hx-confirm="Delete this note?" class="d-inline"><?= csrf_field() ?><input type="hidden" name="note" value="<?= (int) $n['note_id'] ?>">
                        <button type="submit" class="btn btn-light-danger btn-sm" id="note-<?= (int) $n['note_id'] ?>-delete-btn" aria-label="Delete the note"><i class="feather-trash-2"></i></button></form>
                <?php endif; ?></div>
                <div style="white-space: pre-line"><?= e($n['body']) ?></div>
            </div>
        <?php endforeach; ?>
        <?= view('shared/note-form.php', ['recordType' => $recordType, 'recordId' => $recordId]) ?>
    </div>
</div>

<?php /** The add-a-note form (action `note_add`). Data: recordType (notes.record_type — 'sales_order', 'return_authorization'…), recordId */
require_once dirname(__DIR__, 2) . '/features/notes/queries.php';
if (!has_right('notes.write')) { return; }
$word = '';
foreach (NOTE_RECORD_TYPES as $w => $spec) { if ($spec[0] === $recordType) { $word = $w; break; } }
if ($word === '') { return; }
?>
<form method="post" action="/notes/add.php" hx-post="/notes/add.php" hx-target="#flash" id="notes-form" class="mt-3"><?= csrf_field() ?>
    <input type="hidden" name="record_type" value="<?= e($word) ?>"><input type="hidden" name="record" value="<?= (int) $recordId ?>">
    <label class="form-label fs-12 text-muted" for="notes-form-field-body">Add a note</label>
    <textarea name="body" id="notes-form-field-body" class="form-control" rows="2" maxlength="5000" required></textarea>
    <button type="submit" class="btn btn-light btn-touch mt-2" id="notes-form-save-btn">Add the note</button>
</form>

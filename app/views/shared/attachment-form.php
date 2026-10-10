<?php /** The upload form (action `attachment_add`). Data: recordType (attachments.record_type — 'sales_order', 'return'…), recordId */
require_once dirname(__DIR__, 2) . '/features/files/queries.php';
if (!has_right('notes.write')) { return; }
$word = '';
foreach (ATTACHMENT_WORDS as $w => $spec) { if ($spec[0] === $recordType) { $word = $w; break; } }
if ($word === '') { return; }
?>
<form method="post" action="/files/upload.php" hx-post="/files/upload.php" hx-encoding="multipart/form-data" hx-target="#flash" enctype="multipart/form-data" id="attachments-form" class="mt-3"><?= csrf_field() ?>
    <input type="hidden" name="record_type" value="<?= e($word) ?>"><input type="hidden" name="record" value="<?= (int) $recordId ?>">
    <label class="form-label fs-12 text-muted" for="attachments-form-field-file">Attach a file (a photo, a PDF, a spreadsheet, text)</label>
    <input type="file" name="file" id="attachments-form-field-file" class="form-control btn-touch" accept="image/*,application/pdf,.csv,.xlsx,.txt">
    <label class="form-label fs-12 text-muted mt-2" for="attachments-form-field-photo">Or take a photo</label>
    <input type="file" name="photo" id="attachments-form-field-photo" class="form-control btn-touch" accept="image/*" capture="environment">
    <button type="submit" class="btn btn-light btn-touch mt-2" id="attachments-form-save-btn">Attach</button>
</form>

<?php /** Files on a record (returns-worker.md): a thumbnail for an image, the name, the size, who, when, Download, Delete for the uploader or the admin, and the upload form. Data: recordType (attachments.record_type), recordId, attachments? (find_attachments — fetched when absent), tz? */
require_once dirname(__DIR__, 2) . '/features/files/queries.php';
$attachments ??= find_attachments(db(), $recordType, (int) $recordId);
$tz ??= member_timezone();
$me = (int) current_member_id();
$admin = has_right('records.delete');
?>
<div class="card mb-3" id="attachments" data-record-type="<?= e($recordType) ?>"><div class="card-header fw-semibold">Attachments <span class="text-muted fs-12 fw-normal">(<?= count($attachments) ?>)</span></div>
    <div class="card-body">
        <?php if ($attachments === []): ?><div class="text-muted text-center fs-12" id="attachments-empty">Nothing attached yet.</div><?php endif; ?>
        <?php foreach ($attachments as $a): $aid = (int) $a['attachment_id']; $img = str_starts_with((string) $a['mime_type'], 'image/'); ?>
            <div class="d-flex align-items-center gap-2 border-bottom py-2 fs-12" id="attachment-<?= $aid ?>">
                <?php if ($img): ?><a href="/files/<?= $aid ?>" target="_blank" rel="noopener"><img src="/files/<?= $aid ?>/thumb" alt="<?= e($a['filename']) ?>" width="48" height="48" loading="lazy" class="rounded" style="object-fit: cover"></a>
                <?php else: ?><span class="avatar-text avatar-md rounded"><i class="feather-file"></i></span><?php endif; ?>
                <div class="min-w-0 flex-grow-1"><a href="/files/<?= $aid ?>" class="fw-semibold text-break" id="attachment-<?= $aid ?>-link"><?= e($a['filename']) ?></a>
                    <div class="text-muted"><?= e(fmt_bytes((int) $a['byte_size'])) ?> · <?= e($a['uploaded_by_name'] ?? 'Someone who has left') ?> · <?= e(format_ts($a['created_at'], $tz, 'M j, Y')) ?></div></div>
                <a href="/files/<?= $aid ?>" download="<?= e($a['filename']) ?>" class="btn btn-light btn-sm" id="attachment-<?= $aid ?>-download" aria-label="Download <?= e($a['filename']) ?>"><i class="feather-download"></i></a>
                <?php if ($admin || ($a['uploaded_by'] !== null && (int) $a['uploaded_by'] === $me)): ?>
                    <form method="post" action="/files/delete.php" hx-post="/files/delete.php" hx-target="#flash" hx-confirm="Delete <?= e($a['filename']) ?>?" class="d-inline"><?= csrf_field() ?><input type="hidden" name="attachment" value="<?= $aid ?>">
                        <button type="submit" class="btn btn-light-danger btn-sm" id="attachment-<?= $aid ?>-delete-btn" aria-label="Delete <?= e($a['filename']) ?>"><i class="feather-trash-2"></i></button></form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <?= view('shared/attachment-form.php', ['recordType' => $recordType, 'recordId' => $recordId]) ?>
    </div>
</div>

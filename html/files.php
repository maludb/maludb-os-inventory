<?php
declare(strict_types=1);
/**
 * GET /files/{id} (and /files/{id}/thumb) — the gated attachment door (sso-shell.md): a session (an anonymous request is 401, never a redirect: a
 * file URL sits in an <img>), and the record's visibility as inv_can_see_attachment() decides it (today: inv_is_member_here() through
 * mcp_attachments — every record is every reader's; a later slice narrows if its view does). Streams the file from storage/ with the stored
 * MIME type, `inline` for images and PDFs, `attachment` otherwise; ?thumb=1 (/files/{id}/thumb) streams a 320 px JPEG of an image, made once and cached (slice 8; a file that
 * is no image has no thumbnail: 404). Logs nothing — a file view is not an event (returns-worker.md DECISION 17).
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/attachments.php';
require_once dirname(__DIR__) . '/app/features/files/queries.php';
if (!is_logged_in()) {
    http_response_code(401);
    header('Cache-Control: no-store');
    header('Content-Type: text/plain; charset=utf-8');
    exit('Sign in required.');
}
$id = request_integer('id');
$pdo = db();
$row = $id === null ? null : inv_can_see_attachment($pdo, $id);
$full = $row === null ? '' : attachment_path($row);
if ($row === null || $full === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not found.');
}
if (request_string('thumb') === '1') {
    $thumb = thumb_path($row);
    if ($thumb === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Not found.');
    }
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($thumb));
    header('Content-Disposition: inline; filename="thumb-' . (int) $row['attachment_id'] . '.jpg"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=300');
    readfile($thumb);
    exit;
}
$inline = str_starts_with((string) $row['mime_type'], 'image/') || $row['mime_type'] === 'application/pdf';
header('Content-Type: ' . $row['mime_type']);
header('Content-Length: ' . filesize($full));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', (string) $row['filename']) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
readfile($full);

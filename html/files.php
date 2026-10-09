<?php
declare(strict_types=1);
/**
 * GET /files/{id} (and /files/{id}/thumb) — the gated attachment door (sso-shell.md): a session (an anonymous request is 401, never a redirect: a
 * file URL sits in an <img>), and the record's visibility as inv_can_see_attachment() decides it (today: inv_is_member_here() through
 * mcp_attachments — every record is every reader's; a later slice narrows if its view does). Streams the file from storage/ with the stored
 * MIME type, `inline` for images and PDFs, `attachment` otherwise; ?thumb=1 streams the same file (no resizing in version 1). Logs nothing (a read).
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/attachments.php';
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
$inline = str_starts_with((string) $row['mime_type'], 'image/') || $row['mime_type'] === 'application/pdf';
header('Content-Type: ' . $row['mime_type']);
header('Content-Length: ' . filesize($full));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', (string) $row['filename']) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
readfile($full);

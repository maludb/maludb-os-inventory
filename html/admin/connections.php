<?php
declare(strict_types=1);
/**
 * /admin/connections — who outside reads us (screen `connection-list`, read-only): the five shares this application declares, the `share.read` rows grouped by consumer and tool and the last 50, the reads this application
 * declares (none in version 1) and the way to the OS where a super-admin approves a connection. settings.manage or agents.settings.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/shares/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/connections/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/connections/present.php';
require_any_right('settings.manage|agents.settings');
$pdo = db();
$shares = declared_shares();
$reads = declared_reads();
$readers = share_readers($pdo);
$rows = share_reads($pdo, 50);
log_screen_view($pdo, 'connection-list');
if (wants_json()) {
    respond_screen(['shares' => $shares, 'readers' => array_map('present_share_reader', $readers), 'share_reads' => array_map('present_share_read', $rows), 'declared_reads' => $reads, 'os_url' => os_applications_url()]);
}
render_screen('Connections', view('admin/connections.php', ['shares' => $shares, 'reads' => $reads, 'readers' => $readers, 'rows' => $rows, 'tz' => member_timezone(), 'osUrl' => os_applications_url()]),
    ['activeNav' => 'connection-list', 'screen' => 'connection-list', 'entity' => 'share']);

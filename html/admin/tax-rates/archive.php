<?php
declare(strict_types=1);
/** Action `tax_rate_archive` (log `tax_rate.archive`): archive a tax rate — not the default one ("Make another rate the default first."). Orders and customers that name it keep it. settings.manage; a confirm in the browser. */
require_once dirname(__DIR__, 3) . '/app/features/admin/handler.php';
inv_handler_begin();
require_right('settings.manage');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('tax_rate') ?? request_integer('tax_rate_id');
$cur = tax_rate_or_404($pdo, $id);
inv_guard($pdo, static function () use ($pdo, $me, $id, $cur): void {
    $pdo->beginTransaction();
    archive_tax_rate($pdo, $id, $me);
    log_activity($pdo, 'tax_rate.archive', 'tax_rate', $id, ['after' => tax_rate_loggable($cur) + ['archived' => true]]);
    $pdo->commit();
});
inv_done('Archived the tax rate ' . $cur['name'], $id, inv_land('/admin/tax-rates/', 'archived', 'tax-rate-row-' . $id), 'settingsChanged', ['tax_rate_id' => $id]);

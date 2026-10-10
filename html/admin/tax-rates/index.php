<?php
declare(strict_types=1);
/** /admin/tax-rates/ — the tax rates (screen `tax-rate-list`): name, rate as a percent, the default, the orders and customers using each; the archived apart. settings.manage. */
require_once dirname(__DIR__, 3) . '/app/features/admin/handler.php';
require_right('settings.manage');
$pdo = db();
$rows = find_tax_rates($pdo, true);
admin_screen_view($pdo, 'tax-rate-list');
if (wants_json()) {
    respond_screen(['tax_rates' => array_map('present_tax_rate', $rows)]);
}
render_screen('Tax rates', view('admin/tax-rates/index.php', ['live' => array_values(array_filter($rows, static fn (array $t): bool => $t['archived_at'] === null)), 'archived' => array_values(array_filter($rows, static fn (array $t): bool => $t['archived_at'] !== null)),
    'notice' => inv_notice($_GET['notice'] ?? null, ['created' => ['success', 'Added the tax rate.'], 'saved' => ['success', 'Saved the tax rate.'], 'archived' => ['success', 'Archived the tax rate.']])]),
    ['activeNav' => 'tax-rate-list', 'screen' => 'tax-rate-list', 'entity' => 'tax_rate']);

<?php
declare(strict_types=1);
/** /admin/tax-rates/new and /admin/tax-rates/{id}/edit (screens `tax-rate-add`, `tax-rate-edit`; the form `tax-rate-form`). settings.manage; people only. An archived rate cannot be edited. */
require_once dirname(__DIR__, 3) . '/app/features/admin/handler.php';
require_right('settings.manage');
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('tax_rate');
$cur = $id === null ? null : tax_rate_or_404($pdo, $id);
if ($cur !== null && $cur['archived_at'] !== null) { refuse(422, 'An archived tax rate cannot be changed.'); }
$screen = $cur === null ? 'tax-rate-add' : 'tax-rate-edit';
admin_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['tax_rate' => $cur === null ? null : present_tax_rate($cur)]);
}
render_screen($cur === null ? 'Add a tax rate' : 'Change ' . $cur['name'], view('admin/tax-rates/form.php', ['cur' => $cur]),
    ['activeNav' => 'tax-rate-list', 'screen' => $screen, 'entity' => 'tax_rate', 'recordId' => $id === null ? '' : (string) $id]);

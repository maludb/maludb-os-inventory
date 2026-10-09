<?php
declare(strict_types=1);
/** /brands/new and /brands/{id}/edit (screens `brand-add`, `brand-edit`). catalog.write; people only. */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
require_right('catalog.write');
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('brand');
$cur = null;
if ($id !== null) { $cur = find_brand($pdo, $id) ?? refuse(404, 'Brand not found.'); }
$screen = $cur === null ? 'brand-add' : 'brand-edit';
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['brand' => $cur === null ? null : present_brand($cur)]);
}
render_screen($cur === null ? 'New brand' : 'Change ' . $cur['name'], view('catalog/brand-form.php', ['cur' => $cur]), ['activeNav' => 'brand-list', 'screen' => $screen, 'entity' => 'brand', 'recordId' => $id === null ? '' : (string) $id]);

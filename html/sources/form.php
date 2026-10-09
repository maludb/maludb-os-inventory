<?php
declare(strict_types=1);
/** /sources/new?template=&supplier=&connector= and /sources/{id}/edit (screens `source-add`, `source-edit`): the common fields and every connector's sub-form, rendered server-side. sources.write. */
require_once dirname(__DIR__, 2) . '/app/features/sources/handler.php';
require_right('sources.write');
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('source');
$cur = $id === null ? null : source_or_404($pdo, $id);
$template = null;
$prefill = [];
if ($cur === null && request_string('template') !== '') {
    $template = find_template($pdo, request_string('template')) ?? refuse(404, 'Template not found.');
    $prefill = ['name' => $template['name'], 'connector' => $template['connector'], 'role' => $template['role'], 'base_url' => $template['base_url'], 'brand_hint' => $template['brand_hint']];
}
foreach (['connector', 'name', 'base_url', 'role'] as $k) { if (request_string($k) !== '') { $prefill[$k] = request_string($k); } }
if (request_integer('supplier') !== null) { $prefill['supplier_id'] = request_integer('supplier'); $prefill['role'] = $prefill['role'] ?? 'supplier'; }
$screen = $cur === null ? 'source-add' : 'source-edit';
$settings = $cur === null ? [] : source_settings_raw($pdo, $cur['source_id']);
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['source' => $cur === null ? null : present_source($cur), 'template' => $template === null ? null : present_template($template), 'connectors' => connector_defs(),
        'settings_keys' => array_map('connector_settings_keys', array_combine(array_keys(inv_connectors()), array_keys(inv_connectors()))), 'suppliers' => source_suppliers($pdo)]);
}
render_screen($cur === null ? 'New source' : 'Change ' . $cur['name'], view('sources/form.php', ['cur' => $cur, 'settings' => $settings, 'tpl' => $template, 'prefill' => $prefill,
    'suppliers' => source_suppliers($pdo), 'defaults' => $pdo->query('SELECT schedule_supplier_minutes, schedule_reference_minutes, schedule_jsonld_minutes, crawl_rate_per_second FROM inv_settings WHERE id = 1')->fetch(),
    'sizes' => settings_vocabulary($pdo)['sizes'], 'sftpTool' => inv_feed_sftp_tool()]),
    ['activeNav' => 'source-list', 'screen' => $screen, 'entity' => 'source', 'recordId' => $id === null ? '' : (string) $id]);

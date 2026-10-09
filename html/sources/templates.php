<?php
declare(strict_types=1);
/** /sources/templates — the known stores and feeds as cards with the survey's verdict (screen `source-template-list`). sources.write (the menu's right). */
require_once dirname(__DIR__, 2) . '/app/features/sources/handler.php';
require_right('sources.write');
$pdo = db();
$templates = source_templates($pdo);
log_screen_view($pdo, 'source-template-list');
if (wants_json()) {
    respond_screen(['templates' => array_map('present_template', $templates)]);
}
render_screen('Source templates', view('sources/templates.php', ['templates' => $templates, 'mayWrite' => has_right('sources.write'), 'here' => here_url()]),
    ['activeNav' => 'source-template-list', 'screen' => 'source-template-list', 'entity' => 'source_template']);

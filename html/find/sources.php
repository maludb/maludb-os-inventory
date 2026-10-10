<?php
declare(strict_types=1);
/**
 * GET /find/sources?q=&size= — the live fan-out shell (find.md): one placeholder card per searchable source, each asking /find/source-search on
 * load — the browser fans out, one request a source. orders.write. JavaScript off: a page whose cards carry an "Ask" link each.
 */
require_once dirname(__DIR__, 2) . '/app/features/listings/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/find/queries.php';
require_right('orders.write');
$pdo = db();
$q = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($q) < 2 || mb_strlen($q) > 120) { refuse(422, 'Ask for 2 to 120 characters.'); }
$size = trim((string) ($_GET['size'] ?? '')) ?: null;
$sources = searchable_sources($pdo);
if (wants_json()) { respond_screen(['query' => $q, 'size' => $size, 'sources' => array_map(static fn ($s) => ['source_id' => $s['source_id'], 'name' => $s['name'], 'connector' => $s['connector'], 'role' => $s['role']], $sources)]); }
$html = view('find/partials/live-sources.php', ['sources' => $sources, 'q' => $q, 'size' => $size]);
if (is_htmx_request()) { echo $html; return; }
render_screen('Ask the sources', view('shared/header.php', ['id' => 'find-sources', 'title' => 'Ask the sources', 'crumbs' => [['Home', '/'], ['Find', '/find?' . http_build_query(['q' => $q])], ['Ask the sources', null]]])
    . '<div class="main-content"><div id="find-live">' . $html . '</div></div>', ['activeNav' => 'find', 'screen' => 'find', 'entity' => 'variant', 'recordId' => '']);

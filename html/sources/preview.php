<?php
declare(strict_types=1);
/** POST /sources/preview.php — the feed's "Read the file" (Pattern A): the first five rows under their headers and the mapping selects, from the form's values (or the source's row + the values). sources.write; logs nothing; keeps no file. */
require_once dirname(__DIR__, 2) . '/app/features/sources/handler.php';
source_write_begin('sources.write');
$pdo = db();
$id = request_integer('source');
$base = $id !== null && find_source($pdo, $id) !== null ? source_settings_raw($pdo, $id) : [];
$errors = [];
$settings = source_settings_from_request('feed', $base, $errors);
$source = ['connector' => 'feed', 'settings' => $settings, 'credential' => null, 'rate_per_second' => 1, 'user_agent' => null, 'timeout' => 120, 'cache_dir' => inv_pull_cache_dir()];
if ($id !== null) { try { $source['credential'] = inv_source_for_connector($pdo, $id)['credential']; } catch (Throwable) { } }
$p = $errors !== [] ? ['ok' => false, 'error' => implode(' ', $errors), 'columns' => [], 'rows' => [], 'row_count' => 0, 'format' => null, 'via' => 'none', 'mapped' => []]
    : (new InvConnectorFeed())->preview($source, 5);
emit_action_status(true, ['did' => $p['ok'] ? 'Read ' . $p['row_count'] . ' rows' : 'The file could not be read', 'record_id' => $id]);
if (wants_json()) { respond_saved(['did' => $p['ok'] ? 'Read the file' : 'The file could not be read', 'preview' => $p]); }
echo view('sources/partials/feed-preview.php', ['p' => $p]) . view('sources/partials/feed-mapping.php', ['columns' => $p['columns'], 'mapping' => $settings['mapping'] ?? [], 'missing' => $p['missing'] ?? []]);

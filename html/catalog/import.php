<?php
declare(strict_types=1);
/** /catalog/import — the three steps of the CSV import (screen `catalog-import`): the file; the preview and the mapping (?upload=); the result (?result=). The action posts to /products/import.php. catalog.write; people only. */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
require_right('catalog.write');
require_human();
$pdo = db();
$step = 1;
$preview = null;
$result = null;
$upload = preg_replace('/[^a-f0-9]/', '', request_string('upload'));
$resultKey = preg_replace('/[^a-f0-9]/', '', request_string('result'));
if ($resultKey !== '' && isset($_SESSION['import_result'][$resultKey])) {
    $step = 3;
    $result = $_SESSION['import_result'][$resultKey];
    unset($_SESSION['import_result'][$resultKey]);
} elseif ($upload !== '' && isset($_SESSION['import_preview']) && $_SESSION['import_preview']['upload'] === $upload) {
    $step = 2;
    $preview = $_SESSION['import_preview'];
}
log_screen_view($pdo, 'catalog-import');
if (wants_json()) {
    respond_screen(['step' => $step, 'preview' => $preview, 'result' => $result, 'fields' => ['name', 'sku', 'brand', 'type', 'size', 'gtin', 'mpn', 'retail_price', 'map_price', 'cost_price', 'description', 'tags', 'ships_how']]);
}
render_screen('Import the catalog', view('catalog/import.php', ['step' => $step, 'preview' => $preview, 'result' => $result, 'seesCost' => sees_cost(), 'notice' => null]), ['activeNav' => 'catalog-import', 'screen' => 'catalog-import']);

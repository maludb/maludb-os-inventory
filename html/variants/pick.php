<?php
declare(strict_types=1);
/** GET /variants/pick?q=&single=1 — the variant picker's rows as JSON (Pattern A; the record picker itself reads /pick/?source=variant). inventory.read. No log. */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
require_right('inventory.read');
header('Cache-Control: no-store');
json_response(['rows' => variant_pick(db(), request_string('q'), 20, request_bool('single'))]);

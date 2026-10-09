<?php
declare(strict_types=1);
/** /brands/?q= — the brands with their product counts and dealer program (screen `brand-list`). */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
require_right('inventory.read');
$pdo = db();
$q = request_string('q');
$brands = find_brands($pdo, $q, true, 500);
log_screen_view($pdo, 'brand-list');
if (wants_json()) {
    respond_screen(['q' => $q, 'brands' => array_map('present_brand', $brands)]);
}
render_screen('Brands', view('catalog/brands.php', ['brands' => $brands, 'q' => $q, 'mayWrite' => has_right('catalog.write'), 'here' => here_url(), 'notice' => inv_notice($_GET['notice'] ?? null, ['created' => ['success', 'The brand is made.'], 'saved' => ['success', 'Saved.']])]),
    ['activeNav' => 'brand-list', 'screen' => 'brand-list', 'entity' => 'brand']);

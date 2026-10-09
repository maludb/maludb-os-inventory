<?php
declare(strict_types=1);
/** /sources/{id}/listings?match=&q=&availability=&removed=&page= — a source's listings with their variants (screen `source-listings`). */
require_once dirname(__DIR__, 2) . '/app/features/listings/handler.php';
require_right('inventory.read');
$pdo = db();
$s = find_source($pdo, (int) (request_integer('id') ?? request_integer('source') ?? 0)) ?? refuse(404, 'Source not found.');
$filters = ['source' => $s['source_id'], 'matched' => request_string('match'), 'q' => request_string('q'), 'availability' => request_string('availability'), 'include_removed' => ($_GET['removed'] ?? '') === '1'];
$page = max(1, request_integer('page') ?? 1);
$res = source_listings($pdo, $filters, LISTING_PAGE, ($page - 1) * LISTING_PAGE);
log_screen_view($pdo, 'source-listings');
if (wants_json()) {
    respond_screen(['source' => present_source($s), 'filters' => $filters, 'page' => $page, 'more' => $res['more'], 'listings' => array_map('present_listing', $res['rows'])]);
}
render_screen('Listings of ' . $s['name'], view('sources/listings.php', ['s' => $s, 'rows' => $res['rows'], 'more' => $res['more'], 'page' => $page, 'filters' => $filters, 'seesCost' => sees_cost(), 'here' => here_url()]),
    ['activeNav' => 'source-list', 'screen' => 'source-listings', 'entity' => 'source', 'recordId' => (string) $s['source_id']]);

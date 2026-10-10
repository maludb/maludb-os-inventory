<?php
declare(strict_types=1);
/**
 * GET /find/source-search?source=&q=&size= — one source asked live (find.md): inv_source_search_live(…, 10, me) — the function logs `source.search`
 * — and the listing variants it wrote, read through mcp_listing_variants (the wall stands). The answer carries HX-Trigger: offerChanged so every
 * revealed card's availability reloads. orders.write; a source that is not searchable is 404.
 */
require_once dirname(__DIR__, 2) . '/app/features/listings/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/find/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/find/present.php';
require_right('orders.write');
$pdo = db();
$sid = request_integer('source') ?? 0;
$q = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($q) < 2 || mb_strlen($q) > 120) { refuse(422, 'Ask for 2 to 120 characters.'); }
$size = trim((string) ($_GET['size'] ?? '')) ?: null;
$s = find_source($pdo, $sid) ?? refuse(404, 'Source not found.');
if (empty(inv_connectors()[$s['connector']]['capabilities']['has_search'])) { refuse(404, 'Source not found.'); }
set_time_limit(30);
$res = live_search_source($pdo, $sid, $q, 10, (int) current_member_id(), $size);
header('HX-Trigger: offerChanged');
if (wants_json()) { respond_screen(['source_id' => $sid, 'status' => $res['status'], 'pull_id' => $res['pull_id'], 'listings_seen' => $res['listings_seen'], 'rows' => array_map('present_listing_variant', array_map(static fn ($r) => listing_variant_decode($r), $res['rows']))]); }
$html = view('find/partials/live-card.php', ['s' => $s, 'res' => $res, 'q' => $q, 'seesCost' => sees_cost(), 'mayMatch' => has_right('listings.match')]);
if (is_htmx_request()) { echo $html; return; }
render_screen('Asked ' . $s['name'], '<div class="main-content">' . $html . '</div>', ['activeNav' => 'find', 'screen' => 'find', 'entity' => 'source', 'recordId' => (string) $sid]);

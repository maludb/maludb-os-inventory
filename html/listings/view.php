<?php
declare(strict_types=1);
/** /listings/{id}?listing_variant=&since= — one listing (screen `listing-view`): its variants with the match column, the chart of the selected variant's offers, the snapshots, the proposals, raw fields (listings.match), what was sold against it, the trail. */
require_once dirname(__DIR__, 2) . '/app/features/listings/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/activity/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/activity/present.php';
require_right('inventory.read');
$pdo = db();
$l = listing_or_404($pdo, request_integer('id') ?? request_integer('listing'));
$full = listing_full($pdo, $l['listing_id']);
$lvIds = array_column($full['variants'], 'listing_variant_id');
$sel = request_integer('listing_variant');
if ($sel === null || !in_array($sel, $lvIds, true)) { $sel = $lvIds[0] ?? null; }
$sinceDays = in_array(request_integer('since'), [30, 90, 180, 365], true) ? (int) request_integer('since') : 90;
$history = $sel === null ? [] : offer_history($pdo, $sel, date(DATE_ATOM, time() - $sinceDays * 86400));
$chart = offer_chart_series($history, sees_cost());
$trail = find_record_activity($pdo, 'listing', $l['listing_id'], 30);
foreach ($lvIds as $lvId) { $trail = array_merge($trail, find_record_activity($pdo, 'listing_variant', $lvId, 10)); }
usort($trail, static fn ($a, $b) => strcmp((string) $b['occurred_at'], (string) $a['occurred_at']));
log_activity($pdo, 'screen.view', 'listing', $l['listing_id'], ['screen' => 'listing-view', 'source_id' => $l['source_id'], 'after' => ['source_id' => $l['source_id']]]);
if (wants_json()) {
    respond_screen(['listing' => present_listing($l), 'variants' => array_map('present_listing_variant', $full['variants']), 'proposals' => array_map('present_proposal', $full['proposals']),
        'raw' => $full['raw'], 'sold_lines' => $full['sold_lines'], 'selected' => $sel, 'since_days' => $sinceDays, 'history' => array_map('present_offer_point', $history),
        'summary' => offer_summary($history), 'availability' => availability_runs($history)]);
}
render_screen($l['title'] ?: 'Listing', view('listings/view.php', ['l' => $l, 'full' => $full, 'sel' => $sel, 'sinceDays' => $sinceDays, 'history' => $history, 'chart' => $chart,
    'summary' => offer_summary($history), 'mayMatch' => has_right('listings.match'), 'seesCost' => sees_cost(), 'trail' => array_slice($trail, 0, 30), 'tz' => member_timezone(), 'here' => here_url(),
    'notice' => inv_notice($_GET['notice'] ?? null, LISTING_NOTICES)]), ['activeNav' => 'source-list', 'screen' => 'listing-view', 'entity' => 'listing', 'recordId' => (string) $l['listing_id']]);

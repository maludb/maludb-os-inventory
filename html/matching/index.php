<?php
declare(strict_types=1);
/** /matching/?source=&q=&min_confidence=&page= — the match queue (screen `match-queue`): unmatched live listing variants with their best proposal; Accept, Pick another, Dismiss, Not ours, Score again. listings.match. */
require_once dirname(__DIR__, 2) . '/app/features/listings/handler.php';
require_right('listings.match');
$pdo = db();
$source = request_integer('source');
$q = request_string('q');
$min = request_string('min_confidence');
$minF = is_numeric($min) ? max(0.0, min(1.0, (float) $min)) : null;
$page = max(1, request_integer('page') ?? 1);
$res = unmatched_listings($pdo, $source, false, $minF, 50, ($page - 1) * 50, $q);
log_screen_view($pdo, 'match-queue');
if (wants_json()) {
    respond_screen(['source' => $source, 'q' => $q, 'min_confidence' => $minF, 'page' => $page, 'more' => $res['more'], 'counts' => queue_counts($pdo, $source), 'rows' => $res['rows']]);
}
render_screen('Match queue', view('matching/index.php', ['rows' => $res['rows'], 'more' => $res['more'], 'page' => $page, 'source' => $source, 'q' => $q, 'min' => $minF, 'counts' => queue_counts($pdo, $source),
    'sources' => find_sources($pdo, []), 'here' => here_url(), 'notice' => inv_notice($_GET['notice'] ?? null, LISTING_NOTICES)]), ['activeNav' => 'match-queue', 'screen' => 'match-queue', 'entity' => 'listing']);

<?php
declare(strict_types=1);

/** Watches' prelude (find.md "Handlers"): the reader of a watch from the request, watch_log() — source_id on a listing-variant watch — and watch_path(). */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__) . '/catalog/handler.php';
require_once dirname(__DIR__) . '/find/queries.php';
require_once dirname(__DIR__) . '/find/present.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/write.php';

/**
 * The fields of watch_set from the request: kind, exactly one target (visible through its view, else 404), the threshold (required for three kinds,
 * refused for the others), cost_below behind the wall, an agent behind watches.all, text_me, note. Field errors are refused together.
 * Returns [fields, target].
 */
function watch_from_request(PDO $pdo): array
{
    $errors = [];
    $kind = trim((string) (req_val('kind') ?? ''));
    if (!isset(WATCH_KINDS[$kind])) { $errors['kind'] = $kind === '' ? 'Say what to watch for.' : 'A watch is one of ' . implode(', ', array_keys(WATCH_KINDS)) . '.'; }
    $given = array_values(array_filter(['variant', 'listing_variant', 'product'], static fn ($k) => trim((string) (req_val($k) ?? '')) !== ''));
    if (count($given) !== 1) { $errors['target'] = 'Name one thing to watch: a variant, a listing variant or a product.'; }
    $threshold = trim((string) (req_val('threshold') ?? ''));
    if ($kind !== '' && isset(WATCH_KINDS[$kind])) {
        if (in_array($kind, WATCH_THRESHOLD_KINDS, true)) {
            if ($threshold === '') { $errors['threshold'] = $kind === 'lead_time_over' ? 'Say how many days.' : 'Say the amount.'; }
            elseif (!is_numeric($threshold) || (float) $threshold < 0) { $errors['threshold'] = 'The threshold is a number, 0 or more.'; }
            elseif ($kind === 'lead_time_over' && (!ctype_digit($threshold) || (int) $threshold > 365)) { $errors['threshold'] = 'Whole days, 0 to 365.'; }
        } elseif ($threshold !== '') {
            $errors['threshold'] = 'A threshold means nothing for ' . $kind . '.';
        }
    }
    $note = trim((string) (req_val('note') ?? ''));
    if (mb_strlen($note) > 500) { $errors['note'] = 'Keep the note under 500 characters.'; }
    if ($errors !== []) { inv_refuse_fields($errors); }
    $tkind = $given[0];
    $tid = filter_var(req_val($tkind), FILTER_VALIDATE_INT);
    $target = $tid === false ? null : watch_target($pdo, $tkind, (int) $tid);
    if ($target === null) { refuse(404, ['variant' => 'Variant', 'listing_variant' => 'Listing', 'product' => 'Product'][$tkind] . ' not found.'); }
    if ($kind === 'cost_below' && !sees_cost()) { refuse(403, 'You may not see cost or margin.'); }
    $agent = null;
    $a = trim((string) (req_val('agent') ?? ''));
    if ($a !== '') {
        if (!has_right('watches.all')) { refuse(403, 'Naming an agent is for someone who may see everyone\'s watches.'); }
        $agent = filter_var($a, FILTER_VALIDATE_INT);
        $ok = $agent !== false && one_value($pdo, "SELECT 1 FROM members WHERE id = :m AND member_kind = 'agent' AND status = 'active' AND capability IS NOT NULL", ['m' => $agent]) !== null;
        if (!$ok) { inv_refuse_fields(['agent' => 'That agent is not here.']); }
    }
    return [['kind' => $kind, 'variant_id' => $tkind === 'variant' ? $target['id'] : null, 'listing_variant_id' => $tkind === 'listing_variant' ? $target['id'] : null,
             'product_id' => $tkind === 'product' ? $target['id'] : null, 'threshold' => $threshold === '' ? null : $threshold, 'text_me' => inv_yes('text_me'),
             'agent_member_id' => $agent === null ? null : (int) $agent, 'note' => $note === '' ? null : $note], $target];
}

/** log_activity() for a watch: source_id on a listing-variant watch. */
function watch_log(PDO $pdo, string $action, int $watchId, array $after, ?int $sourceId = null, array $opts = []): void
{
    log_activity($pdo, $action, 'watch', $watchId, ['after' => $after] + ($sourceId !== null ? ['source_id' => $sourceId] : []) + $opts);
}

/** Where a watch shows: its row on the list. */
function watch_path(int $id): string
{
    return '/watches/#watch-row-' . $id;
}

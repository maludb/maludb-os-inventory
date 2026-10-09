<?php
declare(strict_types=1);

/** Sources' JSON shapes and chips (sources.md "Status vocabulary"). Settings and the user-agent arrive null below sources.write (the view). */

function src_chip(string $label, string $colour, string $id = '', string $title = ''): string
{
    return '<span class="badge bg-soft-' . $colour . ' text-' . ($colour === 'light' ? 'dark border' : $colour) . '"' . ($id !== '' ? ' id="' . e($id) . '"' : '') . ($title !== '' ? ' title="' . e($title) . '"' : '') . '>' . e($label) . '</span>';
}

function health_chip(?string $health, string $id = ''): string
{
    $h = $health ?? 'never_pulled';
    $c = ['ok' => 'success', 'stale' => 'warning', 'failing' => 'warning', 'blocked' => 'danger', 'paused' => 'dark', 'manual' => 'secondary', 'never_pulled' => 'light', 'inactive' => 'dark'][$h] ?? 'secondary';
    return src_chip(SOURCE_HEALTHS[$h] ?? $h, $c, $id);
}

function pull_status_chip(array $p): string
{
    if ($p['status'] === 'running') { return $p['queued'] ? src_chip('queued', 'secondary') : ($p['stale'] ? src_chip('stale — the worker may have died', 'warning') : src_chip('running', 'info')); }
    $c = ['ok' => 'success', 'partial' => 'warning', 'failed' => 'danger', 'blocked' => 'danger'][$p['status']] ?? 'secondary';
    return src_chip($p['status'], $c);
}

function pull_kind_chip(string $kind): string
{
    return match ($kind) { 'manual' => src_chip('manual', 'primary'), 'search' => src_chip('search', 'info'), 'probe' => src_chip('probe', 'secondary'), default => '<span class="text-muted">scheduled</span>' };
}

function role_chip(string $role): string
{
    return $role === 'supplier' ? src_chip('supplier', 'primary') : src_chip('reference', 'secondary');
}

function connector_badge(string $key): string
{
    $defs = inv_connectors();
    return src_chip($defs[$key]['label'] ?? $key, 'light', '', $key);
}

function robots_chip(string $state): string
{
    $c = ['ok' => 'success', 'blocked' => 'danger'][$state] ?? 'secondary';
    return src_chip('robots ' . $state, $c);
}

function survey_chip(string $result): string
{
    return match ($result) { 'open' => src_chip('open', 'success'), 'blocked' => src_chip('blocked for a non-browser agent', 'danger'), 'not_platform' => src_chip('not that platform', 'secondary'), default => src_chip('unverified', 'light') };
}

function match_kind_chip(?string $kind): string
{
    if ($kind === null) { return src_chip('unmatched', 'warning'); }
    $c = ['gtin' => 'primary', 'supplier_sku' => 'primary', 'mpn' => 'info', 'marketplace_id' => 'info', 'manual' => 'success', 'proposed_accepted' => 'success'][$kind] ?? 'secondary';
    return src_chip($kind === 'proposed_accepted' ? 'accepted' : str_replace('_', ' ', $kind), $c);
}

/** "every 30 min", "every 6 h", "daily", "manual". */
function schedule_words(?int $m): string
{
    return match (true) { $m === null => 'default', $m === 0 => 'manual', $m % 1440 === 0 => $m === 1440 ? 'daily' : 'every ' . ($m / 1440) . ' days', $m % 60 === 0 => 'every ' . ($m / 60) . ' h', default => 'every ' . $m . ' min' };
}

function present_source(array $s): array
{
    return ['source_id' => $s['source_id'], 'name' => $s['name'], 'connector' => $s['connector'], 'connector_label' => $s['connector_label'], 'role' => $s['role'], 'supplier_id' => $s['supplier_id'],
            'supplier_name' => $s['supplier_name'], 'base_url' => $s['base_url'], 'settings' => $s['settings'], 'credential_id' => $s['credential_id'], 'schedule_minutes' => $s['schedule_minutes'],
            'rate_per_second' => $s['rate_per_second'], 'user_agent' => $s['user_agent'], 'robots_state' => $s['robots_state'], 'last_ok_at' => json_ts($s['last_ok_at']),
            'consecutive_failures' => $s['consecutive_failures'], 'backoff_until' => json_ts($s['backoff_until']), 'paused_at' => json_ts($s['paused_at']), 'paused_reason' => $s['paused_reason'],
            'active' => $s['active'], 'health' => $s['health'], 'health_sentence' => health_sentence($s), 'next_due_at' => json_ts($s['next_due_at']), 'last_pull_at' => json_ts($s['last_pull_at']),
            'last_status' => $s['last_status'], 'listings_live' => $s['listings_live'], 'variants_live' => $s['variants_live'], 'variants_unmatched' => $s['variants_unmatched'], 'label' => $s['label']];
}

function present_pull(array $p): array
{
    return ['pull_id' => $p['pull_id'], 'source_id' => $p['source_id'], 'kind' => $p['kind'], 'status' => $p['status'], 'queued' => $p['queued'], 'started_at' => json_ts($p['started_at']),
            'finished_at' => json_ts($p['finished_at']), 'listings_seen' => $p['listings_seen'], 'listings_new' => $p['listings_new'], 'listings_changed' => $p['listings_changed'],
            'variants_changed' => $p['variants_changed'], 'listings_removed' => $p['listings_removed'], 'http_requests' => $p['http_requests'], 'bytes' => $p['bytes'], 'error' => $p['error'],
            'policy' => $p['policy'], 'query' => $p['query']];
}

function present_template(array $t): array
{
    return ['template_id' => (int) $t['template_id'], 'key' => $t['key'], 'name' => $t['name'], 'connector' => $t['connector'], 'role' => $t['role'], 'base_url' => $t['base_url'],
            'brand_hint' => $t['brand_hint'], 'notes' => $t['notes'], 'survey_result' => $t['survey_result'], 'surveyed_at' => json_ts($t['surveyed_at'])];
}

/** A probe's facts fit for a screen or a reply: scalars and lists of scalars; anything named like a secret dropped (the connectors' contract says none is there — this holds it). */
function probe_facts_public(array $facts): array
{
    $out = [];
    foreach ($facts as $k => $v) {
        if (preg_match('/token|password|secret|private|passphrase|key$/i', (string) $k)) { continue; }
        if (is_scalar($v) || $v === null) { $out[$k] = $v; }
        elseif (is_array($v)) { $out[$k] = array_values(array_filter($v, static fn ($x) => is_scalar($x))) ?: (array_filter($v, 'is_array') !== [] ? array_map(static fn ($x) => is_array($x) ? json_encode($x) : $x, $v) : []); }
    }
    return $out;
}

const SOURCE_NOTICES = ['created' => ['success', 'The source is made — probe it, then pull it.'], 'saved' => ['success', 'Saved.'], 'queued' => ['success', 'Queued — the worker runs it within a minute.'],
    'paused' => ['success', 'Paused — the worker skips it until you resume it.'], 'resumed' => ['success', 'Resumed — the ladder is cleared.'], 'credential' => ['success', 'The credential is sealed — only its label and last four characters are kept in sight.'],
    'schedule' => ['success', 'The schedule is set.'], 'deleted' => ['success', 'The source is deleted.']];

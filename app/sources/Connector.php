<?php
declare(strict_types=1);

/**
 * The connector interface (design §6.1) — one class per source platform, five methods, the normalized listing of
 * normalize.php and nothing else. The worker matches, snapshots, watches and logs; a connector only READS.
 *
 * $source is the row-shaped array the worker passes:
 *   ['connector' => 'shopify', 'base_url' => 'https://store.example', 'settings' => [...], 'credential' => [...]|null
 *    (DECRYPTED by credentials.php, the only file that may), 'rate_per_second' => 1, 'user_agent' => null|string,
 *    'cache_dir' => null|string (tests), 'transport' => null|callable (tests)]
 *
 * probe()  → ['state' => ok|blocked|misconfigured, 'message' => one sentence, 'facts' => [...]]
 * pull()   → calls $emit(normalized listing) once per listing (the worker writes one transaction per call) and returns
 *            the pull's stats: ['status' => ok|partial|failed|blocked, 'listings_seen' => n, 'http_requests' => n,
 *            'bytes' => n, 'cached' => n, 'errors' => [string…], 'policy' => the HTTP client's facts (§0.2)]
 * search() → normalized listings matching $q, read LIVE — only where the platform searches; others throw InvNotSupported
 * lookup() → normalized listings for a few identifiers (['handle' => …], ['handles' => […]], ['id' => …], ['sku' => …],
 *            ['url' => …], ['urls' => […]] — each connector says which it takes in capabilities()['lookup_by'])
 * capabilities() → ['has_search', 'has_lookup', 'gives_qty', 'gives_cost', 'needs_credential', 'is_reference',
 *            'lookup_by' => [...], 'credential_kinds' => [...]]
 */
interface InvConnector
{
    public function probe(array $source): array;

    public function pull(array $source, callable $emit): array;

    public function search(array $source, string $q, int $limit = 20): array;

    public function lookup(array $source, array $identifiers): array;

    public function capabilities(): array;
}

/** Thrown by search()/lookup() on a connector that has no such door (the worker answers from the last pull). */
final class InvNotSupported extends RuntimeException
{
}

/** A source that cannot be read as configured (a missing setting, a missing credential, not that platform). */
final class InvMisconfigured extends RuntimeException
{
}

/** The probe result in one shape. */
function inv_probe_result(string $state, string $message, array $facts = []): array
{
    if (!in_array($state, ['ok', 'blocked', 'misconfigured'], true)) {
        throw new InvalidArgumentException("probe state $state");
    }
    return ['state' => $state, 'message' => $message, 'facts' => $facts];
}

/** The pull stats in one shape, from the HTTP client's facts (or none for a connector without a network). */
function inv_pull_stats(?InvHttp $http, int $seen, array $errors = [], ?string $status = null, array $extra = []): array
{
    $facts = $http ? $http->facts() : ['requests' => [], 'http_requests' => 0, 'bytes' => 0, 'cached' => 0, 'blocked' => 0, 'robots_skipped' => 0];
    if ($status === null) {
        $status = $facts['blocked'] > 0 ? ($seen > 0 ? 'partial' : 'blocked') : ($errors === [] ? 'ok' : ($seen > 0 ? 'partial' : 'failed'));
    }
    return array_merge([
        'status' => $status,
        'listings_seen' => $seen,
        'http_requests' => (int) $facts['http_requests'],
        'bytes' => (int) $facts['bytes'],
        'cached' => (int) $facts['cached'],
        'errors' => array_values(array_map(static fn($e) => mb_substr((string) $e, 0, 200), $errors)),
        'policy' => $facts,
    ], $extra);
}

/** The capabilities array with every key present. */
function inv_capabilities(array $set): array
{
    return array_merge([
        'has_search' => false, 'has_lookup' => false, 'gives_qty' => false, 'gives_cost' => false,
        'needs_credential' => false, 'is_reference' => false, 'lookup_by' => [], 'credential_kinds' => [],
    ], $set);
}

/** One setting of a source, with a default. */
function inv_setting(array $source, string $key, mixed $default = null): mixed
{
    $settings = $source['settings'] ?? [];
    if (is_string($settings)) {
        $settings = json_decode($settings, true) ?: [];
    }
    return array_key_exists($key, $settings) && $settings[$key] !== null && $settings[$key] !== '' ? $settings[$key] : $default;
}

/** The base URL without a trailing slash; InvMisconfigured when it is not an absolute http(s) URL. */
function inv_base_url(array $source): string
{
    $base = rtrim(trim((string) ($source['base_url'] ?? '')), '/');
    if (!preg_match('~^https?://[^/\s]+~i', $base)) {
        throw new InvMisconfigured('base_url must be an absolute http(s) URL');
    }
    return $base;
}

<?php
declare(strict_types=1);

/** Locations' prelude: the readers that turn the form (or an agent's call) into save_location()'s fields — a field left out stays as it was. */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/write.php';

function location_from_request(PDO $pdo, ?array $cur, array &$errors): array
{
    $f = [];
    $f['name'] = req_has('name') ? trim((string) req_val('name')) : (string) ($cur['name'] ?? '');
    if ($f['name'] === '' || mb_strlen($f['name']) > 120) { $errors['name'] = 'Give the location a name of up to 120 characters.'; }
    $f['kind'] = $cur['kind'] ?? 'warehouse';
    if (req_has('kind') && (string) req_val('kind') !== '') {
        $k = (string) req_val('kind');
        if (!isset(LOCATION_KINDS[$k])) { $errors['kind'] = 'The kind is warehouse, showroom, store, in_transit, returns or offsite.'; } else { $f['kind'] = $k; }
    }
    $f['address'] = req_has('address') ? (((string) req_val('address') === '') ? null : mb_substr((string) req_val('address'), 0, 1000)) : ($cur['address'] ?? null);
    $f['department_id'] = inv_ref($pdo, 'department', $cur['department_id'] ?? null, 'SELECT 1 FROM mcp_departments WHERE department_id = :id AND archived_at IS NULL', 'the department', $errors);
    $f['is_sellable'] = inv_yes('is_sellable', $cur['is_sellable'] ?? true);
    $f['allow_negative'] = inv_yes('allow_negative', $cur['allow_negative'] ?? false);
    $f['active'] = inv_yes('active', $cur['active'] ?? true);
    return $f;
}

function location_loggable(array $l): array
{
    return ['name' => $l['name'], 'kind' => $l['kind'], 'address_length' => mb_strlen((string) ($l['address'] ?? '')), 'department_id' => $l['department_id'],
            'is_sellable' => (bool) $l['is_sellable'], 'allow_negative' => (bool) $l['allow_negative'], 'active' => (bool) $l['active']];
}

function location_or_404(PDO $pdo, ?int $id): array
{
    $l = $id === null ? null : find_location($pdo, $id);
    if ($l === null) { refuse(404, 'Location not found.'); }
    return $l;
}

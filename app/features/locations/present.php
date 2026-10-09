<?php
declare(strict_types=1);

/** Locations' JSON shape and chips (stock.md "Status vocabulary"). */

function present_location(array $l): array
{
    return ['location_id' => (int) $l['location_id'], 'name' => $l['name'], 'kind' => $l['kind'], 'address' => $l['address'], 'department_id' => $l['department_id'],
            'department_name' => $l['department_name'] ?? null, 'is_sellable' => (bool) $l['is_sellable'], 'allow_negative' => (bool) $l['allow_negative'], 'active' => (bool) $l['active'],
            'units_on_hand' => isset($l['units_on_hand']) ? (int) $l['units_on_hand'] : null, 'label' => $l['name'] . ' (' . (LOCATION_KINDS[$l['kind']] ?? $l['kind']) . ')'];
}

function location_kind_chip(string $kind): string
{
    $c = ['warehouse' => 'primary', 'showroom' => 'info', 'store' => 'success', 'in_transit' => 'secondary', 'returns' => 'warning', 'offsite' => 'dark'][$kind] ?? 'secondary';
    return '<span class="badge bg-soft-' . $c . ' text-' . $c . '">' . e(LOCATION_KINDS[$kind] ?? $kind) . '</span>';
}

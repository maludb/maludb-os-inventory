<?php
declare(strict_types=1);

/** Suppliers' JSON shapes (a whitelist over the view row — the view already nulled the account number for a caller without purchasing.write) and chips. */

function present_supplier(array $s): array
{
    return ['supplier_id' => (int) $s['supplier_id'], 'name' => $s['name'], 'kind' => $s['kind'], 'contact_name' => $s['contact_name'], 'email' => $s['email'], 'phone' => $s['phone'], 'address' => $s['address'],
            'website' => $s['website'], 'account_number' => $s['account_number'], 'terms' => $s['terms'], 'dropships' => (bool) $s['dropships'], 'lead_time_days' => $s['lead_time_days'],
            'order_method' => $s['order_method'], 'order_email' => $s['order_email'], 'portal_url' => $s['portal_url'], 'min_order' => $s['min_order'], 'notes' => $s['notes'], 'active' => (bool) $s['active'],
            'open_orders' => (int) ($s['open_orders'] ?? 0), 'source_count' => (int) ($s['source_count'] ?? 0), 'created_at' => json_ts($s['created_at'] ?? null), 'updated_at' => json_ts($s['updated_at'] ?? null), 'label' => $s['name']];
}

function supplier_kind_word(string $kind): string
{
    return SUPPLIER_KINDS[$kind] ?? ucfirst(str_replace('_', ' ', $kind));
}

function supplier_kind_chip(string $kind): string
{
    return '<span class="badge bg-soft-secondary text-dark">' . e(supplier_kind_word($kind)) . '</span>';
}

function dropships_chip(): string
{
    return '<span class="badge bg-soft-success text-success"><i class="feather-truck me-1"></i>drop-ships</span>';
}

const SUPPLIER_NOTICES = ['created' => ['success', 'The supplier is made.'], 'saved' => ['success', 'Saved.'], 'archived' => ['success', 'The supplier is archived.'], 'restored' => ['success', 'The supplier is back.'],
                          'messaged' => ['success', 'The message is sent.']];

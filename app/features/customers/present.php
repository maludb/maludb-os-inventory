<?php
declare(strict_types=1);

/** Customers' JSON shape (a whitelist over the view row — the view already nulled what the caller may not see) and chips. */

function present_customer(array $c): array
{
    return ['customer_id' => (int) $c['customer_id'], 'name' => $c['name'], 'legal_name' => $c['legal_name'], 'email' => $c['email'], 'phone' => $c['phone'], 'phone_alt' => $c['phone_alt'],
            'billing_address' => $c['billing_address'], 'shipping_address' => $c['shipping_address'], 'tax_id' => $c['tax_id'], 'terms_days' => $c['terms_days'],
            'currency' => $c['currency'], 'income_account_id' => $c['income_account_id'], 'tax_rate_id' => $c['tax_rate_id'], 'source' => $c['source'], 'email_opt_in' => (bool) $c['email_opt_in'],
            'notes' => $c['notes'], 'archived' => $c['archived_at'] !== null, 'order_count' => (int) ($c['order_count'] ?? 0), 'open_orders' => (int) ($c['open_orders'] ?? 0),
            'last_order_on' => $c['last_order_on'] ?? null, 'created_at' => json_ts($c['created_at'] ?? null), 'updated_at' => json_ts($c['updated_at'] ?? null), 'label' => $c['name']];
}

function customer_source_chip(string $source): string
{
    return '<span class="badge bg-soft-secondary text-dark">' . e(CUSTOMER_SOURCES[$source] ?? $source) . '</span>';
}

const CUSTOMER_NOTICES = ['created' => ['success', 'The customer is made.'], 'saved' => ['success', 'Saved.'], 'archived' => ['success', 'The customer is archived.'], 'restored' => ['success', 'The customer is back.'],
                          'deleted' => ['success', 'The customer was deleted.']];

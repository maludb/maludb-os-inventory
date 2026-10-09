<?php
declare(strict_types=1);

/**
 * `manual` — a source with no door (design §0.2): a person records what the supplier said (a phone call, a price sheet)
 * as entries in settings.listings[], each with a date. No network. pull() emits them as listings; probe() is ok.
 *
 * settings.listings[]: {title, vendor, product_type, sku, gtin, mpn, size, price, cost, qty, availability,
 *   lead_time_days, ships_how, url, recorded_on (YYYY-MM-DD), note, currency, variants[] (the same keys, per size)}
 */

final class InvConnectorManual implements InvConnector
{
    public function capabilities(): array
    {
        return inv_capabilities([
            'has_search' => false, 'has_lookup' => false, 'gives_qty' => true, 'gives_cost' => true,
            'needs_credential' => false, 'is_reference' => false, 'lookup_by' => [], 'credential_kinds' => [],
        ]);
    }

    public function probe(array $source): array
    {
        $entries = inv_setting($source, 'listings', []);
        if (!is_array($entries)) {
            return inv_probe_result('misconfigured', 'settings.listings must be a list of entries.', []);
        }
        $n = count($entries);
        $dates = array_values(array_filter(array_map(static fn($e) => is_array($e) ? ($e['recorded_on'] ?? null) : null, $entries)));
        sort($dates);
        return inv_probe_result('ok', $n === 0 ? 'A manual source; it holds no entries yet.' : "$n entries typed in" . ($dates !== [] ? ', the latest dated ' . end($dates) : '') . '.', ['entries' => $n, 'latest' => $dates !== [] ? end($dates) : null]);
    }

    public function pull(array $source, callable $emit): array
    {
        $entries = inv_setting($source, 'listings', []);
        $errors = [];
        $seen = 0;
        foreach (is_array($entries) ? $entries : [] as $i => $e) {
            if (!is_array($e)) {
                $errors[] = 'entry ' . ($i + 1) . ' is not an object';
                continue;
            }
            try {
                $emit($this->fromEntry($e, $i, $source));
                $seen++;
            } catch (InvalidArgumentException $x) {
                $errors[] = 'entry ' . ($i + 1) . ': ' . $x->getMessage();
            }
        }
        return inv_pull_stats(null, $seen, $errors, $errors === [] ? 'ok' : ($seen > 0 ? 'partial' : 'failed'), ['entries' => is_array($entries) ? count($entries) : 0]);
    }

    public function search(array $source, string $q, int $limit = 20): array
    {
        throw new InvNotSupported('a manual source has no search; Find answers from the last pull');
    }

    public function lookup(array $source, array $identifiers): array
    {
        throw new InvNotSupported('a manual source has no lookup');
    }

    public function fromEntry(array $e, int $i, array $source): array
    {
        $currency = inv_currency($e['currency'] ?? null) ?? inv_currency(inv_setting($source, 'currency')) ?? 'USD';
        $sku = inv_str($e['sku'] ?? null, 100);
        $gtin = inv_gtin_normalize($e['gtin'] ?? ($e['barcode'] ?? null));
        $given = is_array($e['variants'] ?? null) ? $e['variants'] : [];
        $firstVariant = is_array($given[0] ?? null) ? $given[0] : [];
        // DECISION: an entry's external_id is its sku, else its GTIN, else the first variant's, else manual-<n>
        $external = inv_str($e['external_id'] ?? null, 200) ?? $sku ?? $gtin
            ?? inv_str($firstVariant['sku'] ?? null, 100) ?? inv_gtin_normalize($firstVariant['gtin'] ?? ($firstVariant['barcode'] ?? null))
            ?? ('manual-' . ($i + 1));
        $variants = [];
        $rows = $given !== [] ? $given : [$e];
        foreach ($rows as $j => $v) {
            if (!is_array($v)) {
                continue;
            }
            $vsku = inv_str($v['sku'] ?? null, 100) ?? ($given === [] ? $sku : null);
            $qty = inv_norm_int($v['qty'] ?? null);
            $variants[] = [
                'external_variant_id' => $vsku ?? inv_gtin_normalize($v['gtin'] ?? ($v['barcode'] ?? null)) ?? ($external . ':' . ($j + 1)),
                'title' => inv_str($v['title'] ?? null, 300) ?? (isset($v['size']) ? ($e['title'] ?? '') . ' - ' . $v['size'] : ($e['title'] ?? '')),
                'option_values' => array_filter(['Size' => inv_str($v['size'] ?? null, 100)]),
                'sku' => $vsku,
                'barcode' => $v['gtin'] ?? ($v['barcode'] ?? null),
                'mpn' => $v['mpn'] ?? ($e['mpn'] ?? null),
                'price' => $v['price'] ?? null,
                'compare_at_price' => $v['compare_at_price'] ?? null,
                'cost_price' => $v['cost'] ?? ($v['cost_price'] ?? null),
                'currency' => $currency,
                'availability' => $v['availability'] ?? null,
                'qty' => $qty,
                'lead_time_days' => $v['lead_time_days'] ?? ($e['lead_time_days'] ?? null),
                'ships_how' => $v['ships_how'] ?? ($e['ships_how'] ?? null),
                'url' => $v['url'] ?? ($e['url'] ?? null),
            ];
        }
        return inv_normalize_listing([
            'external_id' => $external,
            'handle' => null,
            'url' => $e['url'] ?? null,
            'title' => $e['title'] ?? ($e['name'] ?? ''),
            'vendor' => $e['vendor'] ?? ($e['brand'] ?? null),
            'product_type' => $e['product_type'] ?? null,
            'tags' => $e['tags'] ?? [],
            'currency' => $currency,
            'variants' => $variants,
            'raw' => ['recorded_on' => inv_str($e['recorded_on'] ?? null, 10), 'note' => inv_str($e['note'] ?? null, 500), 'recorded_by' => inv_str($e['recorded_by'] ?? null, 100), 'manual' => true],
        ]);
    }
}

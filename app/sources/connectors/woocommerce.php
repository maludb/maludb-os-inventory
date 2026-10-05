<?php
declare(strict_types=1);

/**
 * `woocommerce` — a WooCommerce store's public catalog through the Store API (design §0.2):
 * GET {base}/wp-json/wc/store/v1/products?per_page=100&page=N (stops on an empty page or past X-WP-TotalPages);
 * a variable product's variations[] resolved one by one through /products/<id>; prices in minor units with
 * currency_minor_unit; is_in_stock, low_stock_remaining, stock_status; attributes (Size) → option values;
 * search= supported; lookup by id or sku.
 *
 * settings: currency (default: what the store says), vendor (a brand when the store names none), max_products
 */

final class InvConnectorWooCommerce implements InvConnector
{
    public const PAGE_SIZE = 100;
    public const MAX_PRODUCTS = 25000;
    public const API = '/wp-json/wc/store/v1/products';

    public function capabilities(): array
    {
        return inv_capabilities([
            'has_search' => true, 'has_lookup' => true, 'gives_qty' => 'low_stock_remaining only', 'gives_cost' => false,
            'needs_credential' => false, 'is_reference' => false, 'lookup_by' => ['id', 'ids', 'sku'], 'credential_kinds' => [],
        ]);
    }

    public function probe(array $source): array
    {
        $base = inv_base_url($source);
        $http = inv_http_client($source);
        $robots = $http->robotsFor($base . '/');
        if ($robots['state'] === 'blocked') {
            return inv_probe_result('blocked', 'The store refuses crawlers at robots.txt.', ['robots' => 'blocked']);
        }
        if (!$http->allows($base . self::API)) {
            return inv_probe_result('blocked', 'robots.txt disallows the Store API path for our user-agent; nothing is read.', ['robots' => 'disallow']);
        }
        $r = $http->get($base . self::API . '?per_page=1');
        if ($r['blocked']) {
            return inv_probe_result('blocked', 'The Store API answered with a block (' . $r['reason'] . '); the worker backs off.', ['reason' => $r['reason']]);
        }
        $data = inv_http_json($r);
        if ($data === null || !array_is_list($data)) {
            return inv_probe_result('misconfigured', 'Not a WooCommerce Store API: ' . self::API . ' answered HTTP ' . $r['status'] . '.', ['status' => $r['status']]);
        }
        $total = isset($r['headers']['x-wp-total']) ? (int) $r['headers']['x-wp-total'] : null;
        return inv_probe_result('ok', $total !== null ? "The Store API is open with $total products." : 'The Store API is open.', ['total' => $total, 'robots' => $robots['state']]);
    }

    public function pull(array $source, callable $emit): array
    {
        $base = inv_base_url($source);
        $http = inv_http_client($source);
        $seen = 0;
        $errors = [];
        $blocked = false;
        $max = (int) inv_setting($source, 'max_products', self::MAX_PRODUCTS);
        $totalPages = null;
        for ($page = 1; $seen < $max; $page++) {
            if ($totalPages !== null && $page > $totalPages) {
                break;
            }
            $r = $http->get($base . self::API . '?per_page=' . self::PAGE_SIZE . '&page=' . $page);
            if ($r['blocked']) {
                $errors[] = 'blocked at page ' . $page . ' (' . $r['reason'] . ')';
                $blocked = true;
                break;
            }
            if ($r['skipped']) {
                $errors[] = 'robots.txt disallows the Store API';
                break;
            }
            $data = inv_http_json($r);
            if ($data === null || !array_is_list($data)) {
                if ($r['status'] === 400 && $page > 1) {
                    break; // past the last page: Woo answers 400 rest_product_invalid_page_number
                }
                $errors[] = 'page ' . $page . ' answered HTTP ' . $r['status'] . ' without a product list';
                break;
            }
            if ($data === []) {
                break;
            }
            if (isset($r['headers']['x-wp-totalpages'])) {
                $totalPages = (int) $r['headers']['x-wp-totalpages'];
            }
            foreach ($data as $p) {
                if (!is_array($p) || ($p['type'] ?? '') === 'variation') {
                    continue;
                }
                $listing = $this->fromProduct($http, $base, $source, $p);
                $emit($listing);
                $seen++;
                if ($seen >= $max) {
                    break;
                }
            }
            if (count($data) < self::PAGE_SIZE && $totalPages === null) {
                break;
            }
        }
        return inv_pull_stats($http, $seen, $errors, $blocked ? ($seen > 0 ? 'partial' : 'blocked') : null);
    }

    public function search(array $source, string $q, int $limit = 20): array
    {
        $base = inv_base_url($source);
        $http = inv_http_client($source);
        $r = $http->get($base . self::API . '?search=' . rawurlencode($q) . '&per_page=' . max(1, min(100, $limit)));
        if ($r['blocked']) {
            throw new InvMisconfigured('search blocked: ' . $r['reason']);
        }
        $data = inv_http_json($r);
        if ($data === null || !array_is_list($data)) {
            throw new InvNotSupported('the Store API did not answer search= (HTTP ' . $r['status'] . ')');
        }
        $out = [];
        foreach ($data as $p) {
            if (is_array($p) && ($p['type'] ?? '') !== 'variation') {
                $out[] = $this->fromProduct($http, $base, $source, $p);
            }
        }
        return $out;
    }

    public function lookup(array $source, array $identifiers): array
    {
        $base = inv_base_url($source);
        $http = inv_http_client($source);
        $out = [];
        $ids = [];
        if (isset($identifiers['id'])) {
            $ids[] = (int) $identifiers['id'];
        }
        foreach ((array) ($identifiers['ids'] ?? []) as $id) {
            $ids[] = (int) $id;
        }
        foreach (array_unique(array_filter($ids)) as $id) {
            $r = $http->get($base . self::API . '/' . $id);
            $p = inv_http_json($r);
            if (is_array($p) && isset($p['id'])) {
                $out[] = $this->fromProduct($http, $base, $source, $p);
            }
        }
        if (isset($identifiers['sku'])) {
            $r = $http->get($base . self::API . '?sku=' . rawurlencode((string) $identifiers['sku']));
            $data = inv_http_json($r);
            foreach (is_array($data) && array_is_list($data) ? $data : [] as $p) {
                if (is_array($p)) {
                    $out[] = $this->fromProduct($http, $base, $source, $p);
                }
            }
        }
        if ($ids === [] && !isset($identifiers['sku'])) {
            throw new InvNotSupported('woocommerce looks up by id, ids or sku');
        }
        return $out;
    }

    /** A Store API product (simple or variable, its variations fetched) → the normalized listing. */
    public function fromProduct(InvHttp $http, string $base, array $source, array $p): array
    {
        $currency = inv_currency($p['prices']['currency_code'] ?? null) ?? inv_currency(inv_setting($source, 'currency')) ?? 'USD';
        $unit = (int) ($p['prices']['currency_minor_unit'] ?? 2);
        $brand = null;
        foreach ((array) ($p['brands'] ?? []) as $b) {
            $brand = is_array($b) ? ($b['name'] ?? null) : (is_string($b) ? $b : null);
            if ($brand !== null) {
                break;
            }
        }
        $attrs = [];
        foreach ((array) ($p['attributes'] ?? []) as $a) {
            if (!is_array($a) || !isset($a['name'])) {
                continue;
            }
            $terms = array_values(array_filter(array_map(static fn($t) => is_array($t) ? ($t['name'] ?? null) : null, (array) ($a['terms'] ?? []))));
            $attrs[(string) $a['name']] = $terms;
            if ($brand === null && preg_match('~^(brand|manufacturer|vendor)$~i', (string) $a['name']) && $terms !== []) {
                $brand = $terms[0];
            }
        }
        $brand ??= inv_setting($source, 'vendor');
        $categories = array_values(array_filter(array_map(static fn($c) => is_array($c) ? ($c['name'] ?? null) : null, (array) ($p['categories'] ?? []))));
        $images = [];
        foreach ((array) ($p['images'] ?? []) as $img) {
            if (is_array($img) && isset($img['src'])) {
                $images[] = (string) $img['src'];
            }
            if (count($images) >= 5) {
                break;
            }
        }
        $variants = [];
        $variations = is_array($p['variations'] ?? null) ? $p['variations'] : [];
        if (($p['type'] ?? '') === 'variable' && $variations !== []) {
            foreach ($variations as $vs) {
                $vid = (int) ($vs['id'] ?? 0);
                if ($vid <= 0) {
                    continue;
                }
                $values = [];
                foreach ((array) ($vs['attributes'] ?? []) as $a) {
                    if (isset($a['name'], $a['value'])) {
                        $values[(string) $a['name']] = $this->termName($attrs, (string) $a['name'], (string) $a['value']);
                    }
                }
                $vr = $http->get($base . self::API . '/' . $vid);
                $v = inv_http_json($vr);
                if (!is_array($v) || !isset($v['id'])) {
                    $v = ['id' => $vid, 'prices' => $p['prices'] ?? [], 'is_in_stock' => $p['is_in_stock'] ?? null, 'sku' => null, 'unresolved' => true];
                }
                if (isset($v['variation']) && is_string($v['variation']) && $values === []) {
                    foreach (explode(',', $v['variation']) as $pair) { // "Size: Queen, Firmness: Medium"
                        if (str_contains($pair, ':')) {
                            [$k, $val] = array_map('trim', explode(':', $pair, 2));
                            $values[$k] = $val;
                        }
                    }
                }
                $variants[] = $this->variant($v, $p, $values, $currency, $unit, $base);
            }
        } else {
            $values = [];
            foreach ($attrs as $name => $terms) {
                if (count($terms) === 1) {
                    $values[$name] = $terms[0];
                }
            }
            $variants[] = $this->variant($p, $p, $values, $currency, $unit, $base);
        }
        $slug = inv_str($p['slug'] ?? null, 200);
        return inv_normalize_listing([
            'external_id' => (string) ($p['id'] ?? $slug),
            'handle' => $slug,
            'url' => $p['permalink'] ?? null,
            'title' => html_entity_decode((string) ($p['name'] ?? ''), ENT_QUOTES | ENT_HTML5),
            'vendor' => $brand,
            'product_type' => $categories[0] ?? null,
            'tags' => array_merge(inv_tags($p['tags'] ?? []), array_slice($categories, 1)),
            'currency' => $currency,
            'variants' => $variants,
            'raw' => ['images' => $images, 'attributes' => $attrs, 'categories' => $categories, 'type' => $p['type'] ?? null, 'on_sale' => $p['on_sale'] ?? null],
        ]);
    }

    /** A variation's attribute value is a term slug ("queen"); the parent's attribute lists the term name ("Queen"). */
    private function termName(array $attrs, string $attr, string $value): string
    {
        foreach ($attrs[$attr] ?? [] as $name) {
            if (strtolower(preg_replace('~[^a-z0-9]+~', '-', strtolower($name)) ?? '') === strtolower($value) || strtolower($name) === strtolower($value)) {
                return $name;
            }
        }
        return $value;
    }

    private function variant(array $v, array $parent, array $values, string $currency, int $unit, string $base): array
    {
        $prices = is_array($v['prices'] ?? null) ? $v['prices'] : [];
        $price = inv_money_minor($prices['price'] ?? null, $unit);
        $regular = inv_money_minor($prices['regular_price'] ?? null, $unit);
        $stockStatus = $v['stock_status'] ?? null;   // REST v3 word when a plugin exposes it: instock / outofstock / onbackorder
        $inStock = isset($v['is_in_stock']) ? (bool) $v['is_in_stock'] : null;
        $low = isset($v['low_stock_remaining']) && is_numeric($v['low_stock_remaining']) ? (int) $v['low_stock_remaining'] : null;
        $text = $v['stock_availability']['text'] ?? null;
        $availability = $stockStatus !== null ? inv_availability_state($stockStatus)
            : ($inStock === false ? ($text !== null && inv_availability_state($text) === 'back_order' ? 'back_order' : 'out_of_stock')
            : ($inStock === true ? ($low !== null && $low > 0 ? 'limited' : 'in_stock') : inv_availability_state($text)));
        $sku = inv_str($v['sku'] ?? null) ?? inv_str($parent['sku'] ?? null);
        $gtin = $v['global_unique_id'] ?? ($v['gtin'] ?? ($v['barcode'] ?? null));
        $title = html_entity_decode((string) ($v['name'] ?? ($parent['name'] ?? '')), ENT_QUOTES | ENT_HTML5);
        if ($values !== [] && !isset($v['name'])) {
            $title = html_entity_decode((string) ($parent['name'] ?? ''), ENT_QUOTES | ENT_HTML5) . ' - ' . implode(', ', $values);
        }
        return [
            'external_variant_id' => (string) ($v['id'] ?? ''),
            'title' => $title,
            'option_values' => $values,
            'sku' => $sku,
            'barcode' => $gtin,
            'price' => $price,
            'compare_at_price' => !empty($parent['on_sale']) || !empty($v['on_sale']) ? $regular : null,
            'currency' => $currency,
            'availability' => $availability,
            'qty' => $low,
            'url' => $v['permalink'] ?? ($parent['permalink'] ?? null),
        ];
    }
}

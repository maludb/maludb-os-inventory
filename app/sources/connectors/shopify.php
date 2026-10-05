<?php
declare(strict_types=1);

/**
 * `shopify` — a Shopify store's public catalog (design §0.2). GET {base}/products.json?limit=250&page=N until the
 * page is empty (a hard stop at 25,000 products); settings.collections[] narrows to /collections/<handle>/products.json;
 * lookup by handle through /products/<handle>.js; search through /search/suggest.json (InvNotSupported when the
 * store does not answer it). With a Storefront access token (credential kind `bearer`) the pull reads the Storefront
 * GraphQL API instead, which adds quantityAvailable.
 *
 * settings: collections[] (handles), currency (default USD — products.json carries none), api_version (default
 * 2024-10 for the Storefront API), max_products (default 25000)
 */

final class InvConnectorShopify implements InvConnector
{
    public const PAGE_SIZE = 250;
    public const MAX_PRODUCTS = 25000;

    public function capabilities(): array
    {
        return inv_capabilities([
            'has_search' => true, 'has_lookup' => true, 'gives_qty' => 'with a Storefront token', 'gives_cost' => false,
            'needs_credential' => false, 'is_reference' => false, 'lookup_by' => ['handle', 'handles'], 'credential_kinds' => ['bearer'],
        ]);
    }

    public function probe(array $source): array
    {
        $base = inv_base_url($source);
        $http = inv_http_client($source);
        $robots = $http->robotsFor($base . '/');
        if ($robots['state'] === 'blocked') {
            return inv_probe_result('blocked', 'The store refuses crawlers at robots.txt.', ['robots' => 'blocked'] + $this->facts($http));
        }
        if (!$http->allows($base . '/products.json')) {
            return inv_probe_result('blocked', 'robots.txt disallows /products.json for our user-agent; nothing is read.', ['robots' => 'disallow'] + $this->facts($http));
        }
        if ($this->token($source) !== null) {
            $r = $this->graphql($http, $source, 'query { shop { name } }', []);
            if ($r['blocked']) {
                return inv_probe_result('blocked', 'The Storefront API answered with a block (' . $r['reason'] . ').', $this->facts($http));
            }
            $data = inv_http_json($r);
            if (!isset($data['data']['shop']['name'])) {
                return inv_probe_result('misconfigured', 'The Storefront access token was not accepted (HTTP ' . $r['status'] . ').', $this->facts($http));
            }
            return inv_probe_result('ok', 'Storefront API reachable as "' . $data['data']['shop']['name'] . '"; quantities will be read.', ['shop' => $data['data']['shop']['name'], 'storefront' => true] + $this->facts($http));
        }
        $r = $http->get($base . '/products.json?limit=1');
        if ($r['blocked']) {
            return inv_probe_result('blocked', 'The store answered /products.json with a block (' . $r['reason'] . '); the worker backs off.', $this->facts($http));
        }
        $data = inv_http_json($r);
        if ($data === null || !array_key_exists('products', $data)) {
            return inv_probe_result('misconfigured', 'Not a Shopify storefront: /products.json answered HTTP ' . $r['status'] . ' without a products list.', $this->facts($http));
        }
        $n = count($data['products']);
        return inv_probe_result('ok', $n > 0 ? 'The public catalog is open; the first product is "' . ($data['products'][0]['title'] ?? '') . '".' : 'The public catalog answers but holds no published product.', ['first_page_products' => $n, 'robots' => $robots['state']] + $this->facts($http));
    }

    public function pull(array $source, callable $emit): array
    {
        $base = inv_base_url($source);
        $http = inv_http_client($source);
        $seen = 0;
        $errors = [];
        $max = (int) inv_setting($source, 'max_products', self::MAX_PRODUCTS);
        if ($this->token($source) !== null) {
            return $this->pullStorefront($http, $source, $emit, $max);
        }
        $collections = inv_setting($source, 'collections', []);
        $paths = [];
        if (is_array($collections) && $collections !== []) {
            foreach ($collections as $handle) {
                $h = preg_replace('~[^a-z0-9\-_]~', '', strtolower((string) $handle)) ?? '';
                if ($h !== '') {
                    $paths[] = '/collections/' . $h . '/products.json';
                }
            }
        } else {
            $paths[] = '/products.json';
        }
        $emitted = [];
        $blocked = false;
        foreach ($paths as $path) {
            for ($page = 1; $seen < $max; $page++) {
                $r = $http->get($base . $path . '?limit=' . self::PAGE_SIZE . '&page=' . $page);
                if ($r['blocked']) {
                    $errors[] = 'blocked at page ' . $page . ' of ' . $path . ' (' . $r['reason'] . ')';
                    $blocked = true;
                    break 2;
                }
                if ($r['skipped']) {
                    $errors[] = 'robots.txt disallows ' . $path;
                    break;
                }
                $data = inv_http_json($r);
                if ($data === null || !isset($data['products']) || !is_array($data['products'])) {
                    $errors[] = 'page ' . $page . ' of ' . $path . ' answered HTTP ' . $r['status'] . ' without products';
                    break;
                }
                if ($data['products'] === []) {
                    break;
                }
                foreach ($data['products'] as $p) {
                    if (!is_array($p)) {
                        continue;
                    }
                    $listing = $this->fromProduct($p, $base, $source);
                    if (isset($emitted[$listing['external_id']])) {
                        continue; // a product in two collections
                    }
                    $emitted[$listing['external_id']] = true;
                    $emit($listing);
                    $seen++;
                    if ($seen >= $max) {
                        break;
                    }
                }
                if (count($data['products']) < self::PAGE_SIZE) {
                    break; // DECISION: a short page is the last — the empty page after it is not fetched
                }
            }
        }
        return inv_pull_stats($http, $seen, $errors, $blocked ? ($seen > 0 ? 'partial' : 'blocked') : null);
    }

    public function search(array $source, string $q, int $limit = 20): array
    {
        $base = inv_base_url($source);
        $http = inv_http_client($source);
        $limit = max(1, min(50, $limit));
        $r = $http->get($base . '/search/suggest.json?q=' . rawurlencode($q) . '&resources[type]=product&resources[limit]=' . $limit);
        if ($r['blocked']) {
            throw new InvMisconfigured('search blocked: ' . $r['reason']);
        }
        $data = inv_http_json($r);
        if ($data === null || !isset($data['resources']['results']['products'])) {
            // DECISION: a store that does not answer suggest.json (404, a theme without predictive search) has no live
            // search; a collection handle equal to the query is the fallback the design names ("by collection filter")
            $h = preg_replace('~[^a-z0-9\-_]~', '', strtolower($q)) ?? '';
            if ($h !== '') {
                $c = $http->get($base . '/collections/' . $h . '/products.json?limit=' . $limit);
                $cd = inv_http_json($c);
                if (is_array($cd['products'] ?? null) && $cd['products'] !== []) {
                    return array_map(fn($p) => $this->fromProduct($p, $base, $source), array_slice($cd['products'], 0, $limit));
                }
            }
            throw new InvNotSupported('this store does not answer /search/suggest.json');
        }
        $out = [];
        foreach ($data['resources']['results']['products'] as $hit) {
            $handle = inv_str($hit['handle'] ?? null, 200);
            if ($handle === null) {
                continue;
            }
            $one = $this->byHandle($http, $base, $handle, $source);
            if ($one !== null) {
                $out[] = $one;
            } else {
                $out[] = inv_normalize_listing([
                    'external_id' => (string) ($hit['id'] ?? $handle), 'handle' => $handle, 'url' => $base . '/products/' . $handle,
                    'title' => $hit['title'] ?? '', 'vendor' => $hit['vendor'] ?? null, 'product_type' => $hit['type'] ?? null,
                    'tags' => $hit['tags'] ?? [], 'price' => $hit['price'] ?? null, 'available' => $hit['available'] ?? null,
                    'currency' => inv_setting($source, 'currency', 'USD'), 'raw' => ['suggest' => true],
                ]);
            }
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    public function lookup(array $source, array $identifiers): array
    {
        $base = inv_base_url($source);
        $http = inv_http_client($source);
        $handles = [];
        if (isset($identifiers['handle'])) {
            $handles[] = (string) $identifiers['handle'];
        }
        foreach ((array) ($identifiers['handles'] ?? []) as $h) {
            $handles[] = (string) $h;
        }
        if (isset($identifiers['url']) && preg_match('~/products/([a-z0-9\-_]+)~i', (string) $identifiers['url'], $m)) {
            $handles[] = $m[1];
        }
        if ($handles === []) {
            throw new InvNotSupported('shopify looks up by handle (handle, handles, or a product url)');
        }
        $out = [];
        foreach (array_unique($handles) as $h) {
            $one = $this->byHandle($http, $base, $h, $source);
            if ($one !== null) {
                $out[] = $one;
            }
        }
        return $out;
    }

    private function byHandle(InvHttp $http, string $base, string $handle, array $source): ?array
    {
        $h = preg_replace('~[^a-z0-9\-_]~', '', strtolower($handle)) ?? '';
        if ($h === '') {
            return null;
        }
        $r = $http->get($base . '/products/' . $h . '.js');
        $p = inv_http_json($r);
        if ($p === null || !isset($p['variants'])) {
            return null;
        }
        return $this->fromProduct($p, $base, $source, true);
    }

    private function token(array $source): ?string
    {
        $cred = $source['credential'] ?? null;
        if (is_array($cred) && ($cred['kind'] ?? '') === 'bearer' && inv_str($cred['token'] ?? null) !== null) {
            return trim((string) $cred['token']);
        }
        return null;
    }

    private function facts(InvHttp $http): array
    {
        $f = $http->facts();
        return ['http_requests' => $f['http_requests'], 'blocked' => $f['blocked']];
    }

    /**
     * A product of products.json (prices as "999.00" strings, options as objects, tags an array) or of
     * /products/<handle>.js ($js: prices in cents, options as names, `type` for product_type) → the normalized listing.
     */
    public function fromProduct(array $p, string $base, array $source, bool $js = false): array
    {
        // DECISION: products.json and .js carry no currency — settings.currency, default USD (the Storefront API says its own)
        $currency = inv_setting($source, 'currency', 'USD');
        $handle = inv_str($p['handle'] ?? null, 200);
        $optionNames = [];
        foreach ((array) ($p['options'] ?? []) as $i => $o) {
            $optionNames[$i] = is_array($o) ? (string) ($o['name'] ?? ('Option ' . ($i + 1))) : (string) $o;
        }
        $images = [];
        foreach ((array) ($p['images'] ?? []) as $img) {
            $src = is_array($img) ? ($img['src'] ?? null) : $img;
            if (is_string($src) && $src !== '') {
                $images[] = str_starts_with($src, '//') ? 'https:' . $src : $src;
            }
            if (count($images) >= 5) {
                break;
            }
        }
        $variants = [];
        foreach ((array) ($p['variants'] ?? []) as $v) {
            if (!is_array($v)) {
                continue;
            }
            $values = [];
            for ($i = 1; $i <= 3; $i++) {
                $val = $v['option' . $i] ?? null;
                if (is_string($val) && $val !== '' && $val !== 'Default Title') {
                    $values[$optionNames[$i - 1] ?? ('Option ' . $i)] = $val;
                }
            }
            if ($values === [] && isset($v['options']) && is_array($v['options'])) {
                foreach ($v['options'] as $i => $val) {
                    if (is_string($val) && $val !== '' && $val !== 'Default Title') {
                        $values[$optionNames[$i] ?? ('Option ' . ($i + 1))] = $val;
                    }
                }
            }
            $price = $v['price'] ?? null;
            $compare = $v['compare_at_price'] ?? null;
            if ($js) { // the .js endpoint gives cents
                $price = is_numeric($price) ? inv_money_minor((string) (int) $price, 2) : null;
                $compare = is_numeric($compare) ? inv_money_minor((string) (int) $compare, 2) : null;
            }
            $qty = isset($v['inventory_quantity']) && is_numeric($v['inventory_quantity']) ? (int) $v['inventory_quantity'] : null;
            $variants[] = [
                'external_variant_id' => (string) ($v['id'] ?? ''),
                'title' => ($v['title'] ?? '') === 'Default Title' ? ($p['title'] ?? '') : ($v['title'] ?? ''),
                'option_values' => $values,
                'sku' => $v['sku'] ?? null,
                'barcode' => $v['barcode'] ?? null,
                'price' => $price,
                'compare_at_price' => $compare,
                'currency' => $currency,
                'available' => isset($v['available']) ? (bool) $v['available'] : null,
                'qty' => $qty !== null && $qty >= 0 ? $qty : null,
                'url' => $handle !== null ? $base . '/products/' . $handle . '?variant=' . ($v['id'] ?? '') : null,
            ];
        }
        return inv_normalize_listing([
            'external_id' => (string) ($p['id'] ?? $handle),
            'handle' => $handle,
            'url' => $handle !== null ? $base . '/products/' . $handle : null,
            'title' => $p['title'] ?? '',
            'vendor' => $p['vendor'] ?? null,
            'product_type' => $p['product_type'] ?? ($p['type'] ?? null),
            'tags' => $p['tags'] ?? [],
            'currency' => $currency,
            'variants' => $variants,
            'raw' => [
                'images' => $images,
                'options' => array_values($optionNames),
                'published_at' => $p['published_at'] ?? null,
                'updated_at' => $p['updated_at'] ?? null,
                'grams' => array_values(array_filter(array_map(static fn($v) => is_array($v) ? ($v['grams'] ?? ($v['weight'] ?? null)) : null, (array) ($p['variants'] ?? [])), static fn($g) => $g !== null)),
            ],
        ]);
    }

    /** POST the Storefront GraphQL API. */
    private function graphql(InvHttp $http, array $source, string $query, array $variables): array
    {
        $base = inv_base_url($source);
        $version = preg_replace('~[^0-9\-]~', '', (string) inv_setting($source, 'api_version', '2024-10')) ?: '2024-10';
        return $http->post($base . '/api/' . $version . '/graphql.json', json_encode(['query' => $query, 'variables' => $variables], JSON_THROW_ON_ERROR), [
            'headers' => ['X-Shopify-Storefront-Access-Token' => (string) $this->token($source)],
            'accept' => 'application/json',
        ]);
    }

    public const STOREFRONT_QUERY = <<<'GQL'
query InventoryPull($first: Int!, $after: String) {
  products(first: $first, after: $after) {
    pageInfo { hasNextPage endCursor }
    edges { node {
      id handle title vendor productType tags onlineStoreUrl updatedAt
      options { name values }
      images(first: 5) { edges { node { url } } }
      variants(first: 100) { edges { node {
        id title sku barcode availableForSale quantityAvailable
        price { amount currencyCode } compareAtPrice { amount currencyCode }
        selectedOptions { name value } weight weightUnit
      } } }
    } }
  }
}
GQL;

    private function pullStorefront(InvHttp $http, array $source, callable $emit, int $max): array
    {
        $base = inv_base_url($source);
        $seen = 0;
        $errors = [];
        $after = null;
        $blocked = false;
        do {
            $r = $this->graphql($http, $source, self::STOREFRONT_QUERY, ['first' => 100, 'after' => $after]);
            if ($r['blocked']) {
                $errors[] = 'Storefront API blocked (' . $r['reason'] . ')';
                $blocked = true;
                break;
            }
            $data = inv_http_json($r);
            if (!isset($data['data']['products']['edges'])) {
                $errors[] = 'Storefront API answered HTTP ' . $r['status'] . (isset($data['errors'][0]['message']) ? ': ' . $data['errors'][0]['message'] : ' without products');
                break;
            }
            foreach ($data['data']['products']['edges'] as $edge) {
                $n = $edge['node'] ?? null;
                if (!is_array($n)) {
                    continue;
                }
                $emit($this->fromStorefrontNode($n, $base));
                $seen++;
                if ($seen >= $max) {
                    break 2;
                }
            }
            $info = $data['data']['products']['pageInfo'] ?? [];
            $after = !empty($info['hasNextPage']) && !empty($info['endCursor']) ? (string) $info['endCursor'] : null;
        } while ($after !== null);
        return inv_pull_stats($http, $seen, $errors, $blocked ? ($seen > 0 ? 'partial' : 'blocked') : null, ['storefront' => true]);
    }

    /** gid://shopify/Product/123 → "123" (the same id products.json gives). DECISION. */
    public static function gid(mixed $gid): string
    {
        $s = (string) $gid;
        return preg_match('~/(\d+)(\?|$)~', $s, $m) ? $m[1] : $s;
    }

    public function fromStorefrontNode(array $n, string $base): array
    {
        $handle = inv_str($n['handle'] ?? null, 200);
        $variants = [];
        foreach ((array) ($n['variants']['edges'] ?? []) as $e) {
            $v = $e['node'] ?? null;
            if (!is_array($v)) {
                continue;
            }
            $values = [];
            foreach ((array) ($v['selectedOptions'] ?? []) as $o) {
                if (isset($o['name'], $o['value']) && $o['value'] !== 'Default Title') {
                    $values[(string) $o['name']] = (string) $o['value'];
                }
            }
            $qty = isset($v['quantityAvailable']) && is_numeric($v['quantityAvailable']) ? (int) $v['quantityAvailable'] : null;
            $variants[] = [
                'external_variant_id' => self::gid($v['id'] ?? ''),
                'title' => ($v['title'] ?? '') === 'Default Title' ? ($n['title'] ?? '') : ($v['title'] ?? ''),
                'option_values' => $values,
                'sku' => $v['sku'] ?? null,
                'barcode' => $v['barcode'] ?? null,
                'price' => $v['price']['amount'] ?? null,
                'compare_at_price' => $v['compareAtPrice']['amount'] ?? null,
                'currency' => $v['price']['currencyCode'] ?? null,
                'available' => isset($v['availableForSale']) ? (bool) $v['availableForSale'] : null,
                'qty' => $qty !== null && $qty >= 0 ? $qty : ($qty !== null ? 0 : null),
                'url' => $handle !== null ? $base . '/products/' . $handle . '?variant=' . self::gid($v['id'] ?? '') : null,
            ];
        }
        $images = [];
        foreach ((array) ($n['images']['edges'] ?? []) as $e) {
            if (isset($e['node']['url'])) {
                $images[] = (string) $e['node']['url'];
            }
        }
        return inv_normalize_listing([
            'external_id' => self::gid($n['id'] ?? $handle),
            'handle' => $handle,
            'url' => $n['onlineStoreUrl'] ?? ($handle !== null ? $base . '/products/' . $handle : null),
            'title' => $n['title'] ?? '',
            'vendor' => $n['vendor'] ?? null,
            'product_type' => $n['productType'] ?? null,
            'tags' => $n['tags'] ?? [],
            'variants' => $variants,
            'raw' => ['images' => $images, 'options' => array_map(static fn($o) => (string) ($o['name'] ?? ''), (array) ($n['options'] ?? [])), 'updated_at' => $n['updatedAt'] ?? null, 'storefront' => true],
        ]);
    }
}

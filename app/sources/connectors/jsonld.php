<?php
declare(strict_types=1);

/**
 * `jsonld` — any site that marks its product pages up with schema.org (design §0.2). The pages come from a sitemap
 * (settings.sitemap_url, else robots.txt's Sitemap lines, else /sitemap.xml — product sitemaps preferred inside an index)
 * or from settings.urls[] a person pasted. Each page is fetched politely (robots.txt per page, by the client) and its
 * <script type="application/ld+json"> blocks read for Product / ProductGroup (also inside @graph, arrays, ItemList and
 * mainEntity) with Offer / AggregateOffer (price, priceCurrency, availability as a schema.org state), sku, gtin*, mpn,
 * brand, name, image; Open Graph product:price:amount is the fallback. One listing per product page; variants from
 * hasVariant[] or from offers[] when they carry their own sku. The slowest connector: a daily pull (the worker's concern).
 *
 * settings: sitemap_url, urls[], max_pages (default 500), url_pattern (a regex the page URLs must match), currency
 */

final class InvConnectorJsonLd implements InvConnector
{
    public const MAX_PAGES = 500;
    public const MAX_SITEMAPS = 20;

    public function capabilities(): array
    {
        return inv_capabilities([
            'has_search' => false, 'has_lookup' => true, 'gives_qty' => 'rarely (inventoryLevel)', 'gives_cost' => false,
            'needs_credential' => false, 'is_reference' => false, 'lookup_by' => ['url', 'urls'], 'credential_kinds' => [],
        ]);
    }

    public function probe(array $source): array
    {
        $base = inv_base_url($source);
        $http = inv_http_client($source);
        $robots = $http->robotsFor($base . '/');
        if ($robots['state'] === 'blocked') {
            return inv_probe_result('blocked', 'The site refuses crawlers at robots.txt.', ['robots' => 'blocked']);
        }
        $urls = inv_setting($source, 'urls', []);
        if (is_array($urls) && $urls !== []) {
            $first = (string) $urls[0];
            $r = $http->get($first);
            if ($r['blocked']) {
                return inv_probe_result('blocked', 'The first page answered with a block (' . $r['reason'] . ').', ['reason' => $r['reason']]);
            }
            if ($r['skipped']) {
                return inv_probe_result('blocked', 'robots.txt disallows the first page for our user-agent.', ['robots' => 'disallow']);
            }
            $listings = $r['ok'] ? $this->listingsFromHtml($r['body'], $first, $source) : [];
            return $listings === []
                ? inv_probe_result('misconfigured', 'The first page carries no Product markup (HTTP ' . $r['status'] . ').', ['pages' => count($urls)])
                : inv_probe_result('ok', count($urls) . ' pages listed; the first is "' . $listings[0]['title'] . '".', ['pages' => count($urls), 'robots' => $robots['state']]);
        }
        $found = $this->sitemapUrls($http, $base, $source, 5);
        if ($found['blocked'] !== null) {
            return inv_probe_result('blocked', 'The sitemap answered with a block (' . $found['blocked'] . ').', ['reason' => $found['blocked']]);
        }
        if ($found['urls'] === []) {
            return inv_probe_result('misconfigured', 'No sitemap found at ' . ($found['sitemap'] ?? $base . '/sitemap.xml') . ' and no URLs given; paste the product URLs.', ['sitemap' => $found['sitemap']]);
        }
        return inv_probe_result('ok', count($found['urls']) . '+ page URLs found in ' . $found['sitemap'] . '.', ['sitemap' => $found['sitemap'], 'sitemaps' => $found['sitemaps'], 'robots' => $robots['state']]);
    }

    public function pull(array $source, callable $emit): array
    {
        $base = inv_base_url($source);
        $http = inv_http_client($source);
        $max = max(1, (int) inv_setting($source, 'max_pages', self::MAX_PAGES));
        $errors = [];
        $urls = inv_setting($source, 'urls', []);
        $sitemap = null;
        if (!is_array($urls) || $urls === []) {
            $found = $this->sitemapUrls($http, $base, $source, $max);
            if ($found['blocked'] !== null) {
                return inv_pull_stats($http, 0, ['sitemap blocked (' . $found['blocked'] . ')'], 'blocked');
            }
            $urls = $found['urls'];
            $sitemap = $found['sitemap'];
            if ($urls === []) {
                return inv_pull_stats($http, 0, ['no sitemap and no urls'], 'failed');
            }
        }
        $urls = array_values(array_unique(array_map('strval', $urls)));
        $urls = array_slice($urls, 0, $max);
        $seen = 0;
        $skipped = 0;
        $noProduct = 0;
        $blocked = false;
        $emitted = [];
        foreach ($urls as $url) {
            $r = $http->get($url);
            if ($r['blocked']) {
                $errors[] = 'blocked at ' . $url . ' (' . $r['reason'] . ')';
                $blocked = true;
                break;
            }
            if ($r['skipped']) {
                $skipped++;
                continue;
            }
            if (!$r['ok']) {
                $errors[] = 'HTTP ' . $r['status'] . ' at ' . $url;
                continue;
            }
            $listings = $this->listingsFromHtml($r['body'], $r['url'], $source);
            if ($listings === []) {
                $noProduct++;
                continue;
            }
            foreach ($listings as $listing) {
                if (isset($emitted[$listing['external_id']])) {
                    continue;
                }
                $emitted[$listing['external_id']] = true;
                $emit($listing);
                $seen++;
            }
        }
        return inv_pull_stats($http, $seen, $errors, $blocked ? ($seen > 0 ? 'partial' : 'blocked') : null, [
            'pages' => count($urls), 'pages_skipped_by_robots' => $skipped, 'pages_without_product' => $noProduct, 'sitemap' => $sitemap,
        ]);
    }

    public function search(array $source, string $q, int $limit = 20): array
    {
        throw new InvNotSupported('a marked-up site has no search; Find answers from the last pull');
    }

    public function lookup(array $source, array $identifiers): array
    {
        $urls = [];
        if (isset($identifiers['url'])) {
            $urls[] = (string) $identifiers['url'];
        }
        foreach ((array) ($identifiers['urls'] ?? []) as $u) {
            $urls[] = (string) $u;
        }
        if ($urls === []) {
            throw new InvNotSupported('jsonld looks up by url or urls');
        }
        $http = inv_http_client($source);
        $out = [];
        foreach (array_unique($urls) as $url) {
            $r = $http->get($url);
            if ($r['ok']) {
                foreach ($this->listingsFromHtml($r['body'], $r['url'], $source) as $l) {
                    $out[] = $l;
                }
            }
        }
        return $out;
    }

    /** The page URLs from the sitemap(s): ['urls' => [...], 'sitemap' => the one read, 'sitemaps' => n, 'blocked' => ?reason]. */
    public function sitemapUrls(InvHttp $http, string $base, array $source, int $max): array
    {
        $candidates = [];
        $set = inv_setting($source, 'sitemap_url');
        if (is_string($set) && $set !== '') {
            $candidates[] = str_starts_with($set, 'http') ? $set : $base . '/' . ltrim($set, '/');
        } else {
            $robots = $http->robotsFor($base . '/');
            foreach ($robots['robots']['sitemaps'] ?? [] as $s) {
                $candidates[] = $s;
            }
            $candidates[] = $base . '/sitemap.xml';
            $candidates[] = $base . '/sitemap_index.xml';
            $candidates[] = $base . '/product-sitemap.xml';
        }
        $pattern = inv_setting($source, 'url_pattern');
        $out = ['urls' => [], 'sitemap' => null, 'sitemaps' => 0, 'blocked' => null];
        foreach (array_unique($candidates) as $sm) {
            $r = $http->get($sm, ['accept' => 'application/xml, text/xml;q=0.9, */*;q=0.5']);
            if ($r['blocked']) {
                $out['blocked'] = $r['reason'];
                return $out;
            }
            if (!$r['ok'] || !preg_match('~<(urlset|sitemapindex)~i', $r['body'])) {
                continue;
            }
            $out['sitemap'] = $sm;
            $parsed = inv_sitemap_parse($r['body']);
            $out['sitemaps'] = 1;
            $children = $parsed['sitemaps'];
            if ($children !== []) {
                // DECISION: inside an index, the sitemaps named "product" are read; when none is, every child is (capped)
                $productish = array_values(array_filter($children, static fn($u) => preg_match('~product~i', $u)));
                $children = $productish !== [] ? $productish : $children;
                foreach (array_slice($children, 0, self::MAX_SITEMAPS) as $child) {
                    $cr = $http->get($child, ['accept' => 'application/xml, text/xml;q=0.9, */*;q=0.5']);
                    if ($cr['blocked']) {
                        $out['blocked'] = $cr['reason'];
                        return $out;
                    }
                    if ($cr['ok']) {
                        $out['sitemaps']++;
                        foreach (inv_sitemap_parse($cr['body'])['urls'] as $u) {
                            $out['urls'][] = $u;
                        }
                    }
                    if (count($out['urls']) >= $max) {
                        break;
                    }
                }
            } else {
                $out['urls'] = $parsed['urls'];
            }
            break;
        }
        if (is_string($pattern) && $pattern !== '') {
            $re = '~' . str_replace('~', '\~', $pattern) . '~i';
            $out['urls'] = array_values(array_filter($out['urls'], static fn($u) => @preg_match($re, $u) === 1));
        } elseif ($out['sitemaps'] <= 1 && !(is_string($set) && $set !== '')) {
            // a DISCOVERED flat sitemap of the whole site: keep the product-looking URLs when there are any (a sitemap a
            // person named is read whole)
            $productish = array_values(array_filter($out['urls'], static fn($u) => preg_match('~/(product|products|p|item|shop)/~i', $u)));
            if ($productish !== []) {
                $out['urls'] = $productish;
            }
        }
        $out['urls'] = array_slice(array_values(array_unique($out['urls'])), 0, $max);
        return $out;
    }

    /**
 * Every listing a page's markup yields. DECISION: normally one per page (§0.2); a category page whose ItemList
 * carries whole Products (name + offers) yields each of them rather than being thrown away.
 */
    public function listingsFromHtml(string $html, string $url, array $source): array
    {
        $currency = inv_setting($source, 'currency');
        $products = inv_jsonld_products($html);
        $out = [];
        foreach ($products as $p) {
            $l = inv_jsonld_listing($p, $url, $currency);
            if ($l !== null) {
                $out[] = $l;
            }
        }
        if ($out === []) {
            $og = inv_opengraph_listing($html, $url, $currency);
            if ($og !== null) {
                $out[] = $og;
            }
        }
        return $out;
    }
}

/** <urlset> → urls, <sitemapindex> → sitemaps (regex: sitemaps are large and plain; no XML parser is needed or trusted). */
function inv_sitemap_parse(string $xml): array
{
    $out = ['urls' => [], 'sitemaps' => []];
    if (preg_match('~<sitemapindex~i', $xml)) {
        preg_match_all('~<sitemap>.*?<loc>\s*(.*?)\s*</loc>.*?</sitemap>~is', $xml, $m);
        $out['sitemaps'] = array_map(static fn($u) => html_entity_decode(trim($u)), $m[1]);
        return $out;
    }
    preg_match_all('~<url>.*?<loc>\s*(.*?)\s*</loc>.*?</url>~is', $xml, $m);
    $out['urls'] = array_map(static fn($u) => html_entity_decode(trim($u)), $m[1]);
    return $out;
}

/** True when a node's @type names a product (Product, ProductGroup, ProductModel, IndividualProduct, SomeProducts, Vehicle…). */
function inv_jsonld_is_product(array $node): bool
{
    $types = $node['@type'] ?? null;
    foreach (is_array($types) ? $types : [$types] as $t) {
        if (is_string($t) && preg_match('~^(?:https?://schema\.org/)?(Product|ProductGroup|ProductModel|IndividualProduct|SomeProducts|Vehicle|ProductCollection)$~i', $t)) {
            return true;
        }
    }
    return false;
}

/** The product nodes of a page: every ld+json block decoded and walked (not into hasVariant/offers/isVariantOf). */
function inv_jsonld_products(string $html): array
{
    $products = [];
    if (!preg_match_all('~<script[^>]*type\s*=\s*["\']application/ld\+json["\'][^>]*>(.*?)</script>~is', $html, $m)) {
        return [];
    }
    foreach ($m[1] as $block) {
        $json = trim($block);
        $json = preg_replace('~^\s*<!--|-->\s*$~', '', $json) ?? $json;
        $json = preg_replace('~^\s*//<!\[CDATA\[|//\]\]>\s*$~', '', $json) ?? $json;
        $data = json_decode($json, true);
        if (!is_array($data)) {
            // a stray control character or a trailing comma: one lenient retry
            $data = json_decode(preg_replace('~,\s*([\]}])~', '$1', preg_replace('~[\x00-\x08\x0B\x0C\x0E-\x1F]~', '', $json) ?? $json) ?? $json, true);
            if (!is_array($data)) {
                continue;
            }
        }
        inv_jsonld_walk($data, $products, 0);
    }
    // the variants of a group also sit in the page as hasVariant children — never twice: drop a product that is a
    // variant of a group we hold
    $groupVariantIds = [];
    foreach ($products as $p) {
        foreach ((array) ($p['hasVariant'] ?? []) as $v) {
            if (is_array($v)) {
                $groupVariantIds[] = $v['@id'] ?? ($v['sku'] ?? ($v['url'] ?? null));
            }
        }
    }
    return array_values(array_filter($products, static function ($p) use ($groupVariantIds) {
        $id = $p['@id'] ?? ($p['sku'] ?? ($p['url'] ?? null));
        return $id === null || !in_array($id, $groupVariantIds, true);
    }));
}

function inv_jsonld_walk(mixed $node, array &$products, int $depth): void
{
    if ($depth > 8 || !is_array($node)) {
        return;
    }
    if (array_is_list($node)) {
        foreach ($node as $child) {
            inv_jsonld_walk($child, $products, $depth + 1);
        }
        return;
    }
    if (inv_jsonld_is_product($node)) {
        $products[] = $node;
        return;
    }
    foreach (['@graph', 'mainEntity', 'itemListElement', 'item', 'about', 'mainEntityOfPage'] as $k) {
        if (isset($node[$k])) {
            inv_jsonld_walk($node[$k], $products, $depth + 1);
        }
    }
}

/** A schema.org value that may be a string, an object with name/@value, or a list of those → the first string. */
function inv_jsonld_text(mixed $v): ?string
{
    if (is_array($v)) {
        if (array_is_list($v)) {
            foreach ($v as $x) {
                $t = inv_jsonld_text($x);
                if ($t !== null) {
                    return $t;
                }
            }
            return null;
        }
        foreach (['name', '@value', 'value', 'url', '@id', 'contentUrl'] as $k) {
            if (isset($v[$k]) && !is_array($v[$k])) {
                return inv_str($v[$k], 1000);
            }
        }
        return null;
    }
    return inv_str($v, 1000);
}

/** The offers of a product or variant node, flattened: each ['price','currency','availability','sku','gtin','mpn','url','qty','condition','name']. */
function inv_jsonld_offers(mixed $offers): array
{
    if ($offers === null) {
        return [];
    }
    $list = is_array($offers) && array_is_list($offers) ? $offers : [$offers];
    $out = [];
    foreach ($list as $o) {
        if (!is_array($o)) {
            continue;
        }
        $type = inv_jsonld_text($o['@type'] ?? null) ?? '';
        if (preg_match('~AggregateOffer~i', $type) && isset($o['offers'])) {
            foreach (inv_jsonld_offers($o['offers']) as $inner) {
                $out[] = $inner;
            }
            if ($out !== []) {
                continue;
            }
        }
        $price = $o['price'] ?? ($o['lowPrice'] ?? null);
        $currency = $o['priceCurrency'] ?? null;
        $spec = $o['priceSpecification'] ?? null;
        if ($price === null && is_array($spec)) {
            $specs = array_is_list($spec) ? $spec : [$spec];
            foreach ($specs as $s) {
                if (is_array($s) && isset($s['price'])) {
                    $price = $s['price'];
                    $currency ??= $s['priceCurrency'] ?? null;
                    break;
                }
            }
        }
        $qty = null;
        if (isset($o['inventoryLevel'])) {
            $lvl = $o['inventoryLevel'];
            $qty = is_array($lvl) ? ($lvl['value'] ?? ($lvl['minValue'] ?? null)) : $lvl;
        }
        $out[] = [
            'price' => is_array($price) ? inv_jsonld_text($price) : $price,
            'high_price' => $o['highPrice'] ?? null,
            'currency' => inv_jsonld_text($currency),
            'availability' => inv_jsonld_text($o['availability'] ?? null),
            'sku' => inv_jsonld_text($o['sku'] ?? null),
            'gtin' => inv_jsonld_gtin($o),
            'mpn' => inv_jsonld_text($o['mpn'] ?? null),
            'url' => inv_jsonld_text($o['url'] ?? null),
            'qty' => is_numeric($qty) ? (int) $qty : null,
            'condition' => inv_jsonld_text($o['itemCondition'] ?? null),
            'name' => inv_jsonld_text($o['name'] ?? null),
            'is_aggregate' => (bool) preg_match('~AggregateOffer~i', $type),
        ];
    }
    return $out;
}

/** The first GTIN-like property of a node (gtin, gtin14, gtin13, gtin12, gtin8, isbn is not one). */
function inv_jsonld_gtin(array $node): ?string
{
    foreach (['gtin', 'gtin14', 'gtin13', 'gtin12', 'gtin8', 'upc', 'ean'] as $k) {
        $t = inv_jsonld_text($node[$k] ?? null);
        if ($t !== null) {
            return $t;
        }
    }
    $pid = inv_jsonld_text($node['productID'] ?? null);
    if ($pid !== null && preg_match('~^(?:gtin\w*|upc|ean):\s*(\d+)$~i', $pid, $m)) {
        return $m[1];
    }
    return null;
}

/** A node's size: the size property, a SizeSpecification, an additionalProperty named Size, or the name. */
function inv_jsonld_size(array $node): ?string
{
    $size = inv_jsonld_text($node['size'] ?? null);
    if ($size !== null) {
        return $size;
    }
    foreach ((array) ($node['additionalProperty'] ?? []) as $prop) {
        if (is_array($prop) && preg_match('~^size$~i', (string) inv_jsonld_text($prop['name'] ?? null)) && ($v = inv_jsonld_text($prop['value'] ?? null)) !== null) {
            return $v;
        }
    }
    return null;
}

/** A Product node → the normalized listing, or null when it has no name. */
function inv_jsonld_listing(array $p, string $pageUrl, ?string $defaultCurrency = null): ?array
{
    $name = inv_jsonld_text($p['name'] ?? null);
    if ($name === null) {
        return null;
    }
    $brand = $p['brand'] ?? ($p['manufacturer'] ?? null);
    $brand = is_array($brand) && !array_is_list($brand) ? inv_jsonld_text($brand['name'] ?? null) : inv_jsonld_text($brand);
    $url = inv_jsonld_text($p['url'] ?? null) ?? $pageUrl;
    if (!preg_match('~^https?://~i', $url)) {
        $url = inv_http_resolve($pageUrl, $url);
    }
    $images = [];
    $imgs = $p['image'] ?? [];
    foreach (is_array($imgs) && array_is_list($imgs) ? $imgs : [$imgs] as $img) {
        $t = inv_jsonld_text($img);
        if ($t !== null) {
            $images[] = $t;
        }
        if (count($images) >= 5) {
            break;
        }
    }
    $category = inv_jsonld_text($p['category'] ?? null);
    if ($category !== null && str_contains($category, '>')) {
        $parts = array_map('trim', explode('>', $category));
        $category = end($parts) ?: $category;
    }
    $sku = inv_jsonld_text($p['sku'] ?? null);
    $gtin = inv_jsonld_gtin($p);
    $mpn = inv_jsonld_text($p['mpn'] ?? null);
    $pid = inv_jsonld_text($p['productID'] ?? null);
    $offers = inv_jsonld_offers($p['offers'] ?? null);
    $pageCurrency = $defaultCurrency;
    foreach ($offers as $o) {
        if ($o['currency'] !== null) {
            $pageCurrency = $o['currency'];
            break;
        }
    }
    $variants = [];
    $hasVariant = $p['hasVariant'] ?? ($p['model'] ?? null);
    $hasVariant = is_array($hasVariant) ? (array_is_list($hasVariant) ? $hasVariant : [$hasVariant]) : [];
    if ($hasVariant !== []) {
        foreach ($hasVariant as $i => $v) {
            if (!is_array($v)) {
                continue;
            }
            $vo = inv_jsonld_offers($v['offers'] ?? null);
            $first = $vo[0] ?? null;
            $size = inv_jsonld_size($v);
            $vsku = inv_jsonld_text($v['sku'] ?? null) ?? ($first['sku'] ?? null);
            $variants[] = [
                'external_variant_id' => inv_jsonld_text($v['@id'] ?? null) ?? $vsku ?? inv_jsonld_gtin($v) ?? ((string) ($i + 1)),
                'title' => inv_jsonld_text($v['name'] ?? null) ?? $name,
                'option_values' => array_filter(['Size' => $size, 'Color' => inv_jsonld_text($v['color'] ?? null)]),
                'sku' => $vsku,
                'barcode' => inv_jsonld_gtin($v) ?? ($first['gtin'] ?? null),
                'mpn' => inv_jsonld_text($v['mpn'] ?? null) ?? ($first['mpn'] ?? null),
                'price' => $first['price'] ?? null,
                'compare_at_price' => null,
                'currency' => $first['currency'] ?? $pageCurrency,
                'availability' => $first['availability'] ?? null,
                'qty' => $first['qty'] ?? null,
                'url' => inv_jsonld_text($v['url'] ?? null) ?? ($first['url'] ?? null) ?? $url,
            ];
        }
    } elseif (count($offers) > 1 && count(array_unique(array_filter(array_column($offers, 'sku')))) > 1) {
        foreach ($offers as $i => $o) {
            $variants[] = [
                'external_variant_id' => $o['sku'] ?? ($o['gtin'] ?? ((string) ($i + 1))),
                'title' => $o['name'] ?? $name,
                'option_values' => [],
                'sku' => $o['sku'] ?? $sku,
                'barcode' => $o['gtin'] ?? $gtin,
                'mpn' => $o['mpn'] ?? $mpn,
                'price' => $o['price'],
                'currency' => $o['currency'] ?? $pageCurrency,
                'availability' => $o['availability'],
                'qty' => $o['qty'],
                'url' => $o['url'] ?? $url,
            ];
        }
    } else {
        $first = $offers[0] ?? null;
        // DECISION: an offer whose condition is not new (Refurbished/Used) is kept in raw, not as the price of the listing,
        // unless it is the only one
        foreach ($offers as $o) {
            if ($o['condition'] === null || preg_match('~NewCondition~i', $o['condition'])) {
                $first = $o;
                break;
            }
        }
        $variants[] = [
            'external_variant_id' => ($sku ?? $gtin ?? $pid ?? 'page') . ':1',
            'title' => $name,
            'option_values' => array_filter(['Size' => inv_jsonld_size($p), 'Color' => inv_jsonld_text($p['color'] ?? null)]),
            'sku' => $sku ?? ($first['sku'] ?? null),
            'barcode' => $gtin ?? ($first['gtin'] ?? null),
            'mpn' => $mpn ?? ($first['mpn'] ?? null),
            'price' => $first['price'] ?? null,
            'compare_at_price' => $first['high_price'] ?? null,
            'currency' => $first['currency'] ?? $pageCurrency,
            'availability' => $first['availability'] ?? null,
            'qty' => $first['qty'] ?? null,
            'url' => $first['url'] ?? $url,
        ];
    }
    $path = parse_url($url, PHP_URL_PATH) ?: '/';
    $segments = array_values(array_filter(explode('/', (string) $path)));
    $handle = $segments !== [] ? preg_replace('~\.(html?|php|aspx?)$~i', '', (string) end($segments)) : null;
    return inv_normalize_listing([
        'external_id' => $pid ?? inv_jsonld_text($p['productGroupID'] ?? null) ?? $sku ?? $gtin ?? inv_jsonld_text($p['@id'] ?? null) ?? substr(sha1($url), 0, 16),
        'handle' => $handle,
        'url' => $url,
        'title' => $name,
        'vendor' => $brand,
        'product_type' => $category,
        'tags' => $p['keywords'] ?? [],
        'currency' => $pageCurrency,
        'variants' => $variants,
        'raw' => ['images' => $images, 'offers' => count($offers), 'conditions' => array_values(array_unique(array_filter(array_column($offers, 'condition')))), 'jsonld' => true],
    ]);
}

/** Open Graph / product: meta tags → a listing, when the page says og:type product or carries product:price:amount. */
function inv_opengraph_listing(string $html, string $pageUrl, ?string $defaultCurrency = null): ?array
{
    $meta = [];
    if (preg_match_all('~<meta\s+[^>]*?(?:property|name)\s*=\s*["\']([^"\']+)["\'][^>]*?content\s*=\s*["\']([^"\']*)["\']~is', $html, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) {
            $meta[strtolower($x[1])] ??= html_entity_decode($x[2], ENT_QUOTES | ENT_HTML5);
        }
    }
    if (preg_match_all('~<meta\s+[^>]*?content\s*=\s*["\']([^"\']*)["\'][^>]*?(?:property|name)\s*=\s*["\']([^"\']+)["\']~is', $html, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) {
            $meta[strtolower($x[2])] ??= html_entity_decode($x[1], ENT_QUOTES | ENT_HTML5);
        }
    }
    $price = $meta['product:price:amount'] ?? ($meta['og:price:amount'] ?? null);
    $isProduct = preg_match('~product~i', $meta['og:type'] ?? '') === 1;
    if ($price === null && !$isProduct) {
        return null;
    }
    $title = $meta['og:title'] ?? null;
    if ($title === null && preg_match('~<title>(.*?)</title>~is', $html, $t)) {
        $title = trim(html_entity_decode($t[1], ENT_QUOTES | ENT_HTML5));
    }
    if ($title === null || $title === '') {
        return null;
    }
    $url = $meta['og:url'] ?? $pageUrl;
    $path = parse_url($url, PHP_URL_PATH) ?: '/';
    $segments = array_values(array_filter(explode('/', (string) $path)));
    return inv_normalize_listing([
        'external_id' => $meta['product:retailer_item_id'] ?? ($meta['product:sku'] ?? substr(sha1($url), 0, 16)),
        'handle' => $segments !== [] ? (string) end($segments) : null,
        'url' => $url,
        'title' => $title,
        'vendor' => $meta['product:brand'] ?? ($meta['og:brand'] ?? null),
        'product_type' => $meta['product:category'] ?? null,
        'sku' => $meta['product:retailer_item_id'] ?? ($meta['product:sku'] ?? null),
        'barcode' => $meta['product:gtin'] ?? ($meta['product:ean'] ?? ($meta['product:upc'] ?? null)),
        'mpn' => $meta['product:mfr_part_no'] ?? null,
        'price' => $price,
        'compare_at_price' => $meta['product:original_price:amount'] ?? null,
        'currency' => $meta['product:price:currency'] ?? ($meta['og:price:currency'] ?? $defaultCurrency),
        'availability' => $meta['product:availability'] ?? ($meta['og:availability'] ?? null),
        'raw' => ['images' => isset($meta['og:image']) ? [$meta['og:image']] : [], 'opengraph' => true],
    ]);
}

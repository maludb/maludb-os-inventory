<?php
declare(strict_types=1);

/**
 * The fixture server's router for tests/phase0/connectors.sh: `php -S 127.0.0.1:8606 -t tests/fixtures/sources router.php`.
 * It mimics the paths of the platforms (Shopify products.json / .js / suggest / Storefront GraphQL; the WooCommerce
 * Store API; a sitemap index; JSON-LD pages; a CSV and an XLSX feed; a bot wall; a login redirect) and logs every
 * request as a JSON line to INV_FIX_LOG so the proof can assert the User-Agent, the conditional headers and what was
 * never asked for. Nothing here is a third party's catalog: the data is invented, mattress-like.
 */

$fix = __DIR__;
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH) ?: '/';
$q = [];
parse_str((string) (parse_url($uri, PHP_URL_QUERY) ?? ''), $q);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$h = static fn(string $name) => $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? null;
$body = file_get_contents('php://input') ?: '';

// slice 3: a second version of a fixture (the Queen's price and availability changed) — INV_FIX_VARIANT, or the word in $INV_FIX_TMP/variant
$fixTmp = getenv('INV_FIX_TMP') ?: ($_SERVER['INV_FIX_TMP'] ?? '');
$variant = getenv('INV_FIX_VARIANT') ?: ($fixTmp !== '' && is_file("$fixTmp/variant") ? trim((string) file_get_contents("$fixTmp/variant")) : '');

$log = getenv('INV_FIX_LOG') ?: ($_SERVER['INV_FIX_LOG'] ?? null);
if ($log) {
    file_put_contents($log, json_encode(['method' => $method, 'path' => $path, 'query' => $q, 'ua' => $h('User-Agent'), 'if_none_match' => $h('If-None-Match'), 'if_modified_since' => $h('If-Modified-Since'), 'auth' => $h('Authorization') !== null, 'storefront_token' => $h('X-Shopify-Storefront-Access-Token'), 't' => microtime(true)]) . "\n", FILE_APPEND | LOCK_EX);
}

$send = static function (int $status, string $body, string $type = 'application/json', array $extra = []): void {
    http_response_code($status);
    header('Content-Type: ' . $type);
    foreach ($extra as $k => $v) {
        header("$k: $v");
    }
    echo $body;
    exit;
};
$file = static fn(string $rel) => (string) file_get_contents($fix . '/' . $rel);
$etagged = static function (string $rel, string $etag, string $type = 'application/json', array $extra = []) use ($send, $file, $h): void {
    if ($h('If-None-Match') === $etag) {
        $send(304, '', $type, ['ETag' => $etag]);
    }
    $send(200, $file($rel), $type, ['ETag' => $etag, 'Last-Modified' => 'Wed, 01 Oct 2026 12:30:00 GMT'] + $extra);
};

// ---- the host itself
if ($path === '/robots.txt') {
    $send(200, $file('robots.txt'), 'text/plain');
}
if ($path === '/') {
    $send(200, '<!doctype html><html><head><title>Fixture store</title></head><body>home</body></html>', 'text/html');
}

// ---- Shopify
if ($path === '/products.json') {
    $page = (int) ($q['page'] ?? 1);
    if ($page <= 1) {
        $variant === 'v2' ? $etagged('shopify/products-page1.v2.json', '"p1-v2"') : $etagged('shopify/products-page1.json', '"p1-v1"');
    }
    $send(200, $file('shopify/products-page2.json'));
}
if (preg_match('~^/collections/([a-z0-9\-]+)/products\.json$~', $path, $m)) {
    $page = (int) ($q['page'] ?? 1);
    if ($m[1] === 'mattresses') {
        $send(200, $page <= 1 ? $file('shopify/collection-mattresses.json') : '{"products":[]}');
    }
    $send(404, '{"errors":"Not Found"}');
}
if (preg_match('~^/products/([a-z0-9\-]+)\.js$~', $path, $m)) {
    if (is_file("$fix/shopify/product-{$m[1]}.js")) {
        $send(200, $file("shopify/product-{$m[1]}.js"));
    }
    $send(404, '{"errors":"Not Found"}');
}
if ($path === '/search/suggest.json') {
    if (($q['q'] ?? '') === '') {
        $send(422, '{"errors":"q is required"}');
    }
    $data = json_decode($file('shopify/suggest.json'), true);
    $needle = strtolower((string) $q['q']);
    $data['resources']['results']['products'] = array_values(array_filter($data['resources']['results']['products'], static fn($p) => str_contains(strtolower($p['title']), $needle)));
    $send(200, json_encode($data));
}
if (preg_match('~^/api/(\d{4}-\d{2})/graphql\.json$~', $path)) {
    if ($method !== 'POST') {
        $send(405, '{"errors":[{"message":"POST only"}]}');
    }
    if ($h('X-Shopify-Storefront-Access-Token') !== 'shpat_fixture_token') {
        $send(401, '{"errors":[{"message":"Invalid Storefront access token"}]}');
    }
    $req = json_decode($body, true) ?: [];
    if (str_contains((string) ($req['query'] ?? ''), 'shop {')) {
        $send(200, '{"data":{"shop":{"name":"Fixture Sleep Co"}}}');
    }
    $send(200, $file('shopify/storefront.json'));
}

// ---- WooCommerce Store API
if ($path === '/wp-json/wc/store/v1/products') {
    $all = json_decode($file('woocommerce/products-page1.json'), true);
    if (isset($q['search'])) {
        $needle = strtolower((string) $q['search']);
        $all = array_values(array_filter($all, static fn($p) => str_contains(strtolower(html_entity_decode($p['name'])), $needle)));
    }
    if (isset($q['sku'])) {
        $all = array_values(array_filter($all, static fn($p) => $p['sku'] === $q['sku']));
    }
    $page = (int) ($q['page'] ?? 1);
    if ($page > 1) {
        $send(400, '{"code":"rest_product_invalid_page_number","message":"The page number requested is larger than the number of pages available.","data":{"status":400}}', 'application/json', ['X-WP-Total' => (string) count($all), 'X-WP-TotalPages' => '1']);
    }
    $send(200, json_encode($all), 'application/json', ['X-WP-Total' => (string) count($all), 'X-WP-TotalPages' => '1', 'ETag' => '"woo-v1"']);
}
if (preg_match('~^/wp-json/wc/store/v1/products/(\d+)$~', $path, $m)) {
    if (is_file("$fix/woocommerce/product-{$m[1]}.json")) {
        $send(200, $file("woocommerce/product-{$m[1]}.json"));
    }
    $all = json_decode($file('woocommerce/products-page1.json'), true);
    foreach ($all as $p) {
        if ((string) $p['id'] === $m[1]) {
            $send(200, json_encode($p));
        }
    }
    $send(404, '{"code":"woocommerce_rest_product_invalid_id","message":"Invalid product ID.","data":{"status":404}}');
}

// ---- sitemaps and JSON-LD pages
if ($path === '/sitemap.xml') {
    $send(200, $file('jsonld/sitemap.xml'), 'application/xml');
}
if (preg_match('~^/(sitemap_products_1|sitemap_pages_1)\.xml$~', $path, $m)) {
    $send(200, $file("jsonld/{$m[1]}.xml"), 'application/xml');
}
if (preg_match('~^/products/([a-z0-9\-]+)\.html$~', $path, $m)) {
    $map = ['cloudrest-hybrid' => 'page-cloudrest-hybrid', 'harbor-latex-topper' => 'page-topper', 'northwind-platform-frame-queen' => 'page-opengraph', 'about' => 'page-no-product'];
    if (isset($map[$m[1]])) {
        $etagged('jsonld/' . $map[$m[1]] . '.html', '"page-' . $m[1] . '"', 'text/html; charset=utf-8');
    }
    $send(404, '<html><title>Not found</title></html>', 'text/html');
}
if (str_starts_with($path, '/private/')) {
    $send(200, $file('private/dealer-pricing.html'), 'text/html');
}

// ---- feeds
if ($path === '/feed.csv') {
    $etagged('feed/supplier.csv', '"feed-v1"', 'text/csv');
}
if ($path === '/feed-protected.csv') {
    if ($h('Authorization') !== 'Basic ' . base64_encode('dealer:s3cret-feed')) {
        $send(401, 'auth required', 'text/plain', ['WWW-Authenticate' => 'Basic realm="dealer"']);
    }
    $send(200, $file('feed/supplier.csv'), 'text/csv');
}
if ($path === '/feed.xlsx') {
    $tmp = getenv('INV_FIX_TMP') ?: ($_SERVER['INV_FIX_TMP'] ?? '');
    if ($tmp !== '' && is_file("$tmp/feed.xlsx")) {
        $send(200, (string) file_get_contents("$tmp/feed.xlsx"), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
    $send(404, 'no generated xlsx', 'text/plain');
}

// ---- walls, blocks and redirects
if ($path === '/wall') {
    $send(200, $file('walls/cloudflare.html'), 'text/html; charset=UTF-8', ['Server' => 'cloudflare']);
}
if (str_starts_with($path, '/wall/')) {                       // slice 3: a walled store — everything under /wall/ is the bot wall
    $send(200, $file('walls/cloudflare.html'), 'text/html; charset=UTF-8', ['Server' => 'cloudflare']);
}
if ($path === '/wall-503') {
    $send(503, $file('walls/cloudflare.html'), 'text/html; charset=UTF-8', ['Server' => 'cloudflare']);
}
if ($path === '/denied') {
    $send(200, $file('walls/akamai.html'), 'text/html');
}
if ($path === '/forbidden') {
    $send(403, '{"errors":"forbidden"}');
}
if ($path === '/ratelimited') {
    $send(429, 'slow down', 'text/plain', ['Retry-After' => '60']);
}
if ($path === '/login-redirect') {
    $send(302, '', 'text/html', ['Location' => '/account/login?return_url=%2Fproducts.json']);
}
if ($path === '/login-redirect-abs') {
    $send(302, '', 'text/html', ['Location' => 'http://127.0.0.1:8606/customer/login']);
}
if ($path === '/account/login' || $path === '/customer/login') {
    $send(200, $file('walls/login.html'), 'text/html');
}
if ($path === '/redirect-ok') {
    $send(302, '', 'text/html', ['Location' => '/products.json?page=1']);
}
if ($path === '/redirect-loop') {
    $send(302, '', 'text/html', ['Location' => '/redirect-loop']);
}
if ($path === '/big') {
    $send(200, str_repeat('x', 300000), 'text/plain');
}
if ($path === '/slow-json') {
    $send(200, '{"products":' . json_encode(array_fill(0, 3, ['id' => 1])) . '}');
}
$send(404, '{"errors":"Not Found"}');

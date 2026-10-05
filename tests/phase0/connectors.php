<?php
declare(strict_types=1);

/**
 * The connectors proof, driven by connectors.sh (which serves tests/fixtures/sources on 127.0.0.1:8606 with a request
 * log at INV_FIX_LOG). Only app/sources/ is exercised: no database, no kernel. Every check prints ok/FAIL; exit 1 on
 * any FAIL.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
    if (!(error_reporting() & $no)) {
        return false;
    }
    throw new ErrorException($msg, 0, $no, $file, $line);
});

require_once __DIR__ . '/../../app/sources/registry.php';

$PORT = getenv('INV_FIX_PORT') ?: '8606';
$BASE = "http://127.0.0.1:$PORT";
$LOG = getenv('INV_FIX_LOG') ?: sys_get_temp_dir() . '/inv-connectors-requests.log';
$TMP = getenv('INV_FIX_TMP') ?: sys_get_temp_dir();
$UA = 'FixtureRetail-InventoryBot/1.0 (+https://fixture-retail.example; buyer@fixture-retail.example)';

$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    if ($ok) {
        $GLOBALS['pass']++;
        echo "ok    $name\n";
    } else {
        $GLOBALS['fail']++;
        echo "FAIL  $name" . ($detail === '' ? '' : "  — $detail") . "\n";
    }
}
function same(string $name, mixed $expect, mixed $got): void
{
    check($name, $expect === $got, 'expected ' . json_encode($expect) . ', got ' . json_encode($got));
}
function group(string $g): void
{
    echo "\n== $g\n";
}
function throws(string $name, string $class, callable $fn): void
{
    try {
        $fn();
        check($name, false, "no $class thrown");
    } catch (Throwable $e) {
        check($name, $e instanceof $class, get_class($e) . ': ' . $e->getMessage());
    }
}
/** The request log since the last reset. */
function reqlog(): array
{
    $path = $GLOBALS['LOG'];
    if (!is_file($path)) {
        return [];
    }
    $out = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $out[] = json_decode($line, true);
    }
    return $out;
}
function reqlog_reset(): void
{
    @file_put_contents($GLOBALS['LOG'], '');
}
function paths(): array
{
    return array_map(static fn($r) => $r['path'], reqlog());
}
function fresh_cache(string $tag): string
{
    $dir = $GLOBALS['TMP'] . '/cache-' . $tag . '-' . bin2hex(random_bytes(3));
    mkdir($dir, 0700, true);
    return $dir;
}
function src(string $connector, array $over = []): array
{
    return array_merge([
        'connector' => $connector, 'base_url' => $GLOBALS['BASE'], 'settings' => [], 'credential' => null,
        'rate_per_second' => 50, 'user_agent' => $GLOBALS['UA'], 'cache_dir' => fresh_cache($connector),
    ], $over);
}
function pull_all(InvConnector $c, array $source): array
{
    $listings = [];
    $stats = $c->pull($source, static function (array $l) use (&$listings): void {
        $listings[] = $l;
    });
    return [$listings, $stats];
}
function variant(array $listing, string $sku): ?array
{
    foreach ($listing['variants'] as $v) {
        if ($v['sku'] === $sku) {
            return $v;
        }
    }
    return null;
}
function by_id(array $listings, string $id): ?array
{
    foreach ($listings as $l) {
        if ($l['external_id'] === $id) {
            return $l;
        }
    }
    return null;
}

// =====================================================================================================================
group('normalize — availability (every state word, §0.3)');
foreach ([
    'https://schema.org/InStock' => 'in_stock', 'http://schema.org/InStock' => 'in_stock', 'InStock' => 'in_stock', 'in stock' => 'in_stock',
    'instock' => 'in_stock', 'available' => 'in_stock', 'Yes' => 'in_stock', 'Y' => 'in_stock', 'TRUE' => 'in_stock', '1' => 'in_stock',
    'https://schema.org/InStoreOnly' => 'in_stock', 'https://schema.org/OnlineOnly' => 'in_stock',
    'https://schema.org/OutOfStock' => 'out_of_stock', 'outofstock' => 'out_of_stock', 'out of stock' => 'out_of_stock', 'Sold Out' => 'out_of_stock',
    'https://schema.org/SoldOut' => 'out_of_stock', 'no' => 'out_of_stock', 'N' => 'out_of_stock', 'false' => 'out_of_stock', '0' => 'out_of_stock',
    'unavailable' => 'out_of_stock', 'Sold out — more on the way' => 'out_of_stock',
    'https://schema.org/PreOrder' => 'pre_order', 'preorder' => 'pre_order', 'Pre-Order' => 'pre_order', 'https://schema.org/PreSale' => 'pre_order',
    'https://schema.org/BackOrder' => 'back_order', 'onbackorder' => 'back_order', 'Available on backorder' => 'back_order', 'back-ordered' => 'back_order',
    'https://schema.org/LimitedAvailability' => 'limited', 'Low stock' => 'limited', 'only a few left' => 'limited',
    'https://schema.org/Discontinued' => 'discontinued', 'discontinued' => 'discontinued', 'No longer available' => 'discontinued',
    '' => 'unknown', 'something else entirely' => 'unknown', 'in_stock' => 'in_stock', 'back_order' => 'back_order',
] as $word => $state) {
    same("availability '$word' → $state", $state, inv_availability_state($word));
}
same('availability null → unknown', 'unknown', inv_availability_state(null));
same('availability bool true → in_stock', 'in_stock', inv_availability_state(true));
same('availability bool false → out_of_stock', 'out_of_stock', inv_availability_state(false));
same('availability available=true, qty 0 → back_order (continue selling)', 'back_order', inv_availability_state(null, true, 0));
same('availability available=true, qty 5 → in_stock', 'in_stock', inv_availability_state(null, true, 5));
same('availability qty 0 alone → out_of_stock', 'out_of_stock', inv_availability_state(null, null, 0));
same('availability qty 3 alone → in_stock', 'in_stock', inv_availability_state(null, null, 3));
same('availability word beats qty', 'discontinued', inv_availability_state('Discontinued', true, 9));

group('normalize — GTIN (digits only, check digit, GTIN-14)');
same('UPC-A 850123450004 → 14 digits', '00850123450004', inv_gtin_normalize('850123450004'));
same('EAN-13 4006381333931 → 14 digits', '04006381333931', inv_gtin_normalize('4006381333931'));
same('hyphenated 8-50123-45005-9 → digits', '00850123450059', inv_gtin_normalize('8-50123-45005-9'));
same('spaces and letters stripped', '00850123450004', inv_gtin_normalize('UPC 850 123 450 004'));
same('a wrong check digit (850123450001) → null', null, inv_gtin_normalize('850123450001'));
same('letters only → null', null, inv_gtin_normalize('NW-CR-T'));
same('11 digits (a spreadsheet lost the leading zero) → padded and checked', '00012345678905', inv_gtin_normalize('12345678905'));
same('GTIN-8 96385074 → 14 digits', '00000096385074', inv_gtin_normalize('96385074'));
same('GTIN-14 with its zeros kept', '00850123450004', inv_gtin_normalize('00850123450004'));
same('empty → null', null, inv_gtin_normalize(''));
same('all zeros → null', null, inv_gtin_normalize('000000000000'));
same('an integer value is accepted', '00850123450004', inv_gtin_normalize(850123450004));
check('inv_gtin_valid rejects 7 digits', !inv_gtin_valid('1234567'));
same('check digit of 85012345000 is 4', 4, inv_gtin_check_digit('85012345000'));

group('normalize — money, currency, ships_how');
same('"1,299.00" → 1299.00', '1299.00', inv_money('1,299.00'));
same('"$349.50" → 349.50', '349.50', inv_money('$349.50'));
same('int 999 → 999.00', '999.00', inv_money(999));
same('float 59.9 → 59.90', '59.90', inv_money(59.9));
same('"1299" → 1299.00', '1299.00', inv_money('1299'));
same('"1.299,00" (comma decimal) → 1299.00', '1299.00', inv_money('1.299,00'));
same('"abc" → null', null, inv_money('abc'));
same('"" → null', null, inv_money(''));
same('negative → null', null, inv_money(-5));
same('"USD 1,299.5" → 1299.50', '1299.50', inv_money('USD 1,299.5'));
same('minor 129900 / 2 → 1299.00', '1299.00', inv_money_minor('129900', 2));
same('minor 5 / 2 → 0.05', '0.05', inv_money_minor('5', 2));
same('minor 1299 / 0 → 1299.00', '1299.00', inv_money_minor('1299', 0));
same('currency usd → USD', 'USD', inv_currency('usd'));
same('currency US$ → null', null, inv_currency('US$'));
same('currency null → null', null, inv_currency(null));
same('ships_how LTL → ltl', 'ltl', inv_ships_how('LTL'));
same('ships_how "White Glove" → white_glove', 'white_glove', inv_ships_how('White Glove'));
same('ships_how pickup → pickup_only', 'pickup_only', inv_ships_how('pickup'));
same('ships_how ground → parcel', 'parcel', inv_ships_how('ground'));
same('ships_how bogus → null', null, inv_ships_how('teleport'));

group('normalize — size_key from titles and options (§0.3 sizes)');
foreach ([
    'Twin' => 'twin', 'Twin XL' => 'twin_xl', 'twin-xl' => 'twin_xl', 'TXL' => 'twin_xl', 'Full' => 'full', 'Double' => 'full', 'Full XL' => 'full_xl',
    'Queen' => 'queen', 'Olympic Queen' => 'olympic_queen', 'RV Short Queen' => 'rv_short_queen', 'Short Queen' => 'rv_short_queen',
    'King' => 'king', 'Eastern King' => 'king', 'California King' => 'california_king', 'Cal King' => 'california_king', 'CK' => 'california_king',
    'Cal-King' => 'california_king', 'Split King' => 'split_king', 'Split Cal King' => 'split_california_king', 'Split California King' => 'split_california_king',
    'Crib' => 'crib', 'K' => 'king', 'Q' => 'queen',
    'CloudRest Hybrid Mattress - Twin XL' => 'twin_xl', 'Harbor Latex Topper King 3 inch' => 'king', 'Meadowlark Adjustable Base – Split King' => 'split_king',
    'Twinkle Night Light' => null, 'Pillow' => null, 'Kingston Frame' => null, 'Queensway Sheet Set' => null,
] as $text => $key) {
    same("size '$text' → " . json_encode($key), $key, inv_size_key_in($text));
}
same('size from a Size option beats the title', 'queen', inv_size_key(['Thickness' => '3 inch', 'Size' => 'Queen'], 'Harbor Latex Topper King'));
same('size from any option value when no Size option', 'king', inv_size_key(['Firmness' => 'Medium', 'Dimension' => 'King'], 'Topper'));
same('size from the title when options say nothing', 'full', inv_size_key(['Color' => 'Grey'], 'Platform Frame Full'));
same('size null when nothing names one', null, inv_size_key([], 'Pillow', 'Pillow'));
same('size label', 'California King', inv_size_label('california_king'));

group('normalize — the listing shape');
$n = inv_normalize_listing(['external_id' => 'X1', 'title' => '  A Mattress ', 'tags' => 'a, b, a', 'currency' => 'usd', 'variants' => [
    ['title' => 'Queen', 'sku' => ' SKU-Q ', 'barcode' => '850123450035', 'price' => '999', 'compare_at_price' => '999', 'available' => true, 'option_values' => ['Size' => 'Queen']],
    ['sku' => 'SKU-K', 'price' => 1299, 'compare_at_price' => '1599.00', 'availability' => 'OutOfStock', 'qty' => '0', 'ships_how' => 'LTL', 'lead_time_days' => '7.0'],
]]);
check('listing shape is valid', inv_listing_valid($n, $why), (string) $why);
same('title trimmed', 'A Mattress', $n['title']);
same('tags from a string, unique', ['a', 'b'], $n['tags']);
same('handle null when not given', null, $n['handle']);
same('external_variant_id defaults to id:n', 'X1:1', $n['variants'][0]['external_variant_id']);
same('sku trimmed', 'SKU-Q', $n['variants'][0]['sku']);
same('price two decimals', '999.00', $n['variants'][0]['price']);
same('compare_at equal to price dropped', null, $n['variants'][0]['compare_at_price']);
same('compare_at above price kept', '1599.00', $n['variants'][1]['compare_at_price']);
same('currency inherited from the listing', 'USD', $n['variants'][1]['currency']);
same('availability from available=true', 'in_stock', $n['variants'][0]['availability']);
same('availability word wins over qty', 'out_of_stock', $n['variants'][1]['availability']);
same('qty as int', 0, $n['variants'][1]['qty']);
same('lead_time_days as int', 7, $n['variants'][1]['lead_time_days']);
same('ships_how normalized', 'ltl', $n['variants'][1]['ships_how']);
same('size_key from the option', 'queen', $n['variants'][0]['size_key']);
same('variant title defaults to its options or the listing', 'A Mattress', $n['variants'][1]['title']);
$one = inv_normalize_listing(['handle' => 'solo', 'url' => 'https://x.example/products/solo', 'title' => 'Solo Pillow', 'price' => '59', 'sku' => 'P1', 'availability' => 'in stock']);
same('a listing without variants gets one', 1, count($one['variants']));
same('… with the listing-level price', '59.00', $one['variants'][0]['price']);
same('… external_id from the handle', 'solo', $one['external_id']);
same('… variant url from the listing', 'https://x.example/products/solo', $one['variants'][0]['url']);
throws('a listing with no id, handle or url is refused', InvalidArgumentException::class, static fn() => inv_normalize_listing(['title' => 'x']));
$bad = $n;
$bad['extra'] = 1;
check('inv_listing_valid refuses an extra key', !inv_listing_valid($bad));

// =====================================================================================================================
group('credentials — libsodium seal/open under INV_SECRETS_KEY');
check('sodium extension present', function_exists('sodium_crypto_secretbox'));
putenv('INV_SECRETS_KEY');
unset($_ENV['INV_SECRETS_KEY']);
throws('no key → InvCredentialError', InvCredentialError::class, static fn() => inv_seal(['kind' => 'bearer', 'token' => 'x']));
putenv('INV_SECRETS_KEY=notahexkey');
throws('a malformed key → InvCredentialError', InvCredentialError::class, static fn() => inv_seal(['kind' => 'bearer', 'token' => 'x']));
$key1 = inv_secrets_key_generate();
putenv('INV_SECRETS_KEY=' . $key1);
check('generated key is 64 hex chars', (bool) preg_match('~^[0-9a-f]{64}$~', $key1));
$cred = ['kind' => 'bearer', 'label' => 'Storefront token', 'token' => 'shpat_fixture_token'];
$sealed = inv_seal($cred);
check('sealed text is versioned and base64url', (bool) preg_match('~^v1\.[A-Za-z0-9_\-]+$~', $sealed));
check('ciphertext holds no plaintext', !str_contains($sealed, 'shpat') && !str_contains(base64_decode(strtr(substr($sealed, 3), '-_', '+/')) ?: '', 'shpat_fixture'));
same('open gives the credential back', $cred, inv_open($sealed));
check('two seals of one credential differ (fresh nonce)', inv_seal($cred) !== $sealed);
$tampered = substr($sealed, 0, -3) . (substr($sealed, -3) === 'AAA' ? 'BBB' : 'AAA');
throws('a tampered ciphertext is refused', InvCredentialError::class, static fn() => inv_open($tampered));
$mid = strlen($sealed) >> 1;
$flipped = substr($sealed, 0, $mid) . ($sealed[$mid] === 'a' ? 'b' : 'a') . substr($sealed, $mid + 1);
throws('a flipped character in the box is refused', InvCredentialError::class, static fn() => inv_open($flipped));
throws('an unknown version is refused', InvCredentialError::class, static fn() => inv_open('v9.abc'));
throws('garbage is refused', InvCredentialError::class, static fn() => inv_open('v1.!!!'));
putenv('INV_SECRETS_KEY=' . inv_secrets_key_generate());
throws('another key cannot open it', InvCredentialError::class, static fn() => inv_open($sealed));
putenv('INV_SECRETS_KEY=' . $key1);
throws('an unknown credential kind is refused at sealing', InvCredentialError::class, static fn() => inv_seal(['kind' => 'magic', 'token' => 'x']));
same('summary shows kind, label, last4 only', ['kind' => 'bearer', 'label' => 'Storefront token', 'last4' => 'oken'], inv_credential_summary($cred));
$s = inv_credential_summary(['kind' => 'sftp_key', 'username' => 'dealer', 'private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nabc\n-----END OPENSSH PRIVATE KEY-----"]);
check('a key\'s summary is a fingerprint prefix, not its tail', strlen($s['last4']) === 4 && $s['last4'] !== '----' && $s['label'] === 'dealer');
same('a password credential round-trips', 'basic', inv_open(inv_seal(['kind' => 'basic', 'username' => 'dealer', 'password' => 's3cret-feed']))['kind']);
$_ENV['INV_SECRETS_KEY'] = $key1;
putenv('INV_SECRETS_KEY');
same('the key is read from $_ENV when getenv has none', $cred, inv_open($sealed));
putenv('INV_SECRETS_KEY=' . $key1);

// =====================================================================================================================
group('robots — parsing and matching');
$rb = inv_robots_parse((string) file_get_contents(__DIR__ . '/../fixtures/sources/robots.txt'));
same('two groups parsed', ['*', 'slowbot'], array_keys($rb['groups']));
same('sitemap line read', ['http://127.0.0.1:8606/sitemap.xml'], $rb['sitemaps']);
check('/private/x disallowed for *', !inv_robots_allows($rb, '/private/dealer-pricing.html', $UA));
check('/products.json allowed for *', inv_robots_allows($rb, '/products.json?limit=250&page=1', $UA));
check('a longer Allow beats Disallow', inv_robots_allows($rb, '/private/public-note.html', $UA));
check('/cart disallowed (prefix)', !inv_robots_allows($rb, '/cart/add', $UA));
same('no crawl-delay for *', null, inv_robots_crawl_delay($rb, $UA));
same('crawl-delay 2 for SlowBot (matched inside the UA)', 2.0, inv_robots_crawl_delay($rb, 'Mozilla/5.0 SlowBot/1.0'));
check('SlowBot group still disallows /private/', !inv_robots_allows($rb, '/private/x', 'SlowBot'));
$rb2 = inv_robots_parse("User-agent: *\nDisallow: /*.json$\nDisallow: /search*\nAllow: /\n");
check('wildcard and $ patterns', !inv_robots_allows($rb2, '/products.json', $UA) && inv_robots_allows($rb2, '/products.json?page=1', $UA) && !inv_robots_allows($rb2, '/search/suggest.json', $UA));
check('an empty file allows all', inv_robots_allows(inv_robots_parse(''), '/anything', $UA));
check('Disallow: (empty) allows all', inv_robots_allows(inv_robots_parse("User-agent: *\nDisallow:\n"), '/anything', $UA));

// =====================================================================================================================
group('http — the one client and the crawl policy (§0.2)');
reqlog_reset();
$cache = fresh_cache('http');
$http = new InvHttp(['user_agent' => $UA, 'rate_per_second' => 50, 'cache_dir' => $cache]);
$r = $http->get("$BASE/products.json?limit=250&page=1");
check('a plain GET is ok', $r['ok'] && $r['status'] === 200 && !$r['cached'] && !$r['blocked']);
$log = reqlog();
same('robots.txt fetched first, once', '/robots.txt', $log[0]['path'] ?? null);
same('the honest User-Agent is sent', $UA, $log[1]['ua'] ?? null);
same('no conditional header on the first fetch', null, $log[1]['if_none_match'] ?? null);
$r2 = $http->get("$BASE/products.json?limit=250&page=1");
$log = reqlog();
same('the second fetch sends If-None-Match', '"p1-v1"', $log[2]['if_none_match'] ?? null);
check('… and answers from the cache (304)', $r2['cached'] && $r2['ok'] && $r2['body'] === $r['body'] && $r2['status'] === 200);
$f = $http->facts();
same('facts count the cached answer', 1, $f['cached']);
same('facts count three requests (robots + 2)', 3, $f['http_requests']);
check('facts carry bytes', $f['bytes'] > 1000);
same('facts record robots state per host', 'ok', $f['robots']["127.0.0.1"]['state'] ?? null);
same('facts list every request with host/path/status', ['127.0.0.1', '/products.json', 200], [$f['requests'][1]['host'], $f['requests'][1]['path'], $f['requests'][1]['status']]);
same('a default UA says it is unconfigured', InvHttp::DEFAULT_USER_AGENT, inv_http_user_agent([]));
putenv('INV_CRAWL_USER_AGENT=EnvBot/1.0 (+mailto:ops@example)');
same('INV_CRAWL_USER_AGENT is the default UA', 'EnvBot/1.0 (+mailto:ops@example)', inv_http_user_agent([]));
same('the source overrides the env UA', 'X/1', inv_http_user_agent(['user_agent' => 'X/1']));
putenv('INV_CRAWL_USER_AGENT');

// rate limit
reqlog_reset();
$slow = new InvHttp(['user_agent' => $UA, 'rate_per_second' => 1, 'cache_dir' => fresh_cache('rate')]);
$t0 = microtime(true);
$slow->get("$BASE/products.json?page=2");
$slow->get("$BASE/products.json?page=2&x=1");
$dt = microtime(true) - $t0;
check("two requests to one host at rate 1 take ≥ 1 s (robots + 2 fetches: $dt)", $dt >= 2.0, sprintf('%.2f s', $dt));
$sl = reqlog();
check('… and they were serialized in order', count($sl) === 3 && $sl[2]['t'] - $sl[1]['t'] >= 0.95, isset($sl[2]) ? sprintf('%.2f', $sl[2]['t'] - $sl[1]['t']) : 'n/a');
$crawl = new InvHttp(['user_agent' => 'Mozilla/5.0 SlowBot/1.0 (+ops@example)', 'rate_per_second' => 50, 'cache_dir' => fresh_cache('crawl')]);
$t0 = microtime(true);
$crawl->get("$BASE/products.json?page=2&y=1");
$crawl->get("$BASE/products.json?page=2&y=2");
$dt = microtime(true) - $t0;
check("Crawl-delay: 2 raises the wait (two fetches after robots ≥ 4 s: $dt)", $dt >= 4.0, sprintf('%.2f s', $dt));
same('… and the facts say so', 2.0, $crawl->facts()['robots']['127.0.0.1']['crawl_delay']);

// robots disallow
reqlog_reset();
$r = $http->get("$BASE/private/dealer-pricing.html");
check('a disallowed path is skipped, not fetched', $r['skipped'] && !$r['ok'] && $r['reason'] === 'robots_disallow' && $r['body'] === '');
check('… and never reached the server', !in_array('/private/dealer-pricing.html', paths(), true));
same('… counted in facts', 1, $http->facts()['robots_skipped']);
$r = $http->get("$BASE/private/public-note.html");
check('an Allow inside a Disallow is fetched', !$r['skipped']);

// blocks
foreach (['/wall' => 'bot_wall:cloudflare', '/wall-503' => 'bot_wall:cloudflare', '/denied' => 'bot_wall:akamai', '/forbidden' => 'http_403', '/ratelimited' => 'http_429'] as $p => $reason) {
    $r = $http->get("$BASE$p");
    check("$p → blocked ($reason)", $r['blocked'] && !$r['ok'] && $r['reason'] === $reason, json_encode([$r['status'], $r['reason']]));
}
same('blocked requests counted', 5, $http->facts()['blocked']);
same('a PerimeterX page is a bot wall', 'bot_wall:perimeterx', inv_http_blocked_reason(200, ['content-type' => 'text/html'], '<html><head><script src="/_px/captcha.js"></script><div id="px-captcha"></div></head></html>'));
same('a JSON 200 is never a bot wall', null, inv_http_blocked_reason(200, ['content-type' => 'application/json'], '{"title":"Just a moment..."}'));
same('an ordinary 404 html is not a wall', null, inv_http_blocked_reason(404, ['content-type' => 'text/html'], '<html><title>Not found</title></html>'));

// redirects
reqlog_reset();
$r = $http->get("$BASE/login-redirect");
check('a redirect to /account/login is refused as blocked', $r['blocked'] && $r['reason'] === 'login_redirect');
$r = $http->get("$BASE/login-redirect-abs");
check('an absolute redirect to /customer/login is refused too', $r['blocked'] && $r['reason'] === 'login_redirect');
check('the login page itself was never requested', !in_array('/account/login', paths(), true) && !in_array('/customer/login', paths(), true));
$r = $http->get("$BASE/redirect-ok");
check('an ordinary redirect is followed', $r['ok'] && str_contains($r['body'], 'CloudRest') && str_ends_with($r['url'], '/products.json?page=1'));
$r = $http->get("$BASE/redirect-loop");
check('a redirect loop stops at five hops', !$r['ok'] && $r['reason'] === 'too_many_redirects');
same('inv_http_resolve relative path', 'https://a.example/x/y', inv_http_resolve('https://a.example/x/z', 'y'));
same('inv_http_resolve protocol-relative', 'https://b.example/p', inv_http_resolve('https://a.example/', '//b.example/p'));

// limits and transport
$small = new InvHttp(['user_agent' => $UA, 'rate_per_second' => 50, 'cache_dir' => fresh_cache('small'), 'max_bytes' => 100000]);
$r = $small->get("$BASE/big");
check('a body over max_bytes is refused', !$r['ok'] && $r['reason'] === 'transport:max_bytes');
$seenReq = null;
$fake = new InvHttp(['user_agent' => $UA, 'rate_per_second' => 50, 'cache_dir' => fresh_cache('fake'), 'proxy' => 'http://proxy.example:3128', 'transport' => static function (array $req) use (&$seenReq): array {
    $seenReq = $req;
    if (str_ends_with($req['url'], '/robots.txt')) {
        return ['status' => 403, 'headers' => ['Content-Type' => 'text/html'], 'body' => 'no'];
    }
    return ['status' => 200, 'headers' => ['Content-Type' => 'application/json'], 'body' => '{"products":[]}'];
}]);
$r = $fake->get('https://walled.example/products.json');
check('a 403 on robots.txt = nothing allowed (skipped)', $r['skipped'] && $r['reason'] === 'robots_disallow');
same('… robots state blocked in facts', 'blocked', $fake->facts()['robots']['walled.example']['state']);
check('the transport received the proxy and the UA', $seenReq['proxy'] === 'http://proxy.example:3128' && $seenReq['headers']['User-Agent'] === $UA);
$fake2 = new InvHttp(['user_agent' => $UA, 'rate_per_second' => 50, 'cache_dir' => fresh_cache('fake2'), 'transport' => static fn(array $req) => ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'Could not resolve host']]);
$r = $fake2->get('https://nowhere.invalid/x');
check('a transport error is reported, not thrown', !$r['ok'] && str_starts_with((string) $r['reason'], 'transport:'));
$r = inv_http_get("$BASE/products.json?page=2", ['client' => $http]);
check('inv_http_get() goes through the given client', $r['ok'] && $r['body'] === '{"products":[]}' . "\n" || $r['ok']);

// =====================================================================================================================
group('shopify — probe, pull, lookup, search, Storefront');
reqlog_reset();
$sh = inv_connector('shopify');
$src = src('shopify');
$p = $sh->probe($src);
same('probe ok', 'ok', $p['state']);
check('probe names the first product', str_contains($p['message'], 'CloudRest'), $p['message']);
same('probe asks for one product (limit=1)', '1', reqlog()[1]['query']['limit'] ?? null);
same('probe facts: first page products', 3, $p['facts']['first_page_products'] ?? null);
reqlog_reset();
[$ls, $st] = pull_all($sh, $src);
same('pull emits 3 listings', 3, count($ls));
same('pull status ok', 'ok', $st['status']);
same('pull listings_seen', 3, $st['listings_seen']);
same('pull http_requests = robots + page 1 (a short page ends the pull; no page 2)', 2, $st['http_requests']);
same('pull errors empty', [], $st['errors']);
check('pull policy facts carry the UA and the rate', $st['policy']['user_agent'] === $UA && $st['policy']['rate_per_second'] === 50.0);
$pg = array_values(array_filter(reqlog(), static fn($r) => $r['path'] === '/products.json'));
same('page 1 requested with limit=250', ['250', '1'], [$pg[0]['query']['limit'] ?? null, $pg[0]['query']['page'] ?? null]);
same('only one page requested (3 products < 250)', 1, count($pg));
foreach ($ls as $i => $l) {
    check("listing $i has the normalized shape", inv_listing_valid($l, $why), (string) $why);
}
$cr = by_id($ls, '8001');
same('external_id is the product id', '8001', $cr['external_id'] ?? null);
same('handle', 'cloudrest-hybrid-mattress', $cr['handle']);
same('url', "$BASE/products/cloudrest-hybrid-mattress", $cr['url']);
same('title', 'CloudRest Hybrid Mattress', $cr['title']);
same('vendor', 'Northwind Sleep', $cr['vendor']);
same('product_type', 'Mattress', $cr['product_type']);
same('tags', ['hybrid', 'medium-firm', 'bed-in-a-box', 'CertiPUR-US'], $cr['tags']);
same('six variants', 6, count($cr['variants']));
same('raw holds the images (trimmed), not the body_html', ['https://cdn.example.test/s/files/1/cloudrest-front.jpg'], $cr['raw']['images']);
check('raw has no body_html', !isset($cr['raw']['body_html']));
$t = variant($cr, 'NW-CR-T');
same('Twin: external_variant_id', '41001', $t['external_variant_id']);
same('Twin: option_values', ['Size' => 'Twin'], $t['option_values']);
same('Twin: size_key', 'twin', $t['size_key']);
same('Twin: barcode GTIN-14', '00850123450004', $t['barcode']);
same('Twin: price', '699.00', $t['price']);
same('Twin: compare_at_price', '899.00', $t['compare_at_price']);
same('Twin: currency from settings default', 'USD', $t['currency']);
same('Twin: availability in_stock', 'in_stock', $t['availability']);
same('Twin: qty unknown without a token', null, $t['qty']);
same('Twin: url with ?variant=', "$BASE/products/cloudrest-hybrid-mattress?variant=41001", $t['url']);
same('Full: compare_at equal to price → null', null, variant($cr, 'NW-CR-F')['compare_at_price']);
same('King: available=false → out_of_stock', 'out_of_stock', variant($cr, 'NW-CR-K')['availability']);
same('Cal King: size_key california_king', 'california_king', variant($cr, 'NW-CR-CK')['size_key']);
same('Cal King: hyphenated barcode normalized', '00850123450059', variant($cr, 'NW-CR-CK')['barcode']);
same('Cal King: compare_at null stays null', null, variant($cr, 'NW-CR-CK')['compare_at_price']);
$tp = by_id($ls, '8002');
same('topper: tags from a comma string', ['latex', 'GOLS', 'topper'], $tp['tags']);
same('topper: two options mapped', ['Size' => 'Queen', 'Thickness' => '3 inch'], variant($tp, 'HP-LT-Q-3')['option_values']);
same('topper: size_key from the Size option', 'king', variant($tp, 'HP-LT-K-3')['size_key']);
$pil = by_id($ls, '8003');
same('pillow: Default Title → no option values', [], $pil['variants'][0]['option_values']);
same('pillow: title from the product', 'Northwind Down-Alternative Pillow', $pil['variants'][0]['title']);
same('pillow: size_key null', null, $pil['variants'][0]['size_key']);
same('pillow: empty barcode → null', null, $pil['variants'][0]['barcode']);
same('pillow: compare_at equal → null', null, $pil['variants'][0]['compare_at_price']);
[$ls2, $st2] = pull_all($sh, $src);
check('a second pull answers page 1 from the ETag cache', $st2['cached'] >= 1 && count($ls2) === 3, json_encode($st2['cached']));
$coll = src('shopify', ['settings' => ['collections' => ['mattresses']]]);
[$lc] = pull_all($sh, $coll);
same('settings.collections narrows the pull', 1, count($lc));
same('… to the collection\'s product', '8001', $lc[0]['external_id']);
$lk = $sh->lookup($src, ['handle' => 'cloudrest-hybrid-mattress']);
same('lookup by handle → one listing', 1, count($lk));
same('lookup: .js prices in cents → decimal', '699.00', variant($lk[0], 'NW-CR-T')['price']);
same('lookup: .js compare_at in cents', '1299.00', variant($lk[0], 'NW-CR-Q')['compare_at_price']);
same('lookup: inventory_quantity read when the theme exposes it', 12, variant($lk[0], 'NW-CR-Q')['qty']);
same('lookup: product_type from .js "type"', 'Mattress', $lk[0]['product_type']);
same('lookup: image protocol-relative → https', 'https://cdn.example.test/s/files/1/cloudrest-front.jpg', $lk[0]['raw']['images'][0]);
same('lookup by product url', 1, count($sh->lookup($src, ['url' => "$BASE/products/cloudrest-hybrid-mattress?variant=1"])));
same('lookup of an unknown handle → []', [], $sh->lookup($src, ['handle' => 'nope']));
throws('lookup without a handle → InvNotSupported', InvNotSupported::class, static fn() => $sh->lookup($src, ['gtin' => '1']));
$se = $sh->search($src, 'cloudrest', 10);
same('search via suggest.json → 2 listings', 2, count($se));
same('search: the known handle is fetched whole (.js, 3 variants)', 3, count($se[0]['variants']));
same('search: an unknown handle keeps the suggest fields', ['8009', '249.00'], [$se[1]['external_id'], $se[1]['variants'][0]['price']]);
same('search with no hit → []', [], $sh->search($src, 'zzzz'));
throws('search on a store without suggest.json → InvNotSupported', InvNotSupported::class, static fn() => $sh->search(src('shopify', ['base_url' => "$BASE/nowhere"]), 'x'));
$bad = $sh->probe(src('shopify', ['base_url' => "$BASE/nowhere"]));
same('probe on a non-Shopify path → misconfigured', 'misconfigured', $bad['state']);
$walled = src('shopify', ['transport' => static fn(array $req) => str_ends_with($req['url'], '/robots.txt') ? ['status' => 404, 'headers' => [], 'body' => ''] : ['status' => 403, 'headers' => ['Content-Type' => 'application/json'], 'body' => '{}']]);
same('probe on a 403 store → blocked', 'blocked', $sh->probe($walled)['state']);
[$lw, $sw] = pull_all($sh, $walled);
same('pull on a 403 store → status blocked, nothing emitted', ['blocked', 0], [$sw['status'], count($lw)]);
throws('a relative base_url is misconfigured', InvMisconfigured::class, static fn() => $sh->probe(src('shopify', ['base_url' => 'store.example'])));
// Storefront API with a bearer token
$tok = src('shopify', ['credential' => ['kind' => 'bearer', 'token' => 'shpat_fixture_token']]);
$pp = $sh->probe($tok);
same('Storefront probe ok', 'ok', $pp['state']);
check('Storefront probe names the shop', str_contains($pp['message'], 'Fixture Sleep Co'));
reqlog_reset();
[$lt, $stt] = pull_all($sh, $tok);
same('Storefront pull emits 2 listings', 2, count($lt));
check('Storefront pull went through graphql.json with the token header', count(array_filter(reqlog(), static fn($r) => str_contains($r['path'], 'graphql.json') && $r['storefront_token'] === 'shpat_fixture_token')) >= 1);
$g = by_id($lt, '8001');
same('Storefront: gid → numeric external_id', '8001', $g['external_id'] ?? null);
same('Storefront: quantityAvailable → qty', 7, variant($g, 'NW-CR-T')['qty']);
same('Storefront: price amount "699.0" → 699.00', '699.00', variant($g, 'NW-CR-T')['price']);
same('Storefront: currencyCode', 'USD', variant($g, 'NW-CR-T')['currency']);
same('Storefront: availableForSale + qty 0 → back_order', 'back_order', variant($g, 'NW-CR-Q')['availability']);
same('Storefront: not for sale → out_of_stock', 'out_of_stock', variant($g, 'NW-CR-K')['availability']);
same('Storefront: variant gid → id', '41001', variant($g, 'NW-CR-T')['external_variant_id']);
same('Storefront: selectedOptions → option_values', ['Size' => 'Twin'], variant($g, 'NW-CR-T')['option_values']);
same('Storefront: null quantityAvailable stays null', null, by_id($lt, '8003')['variants'][0]['qty']);
same('Storefront probe with a bad token → misconfigured', 'misconfigured', $sh->probe(src('shopify', ['credential' => ['kind' => 'bearer', 'token' => 'wrong']]))['state']);
$caps = $sh->capabilities();
check('capabilities: search, lookup by handle, bearer credential', $caps['has_search'] && $caps['has_lookup'] && in_array('handle', $caps['lookup_by'], true) && $caps['credential_kinds'] === ['bearer'] && !$caps['gives_cost']);

// =====================================================================================================================
group('woocommerce — probe, pull, variations, search, lookup');
reqlog_reset();
$wc = inv_connector('woocommerce');
$src = src('woocommerce');
$p = $wc->probe($src);
same('probe ok', 'ok', $p['state']);
same('probe facts: total from X-WP-Total', 3, $p['facts']['total']);
[$ls, $st] = pull_all($wc, $src);
same('pull emits 3 listings', 3, count($ls));
same('pull status ok', 'ok', $st['status']);
same('pull http_requests = robots + page 1 + 3 variations (TotalPages honoured, no page 2)', 5, $st['http_requests']);
check('page 2 never requested', count(array_filter(reqlog(), static fn($r) => ($r['query']['page'] ?? '1') === '2')) === 0);
foreach ($ls as $i => $l) {
    check("listing $i has the normalized shape", inv_listing_valid($l, $why), (string) $why);
}
$ml = by_id($ls, '200');
same('variable: vendor from brands[]', 'Meadowlark Bedding', $ml['vendor']);
same('variable: product_type from the first category', 'Mattresses', $ml['product_type']);
same('variable: tags', ['latex', 'organic'], $ml['tags']);
same('variable: handle from slug', 'meadowlark-organic-latex-mattress', $ml['handle']);
same('variable: three variants resolved', 3, count($ml['variants']));
$txl = variant($ml, 'ML-ORG-TXL');
same('Twin XL: external_variant_id is the variation id', '201', $txl['external_variant_id']);
same('Twin XL: option value is the term NAME from the slug', ['Size' => 'Twin XL'], $txl['option_values']);
same('Twin XL: size_key', 'twin_xl', $txl['size_key']);
same('Twin XL: price from minor units', '1499.00', $txl['price']);
same('Twin XL: regular price → compare_at when on sale', '1799.00', $txl['compare_at_price']);
same('Twin XL: currency_code', 'USD', $txl['currency']);
same('Twin XL: global_unique_id → barcode', '05901234123457', $txl['barcode']);
same('Twin XL: in stock', 'in_stock', $txl['availability']);
same('Queen: low_stock_remaining → limited + qty', ['limited', 2], [variant($ml, 'ML-ORG-Q')['availability'], variant($ml, 'ML-ORG-Q')['qty']]);
same('Cal King: not in stock → out_of_stock', 'out_of_stock', variant($ml, 'ML-ORG-CK')['availability']);
same('Cal King: size_key', 'california_king', variant($ml, 'ML-ORG-CK')['size_key']);
same('Cal King: empty gtin → null', null, variant($ml, 'ML-ORG-CK')['barcode']);
same('Cal King: variation permalink', "$BASE/product/meadowlark-organic-latex-mattress/?attribute_pa_size=california-king", variant($ml, 'ML-ORG-CK')['url']);
$wp = by_id($ls, '210');
same('simple: title entity-decoded', 'Meadowlark Wool Protector – Queen', $wp['title']);
same('simple: one variant with the product id', '210', $wp['variants'][0]['external_variant_id']);
same('simple: size from a single-term attribute', ['Size' => 'Queen'], $wp['variants'][0]['option_values']);
same('simple: low_stock_remaining 3 → limited, qty 3', ['limited', 3], [$wp['variants'][0]['availability'], $wp['variants'][0]['qty']]);
same('simple: not on sale → no compare_at', null, $wp['variants'][0]['compare_at_price']);
same('simple: gtin', '04006381333931', $wp['variants'][0]['barcode']);
$ab = by_id($ls, '220');
same('backorder: stock_availability text → back_order', 'back_order', $ab['variants'][0]['availability']);
same('backorder: size from the title', 'split_king', $ab['variants'][0]['size_key']);
same('backorder: no brand, none in settings → null vendor', null, $ab['vendor']);
same('settings.vendor fills a missing brand', 'Meadowlark', by_id(pull_all($wc, src('woocommerce', ['settings' => ['vendor' => 'Meadowlark']]))[0], '220')['vendor']);
$se = $wc->search($src, 'protector', 10);
same('search= → 1 listing', 1, count($se));
same('search: the hit', '210', $se[0]['external_id']);
same('lookup by id', '210', $wc->lookup($src, ['id' => 210])[0]['external_id'] ?? null);
same('lookup by sku', '220', $wc->lookup($src, ['sku' => 'ML-AB-SK'])[0]['external_id'] ?? null);
same('lookup of an unknown id → []', [], $wc->lookup($src, ['id' => 999]));
throws('lookup without id/sku → InvNotSupported', InvNotSupported::class, static fn() => $wc->lookup($src, ['gtin' => 'x']));
same('probe on a non-Woo path → misconfigured', 'misconfigured', $wc->probe(src('woocommerce', ['base_url' => "$BASE/nowhere"]))['state']);
$walled = src('woocommerce', ['transport' => static fn(array $req) => str_ends_with($req['url'], '/robots.txt') ? ['status' => 404, 'headers' => [], 'body' => ''] : ['status' => 429, 'headers' => [], 'body' => 'slow down']]);
same('probe on a 429 store → blocked', 'blocked', $wc->probe($walled)['state']);
same('pull on a 429 store → blocked', 'blocked', pull_all($wc, $walled)[1]['status']);
same('capabilities: search and lookup, no credential', [true, true, false], [$wc->capabilities()['has_search'], $wc->capabilities()['has_lookup'], $wc->capabilities()['needs_credential']]);

// =====================================================================================================================
group('jsonld — sitemap, pages, @graph, ProductGroup, AggregateOffer, Open Graph, robots per page');
reqlog_reset();
$jl = inv_connector('jsonld');
$src = src('jsonld');
$p = $jl->probe($src);
same('probe ok (sitemap found)', 'ok', $p['state']);
same('probe facts: the sitemap read', "$BASE/sitemap.xml", $p['facts']['sitemap']);
reqlog_reset();
[$ls, $st] = pull_all($jl, $src);
same('pull emits 3 listings', 3, count($ls));
same('pull status ok', 'ok', $st['status']);
same('pull pages found in the product sitemap', 5, $st['pages']);
same('pull: the robots-disallowed page skipped', 1, $st['pages_skipped_by_robots']);
same('pull: the page without a product counted', 1, $st['pages_without_product']);
check('the pages sitemap was not fetched (product sitemaps preferred)', !in_array('/sitemap_pages_1.xml', paths(), true));
check('the private page never reached the server', !in_array('/private/dealer-pricing.html', paths(), true));
check('no listing carries the secret price', count(array_filter($ls, static fn($l) => str_contains($l['title'], 'SECRET'))) === 0);
foreach ($ls as $i => $l) {
    check("listing $i has the normalized shape", inv_listing_valid($l, $why), (string) $why);
}
$cr = by_id($ls, 'CLOUDREST');
same('ProductGroup: external_id from productGroupID', 'CLOUDREST', $cr['external_id'] ?? null);
same('ProductGroup: brand from a Brand object', 'Northwind Sleep', $cr['vendor']);
same('ProductGroup: category chain → its last part', 'Mattresses', $cr['product_type']);
same('ProductGroup: handle from the URL', 'cloudrest-hybrid', $cr['handle']);
same('ProductGroup: url', "$BASE/products/cloudrest-hybrid.html", $cr['url']);
same('ProductGroup: two images in raw', 2, count($cr['raw']['images']));
same('ProductGroup: four variants from hasVariant', 4, count($cr['variants']));
$t = variant($cr, 'NW-CR-T');
same('variant: gtin12 → GTIN-14', '00850123450004', $t['barcode']);
same('variant: mpn', 'CR-HYB-T', $t['mpn']);
same('variant: size property → Size option + size_key', [['Size' => 'Twin'], 'twin'], [$t['option_values'], $t['size_key']]);
same('variant: Offer price/currency/availability', ['699.00', 'USD', 'in_stock'], [$t['price'], $t['currency'], $t['availability']]);
same('variant: Offer url', "$BASE/products/cloudrest-hybrid.html?variant=41001", $t['url']);
$q = variant($cr, 'NW-CR-Q');
same('variant: numeric price 999 → "999.00"', '999.00', $q['price']);
same('variant: http://schema.org/LimitedAvailability → limited', 'limited', $q['availability']);
same('variant: inventoryLevel → qty', 3, $q['qty']);
same('variant: Size from additionalProperty', 'king', variant($cr, 'NW-CR-K')['size_key']);
same('variant: BackOrder', 'back_order', variant($cr, 'NW-CR-K')['availability']);
same('variant: SizeSpecification → california_king', 'california_king', variant($cr, 'NW-CR-CK')['size_key']);
same('variant: Discontinued', 'discontinued', variant($cr, 'NW-CR-CK')['availability']);
same('variant: url falls back to the page', "$BASE/products/cloudrest-hybrid.html", variant($cr, 'NW-CR-K')['url']);
$tp = by_id($ls, 'HP-LT-Q-3');
same('Product in a JSON array: external_id from sku', 'HP-LT-Q-3', $tp['external_id'] ?? null);
same('brand as a plain string', 'Harbor & Pine', $tp['vendor']);
same('gtin13 with a leading zero → GTIN-14', '00850123450110', $tp['variants'][0]['barcode']);
same('AggregateOffer lowPrice → price, highPrice → compare_at', ['349.00', '549.00'], [$tp['variants'][0]['price'], $tp['variants'][0]['compare_at_price']]);
same('PreOrder', 'pre_order', $tp['variants'][0]['availability']);
same('a relative url resolved against the page', "$BASE/products/harbor-latex-topper.html", $tp['url']);
same('the WebPage node is not a product', 1, $tp['raw']['offers']);
$og = by_id($ls, 'NW-PF-Q');
same('Open Graph fallback: external_id from retailer_item_id', 'NW-PF-Q', $og['external_id'] ?? null);
same('Open Graph: price and currency', ['199.00', 'USD'], [$og['variants'][0]['price'], $og['variants'][0]['currency']]);
same('Open Graph: availability', 'out_of_stock', $og['variants'][0]['availability']);
same('Open Graph: brand', 'Northwind Sleep', $og['vendor']);
same('Open Graph: size from the title', 'queen', $og['variants'][0]['size_key']);
same('Open Graph: og:url', "$BASE/products/northwind-platform-frame-queen.html", $og['url']);
check('Open Graph: flagged in raw', $og['raw']['opengraph'] === true);
same('a page with JSON-LD does not fall back to its OG tags', 4, count($cr['variants']));
[$ls2, $st2] = pull_all($jl, $src);
check('a second pull answers pages from the ETag cache', $st2['cached'] >= 3, json_encode($st2['cached']));
$lk = $jl->lookup($src, ['url' => "$BASE/products/harbor-latex-topper.html"]);
same('lookup by url', 'HP-LT-Q-3', $lk[0]['external_id'] ?? null);
throws('lookup without a url → InvNotSupported', InvNotSupported::class, static fn() => $jl->lookup($src, ['sku' => 'x']));
throws('search → InvNotSupported', InvNotSupported::class, static fn() => $jl->search($src, 'x'));
$urls = src('jsonld', ['settings' => ['urls' => ["$BASE/products/cloudrest-hybrid.html", "$BASE/private/dealer-pricing.html"]]]);
same('probe with urls[] ok', 'ok', $jl->probe($urls)['state']);
[$lu, $su] = pull_all($jl, $urls);
same('pull from urls[]: one listing, one page skipped by robots', [1, 1], [count($lu), $su['pages_skipped_by_robots']]);
same('settings.sitemap_url names the product sitemap directly', 5, pull_all($jl, src('jsonld', ['settings' => ['sitemap_url' => '/sitemap_products_1.xml']]))[1]['pages']);
same('settings.url_pattern filters the pages', 1, pull_all($jl, src('jsonld', ['settings' => ['url_pattern' => 'topper']]))[1]['pages']);
same('probe with no sitemap and no urls → misconfigured', 'misconfigured', $jl->probe(src('jsonld', ['transport' => static fn(array $req) => ['status' => 404, 'headers' => [], 'body' => '']]))['state']);
$walled = src('jsonld', ['transport' => static fn(array $req) => str_ends_with($req['url'], '/robots.txt') ? ['status' => 404, 'headers' => [], 'body' => ''] : ['status' => 200, 'headers' => ['Content-Type' => 'text/html'], 'body' => (string) file_get_contents(__DIR__ . '/../fixtures/sources/walls/cloudflare.html')]]);
same('a bot wall on the sitemap → probe blocked', 'blocked', $jl->probe($walled)['state']);
same('… and pull blocked', 'blocked', pull_all($jl, $walled)[1]['status']);
$sm = inv_sitemap_parse('<?xml version="1.0"?><urlset><url><loc>https://a.example/p/1</loc></url><url><loc>https://a.example/p/2</loc></url></urlset>');
same('inv_sitemap_parse urlset', 2, count($sm['urls']));
$prods = inv_jsonld_products('<script type="application/ld+json">{"@type":"ItemList","itemListElement":[{"@type":"ListItem","item":{"@type":"Product","name":"A","offers":{"@type":"Offer","price":"1"}}},{"@type":"ListItem","item":{"@type":"Product","name":"B"}}]}</script>');
same('ItemList pages yield their products', ['A', 'B'], array_column($prods, 'name'));
$multi = inv_jsonld_listing(['@type' => 'Product', 'name' => 'Sheets', 'offers' => [['@type' => 'Offer', 'sku' => 'SH-Q', 'price' => '79', 'priceCurrency' => 'USD', 'availability' => 'InStock', 'name' => 'Queen'], ['@type' => 'Offer', 'sku' => 'SH-K', 'price' => '89', 'priceCurrency' => 'USD', 'availability' => 'OutOfStock', 'name' => 'King']]], 'https://a.example/sheets');
same('offers[] with distinct skus → one variant each', ['SH-Q', 'SH-K'], array_column($multi['variants'], 'sku'));
same('… each with its own size and state', ['queen', 'out_of_stock'], [$multi['variants'][0]['size_key'], $multi['variants'][1]['availability']]);
$used = inv_jsonld_listing(['@type' => 'Product', 'name' => 'Open-box King', 'sku' => 'OB-K', 'offers' => [['@type' => 'Offer', 'price' => '500', 'priceCurrency' => 'USD', 'itemCondition' => 'https://schema.org/UsedCondition'], ['@type' => 'Offer', 'price' => '900', 'priceCurrency' => 'USD', 'itemCondition' => 'https://schema.org/NewCondition']]], 'https://a.example/ob');
same('the new-condition offer is preferred', '900.00', $used['variants'][0]['price']);
same('… conditions remembered in raw', ['https://schema.org/UsedCondition', 'https://schema.org/NewCondition'], $used['raw']['conditions']);

// =====================================================================================================================
group('feed — CSV and XLSX, the column mapping, HTTPS and SFTP');
$fd = inv_connector('feed');
$mapping = ['supplier_sku' => 'Item Number', 'gtin' => 'UPC', 'name' => 'Description', 'size' => 'Size', 'cost' => 'Dealer Cost', 'map_price' => 'MAP', 'qty' => 'Qty Available', 'lead_time_days' => 'Lead Time (days)', 'brand' => 'Brand', 'product' => 'Model'];
$src = src('feed', ['settings' => ['url' => "$BASE/feed.csv", 'mapping' => $mapping, 'lead_time_days' => 14]]);
$p = $fd->probe($src);
same('probe ok', 'ok', $p['state']);
same('probe facts: rows and format', [7, 'csv', 'https'], [$p['facts']['rows'], $p['facts']['format'], $p['facts']['via']]);
same('probe facts: the columns', 10, count($p['facts']['columns']));
reqlog_reset();
[$ls, $st] = pull_all($fd, $src);
same('pull emits 3 listings (two models; the pillow alone; the empty row skipped)', 3, count($ls));
same('pull status ok with the skipped row noted', 'ok', $st['status']);
same('pull: rows_skipped', 1, $st['rows_skipped']);
check('pull: errors name the skipped row', count($st['errors']) === 1 && str_contains($st['errors'][0], 'skipped'));
same('pull: http_requests = 1 (no robots for a feed file)', 1, $st['http_requests']);
foreach ($ls as $i => $l) {
    check("listing $i has the normalized shape", inv_listing_valid($l, $why), (string) $why);
}
$cr = by_id($ls, 'CloudRest Hybrid');
same('feed: four variants under one model', 4, count($cr['variants']));
same('feed: vendor from the Brand column', 'Northwind Sleep', $cr['vendor']);
$t = variant($cr, 'NW-CR-T');
same('feed: cost_price from "$349.50"', '349.50', $t['cost_price']);
same('feed: no price column → null', null, $t['price']);
same('feed: qty 24 → in_stock', ['in_stock', 24], [$t['availability'], $t['qty']]);
same('feed: lead time from the row', 3, $t['lead_time_days']);
same('feed: barcode', '00850123450004', $t['barcode']);
same('feed: size column → option + size_key', [['Size' => 'Twin'], 'twin'], [$t['option_values'], $t['size_key']]);
same('feed: currency default USD', 'USD', $t['currency']);
same('feed: MAP rides in raw.map_price (not a §6.1 field)', '699.00', $cr['raw']['map_price']['NW-CR-T']);
same('feed: MAP "1,299.00" → 1299.00', '1299.00', $cr['raw']['map_price']['NW-CR-K']);
same('feed: qty 0 → out_of_stock', 'out_of_stock', variant($cr, 'NW-CR-Q')['availability']);
same('feed: empty lead time → the settings default', 14, variant($cr, 'NW-CR-K')['lead_time_days']);
same('feed: a wrong check digit → barcode null, sku kept', [null, 'NW-CR-CK'], [variant($cr, 'NW-CR-CK')['barcode'], variant($cr, 'NW-CR-CK')['sku']]);
same('feed: Cal King size_key', 'california_king', variant($cr, 'NW-CR-CK')['size_key']);
$pil = by_id($ls, 'NW-PIL-STD');
same('feed: a row without a model is its own listing keyed by sku', 'Northwind Down-Alternative Pillow', $pil['title'] ?? null);
same('feed: pillow qty 140 in_stock', 140, $pil['variants'][0]['qty']);
check('feed: a second pull is answered from the cache', pull_all($fd, $src)[1]['cached'] === 1);
// mapping by index and by letter
$byIndex = src('feed', ['settings' => ['url' => "$BASE/feed.csv", 'mapping' => ['supplier_sku' => 0, 'gtin' => 'B', 'cost' => '4', 'qty' => 6]]]);
[$li] = pull_all($fd, $byIndex);
same('mapping by index and column letter', ['NW-CR-T', '00850123450004', '349.50', 24], [$li[0]['variants'][0]['sku'], $li[0]['variants'][0]['barcode'], $li[0]['variants'][0]['cost_price'], $li[0]['variants'][0]['qty']]);
same('one listing per row without a product column', 6, count($li));
$missing = src('feed', ['settings' => ['url' => "$BASE/feed.csv", 'mapping' => ['supplier_sku' => 'Item Number', 'cost' => 'Wholesale']]]);
$pm = $fd->probe($missing);
same('a mapped column missing from the file → misconfigured', 'misconfigured', $pm['state']);
check('… naming it', str_contains($pm['message'], 'Wholesale'), $pm['message']);
same('… and the pull fails rather than guessing', 'failed', pull_all($fd, $missing)[1]['status']);
$nomap = $fd->probe(src('feed', ['settings' => ['url' => "$BASE/feed.csv"]]));
same('no mapping → misconfigured, with the columns for the screen', ['misconfigured', 'Item Number'], [$nomap['state'], $nomap['facts']['columns'][0] ?? null]);
$prot = src('feed', ['settings' => ['url' => "$BASE/feed-protected.csv", 'mapping' => $mapping]]);
$pp = $fd->probe($prot);
check('a protected file without a credential → misconfigured naming the 401', $pp['state'] === 'misconfigured' && str_contains($pp['message'], '401'), $pp['message']);
$prot['credential'] = ['kind' => 'basic', 'username' => 'dealer', 'password' => 's3cret-feed'];
same('… with a basic credential → ok', 'ok', $fd->probe($prot)['state']);
check('… the password is in no fact or message', !str_contains(json_encode($fd->probe($prot)), 's3cret'));
same('a non-https url is refused', 'misconfigured', $fd->probe(src('feed', ['settings' => ['url' => 'ftp://x.example/f.csv', 'mapping' => $mapping]]))['state']);
$pv = $fd->preview($src, 2);
same('preview: columns, two rows, mapped rows', [10, 2, 2, 7], [count($pv['columns']), count($pv['rows']), count($pv['mapped']), $pv['row_count']]);
same('preview: the first mapped row', ['NW-CR-T', '850123450004'], [$pv['mapped'][0]['supplier_sku'], $pv['mapped'][0]['gtin']]);
throws('search → InvNotSupported', InvNotSupported::class, static fn() => $fd->search($src, 'x'));
throws('lookup → InvNotSupported', InvNotSupported::class, static fn() => $fd->lookup($src, ['sku' => 'x']));
// CSV parsing details
$rows = inv_feed_rows("sku;name;qty\nA;\"Quoted; name\";3\n\n", []);
same('CSV: semicolon delimiter guessed, quotes honoured', [['A', 'Quoted; name', '3']], $rows['rows']);
$rows = inv_feed_rows("\xEF\xBB\xBFsku\tqty\nB\t4\n", ['delimiter' => 'tab']);
same('CSV: BOM stripped, tab delimiter', [['sku', 'qty'], [['B', '4']]], [$rows['columns'], $rows['rows']]);
$rows = inv_feed_rows("x,y\n1,2\n", ['has_header' => false]);
same('CSV: has_header false → generated column names', ['Column 1', 'Column 2'], $rows['columns']);
$rows = inv_feed_rows("Northwind price list\nsku,qty\nC,1\n", ['skip_rows' => 1]);
same('CSV: skip_rows', ['sku', 'qty'], $rows['columns']);
$rows = inv_feed_rows((string) mb_convert_encoding("sku,name\nD,Caf\u{e9}\n", 'Windows-1252', 'UTF-8'), []);
same('CSV: Windows-1252 converted', "Caf\u{e9}", $rows['rows'][0][1]);
// XLSX
check('zip extension present', class_exists('ZipArchive'));
check('simplexml present', function_exists('simplexml_load_string'));
$xlsx = make_xlsx([
    ['Item Number', 'UPC', 'Description', 'Size', 'Dealer Cost', 'Qty Available', 'In Stock'],
    ['NW-CR-Q', 850123450035, 'CloudRest Hybrid Mattress Queen', 'Queen', 499, 12, 'Y'],
    ['NW-CR-K', '850123450042', 'CloudRest Hybrid Mattress King', 'King', 649.5, 0, 'N'],
    ['HP-LT-K-3', '', 'Harbor Latex Topper King 3"', 'King', 249, 4, 'yes'],
]);
file_put_contents("$TMP/feed.xlsx", $xlsx);
$rows = inv_feed_read_xlsx($xlsx);
same('XLSX: four rows read', 4, count($rows));
same('XLSX: shared strings resolved', 'Item Number', $rows[0][0]);
same('XLSX: numbers as strings', ['850123450035', '499', '12'], [$rows[1][1], $rows[1][4], $rows[1][5]]);
same('XLSX: inline strings and a float', ['Harbor Latex Topper King 3"', '649.5'], [$rows[3][2], $rows[2][4]]);
$table = inv_feed_rows($xlsx, []);
same('inv_feed_rows detects XLSX by its bytes', 'xlsx', $table['format']);
$xs = src('feed', ['settings' => ['url' => "$BASE/feed.xlsx", 'mapping' => ['supplier_sku' => 'Item Number', 'gtin' => 'UPC', 'name' => 'Description', 'size' => 'Size', 'cost' => 'Dealer Cost', 'qty' => 'Qty Available', 'in_stock' => 'In Stock']]]);
same('XLSX over HTTPS: probe ok', 'ok', $fd->probe($xs)['state']);
[$lx, $sx] = pull_all($fd, $xs);
same('XLSX pull: 3 listings', 3, count($lx));
same('XLSX: numeric gtin cell → barcode', '00850123450035', $lx[0]['variants'][0]['barcode']);
same('XLSX: cost 499 → 499.00, 649.5 → 649.50', ['499.00', '649.50'], [$lx[0]['variants'][0]['cost_price'], $lx[1]['variants'][0]['cost_price']]);
same('XLSX: in_stock column Y/N beats qty', ['in_stock', 'out_of_stock', 'in_stock'], [$lx[0]['variants'][0]['availability'], $lx[1]['variants'][0]['availability'], $lx[2]['variants'][0]['availability']]);
same('XLSX: qty', [12, 0, 4], [$lx[0]['variants'][0]['qty'], $lx[1]['variants'][0]['qty'], $lx[2]['variants'][0]['qty']]);
// SFTP through a runner (no network): the plan is proven, not a server
$plan = null;
$runner = static function (array $p) use (&$plan): array {
    $plan = $p;
    return ['status' => 0, 'bytes' => (string) file_get_contents(__DIR__ . '/../fixtures/sources/feed/supplier.csv'), 'error' => null];
};
$ss = src('feed', ['settings' => ['sftp' => ['host' => 'sftp.supplier.example', 'port' => 2222, 'user' => 'dealer', 'path' => '/outbound/inventory.csv'], 'mapping' => $mapping], 'credential' => ['kind' => 'sftp_password', 'username' => 'dealer', 'password' => 'pa55word'], 'sftp_runner' => $runner]);
$ps = $fd->probe($ss);
same('SFTP (password) probe ok via the runner', ['ok', 'sftp'], [$ps['state'], $ps['facts']['via']]);
check('SFTP: curl plan uses a netrc file, the password is NOT on argv', $plan['tool'] === 'curl' && in_array('--netrc-file', $plan['argv'], true) && !str_contains(implode(' ', $plan['argv']), 'pa55word') && str_contains(implode(' ', $plan['argv']), 'sftp://sftp.supplier.example:2222/outbound/inventory.csv'), json_encode($plan['argv'] ?? null));
check('SFTP: the netrc file is planned 0600 with the password inside', count($plan['files']) === 1 && reset($plan['files']) === 0600);
[$lsf, $stf] = pull_all($fd, $ss);
same('SFTP pull emits the listings', [3, 'sftp', 0], [count($lsf), $stf['via'], $stf['http_requests']]);
$ss['credential'] = ['kind' => 'sftp_key', 'username' => 'dealer', 'private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfixture\n-----END OPENSSH PRIVATE KEY-----"];
$fd->probe($ss);
check('SFTP (key) plan uses --key with a 0600 key file', in_array('--key', $plan['argv'], true) && !str_contains(implode(' ', $plan['argv']), 'fixture'));
$ss['credential'] = null;
check('SFTP without a credential → misconfigured', $fd->probe($ss)['state'] === 'misconfigured');
$ss['credential'] = ['kind' => 'sftp_password', 'password' => 'x'];
$ss['settings']['sftp']['host'] = 'bad host; rm -rf /';
check('SFTP refuses a host a shell would read', $fd->probe($ss)['state'] === 'misconfigured');
check('an SFTP tool is reported (ssh2 / curl / sftp / none): ' . inv_feed_sftp_tool(), in_array(inv_feed_sftp_tool(), ['ssh2', 'curl', 'sftp', 'none'], true));
$caps = $fd->capabilities();
check('capabilities: gives cost and qty, no search, no lookup', $caps['gives_cost'] === true && $caps['gives_qty'] === true && !$caps['has_search'] && !$caps['has_lookup']);

// =====================================================================================================================
group('manual — typed entries, no network');
$mn = inv_connector('manual');
$src = src('manual', ['base_url' => '', 'settings' => ['currency' => 'USD', 'listings' => [
    ['title' => 'Sierra Pocket Coil Mattress', 'vendor' => 'Sierra Sleep', 'product_type' => 'Mattress', 'recorded_on' => '2026-10-03', 'note' => 'Phone call with Dana', 'lead_time_days' => 10, 'ships_how' => 'LTL',
        'variants' => [['size' => 'Queen', 'sku' => 'SS-PC-Q', 'gtin' => '850123450035', 'cost' => '420', 'price' => '899', 'qty' => 6, 'availability' => 'in stock'], ['size' => 'King', 'sku' => 'SS-PC-K', 'cost' => '520', 'availability' => 'backorder', 'lead_time_days' => 21]]],
    ['title' => 'Sierra Foundation Queen', 'sku' => 'SS-FND-Q', 'cost' => '95.5', 'qty' => 0, 'recorded_on' => '2026-10-03'],
    'not an entry',
]]]);
$p = $mn->probe($src);
same('probe ok', 'ok', $p['state']);
check('probe counts the entries and the latest date', $p['facts']['entries'] === 3 && $p['facts']['latest'] === '2026-10-03');
[$ls, $st] = pull_all($mn, $src);
same('pull emits the two real entries', 2, count($ls));
same('pull: the bad entry is an error, status partial', 'partial', $st['status']);
same('pull: no HTTP at all', [0, 0], [$st['http_requests'], $st['bytes']]);
foreach ($ls as $i => $l) {
    check("listing $i has the normalized shape", inv_listing_valid($l, $why), (string) $why);
}
$s = $ls[0];
same('manual: external_id from the first sku', 'SS-PC-Q', $s['external_id']);
same('manual: vendor and type', ['Sierra Sleep', 'Mattress'], [$s['vendor'], $s['product_type']]);
same('manual: raw holds the date and the note', ['2026-10-03', 'Phone call with Dana'], [$s['raw']['recorded_on'], $s['raw']['note']]);
same('manual: two variants by size', ['queen', 'king'], array_column($s['variants'], 'size_key'));
same('manual: cost, price, qty, availability', ['420.00', '899.00', 6, 'in_stock'], [$s['variants'][0]['cost_price'], $s['variants'][0]['price'], $s['variants'][0]['qty'], $s['variants'][0]['availability']]);
same('manual: gtin normalized', '00850123450035', $s['variants'][0]['barcode']);
same('manual: lead time from the entry, overridden per variant', [10, 21], [$s['variants'][0]['lead_time_days'], $s['variants'][1]['lead_time_days']]);
same('manual: ships_how inherited', ['ltl', 'ltl'], [$s['variants'][0]['ships_how'], $s['variants'][1]['ships_how']]);
same('manual: backorder word', 'back_order', $s['variants'][1]['availability']);
same('manual: a flat entry is one variant, qty 0 → out_of_stock', ['SS-FND-Q', 'out_of_stock', '95.50'], [$ls[1]['variants'][0]['sku'], $ls[1]['variants'][0]['availability'], $ls[1]['variants'][0]['cost_price']]);
same('manual: an empty source is ok', 'ok', $mn->probe(src('manual', ['settings' => []]))['state']);
throws('search → InvNotSupported', InvNotSupported::class, static fn() => $mn->search($src, 'x'));
throws('lookup → InvNotSupported', InvNotSupported::class, static fn() => $mn->lookup($src, ['sku' => 'x']));

// =====================================================================================================================
group('registry — the five connectors (D7)');
same('the five keys, in order', ['shopify', 'woocommerce', 'jsonld', 'feed', 'manual'], array_keys(inv_connectors()));
foreach (inv_connectors() as $key => $def) {
    check("$key: a class implementing InvConnector with a label and capabilities", inv_connector($key) instanceof InvConnector && $def['label'] !== '' && count($def['capabilities']) === 8);
}
throws('an unknown key → InvMisconfigured', InvMisconfigured::class, static fn() => inv_connector('ebay'));
check('every connector answers the eight capability keys', count(array_filter(inv_connectors(), static fn($d) => array_keys($d['capabilities']) === ['has_search', 'has_lookup', 'gives_qty', 'gives_cost', 'needs_credential', 'is_reference', 'lookup_by', 'credential_kinds'])) === 5);
check('no v1 connector is a reference', count(array_filter(inv_connectors(), static fn($d) => $d['capabilities']['is_reference'])) === 0);

// =====================================================================================================================
echo "\n{$GLOBALS['pass']} ok, {$GLOBALS['fail']} FAIL\n";
exit($GLOBALS['fail'] === 0 ? 0 : 1);

/** A minimal XLSX (shared strings for text in even rows, inline strings in the last, numbers as numbers). */
function make_xlsx(array $rows): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'mkx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $shared = [];
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    foreach ($rows as $r => $row) {
        $sheet .= '<row r="' . ($r + 1) . '">';
        foreach ($row as $c => $val) {
            $ref = chr(65 + $c) . ($r + 1);
            if ($val === '' || $val === null) {
                continue;
            }
            if (is_int($val) || is_float($val)) {
                $sheet .= '<c r="' . $ref . '"><v>' . $val . '</v></c>';
            } elseif ($r === count($rows) - 1) {
                $sheet .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . htmlspecialchars((string) $val, ENT_XML1) . '</t></is></c>';
            } else {
                $idx = array_search($val, $shared, true);
                if ($idx === false) {
                    $shared[] = (string) $val;
                    $idx = count($shared) - 1;
                }
                $sheet .= '<c r="' . $ref . '" t="s"><v>' . $idx . '</v></c>';
            }
        }
        $sheet .= '</row>';
    }
    $sheet .= '</sheetData></worksheet>';
    $ss = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($shared) . '" uniqueCount="' . count($shared) . '">';
    foreach ($shared as $s) {
        $ss .= '<si><t>' . htmlspecialchars($s, ENT_XML1) . '</t></si>';
    }
    $ss .= '</sst>';
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Inventory" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->addFromString('xl/sharedStrings.xml', $ss);
    $zip->close();
    $bytes = (string) file_get_contents($tmp);
    unlink($tmp);
    return $bytes;
}

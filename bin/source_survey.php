#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * The live survey of candidate stores (design §0.2, recorded in §16 and seeded as source_templates): which of them
 * expose Shopify's products.json, WooCommerce's Store API, schema.org Product markup on the home page, a sitemap —
 * probed POLITELY through app/sources/http.php (robots.txt first, one request a second per host, an honest user-agent,
 * ETag cache, a 403/429/bot wall recorded as blocked and never retried).
 *
 *   php bin/source_survey.php                       the candidate list of design §0.2
 *   php bin/source_survey.php casper.com zinus.com  given hosts
 *   php bin/source_survey.php --file hosts.txt      one host per line (# comments)
 *   --json        the record for §16 (one object per host) instead of the table
 *   --rate N      requests a second per host (default 1; never more than 2)
 *   --ua "…"      the user-agent (default INV_CRAWL_USER_AGENT, else a survey UA naming this repository)
 *   --timeout S   per request (default 15)
 *   --record      with a database (the kit's db()): each surveyed host updates its template's survey_result (open when the Shopify column is
 *                 open; blocked when blocked and the platform recognised; not_platform when no and not recognised; unverified otherwise) and
 *                 surveyed_at, matched by host against base_url; prints what changed; logged source.update on the template (source cron).
 *                 Never from the planning sandbox, whose rate limit answers for the sites (connectors.md §8).
 *
 * Needs no database and no kernel. Exit 0 when every host answered something; 1 when none could be reached (a sandbox
 * that rate-limits outbound HTTP shows as transport errors — run it from a shell on the build server).
 */

require_once __DIR__ . '/../app/sources/registry.php';

const SURVEY_DEFAULT_HOSTS = [
    'casper.com', 'brooklynbedding.com', 'tuftandneedle.com', 'avocadogreenmattress.com', 'bearmattress.com', 'maloufhome.com',
    'zinus.com', 'lucidmattress.com', 'nolahmattress.com', 'nestbedding.com', 'plushbeds.com', 'naturepedic.com', 'winkbeds.com', 'laylasleep.com',
];

$args = array_slice($argv, 1);
$json = false;
$record = false;
$rate = 1.0;
$timeout = 15;
$ua = null;
$hosts = [];
for ($i = 0; $i < count($args); $i++) {
    $a = $args[$i];
    if ($a === '--json') {
        $json = true;
    } elseif ($a === '--record') {
        $record = true;
    } elseif ($a === '--rate') {
        $rate = min(2.0, max(0.2, (float) ($args[++$i] ?? 1)));
    } elseif ($a === '--timeout') {
        $timeout = max(3, (int) ($args[++$i] ?? 15));
    } elseif ($a === '--ua') {
        $ua = (string) ($args[++$i] ?? '');
    } elseif ($a === '--file') {
        $file = (string) ($args[++$i] ?? '');
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim(preg_replace('~#.*$~', '', $line) ?? '');
            if ($line !== '') {
                $hosts[] = $line;
            }
        }
    } elseif ($a === '--help' || $a === '-h') {
        preg_match('~/\*\*(.*?)\*/~s', (string) file_get_contents(__FILE__), $m);
        fwrite(STDOUT, trim(preg_replace('~^\s*\* ?~m', '', $m[1] ?? '') ?? '') . "\n");
        exit(0);
    } elseif (str_starts_with($a, '--')) {
        fwrite(STDERR, "unknown option $a\n");
        exit(2);
    } else {
        $hosts[] = $a;
    }
}
if ($hosts === []) {
    $hosts = SURVEY_DEFAULT_HOSTS;
}
$hosts = array_values(array_unique(array_map(static fn($h) => strtolower(preg_replace('~^https?://|/.*$~', '', trim($h)) ?? ''), $hosts)));
$ua = $ua ?: (getenv('INV_CRAWL_USER_AGENT') ?: 'MaluDB-Inventory-Survey/0.1 (+https://github.com/maludb/maludb-os-inventory; a one-time catalog-endpoint check, 1 request/s)');
$cacheDir = sys_get_temp_dir() . '/inv-survey-cache';

$rows = [];
$reached = 0;
foreach ($hosts as $host) {
    $http = new InvHttp(['user_agent' => $ua, 'rate_per_second' => $rate, 'cache_dir' => $cacheDir, 'timeout' => $timeout, 'max_bytes' => 2 * 1024 * 1024]);
    $base = (preg_match('~^(127\.0\.0\.1|localhost)(:\d+)?$~', $host) ? 'http' : 'https') . "://$host";      // a local fixture server speaks http
    $row = ['host' => $host, 'robots' => null, 'crawl_delay' => null, 'shopify' => null, 'woocommerce' => null, 'jsonld' => null, 'sitemap' => null, 'note' => [], 'checked_at' => gmdate('c')];
    $robots = $http->robotsFor($base . '/');
    $row['robots'] = $robots['state'];
    $row['crawl_delay'] = $robots['crawl_delay'];
    $verdict = static function (array $r, callable $isIt) use (&$row): string {
        if ($r['blocked']) {
            $row['note'][] = $r['reason'];
            return 'blocked';
        }
        if ($r['skipped']) {
            return 'robots';
        }
        if (!$r['ok']) {
            if ($r['status'] === 0) {
                $row['note'][] = (string) $r['reason'];
                return 'error';
            }
            return 'no';
        }
        return $isIt($r) ? 'open' : 'no';
    };
    // Shopify (a Shopify storefront answers a non-browser user-agent's products.json with 429 TOO_MANY_REQUESTS — its
    // headers still name the platform, so a blocked store is recorded as "platform:shopify" for the template seed)
    $r = $http->get($base . '/products.json?limit=1');
    if (isset($r['headers']['x-shopid']) || isset($r['headers']['x-shopify-stage']) || (isset($r['headers']['x-dc']) && preg_match('~^[0-9a-f-]{36}-\d+$~', $r['headers']['x-request-id'] ?? ''))
        || preg_match('~shopify~i', $r['headers']['powered-by'] ?? '') || str_contains($r['body'], 'TOO_MANY_REQUESTS')) {
        $row['note'][] = 'platform:shopify';
    }
    $row['shopify'] = $verdict($r, static function ($r) use (&$row) {
        $d = inv_http_json($r);
        if (is_array($d) && array_key_exists('products', $d)) {
            $row['note'][] = 'shopify:' . (count($d['products']) > 0 ? 'products' : 'empty');
            return true;
        }
        return false;
    });
    // WooCommerce (a WordPress site names itself in the Link header even when the Store API is off)
    $r = $http->get($base . '/wp-json/wc/store/v1/products?per_page=1');
    if (preg_match('~wp-json~', $r['headers']['link'] ?? '') || isset($r['headers']['x-wp-total'])) {
        $row['note'][] = 'platform:wordpress';
    }
    $row['woocommerce'] = $verdict($r, static function ($r) use (&$row) {
        $d = inv_http_json($r);
        if (is_array($d) && array_is_list($d)) {
            $row['note'][] = 'woo:' . (isset($r['headers']['x-wp-total']) ? $r['headers']['x-wp-total'] . ' products' : 'list');
            return true;
        }
        return false;
    });
    // JSON-LD on the home page
    $r = $http->get($base . '/', ['accept' => 'text/html, */*;q=0.5']);
    $row['jsonld'] = $verdict($r, static function ($r) use (&$row) {
        $blocks = preg_match_all('~<script[^>]*type\s*=\s*["\']application/ld\+json["\']~i', $r['body']);
        $products = count(inv_jsonld_products($r['body']));
        if ($blocks > 0) {
            $row['note'][] = "ld+json:$blocks" . ($products > 0 ? " (product on home)" : '');
        }
        $final = parse_url($r['url'], PHP_URL_HOST);
        if ($final && strtolower((string) $final) !== $row['host']) {
            $row['note'][] = 'home→' . $final;
        }
        return $blocks > 0;
    });
    // sitemap
    $sm = $robots['robots']['sitemaps'][0] ?? ($base . '/sitemap.xml');
    $r = $http->get($sm, ['accept' => 'application/xml, text/xml;q=0.9, */*;q=0.5']);
    $row['sitemap'] = $verdict($r, static function ($r) use (&$row, $sm) {
        if (preg_match('~<(sitemapindex|urlset)~i', $r['body'])) {
            $p = inv_sitemap_parse($r['body']);
            $row['note'][] = 'sitemap:' . ($p['sitemaps'] !== [] ? count($p['sitemaps']) . ' children' : count($p['urls']) . ' urls') . (str_contains($sm, 'robots') ? '' : '');
            return true;
        }
        return false;
    });
    if ($row['robots'] === 'blocked') {
        $row['note'][] = 'robots.txt itself blocked';
    } elseif ($row['crawl_delay'] !== null) {
        $row['note'][] = 'crawl-delay ' . $row['crawl_delay'];
    }
    $facts = $http->facts();
    $row['requests'] = $facts['http_requests'];
    $row['note'] = array_values(array_unique($row['note']));
    if (in_array('open', [$row['shopify'], $row['woocommerce'], $row['jsonld'], $row['sitemap']], true) || in_array('blocked', [$row['shopify'], $row['woocommerce'], $row['jsonld'], $row['sitemap']], true) || $row['shopify'] === 'no') {
        $reached++;
    }
    $rows[] = $row;
    if (!$json) {
        fwrite(STDERR, '.');
    }
}
if (!$json) {
    fwrite(STDERR, "\n");
}

if ($json) {
    echo json_encode(['surveyed_at' => gmdate('c'), 'user_agent' => $ua, 'rate_per_second' => $rate, 'hosts' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
} else {
    $w = max(array_map(static fn($r) => strlen($r['host']), $rows)) + 1;
    printf("%-{$w}s %-9s %-12s %-8s %-8s %s\n", 'host', 'shopify', 'woocommerce', 'jsonld', 'sitemap', 'note');
    foreach ($rows as $r) {
        printf("%-{$w}s %-9s %-12s %-8s %-8s %s\n", $r['host'], $r['shopify'], $r['woocommerce'], $r['jsonld'], $r['sitemap'], implode('; ', $r['note']));
    }
    echo "\n", count($rows), " hosts; open/blocked/no = the connector's answer; robots = robots.txt disallows the path; error = no answer (a sandbox that rate-limits outbound HTTP shows here).\n";
}
if ($record) {
    require_once __DIR__ . '/../app/bootstrap.php';
    $pdo = db();
    $GLOBALS['__public_door'] = 'cron';
    $templates = $pdo->query('SELECT id, key, base_url, survey_result FROM source_templates WHERE base_url IS NOT NULL')->fetchAll();
    $hostOf = static function (string $url): string {
        $p = parse_url($url);
        return preg_replace('~^www\.~', '', strtolower((string) ($p['host'] ?? ''))) . (isset($p['port']) ? ':' . $p['port'] : '');
    };
    $changed = 0;
    foreach ($rows as $r) {
        $platform = in_array('platform:shopify', $r['note'], true);
        $result = match (true) {
            $r['shopify'] === 'open' => 'open',
            $r['shopify'] === 'blocked' && $platform => 'blocked',
            $r['shopify'] === 'no' && !$platform => 'not_platform',
            default => 'unverified',
        };
        foreach ($templates as $t) {
            if ($hostOf((string) $t['base_url']) !== preg_replace('~^www\.~', '', $r['host'])) { continue; }
            $pdo->prepare('UPDATE source_templates SET survey_result = :r, surveyed_at = now() WHERE id = :id')->execute(['r' => $result, 'id' => $t['id']]);
            log_activity($pdo, 'source.update', 'source_template', (int) $t['id'], ['actor_member_id' => null, 'source' => 'cron', 'after' => ['key' => $t['key'], 'survey_result' => $result, 'was' => $t['survey_result']]]);
            fwrite(STDOUT, sprintf("recorded %s: %s → %s\n", $t['key'], $t['survey_result'], $result));
            $changed++;
        }
    }
    fwrite(STDOUT, $changed . " template" . ($changed === 1 ? '' : 's') . " recorded.\n");
}
exit($reached > 0 ? 0 : 1);

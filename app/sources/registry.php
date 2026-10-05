<?php
declare(strict_types=1);

/**
 * The connector registry — THE ONLY PLACE the five v1 connectors (design §13 D7) are listed — and the module's front
 * door: require_once this file and the whole of app/sources/ is loaded (the interface, the normalizer, the HTTP client
 * with the crawl policy, robots, credentials and the five classes). A new connector is one class here, one fixture
 * under tests/fixtures/sources/, one source_templates row — never a change to the worker.
 */

require_once __DIR__ . '/Connector.php';
require_once __DIR__ . '/normalize.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/credentials.php';
require_once __DIR__ . '/connectors/shopify.php';
require_once __DIR__ . '/connectors/woocommerce.php';
require_once __DIR__ . '/connectors/jsonld.php';
require_once __DIR__ . '/connectors/feed.php';
require_once __DIR__ . '/connectors/manual.php';

/** key → ['class', 'label', 'description', 'capabilities'] in the order the screens list them. */
function inv_connectors(): array
{
    static $list = null;
    if ($list === null) {
        $defs = [
            'shopify' => [InvConnectorShopify::class, 'Shopify store', 'A Shopify store\'s public catalog (products.json); a Storefront token adds quantities.'],
            'woocommerce' => [InvConnectorWooCommerce::class, 'WooCommerce store', 'A WooCommerce store\'s public Store API (/wp-json/wc/store/v1).'],
            'jsonld' => [InvConnectorJsonLd::class, 'Marked-up site (schema.org)', 'Any site whose product pages carry Product / Offer JSON-LD; read from its sitemap or a list of URLs, one page a time.'],
            'feed' => [InvConnectorFeed::class, 'Supplier feed (CSV / XLSX)', 'A supplier\'s inventory file on HTTPS or SFTP with a column mapping; the only connector that knows cost and true quantity.'],
            'manual' => [InvConnectorManual::class, 'Typed by a person', 'What a supplier said on the phone or in a price sheet, typed in with a date.'],
        ];
        $list = [];
        foreach ($defs as $key => [$class, $label, $description]) {
            $list[$key] = ['key' => $key, 'class' => $class, 'label' => $label, 'description' => $description, 'capabilities' => (new $class())->capabilities()];
        }
    }
    return $list;
}

/** The connector for a key; InvMisconfigured for one that does not exist (the Extended ones included, until added). */
function inv_connector(string $key): InvConnector
{
    $list = inv_connectors();
    if (!isset($list[$key])) {
        throw new InvMisconfigured('no connector "' . $key . '" (v1 ships ' . implode(', ', array_keys($list)) . ')');
    }
    $class = $list[$key]['class'];
    return new $class();
}

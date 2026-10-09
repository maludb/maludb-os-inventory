<?php
declare(strict_types=1);

/**
 * The shell's menu — ONE table (sso-shell.md "Inventory is not Spaces"): the sidebar and the phone's tab bar read it, so a menu
 * item and the right that opens its screen can never disagree. An item shows only when the person holds its right (several
 * joined by "|": any one). The ids ARE the manifest's screen ids (Phase 1); until a screen is built its controller calls
 * render_nav_stub() and the item opens a page naming the slice that builds it.
 *   [id, url, icon, label, right]
 */
function nav_groups(): array
{
    return [
        'Inventory' => [
            ['home', '/',     'feather-home',   'Home', 'inventory.read'],
            ['find', '/find', 'feather-search', 'Find', 'inventory.read'],
        ],
        'Catalog' => [
            ['product-list',      '/products/',      'feather-package',      'Products',      'inventory.read'],
            ['brand-list',        '/brands/',        'feather-tag',          'Brands',        'inventory.read'],
            ['product-type-list', '/product-types/', 'feather-grid',         'Product types', 'inventory.read'],
            ['catalog-gaps',      '/catalog/gaps',   'feather-alert-circle', 'Catalog gaps',  'catalog.write'],
            ['catalog-import',    '/catalog/import', 'feather-upload',       'Import',        'catalog.write'],
        ],
        'Stock' => [
            ['stock-levels',     '/stock/',             'feather-layers',       'Levels',       'inventory.read'],
            ['location-list',    '/locations/',         'feather-map-pin',      'Locations',    'inventory.read'],
            ['movement-list',    '/stock/movements',    'feather-list',         'Movements',    'inventory.read'],
            ['receipt-list',     '/receipts/',          'feather-download',     'Receive',      'stock.receive'],
            ['adjustment-list',  '/adjustments/',       'feather-edit-3',       'Adjust',       'stock.adjust'],
            ['transfer-list',    '/transfers/',         'feather-repeat',       'Transfers',    'stock.transfer'],
            ['count-list',       '/counts/',            'feather-check-square', 'Counts',       'stock.count'],
            ['floor-model-list', '/stock/floor-models', 'feather-home',         'Floor models', 'inventory.read'],
        ],
        'Sources' => [
            ['source-list',          '/sources/',          'feather-rss',       'Sources',      'inventory.read'],
            ['source-template-list', '/sources/templates', 'feather-book-open', 'Templates',    'sources.write'],
            ['match-queue',          '/matching/',         'feather-link',      'Match queue',  'listings.match'],
            ['supplier-item-list',   '/supplier-items/',   'feather-file-text', 'Price sheets', 'inventory.read'],
            ['watch-list',           '/watches/',          'feather-eye',       'Watches',      'watches.own'],
        ],
        'Orders' => [
            ['order-list',       '/orders/',      'feather-shopping-cart', 'Orders',           'inventory.read'],
            ['fulfilment-today', '/orders/today', 'feather-truck',         'Fulfilment today', 'inventory.read'],
            ['shipment-list',    '/shipments/',   'feather-send',          'Shipments',        'inventory.read'],
            ['customer-list',    '/customers/',   'feather-users',         'Customers',        'inventory.read'],
        ],
        'Purchasing' => [
            ['purchase-order-list', '/purchasing/', 'feather-clipboard', 'Purchase orders', 'inventory.read'],
            ['supplier-list',       '/suppliers/',  'feather-briefcase', 'Suppliers',       'inventory.read'],
        ],
        'Returns' => [
            ['return-list',   '/returns/',   'feather-corner-up-left', 'Returns',         'inventory.read'],
            ['proposal-list', '/proposals/', 'feather-inbox',          'Buyer proposals', 'reports.read|agents.settings'],
        ],
        'Reports' => [
            ['report-list', '/reports/', 'feather-bar-chart-2',    'Reports', 'reports.read'],
            ['export-list', '/exports/', 'feather-download-cloud', 'Exports', 'reports.read|exports.all'],
        ],
        'Me' => [
            ['my-settings',   '/settings/',        'feather-settings', 'My settings',   'inventory.read'],
            ['notifications', '/notifications',    'feather-bell',     'Notifications', 'inventory.read'],
            ['tokens',        '/settings/tokens/', 'feather-key',      'Tokens',        'inventory.read'],
            ['trail',         '/trail',            'feather-activity', 'My trail',      'inventory.read'],
        ],
        'Admin' => [
            ['admin-settings',   '/admin/settings',      'feather-sliders',  'Settings',     'settings.manage'],
            ['sequence-list',    '/admin/sequences',     'feather-hash',     'Sequences',    'sequences.manage'],
            ['tax-rate-list',    '/admin/tax-rates/',    'feather-percent',  'Tax rates',    'settings.manage'],
            ['reason-code-list', '/admin/reason-codes/', 'feather-bookmark', 'Reason codes', 'settings.manage'],
            ['feed-key-list',    '/admin/feed-keys/',    'feather-key',      'Feed keys',    'feed.keys'],
            ['price-list-list',  '/admin/price-lists/',  'feather-percent',  'Price lists',  'feed.keys'],
            ['agent-list',       '/admin/agents',        'feather-cpu',      'Agents',       'agents.settings'],
            ['dispatch-list',    '/admin/dispatches',    'feather-zap',      'Dispatches',   'agents.settings'],
            ['connection-list',  '/admin/connections',   'feather-share-2',  'Connections',  'settings.manage'],
        ],
    ];
}

/** The phone's tabs (design §9, sso-shell.md): Home · Find · Orders · Stock · Me — every one inventory.read. */
function nav_tabs(): array
{
    return [
        ['home',         '/',          'feather-home',          'Home',   'inventory.read'],
        ['find',         '/find',      'feather-search',        'Find',   'inventory.read'],
        ['order-list',   '/orders/',   'feather-shopping-cart', 'Orders', 'inventory.read'],
        ['stock-levels', '/stock/',    'feather-layers',        'Stock',  'inventory.read'],
        ['my-settings',  '/settings/', 'feather-user',          'Me',     'inventory.read'],
    ];
}

function nav_item(string $id): ?array
{
    foreach (nav_groups() as $items) {
        foreach ($items as $i) {
            if ($i[0] === $id) {
                return $i;
            }
        }
    }
    return null;
}

/** A menu right may name several joined by "|": any one of them opens the item. */
function nav_has_right(string $spec): bool
{
    foreach (explode('|', $spec) as $right) {
        if (has_right($right)) {
            return true;
        }
    }
    return false;
}

/**
 * The highest Inventory role the member holds, in words — the header's badge (db/004): Inventory admin › Buyer › Warehouse › Sales ›
 * Viewer; a super-admin "Super-admin". Read through inv_member_roles() — the EFFECTIVE roles, so a member the kernel sent no roles for
 * shows the role their capability implies.
 */
function role_badge(): string
{
    $m = current_member();
    if ($m === null) {
        return '';
    }
    if (($m['business_role'] ?? '') === 'super_admin') {
        return 'Super-admin';
    }
    $held = array_column(my_roles(db(), (int) $m['id']), 'role_key');
    foreach (['admin' => 'Inventory admin', 'buyer' => 'Buyer', 'warehouse' => 'Warehouse', 'user' => 'Sales', 'viewer' => 'Viewer'] as $key => $label) {
        if (in_array($key, $held, true)) {
            return $label;
        }
    }
    return match ($m['capability'] ?? '') { 'admin' => 'Inventory admin', 'write' => 'Sales', default => 'Viewer' };
}

/** The badge's colour (sso-shell.md "Status vocabulary"). */
function role_badge_kind(string $badge): string
{
    return match ($badge) { 'Super-admin', 'Inventory admin' => 'danger', 'Buyer' => 'primary', 'Warehouse' => 'info', 'Sales' => 'secondary', default => 'light' };
}

/** A PostgreSQL text[] literal ({a,b}) as a PHP list; quoted elements are unquoted. */
function pg_text_array(string $literal): array
{
    $literal = trim($literal);
    if ($literal === '' || $literal === '{}') {
        return [];
    }
    $out = [];
    foreach (str_getcsv(substr($literal, 1, -1), ',', '"', '\\') as $v) {
        if ($v !== null && $v !== '') {
            $out[] = $v;
        }
    }
    return $out;
}

/** A link that navigates by HTMX into #page-content and still works as a plain link (progressive enhancement). $html is already escaped. */
function hx_link(string $url, string $html, string $class = '', string $extra = ''): string
{
    return '<a href="' . e($url) . '"' . ($class !== '' ? ' class="' . e($class) . '"' : '') . ($extra !== '' ? ' ' . $extra : '')
        . ' hx-get="' . e($url) . '" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="' . e($url) . '">' . $html . '</a>';
}

/** "Back to …" from the ?back= a link carried (click-around rule): [url, label] or null. Later slices add their record pages here. */
function back_link(): ?array
{
    $back = safe_local_path($_GET['back'] ?? null);
    if ($back === null) {
        return null;
    }
    $path = parse_url($back, PHP_URL_PATH) ?: '/';
    foreach (nav_groups() as $items) {
        foreach ($items as $i) {
            if ($i[1] === $path) {
                return [$back, $i[3]];
            }
        }
    }
    foreach (['products' => 'the product', 'variants' => 'the variant', 'brands' => 'the brand', 'product-types' => 'the product types', 'catalog' => 'the catalog', 'locations' => 'the location', 'sources' => 'the source', 'listings' => 'the listing',
              'suppliers' => 'the supplier', 'customers' => 'the customer', 'orders' => 'the order', 'purchasing' => 'the purchase order', 'returns' => 'the return',
              'receipts' => 'the receipt', 'transfers' => 'the transfer', 'adjustments' => 'the adjustment', 'counts' => 'the count', 'shipments' => 'the shipment', 'watches' => 'the watch'] as $dir => $label) {
        if (preg_match('#^/' . $dir . '/\d+(/[a-z_-]+)?$#', $path)) {
            return [$back, $label];
        }
    }
    return null;
}

/** The URL of this same page, for a ?back= (path and query as requested). */
function here_url(): string
{
    return (string) ($_SERVER['REQUEST_URI'] ?? '/');
}

function with_back(string $url, string $here): string
{
    return $url . (str_contains($url, '?') ? '&' : '?') . 'back=' . rawurlencode($here);
}

/** The path of the current request, for the sidebar's highlight. */
function current_path(): string
{
    return (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
}

/**
 * The screen a notification or a trail row points at, by the record it names (null when none). The route map of the click-around
 * rule: one place. Later slices build the pages; the links are right from the start.
 */
function record_url(?string $type, int|string|null $id): ?string
{
    if ($type === null || $id === null || $id === '') {
        return null;
    }
    $id = (int) $id;
    return match ($type) {
        'product' => '/products/' . $id,
        'product_variant', 'variant' => '/variants/' . $id,
        'brand' => '/brands/' . $id,
        'location' => '/locations/' . $id,
        'source' => '/sources/' . $id,
        'listing' => '/listings/' . $id,
        'listing_variant' => '/listings/' . $id,
        'supplier' => '/suppliers/' . $id,
        'customer' => '/customers/' . $id,
        'sales_order', 'order' => '/orders/' . $id,
        'purchase_order' => '/purchasing/' . $id,
        'return', 'return_authorization' => '/returns/' . $id,
        'goods_receipt', 'receipt' => '/receipts/' . $id,
        'inventory_transfer', 'transfer' => '/transfers/' . $id,
        'inventory_adjustment', 'adjustment' => '/adjustments/' . $id,
        'inventory_count', 'count' => '/counts/' . $id,
        'shipment' => '/shipments/' . $id,
        'watch' => '/watches/' . $id,
        'proposal', 'buyer_proposal' => '/proposals/' . $id,
        'dispatch', 'agent_dispatch' => '/admin/dispatches',
        default => null,
    };
}

/** The slice that builds each not-yet-built menu item, in words — the placeholders and the registry read it (sso-shell.md). */
const NAV_SLICES = [
    'product-list' => 'slice 1', 'brand-list' => 'slice 1', 'product-type-list' => 'slice 1', 'catalog-gaps' => 'slice 1', 'catalog-import' => 'slice 1',
    'stock-levels' => 'slice 2', 'location-list' => 'slice 2', 'movement-list' => 'slice 2', 'receipt-list' => 'slice 2', 'adjustment-list' => 'slice 2',
    'transfer-list' => 'slice 2', 'count-list' => 'slice 2', 'floor-model-list' => 'slice 2',
    'source-list' => 'slice 3', 'source-template-list' => 'slice 3', 'match-queue' => 'slice 3', 'supplier-item-list' => 'slice 3',
    'find' => 'slice 4', 'watch-list' => 'slice 4',
    'order-list' => 'slice 5', 'fulfilment-today' => 'slice 5', 'shipment-list' => 'slice 5', 'customer-list' => 'slice 5',
    'purchase-order-list' => 'slice 6', 'supplier-list' => 'slice 6',
    'feed-key-list' => 'slice 7', 'price-list-list' => 'slice 7', 'connection-list' => 'slice 7',
    'return-list' => 'slice 8', 'proposal-list' => 'slice 8', 'dispatch-list' => 'slice 8',
    'report-list' => 'slice 9', 'export-list' => 'slice 9', 'admin-settings' => 'slice 9', 'sequence-list' => 'slice 9', 'tax-rate-list' => 'slice 9',
    'reason-code-list' => 'slice 9', 'agent-list' => 'slice 9',
];

/**
 * A screen of the manifest that its slice has not built yet (Phase 2): the shell, the page header, one card saying which slice
 * builds it — 200 after the right has been checked; 403 in the right's words; 501 `not_built` to JSON and to a POST.
 * bin/build_action_registry.php reads a controller that calls this as unbuilt, so the voice surface never offers the screen.
 * Removing the call is the slice's first step.
 */
function render_nav_stub(string $navId, string $slice, string $right): void
{
    $item = nav_item($navId);
    $title = $item[3] ?? ucfirst(str_replace('-', ' ', $navId));
    require_login();
    if (!nav_has_right($right)) {
        require_right(explode('|', $right)[0]);        // refuses in that right's sentence
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        emit_action_status(false, ['error' => 'This is built by ' . $slice . '.']);
        if (wants_json()) {
            json_error('not_built', 'This is built by ' . $slice . '.', 501);
        }
        http_response_code(501);
        echo view('shared/message.php', ['title' => 'Not built yet', 'message' => 'This is built by ' . $slice . '.']);
        exit;
    }
    log_screen_view(db(), $navId);
    if (wants_json()) {
        json_error('not_built', 'This screen is built by ' . $slice . '.', 501);
    }
    $html = view('shared/header.php', ['id' => $navId, 'title' => $title, 'crumbs' => [['Home', '/'], [$title, null]]])
        . '<div class="main-content" id="' . e($navId) . '-content"><div class="card coming-card" id="' . e($navId) . '-coming"><div class="card-body">'
        . '<div class="empty-state"><span class="avatar-text avatar-lg rounded"><i class="' . e($item[2] ?? 'feather-box') . '"></i></span>'
        . '<div><div class="fw-semibold">' . e($title) . ' is not built yet</div><div class="fs-12 text-muted">' . e($slice) . ' builds this screen. Nothing is lost: what belongs here will appear when it ships.</div></div></div>'
        . '</div></div></div>';
    render_screen($title, $html, ['activeNav' => $navId, 'screen' => $navId]);
}

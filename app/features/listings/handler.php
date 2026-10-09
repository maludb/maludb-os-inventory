<?php
declare(strict_types=1);

/** Listings' prelude (sources.md "Handlers"): require_matcher(), listing_log() — source_id on every row — and the readers. */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__) . '/sources/handler.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/write.php';

function require_matcher(): void
{
    inv_handler_begin();
    require_right('listings.match');
}

function listing_log(PDO $pdo, string $action, string $entityType, int $entityId, int $sourceId, array $after): void
{
    log_activity($pdo, $action, $entityType, $entityId, ['source_id' => $sourceId, 'after' => $after]);
}

function listing_or_404(PDO $pdo, ?int $id): array
{
    $l = $id === null ? null : find_listing($pdo, $id);
    if ($l === null) { refuse(404, 'Listing not found.'); }
    return $l;
}

function listing_variant_or_404(PDO $pdo, ?int $id): array
{
    $v = $id === null ? null : find_listing_variant($pdo, $id);
    if ($v === null) { refuse(404, 'Listing variant not found.'); }
    return $v;
}

/** The variant a request names (`variant`: an id, or a SKU an agent passes); field error otherwise. */
function listing_variant_from_request(PDO $pdo, array &$errors, bool $required = true): ?int
{
    $v = req_val('variant');
    if ($v === null || $v === '') {
        if ($required) { $errors['variant'] = 'Choose the variant.'; }
        return null;
    }
    $id = ctype_digit($v) ? one_value($pdo, 'SELECT variant_id FROM mcp_product_variants WHERE variant_id = :v', ['v' => (int) $v])
                          : one_value($pdo, 'SELECT variant_id FROM mcp_product_variants WHERE lower(sku) = lower(:s)', ['s' => $v]);
    if ($id === null) { $errors['variant'] = 'That variant is not here.'; return null; }
    return (int) $id;
}

/** The listing variant a request names (`listing_variant`). */
function listing_from_request(PDO $pdo): array
{
    return listing_variant_or_404($pdo, request_integer('listing_variant') ?? request_integer('listing_variant_id'));
}

/** Where a listing action lands: `return_to` (the queue), else the listing with the variant selected. */
function listing_land(array $lv, string $notice): string
{
    return inv_land(return_path('/listings/' . $lv['listing_id'] . '?listing_variant=' . $lv['listing_variant_id']), $notice, 'listing-variant-row-' . $lv['listing_variant_id']);
}

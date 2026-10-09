<?php
declare(strict_types=1);

/** The price sheet's prelude: the row's readers (cost only for a caller who sees cost — refused in words otherwise). */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__) . '/catalog/handler.php';
require_once dirname(__DIR__) . '/sources/queries.php';
require_once dirname(__DIR__) . '/listings/queries.php';
require_once dirname(__DIR__) . '/listings/present.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/write.php';

function supplier_item_from_request(?array $cur, array &$errors): array
{
    $f = [];
    if (req_has('supplier_sku')) { $v = trim((string) req_val('supplier_sku')); $f['supplier_sku'] = $v === '' ? null : mb_substr($v, 0, 100); }
    if (req_has('cost') && (string) req_val('cost') !== '') {
        if (!sees_cost()) { $errors['cost'] = 'You may not set a cost — leave cost empty.'; }
        else { $f['cost'] = inv_money_field('cost', $cur['cost'] ?? null, 'The cost', $errors); }
    } elseif (req_has('cost') && sees_cost()) {
        $f['cost'] = null;
    }
    if (req_has('lead_time_days')) { $f['lead_time_days'] = inv_int('lead_time_days', $cur['lead_time_days'] ?? null, 0, 365, 'The lead time', $errors, true); }
    if (req_has('moq')) { $f['moq'] = inv_int('moq', $cur['moq'] ?? 1, 1, 100000, 'The minimum order', $errors) ?? 1; }
    if (req_has('active')) { $f['active'] = inv_yes('active', $cur['active'] ?? true); }
    return $f;
}

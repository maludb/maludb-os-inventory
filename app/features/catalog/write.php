<?php
declare(strict_types=1);

/**
 * The catalog's writes (catalog.md "Writes"): every one is the SQL the database judges — a SKU's uniqueness, a GTIN's check digit, a
 * bundle's rules, the price history — raised as P0001 / check_violation / 23505 and turned into a 422 by inv_guard(). Called inside the
 * handler's transaction; the handler logs.
 */

/** INSERT or UPDATE a product; the fields are the form's whole set (the handler filled the rest from the row). Returns the id. */
function save_product(PDO $pdo, ?int $id, array $f, int $by): int
{
    $args = ['brand' => $f['brand_id'], 'type' => $f['product_type_id'], 'name' => $f['name'], 'desc' => $f['description'], 'attrs' => json_encode((object) $f['attributes']),
             'kind' => $f['kind'], 'status' => $f['status'], 'options' => json_encode(array_values($f['options'])), 'rp' => $f['reorder_point'], 'ships' => $f['ships_how'], 'tags' => pg_array_literal($f['tags'])];
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO products (brand_id, product_type_id, name, description, attributes, kind, status, options, reorder_point, ships_how, tags, created_by)
                             VALUES (:brand, :type, :name, :desc, CAST(:attrs AS jsonb), :kind, :status, CAST(:options AS jsonb), :rp, :ships, CAST(:tags AS text[]), :by) RETURNING id');
        $st->execute($args + ['by' => $by]);
        return (int) $st->fetchColumn();
    }
    $st = $pdo->prepare('UPDATE products SET brand_id = :brand, product_type_id = :type, name = :name, description = :desc, attributes = CAST(:attrs AS jsonb), kind = :kind, status = :status,
                            options = CAST(:options AS jsonb), reorder_point = :rp, ships_how = :ships, tags = CAST(:tags AS text[]) WHERE id = :id');
    $st->execute($args + ['id' => $id]);
    return $id;
}

function discontinue_product(PDO $pdo, int $id, bool $discontinued): void
{
    $pdo->prepare("UPDATE products SET status = CASE WHEN :d THEN 'discontinued' ELSE 'active' END WHERE id = :id")->execute(['d' => $discontinued ? 1 : 0, 'id' => $id]);
}

/** Delete a product and its variants (the FK cascades; the handler checked product_deletable()). */
function delete_product(PDO $pdo, int $id): void
{
    $pdo->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $id]);
}

/** INSERT or UPDATE a variant. A create carries the three prices; the trigger writes price_history with reason `created` (app.price_reason set here, transaction-local). */
function save_variant(PDO $pdo, ?int $id, array $f, int $by): int
{
    $args = ['sku' => $f['sku'], 'ov' => json_encode((object) $f['option_values']), 'barcode' => $f['barcode'], 'mpn' => $f['mpn'], 'w' => $f['weight_g'], 'l' => $f['length_mm'], 'wd' => $f['width_mm'], 'h' => $f['height_mm'],
             'ships' => $f['ships_how'], 'rp' => $f['reorder_point'], 'rq' => $f['reorder_qty']];
    if ($id === null) {
        $pdo->exec("SELECT set_config('app.price_reason', 'created', true), set_config('app.price_source', 'manual', true)");
        $st = $pdo->prepare('INSERT INTO product_variants (product_id, sku, option_values, barcode, mpn, weight_g, length_mm, width_mm, height_mm, ships_how, retail_price, map_price, cost_price, reorder_point, reorder_qty)
                             VALUES (:p, :sku, CAST(:ov AS jsonb), :barcode, :mpn, :w, :l, :wd, :h, :ships, :retail, :map, :cost, :rp, :rq) RETURNING id');
        $st->execute($args + ['p' => $f['product_id'], 'retail' => $f['retail_price'], 'map' => $f['map_price'], 'cost' => $f['cost_price']]);
        return (int) $st->fetchColumn();
    }
    $st = $pdo->prepare('UPDATE product_variants SET sku = :sku, option_values = CAST(:ov AS jsonb), barcode = :barcode, mpn = :mpn, weight_g = :w, length_mm = :l, width_mm = :wd, height_mm = :h,
                            ships_how = :ships, reorder_point = :rp, reorder_qty = :rq, active = :active WHERE id = :id');
    $st->execute($args + ['active' => $f['active'] ? 1 : 0, 'id' => $id]);
    return $id;
}

function delete_variant(PDO $pdo, int $id): void
{
    $pdo->prepare('DELETE FROM product_variants WHERE id = :id')->execute(['id' => $id]);
}

function add_identifier(PDO $pdo, int $variantId, string $kind, string $value, ?int $sourceId, int $by): int
{
    $st = $pdo->prepare('INSERT INTO variant_identifiers (variant_id, kind, value, source_id, created_by) VALUES (:v, :k, :val, :s, :by) RETURNING id');
    $st->execute(['v' => $variantId, 'k' => $kind, 'val' => $value, 's' => $sourceId, 'by' => $by]);
    return (int) $st->fetchColumn();
}

/** Remove one identifier; returns the row removed (for the log), or throws 'Not found.'. */
function remove_identifier(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('DELETE FROM variant_identifiers WHERE id = :id RETURNING id, variant_id, kind, value, source_id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) { throw new DomainException('Not found.'); }
    return $r;
}

/** Replace a bundle's components with the whole list [{variant_id, qty}] (DELETE then INSERT). Returns [{variant_id, sku, qty}]. */
function set_bundle(PDO $pdo, int $bundleVariantId, array $components): array
{
    $pdo->prepare('DELETE FROM bundle_components WHERE bundle_variant_id = :b')->execute(['b' => $bundleVariantId]);
    $ins = $pdo->prepare('INSERT INTO bundle_components (bundle_variant_id, component_variant_id, qty) VALUES (:b, :c, :q)');
    $out = [];
    foreach ($components as $c) {
        $ins->execute(['b' => $bundleVariantId, 'c' => (int) $c['variant_id'], 'q' => (int) $c['qty']]);
        $out[] = ['variant_id' => (int) $c['variant_id'], 'sku' => $c['sku'] ?? null, 'qty' => (int) $c['qty']];
    }
    return $out;
}

function add_image(PDO $pdo, int $productId, ?int $variantId, int $attachmentId, ?string $alt, bool $primary, int $by): int
{
    if ($primary) {
        $pdo->prepare('UPDATE product_images SET is_primary = false WHERE product_id = :p')->execute(['p' => $productId]);
    }
    $st = $pdo->prepare('INSERT INTO product_images (product_id, variant_id, attachment_id, alt_text, sort_order, is_primary)
                         VALUES (:p, :v, :a, :alt, COALESCE((SELECT max(sort_order) + 1 FROM product_images WHERE product_id = :p2), 0), :prim) RETURNING id');
    $st->execute(['p' => $productId, 'v' => $variantId, 'a' => $attachmentId, 'alt' => $alt, 'p2' => $productId, 'prim' => $primary ? 1 : 0]);
    $id = (int) $st->fetchColumn();
    if (!$primary && (int) $pdo->query("SELECT count(*) FROM product_images WHERE product_id = $productId AND is_primary")->fetchColumn() === 0) {
        $pdo->prepare('UPDATE product_images SET is_primary = true WHERE id = :id')->execute(['id' => $id]);   // the first image is the primary one
    }
    return $id;
}

/** alt_text, is_primary (the old primary cleared first), sort_order (swapped with the neighbour that holds it), variant_id. */
function update_image(PDO $pdo, int $imageId, array $fields): void
{
    $st = $pdo->prepare('SELECT product_id, sort_order, is_primary FROM product_images WHERE id = :id');
    $st->execute(['id' => $imageId]);
    $cur = $st->fetch();
    if ($cur === false) { throw new DomainException('Not found.'); }
    if (array_key_exists('alt_text', $fields)) {
        $pdo->prepare('UPDATE product_images SET alt_text = :a WHERE id = :id')->execute(['a' => $fields['alt_text'], 'id' => $imageId]);
    }
    if (array_key_exists('variant_id', $fields)) {
        $pdo->prepare('UPDATE product_images SET variant_id = :v WHERE id = :id')->execute(['v' => $fields['variant_id'], 'id' => $imageId]);
    }
    if (!empty($fields['is_primary'])) {
        $pdo->prepare('UPDATE product_images SET is_primary = false WHERE product_id = :p AND id <> :id')->execute(['p' => $cur['product_id'], 'id' => $imageId]);
        $pdo->prepare('UPDATE product_images SET is_primary = true WHERE id = :id')->execute(['id' => $imageId]);
    }
    if (array_key_exists('sort_order', $fields) && $fields['sort_order'] !== null) {
        $target = (int) $fields['sort_order'];
        $pdo->prepare('UPDATE product_images SET sort_order = :old WHERE product_id = :p AND sort_order = :new AND id <> :id')->execute(['old' => (int) $cur['sort_order'], 'p' => $cur['product_id'], 'new' => $target, 'id' => $imageId]);
        $pdo->prepare('UPDATE product_images SET sort_order = :new WHERE id = :id')->execute(['new' => $target, 'id' => $imageId]);
    }
}

/** Remove an image row; returns the attachment id (the handler removes the file through attachment_delete()). */
function remove_image(PDO $pdo, int $imageId): int
{
    $st = $pdo->prepare('DELETE FROM product_images WHERE id = :id RETURNING attachment_id, product_id, is_primary');
    $st->execute(['id' => $imageId]);
    $r = $st->fetch();
    if ($r === false) { throw new DomainException('Not found.'); }
    if ($r['is_primary']) {
        $pdo->prepare('UPDATE product_images SET is_primary = true WHERE id = (SELECT id FROM product_images WHERE product_id = :p ORDER BY sort_order, id LIMIT 1)')->execute(['p' => $r['product_id']]);
    }
    return (int) $r['attachment_id'];
}

/** One price with its reason through inv_price_set(…, 'manual'). Returns ['before' => price, 'after' => price]. */
function set_price(PDO $pdo, int $variantId, string $kind, string $price, ?string $reason, string $source = 'manual'): array
{
    $col = ['retail' => 'retail_price', 'map' => 'map_price', 'cost' => 'cost_price'][$kind] ?? throw new DomainException('A price is retail, map or cost.');
    $st = $pdo->prepare("SELECT $col FROM product_variants WHERE id = :id");
    $st->execute(['id' => $variantId]);
    $before = $st->fetchColumn();
    if ($before === false) { throw new DomainException('Not found.'); }
    $pdo->prepare('SELECT inv_price_set(:v, :k, CAST(:p AS numeric), :r, :s)')->execute(['v' => $variantId, 'k' => $kind, 'p' => $price, 'r' => $reason, 's' => $source]);
    return ['before' => $before, 'after' => $price];
}

function save_brand(PDO $pdo, ?int $id, array $f): int
{
    $args = ['name' => $f['name'], 'web' => $f['website'], 'sup' => $f['supplier_id'], 'active' => $f['active'] ? 1 : 0];
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO brands (name, website, supplier_id, active) VALUES (:name, :web, :sup, :active) RETURNING id');
        $st->execute($args);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE brands SET name = :name, website = :web, supplier_id = :sup, active = :active WHERE id = :id')->execute($args + ['id' => $id]);
    return $id;
}

function save_product_type(PDO $pdo, ?int $id, array $f): int
{
    $args = ['key' => $f['key'], 'name' => $f['name'], 'so' => $f['sort_order'], 'active' => $f['active'] ? 1 : 0];
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO product_types (key, name, sort_order, active) VALUES (:key, :name, :so, :active) RETURNING id');
        $st->execute($args);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE product_types SET key = :key, name = :name, sort_order = :so, active = :active WHERE id = :id')->execute($args + ['id' => $id]);
    return $id;
}

/**
 * The CSV import (catalog.md "The import's mapping"): rows sharing (brand, name, type) become one product with a variant per row; an existing
 * SKU (or GTIN) is updated when $updateExisting else skipped; every row its own transaction. $mapping: field => column index. Returns
 * ['rows', 'products_created', 'variants_created', 'variants_updated', 'skipped' => [[row, reason]]]. Cost is ignored unless $seesCost.
 */
function import_catalog(PDO $pdo, string $path, array $mapping, bool $updateExisting, int $by, bool $seesCost): array
{
    require_once APP_ROOT . '/app/sources/registry.php';           // the connectors' loader: the interface, the HTTP client, then feed.php and inv_feed_rows()
    $bytes = (string) @file_get_contents($path);
    $parsed = inv_feed_rows($bytes, ['format' => 'csv']);
    $rows = $parsed['rows'];
    if (count($rows) > 10000) { throw new DomainException('too_large: the import takes at most 10,000 rows.'); }
    $out = ['rows' => count($rows), 'products_created' => 0, 'variants_created' => 0, 'variants_updated' => 0, 'skipped' => []];
    $cell = static fn (array $r, string $field): string => isset($mapping[$field]) && $mapping[$field] !== '' && $mapping[$field] !== null && isset($r[(int) $mapping[$field]]) ? trim((string) $r[(int) $mapping[$field]]) : '';
    $products = [];   // "brand|name|type" => product id
    foreach ($rows as $n => $r) {
        $line = $n + 2;
        $sku = $cell($r, 'sku');
        $name = $cell($r, 'name');
        if ($sku === '') { $out['skipped'][] = ['row' => $line, 'reason' => 'no SKU']; continue; }
        if ($name === '') { $out['skipped'][] = ['row' => $line, 'reason' => 'no product name']; continue; }
        $madeProduct = false;
        $pdo->beginTransaction();
        try {
            $gtin = $cell($r, 'gtin');
            $existing = null;
            $st = $pdo->prepare('SELECT id, product_id FROM product_variants WHERE lower(sku) = lower(:s) OR (:g <> \'\' AND barcode = inv_gtin14(:g2)) ORDER BY (lower(sku) = lower(:s2)) DESC LIMIT 1');
            $st->execute(['s' => $sku, 'g' => $gtin, 'g2' => $gtin, 's2' => $sku]);
            $existing = $st->fetch() ?: null;
            if ($existing !== null && !$updateExisting) {
                $pdo->rollBack();
                $out['skipped'][] = ['row' => $line, 'reason' => 'SKU ' . $sku . ' exists (update existing is off)'];
                continue;
            }
            $prices = [];
            foreach (['retail_price', 'map_price', 'cost_price'] as $pk) {
                $v = $cell($r, $pk);
                if ($pk === 'cost_price' && !$seesCost) { continue; }
                if ($v !== '') {
                    $num = preg_replace('/[^0-9.\-]/', '', $v);
                    if (!is_numeric($num) || (float) $num < 0) { throw new DomainException($pk . ' "' . $v . '" is not a price'); }
                    $prices[$pk] = number_format((float) $num, 2, '.', '');
                }
            }
            if ($existing !== null) {
                $f = [];
                if ($gtin !== '') { $f['barcode'] = $gtin; }
                if (($m = $cell($r, 'mpn')) !== '') { $f['mpn'] = $m; }
                if (($s = $cell($r, 'size')) !== '') { $f['size'] = $s; }
                if (($sh = $cell($r, 'ships_how')) !== '' && isset(SHIPS_HOW[$sh])) { $f['ships_how'] = $sh; }
                $sets = ['sku = :sku'];
                $args = ['sku' => $sku, 'id' => $existing['id']];
                if (isset($f['barcode'])) { $sets[] = 'barcode = :bc'; $args['bc'] = $f['barcode']; }
                if (isset($f['mpn'])) { $sets[] = 'mpn = :mpn'; $args['mpn'] = $f['mpn']; }
                if (isset($f['size'])) { $sets[] = "option_values = option_values || jsonb_build_object('Size', CAST(:size AS text))"; $args['size'] = $f['size']; }
                if (isset($f['ships_how'])) { $sets[] = 'ships_how = :sh'; $args['sh'] = $f['ships_how']; }
                $pdo->prepare('UPDATE product_variants SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($args);
                foreach ($prices as $pk => $price) {
                    set_price($pdo, (int) $existing['id'], substr($pk, 0, -6), $price, 'import', 'import');
                }
                if (($d = $cell($r, 'description')) !== '') {
                    $pdo->prepare('UPDATE products SET description = :d WHERE id = :p')->execute(['d' => mb_substr($d, 0, 4000), 'p' => $existing['product_id']]);
                }
                $out['variants_updated']++;
            } else {
                $brandName = $cell($r, 'brand');
                $brandId = null;
                if ($brandName !== '') {
                    $st = $pdo->prepare('SELECT id FROM brands WHERE lower(name) = lower(:n)');
                    $st->execute(['n' => $brandName]);
                    $brandId = $st->fetchColumn();
                    if ($brandId === false) {
                        $st = $pdo->prepare('INSERT INTO brands (name) VALUES (:n) RETURNING id');
                        $st->execute(['n' => $brandName]);
                        $brandId = $st->fetchColumn();
                    }
                    $brandId = (int) $brandId;
                }
                $typeWord = $cell($r, 'type');
                $type = $typeWord !== '' ? product_type_by_key_or_id($pdo, $typeWord) : null;
                $typeId = $type['product_type_id'] ?? (product_type_by_key_or_id($pdo, 'other')['product_type_id'] ?? null);
                if ($typeId === null) { throw new DomainException('no product type "' . $typeWord . '" and no "other" type'); }
                $key = strtolower($brandName) . '|' . strtolower($name) . '|' . $typeId;
                if (!isset($products[$key])) {
                    $st = $pdo->prepare('SELECT id FROM products WHERE lower(name) = lower(:n) AND product_type_id = :t AND brand_id IS NOT DISTINCT FROM :b ORDER BY id LIMIT 1');
                    $st->execute(['n' => $name, 't' => $typeId, 'b' => $brandId]);
                    $pid = $st->fetchColumn();
                    if ($pid === false) {
                        $tags = array_values(array_unique(array_filter(array_map('trim', explode(',', $cell($r, 'tags'))))));
                        $st = $pdo->prepare('INSERT INTO products (brand_id, product_type_id, name, description, kind, status, options, tags, ships_how, created_by)
                                             VALUES (:b, :t, :n, :d, \'single\', \'active\', \'["Size"]\', CAST(:tags AS text[]), :sh, :by) RETURNING id');
                        $sh = $cell($r, 'ships_how');
                        $st->execute(['b' => $brandId, 't' => $typeId, 'n' => $name, 'd' => ($d = $cell($r, 'description')) !== '' ? mb_substr($d, 0, 4000) : null, 'tags' => pg_array_literal(array_slice($tags, 0, 20)),
                                      'sh' => isset(SHIPS_HOW[$sh]) ? $sh : null, 'by' => $by]);
                        $pid = $st->fetchColumn();
                        $madeProduct = true;                       // counted once the row commits
                    }
                    $products[$key] = (int) $pid;
                }
                $pdo->exec("SELECT set_config('app.price_reason', 'import', true), set_config('app.price_source', 'import', true)");
                $size = $cell($r, 'size');
                $st = $pdo->prepare('INSERT INTO product_variants (product_id, sku, option_values, barcode, mpn, retail_price, map_price, cost_price, ships_how)
                                     VALUES (:p, :sku, CAST(:ov AS jsonb), :bc, :mpn, :retail, :map, :cost, :sh)');
                $sh = $cell($r, 'ships_how');
                $st->execute(['p' => $products[$key], 'sku' => $sku, 'ov' => json_encode($size !== '' ? ['Size' => $size] : (object) []), 'bc' => $gtin !== '' ? $gtin : null,
                              'mpn' => ($m = $cell($r, 'mpn')) !== '' ? $m : null, 'retail' => $prices['retail_price'] ?? null, 'map' => $prices['map_price'] ?? null, 'cost' => $prices['cost_price'] ?? null,
                              'sh' => isset(SHIPS_HOW[$sh]) ? $sh : null]);
                $out['variants_created']++;
            }
            $pdo->commit();
            if ($madeProduct) { $out['products_created']++; }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $reason = $e instanceof DomainException ? $e->getMessage() : (($e instanceof PDOException && (string) $e->getCode() === '23505') ? 'duplicate SKU or barcode' : db_message($e, 'refused'));
            $out['skipped'][] = ['row' => $line, 'reason' => $reason];
            // a product made in the rolled-back row is gone with it: forget it so the next row makes it again
            $products = array_filter($products, static fn (int $pid): bool => (bool) $pdo->query("SELECT 1 FROM products WHERE id = $pid")->fetchColumn());
        }
    }
    return $out;
}

<?php
/**
 * Helpers for the slice 5 proofs (docs/build-specs/orders.md, "Proof"). Run through tests/phase3/slice5/run.sh on the scratch database inv_dev5, the application on
 * :8601, the fake kernel :8602, the fake MaluDB :8603 and — once the world is built — THE FAKE MALUMAIL on :8606 (FAKE_MALUMAIL_LOG records each send).
 * Builds on slice 4's library (the cast, act(), screen(), find_world() — which builds slice 3's sources from the fixture server and slice 1's catalog).
 *
 * The world (order_world()): slice 4's world (the Cloudrest Hybrid in six sizes, the Queen set, the two suppliers with their matched offers — Malouf's Shopify store
 * (lead 5) and Zinus' feed (lead 3) —, the Warehouse with 4 Queens (1 allocated), the Showroom floor King) plus the customer "SMOKE Alvarez" (a shipping address,
 * email_opt_in), a second customer with no email, the Cook County tax rate (10.25 %) as the default, the business's name, e-mail and phone, the Buyer set to Nora.
 */
require dirname(__DIR__) . '/slice4/lib.php';

/** The fake MaluMail's record: one decoded payload per send. */
function mail_log(): array
{
    $f = getenv('FAKE_MALUMAIL_LOG');
    return $f && is_file($f) ? array_values(array_map(static fn ($l) => json_decode($l, true), array_filter(explode("\n", (string) file_get_contents($f))))) : [];
}
function order_id_of(string $number): ?int { $v = one('SELECT id FROM sales_orders WHERE number = :n', ['n' => $number]); return $v === false || $v === null ? null : (int) $v; }
function customer_id_of(string $name): ?int { $v = one('SELECT id FROM customers WHERE lower(name) = lower(:n)', ['n' => $name]); return $v === false || $v === null ? null : (int) $v; }
function order_row(int $id): array { return q('SELECT * FROM sales_orders WHERE id = :id', ['id' => $id])[0]; }
function order_lines_of(int $id): array { return q('SELECT l.*, v.sku FROM sales_order_lines l JOIN product_variants v ON v.id = l.variant_id WHERE l.sales_order_id = :id ORDER BY l.line_no', ['id' => $id]); }
function lv_for(int $variant, int $sourceId): ?int { $v = one('SELECT lv.id FROM listing_variants lv JOIN listings l ON l.id = lv.listing_id WHERE lv.variant_id = :v AND l.source_id = :s AND lv.removed_at IS NULL ORDER BY lv.id LIMIT 1', ['v' => $variant, 's' => $sourceId]); return $v === false || $v === null ? null : (int) $v; }
/** The order a proof tagged with a customer_reference (the proofs hand orders to each other this way). */
function ref_order(string $ref): ?int { $v = one('SELECT id FROM sales_orders WHERE customer_reference = :r ORDER BY id DESC LIMIT 1', ['r' => $ref]); return $v === false || $v === null ? null : (int) $v; }
function balance_of(int $variant, int $location): array { return q('SELECT * FROM inventory_balances WHERE variant_id = :v AND location_id = :l', ['v' => $variant, 'l' => $location])[0] ?? ['qty_on_hand' => 0, 'qty_allocated' => 0]; }
/** The raw door token of the last send (the proof reads it out of the e-mail, as a customer would). */
function token_from_mail(array $mail): ?string { return preg_match('#/o/([a-f0-9]{48})#', (string) ($mail['text'] ?? ''), $m) ? $m[1] : null; }
/** A quote through the handler: lines are [variant id => ['qty' =>, 'fulfilment' => 'stock:12' | 'dropship:34' | 'backorder', …]]. Returns the response. */
function make_quote(string $jar, int $customer, array $lines, array $extra = []): array
{
    $rows = [];
    foreach ($lines as $l) { $rows[] = $l; }
    return act($jar, '/orders/save.php', ['customer' => $customer, 'lines' => json_encode($rows)] + $extra);
}

function order_world(): array
{
    $w = find_world();
    if (customer_id_of('SMOKE Alvarez') === null) {
        psql_exec("UPDATE tax_rates SET is_default = false WHERE is_default; INSERT INTO tax_rates (name, rate, is_default) VALUES ('Cook County', 10.25, true)");
        psql_exec("UPDATE inv_settings SET business_name = 'SMOKE Business', business_contact_email = 'hello@smoke-business.example.invalid', business_phone = '312-555-0100', buyer_member_id = 40 WHERE id = 1");
        $run = substr(md5((string) microtime(true)), 0, 6);
        $nora = as_member(40);
        act($nora, '/customers/save.php', ['name' => 'SMOKE Alvarez', 'legal_name' => 'Alvarez Household LLC', 'email' => "alvarez-$run@example.invalid", 'phone' => '312-555-0188', 'billing_address' => "10 Main St\nChicago IL 60601",
            'shipping_address' => "22 Elm Street\nUnit 4", 'source' => 'walk_in', 'email_opt_in' => 'yes', 'notes' => 'Prefers morning deliveries.']);
        act($nora, '/customers/save.php', ['name' => 'SMOKE Birch (no email)', 'phone' => '312-555-0199', 'source' => 'phone']);
    }
    $w['alvarez'] = customer_id_of('SMOKE Alvarez');
    $w['birch'] = customer_id_of('SMOKE Birch (no email)');
    $w['cook'] = (int) one("SELECT id FROM tax_rates WHERE name = 'Cook County'");
    $w['alvarez_email'] = (string) one('SELECT email FROM customers WHERE id = :c', ['c' => $w['alvarez']]);
    $w['nora'] = as_member(40);
    $w['sam'] = as_member(41);
    $w['wes'] = as_member(42);
    $w['vera'] = as_member(43);
    $w['owner'] = as_member(1);
    $w['lv_malouf_ck'] = lv_for($w['calking'], $w['src_malouf']);
    $w['lv_zinus_k'] = lv_for($w['king'], $w['src_zinus_feed']);
    $w['lv_casper_k'] = lv_for($w['king'], $w['src_casper']);
    return $w;
}

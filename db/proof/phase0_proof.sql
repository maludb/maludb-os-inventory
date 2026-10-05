-- Phase 0 proof of the schema (docs/inventory-design.md §10, the Phase 0 row). Everything happens inside one transaction that
-- is ROLLED BACK: fixtures written as inventory_rw, reads as the two read roles, acting members set through app.member_id
-- exactly as PHP and the MCP servers set it. Run as postgres on the SCRATCH database:
--   sudo -u postgres psql -v ON_ERROR_STOP=1 -d inv_proof0 -f db/proof/phase0_proof.sql
-- Prints one line per check (ok / FAIL) and the count.
\set QUIET on
\o /dev/null
BEGIN;
CREATE TEMP TABLE proof (n serial, ok boolean, label text);
GRANT ALL ON proof TO PUBLIC; GRANT ALL ON SEQUENCE proof_n_seq TO PUBLIC;
CREATE OR REPLACE FUNCTION pg_temp.check(b boolean, l text) RETURNS void LANGUAGE sql AS $$ INSERT INTO proof (ok, label) VALUES (COALESCE(b, false), l) $$;
CREATE OR REPLACE FUNCTION pg_temp.refused(p_sql text, p_like text, l text) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
    BEGIN
        EXECUTE p_sql;
        INSERT INTO proof (ok, label) VALUES (false, l || ' (was NOT refused)');
    EXCEPTION WHEN OTHERS THEN
        INSERT INTO proof (ok, label) VALUES (SQLERRM ILIKE '%' || p_like || '%', l || CASE WHEN SQLERRM ILIKE '%' || p_like || '%' THEN '' ELSE ' (said: ' || SQLERRM || ')' END);
    END;
END$$;
CREATE OR REPLACE FUNCTION pg_temp.as_member(p bigint) RETURNS void LANGUAGE sql AS $$ SELECT set_config('app.member_id', COALESCE(p::text, ''), true) $$;
GRANT EXECUTE ON FUNCTION pg_temp.check(boolean, text), pg_temp.refused(text, text, text), pg_temp.as_member(bigint) TO PUBLIC;
CREATE TEMP TABLE ids (k text PRIMARY KEY, i bigint, t text);
GRANT ALL ON ids TO PUBLIC;
CREATE OR REPLACE FUNCTION pg_temp.i(k text) RETURNS bigint LANGUAGE sql STABLE AS $$ SELECT i FROM ids WHERE ids.k = $1 $$;
CREATE OR REPLACE FUNCTION pg_temp.t(k text) RETURNS text LANGUAGE sql STABLE AS $$ SELECT t FROM ids WHERE ids.k = $1 $$;
CREATE OR REPLACE FUNCTION pg_temp.keep(k text, v bigint) RETURNS void LANGUAGE sql AS $$ INSERT INTO ids (k, i) VALUES ($1, $2) ON CONFLICT (k) DO UPDATE SET i = EXCLUDED.i $$;
CREATE OR REPLACE FUNCTION pg_temp.keept(k text, v text) RETURNS void LANGUAGE sql AS $$ INSERT INTO ids (k, t) VALUES ($1, $2) ON CONFLICT (k) DO UPDATE SET t = EXCLUDED.t $$;
CREATE OR REPLACE FUNCTION pg_temp.gtin(body text) RETURNS text LANGUAGE sql AS $$ SELECT body || inv_gtin_check_digit(body) $$;
GRANT EXECUTE ON FUNCTION pg_temp.i(text), pg_temp.t(text), pg_temp.keep(text, bigint), pg_temp.keept(text, text), pg_temp.gtin(text) TO PUBLIC;

-- ================================================================ fixtures, as the writer: every actor of §2
SET ROLE inventory_rw;
SELECT pg_temp.as_member(1);
INSERT INTO members (id, member_kind, display_name, email, business_role, is_external, status, capability, roles) VALUES
 (1,  'human', 'SMOKE Owner',      'owner@example.invalid',  'super_admin', false, 'active', 'admin', '{admin}'),
 (20, 'human', 'SMOKE Sam Sales',  'sam@example.invalid',    'user', false, 'active', 'write', '{user}'),
 (21, 'human', 'SMOKE Wendy',      'wendy@example.invalid',  'user', false, 'active', 'write', '{warehouse}'),
 (22, 'human', 'SMOKE Bea Buyer',  'bea@example.invalid',    'user', false, 'active', 'write', '{buyer}'),
 (23, 'human', 'SMOKE Vic Viewer', 'vic@partner.invalid',    'user', true,  'active', 'read',  '{viewer}'),
 (24, 'human', 'SMOKE Ada Admin',  'ada@example.invalid',    'user', false, 'active', 'admin', '{admin}'),
 (25, 'human', 'SMOKE Nora',       'nora@example.invalid',   'user', false, 'active', 'write', '{}'),
 (30, 'agent', 'SMOKE Expert',     NULL, 'user', false, 'active', 'write', '{buyer}'),
 (31, 'agent', 'SMOKE Stock Buyer',NULL, 'user', false, 'active', 'write', '{buyer}'),
 (32, 'agent', 'SMOKE Unadmitted', NULL, 'user', false, 'active', NULL,    '{}'),
 (33, 'human', 'SMOKE Gone',       'gone@example.invalid',   'user', false, 'inactive', 'write', '{user}');
INSERT INTO departments (id, name, is_system, system_key) VALUES (3, 'Accounting', true, 'accounting'), (7, 'Showroom', false, NULL);
INSERT INTO department_members (member_id, department_id, is_admin, is_primary) VALUES (20, 7, false, true), (22, 7, true, true);
UPDATE inv_settings SET business_name = 'SMOKE Mattress Co', business_contact_email = 'hello@example.invalid', buyer_member_id = 22;

-- ================================================================ actors and rights (§2, §3, D2)
SELECT pg_temp.as_member(1);
SELECT pg_temp.check(inv_is_admin() AND inv_has_right('settings.manage') AND inv_has_right('cost.read') AND inv_sees_cost(), 'the super-admin holds the admin role: settings.manage, cost.read, sees cost');
SELECT pg_temp.as_member(20);
SELECT pg_temp.check(inv_has_right('orders.write') AND inv_has_right('payments.record') AND inv_has_right('customers.write') AND inv_has_right('orders.send') AND inv_has_right('watches.own'), 'Sales (user) quotes, takes orders, records payments, sends the order, watches');
SELECT pg_temp.check(NOT inv_has_right('cost.read') AND NOT inv_sees_cost() AND NOT inv_has_right('stock.receive') AND NOT inv_has_right('catalog.write'), 'Sales sees no cost by default, receives nothing, edits no catalog');
SELECT pg_temp.check(NOT inv_sees_receipt_cost(), 'Sales sees no receipt cost either');
SELECT pg_temp.as_member(21);
SELECT pg_temp.check(inv_has_right('stock.receive') AND inv_has_right('stock.adjust') AND inv_has_right('stock.transfer') AND inv_has_right('stock.count') AND inv_has_right('stock.ship') AND inv_has_right('returns.receive'), 'Warehouse receives, adjusts, transfers, counts, ships, takes returns in');
SELECT pg_temp.check(NOT inv_sees_cost() AND inv_sees_receipt_cost(), 'Warehouse sees cost on receipts only (it is on the paperwork)');
SELECT pg_temp.check(NOT inv_has_right('orders.write') AND NOT inv_has_right('prices.write'), 'Warehouse takes no orders and sets no prices');
SELECT pg_temp.as_member(22);
SELECT pg_temp.check(inv_has_right('catalog.write') AND inv_has_right('prices.write') AND inv_has_right('sources.write') AND inv_has_right('sources.credentials') AND inv_has_right('listings.match') AND inv_has_right('purchasing.write') AND inv_has_right('suppliers.write') AND inv_has_right('returns.write') AND inv_has_right('watches.all') AND inv_has_right('reports.read'), 'Buyer holds the catalog, prices, sources, credentials, matching, purchasing, suppliers, returns, every watch, reports');
SELECT pg_temp.check(inv_sees_cost() AND inv_has_right('orders.write') AND inv_has_right('stock.receive'), 'Buyer sees cost and holds Sales'' and Warehouse''s rights too');
SELECT pg_temp.check(NOT inv_has_right('settings.manage') AND NOT inv_has_right('feed.keys') AND NOT inv_has_right('records.delete') AND NOT inv_is_admin(), 'Buyer runs no settings, mints no feed keys, deletes nothing');
SELECT pg_temp.as_member(23);
SELECT pg_temp.check(inv_has_right('inventory.read') AND inv_is_member_here() AND NOT inv_has_right('orders.write') AND NOT inv_sees_cost() AND NOT inv_sees_receipt_cost(), 'a Viewer (external) reads and nothing else — never cost');
SELECT pg_temp.as_member(24);
SELECT pg_temp.check(inv_is_admin() AND inv_has_right('feed.keys') AND inv_has_right('records.delete') AND inv_has_right('sequences.manage') AND inv_has_right('agents.settings') AND inv_has_right('exports.all'), 'a human holding admin runs Inventory: feed keys, deletion, sequences, agents, exports');
SELECT pg_temp.as_member(25);
SELECT pg_temp.check(inv_member_roles(25) = '{user}' AND inv_has_right('orders.write'), 'a member the kernel sent no roles for reads Sales from a write capability');
SELECT pg_temp.as_member(30);
SELECT pg_temp.check(inv_has_right('purchasing.write') AND inv_sees_cost() AND app_member_kind() = 'agent' AND NOT inv_is_admin(), 'an agent granted Buyer has a Buyer''s rights and is never the admin');
SELECT pg_temp.as_member(32);
SELECT pg_temp.check(NOT app_is_active_member() AND NOT inv_is_member_here() AND NOT inv_has_right('inventory.read') AND NOT inv_sees_cost(), 'an agent not yet admitted sees nothing and may do nothing');
SELECT pg_temp.as_member(33);
SELECT pg_temp.check(NOT inv_is_member_here() AND NOT inv_has_right('orders.write'), 'an inactive member holds nothing');
SELECT pg_temp.as_member(NULL);
SELECT pg_temp.check(NOT inv_is_member_here() AND NOT inv_sees_cost(), 'nobody (no app.member_id) is nobody');
-- the sales_sees_cost switch (D2)
SELECT pg_temp.as_member(1);
UPDATE inv_settings SET sales_sees_cost = true;
SELECT pg_temp.as_member(20);
SELECT pg_temp.check(inv_sees_cost(), 'with sales_sees_cost on, Sales sees cost');
SELECT pg_temp.as_member(23);
SELECT pg_temp.check(NOT inv_sees_cost(), '…and a Viewer still does not');
SELECT pg_temp.as_member(1);
UPDATE inv_settings SET sales_sees_cost = false;
SELECT pg_temp.as_member(20);
SELECT pg_temp.check(NOT inv_sees_cost(), 'switched off, Sales is behind the wall again');

-- ================================================================ settings, numbering, tax, reasons, vocabulary (db/005)
SELECT pg_temp.as_member(22);
SELECT pg_temp.check((SELECT count(*) FROM document_sequences) = 7 AND (SELECT prefix FROM document_sequences WHERE kind = 'sales_order') = 'SO-' AND (SELECT prefix FROM document_sequences WHERE kind = 'return') = 'RA-', 'seven document sequences are seeded with the design''s prefixes');
SELECT pg_temp.check(next_document_number('sales_order') = 'SO-00001' AND next_document_number('sales_order') = 'SO-00002', 'a number is prefix + padded value, one after another');
SAVEPOINT num;
SELECT pg_temp.keept('n1', next_document_number('purchase_order'));
ROLLBACK TO SAVEPOINT num;
SELECT pg_temp.check(next_document_number('purchase_order') = 'PO-00001', 'a rolled-back issue returns the number (the table, not a sequence)');
SELECT pg_temp.refused($$INSERT INTO document_sequences (kind, prefix) VALUES ('invoice', 'INV-')$$, 'check', 'the sequence kinds are ours alone — no invoice (D10)');
SELECT pg_temp.check((SELECT count(*) FROM tax_rates WHERE is_default) = 1 AND (SELECT rate FROM tax_rates WHERE is_default) = 0, 'the default tax rate is No tax at 0 (GL''s shape)');
INSERT INTO tax_rates (name, rate) VALUES ('SMOKE Cook County', 10.25);
SELECT pg_temp.refused($$INSERT INTO tax_rates (name, rate, is_default) VALUES ('SMOKE Second default', 5, true)$$, 'duplicate', 'only one default tax rate');
SELECT pg_temp.check((SELECT count(*) FROM reason_codes WHERE 'adjustment' = ANY (applies_to)) = 7 AND (SELECT count(*) FROM reason_codes WHERE 'return' = ANY (applies_to)) = 5, 'seven adjustment reasons and five return reasons are seeded');
SELECT pg_temp.check((SELECT affects_qty FROM reason_codes WHERE code = 'floor_model') = false, 'the floor-model reason moves a flag, not a count');
SELECT pg_temp.check(cardinality(inv_availability_states()) = 7 AND 'unknown' = ANY (inv_availability_states()), 'seven availability states, schema.org''s plus unknown (§0.3)');
SELECT pg_temp.check(cardinality(inv_identifier_kinds()) = 9 AND cardinality(inv_ships_how_kinds()) = 4, 'nine identifier kinds and four ways to ship');
SELECT pg_temp.check(jsonb_array_length((SELECT sizes FROM inv_settings)) = 12 AND jsonb_array_length((SELECT attribute_keys FROM inv_settings)) = 9, 'twelve mattress sizes and nine attribute keys are seeded (D5)');
SELECT pg_temp.check((SELECT cost_source FROM inv_settings) = 'last_receipt' AND (SELECT cost_move_pct FROM inv_settings) = 5 AND (SELECT reference_undercut_pct FROM inv_settings) = 10, 'D11: cost from the last receipt; 5 % and 10 % thresholds');
SELECT pg_temp.check((SELECT supplier_sees_phone FROM inv_settings) = '{ltl,white_glove}' AND NOT (SELECT feed_shows_quantity FROM inv_settings) AND NOT (SELECT sales_sees_cost FROM inv_settings), 'D9: the phone reaches the supplier for ltl and white glove; the feed hides quantities; Sales sees no cost');
SELECT pg_temp.check((SELECT order_link_days FROM inv_settings) = 180 AND (SELECT supplier_link_days FROM inv_settings) = 90 AND (SELECT feed_rate_per_minute FROM inv_settings) = 60 AND (SELECT feed_rate_per_day FROM inv_settings) = 10000, '§4: link lifetimes 180 / 90 days; the feed at 60 a minute and 10,000 a day');
SELECT pg_temp.check((SELECT crawl_backoff_minutes FROM inv_settings) = '{60,1440}' AND (SELECT crawl_rate_per_second FROM inv_settings) = 1 AND (SELECT removed_after_pulls FROM inv_settings) = 2, 'D8: back off an hour, a day, then pause; one request a second; removed after two unseen pulls');
SELECT pg_temp.check((SELECT buyer_member_id FROM inv_settings) = 22, 'D12: the Buyer the morning note goes to is a setting');
SELECT pg_temp.check((SELECT line_total FROM inv_line_money(2, 999.99, 100, 10.25)) = round(1899.98 * 1.1025, 2), 'line money is rounded per line (GL''s rule under our name)');

-- GTIN and sizes (§0.3)
SELECT pg_temp.check(inv_gtin14('012345678905') = '00012345678905' AND inv_gtin14('0 12345 67890 5') = '00012345678905', 'a UPC-A is normalized to GTIN-14, whatever its spacing');
SELECT pg_temp.check(inv_gtin14('4006381333931') = '04006381333931' AND inv_gtin14('96385074') = '00000096385074', 'an EAN-13 and an EAN-8 normalize too');
SELECT pg_temp.check(inv_gtin14('012345678906') IS NULL AND inv_gtin14('1234') IS NULL AND inv_gtin14('abc') IS NULL AND inv_gtin14(NULL) IS NULL, 'a wrong check digit, a wrong length, letters and NULL are not a GTIN');
SELECT pg_temp.check(inv_gtin_check_digit('01234567890') = '5' AND inv_gtin_check_digit('400638133393') = '1', 'the GS1 check digit is computed');
SELECT pg_temp.check(inv_size_key('Cal King') = 'california_king' AND inv_size_key('California King') = 'california_king' AND inv_size_key('CK') = 'california_king' AND inv_size_key('calking') = 'california_king', 'Cal King, California King, CK and calking are one size key');
SELECT pg_temp.check(inv_size_key('Twin XL') = 'twin_xl' AND inv_size_key('TWIN EXTRA-LONG') = 'twin_xl' AND inv_size_key('Split King') = 'split_king' AND inv_size_key('Queen') = 'queen', 'synonyms fold case, punctuation and words');
SELECT pg_temp.check(inv_size_key('Super King') = 'super_king' AND inv_size_key('') IS NULL AND inv_size_key(NULL) IS NULL, 'an unknown size keeps its folded text; nothing is NULL');
SELECT pg_temp.check(inv_option_size('{"Size":"Queen","Firmness":"Medium"}') = 'Queen' AND inv_option_size('{"size":"King"}') = 'King' AND inv_option_size('{"Color":"Grey"}') IS NULL, 'the Size option is found whatever its case');

-- ================================================================ the catalog (db/006–007, D5): a product with six sizes, a bundle, identifiers of every kind
SELECT pg_temp.as_member(22);
INSERT INTO brands (name, website) VALUES ('SMOKE Purple', 'https://purple.example'), ('SMOKE Malouf', 'https://malouf.example'), ('SMOKE Zinus', 'https://zinus.example');
SELECT pg_temp.keep('b_purple', id) FROM brands WHERE name = 'SMOKE Purple';
SELECT pg_temp.keep('b_malouf', id) FROM brands WHERE name = 'SMOKE Malouf';
SELECT pg_temp.keep('b_zinus', id) FROM brands WHERE name = 'SMOKE Zinus';
SELECT pg_temp.check((SELECT count(*) FROM product_types) = 9 AND EXISTS (SELECT 1 FROM product_types WHERE key = 'adjustable_base'), 'nine product types are seeded, Mattress to Other');
SELECT pg_temp.refused($$INSERT INTO brands (name) VALUES ('smoke purple')$$, 'duplicate', 'a brand''s name is unique whatever its case');
INSERT INTO products (brand_id, product_type_id, name, kind, status, attributes, reorder_point, ships_how)
VALUES (pg_temp.i('b_purple'), (SELECT id FROM product_types WHERE key = 'mattress'), 'SMOKE Purple Hybrid 2', 'single', 'active', '{"type":"hybrid","firmness":6,"height_in":11,"trial_nights":100}', 2, 'parcel');
SELECT pg_temp.keep('p_purple', id) FROM products WHERE name = 'SMOKE Purple Hybrid 2';
INSERT INTO product_variants (product_id, sku, option_values, barcode, mpn, retail_price, map_price, cost_price, weight_g, length_mm, width_mm, height_mm)
SELECT pg_temp.i('p_purple'), 'PH2-' || s.code, jsonb_build_object('Size', s.name), pg_temp.gtin('84000000010' || s.n), 'PH2-MPN-' || s.code, s.retail, s.retail, s.cost, 30000, s.len, s.wid, 280
  FROM (VALUES ('T', 'Twin', 1, 1199.00, 600.00, 1905, 965), ('TXL', 'Twin XL', 2, 1299.00, 650.00, 2030, 965), ('F', 'Full', 3, 1499.00, 750.00, 1905, 1370),
               ('Q', 'Queen', 4, 1699.00, 850.00, 2030, 1525), ('K', 'King', 5, 2099.00, 1050.00, 2030, 1930), ('CK', 'Cal King', 6, 2099.00, 1050.00, 2130, 1830)) AS s(code, name, n, retail, cost, len, wid);
SELECT pg_temp.check((SELECT count(*) FROM product_variants WHERE product_id = pg_temp.i('p_purple')) = 6, 'a product with six sizes has six variants');
SELECT pg_temp.check((SELECT array_agg(size_key ORDER BY id) FROM product_variants WHERE product_id = pg_temp.i('p_purple')) = '{twin,twin_xl,full,queen,king,california_king}', 'every variant''s size_key was derived by trigger through the synonyms');
SELECT pg_temp.check((SELECT barcode FROM product_variants WHERE sku = 'PH2-Q') = lpad(pg_temp.gtin('840000000104'), 14, '0') AND length((SELECT barcode FROM product_variants WHERE sku = 'PH2-Q')) = 14, 'a variant''s barcode is stored as GTIN-14');
SELECT pg_temp.keep('v_q', id) FROM product_variants WHERE sku = 'PH2-Q';
SELECT pg_temp.keep('v_k', id) FROM product_variants WHERE sku = 'PH2-K';
SELECT pg_temp.keep('v_ck', id) FROM product_variants WHERE sku = 'PH2-CK';
SELECT pg_temp.keep('v_t', id) FROM product_variants WHERE sku = 'PH2-T';
SELECT pg_temp.check((SELECT count(*) FROM price_history WHERE variant_id = pg_temp.i('v_q')) = 3 AND (SELECT count(*) FROM price_history WHERE variant_id = pg_temp.i('v_q') AND old_price IS NULL) = 3, 'setting three prices at creation wrote three history rows, from nothing');
SELECT pg_temp.refused($$INSERT INTO product_variants (product_id, sku, option_values, barcode) VALUES (pg_temp.i('p_purple'), 'PH2-DUP', '{"Size":"Queen"}', '012345678906')$$, 'not a GTIN', 'a barcode with a wrong check digit is refused');
SELECT pg_temp.refused(format($$INSERT INTO product_variants (product_id, sku, option_values, barcode) VALUES (%s, 'PH2-DUP', '{"Size":"Queen"}', %L)$$, pg_temp.i('p_purple'), pg_temp.gtin('840000000104')), 'duplicate', 'the same GTIN on two variants is refused');
SELECT pg_temp.refused($$INSERT INTO product_variants (product_id, sku, option_values) VALUES (pg_temp.i('p_purple'), 'ph2-q', '{"Size":"Queen"}')$$, 'duplicate', 'a SKU is unique whatever its case');
SELECT pg_temp.refused($$INSERT INTO product_variants (product_id, sku) VALUES (pg_temp.i('p_purple'), '  ')$$, 'needs a SKU', 'a blank SKU is refused');
-- prices with history and a reason (D11)
SELECT inv_price_set(pg_temp.i('v_q'), 'retail', 1599.00, 'SMOKE sale event');
SELECT pg_temp.check((SELECT retail_price FROM product_variants WHERE id = pg_temp.i('v_q')) = 1599.00, 'inv_price_set changes the price');
SELECT pg_temp.check((SELECT reason FROM price_history WHERE variant_id = pg_temp.i('v_q') AND kind = 'retail' ORDER BY id DESC LIMIT 1) = 'SMOKE sale event'
                 AND (SELECT old_price FROM price_history WHERE variant_id = pg_temp.i('v_q') AND kind = 'retail' ORDER BY id DESC LIMIT 1) = 1699.00
                 AND (SELECT changed_by FROM price_history WHERE variant_id = pg_temp.i('v_q') AND kind = 'retail' ORDER BY id DESC LIMIT 1) = 22, '…and the history says old, new, who and why');
SELECT pg_temp.refused($$SELECT inv_price_set(pg_temp.i('v_q'), 'wholesale', 1)$$, 'retail, map or cost', 'a price is retail, map or cost');
UPDATE product_variants SET map_price = 1649.00 WHERE id = pg_temp.i('v_q');
SELECT pg_temp.check((SELECT count(*) FROM price_history WHERE variant_id = pg_temp.i('v_q') AND kind = 'map') = 2, 'a direct update writes history too (no reason)');
SELECT pg_temp.check((SELECT cost_updated_at FROM product_variants WHERE id = pg_temp.i('v_q')) IS NOT NULL, 'cost_updated_at is stamped');
-- attributes
SELECT pg_temp.check((SELECT attributes->>'type' FROM products WHERE id = pg_temp.i('p_purple')) = 'hybrid', 'attributes are a jsonb object keyed as the settings declare');
SELECT pg_temp.refused($$UPDATE products SET attributes = '[1]' WHERE id = pg_temp.i('p_purple')$$, 'check', 'attributes must be an object');
-- a second product and a bundle (Queen set = mattress + foundation)
INSERT INTO products (brand_id, product_type_id, name, kind, status) VALUES (pg_temp.i('b_purple'), (SELECT id FROM product_types WHERE key = 'foundation'), 'SMOKE Purple Foundation', 'single', 'active');
SELECT pg_temp.keep('p_found', id) FROM products WHERE name = 'SMOKE Purple Foundation';
INSERT INTO product_variants (product_id, sku, option_values, retail_price, cost_price) VALUES (pg_temp.i('p_found'), 'PF-Q', '{"Size":"Queen"}', 299.00, 120.00), (pg_temp.i('p_found'), 'PF-K', '{"Size":"King"}', 399.00, 160.00);
SELECT pg_temp.keep('v_fq', id) FROM product_variants WHERE sku = 'PF-Q';
INSERT INTO products (brand_id, product_type_id, name, kind, status) VALUES (pg_temp.i('b_purple'), (SELECT id FROM product_types WHERE key = 'mattress'), 'SMOKE Purple Hybrid 2 Set', 'bundle', 'active');
SELECT pg_temp.keep('p_set', id) FROM products WHERE name = 'SMOKE Purple Hybrid 2 Set';
INSERT INTO product_variants (product_id, sku, option_values, retail_price) VALUES (pg_temp.i('p_set'), 'PH2SET-Q', '{"Size":"Queen"}', 1899.00);
SELECT pg_temp.keep('v_set', id) FROM product_variants WHERE sku = 'PH2SET-Q';
INSERT INTO bundle_components (bundle_variant_id, component_variant_id, qty) VALUES (pg_temp.i('v_set'), pg_temp.i('v_q'), 1), (pg_temp.i('v_set'), pg_temp.i('v_fq'), 1);
SELECT pg_temp.check((SELECT count(*) FROM bundle_components WHERE bundle_variant_id = pg_temp.i('v_set')) = 2, 'a bundle variant lists its components with counts');
SELECT pg_temp.refused($$INSERT INTO bundle_components (bundle_variant_id, component_variant_id, qty) VALUES (pg_temp.i('v_q'), pg_temp.i('v_fq'), 1)$$, 'bundle product', 'only a bundle product''s variant has components');
SELECT pg_temp.refused($$INSERT INTO bundle_components (bundle_variant_id, component_variant_id, qty) VALUES (pg_temp.i('v_set'), pg_temp.i('v_set'), 1)$$, 'bundle', 'a bundle does not contain itself');
INSERT INTO products (product_type_id, name, kind, status) VALUES ((SELECT id FROM product_types WHERE key = 'other'), 'SMOKE Mega Bundle', 'bundle', 'active');
INSERT INTO product_variants (product_id, sku) VALUES ((SELECT id FROM products WHERE name = 'SMOKE Mega Bundle'), 'MEGA');
SELECT pg_temp.refused($$INSERT INTO bundle_components (bundle_variant_id, component_variant_id, qty) VALUES ((SELECT id FROM product_variants WHERE sku = 'MEGA'), pg_temp.i('v_set'), 1)$$, 'does not contain a bundle', 'a bundle never nests');
SELECT pg_temp.refused($$INSERT INTO bundle_components (bundle_variant_id, component_variant_id, qty) VALUES ((SELECT id FROM product_variants WHERE sku = 'MEGA'), pg_temp.i('v_q'), 0)$$, 'check', 'a component count is positive');
-- discontinuing
UPDATE products SET status = 'discontinued' WHERE name = 'SMOKE Mega Bundle';
SELECT pg_temp.check((SELECT discontinued_at FROM products WHERE name = 'SMOKE Mega Bundle') IS NOT NULL, 'discontinuing a product stamps discontinued_at');
-- images need attachments of the right kind
INSERT INTO attachments (record_type, record_id, filename, mime_type, byte_size, sha256, storage_path, uploaded_by) VALUES ('product', pg_temp.i('p_purple'), 'hero.jpg', 'image/jpeg', 1234, repeat('a', 64), 'attachments/product/1/hero.jpg', 22);
INSERT INTO attachments (record_type, record_id, filename, mime_type, byte_size, sha256, storage_path, uploaded_by) VALUES ('supplier', 1, 'sheet.pdf', 'application/pdf', 99, repeat('b', 64), 'attachments/supplier/1/sheet.pdf', 22);
INSERT INTO product_images (product_id, attachment_id, is_primary) VALUES (pg_temp.i('p_purple'), (SELECT id FROM attachments WHERE filename = 'hero.jpg'), true);
SELECT pg_temp.check((SELECT count(*) FROM product_images) = 1, 'an image is an attachment on the product');
SELECT pg_temp.refused($$INSERT INTO product_images (product_id, attachment_id) VALUES (pg_temp.i('p_purple'), (SELECT id FROM attachments WHERE filename = 'sheet.pdf'))$$, 'image attachment', 'a PDF on a supplier is not a product image');
SELECT pg_temp.refused($$INSERT INTO product_images (product_id, variant_id, attachment_id) VALUES (pg_temp.i('p_found'), pg_temp.i('v_q'), (SELECT id FROM attachments WHERE filename = 'hero.jpg'))$$, 'another product', 'an image''s variant belongs to its product');
SELECT pg_temp.refused($$INSERT INTO attachments (record_type, record_id, filename, mime_type, byte_size, sha256, storage_path) VALUES ('invoice', 1, 'x', 'x', 1, 'x', 'x')$$, 'check', 'attachments'' record_type is widened to ours (no invoice)');
INSERT INTO notes (record_type, record_id, member_id, body) VALUES ('product', pg_temp.i('p_purple'), 22, 'SMOKE a note');
SELECT pg_temp.check((SELECT count(*) FROM notes) = 1, 'a note on a record (GL''s shape)');

-- ================================================================ suppliers and sources (db/009) — three sources
INSERT INTO suppliers (name, kind, dropships, lead_time_days, order_method, order_email, account_number) VALUES ('SMOKE Malouf', 'manufacturer', true, 5, 'portal', 'orders@malouf.invalid', 'DLR-1001');
INSERT INTO suppliers (name, kind, dropships, lead_time_days, order_method, order_email) VALUES ('SMOKE Zinus', 'manufacturer', true, 3, 'email', 'dealers@zinus.invalid');
SELECT pg_temp.keep('s_malouf', id) FROM suppliers WHERE name = 'SMOKE Malouf';
SELECT pg_temp.keep('s_zinus', id) FROM suppliers WHERE name = 'SMOKE Zinus';
UPDATE brands SET supplier_id = pg_temp.i('s_malouf') WHERE id = pg_temp.i('b_malouf');
SELECT pg_temp.check((SELECT supplier_id FROM brands WHERE id = pg_temp.i('b_malouf')) = pg_temp.i('s_malouf'), 'a brand may name its own dealer program (supplier)');
SELECT pg_temp.check((SELECT count(*) FROM information_schema.columns WHERE table_name = 'suppliers' AND column_name IN ('name','kind','contact_name','email','phone','address','notes','active','website','account_number','terms','dropships','lead_time_days','order_method','order_email','portal_url','min_order')) = 17, 'suppliers keeps the Cidery''s columns and appends the dealer-program columns');
INSERT INTO sources (name, connector, role, supplier_id, base_url, settings) VALUES ('SMOKE Malouf dealer feed', 'feed', 'supplier', pg_temp.i('s_malouf'), 'https://feed.malouf.invalid/dealer.csv', '{"transport":"https","mapping":{"supplier_sku":"SKU","gtin":"UPC","cost":"Dealer Cost","qty":"Qty"}}');
INSERT INTO sources (name, connector, role, supplier_id, base_url) VALUES ('SMOKE Zinus store', 'shopify', 'supplier', pg_temp.i('s_zinus'), 'https://zinus.invalid');
INSERT INTO sources (name, connector, role, base_url) VALUES ('SMOKE Casper site', 'shopify', 'reference', 'https://casper.invalid');
INSERT INTO sources (name, connector, role, base_url) VALUES ('SMOKE Bigbox pages', 'jsonld', 'reference', 'https://bigbox.invalid');
INSERT INTO sources (name, connector, role, supplier_id) VALUES ('SMOKE Zinus price sheet', 'manual', 'supplier', pg_temp.i('s_zinus'));
SELECT pg_temp.keep('src_feed', id) FROM sources WHERE name = 'SMOKE Malouf dealer feed';
SELECT pg_temp.keep('src_zinus', id) FROM sources WHERE name = 'SMOKE Zinus store';
SELECT pg_temp.keep('src_casper', id) FROM sources WHERE name = 'SMOKE Casper site';
SELECT pg_temp.keep('src_jsonld', id) FROM sources WHERE name = 'SMOKE Bigbox pages';
SELECT pg_temp.keep('src_manual', id) FROM sources WHERE name = 'SMOKE Zinus price sheet';
SELECT pg_temp.check((SELECT schedule_minutes FROM sources WHERE id = pg_temp.i('src_feed')) = 30 AND (SELECT schedule_minutes FROM sources WHERE id = pg_temp.i('src_casper')) = 360
                 AND (SELECT schedule_minutes FROM sources WHERE id = pg_temp.i('src_jsonld')) = 1440 AND (SELECT schedule_minutes FROM sources WHERE id = pg_temp.i('src_manual')) = 0, 'D8 cadence by default: supplier 30 min, reference 6 h, jsonld daily, manual never');
SELECT pg_temp.refused($$INSERT INTO sources (name, connector, role, base_url) VALUES ('SMOKE orphan', 'shopify', 'supplier', 'https://x')$$, 'check', 'a supplier source names its supplier');
SELECT pg_temp.refused($$INSERT INTO sources (name, connector, role) VALUES ('SMOKE login', 'portal_login', 'reference')$$, 'check', 'a connector is one of the known kinds — no login scraper');
INSERT INTO sources (name, connector, role, base_url, rate_per_second) VALUES ('SMOKE Fast', 'woocommerce', 'reference', 'https://fast.invalid', 9);
SELECT pg_temp.check((SELECT rate_per_second FROM sources WHERE name = 'SMOKE Fast') = 1, 'a source is never faster than the crawl policy''s rate (§0.2)');
SELECT pg_temp.check((SELECT count(*) FROM source_templates) = 18 AND (SELECT count(*) FROM source_templates WHERE survey_result = 'unverified') = 18 AND (SELECT count(*) FROM source_templates WHERE connector = 'shopify') = 14, 'eighteen templates are seeded from §0.2, every store unverified until the live survey');
-- a credential: sealed, labelled, last four; in no view
INSERT INTO source_credentials (source_id, kind, label, last4, ciphertext, created_by) VALUES (pg_temp.i('src_feed'), 'sftp_password', 'SMOKE dealer SFTP', 'x9Q2', '\xdeadbeefcafe'::bytea, 22);
SELECT pg_temp.keep('cred', id) FROM source_credentials WHERE label = 'SMOKE dealer SFTP';
UPDATE sources SET credential_id = pg_temp.i('cred') WHERE id = pg_temp.i('src_feed');
SELECT pg_temp.check((SELECT credential_id FROM sources WHERE id = pg_temp.i('src_feed')) = pg_temp.i('cred'), 'a source names its credential');
SELECT pg_temp.refused(format($$UPDATE sources SET credential_id = %s WHERE id = %s$$, pg_temp.i('cred'), pg_temp.i('src_zinus')), 'own source', 'a credential belongs to its own source, never another''s');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name LIKE 'mcp_%' AND column_name = 'ciphertext'), 'NO mcp_* view has a ciphertext column');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM information_schema.columns c JOIN pg_views v ON v.viewname = c.table_name AND v.schemaname = 'public' WHERE c.table_name LIKE 'mcp\_%' AND c.column_name IN ('token_hash', 'storage_path', 'body_html', 'session_hash', 'ciphertext')), 'no view exposes a token hash, a storage path, an outbox body, a session hash or a ciphertext');
SELECT pg_temp.check((SELECT string_agg(column_name, ',' ORDER BY ordinal_position) FROM information_schema.columns WHERE table_name = 'mcp_source_credentials') = 'credential_id,source_id,kind,label,last4,rotated_at,created_by,created_at', 'mcp_source_credentials is id, source, kind, label, last4, rotated_at and the author — nothing more');
-- the price sheet (supplier_items) and identifiers of every kind
INSERT INTO supplier_items (supplier_id, variant_id, supplier_sku, cost, lead_time_days, moq) VALUES (pg_temp.i('s_zinus'), pg_temp.i('v_q'), 'ZN-PH2-Q', 820.00, 3, 1), (pg_temp.i('s_zinus'), pg_temp.i('v_k'), 'ZN-PH2-K', 1000.00, 3, 1);
INSERT INTO variant_identifiers (variant_id, kind, value, created_by) VALUES
 (pg_temp.i('v_q'), 'upc', '0 12345 67890 5', 22), (pg_temp.i('v_q'), 'ean', '4006381333931', 22), (pg_temp.i('v_q'), 'mpn', 'PH2-MPN-Q-ALT', 22),
 (pg_temp.i('v_q'), 'asin', 'B0SMOKEQ01', 22), (pg_temp.i('v_q'), 'ebay_epid', '12345678', 22), (pg_temp.i('v_q'), 'walmart_item_id', '987654321', 22), (pg_temp.i('v_q'), 'other', 'LEGACY-Q', 22);
INSERT INTO variant_identifiers (variant_id, kind, value, source_id, created_by) VALUES (pg_temp.i('v_k'), 'supplier_sku', 'MLF-PH2-K', pg_temp.i('src_feed'), 22);
INSERT INTO variant_identifiers (variant_id, kind, value, created_by) VALUES (pg_temp.i('v_k'), 'gtin', pg_temp.gtin('84000000020'), 22);
SELECT pg_temp.check((SELECT count(DISTINCT kind) FROM variant_identifiers) = 9, 'identifiers of every one of the nine kinds');
SELECT pg_temp.check((SELECT value FROM variant_identifiers WHERE kind = 'upc' AND variant_id = pg_temp.i('v_q')) = '00012345678905' AND (SELECT value FROM variant_identifiers WHERE kind = 'ean') = '04006381333931', 'a UPC and an EAN identifier are normalized to GTIN-14');
SELECT pg_temp.refused($$INSERT INTO variant_identifiers (variant_id, kind, value) VALUES (pg_temp.i('v_k'), 'gtin', '012345678906')$$, 'not a valid GTIN', 'a GTIN identifier with a bad check digit is refused');
SELECT pg_temp.refused($$INSERT INTO variant_identifiers (variant_id, kind, value) VALUES (pg_temp.i('v_k'), 'supplier_sku', 'X')$$, 'belongs to a source', 'a supplier SKU names its source');
SELECT pg_temp.refused($$INSERT INTO variant_identifiers (variant_id, kind, value) VALUES (pg_temp.i('v_k'), 'asin', 'b0smokeq01')$$, 'duplicate', 'one identifier value per kind (case-insensitive)');
SELECT pg_temp.refused($$INSERT INTO variant_identifiers (variant_id, kind, value) VALUES (pg_temp.i('v_k'), 'isbn', '1')$$, 'check', 'an unknown identifier kind is refused');

-- ================================================================ locations and stock (db/008): the referee
INSERT INTO locations (name, kind, is_sellable) VALUES ('SMOKE Warehouse', 'warehouse', true), ('SMOKE Showroom', 'showroom', true), ('SMOKE Returns bay', 'returns', false);
INSERT INTO locations (name, kind, allow_negative) VALUES ('SMOKE Consignment', 'offsite', true);
SELECT pg_temp.keep('l_wh', id) FROM locations WHERE name = 'SMOKE Warehouse';
SELECT pg_temp.keep('l_sr', id) FROM locations WHERE name = 'SMOKE Showroom';
SELECT pg_temp.keep('l_ret', id) FROM locations WHERE name = 'SMOKE Returns bay';
SELECT pg_temp.keep('l_neg', id) FROM locations WHERE name = 'SMOKE Consignment';
SELECT pg_temp.refused(format($$INSERT INTO inventory_balances (variant_id, location_id, qty_on_hand) VALUES (%s, %s, 5)$$, pg_temp.i('v_q'), pg_temp.i('l_wh')), 'maintained only by transactions', 'a balance cannot be written directly');
-- a free receipt of 5 Queens and 3 Kings, posted
SELECT pg_temp.as_member(21);
INSERT INTO goods_receipts (supplier_id, location_id, delivery_note_ref, created_by) VALUES (pg_temp.i('s_zinus'), pg_temp.i('l_wh'), 'DN-1', 21);
SELECT pg_temp.keep('gr1', id) FROM goods_receipts WHERE delivery_note_ref = 'DN-1';
SELECT pg_temp.check((SELECT number FROM goods_receipts WHERE id = pg_temp.i('gr1')) = 'GR-00001', 'a receipt takes its number at creation');
INSERT INTO goods_receipt_lines (goods_receipt_id, line_no, variant_id, qty, unit_cost) VALUES (pg_temp.i('gr1'), 1, pg_temp.i('v_q'), 5, 830.00), (pg_temp.i('gr1'), 2, pg_temp.i('v_k'), 3, 1000.00);
SELECT pg_temp.refused(format($$SELECT inv_post_receipt(%s, 21)$$, 0), 'No such receipt', 'posting a receipt that does not exist is refused');
SELECT inv_post_receipt(pg_temp.i('gr1'), 21);
SELECT pg_temp.check((SELECT status FROM goods_receipts WHERE id = pg_temp.i('gr1')) = 'posted' AND (SELECT posted_by FROM goods_receipts WHERE id = pg_temp.i('gr1')) = 21, 'the receipt is posted by Wendy');
SELECT pg_temp.check((SELECT qty_on_hand FROM inventory_balances WHERE variant_id = pg_temp.i('v_q') AND location_id = pg_temp.i('l_wh')) = 5 AND (SELECT qty_on_hand FROM inventory_balances WHERE variant_id = pg_temp.i('v_k') AND location_id = pg_temp.i('l_wh')) = 3, 'the balances were maintained by the ledger''s trigger: 5 Queens, 3 Kings on hand');
SELECT pg_temp.check((SELECT count(*) FROM inventory_transactions WHERE reference_kind = 'goods_receipt' AND reference_id = pg_temp.i('gr1') AND txn_type = 'receipt' AND counterparty_kind = 'supplier') = 2, 'two receipt transactions, the supplier as counterparty');
SELECT pg_temp.refused(format($$SELECT inv_post_receipt(%s, 21)$$, pg_temp.i('gr1')), 'only a draft posts', 'a receipt posts once');
SELECT pg_temp.refused(format($$UPDATE inventory_balances SET qty_on_hand = 99 WHERE variant_id = %s$$, pg_temp.i('v_q')), 'maintained only by transactions', 'a balance cannot be updated directly either');
SELECT pg_temp.refused(format($$INSERT INTO goods_receipt_lines (goods_receipt_id, line_no, variant_id, qty) VALUES (%s, 3, %s, 1)$$, pg_temp.i('gr1'), pg_temp.i('v_q')), 'only while it is draft', 'a posted receipt''s lines are locked');
-- D11: the received cost became the variant's cost with a reason
SELECT pg_temp.as_member(22);
SELECT pg_temp.check((SELECT cost_price FROM product_variants WHERE id = pg_temp.i('v_q')) = 830.00, 'D11: the received cost became the Queen''s cost_price (cost_source = last_receipt)');
SELECT pg_temp.check((SELECT reason FROM price_history WHERE variant_id = pg_temp.i('v_q') AND kind = 'cost' ORDER BY id DESC LIMIT 1) = 'receipt GR-00001' AND (SELECT source_kind FROM price_history WHERE variant_id = pg_temp.i('v_q') AND kind = 'cost' ORDER BY id DESC LIMIT 1) = 'last_receipt', '…with the receipt''s number as the reason');
SELECT pg_temp.check((SELECT cost_price FROM product_variants WHERE id = pg_temp.i('v_k')) = 1000 AND (SELECT count(*) FROM price_history WHERE variant_id = pg_temp.i('v_k') AND kind = 'cost') = 2, 'the King''s cost 1050 → 1000 from the receipt, one more history row');
-- negative refused, unless the location allows it
SELECT pg_temp.refused(format($$SELECT inv_post_txn('issue', %s, %s, -6, NULL, 'opening', 0, 'smoke:neg1')$$, pg_temp.i('v_q'), pg_temp.i('l_wh')), 'Not enough on hand', 'an issue of 6 against 5 on hand is refused');
SELECT pg_temp.check((SELECT qty_on_hand FROM inventory_balances WHERE variant_id = pg_temp.i('v_q') AND location_id = pg_temp.i('l_wh')) = 5, '…and the balance is untouched');
SELECT inv_post_txn('issue', pg_temp.i('v_q'), pg_temp.i('l_neg'), -1, NULL, 'opening', 0, 'smoke:neg2');
SELECT pg_temp.check((SELECT qty_on_hand FROM inventory_balances WHERE variant_id = pg_temp.i('v_q') AND location_id = pg_temp.i('l_neg')) = -1, 'a location that allows negative stock goes to −1');
SELECT pg_temp.refused(format($$SELECT inv_post_txn('receipt', %s, %s, 0, NULL, 'opening', 0, 'smoke:zero')$$, pg_temp.i('v_q'), pg_temp.i('l_wh')), 'check', 'a zero movement is refused');
SELECT pg_temp.refused(format($$SELECT inv_post_txn('receipt', %s, %s, -1, NULL, 'opening', 0, 'smoke:sign')$$, pg_temp.i('v_q'), pg_temp.i('l_wh')), 'check', 'a receipt is positive, an issue negative — the sign is checked');
SELECT pg_temp.refused(format($$SELECT inv_post_txn('receipt', %s, %s, 1, NULL, 'opening', 0, 'smoke:bundle')$$, pg_temp.i('v_set'), pg_temp.i('l_wh')), 'bundle never holds stock', 'a bundle never holds stock');
SELECT pg_temp.refused(format($$SELECT inv_post_txn('receipt', %s, %s, 1, NULL, 'opening', 0, 'smoke:neg2')$$, pg_temp.i('v_q'), pg_temp.i('l_wh')), 'duplicate', 'an idempotency key posts once');
SELECT pg_temp.refused($$UPDATE inventory_transactions SET qty = 100 WHERE idempotency_key = 'smoke:neg2'$$, 'permission denied', 'a transaction is never updated (the writer has no UPDATE grant; the trigger refuses besides)');
SELECT pg_temp.refused($$DELETE FROM inventory_transactions WHERE idempotency_key = 'smoke:neg2'$$, 'permission denied', '…nor deleted');
-- a floor model through an adjustment with the floor_model reason
INSERT INTO inventory_adjustments (location_id, reason_code_id, created_by) VALUES (pg_temp.i('l_wh'), (SELECT id FROM reason_codes WHERE code = 'floor_model'), 22);
SELECT pg_temp.keep('adj_fm', id) FROM inventory_adjustments WHERE reason_code_id = (SELECT id FROM reason_codes WHERE code = 'floor_model');
INSERT INTO inventory_adjustment_lines (adjustment_id, line_no, variant_id, qty_delta) VALUES (pg_temp.i('adj_fm'), 1, pg_temp.i('v_q'), 1);
SELECT inv_post_adjustment(pg_temp.i('adj_fm'), 22);
SELECT pg_temp.check((SELECT qty_floor_model FROM inventory_balances WHERE variant_id = pg_temp.i('v_q') AND location_id = pg_temp.i('l_wh')) = 1 AND (SELECT qty_on_hand FROM inventory_balances WHERE variant_id = pg_temp.i('v_q') AND location_id = pg_temp.i('l_wh')) = 5, 'a floor model is on hand AND flagged: 5 on hand, 1 on the floor');
SELECT pg_temp.check((SELECT txn_type FROM inventory_transactions WHERE reference_kind = 'adjustment' AND reference_id = pg_temp.i('adj_fm')) = 'floor_model_in', '…posted as floor_model_in, not an adjustment of quantity');
SELECT pg_temp.refused(format($$SELECT inv_post_txn('floor_model_in', %s, %s, 9, NULL, 'opening', 0, 'smoke:fm9')$$, pg_temp.i('v_q'), pg_temp.i('l_wh')), 'cannot exceed', 'more floor models than units on hand is refused');
SELECT pg_temp.refused(format($$SELECT inv_post_txn('floor_model_out', %s, %s, -2, NULL, 'opening', 0, 'smoke:fm-2')$$, pg_temp.i('v_q'), pg_temp.i('l_wh')), 'floor_model', 'taking two off a floor with one is refused');
-- an adjustment of quantity: 1 damaged
INSERT INTO inventory_adjustments (location_id, reason_code_id, created_by) VALUES (pg_temp.i('l_wh'), (SELECT id FROM reason_codes WHERE code = 'damaged'), 22);
SELECT pg_temp.keep('adj_dmg', id) FROM inventory_adjustments WHERE reason_code_id = (SELECT id FROM reason_codes WHERE code = 'damaged');
INSERT INTO inventory_adjustment_lines (adjustment_id, line_no, variant_id, qty_delta) VALUES (pg_temp.i('adj_dmg'), 1, pg_temp.i('v_k'), -1);
SELECT inv_post_adjustment(pg_temp.i('adj_dmg'), 22);
SELECT pg_temp.check((SELECT qty_on_hand FROM inventory_balances WHERE variant_id = pg_temp.i('v_k') AND location_id = pg_temp.i('l_wh')) = 2 AND (SELECT number FROM inventory_adjustments WHERE id = pg_temp.i('adj_dmg')) = 'ADJ-00002', 'an adjustment with a reason: 3 Kings → 2, numbered ADJ-');
SELECT pg_temp.check((SELECT reason_code_id FROM inventory_transactions WHERE reference_kind = 'adjustment' AND reference_id = pg_temp.i('adj_dmg')) = (SELECT id FROM reason_codes WHERE code = 'damaged'), 'the transaction carries the reason');
INSERT INTO inventory_adjustments (location_id, reason_code_id, created_by) VALUES (pg_temp.i('l_wh'), (SELECT id FROM reason_codes WHERE code = 'comfort'), 22);
INSERT INTO inventory_adjustment_lines (adjustment_id, line_no, variant_id, qty_delta) VALUES ((SELECT id FROM inventory_adjustments WHERE reason_code_id = (SELECT id FROM reason_codes WHERE code = 'comfort')), 1, pg_temp.i('v_k'), -1);
SELECT pg_temp.refused(format($$SELECT inv_post_adjustment(%s, 22)$$, (SELECT id FROM inventory_adjustments WHERE reason_code_id = (SELECT id FROM reason_codes WHERE code = 'comfort'))), 'not an adjustment reason', 'a return reason does not post an adjustment');
-- a transfer in two halves
INSERT INTO inventory_transfers (from_location_id, to_location_id, created_by) VALUES (pg_temp.i('l_wh'), pg_temp.i('l_sr'), 22);
SELECT pg_temp.keep('tr1', id) FROM inventory_transfers WHERE from_location_id = pg_temp.i('l_wh');
INSERT INTO inventory_transfer_lines (transfer_id, line_no, variant_id, qty) VALUES (pg_temp.i('tr1'), 1, pg_temp.i('v_q'), 2);
SELECT pg_temp.refused(format($$SELECT inv_transfer_receive(%s, 22)$$, pg_temp.i('tr1')), 'only one in transit', 'a draft transfer cannot be received');
SELECT inv_transfer_send(pg_temp.i('tr1'), 22);
SELECT pg_temp.check((SELECT status FROM inventory_transfers WHERE id = pg_temp.i('tr1')) = 'in_transit' AND (SELECT qty_on_hand FROM inventory_balances WHERE variant_id = pg_temp.i('v_q') AND location_id = pg_temp.i('l_wh')) = 3, 'sending issues at the origin: 5 → 3 Queens in the warehouse, the transfer in transit');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM inventory_balances WHERE variant_id = pg_temp.i('v_q') AND location_id = pg_temp.i('l_sr')), '…and nothing at the showroom yet');
SELECT pg_temp.refused(format($$INSERT INTO inventory_transfer_lines (transfer_id, line_no, variant_id, qty) VALUES (%s, 2, %s, 1)$$, pg_temp.i('tr1'), pg_temp.i('v_k')), 'only while it is a draft', 'a sent transfer''s lines are locked');
SELECT inv_transfer_receive(pg_temp.i('tr1'), 21);
SELECT pg_temp.check((SELECT status FROM inventory_transfers WHERE id = pg_temp.i('tr1')) = 'received' AND (SELECT qty_on_hand FROM inventory_balances WHERE variant_id = pg_temp.i('v_q') AND location_id = pg_temp.i('l_sr')) = 2 AND (SELECT received_by FROM inventory_transfers WHERE id = pg_temp.i('tr1')) = 21, 'receiving puts 2 Queens away at the showroom');
SELECT pg_temp.check((SELECT count(DISTINCT group_id) FROM inventory_transactions WHERE reference_kind = 'transfer' AND reference_id = pg_temp.i('tr1')) = 2 AND (SELECT count(*) FROM inventory_transactions WHERE reference_kind = 'transfer' AND reference_id = pg_temp.i('tr1')) = 2, 'two halves: transfer_out and transfer_in, each its own posting');
SELECT pg_temp.refused($$INSERT INTO inventory_transfers (from_location_id, to_location_id) VALUES (pg_temp.i('l_wh'), pg_temp.i('l_wh'))$$, 'check', 'a transfer goes somewhere else');
-- a count with a correction
SELECT inv_count_start(pg_temp.i('l_wh'), 21);
SELECT pg_temp.keep('cnt1', id) FROM inventory_counts WHERE location_id = pg_temp.i('l_wh') AND status = 'open';
SELECT pg_temp.check((SELECT count(*) FROM inventory_count_lines WHERE count_id = pg_temp.i('cnt1')) = 2 AND (SELECT system_qty FROM inventory_count_lines WHERE count_id = pg_temp.i('cnt1') AND variant_id = pg_temp.i('v_q')) = 3, 'a count opens with every variant at the location and the system quantity frozen');
SELECT pg_temp.refused(format($$SELECT inv_count_start(%s, 21)$$, pg_temp.i('l_wh')), 'already open', 'one open count per location');
UPDATE inventory_count_lines SET counted_qty = 4, counted_by = 21, counted_at = now() WHERE count_id = pg_temp.i('cnt1') AND variant_id = pg_temp.i('v_q');
UPDATE inventory_count_lines SET counted_qty = 2, counted_by = 21, counted_at = now() WHERE count_id = pg_temp.i('cnt1') AND variant_id = pg_temp.i('v_k');
SELECT inv_post_count(pg_temp.i('cnt1'), 21);
SELECT pg_temp.check((SELECT qty_on_hand FROM inventory_balances WHERE variant_id = pg_temp.i('v_q') AND location_id = pg_temp.i('l_wh')) = 4, 'posting the count corrected the Queens 3 → 4 (one found)');
SELECT pg_temp.check((SELECT count(*) FROM inventory_transactions WHERE reference_kind = 'count' AND reference_id = pg_temp.i('cnt1')) = 1 AND (SELECT txn_type FROM inventory_transactions WHERE reference_kind = 'count' AND reference_id = pg_temp.i('cnt1')) = 'count_correction', 'one count_correction, none for the King that agreed');
SELECT pg_temp.check((SELECT number FROM inventory_counts WHERE id = pg_temp.i('cnt1')) = 'CNT-00001' AND (SELECT status FROM inventory_counts WHERE id = pg_temp.i('cnt1')) = 'posted', 'the count is numbered and posted');
-- a reversal, once
SELECT pg_temp.keep('txn_dmg', id) FROM inventory_transactions WHERE reference_kind = 'adjustment' AND reference_id = pg_temp.i('adj_dmg');
SELECT inv_reverse_transaction(pg_temp.i('txn_dmg'), 22, 'SMOKE it was not damaged');
SELECT pg_temp.check((SELECT qty_on_hand FROM inventory_balances WHERE variant_id = pg_temp.i('v_k') AND location_id = pg_temp.i('l_wh')) = 3, 'reversing the damage adjustment puts the King back: 2 → 3');
SELECT pg_temp.check((SELECT txn_type FROM inventory_transactions WHERE reverses_id = pg_temp.i('txn_dmg')) = 'reversal' AND (SELECT qty FROM inventory_transactions WHERE reverses_id = pg_temp.i('txn_dmg')) = 1, 'the reversal is the opposite movement, linked');
SELECT pg_temp.refused(format($$SELECT inv_reverse_transaction(%s, 22)$$, pg_temp.i('txn_dmg')), 'already reversed', 'a transaction is reversed once');
SELECT pg_temp.keep('txn_fm', id) FROM inventory_transactions WHERE reference_kind = 'adjustment' AND reference_id = pg_temp.i('adj_fm');
SELECT inv_reverse_transaction(pg_temp.i('txn_fm'), 22);
SELECT pg_temp.check((SELECT qty_floor_model FROM inventory_balances WHERE variant_id = pg_temp.i('v_q') AND location_id = pg_temp.i('l_wh')) = 0 AND (SELECT txn_type FROM inventory_transactions WHERE reference_kind = 'reversal' AND reference_id = pg_temp.i('txn_fm')) = 'floor_model_out', 'reversing a floor move takes the unit off the floor');
SELECT inv_post_txn('floor_model_in', pg_temp.i('v_q'), pg_temp.i('l_sr'), 1, NULL, 'opening', 0, 'smoke:sr-floor');
SELECT pg_temp.check((SELECT qty_floor_model FROM inventory_balances WHERE variant_id = pg_temp.i('v_q') AND location_id = pg_temp.i('l_sr')) = 1, 'one Queen on the showroom floor');
-- allocation
SELECT pg_temp.check(inv_allocate(pg_temp.i('v_q'), pg_temp.i('l_wh'), 1) = 1, 'allocating 1 of 4 at the warehouse');
SELECT pg_temp.refused(format($$SELECT inv_allocate(%s, %s, 4)$$, pg_temp.i('v_q'), pg_temp.i('l_wh')), 'available', 'allocating 4 more against 3 available is refused');
SELECT pg_temp.refused(format($$SELECT inv_allocate(%s, %s, 1)$$, pg_temp.i('v_q'), pg_temp.i('l_ret')), 'not sellable', 'stock at an unsellable location is never allocated');
SELECT pg_temp.check(inv_allocate(pg_temp.i('v_q'), pg_temp.i('l_wh'), -1) = 0, 'releasing it');
SELECT pg_temp.check(inv_allocate(pg_temp.i('v_q'), pg_temp.i('l_wh'), -5) = 0, 'releasing more than held floors at zero');


-- ================================================================ pulls, listings, snapshots on change, the heartbeat, removal, back-off (db/009, D8)
SELECT pg_temp.as_member(22);
SELECT pg_temp.keep('pull_c1', inv_source_pull_start(pg_temp.i('src_casper'), 'scheduled', 22));
SELECT pg_temp.check((SELECT status FROM source_pulls WHERE id = pg_temp.i('pull_c1')) = 'running' AND (SELECT last_pull_id FROM sources WHERE id = pg_temp.i('src_casper')) = pg_temp.i('pull_c1'), 'a pull starts running and the source points at it');
SELECT pg_temp.refused(format($$SELECT inv_source_pull_start(%s, 'scheduled')$$, pg_temp.i('src_casper')), 'already running', 'a second scheduled pull of a running source is refused');
SELECT pg_temp.keep('lst_c1', inv_upsert_listing(pg_temp.i('src_casper'), pg_temp.i('pull_c1'), format('{"external_id":"c-100","handle":"purple-hybrid-2","url":"https://casper.invalid/p/ph2","title":"Purple Hybrid 2 Mattress","vendor":"SMOKE Purple","product_type":"Mattress","tags":["hybrid"],"raw":{"id":100},"variants":[{"external_variant_id":"c-100-q","title":"Queen","sku":"CSP-PH2-Q","barcode":"%s","price":1649,"compare_at_price":1799,"currency":"usd","availability":"in_stock"},{"external_variant_id":"c-100-k","title":"King","sku":"CSP-PH2-K","price":1999,"availability":"out_of_stock"},{"external_variant_id":"c-100-x","title":"Olympic Queen","sku":"CSP-PH2-OQ","price":1899,"availability":"in_stock"}]}', pg_temp.gtin('840000000104'))::jsonb));
SELECT pg_temp.check((SELECT count(*) FROM listing_variants WHERE listing_id = pg_temp.i('lst_c1')) = 3, 'a normalized listing (§6.1) became a listing with three variants');
SELECT pg_temp.check((SELECT size_key FROM listing_variants WHERE external_variant_id = 'c-100-q') = 'queen' AND (SELECT size_key FROM listing_variants WHERE external_variant_id = 'c-100-x') = 'olympic_queen', 'a Shopify variant''s title is its size');
SELECT pg_temp.check((SELECT currency FROM listing_variants WHERE external_variant_id = 'c-100-q') = 'USD' AND (SELECT barcode_valid FROM listing_variants WHERE external_variant_id = 'c-100-q'), 'currency upper-cased, barcode valid');
SELECT pg_temp.check((SELECT count(*) FROM offer_snapshots o JOIN listing_variants lv ON lv.id = o.listing_variant_id WHERE lv.listing_id = pg_temp.i('lst_c1')) = 3, 'the first pull snapshots every offer once');
SELECT pg_temp.keep('lv_cq', id) FROM listing_variants WHERE external_variant_id = 'c-100-q';
SELECT pg_temp.keep('lv_ck', id) FROM listing_variants WHERE external_variant_id = 'c-100-k';
SELECT pg_temp.keep('lv_cx', id) FROM listing_variants WHERE external_variant_id = 'c-100-x';
SELECT pg_temp.check((SELECT variant_id FROM listing_variants WHERE id = pg_temp.i('lv_cq')) = pg_temp.i('v_q') AND (SELECT match_kind FROM listing_variants WHERE id = pg_temp.i('lv_cq')) = 'gtin' AND (SELECT match_confidence FROM listing_variants WHERE id = pg_temp.i('lv_cq')) = 1, 'rule 1: the Queen matched by GTIN at the pull, confidence 1');
SELECT pg_temp.check((SELECT product_id FROM listings WHERE id = pg_temp.i('lst_c1')) = pg_temp.i('p_purple'), '…and the listing matched to the product');
SELECT pg_temp.check((SELECT variant_id FROM listing_variants WHERE id = pg_temp.i('lv_ck')) IS NULL, 'the King has no identifier — unmatched');
-- the same pull again: nothing changed, no snapshot
SELECT inv_upsert_listing(pg_temp.i('src_casper'), pg_temp.i('pull_c1'), format('{"external_id":"c-100","title":"Purple Hybrid 2 Mattress","vendor":"SMOKE Purple","variants":[{"external_variant_id":"c-100-q","title":"Queen","sku":"CSP-PH2-Q","barcode":"%s","price":1649,"compare_at_price":1799,"currency":"usd","availability":"in_stock"},{"external_variant_id":"c-100-k","title":"King","sku":"CSP-PH2-K","price":1999,"availability":"out_of_stock"},{"external_variant_id":"c-100-x","title":"Olympic Queen","sku":"CSP-PH2-OQ","price":1899,"availability":"in_stock"}]}', pg_temp.gtin('840000000104'))::jsonb);
SELECT pg_temp.check((SELECT count(*) FROM offer_snapshots o JOIN listing_variants lv ON lv.id = o.listing_variant_id WHERE lv.listing_id = pg_temp.i('lst_c1')) = 3, 'the same offers again write NO snapshot (silence means unchanged)');
SELECT pg_temp.check((SELECT listings_seen FROM source_pulls WHERE id = pg_temp.i('pull_c1')) = 2 AND (SELECT listings_new FROM source_pulls WHERE id = pg_temp.i('pull_c1')) = 1 AND (SELECT variants_changed FROM source_pulls WHERE id = pg_temp.i('pull_c1')) = 0, 'the pull counts: seen 2, new 1, changed 0');
-- a price change: one snapshot, for the one that changed
SELECT inv_upsert_listing(pg_temp.i('src_casper'), pg_temp.i('pull_c1'), format('{"external_id":"c-100","title":"Purple Hybrid 2 Mattress","vendor":"SMOKE Purple","variants":[{"external_variant_id":"c-100-q","title":"Queen","sku":"CSP-PH2-Q","barcode":"%s","price":1549,"compare_at_price":1799,"currency":"usd","availability":"in_stock"},{"external_variant_id":"c-100-k","title":"King","sku":"CSP-PH2-K","price":1999,"availability":"out_of_stock"},{"external_variant_id":"c-100-x","title":"Olympic Queen","sku":"CSP-PH2-OQ","price":1899,"availability":"in_stock"}]}', pg_temp.gtin('840000000104'))::jsonb);
SELECT pg_temp.check((SELECT count(*) FROM offer_snapshots WHERE listing_variant_id = pg_temp.i('lv_cq')) = 2 AND (SELECT count(*) FROM offer_snapshots WHERE listing_variant_id = pg_temp.i('lv_ck')) = 1, 'a price change snapshots the Queen only');
SELECT pg_temp.check((SELECT variants_changed FROM source_pulls WHERE id = pg_temp.i('pull_c1')) = 1 AND (SELECT listings_changed FROM source_pulls WHERE id = pg_temp.i('pull_c1')) = 1, '…and the pull counts one changed variant, one changed listing');
SELECT pg_temp.check((SELECT price FROM offer_snapshots WHERE listing_variant_id = pg_temp.i('lv_cq') ORDER BY id DESC LIMIT 1) = 1549, 'the snapshot carries the new price');
-- the heartbeat: a point a day regardless
UPDATE offer_snapshots SET observed_at = now() - interval '2 days' WHERE listing_variant_id = pg_temp.i('lv_ck');
SELECT pg_temp.check(inv_snapshot_heartbeat() = 1, 'the heartbeat writes one point for the offer not seen in a day');
SELECT pg_temp.check((SELECT is_heartbeat FROM offer_snapshots WHERE listing_variant_id = pg_temp.i('lv_ck') ORDER BY id DESC LIMIT 1) AND inv_snapshot_heartbeat() = 0, '…flagged as a heartbeat, and not twice');
SELECT inv_source_pull_finish(pg_temp.i('pull_c1'), 'ok', NULL, '{"robots":"ok","crawl_delay":1,"user_agent":"SMOKE Mattress Co bot"}', 4, 81920);
SELECT pg_temp.check((SELECT status FROM source_pulls WHERE id = pg_temp.i('pull_c1')) = 'ok' AND (SELECT policy->>'robots' FROM source_pulls WHERE id = pg_temp.i('pull_c1')) = 'ok' AND (SELECT http_requests FROM source_pulls WHERE id = pg_temp.i('pull_c1')) = 4, 'the pull finished ok with the policy facts it followed');
SELECT pg_temp.check((SELECT last_ok_at FROM sources WHERE id = pg_temp.i('src_casper')) IS NOT NULL AND (SELECT robots_state FROM sources WHERE id = pg_temp.i('src_casper')) = 'ok' AND (SELECT consecutive_failures FROM sources WHERE id = pg_temp.i('src_casper')) = 0, '…and the source is healthy');
-- a removed listing: unseen for two pulls (timestamps staged)
UPDATE source_pulls SET started_at = now() - interval '3 hours', finished_at = now() - interval '3 hours' WHERE id = pg_temp.i('pull_c1');
UPDATE listings SET last_seen_at = now() - interval '3 hours' WHERE id = pg_temp.i('lst_c1');
UPDATE listing_variants SET last_seen_at = now() - interval '3 hours' WHERE listing_id = pg_temp.i('lst_c1');
SELECT inv_upsert_listing(pg_temp.i('src_casper'), pg_temp.i('pull_c1'), '{"external_id":"c-200","title":"Casper Original","vendor":"Casper","variants":[{"external_variant_id":"c-200-q","title":"Queen","price":1295,"availability":"in_stock"}]}');
SELECT pg_temp.keep('lst_c2', id) FROM listings WHERE external_id = 'c-200';
UPDATE listings SET last_seen_at = now() - interval '3 hours' WHERE id = pg_temp.i('lst_c2');
SELECT pg_temp.keep('pull_c2', inv_source_pull_start(pg_temp.i('src_casper'), 'scheduled', 22));
UPDATE source_pulls SET started_at = now() - interval '2 hours' WHERE id = pg_temp.i('pull_c2');
SELECT inv_upsert_listing(pg_temp.i('src_casper'), pg_temp.i('pull_c2'), format('{"external_id":"c-100","title":"Purple Hybrid 2 Mattress","vendor":"SMOKE Purple","variants":[{"external_variant_id":"c-100-q","title":"Queen","sku":"CSP-PH2-Q","barcode":"%s","price":1549,"compare_at_price":1799,"currency":"usd","availability":"in_stock"},{"external_variant_id":"c-100-k","title":"King","sku":"CSP-PH2-K","price":1999,"availability":"out_of_stock"},{"external_variant_id":"c-100-x","title":"Olympic Queen","sku":"CSP-PH2-OQ","price":1899,"availability":"in_stock"}]}', pg_temp.gtin('840000000104'))::jsonb);
UPDATE listings SET last_seen_at = now() - interval '2 hours' WHERE id = pg_temp.i('lst_c1');
SELECT pg_temp.check(inv_mark_removed(pg_temp.i('src_casper'), pg_temp.i('pull_c2')) = 0, 'after one pull without it, the Original is not yet removed');
SELECT inv_source_pull_finish(pg_temp.i('pull_c2'), 'ok');
SELECT pg_temp.keep('pull_c3', inv_source_pull_start(pg_temp.i('src_casper'), 'scheduled', 22));
UPDATE source_pulls SET started_at = now() - interval '1 hour' WHERE id = pg_temp.i('pull_c3');
SELECT inv_upsert_listing(pg_temp.i('src_casper'), pg_temp.i('pull_c3'), format('{"external_id":"c-100","title":"Purple Hybrid 2 Mattress","vendor":"SMOKE Purple","variants":[{"external_variant_id":"c-100-q","title":"Queen","sku":"CSP-PH2-Q","barcode":"%s","price":1549,"compare_at_price":1799,"currency":"usd","availability":"in_stock"},{"external_variant_id":"c-100-k","title":"King","sku":"CSP-PH2-K","price":1999,"availability":"out_of_stock"},{"external_variant_id":"c-100-x","title":"Olympic Queen","sku":"CSP-PH2-OQ","price":1899,"availability":"in_stock"}]}', pg_temp.gtin('840000000104'))::jsonb);
SELECT pg_temp.check(inv_mark_removed(pg_temp.i('src_casper'), pg_temp.i('pull_c3')) = 1, 'after two pulls without it, the Original is marked removed');
SELECT pg_temp.check((SELECT removed_at FROM listings WHERE id = pg_temp.i('lst_c2')) IS NOT NULL AND (SELECT availability FROM listing_variants WHERE external_variant_id = 'c-200-q') = 'unknown', '…its offer became unknown');
SELECT pg_temp.check((SELECT count(*) FROM offer_snapshots o JOIN listing_variants lv ON lv.id = o.listing_variant_id WHERE lv.external_variant_id = 'c-200-q') = 2, '…and a snapshot says so');
SELECT pg_temp.check((SELECT removed_at FROM listings WHERE id = pg_temp.i('lst_c1')) IS NULL AND (SELECT variant_id FROM listing_variants WHERE id = pg_temp.i('lv_cq')) = pg_temp.i('v_q'), 'the listing that was seen stays, its match kept');
SELECT inv_source_pull_finish(pg_temp.i('pull_c3'), 'ok');
SELECT inv_upsert_listing(pg_temp.i('src_casper'), NULL, '{"external_id":"c-200","title":"Casper Original","vendor":"Casper","variants":[{"external_variant_id":"c-200-q","title":"Queen","price":1295,"availability":"in_stock"}]}');
SELECT pg_temp.check((SELECT removed_at FROM listings WHERE id = pg_temp.i('lst_c2')) IS NULL, 'a removed listing seen again comes back');
-- a blocked source backs off: an hour, a day, then paused
SELECT pg_temp.keep('pull_j1', inv_source_pull_start(pg_temp.i('src_jsonld'), 'scheduled', 22));
SELECT inv_source_pull_finish(pg_temp.i('pull_j1'), 'blocked', 'HTTP 403 from bigbox.invalid');
SELECT pg_temp.check((SELECT robots_state FROM sources WHERE id = pg_temp.i('src_jsonld')) = 'blocked' AND (SELECT consecutive_failures FROM sources WHERE id = pg_temp.i('src_jsonld')) = 1
                 AND (SELECT backoff_until FROM sources WHERE id = pg_temp.i('src_jsonld')) BETWEEN now() + interval '59 minutes' AND now() + interval '61 minutes', 'a 403 marks the source blocked and backs off an hour');
SELECT pg_temp.refused(format($$SELECT inv_source_pull_start(%s, 'scheduled')$$, pg_temp.i('src_jsonld')), 'backing off', 'a scheduled pull during the back-off is refused');
SELECT pg_temp.keep('pull_j2', inv_source_pull_start(pg_temp.i('src_jsonld'), 'manual', 22));
SELECT pg_temp.check(pg_temp.i('pull_j2') IS NOT NULL, 'a person may still pull by hand');
SELECT inv_source_pull_finish(pg_temp.i('pull_j2'), 'failed', 'timeout');
SELECT pg_temp.check((SELECT consecutive_failures FROM sources WHERE id = pg_temp.i('src_jsonld')) = 2 AND (SELECT backoff_until FROM sources WHERE id = pg_temp.i('src_jsonld')) > now() + interval '23 hours', 'a second failure backs off a day');
SELECT pg_temp.keep('pull_j3', inv_source_pull_start(pg_temp.i('src_jsonld'), 'manual', 22));
SELECT inv_source_pull_finish(pg_temp.i('pull_j3'), 'failed', 'timeout again');
SELECT pg_temp.check((SELECT paused_at FROM sources WHERE id = pg_temp.i('src_jsonld')) IS NOT NULL AND (SELECT paused_reason FROM sources WHERE id = pg_temp.i('src_jsonld')) LIKE '3 failures%', 'a third failure pauses the source until a person looks');
SELECT pg_temp.refused(format($$SELECT inv_source_pull_start(%s, 'scheduled')$$, pg_temp.i('src_jsonld')), 'paused', 'a paused source is not pulled on schedule');
SELECT inv_source_resume(pg_temp.i('src_jsonld'));
SELECT pg_temp.check((SELECT paused_at FROM sources WHERE id = pg_temp.i('src_jsonld')) IS NULL AND (SELECT consecutive_failures FROM sources WHERE id = pg_temp.i('src_jsonld')) = 0, 'a person resumes it');
SELECT pg_temp.refused(format($$SELECT inv_source_pull_finish(%s, 'done')$$, pg_temp.i('pull_j3')), 'ok, partial, failed or blocked', 'a pull finishes in one of four states');
SELECT pg_temp.check((SELECT count(*) FROM inv_sources_due() WHERE id = pg_temp.i('src_jsonld')) = 1 AND (SELECT count(*) FROM inv_sources_due() WHERE id = pg_temp.i('src_casper')) = 0 AND (SELECT count(*) FROM inv_sources_due() WHERE id = pg_temp.i('src_manual')) = 0, 'the worker''s due list: the resumed source yes, the one just pulled no, the manual one never');
-- a supplier's feed pull: cost, the price sheet, supplier SKU
SELECT pg_temp.keep('pull_f1', inv_source_pull_start(pg_temp.i('src_feed'), 'scheduled', 22));
SELECT inv_upsert_listing(pg_temp.i('src_feed'), pg_temp.i('pull_f1'), format('{"external_id":"MLF-PH2-K","title":"Purple Hybrid 2 King","variants":[{"external_variant_id":"MLF-PH2-K","sku":"MLF-PH2-K","option_values":{"Size":"King"},"cost_price":980,"qty":12,"lead_time_days":5,"availability":"in_stock"}]}')::jsonb);
SELECT pg_temp.keep('lv_fk', id) FROM listing_variants WHERE external_variant_id = 'MLF-PH2-K';
SELECT pg_temp.check((SELECT match_kind FROM listing_variants WHERE id = pg_temp.i('lv_fk')) = 'supplier_sku' AND (SELECT variant_id FROM listing_variants WHERE id = pg_temp.i('lv_fk')) = pg_temp.i('v_k'), 'rule 2: the feed''s King matched by the supplier SKU identifier of that source');
SELECT pg_temp.check((SELECT cost FROM supplier_items WHERE supplier_id = pg_temp.i('s_malouf') AND variant_id = pg_temp.i('v_k')) = 980 AND (SELECT lead_time_days FROM supplier_items WHERE supplier_id = pg_temp.i('s_malouf') AND variant_id = pg_temp.i('v_k')) = 5 AND (SELECT source_id FROM supplier_items WHERE supplier_id = pg_temp.i('s_malouf') AND variant_id = pg_temp.i('v_k')) = pg_temp.i('src_feed'), 'a feed pull keeps the supplier''s price sheet: cost 980, 5 days, from the feed');
SELECT inv_upsert_listing(pg_temp.i('src_feed'), pg_temp.i('pull_f1'), format('{"external_id":"MLF-PH2-Q","title":"Purple Hybrid 2 Queen","variants":[{"external_variant_id":"MLF-PH2-Q","sku":"MLF-PH2-Q","option_values":{"Size":"Queen"},"cost_price":790,"qty":0,"lead_time_days":7,"availability":"out_of_stock"}]}')::jsonb);
SELECT pg_temp.keep('lv_fq', id) FROM listing_variants WHERE external_variant_id = 'MLF-PH2-Q';
SELECT pg_temp.check((SELECT variant_id FROM listing_variants WHERE id = pg_temp.i('lv_fq')) IS NULL, 'the feed''s Queen has no identifier we hold — unmatched');
SELECT inv_source_pull_finish(pg_temp.i('pull_f1'), 'ok');
-- the Zinus store (a supplier source): rule 2 by supplier_items, rule 3 by MPN + size
SELECT pg_temp.keep('pull_z1', inv_source_pull_start(pg_temp.i('src_zinus'), 'scheduled', 22));
SELECT inv_upsert_listing(pg_temp.i('src_zinus'), pg_temp.i('pull_z1'), '{"external_id":"z-1","title":"SMOKE Purple Hybrid 2","vendor":"SMOKE Purple","product_type":"Mattress","raw":{"length_mm":2030,"width_mm":1525},"variants":[{"external_variant_id":"z-1-q","title":"Queen","sku":"ZN-PH2-Q","price":1399,"availability":"in_stock","lead_time_days":2},{"external_variant_id":"z-1-k","title":"King","sku":"ZN-PH2-K-NEW","mpn":"PH2-MPN-K","price":1799,"availability":"in_stock","lead_time_days":4},{"external_variant_id":"z-1-ck","title":"Cal King","sku":"ZN-PH2-CK","mpn":"PH2-MPN-K","price":1799,"availability":"in_stock"},{"external_variant_id":"z-1-t","title":"Twin","sku":"ZN-PH2-T?","price":899,"availability":"in_stock"}]}');
SELECT pg_temp.keep('lv_zq', id) FROM listing_variants WHERE external_variant_id = 'z-1-q';
SELECT pg_temp.keep('lv_zk', id) FROM listing_variants WHERE external_variant_id = 'z-1-k';
SELECT pg_temp.keep('lv_zck', id) FROM listing_variants WHERE external_variant_id = 'z-1-ck';
SELECT pg_temp.keep('lv_zt', id) FROM listing_variants WHERE external_variant_id = 'z-1-t';
SELECT pg_temp.check((SELECT match_kind FROM listing_variants WHERE id = pg_temp.i('lv_zq')) = 'supplier_sku' AND (SELECT variant_id FROM listing_variants WHERE id = pg_temp.i('lv_zq')) = pg_temp.i('v_q'), 'rule 2: the Zinus Queen matched by the price sheet''s supplier SKU');
SELECT pg_temp.check((SELECT match_kind FROM listing_variants WHERE id = pg_temp.i('lv_zk')) = 'mpn' AND (SELECT variant_id FROM listing_variants WHERE id = pg_temp.i('lv_zk')) = pg_temp.i('v_k'), 'rule 3: the King matched by MPN + size');
SELECT pg_temp.check((SELECT variant_id FROM listing_variants WHERE id = pg_temp.i('lv_zck')) IS NULL, 'the Cal King with the King''s MPN did NOT match across sizes');
SELECT pg_temp.check((SELECT variant_id FROM listing_variants WHERE id = pg_temp.i('lv_zt')) IS NULL, 'the Twin with no identifier is unmatched');
SELECT inv_source_pull_finish(pg_temp.i('pull_z1'), 'ok');
-- rule 4: a marketplace id
INSERT INTO sources (name, connector, role, base_url) VALUES ('SMOKE Amazon ref', 'amazon', 'reference', 'https://amazon.invalid');
SELECT pg_temp.keep('src_amz', id) FROM sources WHERE name = 'SMOKE Amazon ref';
SELECT inv_upsert_listing(pg_temp.i('src_amz'), NULL, '{"external_id":"B0SMOKEQ01","title":"Purple Hybrid 2 Queen Mattress","variants":[{"external_variant_id":"B0SMOKEQ01","title":"Queen","price":1699,"availability":"in_stock"}]}');
SELECT pg_temp.keep('lv_amz', id) FROM listing_variants WHERE external_variant_id = 'B0SMOKEQ01';
SELECT pg_temp.check((SELECT match_kind FROM listing_variants WHERE id = pg_temp.i('lv_amz')) = 'marketplace_id' AND (SELECT variant_id FROM listing_variants WHERE id = pg_temp.i('lv_amz')) = pg_temp.i('v_q'), 'rule 4: an ASIN recorded as an identifier matches the marketplace listing');
-- rule 5: a person's match; never across sizes
SELECT pg_temp.refused(format($$SELECT inv_listing_match(%s, %s, 22)$$, pg_temp.i('lv_zck'), pg_temp.i('v_k')), 'never crosses sizes', 'rule 5 refuses a Cal King listing on the King variant');
SELECT inv_listing_match(pg_temp.i('lv_zck'), pg_temp.i('v_ck'), 22);
SELECT pg_temp.check((SELECT match_kind FROM listing_variants WHERE id = pg_temp.i('lv_zck')) = 'manual' AND (SELECT matched_by FROM listing_variants WHERE id = pg_temp.i('lv_zck')) = 22 AND (SELECT variant_id FROM listing_variants WHERE id = pg_temp.i('lv_zck')) = pg_temp.i('v_ck'), 'rule 5: Bea matched the Cal King by hand — remembered with who');
SELECT inv_upsert_listing(pg_temp.i('src_zinus'), NULL, '{"external_id":"z-1","title":"SMOKE Purple Hybrid 2","vendor":"SMOKE Purple","variants":[{"external_variant_id":"z-1-ck","title":"Cal King","sku":"ZN-PH2-CK","mpn":"PH2-MPN-K","price":1749,"availability":"in_stock"}]}');
SELECT pg_temp.check((SELECT variant_id FROM listing_variants WHERE id = pg_temp.i('lv_zck')) = pg_temp.i('v_ck') AND (SELECT price FROM listing_variants WHERE id = pg_temp.i('lv_zck')) = 1749, 'the next pull keeps the person''s match and takes the new price');
SELECT pg_temp.refused(format($$SELECT inv_listing_match(%s, %s, 22, 'gtin')$$, pg_temp.i('lv_zt'), pg_temp.i('v_t')), 'manual or an accepted proposal', 'a person''s match is manual or an accepted proposal');
-- rule 6: proposals — the matcher's scoring, a dismissal remembered, an agent never accepts its own
SELECT pg_temp.check(inv_propose_matches(pg_temp.i('lv_zt')) >= 1, 'the matcher proposes candidates for the unmatched Twin (brand, name tokens, size)');
SELECT pg_temp.keep('prop_t', id) FROM match_proposals WHERE listing_variant_id = pg_temp.i('lv_zt') AND variant_id = pg_temp.i('v_t');
SELECT pg_temp.check(pg_temp.i('prop_t') IS NOT NULL AND (SELECT confidence FROM match_proposals WHERE id = pg_temp.i('prop_t')) >= 0.5 AND (SELECT evidence->>'size' FROM match_proposals WHERE id = pg_temp.i('prop_t')) = 'true' AND (SELECT evidence->>'brand' FROM match_proposals WHERE id = pg_temp.i('prop_t')) = 'true', 'the Twin is proposed with its evidence: brand, size, name tokens');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM match_proposals WHERE listing_variant_id = pg_temp.i('lv_zt') AND variant_id = pg_temp.i('v_q')), 'no proposal crosses sizes');
SELECT pg_temp.check(inv_propose_matches(pg_temp.i('lv_zt')) = 0, 'proposing again adds nothing (one row per pair)');
SELECT pg_temp.refused(format($$SELECT inv_proposal_accept(%s, 30)$$, pg_temp.i('prop_t')), 'An agent never accepts', 'D6: an agent cannot accept the matcher''s proposal');
INSERT INTO match_proposals (listing_variant_id, variant_id, confidence, evidence, proposed_by) VALUES (pg_temp.i('lv_fq'), pg_temp.i('v_q'), 0.8, '{"by":"agent"}', 31);
SELECT pg_temp.keep('prop_agent', id) FROM match_proposals WHERE listing_variant_id = pg_temp.i('lv_fq') AND proposed_by = 31;
SELECT pg_temp.refused(format($$SELECT inv_proposal_accept(%s, 31)$$, pg_temp.i('prop_agent')), 'An agent never accepts', 'D6: an agent never accepts its own proposal');
SELECT pg_temp.refused(format($$SELECT inv_proposal_accept(%s, 30)$$, pg_temp.i('prop_agent')), 'An agent never accepts', '…nor another agent''s');
SELECT inv_proposal_dismiss(pg_temp.i('prop_agent'), 22);
SELECT pg_temp.check((SELECT status FROM match_proposals WHERE id = pg_temp.i('prop_agent')) = 'dismissed' AND (SELECT decided_by FROM match_proposals WHERE id = pg_temp.i('prop_agent')) = 22, 'Bea dismissed the agent''s proposal');
SELECT pg_temp.check(inv_propose_matches(pg_temp.i('lv_fq')) = 0 AND (SELECT variant_id FROM listing_variants WHERE id = pg_temp.i('lv_fq')) IS NULL, 'a dismissed pair is remembered and never proposed again');
SELECT pg_temp.refused(format($$SELECT inv_proposal_dismiss(%s, 22)$$, pg_temp.i('prop_agent')), 'pending proposal', 'a decided proposal is not dismissed again');
INSERT INTO match_proposals (listing_variant_id, variant_id, confidence, evidence, proposed_by) VALUES (pg_temp.i('lv_fq'), pg_temp.i('v_t'), 0.6, '{"by":"Sam"}', 20);
SELECT pg_temp.keep('prop_sam', id) FROM match_proposals WHERE listing_variant_id = pg_temp.i('lv_fq') AND proposed_by = 20;
SELECT pg_temp.refused(format($$SELECT inv_proposal_accept(%s, 30)$$, pg_temp.i('prop_sam')), 'never crosses sizes', 'accepting a proposal still never crosses sizes (Queen listing, Twin variant)');
SELECT inv_proposal_accept(pg_temp.i('prop_t'), 22);
SELECT pg_temp.check((SELECT match_kind FROM listing_variants WHERE id = pg_temp.i('lv_zt')) = 'proposed_accepted' AND (SELECT variant_id FROM listing_variants WHERE id = pg_temp.i('lv_zt')) = pg_temp.i('v_t') AND (SELECT status FROM match_proposals WHERE id = pg_temp.i('prop_t')) = 'accepted', 'rule 6: Bea accepted the Twin proposal — recorded as proposed_accepted with the evidence on the proposal');
SELECT pg_temp.check((SELECT count(*) FROM match_proposals WHERE listing_variant_id = pg_temp.i('lv_zt') AND status = 'proposed') = 0, '…and the other candidates for that listing were dismissed');
SELECT inv_listing_unmatch(pg_temp.i('lv_amz'));
SELECT pg_temp.check((SELECT variant_id FROM listing_variants WHERE id = pg_temp.i('lv_amz')) IS NULL AND (SELECT match_kind FROM listing_variants WHERE id = pg_temp.i('lv_amz')) IS NULL, 'unmatching clears the match');
SELECT pg_temp.check(inv_match_listing_variant(pg_temp.i('lv_amz')) = 'marketplace_id', '…and the matcher finds it again by its identifier');
SELECT pg_temp.refused(format($$UPDATE listing_variants SET match_kind = 'gtin' WHERE id = %s$$, pg_temp.i('lv_fq')), 'check', 'a match kind without a variant is refused (the pair is one fact)');

-- ================================================================ availability and ATP (db/014, §7 A1, A2, C4)
SELECT pg_temp.as_member(22);
SELECT pg_temp.keept('av_q', inv_availability(pg_temp.i('v_q'))::text);
SELECT pg_temp.check((pg_temp.t('av_q')::jsonb->'own_available')::integer = 4 AND jsonb_array_length(pg_temp.t('av_q')::jsonb->'own') = 3, 'availability: own stock by location (4 warehouse + (2 − 1 floor) showroom − 1 consignment = 4 sellable, three locations listed)');
SELECT pg_temp.check((pg_temp.t('av_q')::jsonb->'prices'->>'cost')::numeric = 830 AND (pg_temp.t('av_q')::jsonb->'prices'->>'cost_withheld') = 'false', '…the Buyer sees cost and margin');
SELECT pg_temp.check(jsonb_array_length(pg_temp.t('av_q')::jsonb->'offers') = 1 AND (pg_temp.t('av_q')::jsonb->'offers'->0->>'source') = 'SMOKE Zinus store' AND (pg_temp.t('av_q')::jsonb->'offers'->0->>'cost')::numeric = 820 AND (pg_temp.t('av_q')::jsonb->'offers'->0->>'lead_time_days')::integer = 2, '…one supplier offer: Zinus at the price sheet''s cost 820, 2 days');
SELECT pg_temp.check(jsonb_array_length(pg_temp.t('av_q')::jsonb->'references') = 2 AND (pg_temp.t('av_q')::jsonb->'references'->0->>'source') = 'SMOKE Casper site' AND (pg_temp.t('av_q')::jsonb->'references'->0->>'price')::numeric = 1549, '…two references ranked by price, Casper first at 1549');
SELECT pg_temp.check((pg_temp.t('av_q')::jsonb->>'best_lead_time_days')::integer = 0 AND (pg_temp.t('av_q')::jsonb->>'state') = 'in_stock', '…in stock, best lead time 0');
-- the King: two supplier offers ranked by cost then lead time
SELECT pg_temp.keept('av_k', inv_availability(pg_temp.i('v_k'))::text);
SELECT pg_temp.check(jsonb_array_length(pg_temp.t('av_k')::jsonb->'offers') = 2 AND (pg_temp.t('av_k')::jsonb->'offers'->0->>'source') = 'SMOKE Malouf dealer feed' AND (pg_temp.t('av_k')::jsonb->'offers'->0->>'cost')::numeric = 980 AND (pg_temp.t('av_k')::jsonb->'offers'->1->>'cost')::numeric = 1000, 'the King''s suppliers rank by cost: Malouf 980 (5 days) before Zinus 1000 (4 days)');
INSERT INTO supplier_items (supplier_id, variant_id, supplier_sku, cost, lead_time_days) VALUES (pg_temp.i('s_malouf'), pg_temp.i('v_q'), 'MLF-PH2-Q', 820.00, 6) ON CONFLICT (supplier_id, variant_id) DO UPDATE SET cost = 820, lead_time_days = 6;
SELECT inv_listing_match(pg_temp.i('lv_fq'), pg_temp.i('v_q'), 22);
UPDATE listing_variants SET availability = 'in_stock', qty = 3, cost_price = 820 WHERE id = pg_temp.i('lv_fq');
SELECT pg_temp.keept('av_q2', inv_availability(pg_temp.i('v_q'))::text);
SELECT pg_temp.check(jsonb_array_length(pg_temp.t('av_q2')::jsonb->'offers') = 2 AND (pg_temp.t('av_q2')::jsonb->'offers'->0->>'source') = 'SMOKE Zinus store' AND (pg_temp.t('av_q2')::jsonb->'offers'->1->>'source') = 'SMOKE Malouf dealer feed', 'at equal cost (820) the shorter lead time ranks first: Zinus 2 days before Malouf 7');
UPDATE listing_variants SET availability = 'out_of_stock' WHERE id = pg_temp.i('lv_zq');
SELECT pg_temp.keept('av_q3', inv_availability(pg_temp.i('v_q'))::text);
SELECT pg_temp.check((pg_temp.t('av_q3')::jsonb->'offers'->0->>'source') = 'SMOKE Malouf dealer feed' AND (pg_temp.t('av_q3')::jsonb->'offers'->1->>'availability') = 'out_of_stock', 'an out-of-stock offer ranks after one in stock whatever its cost');
UPDATE listing_variants SET availability = 'in_stock' WHERE id = pg_temp.i('lv_zq');
-- a Viewer: cost withheld everywhere in the answer
SELECT pg_temp.as_member(23);
SELECT pg_temp.keept('av_v', inv_availability(pg_temp.i('v_q'))::text);
SELECT pg_temp.check((pg_temp.t('av_v')::jsonb->'prices'->'cost') = 'null'::jsonb AND (pg_temp.t('av_v')::jsonb->'prices'->>'cost_withheld') = 'true' AND (pg_temp.t('av_v')::jsonb->'offers'->0->'cost') = 'null'::jsonb AND (pg_temp.t('av_v')::jsonb->'prices'->>'retail')::numeric = 1599, 'a Viewer gets the same answer with every cost null and retail intact');
SELECT pg_temp.check(jsonb_array_length(pg_temp.t('av_v')::jsonb->'offers') = 2 AND (pg_temp.t('av_v')::jsonb->'offers'->0->>'lead_time_days') IS NOT NULL, '…and still sees the offers'' availability and lead times');
SELECT pg_temp.as_member(32);
SELECT pg_temp.refused(format($$SELECT inv_availability(%s)$$, pg_temp.i('v_q')), 'Not admitted', 'an unadmitted agent is refused the answer');
-- ATP
SELECT pg_temp.as_member(20);
SELECT pg_temp.check((inv_atp(pg_temp.i('v_q'), 2)->>'from') = 'stock' AND (inv_atp(pg_temp.i('v_q'), 2)->>'can_promise') = 'true' AND (inv_atp(pg_temp.i('v_q'), 2)->>'by')::date = current_date, 'ATP: two Queens can be promised today from stock');
SELECT pg_temp.check((inv_atp(pg_temp.i('v_q'), 6)->>'from') = 'source' AND (inv_atp(pg_temp.i('v_q'), 6)->>'source') = 'SMOKE Zinus store' AND (inv_atp(pg_temp.i('v_q'), 6)->>'by')::date = current_date + 2, 'ATP: six Queens come from the cheapest in-stock supplier, by today + its lead time');
SELECT pg_temp.check((inv_atp(pg_temp.i('v_q'), 6)->'cost') = 'null'::jsonb AND (inv_atp(pg_temp.i('v_q'), 6)->>'cost_withheld') = 'true', '…Sales is told the source but not its cost');
SELECT pg_temp.check((inv_atp(pg_temp.i('v_q'), 6, current_date + 1)->>'can_promise') = 'false' AND (inv_atp(pg_temp.i('v_q'), 6, current_date + 1)->>'reason') LIKE 'a supplier has it, but not by%', 'ATP: not by tomorrow — the reason says so');
SELECT pg_temp.check((inv_atp(pg_temp.i('v_ck'), 1)->>'from') = 'source' AND (inv_atp(pg_temp.i('v_ck'), 1)->>'source') = 'SMOKE Zinus store', 'ATP for a size with no stock finds the supplier');
SELECT pg_temp.check((inv_atp(pg_temp.i('v_fq'), 1)->>'can_promise') = 'false', 'ATP for a foundation nobody offers: no');
SELECT pg_temp.refused(format($$SELECT inv_atp(%s, 0)$$, pg_temp.i('v_q')), 'at least one', 'ATP is for at least one');
-- bundles
SELECT pg_temp.as_member(22);
SELECT pg_temp.check((inv_bundle_availability(pg_temp.i('v_set'))->>'sets_available')::integer = 0 AND (inv_bundle_availability(pg_temp.i('v_set'))->>'state') = 'unavailable', 'a bundle''s availability is the minimum over its components: no foundations, no sets');
SELECT inv_post_txn('receipt', pg_temp.i('v_fq'), pg_temp.i('l_wh'), 3, 120, 'opening', 0, 'smoke:found3');
SELECT pg_temp.check((inv_bundle_availability(pg_temp.i('v_set'))->>'sets_available')::integer = 3 AND (inv_availability(pg_temp.i('v_set'))->>'state') = 'in_stock', 'three foundations in: three sets (inv_availability answers a bundle the same way)');
-- find
SELECT pg_temp.check((SELECT count(*) FROM inv_find('purple hybrid')) = 9 AND (SELECT count(*) FROM inv_find('purple hybrid') WHERE score = 1) = 7 AND (SELECT count(*) FROM inv_find('purple hybrid', 'Queen')) = 3 AND (SELECT sku FROM inv_find('purple hybrid') ORDER BY score DESC, sku LIMIT 1) LIKE 'PH2-%', 'find "purple hybrid": the six sizes and the set first (score 1), the two foundations after (the brand); with size Queen: three');
SELECT pg_temp.check((SELECT variant_id FROM inv_find(pg_temp.gtin('840000000104')) LIMIT 1) = pg_temp.i('v_q') AND (SELECT score FROM inv_find(pg_temp.gtin('840000000104')) LIMIT 1) = 10, 'find by GTIN is exact and first');
SELECT pg_temp.check((SELECT variant_id FROM inv_find('ph2-k') LIMIT 1) = pg_temp.i('v_k') AND (SELECT variant_id FROM inv_find('B0SMOKEQ01') LIMIT 1) = pg_temp.i('v_q'), 'find by SKU and by ASIN');
SELECT pg_temp.check((SELECT count(*) FROM inv_find('purple', NULL, '{"in_stock_only":true}')) = 6 AND (SELECT count(*) FROM inv_find('purple', NULL, '{"attributes":{"type":"hybrid"}}')) = 6, 'find filters: in stock only (Queen, King, Twin, the Cal King from Zinus, the foundation Queen, the set); by attribute: the six hybrids');
SELECT pg_temp.check((SELECT own_available FROM inv_find('ph2-q')) = 4 AND (SELECT best_offer->>'source' FROM inv_find('ph2-q')) = 'SMOKE Zinus store' AND (SELECT state FROM inv_find('ph2-ck')) = 'from_supplier', 'find''s cards carry own stock, the best offer and the state');
SELECT pg_temp.as_member(23);
SELECT pg_temp.check((SELECT cost_price FROM inv_find('ph2-q')) IS NULL AND (SELECT cost_withheld FROM inv_find('ph2-q')) AND (SELECT best_offer->'cost' FROM inv_find('ph2-q')) = 'null'::jsonb, 'a Viewer''s find card withholds cost everywhere');

-- ================================================================ customers and orders (db/010, D9, D10)
SELECT pg_temp.as_member(22);
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = 'customers'::regclass AND contype = 'f' AND conkey = ARRAY[(SELECT attnum FROM pg_attribute WHERE attrelid = 'customers'::regclass AND attname = 'income_account_id')]), 'customers.income_account_id has NO foreign key (the chart is the ledger''s — §6.3)');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = 'customers'::regclass AND contype = 'f' AND confrelid = 'tax_rates'::regclass), '…and tax_rate_id keeps its FK to our tax_rates');
SELECT pg_temp.check((SELECT count(*) FROM information_schema.columns WHERE table_name = 'customers' AND column_name IN ('name','legal_name','email','phone','billing_address','shipping_address','tax_id','terms_days','currency','income_account_id','tax_rate_id','member_id','notes','archived_at','created_by','phone_alt','source','email_opt_in')) = 18, 'customers is GL''s shape with phone_alt, source and email_opt_in appended');
SELECT pg_temp.as_member(20);
INSERT INTO customers (name, email, phone, shipping_address, source, created_by) VALUES ('SMOKE Alvarez', 'alvarez@example.invalid', '+1 312 555 0100', '12 Lake St, Chicago IL 60601', 'walk_in', 20);
SELECT pg_temp.keep('cust', id) FROM customers WHERE name = 'SMOKE Alvarez';
INSERT INTO sales_orders (customer_id, salesperson_member_id, location_id, delivery_method, created_by, promised_on) VALUES (pg_temp.i('cust'), 20, pg_temp.i('l_sr'), 'white_glove', 20, current_date + 7);
SELECT pg_temp.keep('so1', id) FROM sales_orders WHERE customer_id = pg_temp.i('cust');
SELECT pg_temp.check((SELECT number FROM sales_orders WHERE id = pg_temp.i('so1')) = 'SO-00003' AND (SELECT status FROM sales_orders WHERE id = pg_temp.i('so1')) = 'quote' AND (SELECT ship_to_name FROM sales_orders WHERE id = pg_temp.i('so1')) = 'SMOKE Alvarez', 'a quote is numbered SO- and takes the customer''s ship-to');
SELECT pg_temp.check((SELECT tax_rate_id FROM sales_orders WHERE id = pg_temp.i('so1')) = (SELECT id FROM tax_rates WHERE is_default), 'the default tax rate is taken');
-- lines: one from the warehouse, one Cal King drop-shipped from Zinus, one pickup
INSERT INTO sales_order_lines (sales_order_id, line_no, variant_id, qty, fulfilment_kind, location_id) VALUES (pg_temp.i('so1'), 1, pg_temp.i('v_q'), 1, 'stock', pg_temp.i('l_wh'));
INSERT INTO sales_order_lines (sales_order_id, line_no, variant_id, qty, fulfilment_kind, listing_variant_id, discount) VALUES (pg_temp.i('so1'), 2, pg_temp.i('v_ck'), 1, 'dropship', pg_temp.i('lv_zck'), 100);
INSERT INTO sales_order_lines (sales_order_id, line_no, variant_id, qty, fulfilment_kind, listing_variant_id) VALUES (pg_temp.i('so1'), 3, pg_temp.i('v_k'), 1, 'dropship', pg_temp.i('lv_fk'));
SELECT pg_temp.check((SELECT unit_price FROM sales_order_lines WHERE sales_order_id = pg_temp.i('so1') AND line_no = 1) = 1599 AND (SELECT line_total FROM sales_order_lines WHERE sales_order_id = pg_temp.i('so1') AND line_no = 2) = 1999, 'a line takes the retail price; the Cal King 2099 − 100 = 1999');
SELECT pg_temp.check((SELECT offer_cost FROM sales_order_lines WHERE sales_order_id = pg_temp.i('so1') AND line_no = 2) = 1749 AND (SELECT offer_lead_time_days FROM sales_order_lines WHERE sales_order_id = pg_temp.i('so1') AND line_no = 2) = 3 AND (SELECT source_id FROM sales_order_lines WHERE sales_order_id = pg_temp.i('so1') AND line_no = 2) = pg_temp.i('src_zinus'), 'the drop-ship line snapshots the offer: Zinus'' price as cost (no sheet), the supplier''s 3-day lead time');
SELECT pg_temp.check((SELECT offer_cost FROM sales_order_lines WHERE sales_order_id = pg_temp.i('so1') AND line_no = 3) = 980 AND (SELECT offer_lead_time_days FROM sales_order_lines WHERE sales_order_id = pg_temp.i('so1') AND line_no = 3) = 5, '…the King from the feed at the feed''s cost 980 and 5 days');
SELECT pg_temp.check((SELECT subtotal FROM sales_orders WHERE id = pg_temp.i('so1')) = 1599 + 1999 + 2099 AND (SELECT total FROM sales_orders WHERE id = pg_temp.i('so1')) = 5697 AND (SELECT discount_total FROM sales_orders WHERE id = pg_temp.i('so1')) = 100, 'totals are recomputed by trigger: 5697, discount 100');
UPDATE sales_orders SET tax_rate_id = (SELECT id FROM tax_rates WHERE name = 'SMOKE Cook County'), shipping_charge = 150 WHERE id = pg_temp.i('so1');
SELECT pg_temp.check((SELECT tax_total FROM sales_orders WHERE id = pg_temp.i('so1')) = round(5697 * 0.1025, 2) AND (SELECT total FROM sales_orders WHERE id = pg_temp.i('so1')) = 5697 + round(5697 * 0.1025, 2) + 150, 'a tax rate and a shipping charge recompute the total');
SELECT pg_temp.refused(format($$INSERT INTO sales_order_lines (sales_order_id, line_no, variant_id, qty, fulfilment_kind, listing_variant_id) VALUES (%s, 9, %s, 1, 'dropship', %s)$$, pg_temp.i('so1'), pg_temp.i('v_q'), pg_temp.i('lv_cq')), 'a reference', 'a drop-ship line against a reference offer is refused');
SELECT pg_temp.refused(format($$INSERT INTO sales_order_lines (sales_order_id, line_no, variant_id, qty, fulfilment_kind, listing_variant_id) VALUES (%s, 9, %s, 1, 'dropship', %s)$$, pg_temp.i('so1'), pg_temp.i('v_q'), pg_temp.i('lv_fk')), 'not matched to this variant', 'a drop-ship line against another variant''s offer is refused');
SELECT pg_temp.refused(format($$INSERT INTO sales_order_lines (sales_order_id, line_no, variant_id, qty, fulfilment_kind) VALUES (%s, 9, %s, 1, 'stock')$$, pg_temp.i('so1'), pg_temp.i('v_q')), 'check', 'a stock line needs a location');
SELECT pg_temp.refused(format($$INSERT INTO sales_order_lines (sales_order_id, line_no, variant_id, qty, fulfilment_kind, location_id) VALUES (%s, 9, %s, 1, 'stock', %s)$$, pg_temp.i('so1'), pg_temp.i('v_set'), pg_temp.i('l_wh')), 'sold as its components', 'a bundle is sold as its components');
-- payments: a record, status derived
INSERT INTO order_payments (sales_order_id, kind, amount, method, reference) VALUES (pg_temp.i('so1'), 'deposit', 1000, 'card', '4242');
SELECT pg_temp.check((SELECT payment_status FROM sales_orders WHERE id = pg_temp.i('so1')) = 'deposit' AND (SELECT amount_paid FROM sales_orders WHERE id = pg_temp.i('so1')) = 1000 AND (SELECT taken_by FROM order_payments WHERE sales_order_id = pg_temp.i('so1')) = 20, 'a deposit recorded: status deposit, taken by Sam');
-- confirm: a stock line that cannot be covered is refused unless backorder
SELECT pg_temp.as_member(1);
UPDATE sales_order_lines SET qty = 9 WHERE sales_order_id = pg_temp.i('so1') AND line_no = 1;
SELECT pg_temp.refused(format($$SELECT inv_order_confirm(%s, 20)$$, pg_temp.i('so1')), 'make it a backorder', 'confirming with 9 Queens against 4 at the warehouse is refused');
SELECT pg_temp.check((SELECT status FROM sales_orders WHERE id = pg_temp.i('so1')) = 'quote' AND (SELECT qty_allocated FROM inventory_balances WHERE variant_id = pg_temp.i('v_q') AND location_id = pg_temp.i('l_wh')) = 0, '…and nothing was allocated');
UPDATE sales_order_lines SET qty = 1 WHERE sales_order_id = pg_temp.i('so1') AND line_no = 1;
INSERT INTO sales_order_lines (sales_order_id, line_no, variant_id, qty, fulfilment_kind, location_id) VALUES (pg_temp.i('so1'), 4, pg_temp.i('v_t'), 2, 'backorder', pg_temp.i('l_wh'));
SELECT pg_temp.as_member(20);
SELECT inv_order_confirm(pg_temp.i('so1'), 20);
SELECT pg_temp.check((SELECT status FROM sales_orders WHERE id = pg_temp.i('so1')) = 'confirmed' AND (SELECT confirmed_by FROM sales_orders WHERE id = pg_temp.i('so1')) = 20, 'the quote is confirmed by Sam');
SELECT pg_temp.check((SELECT qty_allocated FROM inventory_balances WHERE variant_id = pg_temp.i('v_q') AND location_id = pg_temp.i('l_wh')) = 1 AND (SELECT status FROM sales_order_lines WHERE sales_order_id = pg_temp.i('so1') AND line_no = 1) = 'allocated', 'confirming allocated the stock line (1 Queen held at the warehouse)');
SELECT pg_temp.check((SELECT status FROM sales_order_lines WHERE sales_order_id = pg_temp.i('so1') AND line_no = 4) = 'open' AND (SELECT qty_allocated FROM sales_order_lines WHERE sales_order_id = pg_temp.i('so1') AND line_no = 4) = 0, 'the backorder line holds nothing');
SELECT pg_temp.check((SELECT count(*) FROM purchase_orders WHERE sales_order_id = pg_temp.i('so1')) = 2 AND (SELECT count(DISTINCT supplier_id) FROM purchase_orders WHERE sales_order_id = pg_temp.i('so1')) = 2, 'one drop-ship PO per supplier was drafted: Zinus and Malouf');
SELECT pg_temp.keep('po_z', id) FROM purchase_orders WHERE sales_order_id = pg_temp.i('so1') AND supplier_id = pg_temp.i('s_zinus');
SELECT pg_temp.keep('po_m', id) FROM purchase_orders WHERE sales_order_id = pg_temp.i('so1') AND supplier_id = pg_temp.i('s_malouf');
SELECT pg_temp.check((SELECT status FROM purchase_orders WHERE id = pg_temp.i('po_z')) = 'draft' AND (SELECT kind FROM purchase_orders WHERE id = pg_temp.i('po_z')) = 'dropship' AND (SELECT ship_to_kind FROM purchase_orders WHERE id = pg_temp.i('po_z')) = 'customer' AND (SELECT ship_to_name FROM purchase_orders WHERE id = pg_temp.i('po_z')) = 'SMOKE Alvarez', 'the PO is a DRAFT drop-ship addressed to the customer — never sent by the confirmation');
SELECT pg_temp.check((SELECT unit_cost FROM purchase_order_lines WHERE purchase_order_id = pg_temp.i('po_z')) = 1749 AND (SELECT expected_on FROM purchase_order_lines WHERE purchase_order_id = pg_temp.i('po_z')) = current_date + 3 AND (SELECT supplier_sku FROM purchase_order_lines WHERE purchase_order_id = pg_temp.i('po_z')) = 'ZN-PH2-CK', 'its line carries the LINE''s snapshotted cost and lead time and the supplier''s SKU');
SELECT pg_temp.check((SELECT purchase_order_line_id FROM sales_order_lines WHERE sales_order_id = pg_temp.i('so1') AND line_no = 2) = (SELECT id FROM purchase_order_lines WHERE purchase_order_id = pg_temp.i('po_z')), 'the order line points at its PO line');
SELECT pg_temp.check((SELECT total FROM purchase_orders WHERE id = pg_temp.i('po_m')) = 980 AND (SELECT number FROM purchase_orders WHERE id = pg_temp.i('po_m')) LIKE 'PO-%', 'the Malouf PO totals 980, numbered PO-');
SELECT pg_temp.refused(format($$INSERT INTO sales_order_lines (sales_order_id, line_no, variant_id, qty, fulfilment_kind, location_id) VALUES (%s, 9, %s, 1, 'stock', %s)$$, pg_temp.i('so1'), pg_temp.i('v_q'), pg_temp.i('l_wh')), 'only while it is a quote', 'a confirmed order''s lines are locked');
SELECT pg_temp.refused(format($$SELECT inv_order_confirm(%s, 20)$$, pg_temp.i('so1')), 'only a quote is confirmed', 'an order confirms once');
SELECT pg_temp.check((SELECT payment_status FROM sales_orders WHERE id = pg_temp.i('so1')) = 'deposit', 'the deposit stands');
-- the customer's door
SELECT pg_temp.keept('olink', inv_order_link_mint(pg_temp.i('so1')));
SELECT pg_temp.check(pg_temp.t('olink') ~ '^[a-f0-9]{48}$', 'the order link is 48 hex, shown once');
SELECT pg_temp.keep('opened', inv_secure_link_order(pg_temp.t('olink')));
SELECT pg_temp.check(pg_temp.i('opened') = pg_temp.i('so1') AND (SELECT view_count FROM order_links_secure WHERE sales_order_id = pg_temp.i('so1') AND rotated_at IS NULL) = 1, 'the token opens the order and counts the view');
SELECT pg_temp.check(inv_secure_link_order('deadbeef') IS NULL AND inv_secure_link_order(repeat('0', 48)) IS NULL, 'a bad token opens nothing — the same nothing for every reason');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM order_links_secure WHERE token_hash = pg_temp.t('olink')), 'only the hash is stored');
SELECT pg_temp.keept('olink2', inv_order_link_mint(pg_temp.i('so1')));
SELECT pg_temp.check(inv_secure_link_order(pg_temp.t('olink')) IS NULL AND inv_secure_link_order(pg_temp.t('olink2')) = pg_temp.i('so1'), 'rotating kills the old link and opens the new');
SELECT pg_temp.check((SELECT expires_at FROM order_links_secure WHERE sales_order_id = pg_temp.i('so1') AND rotated_at IS NULL) IS NULL, 'an open order''s link does not expire yet');

-- ================================================================ purchasing and the supplier's door (db/011, D9)
SELECT pg_temp.as_member(22);
SELECT pg_temp.refused(format($$SELECT inv_po_acknowledge(%s, NULL, 'Z-1', current_date + 3)$$, pg_temp.i('po_z')), 'a sent order is acknowledged', 'a draft PO cannot be acknowledged');
SELECT inv_po_send(pg_temp.i('po_z'), 22, 'email');
SELECT pg_temp.check((SELECT status FROM purchase_orders WHERE id = pg_temp.i('po_z')) = 'sent' AND (SELECT sent_by FROM purchase_orders WHERE id = pg_temp.i('po_z')) = 22 AND (SELECT sent_via FROM purchase_orders WHERE id = pg_temp.i('po_z')) = 'email', 'Bea sent the Zinus PO by email');
SELECT pg_temp.check((SELECT status FROM sales_order_lines WHERE sales_order_id = pg_temp.i('so1') AND line_no = 2) = 'ordered', '…the customer''s line is now ordered');
SELECT pg_temp.check((SELECT count(*) FROM purchase_order_events WHERE purchase_order_id = pg_temp.i('po_z') AND kind = 'sent') = 1, '…and the event is on the trail');
SELECT inv_po_place(pg_temp.i('po_m'), 22, 'MLF-77812', 'portal');
SELECT pg_temp.check((SELECT status FROM purchase_orders WHERE id = pg_temp.i('po_m')) = 'sent' AND (SELECT supplier_order_ref FROM purchase_orders WHERE id = pg_temp.i('po_m')) = 'MLF-77812' AND (SELECT sent_via FROM purchase_orders WHERE id = pg_temp.i('po_m')) = 'portal', 'placing on the portal sends and records the supplier''s reference');
SELECT pg_temp.refused(format($$INSERT INTO purchase_order_lines (purchase_order_id, line_no, variant_id, qty_ordered) VALUES (%s, 9, %s, 1)$$, pg_temp.i('po_z'), pg_temp.i('v_q')), 'only while it is a draft', 'a sent PO''s lines are locked');
-- the supplier's door: mint, open, acknowledge, decline, tracking
SELECT pg_temp.keept('slink', inv_supplier_link_mint(pg_temp.i('po_z')));
SELECT pg_temp.check(inv_secure_link_purchase_order(pg_temp.t('slink')) = pg_temp.i('po_z') AND inv_secure_link_purchase_order(repeat('f', 48)) IS NULL, 'the supplier''s token opens the PO; a wrong one nothing');
SELECT pg_temp.check(inv_po_shows_phone(pg_temp.i('po_z')) = false, 'D9: a parcel-shipped Cal King does not give the supplier the customer''s phone');
UPDATE product_variants SET ships_how = 'white_glove' WHERE id = pg_temp.i('v_ck');
SELECT pg_temp.check(inv_po_shows_phone(pg_temp.i('po_z')) = true, '…a white-glove line does (the setting names ltl and white_glove)');
SELECT inv_po_acknowledge(pg_temp.i('po_z'), NULL, 'ZN-ORD-9', current_date + 4, 'portal', NULL);
SELECT pg_temp.check((SELECT status FROM purchase_orders WHERE id = pg_temp.i('po_z')) = 'acknowledged' AND (SELECT supplier_order_ref FROM purchase_orders WHERE id = pg_temp.i('po_z')) = 'ZN-ORD-9' AND (SELECT expected_on FROM purchase_orders WHERE id = pg_temp.i('po_z')) = current_date + 4, 'the supplier acknowledged by the door with a reference and a date');
SELECT pg_temp.check((SELECT source FROM purchase_order_events WHERE purchase_order_id = pg_temp.i('po_z') AND kind = 'acknowledge') = 'portal' AND (SELECT member_id FROM purchase_order_events WHERE purchase_order_id = pg_temp.i('po_z') AND kind = 'acknowledge') IS NULL, '…logged as the portal''s, nobody''s member id');
SELECT pg_temp.keep('pol_m', id) FROM purchase_order_lines WHERE purchase_order_id = pg_temp.i('po_m');
SELECT inv_po_decline_line(pg_temp.i('pol_m'), 'discontinued at the factory', 'portal', NULL);
SELECT pg_temp.check((SELECT status FROM purchase_order_lines WHERE id = pg_temp.i('pol_m')) = 'declined' AND (SELECT supplier_note FROM purchase_order_lines WHERE id = pg_temp.i('pol_m')) = 'discontinued at the factory', 'Malouf declined the King line by the door');
SELECT pg_temp.check((SELECT status FROM sales_order_lines WHERE sales_order_id = pg_temp.i('so1') AND line_no = 3) = 'open' AND (SELECT total FROM purchase_orders WHERE id = pg_temp.i('po_m')) = 0, '…the customer''s line is open again (at risk) and the PO totals 0');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM inv_lines_at_risk() WHERE line_id = (SELECT id FROM sales_order_lines WHERE sales_order_id = pg_temp.i('so1') AND line_no = 3) AND risk = 'declined'), 'lines at risk lists the declined King');
SELECT pg_temp.keep('pol_z', id) FROM purchase_order_lines WHERE purchase_order_id = pg_temp.i('po_z');
SELECT inv_po_tracking(pg_temp.i('pol_z'), 'XPO', 'XPO123456', now(), 'portal', NULL);
SELECT pg_temp.check((SELECT status FROM purchase_order_lines WHERE id = pg_temp.i('pol_z')) = 'shipped' AND (SELECT tracking_number FROM purchase_order_lines WHERE id = pg_temp.i('pol_z')) = 'XPO123456', 'the supplier added tracking by the door');
SELECT pg_temp.check((SELECT count(*) FROM shipments WHERE sales_order_id = pg_temp.i('so1') AND kind = 'dropship' AND tracking_number = 'XPO123456') = 1 AND (SELECT status FROM sales_order_lines WHERE sales_order_id = pg_temp.i('so1') AND line_no = 2) = 'shipped', '…which is the customer''s shipment: the drop-ship line is shipped');
SELECT pg_temp.check((SELECT status FROM sales_orders WHERE id = pg_temp.i('so1')) = 'in_fulfilment', '…and the order is in fulfilment');
SELECT pg_temp.keep('sh_drop', id) FROM shipments WHERE sales_order_id = pg_temp.i('so1') AND kind = 'dropship';
SELECT pg_temp.refused(format($$SELECT inv_po_tracking(%s, 'XPO', 'again')$$, pg_temp.i('pol_m')), 'declined', 'tracking on a declined line is refused');
-- delivered: the drop-ship PO line is received; its cost becomes the Cal King's (last_receipt)
SELECT inv_shipment_deliver(pg_temp.i('sh_drop'), 20);
SELECT pg_temp.check((SELECT status FROM purchase_order_lines WHERE id = pg_temp.i('pol_z')) = 'received' AND (SELECT qty_received FROM purchase_order_lines WHERE id = pg_temp.i('pol_z')) = 1 AND (SELECT status FROM purchase_orders WHERE id = pg_temp.i('po_z')) = 'received', 'delivered to the customer: the drop-ship PO line is received, the PO received');
SELECT pg_temp.check((SELECT cost_price FROM product_variants WHERE id = pg_temp.i('v_ck')) = 1749 AND (SELECT reason FROM price_history WHERE variant_id = pg_temp.i('v_ck') AND kind = 'cost' ORDER BY id DESC LIMIT 1) LIKE 'drop-ship PO-%', 'D11: the drop-ship''s cost became the Cal King''s cost_price, with the PO as the reason');
SELECT pg_temp.check((SELECT status FROM sales_order_lines WHERE sales_order_id = pg_temp.i('so1') AND line_no = 2) = 'delivered', 'the customer''s line is delivered');
SELECT inv_po_close(pg_temp.i('po_z'), 22);
SELECT pg_temp.check((SELECT status FROM purchase_orders WHERE id = pg_temp.i('po_z')) = 'closed' AND (SELECT expires_at FROM supplier_links_secure WHERE purchase_order_id = pg_temp.i('po_z') AND rotated_at IS NULL) BETWEEN now() + interval '89 days' AND now() + interval '91 days', 'closing the PO gives its link 90 days to live');
SELECT inv_po_cancel(pg_temp.i('po_m'), 22, 'nothing left on it');
SELECT pg_temp.check((SELECT status FROM purchase_orders WHERE id = pg_temp.i('po_m')) = 'cancelled' AND (SELECT count(*) FROM purchase_order_events WHERE purchase_order_id = pg_temp.i('po_m') AND kind = 'cancelled') = 1, 'the declined Malouf PO is cancelled with the event');
-- a stock PO, received against on a goods receipt
INSERT INTO purchase_orders (supplier_id, kind, ship_to_kind, location_id, created_by) VALUES (pg_temp.i('s_zinus'), 'stock', 'location', pg_temp.i('l_wh'), 22);
SELECT pg_temp.keep('po_s', id) FROM purchase_orders WHERE kind = 'stock' AND supplier_id = pg_temp.i('s_zinus');
INSERT INTO purchase_order_lines (purchase_order_id, line_no, variant_id, qty_ordered, unit_cost, expected_on) VALUES (pg_temp.i('po_s'), 1, pg_temp.i('v_t'), 4, 590.00, current_date + 3), (pg_temp.i('po_s'), 2, pg_temp.i('v_q'), 2, 815.00, current_date + 3);
SELECT pg_temp.check((SELECT total FROM purchase_orders WHERE id = pg_temp.i('po_s')) = 4 * 590 + 2 * 815, 'a stock PO totals its lines');
SELECT pg_temp.refused(format($$SELECT inv_receive_against(%s, 21)$$, pg_temp.i('po_s')), 'a sent order is received', 'a draft stock PO cannot be received against');
SELECT inv_po_send(pg_temp.i('po_s'), 22, 'email');
SELECT pg_temp.check(inv_on_order(pg_temp.i('v_t')) = 4, 'four Twins are on order');
SELECT pg_temp.as_member(21);
SELECT pg_temp.keep('gr2', (inv_receive_against(pg_temp.i('po_s'), 21)).id);
SELECT pg_temp.check((SELECT count(*) FROM goods_receipt_lines WHERE goods_receipt_id = pg_temp.i('gr2')) = 2 AND (SELECT qty FROM goods_receipt_lines WHERE goods_receipt_id = pg_temp.i('gr2') AND line_no = 1) = 4 AND (SELECT unit_cost FROM goods_receipt_lines WHERE goods_receipt_id = pg_temp.i('gr2') AND line_no = 2) = 815, 'receiving against drafts a receipt with every open line at the PO''s cost');
UPDATE goods_receipt_lines SET qty = 3, discrepancy_kind = 'short', discrepancy_note = 'one carton missing' WHERE goods_receipt_id = pg_temp.i('gr2') AND line_no = 1;
SELECT inv_post_receipt(pg_temp.i('gr2'), 21);
SELECT pg_temp.check((SELECT qty_received FROM purchase_order_lines WHERE purchase_order_id = pg_temp.i('po_s') AND line_no = 1) = 3 AND (SELECT status FROM purchase_order_lines WHERE purchase_order_id = pg_temp.i('po_s') AND line_no = 1) = 'partial' AND (SELECT status FROM purchase_order_lines WHERE purchase_order_id = pg_temp.i('po_s') AND line_no = 2) = 'received', 'posting the receipt: Twins 3 of 4 (partial), Queens received');
SELECT pg_temp.check((SELECT status FROM purchase_orders WHERE id = pg_temp.i('po_s')) = 'partial' AND (SELECT qty_on_hand FROM inventory_balances WHERE variant_id = pg_temp.i('v_t') AND location_id = pg_temp.i('l_wh')) = 3, 'the PO is partial; three Twins are on hand');
SELECT pg_temp.check((SELECT cost_price FROM product_variants WHERE id = pg_temp.i('v_q')) = 815 AND (SELECT cost_price FROM product_variants WHERE id = pg_temp.i('v_t')) = 590, 'the received costs became the standard costs (last receipt)');
SELECT pg_temp.as_member(22);
SELECT pg_temp.check((SELECT count(*) FROM inv_purchase_orders_open() WHERE purchase_order_id = pg_temp.i('po_s') AND lines_open = 1) = 1 AND (SELECT count(*) FROM inv_purchase_orders_open() WHERE purchase_order_id = pg_temp.i('po_z')) = 0, 'purchase orders open lists the partial stock PO, not the closed drop-ship');
SELECT inv_po_close(pg_temp.i('po_s'), 22);
SELECT pg_temp.check((SELECT status FROM purchase_orders WHERE id = pg_temp.i('po_s')) = 'closed_short' AND (SELECT status FROM purchase_order_lines WHERE purchase_order_id = pg_temp.i('po_s') AND line_no = 1) = 'closed_short', 'closing with a line short closes short');
SELECT pg_temp.check(inv_on_order(pg_temp.i('v_t')) = 0, '…and nothing is on order');

-- ================================================================ shipping from stock, delivery, close (db/010)
SELECT pg_temp.as_member(21);
SELECT pg_temp.keep('line1', id) FROM sales_order_lines WHERE sales_order_id = pg_temp.i('so1') AND line_no = 1;
SELECT pg_temp.keep('line4', id) FROM sales_order_lines WHERE sales_order_id = pg_temp.i('so1') AND line_no = 4;
SELECT pg_temp.keep('sh1', (inv_order_ship(pg_temp.i('so1'), 21, format('[{"line_id":%s,"qty":1,"serials":["LT-8891"]}]', pg_temp.i('line1'))::jsonb, 'own_delivery', 'Our truck', NULL)).id);
SELECT pg_temp.check((SELECT qty_on_hand FROM inventory_balances WHERE variant_id = pg_temp.i('v_q') AND location_id = pg_temp.i('l_wh')) = 5 AND (SELECT qty_allocated FROM inventory_balances WHERE variant_id = pg_temp.i('v_q') AND location_id = pg_temp.i('l_wh')) = 0, 'shipping the stock line issued one Queen (6 → 5) and released the hold');
SELECT pg_temp.check((SELECT txn_type FROM inventory_transactions WHERE reference_kind = 'shipment' AND reference_id = pg_temp.i('sh1')) = 'sale' AND (SELECT counterparty_id FROM inventory_transactions WHERE reference_kind = 'shipment' AND reference_id = pg_temp.i('sh1')) = pg_temp.i('cust'), '…as a sale transaction with the customer as counterparty');
SELECT pg_temp.check((SELECT status FROM sales_order_lines WHERE id = pg_temp.i('line1')) = 'shipped' AND (SELECT serials FROM sales_order_lines WHERE id = pg_temp.i('line1')) = '{LT-8891}' AND (SELECT qty_shipped FROM sales_order_lines WHERE id = pg_temp.i('line1')) = 1, 'the line is shipped with its serial typed');
SELECT pg_temp.refused(format($$SELECT inv_order_ship(%s, 21, '[{"line_id":%s,"qty":1}]')$$, pg_temp.i('so1'), pg_temp.i('line1')), 'between 1 and', 'shipping a shipped line again is refused');
SELECT pg_temp.as_member(20);
SELECT pg_temp.refused(format($$SELECT inv_order_cancel(%s, 20, 'changed mind')$$, pg_temp.i('so1')), 'shipped lines', 'an order with shipped lines is not cancelled — a return is');
SELECT inv_order_line_cancel((SELECT id FROM sales_order_lines WHERE sales_order_id = pg_temp.i('so1') AND line_no = 3), 20);
SELECT pg_temp.check((SELECT status FROM sales_order_lines WHERE sales_order_id = pg_temp.i('so1') AND line_no = 3) = 'cancelled' AND (SELECT subtotal FROM sales_orders WHERE id = pg_temp.i('so1')) = 1599 + 1999 + 2 * 1199, 'the declined King line is cancelled and the totals follow');
SELECT pg_temp.as_member(21);
SELECT pg_temp.keep('sh4', (inv_order_ship(pg_temp.i('so1'), 21, format('[{"line_id":%s}]', pg_temp.i('line4'))::jsonb, 'own_delivery')).id);
SELECT pg_temp.check((SELECT qty_on_hand FROM inventory_balances WHERE variant_id = pg_temp.i('v_t') AND location_id = pg_temp.i('l_wh')) = 1 AND (SELECT status FROM sales_orders WHERE id = pg_temp.i('so1')) = 'shipped', 'the backorder ships once stock arrived (3 → 1 Twins); every line shipped → the order is shipped');
SELECT inv_shipment_deliver(pg_temp.i('sh1'), 21);
SELECT inv_shipment_deliver(pg_temp.i('sh4'), 21);
SELECT pg_temp.check((SELECT status FROM sales_orders WHERE id = pg_temp.i('so1')) = 'delivered', 'every shipment delivered → the order is delivered');
SELECT pg_temp.as_member(20);
INSERT INTO order_payments (sales_order_id, kind, amount, method) VALUES (pg_temp.i('so1'), 'balance', (SELECT total - amount_paid FROM sales_orders WHERE id = pg_temp.i('so1')), 'card');
SELECT pg_temp.check((SELECT payment_status FROM sales_orders WHERE id = pg_temp.i('so1')) = 'paid' AND (SELECT amount_paid FROM sales_orders WHERE id = pg_temp.i('so1')) = (SELECT total FROM sales_orders WHERE id = pg_temp.i('so1')), 'the balance at delivery: paid in full');
SELECT inv_order_close(pg_temp.i('so1'), 20);
SELECT pg_temp.check((SELECT status FROM sales_orders WHERE id = pg_temp.i('so1')) = 'closed' AND (SELECT expires_at FROM order_links_secure WHERE sales_order_id = pg_temp.i('so1') AND rotated_at IS NULL) BETWEEN now() + interval '179 days' AND now() + interval '181 days', 'closing gives the customer''s link 180 days to live');
SELECT pg_temp.check((SELECT count(*) FROM inv_order_timeline(pg_temp.i('so1')) WHERE kind IN ('quote', 'confirm', 'payment', 'po_draft', 'po_acknowledge', 'po_decline', 'po_tracking', 'shipment', 'delivered', 'close')) >= 12, 'the order''s timeline tells the whole story: quote, confirm, payments, the POs and the supplier''s events, shipments, deliveries, close');
-- a second order, cancelled: allocations released, the drafted PO cancelled
SELECT pg_temp.as_member(20);
INSERT INTO sales_orders (customer_id, salesperson_member_id, created_by) VALUES (pg_temp.i('cust'), 20, 20);
SELECT pg_temp.keep('so2', id) FROM sales_orders WHERE customer_id = pg_temp.i('cust') AND status = 'quote';
INSERT INTO sales_order_lines (sales_order_id, line_no, variant_id, qty, fulfilment_kind, location_id) VALUES (pg_temp.i('so2'), 1, pg_temp.i('v_q'), 2, 'stock', pg_temp.i('l_wh'));
INSERT INTO sales_order_lines (sales_order_id, line_no, variant_id, qty, fulfilment_kind, listing_variant_id) VALUES (pg_temp.i('so2'), 2, pg_temp.i('v_k'), 1, 'dropship', pg_temp.i('lv_zk'));
SELECT inv_order_confirm(pg_temp.i('so2'), 20);
SELECT pg_temp.check((SELECT qty_allocated FROM inventory_balances WHERE variant_id = pg_temp.i('v_q') AND location_id = pg_temp.i('l_wh')) = 2 AND (SELECT count(*) FROM purchase_orders WHERE sales_order_id = pg_temp.i('so2') AND status = 'draft') = 1, 'a second order: two Queens held, one drop-ship PO drafted');
SELECT pg_temp.keep('so2_line2', id) FROM sales_order_lines WHERE sales_order_id = pg_temp.i('so2') AND line_no = 2;
SELECT inv_order_cancel(pg_temp.i('so2'), 20, 'SMOKE customer changed her mind');
SELECT pg_temp.check((SELECT status FROM sales_orders WHERE id = pg_temp.i('so2')) = 'cancelled' AND (SELECT qty_allocated FROM inventory_balances WHERE variant_id = pg_temp.i('v_q') AND location_id = pg_temp.i('l_wh')) = 0, 'cancelling releases the hold');
SELECT pg_temp.check((SELECT count(*) FROM purchase_orders WHERE sales_order_id = pg_temp.i('so2') AND status = 'cancelled') = 1 AND (SELECT cancel_reason FROM sales_orders WHERE id = pg_temp.i('so2')) LIKE 'SMOKE%', '…and cancels the drafted drop-ship PO');
SELECT pg_temp.refused(format($$INSERT INTO order_payments (sales_order_id, kind, amount, method) VALUES (%s, 'deposit', 10, 'cash')$$, pg_temp.i('so2')), 'Only a refund', 'only a refund is recorded on a cancelled order');

-- ================================================================ returns (db/012, D14)
SELECT pg_temp.as_member(20);
INSERT INTO return_authorizations (sales_order_id, method, scheduled_on, location_id, notes) VALUES (pg_temp.i('so1'), 'pickup', current_date + 2, pg_temp.i('l_ret'), 'SMOKE too firm');
SELECT pg_temp.keep('ra1', id) FROM return_authorizations WHERE sales_order_id = pg_temp.i('so1');
SELECT pg_temp.check((SELECT number FROM return_authorizations WHERE id = pg_temp.i('ra1')) = 'RA-00001' AND (SELECT customer_id FROM return_authorizations WHERE id = pg_temp.i('ra1')) = pg_temp.i('cust') AND (SELECT status FROM return_authorizations WHERE id = pg_temp.i('ra1')) = 'requested', 'a return is requested, numbered RA-, on the order''s customer');
SELECT pg_temp.refused(format($$INSERT INTO return_lines (return_id, sales_order_line_id, variant_id, qty, reason_code_id) VALUES (%s, %s, %s, 2, (SELECT id FROM reason_codes WHERE code = 'comfort'))$$, pg_temp.i('ra1'), pg_temp.i('line1'), pg_temp.i('v_q')), 'may still come back', 'returning two of a line that shipped one is refused');
SELECT pg_temp.refused(format($$INSERT INTO return_lines (return_id, sales_order_line_id, variant_id, qty, reason_code_id) VALUES (%s, %s, %s, 1, (SELECT id FROM reason_codes WHERE code = 'found'))$$, pg_temp.i('ra1'), pg_temp.i('line1'), pg_temp.i('v_q')), 'not a return reason', 'an adjustment reason is not a return reason');
SELECT pg_temp.refused(format($$INSERT INTO return_lines (return_id, sales_order_line_id, variant_id, qty, reason_code_id) VALUES (%s, %s, %s, 1, (SELECT id FROM reason_codes WHERE code = 'comfort'))$$, pg_temp.i('ra1'), pg_temp.i('so2_line2'), pg_temp.i('v_k')), 'not on the return''s order', 'a line of another order is refused');
INSERT INTO return_lines (return_id, sales_order_line_id, variant_id, qty, reason_code_id, disposition, location_id) VALUES (pg_temp.i('ra1'), pg_temp.i('line1'), pg_temp.i('v_q'), 1, (SELECT id FROM reason_codes WHERE code = 'comfort'), 'restock', pg_temp.i('l_wh'));
INSERT INTO return_lines (return_id, sales_order_line_id, variant_id, qty, reason_code_id, disposition) VALUES (pg_temp.i('ra1'), pg_temp.i('line4'), pg_temp.i('v_t'), 2, (SELECT id FROM reason_codes WHERE code = 'damaged'), 'floor_model');
SELECT pg_temp.refused(format($$SELECT inv_return_receive(%s, 21)$$, pg_temp.i('ra1')), 'an approved return is received', 'a requested return is not received before approval');
SELECT pg_temp.as_member(22);
SELECT inv_return_approve(pg_temp.i('ra1'), 22);
SELECT pg_temp.check((SELECT status FROM return_authorizations WHERE id = pg_temp.i('ra1')) = 'approved' AND (SELECT approved_by FROM return_authorizations WHERE id = pg_temp.i('ra1')) = 22, 'Bea approved it');
SELECT pg_temp.as_member(21);
SELECT inv_return_receive(pg_temp.i('ra1'), 21);
SELECT pg_temp.check((SELECT qty_on_hand FROM inventory_balances WHERE variant_id = pg_temp.i('v_q') AND location_id = pg_temp.i('l_wh')) = 6 AND (SELECT txn_type FROM inventory_transactions WHERE reference_kind = 'return' AND reference_id = pg_temp.i('ra1') AND variant_id = pg_temp.i('v_q')) = 'return', 'receiving with restock writes a return transaction: the Queen is back on the warehouse shelf (5 → 6)');
SELECT pg_temp.check((SELECT qty_on_hand FROM inventory_balances WHERE variant_id = pg_temp.i('v_t') AND location_id = pg_temp.i('l_ret')) = 2 AND (SELECT qty_floor_model FROM inventory_balances WHERE variant_id = pg_temp.i('v_t') AND location_id = pg_temp.i('l_ret')) = 2, 'the floor-model disposition puts the Twins on hand AND on the floor at the returns bay');
SELECT pg_temp.check((SELECT qty_returned FROM sales_order_lines WHERE id = pg_temp.i('line1')) = 1 AND (SELECT status FROM sales_order_lines WHERE id = pg_temp.i('line1')) = 'returned', 'the order line counts the return');
SELECT pg_temp.check((SELECT status FROM return_authorizations WHERE id = pg_temp.i('ra1')) = 'received' AND (SELECT count(*) FROM inv_returns_open() WHERE return_id = pg_temp.i('ra1')) = 1, 'the return is received and still open (awaiting close)');
SELECT pg_temp.as_member(20);
INSERT INTO order_payments (sales_order_id, kind, amount, method) VALUES (pg_temp.i('so1'), 'refund', 1599, 'card');
SELECT pg_temp.check((SELECT payment_status FROM sales_orders WHERE id = pg_temp.i('so1')) = 'partial_refund', 'a refund recorded: partial_refund');
SELECT pg_temp.as_member(22);
SELECT inv_return_close(pg_temp.i('ra1'), 22, 1599, 0);
SELECT pg_temp.check((SELECT status FROM return_authorizations WHERE id = pg_temp.i('ra1')) = 'closed' AND (SELECT refund_amount FROM return_authorizations WHERE id = pg_temp.i('ra1')) = 1599 AND (SELECT count(*) FROM inv_returns_open()) = 0, 'closed with the refund recorded; nothing open');
SELECT pg_temp.refused(format($$INSERT INTO return_lines (return_id, sales_order_line_id, variant_id, qty, reason_code_id) VALUES (%s, %s, %s, 1, (SELECT id FROM reason_codes WHERE code = 'comfort'))$$, pg_temp.i('ra1'), pg_temp.i('line1'), pg_temp.i('v_q')), 'requested or approved', 'a closed return''s lines are locked');
SELECT pg_temp.refused(format($$INSERT INTO return_authorizations (sales_order_id) VALUES (%s)$$, pg_temp.i('so2')), 'confirmed order', 'no return against a cancelled order');

-- ================================================================ watches fire once per state change (db/009, db/013)
SELECT pg_temp.as_member(20);
UPDATE listing_variants SET availability = 'out_of_stock' WHERE id = pg_temp.i('lv_zk');
INSERT INTO watches (member_id, agent_member_id, kind, listing_variant_id, text_me) VALUES (20, 31, 'back_in_stock', pg_temp.i('lv_zk'), true);
SELECT pg_temp.keep('w1', id) FROM watches WHERE member_id = 20 AND kind = 'back_in_stock';
INSERT INTO watches (member_id, kind, variant_id, threshold) VALUES (20, 'price_below', pg_temp.i('v_q'), 1500);
SELECT pg_temp.keep('w2', id) FROM watches WHERE member_id = 20 AND kind = 'price_below';
INSERT INTO watches (member_id, kind, variant_id) VALUES (22, 'map_breach', pg_temp.i('v_q'));
SELECT pg_temp.keep('w3', id) FROM watches WHERE member_id = 22 AND kind = 'map_breach';
SELECT pg_temp.refused($$INSERT INTO watches (member_id, kind, variant_id, listing_variant_id) VALUES (20, 'removed', pg_temp.i('v_q'), pg_temp.i('lv_zk'))$$, 'check', 'a watch has exactly one target');
SELECT pg_temp.refused($$INSERT INTO watches (member_id, kind, variant_id) VALUES (20, 'price_below', pg_temp.i('v_q'))$$, 'check', 'a price watch needs a threshold');
SELECT pg_temp.as_member(22);
SELECT pg_temp.keep('f', inv_fire_watches());
SELECT pg_temp.check(pg_temp.i('f') = 0 AND (SELECT last_state FROM watches WHERE id = pg_temp.i('w1')) = false, 'the first evaluation fires nothing: the King is out of stock, the state is recorded');
UPDATE listing_variants SET availability = 'in_stock' WHERE id = pg_temp.i('lv_zk');
SELECT pg_temp.keep('f', inv_fire_watches());
SELECT pg_temp.check(pg_temp.i('f') = 1 AND (SELECT fire_count FROM watches WHERE id = pg_temp.i('w1')) = 1 AND (SELECT fired_at FROM watches WHERE id = pg_temp.i('w1')) IS NOT NULL, 'back in stock: the watch fires once');
SELECT pg_temp.check((SELECT count(*) FROM notifications WHERE member_id = 20 AND kind = 'watch' AND record_id = pg_temp.i('w1')) = 1 AND (SELECT title FROM notifications WHERE member_id = 20 AND kind = 'watch' ORDER BY id DESC LIMIT 1) LIKE 'Back in stock:%', '…Sam is notified (' || (SELECT count(*) FROM notifications WHERE member_id = 20)::text || ' notifications)');
SELECT pg_temp.check((SELECT count(*) FROM notification_outbox WHERE member_id = 20 AND channel = 'email' AND kind = 'watch') = 1 AND (SELECT count(*) FROM notification_outbox WHERE member_id = 20 AND channel = 'text' AND kind = 'watch') = 1, '…by email and, as he asked, by text (K6, through the kernel)');
SELECT pg_temp.check((SELECT count(*) FROM agent_dispatches WHERE watch_id = pg_temp.i('w1') AND agent_member_id = 31 AND kind = 'watch' AND via = 'chat') = 1 AND (SELECT listing_variant_id FROM agent_dispatches WHERE watch_id = pg_temp.i('w1')) = pg_temp.i('lv_zk'), '…and the Stock Buyer is dispatched one chat turn with the listing variant');
SELECT pg_temp.keep('f', inv_fire_watches());
SELECT pg_temp.check(pg_temp.i('f') = 0 AND (SELECT fire_count FROM watches WHERE id = pg_temp.i('w1')) = 1, 'the next pull with it still in stock fires nothing (once per state change, not per pull)');
UPDATE listing_variants SET availability = 'out_of_stock' WHERE id = pg_temp.i('lv_zk');
SELECT pg_temp.keep('f', inv_fire_watches());
SELECT pg_temp.check(pg_temp.i('f') = 0 AND (SELECT last_state FROM watches WHERE id = pg_temp.i('w1')) = false, 'out again: cleared, nothing fires');
UPDATE listing_variants SET availability = 'in_stock' WHERE id = pg_temp.i('lv_zk');
SELECT pg_temp.keep('f', inv_fire_watches());
SELECT pg_temp.check(pg_temp.i('f') = 1 AND (SELECT fire_count FROM watches WHERE id = pg_temp.i('w1')) = 2 AND (SELECT count(*) FROM notification_outbox WHERE member_id = 20 AND channel = 'email' AND kind = 'watch') = 2, 'back a second time: fires again (a second notification, deduped per firing)');
-- the price and MAP watches were TRUE at creation (Zinus asks 1399): recorded, never fired. Clear them, then breach.
SELECT pg_temp.check((SELECT last_state FROM watches WHERE id = pg_temp.i('w2')) AND (SELECT last_state FROM watches WHERE id = pg_temp.i('w3')) AND (SELECT fire_count FROM watches WHERE id = pg_temp.i('w3')) = 0, 'a watch whose condition already held when set is recorded true and does not fire (nothing changed)');
UPDATE listing_variants SET price = 1699 WHERE id = pg_temp.i('lv_zq');
UPDATE listing_variants SET price = 1649 WHERE id = pg_temp.i('lv_cq');
SELECT pg_temp.keep('f', inv_fire_watches());
SELECT pg_temp.check(pg_temp.i('f') = 0 AND NOT (SELECT last_state FROM watches WHERE id = pg_temp.i('w2')) AND NOT (SELECT last_state FROM watches WHERE id = pg_temp.i('w3')), 'every offer at or above MAP and 1500: both cleared, nothing fires');
UPDATE listing_variants SET price = 1499 WHERE id = pg_temp.i('lv_cq');
SELECT pg_temp.keep('f', inv_fire_watches());
SELECT pg_temp.check(pg_temp.i('f') = 2 AND (SELECT fire_count FROM watches WHERE id = pg_temp.i('w2')) = 1 AND (SELECT fire_count FROM watches WHERE id = pg_temp.i('w3')) = 1, 'Casper at 1499: the price watch (below 1500) and Bea''s MAP watch (MAP 1649) both fire');
SELECT pg_temp.check((SELECT count(*) FROM notifications WHERE member_id = 22 AND kind = 'watch') = 1 AND (SELECT title FROM notifications WHERE member_id = 22 AND kind = 'watch') LIKE 'MAP breached:%', 'the MAP breach reaches the Buyer');
UPDATE listing_variants SET price = 1649 WHERE id = pg_temp.i('lv_cq');
UPDATE watches SET active = false WHERE id = pg_temp.i('w2');
SELECT pg_temp.keep('f', inv_fire_watches());
SELECT pg_temp.check(pg_temp.i('f') = 0 AND (SELECT last_state FROM watches WHERE id = pg_temp.i('w3')) = false AND (SELECT last_state FROM watches WHERE id = pg_temp.i('w2')), 'back at MAP: the Buyer''s watch cleared; the inactive watch is left alone');
UPDATE listing_variants SET price = 1549 WHERE id = pg_temp.i('lv_cq');
UPDATE listing_variants SET price = 1399 WHERE id = pg_temp.i('lv_zq');
SELECT pg_temp.as_member(20);
SELECT pg_temp.check((SELECT count(*) FROM mcp_watches) = 2 AND (SELECT count(*) FROM mcp_notifications) = 3, 'Sam sees his own two watches and three notifications');
SELECT pg_temp.as_member(22);
SELECT pg_temp.check((SELECT count(*) FROM mcp_watches) = 3, 'Bea (watches.all) sees every watch');

-- ================================================================ the Buyer's lists and the reports (db/014)
SELECT pg_temp.as_member(22);
UPDATE product_variants SET reorder_point = 10, reorder_qty = 5 WHERE id = pg_temp.i('v_k');
SELECT pg_temp.check((SELECT count(*) FROM inv_reorder_candidates() WHERE variant_id = pg_temp.i('v_k')) = 1 AND (SELECT best_supplier_name FROM inv_reorder_candidates() WHERE variant_id = pg_temp.i('v_k')) = 'SMOKE Malouf' AND (SELECT best_cost FROM inv_reorder_candidates() WHERE variant_id = pg_temp.i('v_k')) = 980 AND (SELECT reorder_qty FROM inv_reorder_candidates() WHERE variant_id = pg_temp.i('v_k')) = 5, 'reorder candidates: the King (3 on hand, point 10) with its cheapest in-stock supplier');
SELECT pg_temp.check((SELECT count(*) FROM inv_reorder_candidates() WHERE variant_id = pg_temp.i('v_q')) = 0 AND (SELECT count(*) FROM inv_reorder_candidates() WHERE variant_id = pg_temp.i('v_t')) = 1 AND (SELECT reorder_point FROM inv_reorder_candidates() WHERE variant_id = pg_temp.i('v_t')) = 2, 'the Queen (6 on hand against the product''s point of 2) is not listed; the Twin (1 on hand) is, with the product''s point');
UPDATE products SET reorder_point = 20 WHERE id = pg_temp.i('p_purple');
SELECT pg_temp.check((SELECT reorder_point FROM inv_reorder_candidates() WHERE variant_id = pg_temp.i('v_q')) = 20 AND (SELECT reorder_qty FROM inv_reorder_candidates() WHERE variant_id = pg_temp.i('v_q')) = 1, 'a variant inherits its product''s reorder point (20) and the settings'' default quantity');
UPDATE products SET reorder_point = 2 WHERE id = pg_temp.i('p_purple');
SELECT pg_temp.check((SELECT count(*) FROM inv_price_exceptions() WHERE kind = 'retail_under_map' AND variant_id = pg_temp.i('v_q')) = 1, 'price exceptions: the Queen''s retail 1599 is under its MAP 1649');
SELECT pg_temp.check((SELECT count(*) FROM inv_price_exceptions() WHERE kind = 'reference_undercut') = 0, '…no reference undercuts by more than 10 % (Casper 1549 vs 1599)');
UPDATE listing_variants SET price = 1299 WHERE id = pg_temp.i('lv_cq');
SELECT pg_temp.check((SELECT count(*) FROM inv_price_exceptions() WHERE kind = 'reference_undercut' AND source_name = 'SMOKE Casper site') = 1 AND (SELECT pct FROM inv_price_exceptions() WHERE kind = 'reference_undercut') > 10, 'Casper at 1299 undercuts our 1599 by more than 10 %');
SELECT pg_temp.check((SELECT count(*) FROM inv_price_exceptions() WHERE kind = 'cost_moved' AND variant_id = pg_temp.i('v_ck')) = 1, 'the Cal King''s cost moved more than 5 % (1050 → 1749 by the drop-ship)');
SELECT pg_temp.check((SELECT count(*) FROM inv_unmatched_listings()) >= 1 AND (SELECT count(*) FROM inv_unmatched_listings(pg_temp.i('src_casper'))) = 3 AND (SELECT count(*) FROM inv_unmatched_listings(pg_temp.i('src_zinus'))) = 0, 'unmatched listings: Casper''s King, Olympic Queen and the Original; Zinus fully matched');
SELECT pg_temp.check((SELECT health FROM inv_source_health() WHERE source_id = pg_temp.i('src_casper')) = 'ok' AND (SELECT health FROM inv_source_health() WHERE source_id = pg_temp.i('src_manual')) = 'manual' AND (SELECT health FROM inv_source_health() WHERE source_id = pg_temp.i('src_jsonld')) = 'never_pulled' AND (SELECT listings_live FROM inv_source_health() WHERE source_id = pg_temp.i('src_casper')) = 2, 'source health: Casper ok with 2 live listings, the price sheet manual, Bigbox never pulled ok');
UPDATE sources SET last_ok_at = now() - interval '2 days' WHERE id = pg_temp.i('src_zinus');
SELECT pg_temp.check((SELECT health FROM inv_source_health() WHERE source_id = pg_temp.i('src_zinus')) = 'stale' AND (SELECT stale FROM inv_source_health() WHERE source_id = pg_temp.i('src_zinus')), 'a supplier source not pulled for two days is stale');
SELECT pg_temp.check((SELECT sum(units) FROM inv_stock_value(now(), 'location')) = (SELECT sum(qty_on_hand) FROM inventory_balances) AND (SELECT value FROM inv_stock_value(now(), 'location') WHERE group_name = 'SMOKE Warehouse') = 6 * 815 + 3 * 1000 + 1 * 590 + 3 * 120, 'stock value by location: every unit at its standard cost');
SELECT pg_temp.check((SELECT count(*) FROM inv_stock_value(now(), 'brand')) = 1 AND (SELECT count(*) FROM inv_stock_value(now(), 'type')) = 2, '…by brand (one) and by type (mattress, foundation)');
SELECT pg_temp.check((SELECT units_sold FROM inv_sell_through(30, 'variant') WHERE group_id = pg_temp.i('v_q')) = 1 AND (SELECT revenue FROM inv_sell_through(30, 'variant') WHERE group_id = pg_temp.i('v_q')) = 1599 AND (SELECT cogs FROM inv_sell_through(30, 'variant') WHERE group_id = pg_temp.i('v_q')) = 815, 'sell-through: one Queen at 1599, cost 815');
SELECT pg_temp.check((SELECT units_sold FROM inv_sell_through(30, 'salesperson') WHERE group_id = 20) = 4 AND (SELECT margin_pct FROM inv_sell_through(30, 'salesperson') WHERE group_id = 20) > 0, '…by salesperson: Sam sold four units at a margin');
SELECT pg_temp.check((SELECT lines FROM inv_lead_time_actuals(pg_temp.i('s_zinus'))) = 1 AND (SELECT actual_avg_days FROM inv_lead_time_actuals(pg_temp.i('s_zinus'))) = 0 AND (SELECT on_time_pct FROM inv_lead_time_actuals(pg_temp.i('s_zinus'))) = 100, 'lead-time actuals: Zinus shipped the Cal King the same day, on time');
SELECT pg_temp.check((SELECT count(*) FROM inv_catalog_gaps() WHERE gap = 'no_gtin') >= 3 AND (SELECT count(*) FROM inv_catalog_gaps() WHERE gap = 'retail_under_map' AND variant_id = pg_temp.i('v_q')) = 1 AND (SELECT count(*) FROM inv_catalog_gaps() WHERE gap = 'no_image' AND variant_id = pg_temp.i('v_fq')) = 1, 'catalog gaps: no GTIN, retail under MAP, no image');
SELECT pg_temp.check((SELECT count(*) FROM inv_offer_history(pg_temp.i('lv_cq'))) = (SELECT count(*) FROM offer_snapshots WHERE listing_variant_id = pg_temp.i('lv_cq')) AND (SELECT count(*) FROM inv_offer_history(pg_temp.i('lv_ck')) WHERE is_heartbeat) = 1, 'the offer history is the snapshot series, heartbeats flagged');
SELECT pg_temp.check((SELECT count(*) FROM inv_price_history(pg_temp.i('v_q')) WHERE kind = 'cost') >= 2, 'the Buyer sees the cost history');
SELECT pg_temp.as_member(23);
SELECT pg_temp.check((SELECT count(*) FROM inv_price_history(pg_temp.i('v_q')) WHERE kind = 'cost') = 0 AND (SELECT count(*) FROM inv_price_history(pg_temp.i('v_q')) WHERE kind = 'retail') >= 2, 'a Viewer sees the retail history and no cost rows');
SELECT pg_temp.refused('SELECT count(*) FROM inv_reorder_candidates()', 'reports.read', 'reorder candidates need reports.read');
SELECT pg_temp.refused('SELECT count(*) FROM inv_stock_value()', 'reports.read', 'stock value needs reports.read');
SELECT pg_temp.refused('SELECT count(*) FROM inv_unmatched_listings()', 'listings.match', 'the match queue needs listings.match');
SELECT pg_temp.check((SELECT count(*) FROM inv_offer_history(pg_temp.i('lv_fq')) WHERE cost_price IS NOT NULL) = 0 AND (SELECT count(*) FROM inv_offer_history(pg_temp.i('lv_fq')) WHERE cost_withheld) > 0, 'a Viewer''s offer history withholds the feed''s cost');
SELECT pg_temp.as_member(21);
SELECT pg_temp.check((SELECT count(*) FROM inv_purchase_orders_open()) = 0, 'Warehouse may list the open purchase orders (none open now)');

-- ================================================================ the feed (db/013, db/014, §4)
SELECT pg_temp.as_member(24);
INSERT INTO price_lists (name, percent_off_retail) VALUES ('SMOKE Dealer 30', 30);
INSERT INTO feed_keys (member_id, label, token_hash, consumer_kind) VALUES (24, 'SMOKE website', encode(sha256('feed_smoke_web'::bytea), 'hex'), 'website');
INSERT INTO feed_keys (member_id, label, token_hash, consumer_kind, price_list_id) VALUES (24, 'SMOKE partner store', encode(sha256('feed_smoke_partner'::bytea), 'hex'), 'partner', (SELECT id FROM price_lists WHERE name = 'SMOKE Dealer 30'));
SELECT pg_temp.keep('key_web', id) FROM feed_keys WHERE label = 'SMOKE website';
SELECT pg_temp.keep('key_partner', id) FROM feed_keys WHERE label = 'SMOKE partner store';
SELECT pg_temp.check((SELECT rate_per_minute FROM feed_keys WHERE id = pg_temp.i('key_web')) = 60 AND (SELECT rate_per_day FROM feed_keys WHERE id = pg_temp.i('key_web')) = 10000, 'a key takes the settings'' limits');
SELECT pg_temp.refused($$INSERT INTO feed_keys (member_id, label, token_hash, consumer_kind, price_list_id) VALUES (24, 'SMOKE bad', 'x', 'website', (SELECT id FROM price_lists LIMIT 1))$$, 'check', 'only a partner key names a price list');
SELECT pg_temp.check((SELECT key_id FROM inv_resolve_feed_key(encode(sha256('feed_smoke_web'::bytea), 'hex'))) = pg_temp.i('key_web') AND (SELECT count(*) FROM inv_resolve_feed_key('nope')) = 0, 'a key resolves by its hash; an unknown one does not');
SELECT pg_temp.check((SELECT ok FROM inv_rate_ok(pg_temp.i('key_web'))) AND (SELECT minute_calls FROM inv_rate_ok(pg_temp.i('key_web'))) = 2, 'two calls counted');
UPDATE feed_keys SET rate_per_minute = 3 WHERE id = pg_temp.i('key_web');
SELECT inv_rate_ok(pg_temp.i('key_web'));
SELECT pg_temp.check(NOT (SELECT ok FROM inv_rate_ok(pg_temp.i('key_web'))) AND (SELECT limit_hit FROM inv_rate_ok(pg_temp.i('key_web'))) = 'minute' AND (SELECT retry_after FROM inv_rate_ok(pg_temp.i('key_web'))) BETWEEN 1 AND 60, 'the fourth call in a minute is refused with a retry-after');
SELECT pg_temp.check((SELECT calls FROM key_usage WHERE token_id = pg_temp.i('key_web') AND bucket_kind = 'day') = 3 AND (SELECT refused FROM key_usage WHERE token_id = pg_temp.i('key_web') AND bucket_kind = 'minute') >= 3 AND (SELECT calls FROM key_usage WHERE token_id = pg_temp.i('key_web') AND bucket_kind = 'minute') = 3, 'a refused call is not charged to the day (3 and 3); refusals are counted');
SELECT pg_temp.check(inv_feed_key_calls_today(pg_temp.i('key_web')) = 3, 'calls today: 3');
SELECT pg_temp.keep('key_web2', inv_feed_key_rotate(pg_temp.i('key_web'), encode(sha256('feed_smoke_web2'::bytea), 'hex')));
SELECT pg_temp.check((SELECT expires_at FROM feed_keys WHERE id = pg_temp.i('key_web')) BETWEEN now() + interval '23 hours' AND now() + interval '25 hours' AND (SELECT rotated_from FROM feed_keys WHERE id = pg_temp.i('key_web2')) = pg_temp.i('key_web') AND (SELECT label FROM feed_keys WHERE id = pg_temp.i('key_web2')) = 'SMOKE website', 'rotation: the new key under the same label, the old one lives 24 hours more');
SELECT inv_feed_key_revoke(pg_temp.i('key_web'), 24);
SELECT pg_temp.check((SELECT count(*) FROM inv_resolve_feed_key(encode(sha256('feed_smoke_web'::bytea), 'hex'))) = 0 AND NOT (SELECT ok FROM inv_rate_ok(pg_temp.i('key_web'))) AND (SELECT limit_hit FROM inv_rate_ok(pg_temp.i('key_web'))) = 'revoked', 'a revoked key resolves to nothing and counts nothing');
SELECT pg_temp.refused(format($$SELECT inv_feed_key_rotate(%s, 'y')$$, pg_temp.i('key_web')), 'live key', 'only a live key is rotated');
-- the answer
SELECT pg_temp.keept('fa', inv_feed_answer(pg_temp.i('key_web2'), format('{"gtin":"%s"}', pg_temp.gtin('840000000104'))::jsonb)::text);
SELECT pg_temp.check((pg_temp.t('fa')::jsonb->>'count')::integer = 1 AND (pg_temp.t('fa')::jsonb->'results'->0->>'sku') = 'PH2-Q' AND (pg_temp.t('fa')::jsonb->'results'->0->>'retail_price')::numeric = 1599 AND (pg_temp.t('fa')::jsonb->'results'->0->>'availability') = 'in_stock', 'the feed answers a GTIN with the SKU, retail and in_stock');
SELECT pg_temp.check((pg_temp.t('fa')::jsonb->'results'->0->'quantity') = 'null'::jsonb AND (pg_temp.t('fa')::jsonb->'results'->0->'partner_price') = 'null'::jsonb AND (pg_temp.t('fa')::jsonb->'results'->0->>'lead_time_days')::integer = 0, '…no quantity (the setting), no partner price for a website key, lead time 0');
SELECT pg_temp.check(pg_temp.t('fa') NOT ILIKE '%cost%' AND pg_temp.t('fa') NOT ILIKE '%Zinus%' AND pg_temp.t('fa') NOT ILIKE '%Malouf%' AND pg_temp.t('fa') NOT ILIKE '%Casper%', '…never cost, never a source''s name');
SELECT pg_temp.keept('fa2', inv_feed_answer(pg_temp.i('key_partner'), '{"sku":"PH2-CK"}')::jsonb::text);
SELECT pg_temp.check((pg_temp.t('fa2')::jsonb->'results'->0->>'partner_price')::numeric = round(2099 * 0.7, 2) AND (pg_temp.t('fa2')::jsonb->'results'->0->>'availability') = 'back_order' AND (pg_temp.t('fa2')::jsonb->'results'->0->>'lead_time_days')::integer = 3 AND (pg_temp.t('fa2')::jsonb->'results'->0->>'ships_how') = 'white_glove', 'a partner key gets its price (30 % off retail); the Cal King is back_order in 3 days, white glove');
UPDATE inv_settings SET feed_shows_quantity = true;
SELECT pg_temp.check((inv_feed_answer(pg_temp.i('key_web2'), '{"sku":"PH2-Q"}')->'results'->0->>'quantity')::integer = 6, 'with feed_shows_quantity on, the quantity is given');
UPDATE inv_settings SET feed_shows_quantity = false;
SELECT pg_temp.check((inv_feed_answer(pg_temp.i('key_web2'), '{"q":"purple hybrid","size":"King"}')->>'count')::integer = 2 AND (inv_feed_answer(pg_temp.i('key_web2'), '{"q":"purple hybrid","size":"King"}')->'results') @> '[{"sku":"PH2-K"}]' AND (inv_feed_answer(pg_temp.i('key_web2'), '{"q":"nothing like it"}')->>'count')::integer = 0, 'the feed searches by name and size too (the King and the foundation King)');
SELECT pg_temp.refused(format($$SELECT inv_feed_answer(%s, '{"sku":"PH2-Q"}')$$, pg_temp.i('key_web')), 'No such key', 'a revoked key gets no answer');
SELECT pg_temp.check((SELECT count(*) FROM mcp_feed_keys) = 3 AND (SELECT calls_today FROM mcp_feed_keys WHERE key_id = pg_temp.i('key_web')) = 3, 'the admin''s keys screen lists the keys with today''s calls');
SELECT pg_temp.as_member(22);
SELECT pg_temp.check((SELECT count(*) FROM mcp_feed_keys) = 0 AND (SELECT count(*) FROM mcp_price_lists) = 0, 'the Buyer sees no feed keys (feed.keys is the admin''s)');
SELECT pg_temp.check(inv_expire_links() = 0 AND inv_prune_key_usage() = 0, 'the worker''s passes: nothing to expire or prune yet');

-- ================================================================ the views: the wall, the credential, the doors, the two read roles
SELECT pg_temp.as_member(22);
SELECT pg_temp.check((SELECT cost_price FROM mcp_product_variants WHERE variant_id = pg_temp.i('v_q')) = 815 AND (SELECT unit_cost FROM mcp_purchase_order_lines WHERE purchase_order_line_id = pg_temp.i('pol_z')) = 1749 AND (SELECT cost FROM mcp_supplier_items WHERE variant_id = pg_temp.i('v_k') AND supplier_id = pg_temp.i('s_malouf')) = 980, 'the Buyer sees cost in the views: variants, PO lines, the price sheet');
SELECT pg_temp.check((SELECT settings FROM mcp_sources WHERE source_id = pg_temp.i('src_feed')) IS NOT NULL AND (SELECT count(*) FROM mcp_source_credentials) = 1 AND (SELECT last4 FROM mcp_source_credentials) = 'x9Q2', '…and a source''s settings and its credential''s label and last four');
SELECT pg_temp.check((SELECT account_number FROM mcp_suppliers WHERE supplier_id = pg_temp.i('s_malouf')) = 'DLR-1001' AND (SELECT raw FROM mcp_listings WHERE listing_id = pg_temp.i('lst_c1')) IS NOT NULL, '…the account number and a listing''s raw fields');
SELECT pg_temp.as_member(23);
SELECT pg_temp.check((SELECT cost_price FROM mcp_product_variants WHERE variant_id = pg_temp.i('v_q')) IS NULL AND (SELECT cost_withheld FROM mcp_product_variants WHERE variant_id = pg_temp.i('v_q')) AND (SELECT retail_price FROM mcp_product_variants WHERE variant_id = pg_temp.i('v_q')) = 1599, 'a Viewer: cost null in mcp_product_variants, retail intact');
SELECT pg_temp.check((SELECT unit_cost FROM mcp_purchase_order_lines WHERE purchase_order_line_id = pg_temp.i('pol_z')) IS NULL AND (SELECT total FROM mcp_purchase_orders WHERE purchase_order_id = pg_temp.i('po_z')) IS NULL AND (SELECT cost FROM mcp_supplier_items LIMIT 1) IS NULL AND (SELECT offer_cost FROM mcp_sales_order_lines WHERE line_id = pg_temp.i('line1')) IS NULL, '…null on PO lines, PO totals, the price sheet, the order lines'' offer cost');
SELECT pg_temp.check((SELECT cost_price FROM mcp_listing_variants WHERE listing_variant_id = pg_temp.i('lv_fq')) IS NULL AND (SELECT count(*) FROM mcp_offer_snapshots WHERE cost_price IS NOT NULL) = 0 AND (SELECT count(*) FROM mcp_price_history WHERE kind = 'cost') = 0 AND (SELECT unit_cost FROM mcp_inventory_transactions WHERE txn_type = 'receipt' LIMIT 1) IS NULL, '…null on listing variants, snapshots, the ledger; no cost history rows');
SELECT pg_temp.check((SELECT settings FROM mcp_sources WHERE source_id = pg_temp.i('src_feed')) IS NULL AND (SELECT user_agent FROM mcp_sources WHERE source_id = pg_temp.i('src_feed')) IS NULL AND (SELECT count(*) FROM mcp_source_credentials) = 0 AND (SELECT raw FROM mcp_listings WHERE listing_id = pg_temp.i('lst_c1')) IS NULL, 'a Viewer sees no source settings, no credential row, no raw object');
SELECT pg_temp.check((SELECT email FROM mcp_customers WHERE customer_id = pg_temp.i('cust')) IS NULL AND (SELECT phone FROM mcp_customers WHERE customer_id = pg_temp.i('cust')) IS NULL AND (SELECT name FROM mcp_customers WHERE customer_id = pg_temp.i('cust')) = 'SMOKE Alvarez', 'a Viewer sees the customer''s name, not their contact details');
SELECT pg_temp.check((SELECT ship_to_phone FROM mcp_sales_orders WHERE sales_order_id = pg_temp.i('so1')) IS NULL AND (SELECT status FROM mcp_sales_orders WHERE sales_order_id = pg_temp.i('so1')) = 'closed' AND (SELECT account_number FROM mcp_suppliers WHERE supplier_id = pg_temp.i('s_malouf')) IS NULL, '…nor the ship-to phone, nor the supplier account number; the order''s status yes');
SELECT pg_temp.check((SELECT count(*) FROM mcp_order_payments) = 0 AND (SELECT count(*) FROM mcp_order_links) = 0 AND (SELECT count(*) FROM mcp_supplier_links) = 0 AND (SELECT count(*) FROM mcp_document_sequences) = 0, '…no payments, no doors, no sequences');
SELECT pg_temp.as_member(20);
SELECT pg_temp.check((SELECT email FROM mcp_customers WHERE customer_id = pg_temp.i('cust')) = 'alvarez@example.invalid' AND (SELECT count(*) FROM mcp_order_payments WHERE sales_order_id = pg_temp.i('so1')) = 3 AND (SELECT count(*) FROM mcp_order_links WHERE sales_order_id = pg_temp.i('so1') AND is_live) = 1, 'Sales sees the customer''s contact, the payments and the live link (not the token)');
SELECT pg_temp.as_member(21);
SELECT pg_temp.check((SELECT unit_cost FROM mcp_goods_receipt_lines WHERE goods_receipt_id = pg_temp.i('gr2') AND line_no = 2) = 815 AND (SELECT unit_cost FROM mcp_inventory_transactions WHERE txn_type = 'receipt' AND reference_id = pg_temp.i('gr2') AND variant_id = pg_temp.i('v_q')) = 815 AND (SELECT cost_price FROM mcp_product_variants WHERE variant_id = pg_temp.i('v_q')) IS NULL, 'Warehouse sees cost on receipt lines and receipt transactions, not on the variant');
SELECT pg_temp.as_member(32);
SELECT pg_temp.check((SELECT count(*) FROM mcp_products) = 0 AND (SELECT count(*) FROM mcp_sales_orders) = 0 AND (SELECT count(*) FROM mcp_members) = 0, 'an unadmitted agent sees no rows in any view');
SELECT pg_temp.as_member(24);
SELECT pg_temp.check((SELECT count(*) FROM mcp_notifications) >= 3 AND (SELECT count(*) FROM mcp_notification_outbox) >= 3 AND (SELECT count(*) FROM mcp_agent_dispatches) = 2, 'the admin sees every notification, the outbox and the dispatches');
SELECT pg_temp.check((SELECT count(*) FROM mcp_app_roles) = 5 AND (SELECT is_admin FROM mcp_app_roles WHERE role_key = 'admin') AND (SELECT count(*) FROM mcp_app_roles WHERE is_admin) = 1 AND (SELECT 'cost.read' = ANY (rights) FROM mcp_app_roles WHERE role_key = 'buyer'), 'app_roles answers five roles, one admin, the Buyer holding cost.read');
-- the records role: views only; cost withheld; nothing else
RESET ROLE; SET ROLE inventory_records_ro;
SELECT pg_temp.as_member(23);
SELECT pg_temp.check((SELECT count(*) FROM mcp_products) = 4 AND (SELECT count(*) FROM mcp_product_variants) = 10 AND (SELECT count(*) FROM mcp_sources) = 7 AND (SELECT count(*) FROM mcp_listing_variants) >= 9, 'the records role reads the catalog, sources and listings through the views');
SELECT pg_temp.check((SELECT cost_price FROM mcp_product_variants WHERE variant_id = pg_temp.i('v_q')) IS NULL AND (SELECT count(*) FROM mcp_source_credentials) = 0, '…with cost null for the Viewer and no credential');
SELECT pg_temp.check((inv_availability(pg_temp.i('v_q'))->'prices'->'cost') = 'null'::jsonb AND (SELECT cost_withheld FROM inv_find('ph2-q')), '…and the read functions answer it the same way');
SELECT pg_temp.refused('SELECT count(*) FROM product_variants', 'permission denied', 'the records role cannot read the variants table');
SELECT pg_temp.refused('SELECT count(*) FROM source_credentials', 'permission denied', '…nor the credentials table');
SELECT pg_temp.refused('SELECT count(*) FROM activity_log', 'permission denied', '…nor the activity log');
SELECT pg_temp.refused('SELECT token_hash FROM feed_keys', 'permission denied', '…nor a feed key''s hash');
SELECT pg_temp.refused('SELECT token_hash FROM order_links_secure', 'permission denied', '…nor a door''s hash');
SELECT pg_temp.refused('SELECT count(*) FROM inv_offers_for_variant(1)', 'permission denied', '…nor the unnulled offers function');
SELECT pg_temp.refused('SELECT inv_feed_answer(1, ''{}'')', 'permission denied', '…nor the feed''s answer (the writer''s alone)');
SELECT pg_temp.refused($$INSERT INTO products (product_type_id, name) VALUES (1, 'x')$$, 'permission denied', 'the records role writes nothing');
SELECT pg_temp.refused(format($$SELECT inv_price_set(%s, 'retail', 1)$$, pg_temp.i('v_q')), 'permission denied', '…and calls no writer function');
SELECT pg_temp.check((SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind = 'r' AND has_table_privilege('inventory_records_ro', c.oid, 'SELECT') AND c.relname NOT IN ('inv_rights', 'inv_roles', 'inv_role_rights')) = 0, 'the records role has SELECT on no base table but the kit''s rights catalogue');
SELECT pg_temp.check((SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind = 'v' AND c.relname LIKE 'mcp\_%' AND c.relname <> 'mcp_activity_log' AND NOT has_table_privilege('inventory_records_ro', c.oid, 'SELECT')) = 0, '…and SELECT on every mcp_* view but the activity log');
SELECT pg_temp.check(mcp_member_kind(30) = 'agent' AND mcp_member_kind(32) IS NULL, 'mcp_member_kind answers for an admitted agent only');
SELECT pg_temp.check(mcp_admit_agent(32), 'first contact admits an active agent');
SELECT pg_temp.check(mcp_member_kind(32) = 'agent' AND NOT mcp_admit_agent(32), 'the agent is admitted, and not twice');
RESET ROLE; SET ROLE inventory_activity_ro;
SELECT pg_temp.as_member(22);
SELECT pg_temp.check((SELECT count(*) FROM mcp_activity_log) = 0, 'the activity role reads the log (empty in this proof)');
SELECT pg_temp.check((SELECT count(*) FROM mcp_products) = 4, '…and the names beside an activity row');
SELECT pg_temp.refused('SELECT count(*) FROM mcp_listing_variants', 'permission denied', 'the activity role sees no content view');
SELECT pg_temp.refused('SELECT count(*) FROM listings', 'permission denied', 'the activity role sees no base table');
RESET ROLE;

-- ================================================================ what Phase 1 found (db/016): the robots map, D8's window, the page cap, the templates' keys, the five shares, the two shared functions
CREATE OR REPLACE FUNCTION pg_temp.j(k text) RETURNS jsonb LANGUAGE sql STABLE AS $$ SELECT t::jsonb FROM ids WHERE ids.k = $1 $$;
GRANT EXECUTE ON FUNCTION pg_temp.j(text) TO PUBLIC;
SET ROLE inventory_rw;
SELECT pg_temp.as_member(22);
-- the client's robots map (host → {state, crawl_delay}) → sources.robots_state
INSERT INTO sources (name, connector, role, base_url) VALUES ('SMOKE Robots', 'jsonld', 'reference', 'https://robots.invalid');
SELECT pg_temp.keep('src_rob', id) FROM sources WHERE name = 'SMOKE Robots';
SELECT inv_source_pull_finish(inv_source_pull_start(pg_temp.i('src_rob'), 'manual', 22), 'ok', NULL, '{"robots":{"robots.invalid":{"state":"ok","crawl_delay":1},"cdn.invalid":{"state":"blocked","crawl_delay":null}}}');
SELECT pg_temp.check((SELECT robots_state FROM sources WHERE id = pg_temp.i('src_rob')) = 'blocked', 'db/016: a pull whose policy carries the client''s robots MAP — any host blocked → the source is blocked');
SELECT inv_source_pull_finish(inv_source_pull_start(pg_temp.i('src_rob'), 'manual', 22), 'ok', NULL, '{"robots":{"robots.invalid":{"state":"ok","crawl_delay":1}}}');
SELECT pg_temp.check((SELECT robots_state FROM sources WHERE id = pg_temp.i('src_rob')) = 'ok' AND (SELECT robots_checked_at FROM sources WHERE id = pg_temp.i('src_rob')) IS NOT NULL, '…every host ok and none blocked → ok, checked now');
SELECT inv_source_pull_finish(inv_source_pull_start(pg_temp.i('src_rob'), 'manual', 22), 'ok', NULL, '{"robots":{"robots.invalid":{"state":"error","crawl_delay":null}}}');
SELECT pg_temp.check((SELECT robots_state FROM sources WHERE id = pg_temp.i('src_rob')) = 'unknown', '…hosts neither ok nor blocked (error, pending) → unknown');
SELECT inv_source_pull_finish(inv_source_pull_start(pg_temp.i('src_rob'), 'manual', 22), 'ok', NULL, '{"robots":"blocked","crawl_delay":2}');
SELECT pg_temp.check((SELECT robots_state FROM sources WHERE id = pg_temp.i('src_rob')) = 'blocked', '…the glue''s scalar still reads as before');
SELECT pg_temp.check((inv_source_pull_finish(inv_source_pull_start(pg_temp.i('src_rob'), 'manual', 22), 'ok', NULL, '{"robots":{}}')).robots_state = 'blocked', '…a map with no host leaves the state as it was');
SELECT pg_temp.check((inv_source_pull_finish(inv_source_pull_start(pg_temp.i('src_rob'), 'manual', 22), 'ok', NULL, '{"robots":"none"}')).robots_state = 'blocked', '…and so does a word outside ok/blocked/unknown (no CHECK violation)');
-- D8: removal after two FULL pulls — a partial pull never counts
INSERT INTO sources (name, connector, role, base_url) VALUES ('SMOKE Window', 'shopify', 'reference', 'https://window.invalid');
SELECT pg_temp.keep('src_win', id) FROM sources WHERE name = 'SMOKE Window';
SELECT pg_temp.keep('pull_w1', inv_source_pull_start(pg_temp.i('src_win'), 'scheduled', 22));
SELECT inv_upsert_listing(pg_temp.i('src_win'), pg_temp.i('pull_w1'), '{"external_id":"w-a","title":"SMOKE Window A","vendor":"SMOKE Window Co","variants":[{"external_variant_id":"w-a-q","title":"Queen","price":100,"availability":"in_stock"}]}');
SELECT inv_upsert_listing(pg_temp.i('src_win'), pg_temp.i('pull_w1'), '{"external_id":"w-b","title":"SMOKE Window B","vendor":"SMOKE Window Co","variants":[{"external_variant_id":"w-b-q","title":"Queen","price":200,"availability":"in_stock"}]}');
SELECT inv_source_pull_finish(pg_temp.i('pull_w1'), 'ok');
UPDATE source_pulls SET started_at = now() - interval '5 hours', finished_at = now() - interval '5 hours' WHERE id = pg_temp.i('pull_w1');
UPDATE listings SET last_seen_at = now() - interval '5 hours' WHERE source_id = pg_temp.i('src_win');
SELECT pg_temp.keep('pull_w2', inv_source_pull_start(pg_temp.i('src_win'), 'scheduled', 22));
SELECT inv_upsert_listing(pg_temp.i('src_win'), pg_temp.i('pull_w2'), '{"external_id":"w-a","title":"SMOKE Window A","vendor":"SMOKE Window Co","variants":[{"external_variant_id":"w-a-q","title":"Queen","price":100,"availability":"in_stock"}]}');
SELECT inv_source_pull_finish(pg_temp.i('pull_w2'), 'partial', 'page 3 timed out');
UPDATE source_pulls SET started_at = now() - interval '4 hours', finished_at = now() - interval '4 hours' WHERE id = pg_temp.i('pull_w2');
UPDATE listings SET last_seen_at = now() - interval '4 hours' WHERE source_id = pg_temp.i('src_win') AND external_id = 'w-a';
SELECT pg_temp.keep('pull_w3', inv_source_pull_start(pg_temp.i('src_win'), 'scheduled', 22));
SELECT inv_upsert_listing(pg_temp.i('src_win'), pg_temp.i('pull_w3'), '{"external_id":"w-a","title":"SMOKE Window A","vendor":"SMOKE Window Co","variants":[{"external_variant_id":"w-a-q","title":"Queen","price":100,"availability":"in_stock"}]}');
SELECT pg_temp.check(inv_mark_removed(pg_temp.i('src_win'), pg_temp.i('pull_w3')) = 0, 'db/016 D8: a PARTIAL pull without B does not count — after one full pull without it, B stays');
SELECT pg_temp.check((SELECT removed_at FROM listings WHERE source_id = pg_temp.i('src_win') AND external_id = 'w-b') IS NULL AND (SELECT availability FROM listing_variants WHERE external_variant_id = 'w-b-q') = 'in_stock', '…B is still live with its offer');
SELECT inv_source_pull_finish(pg_temp.i('pull_w3'), 'ok');
UPDATE source_pulls SET started_at = now() - interval '1 hour', finished_at = now() - interval '1 hour' WHERE id = pg_temp.i('pull_w3');
SELECT pg_temp.keep('pull_w4', inv_source_pull_start(pg_temp.i('src_win'), 'scheduled', 22));
SELECT inv_upsert_listing(pg_temp.i('src_win'), pg_temp.i('pull_w4'), '{"external_id":"w-a","title":"SMOKE Window A","vendor":"SMOKE Window Co","variants":[{"external_variant_id":"w-a-q","title":"Queen","price":100,"availability":"in_stock"}]}');
SELECT pg_temp.check(inv_mark_removed(pg_temp.i('src_win'), pg_temp.i('pull_w4')) = 1, '…after two FULL pulls without it, B is removed');
SELECT pg_temp.check((SELECT removed_at FROM listings WHERE source_id = pg_temp.i('src_win') AND external_id = 'w-b') IS NOT NULL AND (SELECT availability FROM listing_variants WHERE external_variant_id = 'w-b-q') = 'unknown' AND (SELECT listings_removed FROM source_pulls WHERE id = pg_temp.i('pull_w4')) = 1, '…its offer is unknown and the current pull counts it');
SELECT inv_source_pull_finish(pg_temp.i('pull_w4'), 'ok');
-- the page cap and the templates' keys
SELECT pg_temp.check((SELECT column_default FROM information_schema.columns WHERE table_name = 'inv_settings' AND column_name = 'crawl_max_pages') = '500' AND (SELECT crawl_max_pages FROM inv_settings WHERE id = 1) = 500, 'db/016: crawl_max_pages defaults to 500 — the page cap every connector maps onto its own limit');
SELECT pg_temp.check((SELECT count(*) FROM source_templates) = 18 AND NOT EXISTS (SELECT 1 FROM source_templates WHERE settings ?| ARRAY['endpoint', 'limit', 'per_page', 'daily', 'transport', 'sitemap']) AND NOT EXISTS (SELECT 1 FROM source_templates WHERE settings->'mapping' ? 'map'), 'db/016: the eighteen templates carry no documentary key the connectors do not read');
SELECT pg_temp.check((SELECT settings FROM source_templates WHERE key = 'jsonld_site') = '{"urls": [], "sitemap_url": null}'::jsonb AND (SELECT settings->'mapping' ? 'map_price' AND settings->'mapping' ? 'supplier_sku' AND settings->'mapping' ? 'lead_time_days' FROM source_templates WHERE key = 'dealer_feed_csv') AND (SELECT settings FROM source_templates WHERE key = 'casper') = '{}'::jsonb AND (SELECT settings FROM source_templates WHERE key = 'woocommerce_store') = '{}'::jsonb, '…jsonld_site says sitemap_url, the dealer feed maps map_price, a store needs no setting');
SELECT pg_temp.check((SELECT brand_hint || '|' || base_url || '|' || connector || '|' || role || '|' || survey_result FROM source_templates WHERE key = 'casper') = 'Casper|https://casper.com|shopify|reference|unverified' AND (SELECT notes FROM source_templates WHERE key = 'dealer_feed_csv') = 'HTTPS URL or SFTP; map the columns once', '…every other field of a template is as seeded');
-- what the shares must agree with, kept as the writer
SELECT pg_temp.keept('so1_no', (SELECT number FROM sales_orders WHERE id = pg_temp.i('so1')));
SELECT pg_temp.keept('so1_total', (SELECT total::text FROM sales_orders WHERE id = pg_temp.i('so1')));
SELECT pg_temp.keept('so1_cogs', (SELECT sum(l.qty * COALESCE(l.offer_cost, v.cost_price, 0))::text FROM sales_order_lines l JOIN product_variants v ON v.id = l.variant_id WHERE l.sales_order_id = pg_temp.i('so1') AND l.status <> 'cancelled'));
SELECT pg_temp.keept('exp_rec', (SELECT sum(l.qty * l.unit_cost)::text FROM goods_receipt_lines l JOIN goods_receipts g ON g.id = l.goods_receipt_id WHERE g.status = 'posted'));
SELECT pg_temp.keept('exp_drop', (SELECT (sum(pl.qty_ordered * pl.unit_cost) + (SELECT shipping_cost FROM purchase_orders WHERE id = pg_temp.i('po_z')))::text FROM purchase_order_lines pl JOIN purchase_orders po ON po.id = pl.purchase_order_id WHERE po.kind = 'dropship' AND pl.status = 'received'));
SELECT pg_temp.keept('exp_units', (SELECT sum(qty_on_hand)::text FROM inventory_balances));
SELECT pg_temp.keept('exp_value', (SELECT sum(b.qty_on_hand * COALESCE(v.cost_price, 0))::text FROM inventory_balances b JOIN product_variants v ON v.id = b.variant_id));
-- the shares, as the kernel's token reaches them: the read role, NO app.member_id
RESET ROLE; SET ROLE inventory_records_ro;
SELECT pg_temp.as_member(NULL);
SELECT pg_temp.check(NOT inv_is_member_here(), 'the shares run as nobody (the kernel''s token sets no member)');
SELECT pg_temp.keept('sh_sales', inv_share_sales_closed(current_date - 30, current_date)::text);
SELECT pg_temp.check(pg_temp.j('sh_sales')->>'schema' = 'os.inventory-sales/1' AND pg_temp.j('sh_sales')->>'application' = 'inventory' AND pg_temp.j('sh_sales')->>'currency' = 'USD' AND (pg_temp.j('sh_sales')->>'count')::int = 1 AND pg_temp.j('sh_sales')->'orders'->0->>'number' = pg_temp.t('so1_no') AND pg_temp.j('sh_sales')->'period'->>'to' = current_date::text, 'share sales_closed answers os.inventory-sales/1: the one closed order in the period');
SELECT pg_temp.check((pg_temp.j('sh_sales')->'orders'->0->>'cogs')::numeric = pg_temp.t('so1_cogs')::numeric AND (pg_temp.j('sh_sales')->'orders'->0->>'cogs')::numeric > 0 AND (pg_temp.j('sh_sales')->'totals'->>'cogs')::numeric = pg_temp.t('so1_cogs')::numeric, '…with cogs UNNULLED (Σ qty × COALESCE(offer_cost, cost_price)) on the order and in the totals');
SELECT pg_temp.check(jsonb_array_length(pg_temp.j('sh_sales')->'orders'->0->'payments') = 3 AND (pg_temp.j('sh_sales')->'orders'->0->'payments_by_method'->>'card')::numeric = pg_temp.t('so1_total')::numeric AND (pg_temp.j('sh_sales')->'orders'->0->>'refunds')::numeric = 1599 AND (pg_temp.j('sh_sales')->'totals'->'payments_by_method'->>'card')::numeric = pg_temp.t('so1_total')::numeric AND (pg_temp.j('sh_sales')->'totals'->>'total')::numeric = pg_temp.t('so1_total')::numeric, '…the deposit, the balance and the refund: payments by method, refunds, the period''s totals');
SELECT pg_temp.check(jsonb_array_length(pg_temp.j('sh_sales')->'orders'->0->'lines') = 3 AND pg_temp.j('sh_sales')->'orders'->0->'lines'->1->>'fulfilment_kind' = 'dropship' AND pg_temp.j('sh_sales')->'orders'->0->'lines'->0->>'size' = 'Queen' AND pg_temp.j('sh_sales')->'orders'->0->'tax'->>'name' = 'SMOKE Cook County' AND pg_temp.j('sh_sales')->'orders'->0->'customer'->>'name' = 'SMOKE Alvarez' AND jsonb_array_length(pg_temp.j('sh_sales')->'orders'->0->'returns') = 1, '…the three live lines (the declined King''s was cancelled with its PO), the tax, the customer''s name and the return');
SELECT pg_temp.check(position('ship_to' IN pg_temp.t('sh_sales')) = 0 AND position('@' IN pg_temp.t('sh_sales')) = 0 AND position('555' IN pg_temp.t('sh_sales')) = 0 AND position('Lake St' IN pg_temp.t('sh_sales')) = 0 AND pg_temp.t('sh_sales') NOT ILIKE '%salesperson%' AND pg_temp.t('sh_sales') NOT ILIKE '%member%', '…and no address, phone, email or salesperson anywhere in it');
SELECT pg_temp.check((inv_share_sales_closed(current_date - 30, current_date, 1, 10)->'orders') = '[]'::jsonb AND (inv_share_sales_closed(current_date - 30, current_date, 1, 10)->>'count')::int = 1 AND (inv_share_sales_closed(current_date - 30, current_date, 0, 1)->>'truncated')::boolean = false AND (inv_share_sales_closed(current_date - 30, current_date, 0, 9999)->>'limit')::int = 500, '…paged: an offset past the end answers no orders and the same count; the limit is clamped to 500');
SELECT pg_temp.refused('SELECT inv_share_sales_closed(current_date - 93, current_date)', 'at most 92 days', '…a period over 92 days is refused');
SELECT pg_temp.keept('sh_purch', inv_share_purchases_received(current_date - 30, current_date)::text);
SELECT pg_temp.keept('zinus_grp', (SELECT s::text FROM jsonb_array_elements(pg_temp.j('sh_purch')->'suppliers') s WHERE s->>'name' = 'SMOKE Zinus'));
SELECT pg_temp.check(pg_temp.j('sh_purch')->>'schema' = 'os.inventory-purchases/1' AND (pg_temp.j('sh_purch')->>'count')::int = 3 AND jsonb_array_length(pg_temp.j('sh_purch')->'suppliers') = 1 AND pg_temp.t('zinus_grp') IS NOT NULL, 'share purchases_received answers os.inventory-purchases/1: three documents, one supplier (Zinus) in the period');
SELECT pg_temp.check(jsonb_array_length(pg_temp.j('zinus_grp')->'receipts') = 2 AND jsonb_array_length(pg_temp.j('zinus_grp')->'dropships') = 1 AND pg_temp.j('zinus_grp')->'dropships'->0->>'sales_order_number' = pg_temp.t('so1_no') AND pg_temp.j('zinus_grp')->'receipts'->0->>'number' = 'GR-00001', '…two posted receipts and the delivered drop-ship, tied to the sale by its number');
SELECT pg_temp.check((pg_temp.j('zinus_grp')->'receipts'->0->'lines'->0->>'unit_cost')::numeric = 830 AND (pg_temp.j('zinus_grp')->'receipts'->1->'lines'->0->>'discrepancy_kind') = 'short' AND (pg_temp.j('zinus_grp')->'dropships'->0->'lines'->0->>'unit_cost')::numeric > 0 AND (pg_temp.j('zinus_grp')->'dropships'->0->'lines'->0->>'delivered_at') IS NOT NULL, '…at cost, UNNULLED, with the discrepancy and the delivery');
SELECT pg_temp.check((pg_temp.j('sh_purch')->'totals'->>'receipts')::numeric = pg_temp.t('exp_rec')::numeric AND (pg_temp.j('sh_purch')->'totals'->>'dropships')::numeric = pg_temp.t('exp_drop')::numeric AND (pg_temp.j('sh_purch')->'totals'->>'total')::numeric = pg_temp.t('exp_rec')::numeric + pg_temp.t('exp_drop')::numeric AND (pg_temp.j('zinus_grp')->>'total')::numeric = pg_temp.t('exp_rec')::numeric + pg_temp.t('exp_drop')::numeric, '…the totals tie to the receipt lines and the drop-ship lines');
SELECT pg_temp.check(position('ship_to' IN pg_temp.t('sh_purch')) = 0 AND position('Lake St' IN pg_temp.t('sh_purch')) = 0 AND position('555' IN pg_temp.t('sh_purch')) = 0 AND pg_temp.t('sh_purch') NOT ILIKE '%phone%' AND pg_temp.t('sh_purch') NOT ILIKE '%Alvarez%', '…and never the customer''s address, phone or name');
SELECT pg_temp.refused('SELECT inv_share_purchases_received(current_date, current_date - 1)', 'ends before it starts', '…a period ending before it starts is refused');
SELECT pg_temp.keept('sh_val', inv_share_stock_valuation(current_date)::text);
SELECT pg_temp.check(pg_temp.j('sh_val')->>'schema' = 'os.inventory-valuation/1' AND pg_temp.j('sh_val')->>'by' = 'location' AND pg_temp.j('sh_val')->>'as_of' = current_date::text AND (pg_temp.j('sh_val')->'totals'->>'units')::bigint = pg_temp.t('exp_units')::bigint AND (pg_temp.j('sh_val')->'totals'->>'value')::numeric = pg_temp.t('exp_value')::numeric, 'share stock_valuation answers os.inventory-valuation/1: the units and the value at standard cost, UNNULLED, as the balances say');
SELECT pg_temp.check((SELECT (r->>'value')::numeric > 0 AND (r->>'variants')::int >= 2 FROM jsonb_array_elements(pg_temp.j('sh_val')->'rows') r WHERE r->>'group_name' = 'SMOKE Warehouse') AND pg_temp.j('sh_val')->>'cost_basis' LIKE 'standard (product_variants.cost_price; cost_source = last_receipt)', '…the warehouse row carries its value and its variants; the basis is named');
SELECT pg_temp.check((SELECT count(*) FROM jsonb_array_elements(inv_share_stock_valuation(current_date, 'brand')->'rows') r WHERE r->>'group_name' = 'SMOKE Purple') = 1 AND (inv_share_stock_valuation(current_date, 'brand')->'totals'->>'units')::bigint = pg_temp.t('exp_units')::bigint, '…by brand the same units');
SELECT pg_temp.refused('SELECT inv_share_stock_valuation(current_date, ''type'')', 'location or brand', '…by type is refused (the ledger closes by location or brand)');
SELECT pg_temp.keept('sh_av', inv_share_availability_index('{"q":"purple hybrid","size":"queen"}')::text);
SELECT pg_temp.check(pg_temp.j('sh_av')->>'schema' = 'os.inventory-availability/1' AND (pg_temp.j('sh_av')->>'count')::int >= 1 AND pg_temp.j('sh_av')->'query'->>'size' = 'queen' AND (SELECT r->>'availability' = 'in_stock' AND r->>'size' = 'Queen' AND r->>'brand' = 'SMOKE Purple' AND (r->>'retail_price')::numeric > 0 FROM jsonb_array_elements(pg_temp.j('sh_av')->'rows') r WHERE r->>'sku' = 'PH2-Q'), 'share availability_index answers os.inventory-availability/1: the Queen in stock at our retail');
SELECT pg_temp.check(position('quantity' IN pg_temp.t('sh_av')) = 0 AND position('partner' IN pg_temp.t('sh_av')) = 0 AND position('cost' IN pg_temp.t('sh_av')) = 0 AND position('source' IN pg_temp.t('sh_av')) = 0 AND position('Zinus' IN pg_temp.t('sh_av')) = 0, '…without a quantity, a partner price, a cost or a source''s name');
SELECT pg_temp.check((inv_share_availability_index(jsonb_build_object('gtin', pg_temp.gtin('840000000104')))->>'count')::int = 1 AND inv_share_availability_index(jsonb_build_object('gtin', pg_temp.gtin('840000000104')))->'rows'->0->>'sku' = 'PH2-Q', '…by GTIN: exact');
SELECT pg_temp.check(inv_share_availability_index('{"sku":"ph2-ck"}')->'rows'->0->>'availability' = 'back_order' AND (inv_share_availability_index('{"sku":"ph2-ck"}')->'rows'->0->>'lead_time_days')::int = 3, '…by SKU: the Cal King a supplier ships in three days is back_order');
SELECT pg_temp.check((inv_share_availability_index('{"q":"zzzz nothing here"}')->>'count')::int = 0 AND (inv_share_availability_index('{}')->>'count')::int = 0 AND inv_share_availability_index('{}')->'rows' = '[]'::jsonb, '…nothing found, or nothing asked, is an empty answer');
SELECT pg_temp.keept('sh_co', inv_share_customer_orders('ALVAREZ@example.invalid')::text);
SELECT pg_temp.keept('sh_co2', inv_share_customer_orders('alvarez@example.invalid', false, 50)::text);
SELECT pg_temp.check(pg_temp.j('sh_co')->>'schema' = 'os.inventory-orders/1' AND (pg_temp.j('sh_co')->>'found')::boolean AND pg_temp.j('sh_co')->'customer'->>'name' = 'SMOKE Alvarez' AND (pg_temp.j('sh_co')->>'count')::int = 0 AND pg_temp.j('sh_co')->'orders' = '[]'::jsonb, 'share customer_orders answers os.inventory-orders/1 by email, case-insensitively: known, with no OPEN order');
SELECT pg_temp.check((pg_temp.j('sh_co2')->>'count')::int = 2 AND pg_temp.j('sh_co2')->'orders'->1->>'number' = pg_temp.t('so1_no') AND pg_temp.j('sh_co2')->'orders'->1->>'status' = 'closed' AND pg_temp.j('sh_co2')->'orders'->0->>'status' = 'cancelled', '…with open_only false, both orders, newest first');
SELECT pg_temp.check(pg_temp.j('sh_co2')->'orders'->1->'lines'->1->>'fulfilment' = 'ships from our supplier' AND pg_temp.j('sh_co2')->'orders'->1->'lines'->1->'shipment'->>'tracking_number' = 'XPO123456' AND (pg_temp.j('sh_co2')->'orders'->1->'lines'->1->'shipment'->>'delivered_at') IS NOT NULL AND pg_temp.j('sh_co2')->'orders'->1->'lines'->0->>'fulfilment' = 'from stock' AND pg_temp.j('sh_co2')->'orders'->1->'lines'->3->>'fulfilment' = 'backordered', '…a line''s fulfilment in the customer''s words, with the shipment''s tracking');
SELECT pg_temp.check((pg_temp.j('sh_co2')->'orders'->1->>'order_page_live')::boolean AND jsonb_array_length(pg_temp.j('sh_co2')->'orders'->1->'returns') = 1 AND pg_temp.j('sh_co2')->'orders'->1->'returns'->0->>'status' = 'closed' AND (pg_temp.j('sh_co2')->'orders'->1->>'is_late')::boolean = false, '…whether the order page is live (never its token), the return, lateness');
SELECT pg_temp.check(position('Zinus' IN pg_temp.t('sh_co2')) = 0 AND position('Malouf' IN pg_temp.t('sh_co2')) = 0 AND position('Lake St' IN pg_temp.t('sh_co2')) = 0 AND position('555' IN pg_temp.t('sh_co2')) = 0 AND position('token' IN pg_temp.t('sh_co2')) = 0 AND pg_temp.t('sh_co2') NOT ILIKE '%cost%' AND pg_temp.t('sh_co2') NOT ILIKE '%salesperson%', '…and no supplier''s name, address, phone, token, cost or salesperson');
SELECT pg_temp.check((inv_share_customer_orders('nobody@example.invalid')->>'found')::boolean = false AND inv_share_customer_orders('nobody@example.invalid')->'orders' = '[]'::jsonb AND inv_share_customer_orders('nobody@example.invalid')->'customer' = 'null'::jsonb, '…an email we do not know: found false, no orders');
SELECT pg_temp.check(has_function_privilege('inventory_records_ro', 'inv_share_sales_closed(date,date,integer,integer)', 'EXECUTE') AND has_function_privilege('inventory_records_ro', 'inv_share_purchases_received(date,date,integer,integer)', 'EXECUTE') AND has_function_privilege('inventory_records_ro', 'inv_share_stock_valuation(date,text)', 'EXECUTE') AND has_function_privilege('inventory_records_ro', 'inv_share_availability_index(jsonb,integer)', 'EXECUTE') AND has_function_privilege('inventory_records_ro', 'inv_share_customer_orders(citext,boolean,integer)', 'EXECUTE') AND has_function_privilege('inventory_records_ro', 'inv_fulfilment_today(date,bigint)', 'EXECUTE') AND has_function_privilege('inventory_records_ro', 'inv_sales_summary(date,date,text)', 'EXECUTE'), 'the read role may EXECUTE the five shares and the two shared functions');
SELECT pg_temp.check(NOT has_function_privilege('inventory_activity_ro', 'inv_share_sales_closed(date,date,integer,integer)', 'EXECUTE') AND NOT has_function_privilege('inventory_activity_ro', 'inv_sales_summary(date,date,text)', 'EXECUTE'), '…the activity role may not (nothing granted to PUBLIC)');
-- the two shared functions: an order promised today, then the Warehouse's list and the Sales summary
RESET ROLE; SET ROLE inventory_rw;
SELECT pg_temp.as_member(20);
INSERT INTO sales_orders (customer_id, salesperson_member_id, location_id, delivery_method, created_by, promised_on) VALUES (pg_temp.i('cust'), 20, pg_temp.i('l_sr'), 'delivery', 20, current_date);
SELECT pg_temp.keep('so3', id) FROM sales_orders WHERE customer_id = pg_temp.i('cust') AND status = 'quote';
INSERT INTO sales_order_lines (sales_order_id, line_no, variant_id, qty, fulfilment_kind, location_id) VALUES (pg_temp.i('so3'), 1, pg_temp.i('v_q'), 1, 'stock', pg_temp.i('l_wh')), (pg_temp.i('so3'), 2, pg_temp.i('v_t'), 1, 'backorder', pg_temp.i('l_wh'));
SELECT inv_order_confirm(pg_temp.i('so3'), 20);
SELECT pg_temp.as_member(21);
SELECT pg_temp.check((SELECT count(*) FROM inv_fulfilment_today()) = 2 AND (SELECT count(*) FROM inv_fulfilment_today() WHERE sales_order_id = pg_temp.i('so3')) = 2, 'inv_fulfilment_today: the two lines of the order promised today — nothing closed or cancelled');
SELECT pg_temp.check((SELECT line_status || '/' || qty_allocated || '/' || qty_shipped FROM inv_fulfilment_today() WHERE sales_order_id = pg_temp.i('so3') AND line_no = 1) = 'allocated/1/0' AND (SELECT line_status || '/' || fulfilment_kind FROM inv_fulfilment_today() WHERE sales_order_id = pg_temp.i('so3') AND line_no = 2) = 'open/backorder', '…the stock line allocated and unshipped, the backorder open');
SELECT pg_temp.check((SELECT count(*) FROM inv_fulfilment_today(current_date, pg_temp.i('l_wh'))) = 2 AND (SELECT count(*) FROM inv_fulfilment_today(current_date, pg_temp.i('l_sr'))) = 0 AND (SELECT count(*) FROM inv_fulfilment_today(current_date - 1)) = 0 AND (SELECT count(*) FROM inv_fulfilment_today(current_date + 1)) = 2, '…by the line''s location; nothing was due yesterday; still due tomorrow');
SELECT pg_temp.check((SELECT location_name || '|' || delivery_method || '|' || customer_name || '|' || order_number || '|' || sku || '|' || size_name FROM inv_fulfilment_today() WHERE sales_order_id = pg_temp.i('so3') AND line_no = 1) = 'SMOKE Warehouse|delivery|SMOKE Alvarez|' || (SELECT number FROM sales_orders WHERE id = pg_temp.i('so3')) || '|PH2-Q|Queen', '…with the names a picker reads');
SELECT pg_temp.as_member(NULL);
SELECT pg_temp.refused('SELECT count(*) FROM inv_fulfilment_today()', 'Not admitted', 'nobody reads the fulfilment list');
SELECT pg_temp.as_member(20);
SELECT pg_temp.check((SELECT cogs IS NULL AND margin_pct IS NULL AND cost_withheld AND revenue > 0 AND tax >= 0 AND orders = 2 AND units_sold = 6 FROM inv_sales_summary(current_date - 30, current_date, 'salesperson') WHERE group_name = 'SMOKE Sam Sales'), 'inv_sales_summary for Sales: the two confirmed orders (the cancelled one never, nor a cancelled line) at retail — cogs and margin withheld');
SELECT pg_temp.as_member(22);
SELECT pg_temp.check((SELECT cogs > 0 AND margin_pct IS NOT NULL AND NOT cost_withheld AND units_sold = 6 AND group_id = 20 FROM inv_sales_summary(current_date - 30, current_date, 'salesperson') WHERE group_name = 'SMOKE Sam Sales'), '…for the Buyer: cogs and the margin');
SELECT pg_temp.check((SELECT group_key = to_char(current_date, 'YYYY-MM-DD') AND group_id IS NULL AND orders = 2 FROM inv_sales_summary(current_date - 30, current_date, 'day')), '…by day: one row keyed on today');
SELECT pg_temp.check((SELECT count(*) FROM inv_sales_summary(current_date - 30, current_date, 'week')) = 1 AND (SELECT group_key FROM inv_sales_summary(current_date - 30, current_date, 'week')) = to_char(current_date, 'IYYY-"W"IW') AND (SELECT count(*) FROM inv_sales_summary(current_date - 30, current_date, 'month')) = 1 AND (SELECT orders FROM inv_sales_summary(current_date - 30, current_date, 'location') WHERE group_id = pg_temp.i('l_sr')) = 2, '…by week, month and location');
SELECT pg_temp.check((SELECT sum(units_sold) FROM inv_sales_summary(current_date - 30, current_date, 'brand')) = 6 AND (SELECT group_name FROM inv_sales_summary(current_date - 30, current_date, 'brand')) = 'SMOKE Purple' AND (SELECT units_sold FROM inv_sales_summary(current_date - 30, current_date, 'variant') WHERE group_key = 'PH2-T') = 3 AND (SELECT count(*) FROM inv_sales_summary(current_date - 30, current_date, 'type')) = 1, '…by brand, variant (the Twin: two backordered, one more today) and type');
SELECT pg_temp.check((SELECT count(*) FROM inv_sales_summary(current_date - 60, current_date - 31, 'day')) = 0, '…a period with nothing sold is empty');
SELECT pg_temp.refused('SELECT count(*) FROM inv_sales_summary(current_date - 30, current_date, ''hour'')', 'Group by day', '…an unknown grouping is refused');
SELECT pg_temp.as_member(23);
SELECT pg_temp.check((SELECT cost_withheld AND revenue > 0 FROM inv_sales_summary(current_date - 30, current_date, 'brand')), '…a Viewer reads it too, at retail');
SELECT pg_temp.as_member(NULL);
SELECT pg_temp.refused('SELECT count(*) FROM inv_sales_summary(current_date - 30, current_date, ''brand'')', 'Not admitted', '…nobody does not');
RESET ROLE;

-- ================================================================ db/017: "not ours", the share.read logger, the two settings columns
SET ROLE inventory_rw;
SELECT pg_temp.as_member(22);
-- a third Casper listing: the Queen matched by GTIN at the pull, the Twin scored, the King unmatched
SELECT pg_temp.keep('lst_c3', inv_upsert_listing(pg_temp.i('src_casper'), NULL, format('{"external_id":"c-300","title":"Purple Hybrid 2 Mattress","vendor":"SMOKE Purple","product_type":"Mattress","variants":[{"external_variant_id":"c-300-q","title":"Queen","sku":"CSP-PH2-Q-ALT","barcode":"%s","price":1599,"availability":"in_stock"},{"external_variant_id":"c-300-t","title":"Twin","sku":"CSP-PH2-T-ALT","price":899,"availability":"in_stock"},{"external_variant_id":"c-300-k","title":"King","sku":"CSP-PH2-K-ALT","price":1999,"availability":"in_stock"}]}', pg_temp.gtin('840000000104'))::jsonb));
SELECT pg_temp.keep('lv_c3q', id) FROM listing_variants WHERE external_variant_id = 'c-300-q';
SELECT pg_temp.keep('lv_c3t', id) FROM listing_variants WHERE external_variant_id = 'c-300-t';
SELECT pg_temp.keep('lv_c3k', id) FROM listing_variants WHERE external_variant_id = 'c-300-k';
SELECT pg_temp.check((SELECT count(*) FROM listing_variants WHERE listing_id = pg_temp.i('lst_c3')) = 3 AND (SELECT match_kind FROM listing_variants WHERE id = pg_temp.i('lv_c3q')) = 'gtin' AND (SELECT variant_id FROM listing_variants WHERE id = pg_temp.i('lv_c3q')) = pg_temp.i('v_q') AND (SELECT forgotten_at FROM listings WHERE id = pg_temp.i('lst_c3')) IS NULL, 'a listing with three variants, its Queen matched by GTIN, not forgotten');
SELECT pg_temp.keep('c3t_proposed', inv_propose_matches(pg_temp.i('lv_c3t')));
SELECT pg_temp.check(pg_temp.i('c3t_proposed') >= 1 AND EXISTS (SELECT 1 FROM match_proposals WHERE listing_variant_id = pg_temp.i('lv_c3t') AND variant_id = pg_temp.i('v_t') AND status = 'proposed'), '…its Twin has an open proposal');
SELECT pg_temp.keep('prop_c3t', id) FROM match_proposals WHERE listing_variant_id = pg_temp.i('lv_c3t') AND variant_id = pg_temp.i('v_t');
SELECT pg_temp.check((SELECT count(*) FROM inv_unmatched_listings() WHERE listing_id = pg_temp.i('lst_c3')) = 2 AND (SELECT count(*) FROM inv_unmatched_listings() WHERE listing_variant_id = pg_temp.i('lv_c3t')) = 1 AND (SELECT count(*) FROM inv_unmatched_listings() WHERE listing_variant_id = pg_temp.i('lv_c3k')) = 1, '…and the Twin and King sit in the match queue');
-- the person marks it not ours
SELECT pg_temp.check(inv_listing_forget(pg_temp.i('lst_c3'), 22) = 1, 'inv_listing_forget returns the one variant it unmatched');
SELECT pg_temp.keept('c3_forgot', (SELECT forgotten_at::text FROM listings WHERE id = pg_temp.i('lst_c3')));
SELECT pg_temp.check((SELECT forgotten_at FROM listings WHERE id = pg_temp.i('lst_c3')) IS NOT NULL AND (SELECT forgotten_by FROM listings WHERE id = pg_temp.i('lst_c3')) = 22 AND (SELECT removed_at FROM listings WHERE id = pg_temp.i('lst_c3')) IS NULL, '…forgotten_at set, forgotten_by Bea, the listing not removed (it stays)');
SELECT pg_temp.check((SELECT variant_id IS NULL AND match_kind IS NULL AND match_confidence IS NULL AND matched_by IS NULL AND matched_at IS NULL FROM listing_variants WHERE id = pg_temp.i('lv_c3q')), '…the Queen''s GTIN match is undone');
SELECT pg_temp.check((SELECT status = 'dismissed' AND decided_by = 22 AND decided_at IS NOT NULL FROM match_proposals WHERE id = pg_temp.i('prop_c3t')) AND NOT EXISTS (SELECT 1 FROM match_proposals mp JOIN listing_variants lv ON lv.id = mp.listing_variant_id WHERE lv.listing_id = pg_temp.i('lst_c3') AND mp.status = 'proposed'), '…the Twin''s open proposal is dismissed by Bea; none left open');
SELECT pg_temp.refused(format($$SELECT inv_listing_forget(%s, 22)$$, pg_temp.i('lst_c3')), 'already marked not ours', 'forgetting it again is refused');
SELECT pg_temp.refused('SELECT inv_listing_forget(999999999, 22)', 'No such listing', 'forgetting a listing that is not there is refused');
-- hidden from the queue; the other listings' variants still there
SELECT pg_temp.check((SELECT count(*) FROM inv_unmatched_listings() WHERE listing_id = pg_temp.i('lst_c3')) = 0 AND (SELECT count(*) FROM inv_unmatched_listings() WHERE listing_variant_id = pg_temp.i('lv_ck')) = 1 AND (SELECT count(*) FROM inv_unmatched_listings() WHERE listing_variant_id = pg_temp.i('lv_cx')) = 1, 'inv_unmatched_listings hides every variant of the forgotten listing — Casper''s King and Olympic Queen are still queued');
SELECT pg_temp.check((SELECT count(*) FROM inv_unmatched_listings(pg_temp.i('src_casper')) WHERE listing_id = pg_temp.i('lst_c3')) = 0 AND (SELECT count(*) FROM inv_unmatched_listings(pg_temp.i('src_casper')) WHERE listing_variant_id = pg_temp.i('lv_ck')) = 1, '…by source too (Casper''s own unmatched King still queued)');
-- the next pull: the GTIN that would match, a new price — not matched, not un-forgotten, the offer kept
SELECT inv_upsert_listing(pg_temp.i('src_casper'), NULL, format('{"external_id":"c-300","title":"Purple Hybrid 2 Mattress","vendor":"SMOKE Purple","variants":[{"external_variant_id":"c-300-q","title":"Queen","sku":"CSP-PH2-Q-ALT","barcode":"%s","price":1499,"availability":"in_stock"},{"external_variant_id":"c-300-k","title":"King","sku":"CSP-PH2-K-ALT","barcode":"%s","price":1999,"availability":"in_stock"}]}', pg_temp.gtin('840000000104'), pg_temp.gtin('84000000020'))::jsonb);
SELECT pg_temp.check((SELECT variant_id IS NULL AND match_kind IS NULL FROM listing_variants WHERE id = pg_temp.i('lv_c3q')) AND (SELECT variant_id IS NULL AND match_kind IS NULL AND barcode_valid FROM listing_variants WHERE id = pg_temp.i('lv_c3k')), 'the matcher skips a forgotten listing: the Queen''s GTIN (which matched before) writes no match at the pull, nor does the King''s');
SELECT pg_temp.check((SELECT forgotten_at::text FROM listings WHERE id = pg_temp.i('lst_c3')) = pg_temp.t('c3_forgot') AND (SELECT forgotten_by FROM listings WHERE id = pg_temp.i('lst_c3')) = 22 AND (SELECT removed_at FROM listings WHERE id = pg_temp.i('lst_c3')) IS NULL, '…the next pull does not un-forget it (forgotten_at and forgotten_by kept)');
SELECT pg_temp.check((SELECT price FROM listing_variants WHERE id = pg_temp.i('lv_c3q')) = 1499 AND (SELECT count(*) FROM offer_snapshots WHERE listing_variant_id = pg_temp.i('lv_c3q')) = 2 AND (SELECT price FROM offer_snapshots WHERE listing_variant_id = pg_temp.i('lv_c3q') ORDER BY id DESC LIMIT 1) = 1499, '…and its pulls keep its offers: the price change took and was snapshotted');
SELECT pg_temp.check(inv_match_listing_variant(pg_temp.i('lv_c3q')) IS NULL AND inv_match_listing_variant(pg_temp.i('lv_c3k')) IS NULL AND (SELECT variant_id FROM listing_variants WHERE id = pg_temp.i('lv_c3q')) IS NULL, 'inv_match_listing_variant called by hand answers NULL and writes nothing');
SELECT pg_temp.check(inv_propose_matches(pg_temp.i('lv_c3t')) = 0 AND inv_propose_matches(pg_temp.i('lv_c3k')) = 0 AND (SELECT count(*) FROM match_proposals WHERE listing_variant_id = pg_temp.i('lv_c3t')) = 1 AND (SELECT count(*) FROM match_proposals WHERE listing_variant_id = pg_temp.i('lv_c3k')) = 0, 'inv_propose_matches writes nothing for a forgotten listing (the dismissed row stays the only one)');
SELECT pg_temp.refused(format($$SELECT inv_listing_match(%s, %s, 22)$$, pg_temp.i('lv_c3k'), pg_temp.i('v_k')), 'marked not ours', 'inv_listing_match refuses a forgotten listing in words');
SELECT pg_temp.refused(format($$SELECT inv_listing_match(%s, %s, 22)$$, pg_temp.i('lv_c3q'), pg_temp.i('v_q')), 'marked not ours', '…even with the GTIN in hand, and before the size wall is asked');
SELECT pg_temp.refused(format($$SELECT inv_proposal_accept(%s, 22)$$, pg_temp.i('prop_c3t')), 'already dismissed', '…and its dismissed proposal is not accepted');
-- what the agents see
SELECT pg_temp.check((SELECT forgotten_at IS NOT NULL AND forgotten_by = 22 FROM mcp_listings WHERE listing_id = pg_temp.i('lst_c3')) AND (SELECT forgotten_at IS NULL AND forgotten_by IS NULL FROM mcp_listings WHERE listing_id = pg_temp.i('lst_c1')), 'mcp_listings shows forgotten_at and forgotten_by');
SELECT pg_temp.check((SELECT string_agg(column_name, ',' ORDER BY ordinal_position) FROM (SELECT column_name, ordinal_position FROM information_schema.columns WHERE table_name = 'mcp_listings' ORDER BY ordinal_position DESC LIMIT 2) c) = 'forgotten_at,forgotten_by', '…as its last two columns (appended, never reordered)');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM pg_indexes WHERE tablename = 'listings' AND indexname = 'listings_forgotten_idx' AND indexdef LIKE '%WHERE (forgotten_at IS NOT NULL)%'), 'the partial index on forgotten listings is there');
SELECT pg_temp.check(has_function_privilege('inventory_rw', 'inv_listing_forget(bigint,bigint)', 'EXECUTE') AND NOT has_function_privilege('inventory_records_ro', 'inv_listing_forget(bigint,bigint)', 'EXECUTE') AND NOT has_function_privilege('inventory_activity_ro', 'inv_listing_forget(bigint,bigint)', 'EXECUTE'), 'inv_listing_forget is the writer''s alone');
-- mcp_settings: the two columns get_settings owes, last
UPDATE inv_settings SET business_address = E'12 SMOKE Lane\nSpringfield' WHERE id = 1;
SELECT pg_temp.check((SELECT string_agg(column_name, ',' ORDER BY ordinal_position) FROM (SELECT column_name, ordinal_position FROM information_schema.columns WHERE table_name = 'mcp_settings' ORDER BY ordinal_position DESC LIMIT 2) c) = 'business_address,raw_max_bytes', 'mcp_settings carries business_address and raw_max_bytes as its last two columns');
SELECT pg_temp.check((SELECT business_address = E'12 SMOKE Lane\nSpringfield' AND raw_max_bytes = 8192 AND business_name = 'SMOKE Mattress Co' FROM mcp_settings), '…with the address and the shipped raw cap, beside the columns it had');
RESET ROLE; SET ROLE inventory_records_ro;
SELECT pg_temp.as_member(23);
SELECT pg_temp.check((SELECT raw_max_bytes = 8192 AND business_address IS NOT NULL AND crawl_user_agent IS NULL FROM mcp_settings), '…the records role reads them through the view (the grant kept; the user-agent still sources.write''s)');
SELECT pg_temp.as_member(NULL);
SELECT pg_temp.check((SELECT count(*) FROM mcp_settings) = 0 AND (SELECT count(*) FROM mcp_listings) = 0, '…and neither view answers nobody');
-- the share.read logger, as the records server calls it: the read role, NO app.member_id
SELECT pg_temp.keep('share_log1', inv_log_share_read('sales_closed', 'gl', 1, 'req-smoke-1'));
SELECT pg_temp.check(pg_temp.i('share_log1') IS NOT NULL AND pg_temp.i('share_log1') > 0, 'inv_log_share_read as inventory_records_ro answers the row''s id');
SELECT pg_temp.keep('share_log2', inv_log_share_read('availability_index', NULL, 0));
SELECT pg_temp.refused($$SELECT inv_log_share_read('', 'gl', 1)$$, 'names its tool', '…a read with no tool name is refused');
SELECT pg_temp.refused('SELECT count(*) FROM activity_log', 'permission denied', '…the role itself still reads no log row');
RESET ROLE; SET ROLE inventory_rw;
SELECT pg_temp.check((SELECT count(*) FROM activity_log WHERE action = 'share.read') = 2 AND (SELECT count(*) FROM activity_log WHERE id = pg_temp.i('share_log1')) = 1, 'exactly one row per call (two calls, two share.read rows)');
SELECT pg_temp.check((SELECT source = 'mcp' AND actor_member_id IS NULL AND entity_type = 'share' AND entity_id IS NULL AND before IS NULL AND agent_run_id IS NULL AND source_id IS NULL AND token_id IS NULL FROM activity_log WHERE id = pg_temp.i('share_log1')), '…source mcp, no actor, entity_type share, nothing else set');
SELECT pg_temp.check((SELECT after FROM activity_log WHERE id = pg_temp.i('share_log1')) = '{"tool":"sales_closed","consumer":"gl","count":1,"request_id":"req-smoke-1"}'::jsonb, '…the payload is {tool, consumer, count, request_id} and nothing else');
SELECT pg_temp.check((SELECT after FROM activity_log WHERE id = pg_temp.i('share_log2')) = '{"tool":"availability_index","consumer":null,"count":0,"request_id":null}'::jsonb, '…an unnamed consumer and no request id are null, the keys still there');
SELECT pg_temp.check(has_function_privilege('inventory_records_ro', 'inv_log_share_read(text,text,integer,text)', 'EXECUTE') AND has_function_privilege('inventory_rw', 'inv_log_share_read(text,text,integer,text)', 'EXECUTE') AND NOT has_function_privilege('inventory_activity_ro', 'inv_log_share_read(text,text,integer,text)', 'EXECUTE'), 'the logger runs for the records role and the writer, not the activity role (PUBLIC revoked)');
RESET ROLE; SET ROLE inventory_activity_ro;
SELECT pg_temp.as_member(NULL);
SELECT pg_temp.refused($$SELECT inv_log_share_read('sales_closed', 'gl', 1)$$, 'permission denied', '…and the activity role is refused when it tries');
RESET ROLE;

-- ================================================================ the one that proves the proof: every statement above ran as a role, nothing committed
SET ROLE inventory_rw;
SELECT pg_temp.check((SELECT count(*) FROM members) = 11 AND (SELECT count(*) FROM inventory_transactions) > 15, 'the fixtures are there until the rollback');
RESET ROLE;

\o
\pset tuples_only on
SELECT CASE WHEN ok THEN ' ok   ' ELSE ' FAIL ' END || label FROM proof ORDER BY n;
SELECT ' ' || count(*) FILTER (WHERE ok) || ' ok, ' || count(*) FILTER (WHERE NOT ok) || ' failed' FROM proof;
ROLLBACK;

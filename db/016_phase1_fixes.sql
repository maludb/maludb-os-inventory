-- 016: WHAT PHASE 1 FOUND — additive fixes to db/005, db/009 and the two functions the tool surface owes (docs/inventory-mcp-tool-surface.md
-- "Owed to the schema"; docs/build-specs/connectors.md §2.8, §6.2, §6.3, §6.7). A migration is never modified; this one adds.
--
--  1. inv_source_pull_finish(): the HTTP client's facts() give `robots` as a MAP (host → {state, crawl_delay}); the SQL read a scalar and
--     would have copied the map's text into sources.robots_state (a CHECK violation). Now a scalar OR a map: from a map, blocked if any
--     host is blocked, ok if any host is ok and none blocked, else unknown; a map with no host, or a word outside ok/blocked/unknown,
--     leaves robots_state as it was (as an omitted key does).
--  2. inv_mark_removed(): D8 says a listing is removed after TWO FULL pulls without it. The window counted `partial` pulls (which did
--     not reach every listing) — now it is the current pull (still running, the connector's status ok by the worker's contract) plus
--     the last pulls whose status is `ok`; partial, failed and blocked pulls never count.
--  3. inv_settings.crawl_max_pages: the shipped default of 100 was Shopify's arithmetic (100 × 250 products); it is the page cap every
--     connector maps onto its own limit (jsonld reads one page per product) — raised to 500.
--  4. source_templates: the seeds used documentary keys the connectors do not read; rewritten to the connectors' keys (`sitemap` →
--     `sitemap_url`, `mapping.map` → `mapping.map_price`; `endpoint`, `limit`, `per_page`, `daily`, `transport` dropped), every other
--     field kept, so "Add from a template" needs no renaming.
--  5. The five K7 shares as their own functions: the kernel's token reaches them as inventory_records_ro with NO app.member_id, so they
--     call no inv_require_reader(); cost is UNNULLED in the two ledger shares and the valuation (that is the point: to the General
--     Ledger, over a connection a super-admin approved); each answers a versioned, people-free document. People never reach them:
--     the kit's SEARCH_DENY (mcp/db.py, Phase 4) refuses `inv_share_` to records_search and KERNEL_TOOLS keeps the tool names for the
--     kernel's token; a person's tool of the same name goes through the gated read instead.
--  6. inv_fulfilment_today() and inv_sales_summary(): the two functions a screen and a tool share, gated by inv_require_reader(), the
--     summary's cogs and margin nulled through inv_sees_cost() so Sales sees retail (§7 O7).
BEGIN;

-- ---------------------------------------------------------------------------------------------
-- 1. The robots verdict from a pull's policy: a scalar (the glue's) or the client's map.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION inv_robots_verdict(p_policy jsonb) RETURNS text
    LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE jsonb_typeof(p_policy->'robots')
             WHEN 'string' THEN CASE WHEN p_policy->>'robots' IN ('ok', 'blocked', 'unknown') THEN p_policy->>'robots' END
             WHEN 'object' THEN
                 CASE WHEN EXISTS (SELECT 1 FROM jsonb_each(p_policy->'robots') h WHERE h.value->>'state' = 'blocked') THEN 'blocked'
                      WHEN EXISTS (SELECT 1 FROM jsonb_each(p_policy->'robots') h WHERE h.value->>'state' = 'ok') THEN 'ok'
                      WHEN EXISTS (SELECT 1 FROM jsonb_each(p_policy->'robots')) THEN 'unknown'
                 END
           END;
$$;

CREATE OR REPLACE FUNCTION inv_source_pull_finish(p_pull_id bigint, p_status text, p_error text DEFAULT NULL, p_policy jsonb DEFAULT NULL,
                                                  p_http_requests integer DEFAULT NULL, p_bytes bigint DEFAULT NULL) RETURNS sources
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE p source_pulls%ROWTYPE; s sources%ROWTYPE; ladder integer[]; n integer; verdict text := inv_robots_verdict(p_policy);
BEGIN
    IF p_status NOT IN ('ok', 'partial', 'failed', 'blocked') THEN RAISE EXCEPTION 'A pull finishes ok, partial, failed or blocked' USING ERRCODE = 'check_violation'; END IF;
    UPDATE source_pulls SET status = p_status, finished_at = now(), error = left(p_error, 200), policy = COALESCE(p_policy, policy),
                            http_requests = COALESCE(p_http_requests, http_requests), bytes = COALESCE(p_bytes, bytes)
     WHERE id = p_pull_id RETURNING * INTO p;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such pull' USING ERRCODE = 'no_data_found'; END IF;
    SELECT * INTO s FROM sources WHERE id = p.source_id FOR UPDATE;
    IF p_status IN ('ok', 'partial') THEN
        UPDATE sources SET last_ok_at = now(), consecutive_failures = 0, backoff_until = NULL,
                           robots_state = COALESCE(verdict, robots_state),
                           robots_checked_at = CASE WHEN verdict IS NOT NULL THEN now() ELSE robots_checked_at END
         WHERE id = s.id RETURNING * INTO s;
    ELSE
        ladder := (SELECT crawl_backoff_minutes FROM inv_settings WHERE id = 1);
        n := s.consecutive_failures + 1;
        IF n <= cardinality(ladder) THEN
            UPDATE sources SET consecutive_failures = n, backoff_until = now() + make_interval(mins => ladder[n]),
                               robots_state = CASE WHEN p_status = 'blocked' THEN 'blocked' ELSE COALESCE(verdict, robots_state) END,
                               robots_checked_at = CASE WHEN p_status = 'blocked' OR verdict IS NOT NULL THEN now() ELSE robots_checked_at END
             WHERE id = s.id RETURNING * INTO s;
        ELSE
            UPDATE sources SET consecutive_failures = n, backoff_until = NULL, paused_at = now(),
                               paused_reason = format('%s failures in a row (last: %s)', n, COALESCE(left(p_error, 120), p_status)),
                               robots_state = CASE WHEN p_status = 'blocked' THEN 'blocked' ELSE COALESCE(verdict, robots_state) END
             WHERE id = s.id RETURNING * INTO s;
        END IF;
    END IF;
    RETURN s;
END$$;

-- ---------------------------------------------------------------------------------------------
-- 2. Removal after two FULL pulls (D8): the window is the current pull plus the last `ok` pulls of kind scheduled/manual.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION inv_mark_removed(p_source_id bigint, p_pull_id bigint DEFAULT NULL) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer := inv_setting_int('removed_after_pulls'); threshold timestamptz; r record; k integer := 0;
BEGIN
    SELECT started_at INTO threshold FROM source_pulls
     WHERE source_id = p_source_id AND kind IN ('scheduled', 'manual')
       AND (status = 'ok' OR (p_pull_id IS NOT NULL AND id = p_pull_id))        -- a partial, failed or blocked pull did not see everything: it never counts
     ORDER BY started_at DESC OFFSET n - 1 LIMIT 1;
    IF threshold IS NULL THEN RETURN 0; END IF;
    FOR r IN SELECT id FROM listings WHERE source_id = p_source_id AND removed_at IS NULL AND last_seen_at < threshold LOOP
        UPDATE listings SET removed_at = now() WHERE id = r.id;
        UPDATE listing_variants SET removed_at = now(), availability = 'unknown', qty = NULL WHERE listing_id = r.id AND removed_at IS NULL;
        PERFORM inv_record_offer(lv.id, p_pull_id) FROM listing_variants lv WHERE lv.listing_id = r.id;
        k := k + 1;
    END LOOP;
    IF p_pull_id IS NOT NULL THEN UPDATE source_pulls SET listings_removed = listings_removed + k WHERE id = p_pull_id; END IF;
    RETURN k;
END$$;

-- ---------------------------------------------------------------------------------------------
-- 3. The page cap every connector maps onto its own limit (connectors.md §6.2): shopify × 250 products, woocommerce × 100, jsonld
--    one page per product — 100 was low for a site read page by page.
-- ---------------------------------------------------------------------------------------------
ALTER TABLE inv_settings ALTER COLUMN crawl_max_pages SET DEFAULT 500;
UPDATE inv_settings SET crawl_max_pages = 500 WHERE id = 1 AND crawl_max_pages = 100;
COMMENT ON COLUMN inv_settings.crawl_max_pages IS 'The page cap per pull every connector maps onto its own limit: shopify max_products = × 250, woocommerce × 100, jsonld max_pages = this; a source''s own setting wins (connectors.md §6.2)';

-- ---------------------------------------------------------------------------------------------
-- 4. The templates' settings in the connectors' own keys (connectors.md §3): nothing else on the row changes.
-- ---------------------------------------------------------------------------------------------
UPDATE source_templates SET settings = settings - ARRAY['endpoint', 'limit', 'per_page', 'daily', 'transport']
 WHERE settings ?| ARRAY['endpoint', 'limit', 'per_page', 'daily', 'transport'];
UPDATE source_templates SET settings = (settings - 'sitemap'::text) || jsonb_build_object('sitemap_url', settings->'sitemap')
 WHERE settings ? 'sitemap' AND NOT settings ? 'sitemap_url';
UPDATE source_templates SET settings = jsonb_set(settings, '{mapping}', ((settings->'mapping') - 'map'::text) || jsonb_build_object('map_price', settings->'mapping'->'map'))
 WHERE jsonb_typeof(settings->'mapping') = 'object' AND settings->'mapping' ? 'map' AND NOT settings->'mapping' ? 'map_price';

-- ---------------------------------------------------------------------------------------------
-- 5. The shares (design §8, tool surface "The shares"). Each: no caller check, people-free, cost unnulled where the document says so.
--    jsonb orders its keys itself; the records server puts `schema` first when it serializes the document.
-- ---------------------------------------------------------------------------------------------
-- os.inventory-sales/1 — orders CLOSED in the period (closed_at by the business's timezone): the ledger's sales journal.
-- Paged over the orders; `totals` are the whole period's (every page ties to them); `cogs` = Σ qty × COALESCE(offer_cost, cost_price).
CREATE OR REPLACE FUNCTION inv_share_sales_closed(p_from date, p_to date, p_offset integer DEFAULT 0, p_limit integer DEFAULT 200) RETURNS jsonb
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE tz text := (SELECT timezone FROM inv_settings WHERE id = 1); lim integer := LEAST(GREATEST(COALESCE(p_limit, 200), 1), 500); off integer := GREATEST(COALESCE(p_offset, 0), 0);
        total bigint; page jsonb; totals jsonb;
BEGIN
    IF p_from IS NULL OR p_to IS NULL THEN RAISE EXCEPTION 'A period needs from and to' USING ERRCODE = 'check_violation'; END IF;
    IF p_to < p_from THEN RAISE EXCEPTION 'The period ends before it starts' USING ERRCODE = 'check_violation'; END IF;
    IF p_to - p_from > 92 THEN RAISE EXCEPTION 'A period is at most 92 days' USING ERRCODE = 'check_violation'; END IF;
    WITH o AS (
        SELECT so.id, so.closed_at, so.subtotal, so.discount_total, so.tax_total, so.shipping_charge, so.total, so.amount_paid,
               row_number() OVER (ORDER BY so.closed_at, so.id) AS rn,
               (SELECT COALESCE(sum(l.qty * COALESCE(l.offer_cost, v.cost_price, 0)), 0) FROM sales_order_lines l JOIN product_variants v ON v.id = l.variant_id
                 WHERE l.sales_order_id = so.id AND l.status <> 'cancelled') AS cogs,
               (SELECT COALESCE(sum(pm.amount), 0) FROM order_payments pm WHERE pm.sales_order_id = so.id AND pm.kind = 'refund') AS refunds,
               jsonb_build_object(
                   'sales_order_id', so.id, 'number', so.number, 'ordered_on', so.ordered_on, 'closed_at', so.closed_at,
                   'customer', jsonb_build_object('customer_id', c.id, 'name', c.name, 'income_account_id', c.income_account_id, 'tax_id', c.tax_id, 'ledger_ref', NULL),
                   'location', CASE WHEN loc.id IS NULL THEN NULL ELSE jsonb_build_object('location_id', loc.id, 'name', loc.name) END,
                   'delivery_method', so.delivery_method,
                   'lines', (SELECT COALESCE(jsonb_agg(jsonb_build_object('line_no', l.line_no, 'sku', v.sku, 'product_name', p.name, 'size', inv_size_name(v.size_key), 'qty', l.qty,
                                                                          'unit_price', l.unit_price, 'discount', l.discount, 'line_total', l.line_total,
                                                                          'fulfilment_kind', l.fulfilment_kind, 'qty_returned', l.qty_returned) ORDER BY l.line_no), '[]'::jsonb)
                               FROM sales_order_lines l JOIN product_variants v ON v.id = l.variant_id JOIN products p ON p.id = v.product_id
                              WHERE l.sales_order_id = so.id AND l.status <> 'cancelled'),
                   'subtotal', so.subtotal, 'discount_total', so.discount_total,
                   'tax', jsonb_build_object('tax_rate_id', t.id, 'name', t.name, 'rate', COALESCE(t.rate, 0), 'amount', so.tax_total),
                   'shipping_charge', so.shipping_charge, 'total', so.total,
                   'payments', (SELECT COALESCE(jsonb_agg(jsonb_build_object('kind', pm.kind, 'method', pm.method, 'amount', pm.amount, 'taken_at', (pm.taken_at AT TIME ZONE tz)::date,
                                                                             'reference', pm.reference) ORDER BY pm.taken_at, pm.id), '[]'::jsonb)
                                  FROM order_payments pm WHERE pm.sales_order_id = so.id),
                   'payments_by_method', (SELECT COALESCE(jsonb_object_agg(m.method, m.amt), '{}'::jsonb)
                                            FROM (SELECT pm.method, sum(pm.amount) AS amt FROM order_payments pm WHERE pm.sales_order_id = so.id AND pm.kind IN ('deposit', 'balance') GROUP BY pm.method) m),
                   'refunds', (SELECT COALESCE(sum(pm.amount), 0) FROM order_payments pm WHERE pm.sales_order_id = so.id AND pm.kind = 'refund'),
                   'amount_paid', so.amount_paid, 'balance_due', so.total - so.amount_paid,
                   'cogs', (SELECT COALESCE(sum(l.qty * COALESCE(l.offer_cost, v.cost_price, 0)), 0) FROM sales_order_lines l JOIN product_variants v ON v.id = l.variant_id
                             WHERE l.sales_order_id = so.id AND l.status <> 'cancelled'),
                   'returns', (SELECT COALESCE(jsonb_agg(jsonb_build_object('return_id', ra.id, 'number', ra.number, 'refund_amount', ra.refund_amount, 'closed_at', ra.closed_at) ORDER BY ra.id), '[]'::jsonb)
                                 FROM return_authorizations ra WHERE ra.sales_order_id = so.id AND ra.status <> 'denied')
               ) AS doc
          FROM sales_orders so JOIN customers c ON c.id = so.customer_id LEFT JOIN locations loc ON loc.id = so.location_id LEFT JOIN tax_rates t ON t.id = so.tax_rate_id
         WHERE so.status = 'closed' AND so.closed_at IS NOT NULL AND (so.closed_at AT TIME ZONE tz)::date BETWEEN p_from AND p_to
    )
    SELECT count(*), COALESCE(jsonb_agg(o.doc ORDER BY o.rn) FILTER (WHERE o.rn > off AND o.rn <= off + lim), '[]'::jsonb),
           jsonb_build_object('subtotal', COALESCE(sum(o.subtotal), 0), 'discount_total', COALESCE(sum(o.discount_total), 0), 'tax', COALESCE(sum(o.tax_total), 0),
                              'shipping_charge', COALESCE(sum(o.shipping_charge), 0), 'total', COALESCE(sum(o.total), 0),
                              'payments_by_method', (SELECT COALESCE(jsonb_object_agg(m.method, m.amt), '{}'::jsonb)
                                                       FROM (SELECT pm.method, sum(pm.amount) AS amt FROM order_payments pm WHERE pm.kind IN ('deposit', 'balance') AND pm.sales_order_id IN (SELECT id FROM o) GROUP BY pm.method) m),
                              'refunds', COALESCE(sum(o.refunds), 0), 'cogs', COALESCE(sum(o.cogs), 0))
      INTO total, page, totals FROM o;
    RETURN jsonb_build_object('schema', 'os.inventory-sales/1', 'generated_at', now(), 'application', 'inventory', 'currency', inv_currency(),
                              'period', jsonb_build_object('from', p_from, 'to', p_to), 'count', total, 'offset', off, 'limit', lim, 'truncated', total > off + lim,
                              'orders', page, 'totals', totals);
END$$;

-- os.inventory-purchases/1 — goods receipts POSTED in the period and drop-ship lines DELIVERED to the customer in it (the customer
-- line's shipment), at cost, grouped by supplier: the ledger's bills. Paged over the documents (receipts and drop-ship orders, by
-- their date), the page grouped by supplier; `totals` are the whole period's. A drop-ship's shipping cost is carried once, in the
-- period its first line was delivered. Never a ship-to: the sales order number ties the bill to the sale.
CREATE OR REPLACE FUNCTION inv_share_purchases_received(p_from date, p_to date, p_offset integer DEFAULT 0, p_limit integer DEFAULT 200) RETURNS jsonb
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE tz text := (SELECT timezone FROM inv_settings WHERE id = 1); lim integer := LEAST(GREATEST(COALESCE(p_limit, 200), 1), 500); off integer := GREATEST(COALESCE(p_offset, 0), 0);
        total bigint; page jsonb; totals jsonb;
BEGIN
    IF p_from IS NULL OR p_to IS NULL THEN RAISE EXCEPTION 'A period needs from and to' USING ERRCODE = 'check_violation'; END IF;
    IF p_to < p_from THEN RAISE EXCEPTION 'The period ends before it starts' USING ERRCODE = 'check_violation'; END IF;
    IF p_to - p_from > 92 THEN RAISE EXCEPTION 'A period is at most 92 days' USING ERRCODE = 'check_violation'; END IF;
    WITH dl AS (   -- drop-ship lines received, with the moment the customer got them
        SELECT pl.*, po.supplier_id AS po_supplier_id, po.number AS po_number, po.sales_order_id, po.supplier_order_ref AS po_ref, po.sent_at AS po_sent_at, po.shipping_cost AS po_shipping_cost,
               COALESCE((SELECT max(sh.delivered_at) FROM shipment_lines sl JOIN shipments sh ON sh.id = sl.shipment_id
                          WHERE sl.sales_order_line_id = pl.sales_order_line_id AND sh.delivered_at IS NOT NULL), pl.shipped_at, pl.updated_at) AS delivered_at
          FROM purchase_order_lines pl JOIN purchase_orders po ON po.id = pl.purchase_order_id
         WHERE po.kind = 'dropship' AND pl.status = 'received'
    ), docs AS (
        SELECT 'receipt'::text AS kind, gr.id AS doc_id, COALESCE(gr.supplier_id, po.supplier_id) AS supplier_id, gr.posted_at AS at,
               (SELECT COALESCE(sum(l.qty * COALESCE(l.unit_cost, 0)), 0) FROM goods_receipt_lines l WHERE l.goods_receipt_id = gr.id) AS amount,
               jsonb_build_object('goods_receipt_id', gr.id, 'number', gr.number, 'purchase_order_id', po.id, 'purchase_order_number', po.number, 'supplier_order_ref', po.supplier_order_ref,
                                  'received_on', gr.received_on, 'posted_at', gr.posted_at, 'location', jsonb_build_object('location_id', loc.id, 'name', loc.name), 'delivery_note_ref', gr.delivery_note_ref,
                                  'lines', (SELECT COALESCE(jsonb_agg(jsonb_build_object('line_no', l.line_no, 'sku', v.sku,
                                                                                         'supplier_sku', COALESCE(pl.supplier_sku, (SELECT si.supplier_sku FROM supplier_items si WHERE si.supplier_id = COALESCE(gr.supplier_id, po.supplier_id) AND si.variant_id = v.id)),
                                                                                         'product_name', p.name, 'size', inv_size_name(v.size_key), 'qty', l.qty, 'unit_cost', l.unit_cost,
                                                                                         'line_cost', l.qty * COALESCE(l.unit_cost, 0), 'discrepancy_kind', NULLIF(l.discrepancy_kind, 'none')) ORDER BY l.line_no), '[]'::jsonb)
                                              FROM goods_receipt_lines l JOIN product_variants v ON v.id = l.variant_id JOIN products p ON p.id = v.product_id
                                              LEFT JOIN purchase_order_lines pl ON pl.id = l.purchase_order_line_id
                                             WHERE l.goods_receipt_id = gr.id),
                                  'subtotal', (SELECT COALESCE(sum(l.qty * COALESCE(l.unit_cost, 0)), 0) FROM goods_receipt_lines l WHERE l.goods_receipt_id = gr.id)) AS doc
          FROM goods_receipts gr JOIN locations loc ON loc.id = gr.location_id LEFT JOIN purchase_orders po ON po.id = gr.purchase_order_id
         WHERE gr.status = 'posted' AND gr.posted_at IS NOT NULL AND (gr.posted_at AT TIME ZONE tz)::date BETWEEN p_from AND p_to
        UNION ALL
        SELECT 'dropship', g.purchase_order_id, g.po_supplier_id, g.first_at,
               g.subtotal + CASE WHEN g.first_ever BETWEEN p_from AND p_to THEN g.po_shipping_cost ELSE 0 END,
               jsonb_build_object('purchase_order_id', g.purchase_order_id, 'number', g.po_number, 'kind', 'dropship',
                                  'sales_order_number', (SELECT so.number FROM sales_orders so WHERE so.id = g.sales_order_id), 'supplier_order_ref', g.po_ref, 'sent_at', g.po_sent_at,
                                  'lines', g.lines, 'shipping_cost', CASE WHEN g.first_ever BETWEEN p_from AND p_to THEN g.po_shipping_cost ELSE 0 END,
                                  'subtotal', g.subtotal, 'total', g.subtotal + CASE WHEN g.first_ever BETWEEN p_from AND p_to THEN g.po_shipping_cost ELSE 0 END)
          FROM (SELECT d.purchase_order_id, d.po_supplier_id, d.po_number, d.sales_order_id, d.po_ref, d.po_sent_at, d.po_shipping_cost,
                       min(d.delivered_at) AS first_at, sum(d.qty_ordered * d.unit_cost) AS subtotal,
                       (SELECT (min(x.delivered_at) AT TIME ZONE tz)::date FROM dl x WHERE x.purchase_order_id = d.purchase_order_id) AS first_ever,
                       jsonb_agg(jsonb_build_object('line_no', d.line_no, 'sku', (SELECT v.sku FROM product_variants v WHERE v.id = d.variant_id), 'supplier_sku', d.supplier_sku,
                                                    'qty', d.qty_ordered, 'unit_cost', d.unit_cost, 'line_cost', d.qty_ordered * d.unit_cost, 'shipped_at', d.shipped_at, 'delivered_at', d.delivered_at) ORDER BY d.line_no) AS lines
                  FROM dl d WHERE (d.delivered_at AT TIME ZONE tz)::date BETWEEN p_from AND p_to
                 GROUP BY d.purchase_order_id, d.po_supplier_id, d.po_number, d.sales_order_id, d.po_ref, d.po_sent_at, d.po_shipping_cost) g
    ), ranked AS (
        SELECT d.*, row_number() OVER (ORDER BY d.at, d.kind, d.doc_id) AS rn FROM docs d
    )
    SELECT (SELECT count(*) FROM ranked),
           (SELECT COALESCE(jsonb_agg(sup ORDER BY name), '[]'::jsonb) FROM (
                SELECT COALESCE(s.name, '(no supplier)') AS name,
                       jsonb_build_object('supplier_id', s.id, 'name', COALESCE(s.name, '(no supplier)'), 'account_number', s.account_number, 'terms', s.terms,
                                          'receipts', COALESCE(jsonb_agg(r.doc ORDER BY r.rn) FILTER (WHERE r.kind = 'receipt'), '[]'::jsonb),
                                          'dropships', COALESCE(jsonb_agg(r.doc ORDER BY r.rn) FILTER (WHERE r.kind = 'dropship'), '[]'::jsonb),
                                          'total', sum(r.amount)) AS sup
                  FROM ranked r LEFT JOIN suppliers s ON s.id = r.supplier_id
                 WHERE r.rn > off AND r.rn <= off + lim
                 GROUP BY s.id, s.name, s.account_number, s.terms) x),
           (SELECT jsonb_build_object('receipts', COALESCE(sum(amount) FILTER (WHERE kind = 'receipt'), 0), 'dropships', COALESCE(sum(amount) FILTER (WHERE kind = 'dropship'), 0),
                                      'total', COALESCE(sum(amount), 0)) FROM ranked)
      INTO total, page, totals;
    RETURN jsonb_build_object('schema', 'os.inventory-purchases/1', 'generated_at', now(), 'application', 'inventory', 'currency', inv_currency(),
                              'period', jsonb_build_object('from', p_from, 'to', p_to), 'count', total, 'offset', off, 'limit', lim, 'truncated', total > off + lim,
                              'suppliers', page, 'totals', totals);
END$$;

-- os.inventory-valuation/1 — stock at the variant's standard cost at the END of a day (the business's timezone), by location or brand.
CREATE OR REPLACE FUNCTION inv_share_stock_valuation(p_as_of date, p_by text DEFAULT 'location') RETURNS jsonb
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE tz text := (SELECT timezone FROM inv_settings WHERE id = 1); cut timestamptz; grp text := COALESCE(p_by, 'location'); rows_ jsonb; tot jsonb;
BEGIN
    IF p_as_of IS NULL THEN RAISE EXCEPTION 'A valuation needs as_of' USING ERRCODE = 'check_violation'; END IF;
    IF grp NOT IN ('location', 'brand') THEN RAISE EXCEPTION 'Group by location or brand' USING ERRCODE = 'check_violation'; END IF;
    cut := ((p_as_of + 1)::timestamp) AT TIME ZONE tz;
    WITH q AS (
        SELECT t.variant_id, t.location_id, sum(t.qty) AS units
          FROM inventory_transactions t WHERE t.affects = 'on_hand' AND t.occurred_at < cut
         GROUP BY t.variant_id, t.location_id HAVING sum(t.qty) <> 0
    ), g AS (
        SELECT CASE grp WHEN 'location' THEN loc.id ELSE b.id END AS group_id,
               CASE grp WHEN 'location' THEN loc.name ELSE COALESCE(b.name, '(no brand)') END AS group_name,
               sum(q.units)::bigint AS units, sum(q.units * COALESCE(v.cost_price, 0)) AS value, count(DISTINCT v.id) AS variants
          FROM q JOIN product_variants v ON v.id = q.variant_id JOIN products p ON p.id = v.product_id LEFT JOIN brands b ON b.id = p.brand_id JOIN locations loc ON loc.id = q.location_id
         GROUP BY 1, 2
    )
    SELECT COALESCE(jsonb_agg(jsonb_build_object('group_id', g.group_id, 'group_name', g.group_name, 'units', g.units, 'value', g.value, 'variants', g.variants) ORDER BY g.group_name), '[]'::jsonb),
           jsonb_build_object('units', COALESCE(sum(g.units), 0), 'value', COALESCE(sum(g.value), 0))
      INTO rows_, tot FROM g;
    RETURN jsonb_build_object('schema', 'os.inventory-valuation/1', 'generated_at', now(), 'application', 'inventory', 'currency', inv_currency(),
                              'as_of', p_as_of, 'by', grp, 'rows', rows_, 'totals', tot,
                              'cost_basis', 'standard (product_variants.cost_price; cost_source = ' || (SELECT cost_source FROM inv_settings WHERE id = 1) || ')');
END$$;

-- os.inventory-availability/1 — the feed's loop without a key: our retail, the three states, the best lead time, how it ships.
-- No quantity, no partner price, no cost, no source's name. `p_q` = {"gtin"} or {"sku"} (exact) or {"q", "size"} (inv_find's scoring,
-- repeated here because inv_find demands a reader and the kernel's token has none).
CREATE OR REPLACE FUNCTION inv_share_availability_index(p_q jsonb, p_limit integer DEFAULT 25) RETURNS jsonb
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE lim integer := LEAST(GREATEST(COALESCE(p_limit, 25), 1), 100); q jsonb := COALESCE(p_q, '{}'::jsonb); term text; g text; sk text; r record; items jsonb := '[]'::jsonb; own integer; lead integer;
BEGIN
    term := NULLIF(btrim(COALESCE(q->>'q', '')), '');
    g := inv_gtin14(q->>'gtin');
    sk := inv_size_key(q->>'size');
    IF q ? 'gtin' OR q ? 'sku' OR term IS NOT NULL THEN
        FOR r IN
            SELECT v.id, v.sku, v.barcode, v.size_key, v.retail_price, v.map_price, COALESCE(v.ships_how, p.ships_how) AS ships_how, p.name AS pname, b.name AS bname, pt.name AS tname, sc.score
              FROM product_variants v JOIN products p ON p.id = v.product_id LEFT JOIN brands b ON b.id = p.brand_id JOIN product_types pt ON pt.id = p.product_type_id
              CROSS JOIN LATERAL (
                  SELECT (CASE WHEN q ? 'gtin' THEN CASE WHEN g IS NOT NULL AND (v.barcode = g OR EXISTS (SELECT 1 FROM variant_identifiers i WHERE i.variant_id = v.id AND i.kind IN ('gtin', 'upc', 'ean') AND i.value = g)) THEN 10.0 ELSE 0 END
                               WHEN q ? 'sku' THEN CASE WHEN lower(v.sku) = lower(q->>'sku') THEN 9.0 ELSE 0 END
                               WHEN lower(v.sku) = lower(term) OR lower(v.mpn) = lower(term) OR EXISTS (SELECT 1 FROM variant_identifiers i WHERE i.variant_id = v.id AND lower(i.value) = lower(term)) THEN 9.0
                               WHEN lower(v.sku) LIKE lower(term) || '%' THEN 6.0
                               ELSE GREATEST(similarity(p.name, term), similarity(COALESCE(b.name, '') || ' ' || p.name, term), word_similarity(term, p.name), CASE WHEN p.name ILIKE '%' || term || '%' THEN 0.6 ELSE 0 END) END)::real AS score) sc
             WHERE v.active AND p.status = 'active' AND sc.score >= 0.25 AND (sk IS NULL OR v.size_key = sk)
             ORDER BY sc.score DESC, p.name, v.sku LIMIT lim
        LOOP
            SELECT COALESCE(sum(o.qty_available) FILTER (WHERE o.is_sellable), 0) INTO own FROM inv_own_stock(r.id) o;
            lead := inv_best_lead_time(r.id);
            items := items || jsonb_build_object('variant_id', r.id, 'sku', r.sku, 'gtin', r.barcode, 'name', COALESCE(r.bname || ' ', '') || r.pname, 'brand', r.bname, 'product_type', r.tname,
                                                 'size', inv_size_name(r.size_key), 'retail_price', r.retail_price, 'map_price', r.map_price,
                                                 'availability', CASE WHEN own > 0 THEN 'in_stock' WHEN lead IS NOT NULL THEN 'back_order' ELSE 'out_of_stock' END,
                                                 'lead_time_days', lead, 'ships_how', r.ships_how, 'as_of', now());
        END LOOP;
    END IF;
    RETURN jsonb_build_object('schema', 'os.inventory-availability/1', 'generated_at', now(), 'application', 'inventory', 'currency', inv_currency(),
                              'query', jsonb_strip_nulls(jsonb_build_object('gtin', q->>'gtin', 'sku', q->>'sku', 'q', left(term, 120), 'size', q->>'size')),
                              'count', jsonb_array_length(items), 'rows', items);
END$$;

-- os.inventory-orders/1 — a customer's orders by their email (exact, case-insensitive): a support ticket's view. No address, no phone,
-- no cost, no source, no supplier's name, no salesperson. `found` says whether we know them at all. The order page's token is never
-- here — only whether a live link exists.
CREATE OR REPLACE FUNCTION inv_share_customer_orders(p_email citext, p_open_only boolean DEFAULT true, p_limit integer DEFAULT 25) RETURNS jsonb
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE lim integer := LEAST(GREATEST(COALESCE(p_limit, 25), 1), 50); open_only boolean := COALESCE(p_open_only, true); c customers%ROWTYPE; orders jsonb;
BEGIN
    IF p_email IS NULL OR btrim(p_email::text) = '' THEN RAISE EXCEPTION 'An email is needed' USING ERRCODE = 'check_violation'; END IF;
    SELECT * INTO c FROM customers WHERE email = btrim(p_email::text)::citext ORDER BY (archived_at IS NULL) DESC, id LIMIT 1;
    IF NOT FOUND THEN
        RETURN jsonb_build_object('schema', 'os.inventory-orders/1', 'generated_at', now(), 'application', 'inventory', 'currency', inv_currency(),
                                  'email', p_email, 'found', false, 'customer', NULL, 'count', 0, 'orders', '[]'::jsonb);
    END IF;
    SELECT COALESCE(jsonb_agg(doc ORDER BY ordered_on DESC, id DESC), '[]'::jsonb) INTO orders FROM (
        SELECT so.id, so.ordered_on,
               jsonb_build_object('sales_order_id', so.id, 'number', so.number, 'status', so.status, 'ordered_on', so.ordered_on, 'promised_on', so.promised_on,
                                  'delivery_method', so.delivery_method,
                                  'is_late', so.promised_on IS NOT NULL AND so.promised_on < current_date AND so.status NOT IN ('delivered', 'closed', 'cancelled'),
                                  'total', so.total, 'payment_status', so.payment_status, 'balance_due', so.total - so.amount_paid,
                                  'lines', (SELECT COALESCE(jsonb_agg(jsonb_build_object('line_no', l.line_no, 'product_name', p.name, 'size', inv_size_name(v.size_key), 'qty', l.qty,
                                                                                         'fulfilment', CASE l.fulfilment_kind WHEN 'stock' THEN 'from stock' WHEN 'dropship' THEN 'ships from our supplier' WHEN 'backorder' THEN 'backordered' ELSE 'pickup' END,
                                                                                         'status', l.status,
                                                                                         'expected_on', CASE WHEN l.fulfilment_kind = 'dropship' THEN (SELECT pl.expected_on FROM purchase_order_lines pl WHERE pl.id = l.purchase_order_line_id) END,
                                                                                         'shipment', (SELECT jsonb_build_object('carrier', sh.carrier, 'tracking_number', sh.tracking_number, 'tracking_url', sh.tracking_url, 'shipped_at', sh.shipped_at, 'delivered_at', sh.delivered_at)
                                                                                                        FROM shipment_lines sl JOIN shipments sh ON sh.id = sl.shipment_id WHERE sl.sales_order_line_id = l.id ORDER BY sh.shipped_at DESC, sh.id DESC LIMIT 1)) ORDER BY l.line_no), '[]'::jsonb)
                                              FROM sales_order_lines l JOIN product_variants v ON v.id = l.variant_id JOIN products p ON p.id = v.product_id WHERE l.sales_order_id = so.id),
                                  'returns', (SELECT COALESCE(jsonb_agg(jsonb_build_object('number', ra.number, 'status', ra.status, 'scheduled_on', ra.scheduled_on) ORDER BY ra.id), '[]'::jsonb)
                                                FROM return_authorizations ra WHERE ra.sales_order_id = so.id),
                                  'order_page_live', EXISTS (SELECT 1 FROM order_links_secure k WHERE k.sales_order_id = so.id AND k.rotated_at IS NULL AND (k.expires_at IS NULL OR k.expires_at > now()))) AS doc
          FROM sales_orders so
         WHERE so.customer_id = c.id AND (NOT open_only OR so.status NOT IN ('closed', 'cancelled'))
         ORDER BY so.ordered_on DESC, so.id DESC LIMIT lim) x;
    RETURN jsonb_build_object('schema', 'os.inventory-orders/1', 'generated_at', now(), 'application', 'inventory', 'currency', inv_currency(),
                              'email', p_email, 'found', true, 'customer', jsonb_build_object('customer_id', c.id, 'name', c.name),
                              'count', jsonb_array_length(orders), 'orders', orders);
END$$;

-- ---------------------------------------------------------------------------------------------
-- 6. The two functions a screen and a tool share.
-- ---------------------------------------------------------------------------------------------
-- O5: what is to be picked, packed, delivered or collected on a day — the stock, pickup and backorder lines not yet shipped on
-- confirmed / in-fulfilment orders promised on or before that day (an order with no promised date is due now). By location, then
-- delivery method, order, line. Any reader (§7 says Warehouse — tool surface DECISION 5).
CREATE OR REPLACE FUNCTION inv_fulfilment_today(p_day date DEFAULT current_date, p_location_id bigint DEFAULT NULL)
    RETURNS TABLE (location_id bigint, location_name text, delivery_method text, sales_order_id bigint, order_number text, customer_name text, promised_on date, ship_to_city text,
                   line_id bigint, line_no integer, variant_id bigint, sku text, product_name text, size_name text, qty integer, qty_allocated integer, qty_shipped integer,
                   fulfilment_kind text, line_status text)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE d date := COALESCE(p_day, current_date);
BEGIN
    PERFORM inv_require_reader();
    RETURN QUERY
    SELECT l.location_id, loc.name, o.delivery_method, o.id, o.number, c.name, o.promised_on, o.ship_to_city,
           l.id, l.line_no, l.variant_id, v.sku, p.name, inv_size_name(v.size_key), l.qty, l.qty_allocated, l.qty_shipped, l.fulfilment_kind, l.status
      FROM sales_order_lines l JOIN sales_orders o ON o.id = l.sales_order_id JOIN customers c ON c.id = o.customer_id
      JOIN product_variants v ON v.id = l.variant_id JOIN products p ON p.id = v.product_id LEFT JOIN locations loc ON loc.id = l.location_id
     WHERE o.status IN ('confirmed', 'in_fulfilment') AND (o.promised_on IS NULL OR o.promised_on <= d)
       AND l.fulfilment_kind IN ('stock', 'pickup', 'backorder') AND l.status IN ('open', 'allocated') AND l.qty_shipped < l.qty
       AND (p_location_id IS NULL OR l.location_id = p_location_id)
     ORDER BY loc.name NULLS LAST, o.delivery_method, o.promised_on NULLS FIRST, o.number, l.line_no;
END$$;

-- O7: what we SOLD (confirmed orders by ordered_on — inv_sell_through is what shipped) over a period, by day, week, month, brand, type,
-- salesperson, variant or location, at retail; cogs and margin for a caller who sees cost, else null with cost_withheld. Any reader.
CREATE OR REPLACE FUNCTION inv_sales_summary(p_from date, p_to date, p_by text)
    RETURNS TABLE (group_id bigint, group_key text, group_name text, orders bigint, units_sold bigint, revenue numeric, discount numeric, tax numeric, cogs numeric, margin_pct numeric, cost_withheld boolean)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE sees boolean := inv_sees_cost(); grp text := COALESCE(p_by, 'brand');
BEGIN
    PERFORM inv_require_reader();
    IF p_from IS NULL OR p_to IS NULL THEN RAISE EXCEPTION 'A period needs from and to' USING ERRCODE = 'check_violation'; END IF;
    IF p_to < p_from THEN RAISE EXCEPTION 'The period ends before it starts' USING ERRCODE = 'check_violation'; END IF;
    IF grp NOT IN ('day', 'week', 'month', 'brand', 'type', 'salesperson', 'variant', 'location') THEN
        RAISE EXCEPTION 'Group by day, week, month, brand, type, salesperson, variant or location' USING ERRCODE = 'check_violation';
    END IF;
    RETURN QUERY
    WITH sold AS (
        SELECT o.id AS oid, o.ordered_on, o.salesperson_member_id, o.location_id, l.qty, l.line_total, l.discount,
               l.line_total * COALESCE(t.rate, 0) / 100 AS tax_each, l.qty * COALESCE(l.offer_cost, v.cost_price, 0) AS cost_line,
               v.id AS vid, v.sku, p.name AS pname, p.brand_id, p.product_type_id
          FROM sales_order_lines l JOIN sales_orders o ON o.id = l.sales_order_id JOIN product_variants v ON v.id = l.variant_id JOIN products p ON p.id = v.product_id
          LEFT JOIN tax_rates t ON t.id = o.tax_rate_id
         WHERE o.confirmed_at IS NOT NULL AND o.status NOT IN ('quote', 'cancelled') AND o.ordered_on BETWEEN p_from AND p_to AND l.status <> 'cancelled'
    ), keyed AS (
        SELECT s.*,
               CASE grp WHEN 'brand' THEN s.brand_id WHEN 'type' THEN s.product_type_id WHEN 'salesperson' THEN s.salesperson_member_id WHEN 'variant' THEN s.vid WHEN 'location' THEN s.location_id END AS gid,
               CASE grp WHEN 'day' THEN to_char(s.ordered_on, 'YYYY-MM-DD') WHEN 'week' THEN to_char(s.ordered_on, 'IYYY-"W"IW') WHEN 'month' THEN to_char(s.ordered_on, 'YYYY-MM')
                       WHEN 'brand' THEN s.brand_id::text WHEN 'type' THEN s.product_type_id::text WHEN 'salesperson' THEN s.salesperson_member_id::text WHEN 'variant' THEN s.sku ELSE s.location_id::text END AS gkey,
               CASE grp WHEN 'day' THEN to_char(s.ordered_on, 'YYYY-MM-DD') WHEN 'week' THEN 'Week of ' || to_char(date_trunc('week', s.ordered_on)::date, 'YYYY-MM-DD') WHEN 'month' THEN to_char(s.ordered_on, 'Mon YYYY')
                       WHEN 'brand' THEN COALESCE((SELECT b.name FROM brands b WHERE b.id = s.brand_id), '(no brand)')
                       WHEN 'type' THEN (SELECT pt.name FROM product_types pt WHERE pt.id = s.product_type_id)
                       WHEN 'salesperson' THEN COALESCE((SELECT m.display_name FROM members m WHERE m.id = s.salesperson_member_id), '(no salesperson)')
                       WHEN 'variant' THEN s.pname || ' ' || s.sku
                       ELSE COALESCE((SELECT loc.name FROM locations loc WHERE loc.id = s.location_id), '(no location)') END AS gname
          FROM sold s
    )
    SELECT k.gid, k.gkey, k.gname, count(DISTINCT k.oid), sum(k.qty), sum(k.line_total), sum(k.discount), round(sum(k.tax_each), 2),
           CASE WHEN sees THEN sum(k.cost_line) END,
           CASE WHEN sees AND sum(k.line_total) > 0 THEN round((sum(k.line_total) - sum(k.cost_line)) / sum(k.line_total) * 100, 1) END,
           NOT sees
      FROM keyed k
     GROUP BY k.gid, k.gkey, k.gname
     ORDER BY k.gkey, k.gname;
END$$;

-- ---------------------------------------------------------------------------------------------
-- Grants. The shares go to the read role for the kernel's token (and the writer, for the share.read log on a person's path);
-- the kit's SEARCH_DENY (`inv_share_`) and KERNEL_TOOLS keep people off them through the records server.
-- ---------------------------------------------------------------------------------------------
REVOKE ALL ON FUNCTION inv_robots_verdict(jsonb), inv_share_sales_closed(date, date, integer, integer), inv_share_purchases_received(date, date, integer, integer),
    inv_share_stock_valuation(date, text), inv_share_availability_index(jsonb, integer), inv_share_customer_orders(citext, boolean, integer),
    inv_fulfilment_today(date, bigint), inv_sales_summary(date, date, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION inv_robots_verdict(jsonb) TO inventory_rw;
GRANT EXECUTE ON FUNCTION inv_share_sales_closed(date, date, integer, integer), inv_share_purchases_received(date, date, integer, integer),
    inv_share_stock_valuation(date, text), inv_share_availability_index(jsonb, integer), inv_share_customer_orders(citext, boolean, integer),
    inv_fulfilment_today(date, bigint), inv_sales_summary(date, date, text) TO inventory_rw, inventory_records_ro;

COMMIT;

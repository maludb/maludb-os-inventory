-- 014: the READS — SQL functions so a screen, a tool, the feed and an export never disagree (design §6 "Views and
-- functions", §7). Every function tests the caller once (inv_require_reader / inv_require_right) and nulls cost through
-- inv_sees_cost() — cost is the wall (§3). SECURITY DEFINER so the read roles reach the base tables through these alone.
BEGIN;

CREATE OR REPLACE FUNCTION inv_require_reader() RETURNS void
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NOT inv_is_member_here() THEN RAISE EXCEPTION 'Not admitted to Inventory' USING ERRCODE = 'insufficient_privilege'; END IF;
END$$;

CREATE OR REPLACE FUNCTION inv_require_right(p_right text) RETURNS void
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NOT inv_has_right(p_right) THEN RAISE EXCEPTION 'This needs the right %', p_right USING ERRCODE = 'insufficient_privilege'; END IF;
END$$;

-- A size's display name from its key.
CREATE OR REPLACE FUNCTION inv_size_name(p_key text) RETURNS text
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT COALESCE((SELECT s->>'name' FROM inv_settings st, jsonb_array_elements(st.sizes) s WHERE st.id = 1 AND s->>'key' = p_key LIMIT 1), initcap(replace(p_key, '_', ' ')));
$$;

-- ---------------------------------------------------------------------------------------------
-- The offers for one variant, ranked: suppliers first (in stock first, then cost, then lead time), references by price.
-- INTERNAL: cost is not nulled here — every caller nulls it. `stale` = the source has not confirmed it within twice its
-- schedule (30 days for a manual source).
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION inv_offers_for_variant(p_variant_id bigint)
    RETURNS TABLE (listing_variant_id bigint, listing_id bigint, listing_title text, variant_title text, source_id bigint, source_name text, source_role text,
                   connector text, supplier_id bigint, supplier_name text, price numeric, compare_at_price numeric, currency char(3), cost numeric,
                   availability text, qty integer, lead_time_days integer, ships_how text, url text, as_of timestamptz, stale boolean, removed boolean, rank integer)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT lv.id, l.id, l.title, lv.title, s.id, s.name, s.role, s.connector, sp.id, sp.name,
           lv.price, lv.compare_at_price, lv.currency,
           CASE WHEN s.role = 'supplier' THEN COALESCE(lv.cost_price, si.cost, lv.price) END AS cost,
           lv.availability, lv.qty, COALESCE(lv.lead_time_days, si.lead_time_days, CASE WHEN s.role = 'supplier' THEN sp.lead_time_days END) AS lead_time_days,
           COALESCE(lv.ships_how, v.ships_how, p.ships_how) AS ships_how, COALESCE(lv.url, l.url) AS url,
           COALESCE((SELECT max(o.observed_at) FROM offer_snapshots o WHERE o.listing_variant_id = lv.id), lv.last_seen_at) AS as_of,
           (CASE WHEN s.schedule_minutes > 0 THEN lv.last_seen_at < now() - make_interval(mins => 2 * s.schedule_minutes) ELSE lv.last_seen_at < now() - interval '30 days' END) AS stale,
           lv.removed_at IS NOT NULL AS removed,
           (row_number() OVER (ORDER BY
                CASE WHEN s.role = 'supplier' THEN 0 ELSE 1 END,
                CASE WHEN lv.removed_at IS NOT NULL THEN 3 WHEN lv.availability IN ('in_stock', 'limited') THEN 0 WHEN lv.availability IN ('pre_order', 'back_order') THEN 1 ELSE 2 END,
                CASE WHEN s.role = 'supplier' THEN COALESCE(lv.cost_price, si.cost, lv.price) ELSE lv.price END ASC NULLS LAST,
                COALESCE(lv.lead_time_days, si.lead_time_days, sp.lead_time_days) ASC NULLS LAST, lv.id))::integer AS rank
      FROM listing_variants lv
      JOIN listings l ON l.id = lv.listing_id
      JOIN sources s ON s.id = l.source_id
      JOIN product_variants v ON v.id = lv.variant_id
      JOIN products p ON p.id = v.product_id
      LEFT JOIN suppliers sp ON sp.id = s.supplier_id
      LEFT JOIN supplier_items si ON si.supplier_id = s.supplier_id AND si.variant_id = lv.variant_id AND si.active
     WHERE lv.variant_id = p_variant_id AND s.active
     ORDER BY rank;
$$;

-- Own stock per location for one variant (sellable = on hand − allocated − floor).
CREATE OR REPLACE FUNCTION inv_own_stock(p_variant_id bigint)
    RETURNS TABLE (location_id bigint, location_name text, location_kind text, qty_on_hand integer, qty_allocated integer, qty_floor_model integer, qty_available integer, is_sellable boolean)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT b.location_id, loc.name, loc.kind, b.qty_on_hand, b.qty_allocated, b.qty_floor_model, b.qty_on_hand - b.qty_allocated - b.qty_floor_model, loc.is_sellable
      FROM inventory_balances b JOIN locations loc ON loc.id = b.location_id
     WHERE b.variant_id = p_variant_id AND loc.active AND (b.qty_on_hand <> 0 OR b.qty_allocated <> 0 OR b.qty_floor_model <> 0)
     ORDER BY loc.name;
$$;

-- The best lead time for a variant: 0 when sellable stock exists, else the best in-stock supplier offer's, else NULL.
CREATE OR REPLACE FUNCTION inv_best_lead_time(p_variant_id bigint) RETURNS integer
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT CASE WHEN EXISTS (SELECT 1 FROM inv_own_stock(p_variant_id) o WHERE o.is_sellable AND o.qty_available > 0) THEN 0
                ELSE (SELECT min(o.lead_time_days) FROM inv_offers_for_variant(p_variant_id) o WHERE o.source_role = 'supplier' AND NOT o.removed AND o.availability IN ('in_stock', 'limited')) END;
$$;

-- On order for a variant: open stock PO lines (ordered − received).
CREATE OR REPLACE FUNCTION inv_on_order(p_variant_id bigint) RETURNS integer
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT COALESCE(sum(pl.qty_ordered - pl.qty_received), 0)::integer FROM purchase_order_lines pl JOIN purchase_orders po ON po.id = pl.purchase_order_id
     WHERE pl.variant_id = p_variant_id AND po.kind = 'stock' AND po.status IN ('sent', 'acknowledged', 'partial') AND pl.status IN ('open', 'acknowledged', 'partial', 'shipped');
$$;

-- ---------------------------------------------------------------------------------------------
-- A1: inv_availability(variant) — the one answer: own stock by location, every offer ranked, our prices, the best lead time.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION inv_availability(p_variant_id bigint) RETURNS jsonb
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE v record; sees boolean := inv_sees_cost(); own jsonb; offers jsonb; refs jsonb; own_avail integer; best integer; state text;
BEGIN
    PERFORM inv_require_reader();
    SELECT pv.id, pv.sku, pv.size_key, pv.barcode, pv.mpn, pv.retail_price, pv.map_price, pv.cost_price, COALESCE(pv.ships_how, p.ships_how) AS ships_how,
           pv.option_values, pv.active, p.id AS product_id, p.name AS product_name, p.kind, p.status, b.name AS brand, pt.name AS product_type
      INTO v FROM product_variants pv JOIN products p ON p.id = pv.product_id LEFT JOIN brands b ON b.id = p.brand_id JOIN product_types pt ON pt.id = p.product_type_id
     WHERE pv.id = p_variant_id;
    IF NOT FOUND THEN RETURN NULL; END IF;
    IF v.kind = 'bundle' THEN RETURN inv_bundle_availability(p_variant_id); END IF;
    SELECT COALESCE(jsonb_agg(jsonb_build_object('location_id', o.location_id, 'location', o.location_name, 'kind', o.location_kind, 'on_hand', o.qty_on_hand,
                                                 'allocated', o.qty_allocated, 'floor_model', o.qty_floor_model, 'available', o.qty_available, 'sellable', o.is_sellable)), '[]'::jsonb),
           COALESCE(sum(o.qty_available) FILTER (WHERE o.is_sellable), 0)
      INTO own, own_avail FROM inv_own_stock(p_variant_id) o;
    SELECT COALESCE(jsonb_agg(jsonb_build_object('listing_variant_id', o.listing_variant_id, 'source_id', o.source_id, 'source', o.source_name, 'connector', o.connector,
                                                 'supplier_id', o.supplier_id, 'supplier', o.supplier_name, 'price', o.price, 'compare_at_price', o.compare_at_price,
                                                 'cost', CASE WHEN sees THEN o.cost END, 'cost_withheld', NOT sees, 'availability', o.availability, 'qty', o.qty,
                                                 'lead_time_days', o.lead_time_days, 'ships_how', o.ships_how, 'url', o.url, 'as_of', o.as_of, 'stale', o.stale, 'removed', o.removed, 'rank', o.rank)
                              ORDER BY o.rank), '[]'::jsonb)
      INTO offers FROM inv_offers_for_variant(p_variant_id) o WHERE o.source_role = 'supplier';
    SELECT COALESCE(jsonb_agg(jsonb_build_object('listing_variant_id', o.listing_variant_id, 'source_id', o.source_id, 'source', o.source_name, 'connector', o.connector,
                                                 'price', o.price, 'compare_at_price', o.compare_at_price, 'availability', o.availability, 'url', o.url, 'as_of', o.as_of,
                                                 'stale', o.stale, 'removed', o.removed, 'rank', o.rank) ORDER BY o.rank), '[]'::jsonb)
      INTO refs FROM inv_offers_for_variant(p_variant_id) o WHERE o.source_role = 'reference';
    best := inv_best_lead_time(p_variant_id);
    state := CASE WHEN own_avail > 0 THEN 'in_stock'
                  WHEN EXISTS (SELECT 1 FROM inv_offers_for_variant(p_variant_id) o WHERE o.source_role = 'supplier' AND NOT o.removed AND o.availability IN ('in_stock', 'limited')) THEN 'from_supplier'
                  WHEN EXISTS (SELECT 1 FROM inv_offers_for_variant(p_variant_id) o WHERE o.source_role = 'supplier' AND NOT o.removed AND o.availability IN ('pre_order', 'back_order')) THEN 'back_order'
                  ELSE 'unavailable' END;
    RETURN jsonb_build_object(
        'variant', jsonb_build_object('variant_id', v.id, 'product_id', v.product_id, 'product', v.product_name, 'brand', v.brand, 'product_type', v.product_type, 'sku', v.sku,
                                      'size_key', v.size_key, 'size', inv_size_name(v.size_key), 'barcode', v.barcode, 'mpn', v.mpn, 'ships_how', v.ships_how, 'active', v.active, 'status', v.status),
        'prices', jsonb_build_object('retail', v.retail_price, 'map', v.map_price, 'cost', CASE WHEN sees THEN v.cost_price END, 'cost_withheld', NOT sees,
                                     'margin_pct', CASE WHEN sees AND v.retail_price > 0 AND v.cost_price IS NOT NULL THEN round((v.retail_price - v.cost_price) / v.retail_price * 100, 1) END),
        'own', own, 'own_available', own_avail, 'on_order', inv_on_order(p_variant_id),
        'offers', offers, 'references', refs, 'best_lead_time_days', best, 'state', state, 'as_of', now());
END$$;

-- C4: a bundle's availability = the minimum over its components (each component's own sellable stock + the best supplier offer).
CREATE OR REPLACE FUNCTION inv_bundle_availability(p_variant_id bigint) RETURNS jsonb
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE comps jsonb; avail integer; lead integer; v record;
BEGIN
    PERFORM inv_require_reader();
    SELECT pv.id, pv.sku, pv.retail_price, pv.map_price, p.name AS product_name, p.id AS product_id INTO v FROM product_variants pv JOIN products p ON p.id = pv.product_id WHERE pv.id = p_variant_id AND p.kind = 'bundle';
    IF NOT FOUND THEN RETURN NULL; END IF;
    SELECT COALESCE(jsonb_agg(jsonb_build_object('variant_id', c.component_variant_id, 'sku', cv.sku, 'product', cp.name, 'qty', c.qty,
                                                 'own_available', COALESCE((SELECT sum(o.qty_available) FROM inv_own_stock(c.component_variant_id) o WHERE o.is_sellable), 0),
                                                 'sets_from_stock', COALESCE((SELECT sum(o.qty_available) FROM inv_own_stock(c.component_variant_id) o WHERE o.is_sellable), 0) / c.qty,
                                                 'best_lead_time_days', inv_best_lead_time(c.component_variant_id)) ORDER BY c.component_variant_id), '[]'::jsonb),
           min(COALESCE((SELECT sum(o.qty_available) FROM inv_own_stock(c.component_variant_id) o WHERE o.is_sellable), 0) / c.qty),
           max(inv_best_lead_time(c.component_variant_id))
      INTO comps, avail, lead
      FROM bundle_components c JOIN product_variants cv ON cv.id = c.component_variant_id JOIN products cp ON cp.id = cv.product_id
     WHERE c.bundle_variant_id = p_variant_id;
    -- a component nobody can supply makes the set unsuppliable: its lead time is NULL, so the set's is
    IF EXISTS (SELECT 1 FROM bundle_components c WHERE c.bundle_variant_id = p_variant_id AND inv_best_lead_time(c.component_variant_id) IS NULL) THEN lead := NULL; END IF;
    RETURN jsonb_build_object('variant', jsonb_build_object('variant_id', v.id, 'product_id', v.product_id, 'product', v.product_name, 'sku', v.sku, 'kind', 'bundle'),
                              'prices', jsonb_build_object('retail', v.retail_price, 'map', v.map_price),
                              'components', comps, 'sets_available', COALESCE(avail, 0), 'best_lead_time_days', lead,
                              'state', CASE WHEN COALESCE(avail, 0) > 0 THEN 'in_stock' WHEN lead IS NOT NULL THEN 'from_supplier' ELSE 'unavailable' END, 'as_of', now());
END$$;

-- A2: can we promise N by a date, and from where?
CREATE OR REPLACE FUNCTION inv_atp(p_variant_id bigint, p_qty integer DEFAULT 1, p_when date DEFAULT NULL) RETURNS jsonb
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE loc record; off record; sees boolean := inv_sees_cost();
BEGIN
    PERFORM inv_require_reader();
    IF p_qty IS NULL OR p_qty < 1 THEN RAISE EXCEPTION 'A promise is for at least one' USING ERRCODE = 'check_violation'; END IF;
    SELECT * INTO loc FROM inv_own_stock(p_variant_id) o WHERE o.is_sellable AND o.qty_available >= p_qty ORDER BY o.qty_available DESC LIMIT 1;
    IF FOUND THEN
        RETURN jsonb_build_object('can_promise', true, 'from', 'stock', 'location_id', loc.location_id, 'location', loc.location_name, 'qty_available', loc.qty_available,
                                  'by', current_date, 'lead_time_days', 0, 'reason', format('%s available at %s', loc.qty_available, loc.location_name));
    END IF;
    SELECT * INTO off FROM inv_offers_for_variant(p_variant_id) o
     WHERE o.source_role = 'supplier' AND NOT o.removed AND o.availability IN ('in_stock', 'limited') AND (o.qty IS NULL OR o.qty >= p_qty)
       AND (p_when IS NULL OR current_date + COALESCE(o.lead_time_days, 0) <= p_when)
     ORDER BY o.rank LIMIT 1;
    IF FOUND THEN
        RETURN jsonb_build_object('can_promise', true, 'from', 'source', 'source_id', off.source_id, 'source', off.source_name, 'supplier_id', off.supplier_id, 'supplier', off.supplier_name,
                                  'listing_variant_id', off.listing_variant_id, 'cost', CASE WHEN sees THEN off.cost END, 'cost_withheld', NOT sees, 'availability', off.availability,
                                  'by', current_date + COALESCE(off.lead_time_days, 0), 'lead_time_days', off.lead_time_days, 'ships_how', off.ships_how, 'stale', off.stale,
                                  'reason', format('%s can ship in %s days', off.supplier_name, COALESCE(off.lead_time_days::text, '?')));
    END IF;
    RETURN jsonb_build_object('can_promise', false, 'from', NULL, 'by', NULL,
                              'reason', CASE WHEN p_when IS NOT NULL AND EXISTS (SELECT 1 FROM inv_offers_for_variant(p_variant_id) o WHERE o.source_role = 'supplier' AND NOT o.removed AND o.availability IN ('in_stock', 'limited'))
                                             THEN 'a supplier has it, but not by ' || p_when ELSE 'not in stock and no supplier offers it' END);
END$$;

-- ---------------------------------------------------------------------------------------------
-- The one search: inv_find(q, size, filters). Filters: {"brand_id":…, "product_type_id":…, "in_stock_only":true,
-- "max_lead_days":…, "price_min":…, "price_max":…, "status":"active", "attributes":{"type":"hybrid"}}. Ranked by match.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION inv_find(p_q text DEFAULT NULL, p_size text DEFAULT NULL, p_filters jsonb DEFAULT '{}'::jsonb, p_limit integer DEFAULT 50)
    RETURNS TABLE (variant_id bigint, product_id bigint, product_name text, brand text, product_type text, kind text, sku text, size_key text, size_name text, barcode text, mpn text,
                   retail_price numeric, map_price numeric, cost_price numeric, cost_withheld boolean, ships_how text, own_available integer, own_on_hand integer, own_floor_model integer,
                   best_offer jsonb, best_lead_time_days integer, state text, score real)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE sees boolean := inv_sees_cost(); q text := NULLIF(btrim(COALESCE(p_q, '')), ''); g text; sk text := inv_size_key(p_size); f jsonb := COALESCE(p_filters, '{}'::jsonb);
BEGIN
    PERFORM inv_require_reader();
    g := inv_gtin14(q);
    RETURN QUERY
    WITH cand AS (
        SELECT pv.id AS vid, pv.product_id AS pid, p.name AS pname, b.name AS bname, pt.name AS tname, p.kind, pv.sku, pv.size_key, pv.barcode, pv.mpn,
               pv.retail_price, pv.map_price, pv.cost_price, COALESCE(pv.ships_how, p.ships_how) AS ships_how,
               (CASE WHEN q IS NULL THEN 1.0
                     WHEN g IS NOT NULL AND (pv.barcode = g OR EXISTS (SELECT 1 FROM variant_identifiers i WHERE i.variant_id = pv.id AND i.value = g)) THEN 10.0
                     WHEN lower(pv.sku) = lower(q) OR lower(pv.mpn) = lower(q) OR EXISTS (SELECT 1 FROM variant_identifiers i WHERE i.variant_id = pv.id AND lower(i.value) = lower(q)) THEN 9.0
                     WHEN lower(pv.sku) LIKE lower(q) || '%' THEN 6.0
                     ELSE GREATEST(similarity(p.name, q), similarity(COALESCE(b.name, '') || ' ' || p.name, q), word_similarity(q, p.name), CASE WHEN p.name ILIKE '%' || q || '%' THEN 0.6 ELSE 0 END) END)::real AS score
          FROM product_variants pv JOIN products p ON p.id = pv.product_id LEFT JOIN brands b ON b.id = p.brand_id JOIN product_types pt ON pt.id = p.product_type_id
         WHERE pv.active AND p.status <> 'draft'
           AND (sk IS NULL OR pv.size_key = sk)
           AND (NOT (f ? 'brand_id') OR p.brand_id = (f->>'brand_id')::bigint)
           AND (NOT (f ? 'product_type_id') OR p.product_type_id = (f->>'product_type_id')::bigint)
           AND (NOT (f ? 'status') OR p.status = f->>'status')
           AND (NOT (f ? 'price_min') OR pv.retail_price >= (f->>'price_min')::numeric)
           AND (NOT (f ? 'price_max') OR pv.retail_price <= (f->>'price_max')::numeric)
           AND (NOT (f ? 'attributes') OR p.attributes @> (f->'attributes'))
    ), scored AS (
        SELECT c.*, CASE WHEN c.kind = 'bundle' THEN COALESCE((inv_bundle_availability(c.vid)->>'sets_available')::integer, 0)
                         ELSE COALESCE((SELECT sum(o.qty_available) FROM inv_own_stock(c.vid) o WHERE o.is_sellable), 0)::integer END AS own_avail,
               COALESCE((SELECT sum(o.qty_on_hand) FROM inv_own_stock(c.vid) o), 0)::integer AS own_oh,
               COALESCE((SELECT sum(o.qty_floor_model) FROM inv_own_stock(c.vid) o), 0)::integer AS own_fm,
               (SELECT jsonb_build_object('listing_variant_id', o.listing_variant_id, 'source_id', o.source_id, 'source', o.source_name, 'supplier', o.supplier_name,
                                          'cost', CASE WHEN sees THEN o.cost END, 'price', o.price, 'availability', o.availability, 'lead_time_days', o.lead_time_days,
                                          'ships_how', o.ships_how, 'as_of', o.as_of, 'stale', o.stale)
                  FROM inv_offers_for_variant(c.vid) o WHERE o.source_role = 'supplier' AND NOT o.removed ORDER BY o.rank LIMIT 1) AS best_offer,
               inv_best_lead_time(c.vid) AS best_lead
          FROM cand c
         WHERE q IS NULL OR c.score >= 0.25
    )
    SELECT s.vid, s.pid, s.pname, s.bname, s.tname, s.kind, s.sku, s.size_key, inv_size_name(s.size_key), s.barcode, s.mpn,
           s.retail_price, s.map_price, CASE WHEN sees THEN s.cost_price END, NOT sees, s.ships_how, s.own_avail, s.own_oh, s.own_fm, s.best_offer, s.best_lead,
           CASE WHEN s.own_avail > 0 THEN 'in_stock' WHEN s.best_offer IS NOT NULL AND s.best_offer->>'availability' IN ('in_stock', 'limited') THEN 'from_supplier'
                WHEN s.best_offer IS NOT NULL AND s.best_offer->>'availability' IN ('pre_order', 'back_order') THEN 'back_order' ELSE 'unavailable' END,
           s.score
      FROM scored s
     WHERE (NOT COALESCE((f->>'in_stock_only')::boolean, false) OR s.own_avail > 0 OR (s.best_offer->>'availability') IN ('in_stock', 'limited'))
       AND (NOT (f ? 'max_lead_days') OR s.best_lead <= (f->>'max_lead_days')::integer)
     ORDER BY s.score DESC, s.pname, s.sku
     LIMIT GREATEST(1, LEAST(COALESCE(p_limit, 50), 500));
END$$;

-- ---------------------------------------------------------------------------------------------
-- Histories.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION inv_offer_history(p_lv_id bigint, p_since timestamptz DEFAULT now() - interval '90 days')
    RETURNS TABLE (observed_at timestamptz, price numeric, compare_at_price numeric, cost_price numeric, cost_withheld boolean, availability text, qty integer, lead_time_days integer, is_heartbeat boolean, pull_id bigint)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE sees boolean := inv_sees_cost();
BEGIN
    PERFORM inv_require_reader();
    RETURN QUERY SELECT o.observed_at, o.price, o.compare_at_price, CASE WHEN sees THEN o.cost_price END, NOT sees, o.availability, o.qty, o.lead_time_days, o.is_heartbeat, o.pull_id
                   FROM offer_snapshots o WHERE o.listing_variant_id = p_lv_id AND o.observed_at >= COALESCE(p_since, '-infinity') ORDER BY o.observed_at;
END$$;

CREATE OR REPLACE FUNCTION inv_price_history(p_variant_id bigint)
    RETURNS TABLE (changed_at timestamptz, kind text, old_price numeric, new_price numeric, changed_by bigint, changed_by_name text, reason text, source_kind text)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE sees boolean := inv_sees_cost();
BEGIN
    PERFORM inv_require_reader();
    RETURN QUERY SELECT h.changed_at, h.kind, h.old_price, h.new_price, h.changed_by, m.display_name, h.reason, h.source_kind
                   FROM price_history h LEFT JOIN members m ON m.id = h.changed_by
                  WHERE h.variant_id = p_variant_id AND (sees OR h.kind <> 'cost') ORDER BY h.changed_at DESC;
END$$;

-- ---------------------------------------------------------------------------------------------
-- The Buyer's lists (§5, the morning note): reorder candidates, lines at risk, price exceptions, unmatched listings,
-- source health, purchase orders open, returns open.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION inv_reorder_candidates()
    RETURNS TABLE (variant_id bigint, sku text, product_name text, size_name text, on_hand integer, allocated integer, available integer, on_order integer, reorder_point integer, reorder_qty integer,
                   best_listing_variant_id bigint, best_source_id bigint, best_supplier_id bigint, best_supplier_name text, best_cost numeric, best_lead_time_days integer, best_availability text)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE sees boolean := inv_sees_cost();
BEGIN
    PERFORM inv_require_right('reports.read');
    RETURN QUERY
    SELECT v.id, v.sku, p.name, inv_size_name(v.size_key),
           COALESCE(s.oh, 0)::integer, COALESCE(s.al, 0)::integer, COALESCE(s.av, 0)::integer, inv_on_order(v.id),
           COALESCE(v.reorder_point, p.reorder_point, st.reorder_point_default), COALESCE(v.reorder_qty, st.reorder_qty_default),
           o.listing_variant_id, o.source_id, o.supplier_id, o.supplier_name, CASE WHEN sees THEN o.cost END, o.lead_time_days, o.availability
      FROM product_variants v JOIN products p ON p.id = v.product_id CROSS JOIN inv_settings st
      LEFT JOIN LATERAL (SELECT sum(b.qty_on_hand) AS oh, sum(b.qty_allocated) AS al, sum(b.qty_on_hand - b.qty_allocated - b.qty_floor_model) AS av FROM inventory_balances b WHERE b.variant_id = v.id) s ON true
      LEFT JOIN LATERAL (SELECT * FROM inv_offers_for_variant(v.id) x WHERE x.source_role = 'supplier' AND NOT x.removed AND x.availability IN ('in_stock', 'limited') ORDER BY x.rank LIMIT 1) o ON true
     WHERE st.id = 1 AND v.active AND p.status = 'active' AND p.kind = 'single'
       AND COALESCE(v.reorder_point, p.reorder_point, st.reorder_point_default) > 0
       AND COALESCE(s.av, 0) + inv_on_order(v.id) <= COALESCE(v.reorder_point, p.reorder_point, st.reorder_point_default)
     ORDER BY (COALESCE(v.reorder_point, p.reorder_point, st.reorder_point_default) - COALESCE(s.av, 0)) DESC, p.name, v.sku;
END$$;

CREATE OR REPLACE FUNCTION inv_lines_at_risk()
    RETURNS TABLE (sales_order_id bigint, order_number text, line_id bigint, line_no integer, variant_id bigint, sku text, product_name text, customer_name text, promised_on date,
                   fulfilment_kind text, line_status text, risk text, detail text, salesperson_member_id bigint)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    PERFORM inv_require_reader();
    RETURN QUERY
    SELECT o.id, o.number, l.id, l.line_no, l.variant_id, v.sku, p.name, c.name, o.promised_on, l.fulfilment_kind, l.status, r.risk, r.detail, o.salesperson_member_id
      FROM sales_orders o JOIN sales_order_lines l ON l.sales_order_id = o.id
      JOIN product_variants v ON v.id = l.variant_id JOIN products p ON p.id = v.product_id JOIN customers c ON c.id = o.customer_id
      LEFT JOIN listing_variants lv ON lv.id = l.listing_variant_id
      LEFT JOIN sources s ON s.id = l.source_id
      LEFT JOIN source_pulls sp ON sp.id = s.last_pull_id
      LEFT JOIN purchase_order_lines pl ON pl.id = l.purchase_order_line_id
      LEFT JOIN inventory_balances b ON b.variant_id = l.variant_id AND b.location_id = l.location_id
      CROSS JOIN LATERAL (SELECT
            CASE WHEN l.fulfilment_kind = 'dropship' AND pl.status = 'declined' THEN 'declined'
                 WHEN l.fulfilment_kind = 'dropship' AND (lv.removed_at IS NOT NULL OR lv.availability IN ('out_of_stock', 'discontinued', 'unknown')) AND COALESCE(pl.status, 'open') NOT IN ('shipped', 'received') THEN 'offer_gone'
                 WHEN l.fulfilment_kind = 'dropship' AND (s.paused_at IS NOT NULL OR sp.status IN ('failed', 'blocked')) AND COALESCE(pl.status, 'open') NOT IN ('shipped', 'received') THEN 'source_failing'
                 WHEN l.fulfilment_kind = 'dropship' AND pl.expected_on IS NOT NULL AND pl.expected_on < current_date AND pl.status NOT IN ('shipped', 'received', 'declined', 'cancelled') THEN 'overdue'
                 WHEN l.fulfilment_kind IN ('stock', 'pickup', 'backorder') AND COALESCE(b.qty_on_hand - b.qty_floor_model, 0) < (l.qty - l.qty_shipped) THEN 'no_stock'
                 ELSE NULL END AS risk,
            CASE WHEN l.fulfilment_kind = 'dropship' THEN COALESCE(s.name, '') || ' · ' || COALESCE(lv.availability, '') || COALESCE(' · PO line ' || pl.status, '')
                 ELSE format('%s on hand at the location, %s still to ship', COALESCE(b.qty_on_hand - b.qty_floor_model, 0), l.qty - l.qty_shipped) END AS detail) r
     WHERE o.status IN ('confirmed', 'in_fulfilment') AND l.status IN ('open', 'allocated', 'ordered') AND r.risk IS NOT NULL
     ORDER BY o.promised_on NULLS LAST, o.number, l.line_no;
END$$;

CREATE OR REPLACE FUNCTION inv_price_exceptions()
    RETURNS TABLE (kind text, variant_id bigint, sku text, product_name text, size_name text, retail_price numeric, map_price numeric, cost_price numeric, cost_withheld boolean,
                   reference_price numeric, source_id bigint, source_name text, pct numeric, detail text)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE sees boolean := inv_sees_cost(); undercut numeric; move numeric;
BEGIN
    PERFORM inv_require_right('reports.read');
    SELECT reference_undercut_pct, cost_move_pct INTO undercut, move FROM inv_settings WHERE id = 1;
    RETURN QUERY
    -- retail under MAP
    SELECT 'retail_under_map'::text, v.id, v.sku, p.name, inv_size_name(v.size_key), v.retail_price, v.map_price, CASE WHEN sees THEN v.cost_price END, NOT sees,
           NULL::numeric, NULL::bigint, NULL::text, round((v.map_price - v.retail_price) / NULLIF(v.map_price, 0) * 100, 1), format('retail %s is under MAP %s', v.retail_price, v.map_price)
      FROM product_variants v JOIN products p ON p.id = v.product_id
     WHERE v.active AND v.map_price IS NOT NULL AND v.retail_price IS NOT NULL AND v.retail_price < v.map_price
    UNION ALL
    -- a reference undercuts our retail by more than the setting
    SELECT 'reference_undercut'::text, v.id, v.sku, p.name, inv_size_name(v.size_key), v.retail_price, v.map_price, CASE WHEN sees THEN v.cost_price END, NOT sees,
           lv.price, s.id, s.name, round((v.retail_price - lv.price) / NULLIF(v.retail_price, 0) * 100, 1), format('%s asks %s against our %s', s.name, lv.price, v.retail_price)
      FROM listing_variants lv JOIN listings l ON l.id = lv.listing_id JOIN sources s ON s.id = l.source_id
      JOIN product_variants v ON v.id = lv.variant_id JOIN products p ON p.id = v.product_id
     WHERE s.role = 'reference' AND s.active AND lv.removed_at IS NULL AND lv.availability NOT IN ('discontinued') AND lv.price IS NOT NULL AND v.retail_price > 0
       AND lv.price < v.retail_price * (1 - undercut / 100)
    UNION ALL
    -- cost moved more than the setting (the last change within 30 days)
    SELECT 'cost_moved'::text, v.id, v.sku, p.name, inv_size_name(v.size_key), v.retail_price, v.map_price, CASE WHEN sees THEN v.cost_price END, NOT sees,
           CASE WHEN sees THEN h.old_price END, NULL::bigint, h.reason, round((h.new_price - h.old_price) / NULLIF(h.old_price, 0) * 100, 1),
           CASE WHEN sees THEN format('cost %s → %s (%s)', h.old_price, h.new_price, COALESCE(h.reason, h.source_kind)) ELSE 'cost moved (withheld)' END
      FROM price_history h JOIN product_variants v ON v.id = h.variant_id JOIN product_variants v2 ON v2.id = v.id JOIN products p ON p.id = v.product_id
     WHERE h.kind = 'cost' AND h.old_price IS NOT NULL AND h.old_price > 0 AND h.changed_at > now() - interval '30 days'
       AND abs(h.new_price - h.old_price) / h.old_price * 100 > move
       AND h.id = (SELECT max(h2.id) FROM price_history h2 WHERE h2.variant_id = h.variant_id AND h2.kind = 'cost')
     ORDER BY 1, 4, 3;
END$$;

CREATE OR REPLACE FUNCTION inv_unmatched_listings(p_source_id bigint DEFAULT NULL)
    RETURNS TABLE (listing_variant_id bigint, listing_id bigint, source_id bigint, source_name text, title text, variant_title text, vendor text, sku text, barcode text, mpn text,
                   size_name text, price numeric, availability text, proposals integer, best_proposal jsonb, first_seen_at timestamptz, last_seen_at timestamptz)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    PERFORM inv_require_right('listings.match');
    RETURN QUERY
    SELECT lv.id, l.id, s.id, s.name, l.title, lv.title, l.vendor, lv.sku, lv.barcode, lv.mpn, inv_size_name(lv.size_key), lv.price, lv.availability,
           (SELECT count(*) FROM match_proposals mp WHERE mp.listing_variant_id = lv.id AND mp.status = 'proposed')::integer,
           (SELECT jsonb_build_object('proposal_id', mp.id, 'variant_id', mp.variant_id, 'sku', pv.sku, 'product', p.name, 'confidence', mp.confidence, 'evidence', mp.evidence, 'proposed_by', mp.proposed_by)
              FROM match_proposals mp JOIN product_variants pv ON pv.id = mp.variant_id JOIN products p ON p.id = pv.product_id
             WHERE mp.listing_variant_id = lv.id AND mp.status = 'proposed' ORDER BY mp.confidence DESC LIMIT 1),
           lv.first_seen_at, lv.last_seen_at
      FROM listing_variants lv JOIN listings l ON l.id = lv.listing_id JOIN sources s ON s.id = l.source_id
     WHERE lv.variant_id IS NULL AND lv.removed_at IS NULL AND s.active AND (p_source_id IS NULL OR s.id = p_source_id)
     ORDER BY (SELECT max(mp.confidence) FROM match_proposals mp WHERE mp.listing_variant_id = lv.id AND mp.status = 'proposed') DESC NULLS LAST, lv.last_seen_at DESC;
END$$;

CREATE OR REPLACE FUNCTION inv_source_health()
    RETURNS TABLE (source_id bigint, name text, connector text, role text, supplier_name text, active boolean, health text, paused_at timestamptz, paused_reason text, robots_state text,
                   schedule_minutes integer, last_pull_id bigint, last_pull_at timestamptz, last_status text, last_error text, last_ok_at timestamptz, consecutive_failures integer,
                   backoff_until timestamptz, next_due_at timestamptz, stale boolean, listings_live bigint, variants_live bigint, variants_unmatched bigint)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    PERFORM inv_require_reader();
    RETURN QUERY
    SELECT s.id, s.name, s.connector, s.role, sp.name, s.active,
           CASE WHEN NOT s.active THEN 'inactive' WHEN s.paused_at IS NOT NULL THEN 'paused' WHEN s.robots_state = 'blocked' THEN 'blocked'
                WHEN s.consecutive_failures > 0 THEN 'failing' WHEN s.schedule_minutes = 0 THEN 'manual'
                WHEN s.last_ok_at IS NULL THEN 'never_pulled'
                WHEN s.last_ok_at < now() - make_interval(mins => 2 * s.schedule_minutes) THEN 'stale' ELSE 'ok' END,
           s.paused_at, s.paused_reason, s.robots_state, s.schedule_minutes, s.last_pull_id, p.started_at, p.status, p.error, s.last_ok_at, s.consecutive_failures, s.backoff_until,
           CASE WHEN s.schedule_minutes = 0 OR NOT s.active OR s.paused_at IS NOT NULL THEN NULL
                ELSE GREATEST(COALESCE(s.backoff_until, '-infinity'), COALESCE(s.last_ok_at, '-infinity') + make_interval(mins => s.schedule_minutes)) END,
           (s.schedule_minutes > 0 AND (s.last_ok_at IS NULL OR s.last_ok_at < now() - make_interval(mins => 2 * s.schedule_minutes))),
           (SELECT count(*) FROM listings l WHERE l.source_id = s.id AND l.removed_at IS NULL),
           (SELECT count(*) FROM listing_variants lv JOIN listings l ON l.id = lv.listing_id WHERE l.source_id = s.id AND lv.removed_at IS NULL),
           (SELECT count(*) FROM listing_variants lv JOIN listings l ON l.id = lv.listing_id WHERE l.source_id = s.id AND lv.removed_at IS NULL AND lv.variant_id IS NULL)
      FROM sources s LEFT JOIN suppliers sp ON sp.id = s.supplier_id LEFT JOIN source_pulls p ON p.id = s.last_pull_id
     ORDER BY s.name;
END$$;

-- The worker's list: active, scheduled, not paused, not backing off, due.
CREATE OR REPLACE FUNCTION inv_sources_due() RETURNS SETOF sources
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT s.* FROM sources s
     WHERE s.active AND s.schedule_minutes > 0 AND s.paused_at IS NULL AND (s.backoff_until IS NULL OR s.backoff_until <= now())
       AND (s.last_ok_at IS NULL OR s.last_ok_at + make_interval(mins => s.schedule_minutes) <= now())
       AND NOT EXISTS (SELECT 1 FROM source_pulls p WHERE p.source_id = s.id AND p.status = 'running' AND p.started_at > now() - interval '6 hours')
     ORDER BY COALESCE(s.last_ok_at, '-infinity');
$$;

-- S5: stock at cost as of a moment, by location / brand / type / variant. Cost is the variant's standard cost; the quantity
-- is the ledger summed to that moment.
CREATE OR REPLACE FUNCTION inv_stock_value(p_as_of timestamptz DEFAULT now(), p_by text DEFAULT 'location')
    RETURNS TABLE (group_id bigint, group_name text, units bigint, value numeric, cost_withheld boolean)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE sees boolean := inv_sees_cost();
BEGIN
    PERFORM inv_require_right('reports.read');
    IF p_by NOT IN ('location', 'brand', 'type', 'variant') THEN RAISE EXCEPTION 'Group by location, brand, type or variant' USING ERRCODE = 'check_violation'; END IF;
    RETURN QUERY
    WITH q AS (
        SELECT t.variant_id, t.location_id, sum(t.qty) AS units
          FROM inventory_transactions t WHERE t.affects = 'on_hand' AND t.occurred_at <= COALESCE(p_as_of, now())
         GROUP BY t.variant_id, t.location_id HAVING sum(t.qty) <> 0
    )
    SELECT CASE p_by WHEN 'location' THEN loc.id WHEN 'brand' THEN b.id WHEN 'type' THEN pt.id ELSE v.id END,
           CASE p_by WHEN 'location' THEN loc.name WHEN 'brand' THEN COALESCE(b.name, '(no brand)') WHEN 'type' THEN pt.name ELSE p.name || ' ' || v.sku END,
           sum(q.units)::bigint, CASE WHEN sees THEN sum(q.units * COALESCE(v.cost_price, 0)) END, NOT sees
      FROM q JOIN product_variants v ON v.id = q.variant_id JOIN products p ON p.id = v.product_id LEFT JOIN brands b ON b.id = p.brand_id
      JOIN product_types pt ON pt.id = p.product_type_id JOIN locations loc ON loc.id = q.location_id
     GROUP BY 1, 2 ORDER BY 2;
END$$;

-- S6: what shipped in the last N days (stock and drop-ship alike), by variant / brand / type / salesperson, with cover.
CREATE OR REPLACE FUNCTION inv_sell_through(p_days integer DEFAULT 30, p_by text DEFAULT 'variant')
    RETURNS TABLE (group_id bigint, group_name text, units_sold bigint, revenue numeric, cogs numeric, margin_pct numeric, cost_withheld boolean, units_on_hand bigint, weeks_of_cover numeric)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE sees boolean := inv_sees_cost(); d integer := GREATEST(1, COALESCE(p_days, 30));
BEGIN
    PERFORM inv_require_right('reports.read');
    IF p_by NOT IN ('variant', 'brand', 'type', 'salesperson') THEN RAISE EXCEPTION 'Group by variant, brand, type or salesperson' USING ERRCODE = 'check_violation'; END IF;
    RETURN QUERY
    WITH sold AS (
        SELECT sl.qty, l.unit_price, l.discount / NULLIF(l.qty, 0) AS disc_each, COALESCE(l.offer_cost, v.cost_price, 0) AS cost_each, v.id AS vid, p.name AS pname, v.sku, p.brand_id, p.product_type_id, o.salesperson_member_id
          FROM shipment_lines sl JOIN shipments sh ON sh.id = sl.shipment_id JOIN sales_order_lines l ON l.id = sl.sales_order_line_id
          JOIN sales_orders o ON o.id = l.sales_order_id JOIN product_variants v ON v.id = l.variant_id JOIN products p ON p.id = v.product_id
         WHERE sh.shipped_at >= now() - make_interval(days => d) AND o.status <> 'cancelled'
    )
    SELECT CASE p_by WHEN 'variant' THEN s.vid WHEN 'brand' THEN s.brand_id WHEN 'type' THEN s.product_type_id ELSE s.salesperson_member_id END,
           CASE p_by WHEN 'variant' THEN s.pname || ' ' || s.sku WHEN 'brand' THEN COALESCE((SELECT name FROM brands WHERE id = s.brand_id), '(no brand)')
                     WHEN 'type' THEN (SELECT name FROM product_types WHERE id = s.product_type_id) ELSE COALESCE((SELECT display_name FROM members WHERE id = s.salesperson_member_id), '(no salesperson)') END,
           sum(s.qty), sum(s.qty * (s.unit_price - COALESCE(s.disc_each, 0))),
           CASE WHEN sees THEN sum(s.qty * s.cost_each) END,
           CASE WHEN sees AND sum(s.qty * (s.unit_price - COALESCE(s.disc_each, 0))) > 0 THEN round((sum(s.qty * (s.unit_price - COALESCE(s.disc_each, 0))) - sum(s.qty * s.cost_each)) / sum(s.qty * (s.unit_price - COALESCE(s.disc_each, 0))) * 100, 1) END,
           NOT sees,
           CASE WHEN p_by = 'variant' THEN COALESCE((SELECT sum(b.qty_on_hand) FROM inventory_balances b WHERE b.variant_id = min(s.vid)), 0) END,
           CASE WHEN p_by = 'variant' AND sum(s.qty) > 0 THEN round(COALESCE((SELECT sum(b.qty_on_hand) FROM inventory_balances b WHERE b.variant_id = min(s.vid)), 0) / (sum(s.qty)::numeric / (d / 7.0)), 1) END
      FROM sold s
     GROUP BY 1, 2 ORDER BY 3 DESC, 2;
END$$;

-- A14: promised vs actual lead times from the PO events, per supplier.
CREATE OR REPLACE FUNCTION inv_lead_time_actuals(p_supplier_id bigint DEFAULT NULL)
    RETURNS TABLE (supplier_id bigint, supplier_name text, lines bigint, promised_avg_days numeric, actual_avg_days numeric, drift_days numeric, on_time_pct numeric)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    PERFORM inv_require_right('reports.read');
    RETURN QUERY
    SELECT sp.id, sp.name, count(*), round(avg(pl.expected_on - COALESCE(po.sent_at::date, po.ordered_on)), 1), round(avg(pl.shipped_at::date - COALESCE(po.sent_at::date, po.ordered_on)), 1),
           round(avg((pl.shipped_at::date - COALESCE(po.sent_at::date, po.ordered_on)) - (pl.expected_on - COALESCE(po.sent_at::date, po.ordered_on))), 1),
           round(100.0 * count(*) FILTER (WHERE pl.shipped_at::date <= pl.expected_on) / count(*), 1)
      FROM purchase_order_lines pl JOIN purchase_orders po ON po.id = pl.purchase_order_id JOIN suppliers sp ON sp.id = po.supplier_id
     WHERE pl.shipped_at IS NOT NULL AND pl.expected_on IS NOT NULL AND (p_supplier_id IS NULL OR sp.id = p_supplier_id)
     GROUP BY sp.id, sp.name ORDER BY sp.name;
END$$;

-- O2: an order's timeline — its own facts, its drop-ship POs' events, its shipments and returns, and its activity.
CREATE OR REPLACE FUNCTION inv_order_timeline(p_order_id bigint)
    RETURNS TABLE (at timestamptz, kind text, title text, detail jsonb, member_id bigint)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE sees boolean := inv_sees_cost();
BEGIN
    PERFORM inv_require_reader();
    RETURN QUERY
    SELECT o.created_at, 'quote', 'Quote ' || o.number || ' created', jsonb_build_object('origin', o.origin), o.created_by FROM sales_orders o WHERE o.id = p_order_id
    UNION ALL SELECT o.confirmed_at, 'confirm', 'Confirmed', jsonb_build_object('total', o.total), o.confirmed_by FROM sales_orders o WHERE o.id = p_order_id AND o.confirmed_at IS NOT NULL
    UNION ALL SELECT pm.taken_at, 'payment', initcap(pm.kind) || ' ' || pm.amount || ' by ' || pm.method, jsonb_build_object('amount', pm.amount, 'method', pm.method, 'reference', pm.reference), pm.taken_by FROM order_payments pm WHERE pm.sales_order_id = p_order_id
    UNION ALL SELECT po.created_at, 'po_draft', 'Drop-ship ' || po.number || ' drafted for ' || sp.name, jsonb_build_object('purchase_order_id', po.id, 'status', po.status, 'total', CASE WHEN sees THEN po.total END), po.created_by
                FROM purchase_orders po JOIN suppliers sp ON sp.id = po.supplier_id WHERE po.sales_order_id = p_order_id
    UNION ALL SELECT e.created_at, 'po_' || e.kind, initcap(replace(e.kind, '_', ' ')) || ' on ' || po.number || CASE WHEN e.source = 'portal' THEN ' (by the supplier)' ELSE '' END,
                     jsonb_build_object('purchase_order_id', po.id, 'line_id', e.purchase_order_line_id, 'supplier_order_ref', e.supplier_order_ref, 'expected_on', e.expected_on, 'carrier', e.carrier, 'tracking_number', e.tracking_number, 'reason', e.reason), e.member_id
                FROM purchase_order_events e JOIN purchase_orders po ON po.id = e.purchase_order_id WHERE po.sales_order_id = p_order_id
    UNION ALL SELECT sh.shipped_at, 'shipment', 'Shipped (' || sh.kind || ')' || COALESCE(' ' || sh.carrier || ' ' || sh.tracking_number, ''), jsonb_build_object('shipment_id', sh.id, 'carrier', sh.carrier, 'tracking_number', sh.tracking_number), sh.shipped_by FROM shipments sh WHERE sh.sales_order_id = p_order_id
    UNION ALL SELECT sh.delivered_at, 'delivered', 'Delivered', jsonb_build_object('shipment_id', sh.id), NULL::bigint FROM shipments sh WHERE sh.sales_order_id = p_order_id AND sh.delivered_at IS NOT NULL
    UNION ALL SELECT ra.created_at, 'return', 'Return ' || ra.number || ' ' || ra.status, jsonb_build_object('return_id', ra.id, 'status', ra.status, 'refund_amount', ra.refund_amount), ra.requested_by FROM return_authorizations ra WHERE ra.sales_order_id = p_order_id
    UNION ALL SELECT o.closed_at, 'close', 'Closed', '{}'::jsonb, o.closed_by FROM sales_orders o WHERE o.id = p_order_id AND o.closed_at IS NOT NULL
    UNION ALL SELECT o.cancelled_at, 'cancel', 'Cancelled: ' || COALESCE(o.cancel_reason, ''), '{}'::jsonb, o.cancelled_by FROM sales_orders o WHERE o.id = p_order_id AND o.cancelled_at IS NOT NULL
    UNION ALL SELECT a.occurred_at, 'activity', a.action, COALESCE(a.after, '{}'::jsonb), a.actor_member_id FROM activity_log a WHERE a.sales_order_id = p_order_id
    ORDER BY 1;
END$$;

-- D1: open purchase orders with what the Buyer watches for (awaiting acknowledgment past N days, past expected).
CREATE OR REPLACE FUNCTION inv_purchase_orders_open()
    RETURNS TABLE (purchase_order_id bigint, number text, kind text, status text, supplier_id bigint, supplier_name text, sales_order_id bigint, sales_order_number text,
                   ordered_on date, sent_at timestamptz, expected_on date, total numeric, cost_withheld boolean, lines integer, lines_open integer, lines_shipped integer, lines_received integer,
                   days_waiting integer, awaiting_ack boolean, overdue boolean, untracked_past_expected boolean)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE sees boolean := inv_sees_cost(); ack integer := inv_setting_int('ack_days');
BEGIN
    PERFORM inv_require_reader();
    RETURN QUERY
    SELECT po.id, po.number, po.kind, po.status, sp.id, sp.name, so.id, so.number, po.ordered_on, po.sent_at, po.expected_on, CASE WHEN sees THEN po.total END, NOT sees,
           (SELECT count(*) FROM purchase_order_lines pl WHERE pl.purchase_order_id = po.id)::integer,
           (SELECT count(*) FROM purchase_order_lines pl WHERE pl.purchase_order_id = po.id AND pl.status IN ('open', 'acknowledged', 'partial'))::integer,
           (SELECT count(*) FROM purchase_order_lines pl WHERE pl.purchase_order_id = po.id AND pl.status = 'shipped')::integer,
           (SELECT count(*) FROM purchase_order_lines pl WHERE pl.purchase_order_id = po.id AND pl.status = 'received')::integer,
           CASE WHEN po.sent_at IS NOT NULL THEN (current_date - po.sent_at::date) END,
           (po.status = 'sent' AND po.sent_at IS NOT NULL AND po.sent_at < now() - make_interval(days => ack)),
           (po.expected_on IS NOT NULL AND po.expected_on < current_date AND po.status NOT IN ('received')),
           (po.kind = 'dropship' AND EXISTS (SELECT 1 FROM purchase_order_lines pl WHERE pl.purchase_order_id = po.id AND pl.status IN ('open', 'acknowledged') AND pl.expected_on < current_date))
      FROM purchase_orders po JOIN suppliers sp ON sp.id = po.supplier_id LEFT JOIN sales_orders so ON so.id = po.sales_order_id
     WHERE po.status IN ('draft', 'sent', 'acknowledged', 'partial')
     ORDER BY po.expected_on NULLS LAST, po.number;
END$$;

-- D5: returns open — requested, approved, received awaiting close — with their dispositions.
CREATE OR REPLACE FUNCTION inv_returns_open()
    RETURNS TABLE (return_id bigint, number text, status text, sales_order_id bigint, order_number text, customer_id bigint, customer_name text, method text, scheduled_on date,
                   lines integer, dispositions text[], refund_amount numeric, created_at timestamptz, days_open integer)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    PERFORM inv_require_reader();
    RETURN QUERY
    SELECT ra.id, ra.number, ra.status, so.id, so.number, c.id, c.name, ra.method, ra.scheduled_on,
           (SELECT count(*) FROM return_lines rl WHERE rl.return_id = ra.id)::integer,
           (SELECT COALESCE(array_agg(DISTINCT rl.disposition), '{}') FROM return_lines rl WHERE rl.return_id = ra.id),
           ra.refund_amount, ra.created_at, (current_date - ra.created_at::date)
      FROM return_authorizations ra JOIN sales_orders so ON so.id = ra.sales_order_id JOIN customers c ON c.id = ra.customer_id
     WHERE ra.status IN ('requested', 'approved', 'received')
     ORDER BY ra.created_at;
END$$;

-- C6: the catalog's gaps.
CREATE OR REPLACE FUNCTION inv_catalog_gaps()
    RETURNS TABLE (variant_id bigint, sku text, product_name text, size_name text, gap text)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE sees boolean := inv_sees_cost();
BEGIN
    PERFORM inv_require_right('catalog.write');
    RETURN QUERY
    SELECT v.id, v.sku, p.name, inv_size_name(v.size_key), g.gap
      FROM product_variants v JOIN products p ON p.id = v.product_id
      CROSS JOIN LATERAL (SELECT unnest(ARRAY[
            CASE WHEN v.barcode IS NULL AND NOT EXISTS (SELECT 1 FROM variant_identifiers i WHERE i.variant_id = v.id AND i.kind IN ('gtin', 'upc', 'ean')) THEN 'no_gtin' END,
            CASE WHEN sees AND v.cost_price IS NULL AND p.kind = 'single' THEN 'no_cost' END,
            CASE WHEN v.retail_price IS NULL THEN 'no_retail' END,
            CASE WHEN NOT EXISTS (SELECT 1 FROM product_images pi WHERE pi.product_id = p.id) THEN 'no_image' END,
            CASE WHEN v.map_price IS NOT NULL AND v.retail_price < v.map_price THEN 'retail_under_map' END,
            CASE WHEN p.kind = 'bundle' AND NOT EXISTS (SELECT 1 FROM bundle_components bc WHERE bc.bundle_variant_id = v.id) THEN 'empty_bundle' END]) AS gap) g
     WHERE v.active AND p.status <> 'discontinued' AND g.gap IS NOT NULL
     ORDER BY p.name, v.sku, g.gap;
END$$;

-- ---------------------------------------------------------------------------------------------
-- §4: the feed's answer for a key. {"gtin":…} | {"sku":…} | {"q":…,"size":…}. Never cost, never a source's name; the
-- quantity only when the setting says so; a partner key's price from its price list. Runs as the writer in PHP with the
-- key's minter as the acting member; the key itself was resolved and rate-checked by the caller.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION inv_feed_answer(p_key_id bigint, p_q jsonb) RETURNS jsonb
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE k feed_keys%ROWTYPE; pct numeric := 0; show_qty boolean; results jsonb; g text; r record; items jsonb := '[]'::jsonb; own integer; lead integer; st text;
BEGIN
    SELECT * INTO k FROM feed_keys WHERE id = p_key_id AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > now());
    IF NOT FOUND THEN RAISE EXCEPTION 'No such key' USING ERRCODE = 'insufficient_privilege'; END IF;
    IF k.consumer_kind = 'partner' AND k.price_list_id IS NOT NULL THEN SELECT percent_off_retail INTO pct FROM price_lists WHERE id = k.price_list_id AND active; END IF;
    SELECT feed_shows_quantity INTO show_qty FROM inv_settings WHERE id = 1;
    g := inv_gtin14(p_q->>'gtin');
    FOR r IN
        SELECT v.id, v.sku, v.barcode, v.size_key, v.retail_price, COALESCE(v.ships_how, p.ships_how) AS ships_how, p.name AS pname, b.name AS bname
          FROM product_variants v JOIN products p ON p.id = v.product_id LEFT JOIN brands b ON b.id = p.brand_id
         WHERE v.active AND p.status = 'active'
           AND ((p_q ? 'gtin' AND g IS NOT NULL AND (v.barcode = g OR EXISTS (SELECT 1 FROM variant_identifiers i WHERE i.variant_id = v.id AND i.kind IN ('gtin', 'upc', 'ean') AND i.value = g)))
             OR (p_q ? 'sku' AND lower(v.sku) = lower(p_q->>'sku'))
             OR (p_q ? 'q' AND NOT (p_q ? 'gtin') AND NOT (p_q ? 'sku') AND v.id IN (SELECT f.variant_id FROM inv_find(p_q->>'q', p_q->>'size', '{}'::jsonb, 25) f)))
         ORDER BY p.name, v.sku LIMIT 25
    LOOP
        SELECT COALESCE(sum(o.qty_available) FILTER (WHERE o.is_sellable), 0) INTO own FROM inv_own_stock(r.id) o;
        lead := inv_best_lead_time(r.id);
        st := CASE WHEN own > 0 THEN 'in_stock' WHEN lead IS NOT NULL THEN 'back_order' ELSE 'out_of_stock' END;
        items := items || jsonb_build_object('sku', r.sku, 'gtin', r.barcode, 'name', COALESCE(r.bname || ' ', '') || r.pname, 'size', inv_size_name(r.size_key),
                                             'retail_price', r.retail_price, 'currency', inv_currency(),
                                             'partner_price', CASE WHEN k.consumer_kind = 'partner' AND r.retail_price IS NOT NULL THEN round(r.retail_price * (1 - COALESCE(pct, 0) / 100), 2) END,
                                             'availability', st, 'quantity', CASE WHEN show_qty AND own > 0 THEN own END,
                                             'lead_time_days', lead, 'ships_how', r.ships_how);
    END LOOP;
    RETURN jsonb_build_object('query', jsonb_strip_nulls(jsonb_build_object('gtin', p_q->>'gtin', 'sku', p_q->>'sku', 'q', left(p_q->>'q', 120), 'size', p_q->>'size')),
                              'count', jsonb_array_length(items), 'results', items, 'as_of', now());
END$$;

GRANT EXECUTE ON FUNCTION inv_require_reader(), inv_require_right(text), inv_size_name(text), inv_own_stock(bigint), inv_best_lead_time(bigint), inv_on_order(bigint),
    inv_availability(bigint), inv_bundle_availability(bigint), inv_atp(bigint, integer, date), inv_find(text, text, jsonb, integer), inv_offer_history(bigint, timestamptz), inv_price_history(bigint),
    inv_reorder_candidates(), inv_lines_at_risk(), inv_price_exceptions(), inv_unmatched_listings(bigint), inv_source_health(), inv_stock_value(timestamptz, text), inv_sell_through(integer, text),
    inv_lead_time_actuals(bigint), inv_order_timeline(bigint), inv_purchase_orders_open(), inv_returns_open(), inv_catalog_gaps()
    TO inventory_rw, inventory_records_ro;
-- inv_offers_for_variant carries cost UNNULLED: the writer alone may call it (its presenters null); the read roles reach
-- offers only through inv_availability / inv_find / mcp_listing_variants, where the wall stands.
REVOKE ALL ON FUNCTION inv_offers_for_variant(bigint), inv_sources_due(), inv_feed_answer(bigint, jsonb) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION inv_offers_for_variant(bigint), inv_sources_due(), inv_feed_answer(bigint, jsonb) TO inventory_rw;

COMMIT;

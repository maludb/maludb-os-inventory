-- 021: WHAT SLICE 7 FOUND — an additive fix to db/014 and db/016 (docs/build-specs/feed.md "Built and proven"). A migration is never modified; this one adds.
--
--  inv_feed_answer() (db/014's) and inv_share_availability_index() (db/016's) both read a variant's state from its OWN stock (inv_own_stock) and its own best lead time. For a BUNDLE
--  (the Queen set: a mattress and a foundation) that is nothing at all — a set holds no stock of its own; its balance is its components' — so the feed and the availability share
--  said "out_of_stock" for a set whose parts were on the shelf. Find and the order form get it right through inv_availability() / inv_bundle_availability() (db/014); the feed's
--  design is "answers from the same SQL as Find" and the spec's proof reads "a bundle → its state from the components". One internal helper, inv_variant_supply(), answers the two
--  numbers the way inv_bundle_availability() does — a set's available count is its scarcest component's (sellable available ÷ the component's quantity), its lead time the longest of
--  the components' and NULL when any component can be supplied by nobody —, with NO caller check (the feed's key and the kernel's token have no reader of their own). Both functions are
--  db/014's and db/016's bodies with that one line changed. Nothing else about either answer moves.
BEGIN;

CREATE OR REPLACE FUNCTION inv_variant_supply(p_variant_id bigint, OUT sets_available integer, OUT best_lead integer)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM product_variants pv JOIN products p ON p.id = pv.product_id WHERE pv.id = p_variant_id AND p.kind = 'bundle')
       AND EXISTS (SELECT 1 FROM bundle_components WHERE bundle_variant_id = p_variant_id) THEN
        SELECT COALESCE(min(COALESCE((SELECT sum(o.qty_available) FROM inv_own_stock(c.component_variant_id) o WHERE o.is_sellable), 0) / c.qty), 0)::integer, max(inv_best_lead_time(c.component_variant_id))
          INTO sets_available, best_lead FROM bundle_components c WHERE c.bundle_variant_id = p_variant_id;
        IF EXISTS (SELECT 1 FROM bundle_components c WHERE c.bundle_variant_id = p_variant_id AND inv_best_lead_time(c.component_variant_id) IS NULL) THEN best_lead := NULL; END IF;
        IF sets_available > 0 THEN best_lead := 0; END IF;
    ELSE
        SELECT COALESCE(sum(o.qty_available) FILTER (WHERE o.is_sellable), 0)::integer INTO sets_available FROM inv_own_stock(p_variant_id) o;
        best_lead := inv_best_lead_time(p_variant_id);
    END IF;
END$$;
REVOKE ALL ON FUNCTION inv_variant_supply(bigint) FROM PUBLIC;

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
        SELECT s.sets_available, s.best_lead INTO own, lead FROM inv_variant_supply(r.id) s;       -- a bundle's state is its components' (db/021)
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
            SELECT s.sets_available, s.best_lead INTO own, lead FROM inv_variant_supply(r.id) s;       -- a bundle's state is its components' (db/021)
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

COMMIT;

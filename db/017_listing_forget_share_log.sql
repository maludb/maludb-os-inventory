-- 017: WHAT PHASE 1 FOUND, PART TWO — additive to db/002, db/009, db/014 and db/015: "not ours" for a listing, the share.read
-- logger the read role lacks, and two settings the agents' view left out (docs/build-specs/sources.md "db/017_listing_forget.sql";
-- docs/build-specs/feed.md DECISION 2; docs/inventory-mcp-tool-surface.md `get_settings`). A migration is never modified; this one adds.
--
--  1. listings.forgotten_at / forgotten_by: a listing marked "not ours" had no column — a person's removed_at came back on the next
--     pull (inv_upsert_listing() clears it), and the manifest says the listing stays until its source removes it. Now
--     inv_listing_forget(listing, by) unmatches every variant (inv_listing_unmatch()), dismisses every open proposal of its variants
--     with decided_by = the person, stamps forgotten_at/forgotten_by, and refuses a listing already forgotten ("already marked not
--     ours"); it returns the variants unmatched. The listing stays and its pulls keep its offers: inv_upsert_listing() is untouched.
--  2. The forgotten guard, replaced into the four functions whose bodies are copied from db/009 and db/014 with the guard added and
--     nothing else changed: inv_match_listing_variant() returns NULL and inv_propose_matches() 0 for a variant of a forgotten listing
--     (never matched or scored again — the next pull's upsert still calls them and they do nothing); inv_listing_match() refuses
--     "That listing was marked not ours"; inv_unmatched_listings() hides them from the queue (AND l.forgotten_at IS NULL).
--  3. mcp_listings appends forgotten_at, forgotten_by as its last two columns (a view's columns are appended, never reordered —
--     the kernel's rule); the grant of db/015 is said again.
--  4. inv_log_share_read(tool, consumer, count, request_id): the records server runs as inventory_records_ro, which has no INSERT
--     on activity_log (db/002), so the tool surface's rule — every kernel call of a share is logged share.read — had no writer.
--     A SECURITY DEFINER logger, owned by the migration's owner as every definer function here is, writes exactly one row:
--     action share.read, source mcp, no actor (the kernel's token sets no member), entity_type share, the payload
--     {tool, consumer, count, request_id} and nothing else. EXECUTE to inventory_records_ro and inventory_rw; PUBLIC revoked, so
--     the activity role (a reader of the log, never a writer) cannot call it.
--  5. mcp_settings appends business_address and raw_max_bytes as its last two columns — get_settings owes both (the address on
--     the paperwork, the raw cap a connector trims to); the grant of db/015 is said again.
BEGIN;

-- ---------------------------------------------------------------------------------------------
-- 1. "Not ours": the two columns, the partial index, the act.
-- ---------------------------------------------------------------------------------------------
ALTER TABLE listings ADD COLUMN forgotten_at timestamptz, ADD COLUMN forgotten_by bigint REFERENCES members(id) ON DELETE SET NULL;
CREATE INDEX listings_forgotten_idx ON listings (source_id) WHERE forgotten_at IS NOT NULL;
COMMENT ON COLUMN listings.forgotten_at IS 'Marked "not ours" by a person: unmatched, hidden from the queue, never matched or scored again. The listing stays and its pulls keep its offers.';

CREATE OR REPLACE FUNCTION inv_listing_forget(p_listing_id bigint, p_by bigint) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE l listings%ROWTYPE; r record; n integer := 0;
BEGIN
    SELECT * INTO l FROM listings WHERE id = p_listing_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such listing' USING ERRCODE = 'no_data_found'; END IF;
    IF l.forgotten_at IS NOT NULL THEN RAISE EXCEPTION 'That listing was already marked not ours' USING ERRCODE = 'check_violation'; END IF;
    FOR r IN SELECT id FROM listing_variants WHERE listing_id = p_listing_id AND variant_id IS NOT NULL LOOP
        PERFORM inv_listing_unmatch(r.id);
        n := n + 1;
    END LOOP;
    UPDATE match_proposals SET status = 'dismissed', decided_by = p_by, decided_at = now()
     WHERE status = 'proposed' AND listing_variant_id IN (SELECT id FROM listing_variants WHERE listing_id = p_listing_id);
    UPDATE listings SET forgotten_at = now(), forgotten_by = p_by WHERE id = p_listing_id;
    RETURN n;
END$$;
REVOKE ALL ON FUNCTION inv_listing_forget(bigint, bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION inv_listing_forget(bigint, bigint) TO inventory_rw;

-- ---------------------------------------------------------------------------------------------
-- 2. The forgotten guard: db/009's three matcher functions and db/014's queue, copied, the guard added, nothing else changed.
-- ---------------------------------------------------------------------------------------------
-- Rules 1–4 in order; returns the match_kind applied, or NULL. A cross-size candidate is skipped, never taken.
CREATE OR REPLACE FUNCTION inv_match_listing_variant(p_lv_id bigint) RETURNS text
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE lv listing_variants%ROWTYPE; l listings%ROWTYPE; s sources%ROWTYPE; vid bigint;
BEGIN
    SELECT * INTO lv FROM listing_variants WHERE id = p_lv_id;
    IF NOT FOUND OR lv.variant_id IS NOT NULL THEN RETURN lv.match_kind; END IF;
    IF EXISTS (SELECT 1 FROM listings f WHERE f.id = lv.listing_id AND f.forgotten_at IS NOT NULL) THEN RETURN NULL; END IF;   -- the guard (aliased f: l is the row variable)
    SELECT * INTO l FROM listings WHERE id = lv.listing_id;
    SELECT * INTO s FROM sources WHERE id = l.source_id;

    -- (1) GTIN
    IF lv.barcode_valid THEN
        SELECT v.id INTO vid FROM product_variants v
         WHERE v.active AND inv_sizes_agree(v.size_key, lv.size_key)
           AND (v.barcode = lv.barcode OR EXISTS (SELECT 1 FROM variant_identifiers i WHERE i.variant_id = v.id AND i.kind IN ('gtin', 'upc', 'ean') AND i.value = lv.barcode))
         ORDER BY v.id LIMIT 1;
        IF vid IS NOT NULL THEN PERFORM inv_set_match(p_lv_id, vid, 'gtin', 1.000, NULL); RETURN 'gtin'; END IF;
    END IF;
    -- (2) supplier SKU — the source belongs to a supplier
    IF s.supplier_id IS NOT NULL AND lv.sku IS NOT NULL THEN
        SELECT v.id INTO vid FROM product_variants v
         WHERE v.active AND inv_sizes_agree(v.size_key, lv.size_key)
           AND (EXISTS (SELECT 1 FROM supplier_items si WHERE si.variant_id = v.id AND si.supplier_id = s.supplier_id AND lower(si.supplier_sku) = lower(lv.sku))
                OR EXISTS (SELECT 1 FROM variant_identifiers i WHERE i.variant_id = v.id AND i.kind = 'supplier_sku' AND i.source_id = s.id AND lower(i.value) = lower(lv.sku)))
         ORDER BY v.id LIMIT 1;
        IF vid IS NOT NULL THEN PERFORM inv_set_match(p_lv_id, vid, 'supplier_sku', 0.950, NULL); RETURN 'supplier_sku'; END IF;
    END IF;
    -- (3) MPN + size — both sizes known and equal
    IF lv.mpn IS NOT NULL AND lv.size_key IS NOT NULL THEN
        SELECT v.id INTO vid FROM product_variants v
         WHERE v.active AND v.size_key = lv.size_key
           AND (lower(v.mpn) = lower(lv.mpn) OR EXISTS (SELECT 1 FROM variant_identifiers i WHERE i.variant_id = v.id AND i.kind = 'mpn' AND lower(i.value) = lower(lv.mpn)))
         ORDER BY v.id LIMIT 1;
        IF vid IS NOT NULL THEN PERFORM inv_set_match(p_lv_id, vid, 'mpn', 0.900, NULL); RETURN 'mpn'; END IF;
    END IF;
    -- (4) a marketplace id recorded as an identifier (ASIN, eBay EPID, Walmart item id)
    SELECT v.id INTO vid FROM product_variants v
      JOIN variant_identifiers i ON i.variant_id = v.id AND i.kind IN ('asin', 'ebay_epid', 'walmart_item_id')
     WHERE v.active AND inv_sizes_agree(v.size_key, lv.size_key)
       AND (lower(i.value) = lower(lv.external_variant_id) OR lower(i.value) = lower(l.external_id) OR (lv.sku IS NOT NULL AND lower(i.value) = lower(lv.sku)))
     ORDER BY v.id LIMIT 1;
    IF vid IS NOT NULL THEN PERFORM inv_set_match(p_lv_id, vid, 'marketplace_id', 0.900, NULL); RETURN 'marketplace_id'; END IF;
    RETURN NULL;
END$$;

-- (5) A person's match (listing_match) — remembered, so the next pull keeps it. An agent may call it ONLY with an
-- identifier in hand (the screen checks); here the rules enforced are the size wall and "not ours".
CREATE OR REPLACE FUNCTION inv_listing_match(p_lv_id bigint, p_variant_id bigint, p_by bigint, p_kind text DEFAULT 'manual') RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE lsize text; vsize text; ok boolean;
BEGIN
    IF p_kind NOT IN ('manual', 'proposed_accepted') THEN RAISE EXCEPTION 'A person''s match is manual or an accepted proposal' USING ERRCODE = 'check_violation'; END IF;
    SELECT size_key INTO lsize FROM listing_variants WHERE id = p_lv_id;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such listing variant' USING ERRCODE = 'no_data_found'; END IF;
    IF EXISTS (SELECT 1 FROM listing_variants lv JOIN listings l ON l.id = lv.listing_id WHERE lv.id = p_lv_id AND l.forgotten_at IS NOT NULL) THEN
        RAISE EXCEPTION 'That listing was marked not ours' USING ERRCODE = 'check_violation';
    END IF;
    SELECT size_key, active INTO vsize, ok FROM product_variants WHERE id = p_variant_id;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such variant' USING ERRCODE = 'no_data_found'; END IF;
    IF NOT inv_sizes_agree(lsize, vsize) THEN
        RAISE EXCEPTION 'A match never crosses sizes: the listing is %, the variant is %', lsize, vsize USING ERRCODE = 'check_violation';
    END IF;
    PERFORM inv_set_match(p_lv_id, p_variant_id, p_kind, CASE p_kind WHEN 'manual' THEN 1.000 ELSE NULL END, p_by);
END$$;

-- Score every active variant against one unmatched listing variant; write a proposal for each candidate at or above 0.5,
-- unless the pair is already proposed, accepted or dismissed. Returns how many were written. A forgotten listing scores 0.
CREATE OR REPLACE FUNCTION inv_propose_matches(p_lv_id bigint, p_by bigint DEFAULT NULL) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE lv listing_variants%ROWTYPE; l listings%ROWTYPE; c record; n integer := 0; ltok text[]; score numeric; ev jsonb; shared text[]; frac numeric; dims integer;
BEGIN
    SELECT * INTO lv FROM listing_variants WHERE id = p_lv_id;
    IF NOT FOUND OR lv.variant_id IS NOT NULL THEN RETURN 0; END IF;
    IF EXISTS (SELECT 1 FROM listings f WHERE f.id = lv.listing_id AND f.forgotten_at IS NOT NULL) THEN RETURN 0; END IF;   -- the guard (aliased f: l is the row variable)
    SELECT * INTO l FROM listings WHERE id = lv.listing_id;
    ltok := inv_name_tokens(l.title || ' ' || COALESCE(lv.title, ''));
    FOR c IN SELECT v.*, p.name AS pname, b.name AS bname, pt.name AS tname, p.kind AS pkind
               FROM product_variants v JOIN products p ON p.id = v.product_id
               LEFT JOIN brands b ON b.id = p.brand_id JOIN product_types pt ON pt.id = p.product_type_id
              WHERE v.active AND p.status <> 'discontinued' AND p.kind = 'single'
                AND inv_sizes_agree(v.size_key, lv.size_key)
                AND NOT EXISTS (SELECT 1 FROM match_proposals mp WHERE mp.listing_variant_id = p_lv_id AND mp.variant_id = v.id)
    LOOP
        score := 0; ev := '{}'::jsonb;
        IF c.bname IS NOT NULL AND l.vendor IS NOT NULL AND lower(unaccent(c.bname)) = lower(unaccent(l.vendor)) THEN
            score := score + 0.30; ev := ev || '{"brand":true}';
        ELSIF c.bname IS NOT NULL AND position(lower(c.bname) IN lower(l.title)) > 0 THEN
            score := score + 0.20; ev := ev || '{"brand":"in_title"}';
        END IF;
        SELECT COALESCE(array_agg(t), '{}') INTO shared FROM unnest(inv_name_tokens(c.pname)) t WHERE t = ANY (ltok);
        frac := CASE WHEN cardinality(inv_name_tokens(c.pname)) = 0 THEN 0 ELSE cardinality(shared)::numeric / cardinality(inv_name_tokens(c.pname)) END;
        score := score + round(0.35 * frac, 3);
        ev := ev || jsonb_build_object('name_tokens', to_jsonb(shared), 'name_fraction', frac);
        IF c.size_key IS NOT NULL AND lv.size_key IS NOT NULL AND c.size_key = lv.size_key THEN
            score := score + 0.20; ev := ev || '{"size":true}';
        END IF;
        IF c.length_mm IS NOT NULL AND c.width_mm IS NOT NULL AND (l.raw ? 'length_mm') AND (l.raw ? 'width_mm') THEN
            dims := GREATEST(abs(c.length_mm - (l.raw->>'length_mm')::integer), abs(c.width_mm - (l.raw->>'width_mm')::integer));
            IF dims <= 20 THEN score := score + 0.10; ev := ev || jsonb_build_object('dims_mm', dims); END IF;
        END IF;
        IF l.product_type IS NOT NULL AND lower(l.product_type) = lower(c.tname) THEN
            score := score + 0.05; ev := ev || '{"type":true}';
        END IF;
        score := LEAST(score, 1.000);
        IF score >= 0.5 THEN
            INSERT INTO match_proposals (listing_variant_id, variant_id, confidence, evidence, proposed_by) VALUES (p_lv_id, c.id, score, ev, p_by);
            n := n + 1;
        END IF;
    END LOOP;
    RETURN n;
END$$;

-- The match queue: unmatched, live, of an active source — and not forgotten.
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
     WHERE lv.variant_id IS NULL AND lv.removed_at IS NULL AND l.forgotten_at IS NULL AND s.active AND (p_source_id IS NULL OR s.id = p_source_id)
     ORDER BY (SELECT max(mp.confidence) FROM match_proposals mp WHERE mp.listing_variant_id = lv.id AND mp.status = 'proposed') DESC NULLS LAST, lv.last_seen_at DESC;
END$$;
-- The grants of db/009 and db/014 survive CREATE OR REPLACE; said again so this file reads whole.
REVOKE ALL ON FUNCTION inv_match_listing_variant(bigint), inv_listing_match(bigint, bigint, bigint, text), inv_propose_matches(bigint, bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION inv_match_listing_variant(bigint), inv_listing_match(bigint, bigint, bigint, text), inv_propose_matches(bigint, bigint) TO inventory_rw;
GRANT EXECUTE ON FUNCTION inv_unmatched_listings(bigint) TO inventory_rw, inventory_records_ro;

-- ---------------------------------------------------------------------------------------------
-- 3. mcp_listings: db/015's columns verbatim, forgotten_at and forgotten_by appended last.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_listings WITH (security_barrier = true) AS
SELECT l.id AS listing_id, l.source_id, s.name AS source_name, s.role AS source_role, l.external_id, l.handle, l.url, l.title, l.vendor, l.product_type, l.tags,
       CASE WHEN (SELECT inv_has_right('listings.match')) THEN l.raw END AS raw, l.product_id, l.first_seen_at, l.last_seen_at, l.removed_at, l.created_at, l.updated_at,
       (SELECT count(*) FROM listing_variants lv WHERE lv.listing_id = l.id AND lv.removed_at IS NULL) AS variant_count,
       (SELECT count(*) FROM listing_variants lv WHERE lv.listing_id = l.id AND lv.removed_at IS NULL AND lv.variant_id IS NOT NULL) AS matched_count,
       l.forgotten_at, l.forgotten_by
  FROM listings l JOIN sources s ON s.id = l.source_id WHERE (SELECT inv_is_member_here());
GRANT SELECT ON mcp_listings TO inventory_records_ro, inventory_rw;

-- ---------------------------------------------------------------------------------------------
-- 4. The share.read logger for the read role: one row, the payload and nothing else.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION inv_log_share_read(p_tool text, p_consumer text, p_count integer, p_request_id text DEFAULT NULL) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE new_id bigint;
BEGIN
    IF p_tool IS NULL OR btrim(p_tool) = '' THEN RAISE EXCEPTION 'A share read names its tool' USING ERRCODE = 'check_violation'; END IF;
    INSERT INTO activity_log (actor_member_id, source, action, entity_type, after)
    VALUES (NULL, 'mcp', 'share.read', 'share', jsonb_build_object('tool', p_tool, 'consumer', p_consumer, 'count', p_count, 'request_id', p_request_id))
    RETURNING id INTO new_id;
    RETURN new_id;
END$$;
COMMENT ON FUNCTION inv_log_share_read(text, text, integer, text) IS 'The records server''s share.read row for a kernel call of a share (the read role has no INSERT on activity_log): tool, consumer, count, request_id — never the rows.';
REVOKE ALL ON FUNCTION inv_log_share_read(text, text, integer, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION inv_log_share_read(text, text, integer, text) TO inventory_records_ro, inventory_rw;

-- ---------------------------------------------------------------------------------------------
-- 5. mcp_settings: db/015's columns verbatim, business_address and raw_max_bytes appended last.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_settings WITH (security_barrier = true) AS
SELECT s.business_name, s.business_contact_email, s.business_phone, s.currency, s.units, s.timezone, s.sales_sees_cost, s.supplier_sees_phone, s.feed_shows_quantity,
       s.order_link_days, s.supplier_link_days, s.feed_rate_per_minute, s.feed_rate_per_day, s.key_rotation_overlap_hours, s.sizes, s.attribute_keys,
       s.reorder_point_default, s.reorder_qty_default, s.cost_source, s.cost_move_pct, s.reference_undercut_pct, s.ack_days, s.buyer_member_id,
       CASE WHEN (SELECT inv_has_right('sources.write')) THEN s.crawl_user_agent END AS crawl_user_agent, s.crawl_rate_per_second, s.crawl_backoff_minutes, s.crawl_max_pages,
       s.schedule_supplier_minutes, s.schedule_reference_minutes, s.schedule_jsonld_minutes, s.removed_after_pulls, s.snapshot_heartbeat_days, s.max_attachment_bytes, s.updated_at,
       s.business_address, s.raw_max_bytes
  FROM inv_settings s WHERE s.id = 1 AND (SELECT inv_is_member_here());
GRANT SELECT ON mcp_settings TO inventory_records_ro, inventory_rw;

COMMIT;

-- 009: SUPPLIERS and SOURCES — the parties the business orders from and the places it reads offers from: the source and its
-- sealed credential, every pull with the policy it followed, the templates, the listings and their variants, every offer
-- remembered as it changes (offer_snapshots), the matcher's six rules, proposals, and watches (design §6 "Suppliers and
-- sources", "Availability", §6.1, §6.2, §0.2, D6, D7, D8).
--
-- THE ESTATE'S DATA MODEL (design §6.3): `suppliers` is the Cidery's `app.suppliers` (db/004) — name, kind, contact_name,
-- email, phone, address, notes, active — unqualified here, the `kind` list WIDENED (the Cidery's values kept, ours added)
-- and the dealer-program columns APPENDED. `supplier_items` is recorded as a second shape under one family (the Cidery's
-- keys item_id, a material; ours keys variant_id and adds moq). Everything else is NEW and canonical for external catalogs
-- and offers (Knowledge's `sources` — an ingested document — is a recorded name collision).
--
-- THE REFEREE'S RULES: a credential's ciphertext is in no view (db/015 exposes id/source/kind/label/last4/rotated_at only);
-- a snapshot is written only when something changed, plus the daily heartbeat; a match never crosses sizes; a dismissed
-- proposal is remembered; an agent never accepts its own proposal (D6); a listing unseen for N pulls is marked removed and
-- its offer becomes unknown — the match stays; a source backs off an hour, a day, then pauses (D8).
BEGIN;

-- ---------------------------------------------------------------------------------------------
-- Suppliers (Cidery's shape; appended the dealer-program columns of §6).
-- ---------------------------------------------------------------------------------------------
CREATE TABLE suppliers (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name         text NOT NULL,
    kind         text NOT NULL DEFAULT 'vendor' CHECK (kind IN ('vendor','orchard','juice_supplier','packaging','other',
                                                                'manufacturer','distributor','wholesaler','marketplace')),   -- DECISION: widened, the Cidery's values kept
    contact_name text,
    email        text,
    phone        text,
    address      text,
    notes        text,
    active       boolean NOT NULL DEFAULT true,
    created_at   timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now(),
    -- appended (design §6): the dealer program
    website         text,
    account_number  text,                                                    -- the business's account with them (on the supplier's page)
    terms           text,
    dropships       boolean NOT NULL DEFAULT false,
    lead_time_days  integer CHECK (lead_time_days IS NULL OR lead_time_days >= 0),
    order_method    text NOT NULL DEFAULT 'email' CHECK (order_method IN ('email', 'portal', 'api', 'edi', 'phone')),
    order_email     citext,
    portal_url      text,
    min_order       numeric(12,2) CHECK (min_order IS NULL OR min_order >= 0)
);
CREATE UNIQUE INDEX suppliers_name_idx ON suppliers (lower(name)) WHERE active;
CREATE INDEX suppliers_name_trgm_idx ON suppliers USING gin (name gin_trgm_ops);
CREATE TRIGGER suppliers_touch BEFORE UPDATE ON suppliers FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

ALTER TABLE brands ADD CONSTRAINT brands_supplier_fk FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL;
CREATE INDEX brands_supplier_idx ON brands (supplier_id);
ALTER TABLE goods_receipts ADD CONSTRAINT goods_receipts_supplier_fk FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT;

-- The dealer's price sheet as a table; a feed pull updates it.
CREATE TABLE supplier_items (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    supplier_id     bigint NOT NULL REFERENCES suppliers(id) ON DELETE CASCADE,
    variant_id      bigint NOT NULL REFERENCES product_variants(id) ON DELETE CASCADE,
    supplier_sku    text,
    cost            numeric(12,2) CHECK (cost IS NULL OR cost >= 0),
    lead_time_days  integer CHECK (lead_time_days IS NULL OR lead_time_days >= 0),
    moq             integer NOT NULL DEFAULT 1 CHECK (moq >= 1),
    active          boolean NOT NULL DEFAULT true,
    last_seen_at    timestamptz,                                             -- the last feed pull that carried it
    source_id       bigint,                                                  -- the feed that keeps it (FK below)
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now(),
    UNIQUE (supplier_id, variant_id)
);
CREATE UNIQUE INDEX supplier_items_sku_idx ON supplier_items (supplier_id, lower(supplier_sku)) WHERE supplier_sku IS NOT NULL;
CREATE INDEX supplier_items_variant_idx ON supplier_items (variant_id);
CREATE TRIGGER supplier_items_touch BEFORE UPDATE ON supplier_items FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- ---------------------------------------------------------------------------------------------
-- Sources, their credentials, their pulls.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE sources (
    id                    bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name                  text NOT NULL,
    connector             text NOT NULL CHECK (connector = ANY (inv_connectors())),
    role                  text NOT NULL DEFAULT 'reference' CHECK (role IN ('supplier', 'reference')),
    supplier_id           bigint REFERENCES suppliers(id) ON DELETE SET NULL,
    base_url              text,
    settings              jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(settings) = 'object'),   -- per connector: collections, column mapping, sitemap, URL list, search terms, price list
    credential_id         bigint,                                              -- FK below (the credential belongs to the source)
    schedule_minutes      integer CHECK (schedule_minutes IS NULL OR schedule_minutes >= 0),   -- 0 = manual; NULL at insert = the setting for its role/connector
    rate_per_second       numeric(4,2) NOT NULL DEFAULT 1 CHECK (rate_per_second > 0 AND rate_per_second <= 10),
    user_agent            text,                                                -- override; NULL = the settings' crawler user-agent
    robots_state          text NOT NULL DEFAULT 'unknown' CHECK (robots_state IN ('ok', 'blocked', 'unknown')),
    robots_checked_at     timestamptz,
    last_pull_id          bigint,                                              -- FK below
    last_ok_at            timestamptz,
    consecutive_failures  integer NOT NULL DEFAULT 0 CHECK (consecutive_failures >= 0),
    backoff_until         timestamptz,                                         -- D8: the worker skips it until then
    paused_at             timestamptz,
    paused_reason         text,
    active                boolean NOT NULL DEFAULT true,
    created_by            bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at            timestamptz NOT NULL DEFAULT now(),
    updated_at            timestamptz NOT NULL DEFAULT now(),
    CHECK (role <> 'supplier' OR supplier_id IS NOT NULL)                      -- DECISION: a supplier source names its supplier (an order needs a party)
);
CREATE UNIQUE INDEX sources_name_idx ON sources (lower(name));
CREATE INDEX sources_supplier_idx ON sources (supplier_id);
CREATE INDEX sources_connector_idx ON sources (connector, active);
CREATE TRIGGER sources_touch BEFORE UPDATE ON sources FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
ALTER TABLE supplier_items ADD CONSTRAINT supplier_items_source_fk FOREIGN KEY (source_id) REFERENCES sources(id) ON DELETE SET NULL;
ALTER TABLE variant_identifiers ADD CONSTRAINT variant_identifiers_source_fk FOREIGN KEY (source_id) REFERENCES sources(id) ON DELETE CASCADE;
CREATE INDEX variant_identifiers_source_idx ON variant_identifiers (source_id) WHERE source_id IS NOT NULL;

-- Cadence defaults (D8): supplier sources every 30 minutes, references every 6 hours, jsonld daily, manual never.
CREATE OR REPLACE FUNCTION inv_sources_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NEW.schedule_minutes IS NULL THEN
        NEW.schedule_minutes := CASE
            WHEN NEW.connector = 'manual' THEN 0
            WHEN NEW.connector = 'jsonld' THEN inv_setting_int('schedule_jsonld_minutes')
            WHEN NEW.role = 'supplier' THEN inv_setting_int('schedule_supplier_minutes')
            ELSE inv_setting_int('schedule_reference_minutes') END;
    END IF;
    IF NEW.rate_per_second > (SELECT crawl_rate_per_second FROM inv_settings WHERE id = 1) THEN
        NEW.rate_per_second := (SELECT crawl_rate_per_second FROM inv_settings WHERE id = 1);   -- never faster than the policy
    END IF;
    IF NEW.credential_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM source_credentials c WHERE c.id = NEW.credential_id AND c.source_id = NEW.id) THEN
        RAISE EXCEPTION 'A credential belongs to its own source' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END$$;

CREATE TABLE source_credentials (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    source_id   bigint NOT NULL REFERENCES sources(id) ON DELETE CASCADE,
    kind        text NOT NULL CHECK (kind IN ('api_key', 'oauth_client', 'basic', 'sftp_password', 'sftp_key', 'rsa_signing', 'bearer')),
    label       text NOT NULL,                                                 -- "Storefront token (dealer)", "SFTP malouf-dealer"
    last4       text NOT NULL CHECK (length(last4) BETWEEN 1 AND 4),
    ciphertext  bytea NOT NULL,                                                -- libsodium secretbox under INV_SECRETS_KEY; decrypted in app/sources/credentials.php alone
    rotated_at  timestamptz,
    created_by  bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX source_credentials_source_idx ON source_credentials (source_id);
CREATE TRIGGER source_credentials_touch BEFORE UPDATE ON source_credentials FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
ALTER TABLE sources ADD CONSTRAINT sources_credential_fk FOREIGN KEY (credential_id) REFERENCES source_credentials(id) ON DELETE SET NULL;
CREATE TRIGGER sources_before BEFORE INSERT OR UPDATE ON sources FOR EACH ROW EXECUTE FUNCTION inv_sources_before();

CREATE TABLE source_pulls (
    id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    source_id         bigint NOT NULL REFERENCES sources(id) ON DELETE CASCADE,
    kind              text NOT NULL DEFAULT 'scheduled' CHECK (kind IN ('scheduled', 'manual', 'search', 'probe')),
    started_at        timestamptz NOT NULL DEFAULT now(),
    finished_at       timestamptz,
    status            text NOT NULL DEFAULT 'running' CHECK (status IN ('running', 'ok', 'partial', 'failed', 'blocked')),
    listings_seen     integer NOT NULL DEFAULT 0,
    listings_new      integer NOT NULL DEFAULT 0,
    listings_changed  integer NOT NULL DEFAULT 0,
    variants_changed  integer NOT NULL DEFAULT 0,
    listings_removed  integer NOT NULL DEFAULT 0,
    http_requests     integer NOT NULL DEFAULT 0,
    bytes             bigint NOT NULL DEFAULT 0,
    error             text CHECK (error IS NULL OR length(error) <= 200),
    policy            jsonb NOT NULL DEFAULT '{}'::jsonb,                      -- the robots and rate facts followed: {"robots":"ok","crawl_delay":1,"user_agent":"…","etag_hits":12}
    query             text,                                                    -- a search pull's query (120 chars at most)
    started_by        bigint REFERENCES members(id) ON DELETE SET NULL,
    CHECK (query IS NULL OR length(query) <= 120)
);
CREATE INDEX source_pulls_source_idx ON source_pulls (source_id, started_at DESC);
CREATE INDEX source_pulls_status_idx ON source_pulls (status, started_at DESC);
ALTER TABLE sources ADD CONSTRAINT sources_last_pull_fk FOREIGN KEY (last_pull_id) REFERENCES source_pulls(id) ON DELETE SET NULL;

-- Templates: the known stores and feeds, from Phase 0's survey. A person picks one and adds a credential.
CREATE TABLE source_templates (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    key            text NOT NULL UNIQUE CHECK (key ~ '^[a-z][a-z0-9_]{0,39}$'),
    name           text NOT NULL,
    connector      text NOT NULL CHECK (connector = ANY (inv_connectors())),
    role           text NOT NULL DEFAULT 'reference' CHECK (role IN ('supplier', 'reference')),
    base_url       text,
    settings       jsonb NOT NULL DEFAULT '{}'::jsonb,
    brand_hint     text,                                                       -- the brand this store sells (a brand row is made on adoption)
    notes          text,
    survey_result  text NOT NULL DEFAULT 'unverified' CHECK (survey_result IN ('open', 'blocked', 'not_platform', 'unverified')),
    surveyed_at    timestamptz,
    sort_order     integer NOT NULL DEFAULT 0,
    active         boolean NOT NULL DEFAULT true,
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER source_templates_touch BEFORE UPDATE ON source_templates FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
-- The shapes of §0.2, every store UNVERIFIED until the live survey (design §10, Phase 0's first proof) marks it open, blocked or not that platform.
INSERT INTO source_templates (key, name, connector, role, base_url, settings, brand_hint, notes, sort_order) VALUES
 ('casper',          'Casper (casper.com)',                   'shopify',     'reference', 'https://casper.com',             '{"endpoint":"/products.json","limit":250}', 'Casper',           'DTC brand site; the survey says whether products.json answers', 10),
 ('brooklynbedding', 'Brooklyn Bedding (brooklynbedding.com)','shopify',     'reference', 'https://brooklynbedding.com',    '{"endpoint":"/products.json","limit":250}', 'Brooklyn Bedding', 'Wholesale page offers drop-ship to dealers — a feed when the business is a dealer', 11),
 ('tuftandneedle',   'Tuft & Needle (tuftandneedle.com)',     'shopify',     'reference', 'https://www.tuftandneedle.com',  '{"endpoint":"/products.json","limit":250}', 'Tuft & Needle',    NULL, 12),
 ('avocado',         'Avocado (avocadogreenmattress.com)',    'shopify',     'reference', 'https://www.avocadogreenmattress.com', '{"endpoint":"/products.json","limit":250}', 'Avocado',    NULL, 13),
 ('bear',            'Bear (bearmattress.com)',               'shopify',     'reference', 'https://www.bearmattress.com',   '{"endpoint":"/products.json","limit":250}', 'Bear',             NULL, 14),
 ('maloufhome',      'Malouf Home (maloufhome.com)',          'shopify',     'reference', 'https://www.maloufhome.com',     '{"endpoint":"/products.json","limit":250}', 'Malouf',           'Malouf''s dealer program (WRC) publishes a dealer file — a feed source beside this reference', 15),
 ('zinus',           'Zinus (zinus.com)',                     'shopify',     'reference', 'https://www.zinus.com',          '{"endpoint":"/products.json","limit":250}', 'Zinus',            NULL, 16),
 ('lucid',           'Lucid (lucidmattress.com)',             'shopify',     'reference', 'https://www.lucidmattress.com',  '{"endpoint":"/products.json","limit":250}', 'Lucid',            NULL, 17),
 ('nolah',           'Nolah (nolahmattress.com)',             'shopify',     'reference', 'https://www.nolahmattress.com',  '{"endpoint":"/products.json","limit":250}', 'Nolah',            NULL, 18),
 ('nestbedding',     'Nest Bedding (nestbedding.com)',        'shopify',     'reference', 'https://www.nestbedding.com',    '{"endpoint":"/products.json","limit":250}', 'Nest Bedding',     NULL, 19),
 ('plushbeds',       'PlushBeds (plushbeds.com)',             'shopify',     'reference', 'https://www.plushbeds.com',      '{"endpoint":"/products.json","limit":250}', 'PlushBeds',        NULL, 20),
 ('naturepedic',     'Naturepedic (naturepedic.com)',         'shopify',     'reference', 'https://www.naturepedic.com',    '{"endpoint":"/products.json","limit":250}', 'Naturepedic',      NULL, 21),
 ('winkbeds',        'WinkBeds (winkbeds.com)',               'shopify',     'reference', 'https://www.winkbeds.com',       '{"endpoint":"/products.json","limit":250}', 'WinkBeds',         NULL, 22),
 ('layla',           'Layla (laylasleep.com)',                'shopify',     'reference', 'https://laylasleep.com',         '{"endpoint":"/products.json","limit":250}', 'Layla',            NULL, 23),
 ('woocommerce_store','A WooCommerce store (Store API)',      'woocommerce', 'reference', NULL, '{"endpoint":"/wp-json/wc/store/v1/products","per_page":100}', NULL, 'Any store on WooCommerce: set the base URL', 30),
 ('jsonld_site',     'Any site with schema.org Product markup','jsonld',     'reference', NULL, '{"sitemap":null,"urls":[],"daily":true}', NULL, 'The product sitemap or a pasted list of URLs; one page per product, daily', 31),
 ('dealer_feed_csv', 'A supplier''s inventory file (CSV / XLSX)','feed',     'supplier',  NULL, '{"transport":"https","mapping":{"supplier_sku":null,"gtin":null,"name":null,"size":null,"cost":null,"qty":null,"in_stock":null,"lead_time_days":null,"map":null}}', NULL, 'HTTPS URL or SFTP; map the columns once', 32),
 ('price_sheet',     'A price sheet typed in',                'manual',      'supplier',  NULL, '{}', NULL, 'A phone call or a PDF: listings and offers typed in with a date', 33);

-- ---------------------------------------------------------------------------------------------
-- Listings and their variants: what a source says, trimmed; `raw` is never in a log.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE listings (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    source_id      bigint NOT NULL REFERENCES sources(id) ON DELETE CASCADE,
    external_id    text NOT NULL,
    handle         text,
    url            text,
    title          text NOT NULL,
    vendor         text,
    product_type   text,
    tags           text[] NOT NULL DEFAULT '{}',
    raw            jsonb NOT NULL DEFAULT '{}'::jsonb,                          -- the fields the connector read, never the whole page
    product_id     bigint REFERENCES products(id) ON DELETE SET NULL,          -- a product-level match
    first_seen_at  timestamptz NOT NULL DEFAULT now(),
    last_seen_at   timestamptz NOT NULL DEFAULT now(),
    removed_at     timestamptz,
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now(),
    UNIQUE (source_id, external_id)
);
CREATE INDEX listings_source_idx ON listings (source_id, last_seen_at DESC);
CREATE INDEX listings_product_idx ON listings (product_id);
CREATE INDEX listings_title_trgm_idx ON listings USING gin (title gin_trgm_ops);
CREATE INDEX listings_live_idx ON listings (source_id) WHERE removed_at IS NULL;
CREATE TRIGGER listings_touch BEFORE UPDATE ON listings FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE listing_variants (
    id                   bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    listing_id           bigint NOT NULL REFERENCES listings(id) ON DELETE CASCADE,
    external_variant_id  text NOT NULL,
    title                text,
    option_values        jsonb NOT NULL DEFAULT '{}'::jsonb,
    size_key             text,                                                 -- by trigger from the Size option (or the title)
    sku                  text,
    barcode              text,                                                 -- GTIN-14 when valid; the raw text otherwise
    barcode_valid        boolean NOT NULL DEFAULT false,
    mpn                  text,
    variant_id           bigint REFERENCES product_variants(id) ON DELETE SET NULL,   -- the match
    match_kind           text CHECK (match_kind IS NULL OR match_kind IN ('gtin', 'sku', 'mpn', 'supplier_sku', 'marketplace_id', 'manual', 'proposed_accepted')),
    match_confidence     numeric(4,3) CHECK (match_confidence IS NULL OR (match_confidence >= 0 AND match_confidence <= 1)),
    matched_by           bigint REFERENCES members(id) ON DELETE SET NULL,     -- NULL with a match = the matcher
    matched_at           timestamptz,
    price                numeric(12,2) CHECK (price IS NULL OR price >= 0),
    compare_at_price     numeric(12,2) CHECK (compare_at_price IS NULL OR compare_at_price >= 0),
    currency             char(3) CHECK (currency IS NULL OR currency ~ '^[A-Z]{3}$'),
    cost_price           numeric(12,2) CHECK (cost_price IS NULL OR cost_price >= 0),   -- feeds and price sheets only
    availability         text NOT NULL DEFAULT 'unknown' CHECK (availability = ANY (inv_availability_states())),
    qty                  integer CHECK (qty IS NULL OR qty >= 0),
    lead_time_days       integer CHECK (lead_time_days IS NULL OR lead_time_days >= 0),
    ships_how            text CHECK (ships_how IS NULL OR ships_how = ANY (inv_ships_how_kinds())),
    url                  text,
    first_seen_at        timestamptz NOT NULL DEFAULT now(),
    last_seen_at         timestamptz NOT NULL DEFAULT now(),
    removed_at           timestamptz,
    created_at           timestamptz NOT NULL DEFAULT now(),
    updated_at           timestamptz NOT NULL DEFAULT now(),
    UNIQUE (listing_id, external_variant_id),
    CHECK ((variant_id IS NULL) = (match_kind IS NULL))
);
CREATE INDEX listing_variants_listing_idx ON listing_variants (listing_id);
CREATE INDEX listing_variants_variant_idx ON listing_variants (variant_id) WHERE variant_id IS NOT NULL;
CREATE INDEX listing_variants_barcode_idx ON listing_variants (barcode) WHERE barcode_valid;
CREATE INDEX listing_variants_sku_idx ON listing_variants (lower(sku)) WHERE sku IS NOT NULL;
CREATE INDEX listing_variants_mpn_idx ON listing_variants (lower(mpn)) WHERE mpn IS NOT NULL;
CREATE INDEX listing_variants_unmatched_idx ON listing_variants (listing_id) WHERE variant_id IS NULL AND removed_at IS NULL;
CREATE TRIGGER listing_variants_touch BEFORE UPDATE ON listing_variants FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE OR REPLACE FUNCTION inv_listing_variants_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE g text; sz text;
BEGIN
    sz := inv_option_size(NEW.option_values);
    IF sz IS NULL AND NEW.title IS NOT NULL THEN sz := NEW.title; END IF;      -- Shopify's variant title is the option value ("Queen")
    NEW.size_key := CASE WHEN inv_option_size(NEW.option_values) IS NULL AND NEW.title IS NOT NULL
                         THEN (SELECT s->>'key' FROM inv_settings st, jsonb_array_elements(st.sizes) s WHERE st.id = 1
                                 AND (lower(NEW.title) = lower(s->>'name') OR EXISTS (SELECT 1 FROM jsonb_array_elements_text(s->'synonyms') y WHERE lower(y) = lower(btrim(NEW.title)))) LIMIT 1)
                         ELSE inv_size_key(sz) END;
    IF NEW.barcode IS NOT NULL THEN
        g := inv_gtin14(NEW.barcode);
        NEW.barcode_valid := g IS NOT NULL;
        NEW.barcode := COALESCE(g, btrim(NEW.barcode));
    ELSE
        NEW.barcode_valid := false;
    END IF;
    NEW.sku := NULLIF(btrim(COALESCE(NEW.sku, '')), '');
    NEW.mpn := NULLIF(btrim(COALESCE(NEW.mpn, '')), '');
    IF NEW.availability IS NULL OR NOT (NEW.availability = ANY (inv_availability_states())) THEN NEW.availability := 'unknown'; END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER listing_variants_before BEFORE INSERT OR UPDATE ON listing_variants FOR EACH ROW EXECUTE FUNCTION inv_listing_variants_before();

-- ---------------------------------------------------------------------------------------------
-- Offers remembered: a snapshot when anything changed, and once a day regardless (the heartbeat).
-- ---------------------------------------------------------------------------------------------
CREATE TABLE offer_snapshots (
    id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    listing_variant_id  bigint NOT NULL REFERENCES listing_variants(id) ON DELETE CASCADE,
    pull_id             bigint REFERENCES source_pulls(id) ON DELETE SET NULL,
    observed_at         timestamptz NOT NULL DEFAULT now(),
    price               numeric(12,2),
    compare_at_price    numeric(12,2),
    cost_price          numeric(12,2),
    availability        text NOT NULL CHECK (availability = ANY (inv_availability_states())),
    qty                 integer,
    lead_time_days      integer,
    is_heartbeat        boolean NOT NULL DEFAULT false
);
CREATE INDEX offer_snapshots_lv_idx ON offer_snapshots (listing_variant_id, observed_at DESC);
CREATE INDEX offer_snapshots_time_idx ON offer_snapshots (observed_at DESC);

-- True when a snapshot was written (something changed, or none yet, or forced).
CREATE OR REPLACE FUNCTION inv_record_offer(p_lv_id bigint, p_pull_id bigint DEFAULT NULL, p_force boolean DEFAULT false) RETURNS boolean
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE lv listing_variants%ROWTYPE; last offer_snapshots%ROWTYPE; changed boolean;
BEGIN
    SELECT * INTO lv FROM listing_variants WHERE id = p_lv_id;
    IF NOT FOUND THEN RETURN false; END IF;
    SELECT * INTO last FROM offer_snapshots WHERE listing_variant_id = p_lv_id ORDER BY observed_at DESC, id DESC LIMIT 1;
    changed := NOT FOUND
        OR last.price IS DISTINCT FROM lv.price OR last.compare_at_price IS DISTINCT FROM lv.compare_at_price
        OR last.cost_price IS DISTINCT FROM lv.cost_price OR last.availability IS DISTINCT FROM lv.availability
        OR last.qty IS DISTINCT FROM lv.qty OR last.lead_time_days IS DISTINCT FROM lv.lead_time_days;
    IF changed OR p_force THEN
        INSERT INTO offer_snapshots (listing_variant_id, pull_id, price, compare_at_price, cost_price, availability, qty, lead_time_days, is_heartbeat)
        VALUES (p_lv_id, p_pull_id, lv.price, lv.compare_at_price, lv.cost_price, lv.availability, lv.qty, lv.lead_time_days, p_force AND NOT changed);
        RETURN true;
    END IF;
    RETURN false;
END$$;

-- The heartbeat (the worker, once a day): a point for every live offer whose last snapshot is older than the setting.
CREATE OR REPLACE FUNCTION inv_snapshot_heartbeat() RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer := 0; r record; days integer := inv_setting_int('snapshot_heartbeat_days');
BEGIN
    FOR r IN SELECT lv.id FROM listing_variants lv
              WHERE lv.removed_at IS NULL
                AND NOT EXISTS (SELECT 1 FROM offer_snapshots s WHERE s.listing_variant_id = lv.id AND s.observed_at > now() - make_interval(days => days))
    LOOP
        PERFORM inv_record_offer(r.id, NULL, true);
        n := n + 1;
    END LOOP;
    RETURN n;
END$$;

-- ---------------------------------------------------------------------------------------------
-- Matching (§6.2). Rules 1–4 are the matcher's own; 5 a person's; 6 an accepted proposal. Never across sizes.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION inv_sizes_agree(a text, b text) RETURNS boolean
    LANGUAGE sql IMMUTABLE AS $$ SELECT a IS NULL OR b IS NULL OR a = b $$;

CREATE OR REPLACE FUNCTION inv_set_match(p_lv_id bigint, p_variant_id bigint, p_kind text, p_confidence numeric, p_by bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE pid bigint; lid bigint;
BEGIN
    SELECT product_id INTO pid FROM product_variants WHERE id = p_variant_id;
    UPDATE listing_variants SET variant_id = p_variant_id, match_kind = p_kind, match_confidence = p_confidence, matched_by = p_by, matched_at = now()
     WHERE id = p_lv_id RETURNING listing_id INTO lid;
    UPDATE listings SET product_id = COALESCE(product_id, pid) WHERE id = lid;
    -- a proposal for this pair that was pending is now moot
    UPDATE match_proposals SET status = 'accepted', decided_by = p_by, decided_at = now()
     WHERE listing_variant_id = p_lv_id AND variant_id = p_variant_id AND status = 'proposed' AND p_kind = 'proposed_accepted';
END$$;

-- Rules 1–4 in order; returns the match_kind applied, or NULL. A cross-size candidate is skipped, never taken.
CREATE OR REPLACE FUNCTION inv_match_listing_variant(p_lv_id bigint) RETURNS text
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE lv listing_variants%ROWTYPE; l listings%ROWTYPE; s sources%ROWTYPE; vid bigint;
BEGIN
    SELECT * INTO lv FROM listing_variants WHERE id = p_lv_id;
    IF NOT FOUND OR lv.variant_id IS NOT NULL THEN RETURN lv.match_kind; END IF;
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
-- identifier in hand (the screen checks); here the rule enforced is the size wall.
CREATE OR REPLACE FUNCTION inv_listing_match(p_lv_id bigint, p_variant_id bigint, p_by bigint, p_kind text DEFAULT 'manual') RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE lsize text; vsize text; ok boolean;
BEGIN
    IF p_kind NOT IN ('manual', 'proposed_accepted') THEN RAISE EXCEPTION 'A person''s match is manual or an accepted proposal' USING ERRCODE = 'check_violation'; END IF;
    SELECT size_key INTO lsize FROM listing_variants WHERE id = p_lv_id;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such listing variant' USING ERRCODE = 'no_data_found'; END IF;
    SELECT size_key, active INTO vsize, ok FROM product_variants WHERE id = p_variant_id;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such variant' USING ERRCODE = 'no_data_found'; END IF;
    IF NOT inv_sizes_agree(lsize, vsize) THEN
        RAISE EXCEPTION 'A match never crosses sizes: the listing is %, the variant is %', lsize, vsize USING ERRCODE = 'check_violation';
    END IF;
    PERFORM inv_set_match(p_lv_id, p_variant_id, p_kind, CASE p_kind WHEN 'manual' THEN 1.000 ELSE NULL END, p_by);
END$$;

CREATE OR REPLACE FUNCTION inv_listing_unmatch(p_lv_id bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    UPDATE listing_variants SET variant_id = NULL, match_kind = NULL, match_confidence = NULL, matched_by = NULL, matched_at = NULL WHERE id = p_lv_id;
END$$;

-- Proposals (6): the matcher's scoring, or an agent's with evidence; a person accepts or dismisses; a dismissal is remembered.
CREATE TABLE match_proposals (
    id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    listing_variant_id  bigint NOT NULL REFERENCES listing_variants(id) ON DELETE CASCADE,
    variant_id          bigint NOT NULL REFERENCES product_variants(id) ON DELETE CASCADE,
    confidence          numeric(4,3) NOT NULL CHECK (confidence >= 0 AND confidence <= 1),
    evidence            jsonb NOT NULL DEFAULT '{}'::jsonb,                   -- {"brand":true,"name_tokens":["purple","hybrid"],"size":true,"dims_mm":12,"type":true}
    proposed_by         bigint REFERENCES members(id) ON DELETE SET NULL,    -- NULL = the matcher; an agent's member id otherwise
    status              text NOT NULL DEFAULT 'proposed' CHECK (status IN ('proposed', 'accepted', 'dismissed')),
    decided_by          bigint REFERENCES members(id) ON DELETE SET NULL,
    decided_at          timestamptz,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX match_proposals_pair_idx ON match_proposals (listing_variant_id, variant_id);   -- one row per pair: proposed, then accepted or dismissed (remembered)
CREATE INDEX match_proposals_open_idx ON match_proposals (status, created_at DESC) WHERE status = 'proposed';
CREATE TRIGGER match_proposals_touch BEFORE UPDATE ON match_proposals FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE OR REPLACE FUNCTION inv_name_tokens(p text) RETURNS text[]
    LANGUAGE sql IMMUTABLE AS $$
    SELECT COALESCE(array_agg(DISTINCT t), '{}') FROM unnest(regexp_split_to_array(lower(unaccent(COALESCE(p, ''))), '[^a-z0-9]+')) t
     WHERE length(t) >= 3 AND t NOT IN ('the', 'and', 'with', 'for', 'mattress', 'size', 'inch', 'bed');
$$;

-- Score every active variant against one unmatched listing variant; write a proposal for each candidate at or above 0.5,
-- unless the pair is already proposed, accepted or dismissed. Returns how many were written.
CREATE OR REPLACE FUNCTION inv_propose_matches(p_lv_id bigint, p_by bigint DEFAULT NULL) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE lv listing_variants%ROWTYPE; l listings%ROWTYPE; c record; n integer := 0; ltok text[]; score numeric; ev jsonb; shared text[]; frac numeric; dims integer;
BEGIN
    SELECT * INTO lv FROM listing_variants WHERE id = p_lv_id;
    IF NOT FOUND OR lv.variant_id IS NOT NULL THEN RETURN 0; END IF;
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

-- Accept: the match is made as proposed_accepted with the evidence kept on the proposal. D6: an agent never accepts its
-- own proposal — nor the matcher's or another agent's: an agent may accept only what a PERSON proposed.
CREATE OR REPLACE FUNCTION inv_proposal_accept(p_proposal_id bigint, p_by bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE mp match_proposals%ROWTYPE; by_kind text; proposer_kind text;
BEGIN
    SELECT * INTO mp FROM match_proposals WHERE id = p_proposal_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such proposal' USING ERRCODE = 'no_data_found'; END IF;
    IF mp.status <> 'proposed' THEN RAISE EXCEPTION 'That proposal was already %', mp.status USING ERRCODE = 'check_violation'; END IF;
    SELECT member_kind INTO by_kind FROM members WHERE id = p_by;
    SELECT member_kind INTO proposer_kind FROM members WHERE id = mp.proposed_by;
    IF by_kind = 'agent' AND (mp.proposed_by IS NULL OR proposer_kind <> 'human') THEN
        RAISE EXCEPTION 'An agent never accepts its own, another agent''s or the matcher''s proposal — a person does' USING ERRCODE = 'insufficient_privilege';
    END IF;
    PERFORM inv_listing_match(mp.listing_variant_id, mp.variant_id, p_by, 'proposed_accepted');
    UPDATE match_proposals SET status = 'accepted', decided_by = p_by, decided_at = now() WHERE id = p_proposal_id;
    UPDATE match_proposals SET status = 'dismissed', decided_by = p_by, decided_at = now()
     WHERE listing_variant_id = mp.listing_variant_id AND status = 'proposed' AND id <> p_proposal_id;
END$$;

CREATE OR REPLACE FUNCTION inv_proposal_dismiss(p_proposal_id bigint, p_by bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    UPDATE match_proposals SET status = 'dismissed', decided_by = p_by, decided_at = now() WHERE id = p_proposal_id AND status = 'proposed';
    IF NOT FOUND THEN RAISE EXCEPTION 'Only a pending proposal is dismissed' USING ERRCODE = 'check_violation'; END IF;
END$$;

-- ---------------------------------------------------------------------------------------------
-- The worker's writes: a pull's start and finish (with the back-off ladder), the upsert of a normalized listing (§6.1),
-- and the removal of what two pulls have not seen.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION inv_source_pull_start(p_source_id bigint, p_kind text DEFAULT 'scheduled', p_by bigint DEFAULT NULL, p_query text DEFAULT NULL) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE s sources%ROWTYPE; pid bigint;
BEGIN
    SELECT * INTO s FROM sources WHERE id = p_source_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such source' USING ERRCODE = 'no_data_found'; END IF;
    IF NOT s.active THEN RAISE EXCEPTION 'Source % is inactive', s.name USING ERRCODE = 'check_violation'; END IF;
    IF s.paused_at IS NOT NULL AND p_kind = 'scheduled' THEN RAISE EXCEPTION 'Source % is paused: %', s.name, s.paused_reason USING ERRCODE = 'check_violation'; END IF;
    IF s.backoff_until IS NOT NULL AND s.backoff_until > now() AND p_kind = 'scheduled' THEN
        RAISE EXCEPTION 'Source % is backing off until %', s.name, s.backoff_until USING ERRCODE = 'check_violation';
    END IF;
    IF EXISTS (SELECT 1 FROM source_pulls WHERE source_id = p_source_id AND status = 'running' AND started_at > now() - interval '6 hours') THEN
        RAISE EXCEPTION 'A pull of % is already running' , s.name USING ERRCODE = 'check_violation';
    END IF;
    INSERT INTO source_pulls (source_id, kind, started_by, query) VALUES (p_source_id, p_kind, p_by, left(p_query, 120)) RETURNING id INTO pid;
    UPDATE sources SET last_pull_id = pid WHERE id = p_source_id;
    RETURN pid;
END$$;

CREATE OR REPLACE FUNCTION inv_source_pull_finish(p_pull_id bigint, p_status text, p_error text DEFAULT NULL, p_policy jsonb DEFAULT NULL,
                                                  p_http_requests integer DEFAULT NULL, p_bytes bigint DEFAULT NULL) RETURNS sources
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE p source_pulls%ROWTYPE; s sources%ROWTYPE; ladder integer[]; n integer;
BEGIN
    IF p_status NOT IN ('ok', 'partial', 'failed', 'blocked') THEN RAISE EXCEPTION 'A pull finishes ok, partial, failed or blocked' USING ERRCODE = 'check_violation'; END IF;
    UPDATE source_pulls SET status = p_status, finished_at = now(), error = left(p_error, 200), policy = COALESCE(p_policy, policy),
                            http_requests = COALESCE(p_http_requests, http_requests), bytes = COALESCE(p_bytes, bytes)
     WHERE id = p_pull_id RETURNING * INTO p;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such pull' USING ERRCODE = 'no_data_found'; END IF;
    SELECT * INTO s FROM sources WHERE id = p.source_id FOR UPDATE;
    IF p_status IN ('ok', 'partial') THEN
        UPDATE sources SET last_ok_at = now(), consecutive_failures = 0, backoff_until = NULL,
                           robots_state = CASE WHEN p_policy ? 'robots' THEN COALESCE(NULLIF(p_policy->>'robots', ''), robots_state) ELSE robots_state END,
                           robots_checked_at = CASE WHEN p_policy ? 'robots' THEN now() ELSE robots_checked_at END
         WHERE id = s.id RETURNING * INTO s;
    ELSE
        ladder := (SELECT crawl_backoff_minutes FROM inv_settings WHERE id = 1);
        n := s.consecutive_failures + 1;
        IF n <= cardinality(ladder) THEN
            UPDATE sources SET consecutive_failures = n, backoff_until = now() + make_interval(mins => ladder[n]),
                               robots_state = CASE WHEN p_status = 'blocked' THEN 'blocked' ELSE robots_state END, robots_checked_at = CASE WHEN p_status = 'blocked' THEN now() ELSE robots_checked_at END
             WHERE id = s.id RETURNING * INTO s;
        ELSE
            UPDATE sources SET consecutive_failures = n, backoff_until = NULL, paused_at = now(),
                               paused_reason = format('%s failures in a row (last: %s)', n, COALESCE(left(p_error, 120), p_status)),
                               robots_state = CASE WHEN p_status = 'blocked' THEN 'blocked' ELSE robots_state END
             WHERE id = s.id RETURNING * INTO s;
        END IF;
    END IF;
    RETURN s;
END$$;

CREATE OR REPLACE FUNCTION inv_source_resume(p_source_id bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    -- DECISION: a person resuming a blocked source clears the block to `unknown`; the next pull's robots check says ok or blocked again
    UPDATE sources SET paused_at = NULL, paused_reason = NULL, consecutive_failures = 0, backoff_until = NULL,
                       robots_state = CASE WHEN robots_state = 'blocked' THEN 'unknown' ELSE robots_state END WHERE id = p_source_id;
END$$;

-- One normalized listing (§6.1) → the listing row, its variants, a snapshot where something changed, the matcher on each
-- unmatched variant, the supplier's price sheet when the source is a supplier's feed. One call = one listing; the worker
-- wraps each call in its own transaction so a failure mid-pull keeps what was read. Returns the listing id.
CREATE OR REPLACE FUNCTION inv_upsert_listing(p_source_id bigint, p_pull_id bigint, p jsonb) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE s sources%ROWTYPE; lid bigint; is_new boolean; was_changed boolean := false; v jsonb; lvid bigint; lv_new boolean; vchanged integer := 0;
        raw_max integer := inv_setting_int('raw_max_bytes'); old_title text; old_vendor text;
BEGIN
    SELECT * INTO s FROM sources WHERE id = p_source_id;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such source' USING ERRCODE = 'no_data_found'; END IF;
    IF p->>'external_id' IS NULL OR p->>'title' IS NULL THEN RAISE EXCEPTION 'A listing has an external_id and a title' USING ERRCODE = 'check_violation'; END IF;
    SELECT id, title, vendor INTO lid, old_title, old_vendor FROM listings WHERE source_id = p_source_id AND external_id = p->>'external_id';
    is_new := lid IS NULL;
    IF is_new THEN
        INSERT INTO listings (source_id, external_id, handle, url, title, vendor, product_type, tags, raw)
        VALUES (p_source_id, p->>'external_id', p->>'handle', p->>'url', p->>'title', p->>'vendor', p->>'product_type',
                COALESCE((SELECT array_agg(x) FROM jsonb_array_elements_text(CASE WHEN jsonb_typeof(p->'tags') = 'array' THEN p->'tags' ELSE '[]'::jsonb END) x), '{}'),
                CASE WHEN length(COALESCE(p->'raw', '{}'::jsonb)::text) > raw_max THEN jsonb_build_object('_trimmed', true) ELSE COALESCE(p->'raw', '{}'::jsonb) END)
        RETURNING id INTO lid;
    ELSE
        was_changed := old_title IS DISTINCT FROM (p->>'title') OR old_vendor IS DISTINCT FROM (p->>'vendor');
        UPDATE listings SET handle = COALESCE(p->>'handle', handle), url = COALESCE(p->>'url', url), title = p->>'title', vendor = COALESCE(p->>'vendor', vendor),
                            product_type = COALESCE(p->>'product_type', product_type),
                            tags = COALESCE((SELECT array_agg(x) FROM jsonb_array_elements_text(CASE WHEN jsonb_typeof(p->'tags') = 'array' THEN p->'tags' ELSE NULL END) x), tags),
                            raw = CASE WHEN p ? 'raw' THEN (CASE WHEN length((p->'raw')::text) > raw_max THEN jsonb_build_object('_trimmed', true) ELSE p->'raw' END) ELSE raw END,
                            last_seen_at = now(), removed_at = NULL
         WHERE id = lid;
    END IF;
    FOR v IN SELECT * FROM jsonb_array_elements(CASE WHEN jsonb_typeof(p->'variants') = 'array' THEN p->'variants' ELSE '[]'::jsonb END) LOOP
        SELECT id INTO lvid FROM listing_variants WHERE listing_id = lid AND external_variant_id = COALESCE(v->>'external_variant_id', p->>'external_id');
        lv_new := lvid IS NULL;
        IF lv_new THEN
            INSERT INTO listing_variants (listing_id, external_variant_id, title, option_values, sku, barcode, mpn, price, compare_at_price, currency, cost_price,
                                          availability, qty, lead_time_days, ships_how, url)
            VALUES (lid, COALESCE(v->>'external_variant_id', p->>'external_id'), v->>'title',
                    CASE WHEN jsonb_typeof(v->'option_values') = 'object' THEN v->'option_values' ELSE '{}'::jsonb END,
                    v->>'sku', v->>'barcode', v->>'mpn', (v->>'price')::numeric, (v->>'compare_at_price')::numeric, upper(v->>'currency'), (v->>'cost_price')::numeric,
                    COALESCE(v->>'availability', 'unknown'), (v->>'qty')::integer, (v->>'lead_time_days')::integer, v->>'ships_how', v->>'url')
            RETURNING id INTO lvid;
        ELSE
            UPDATE listing_variants SET title = COALESCE(v->>'title', title),
                                        option_values = CASE WHEN jsonb_typeof(v->'option_values') = 'object' THEN v->'option_values' ELSE option_values END,
                                        sku = COALESCE(v->>'sku', sku), barcode = COALESCE(v->>'barcode', barcode), mpn = COALESCE(v->>'mpn', mpn),
                                        price = (v->>'price')::numeric, compare_at_price = (v->>'compare_at_price')::numeric, currency = COALESCE(upper(v->>'currency'), currency),
                                        cost_price = CASE WHEN v ? 'cost_price' THEN (v->>'cost_price')::numeric ELSE cost_price END,
                                        availability = COALESCE(v->>'availability', 'unknown'), qty = (v->>'qty')::integer, lead_time_days = (v->>'lead_time_days')::integer,
                                        ships_how = COALESCE(v->>'ships_how', ships_how), url = COALESCE(v->>'url', url),
                                        last_seen_at = now(), removed_at = NULL
             WHERE id = lvid;
        END IF;
        IF inv_record_offer(lvid, p_pull_id) AND NOT lv_new THEN vchanged := vchanged + 1; END IF;
        PERFORM inv_match_listing_variant(lvid);
        -- a supplier's feed keeps the price sheet
        IF s.supplier_id IS NOT NULL AND v ? 'cost_price' THEN
            INSERT INTO supplier_items (supplier_id, variant_id, supplier_sku, cost, lead_time_days, last_seen_at, source_id)
            SELECT s.supplier_id, lv.variant_id, lv.sku, lv.cost_price, lv.lead_time_days, now(), s.id FROM listing_variants lv WHERE lv.id = lvid AND lv.variant_id IS NOT NULL
            ON CONFLICT (supplier_id, variant_id) DO UPDATE SET supplier_sku = COALESCE(EXCLUDED.supplier_sku, supplier_items.supplier_sku),
                cost = COALESCE(EXCLUDED.cost, supplier_items.cost), lead_time_days = COALESCE(EXCLUDED.lead_time_days, supplier_items.lead_time_days),
                last_seen_at = now(), source_id = EXCLUDED.source_id, active = true;
        END IF;
    END LOOP;
    IF p_pull_id IS NOT NULL THEN
        UPDATE source_pulls SET listings_seen = listings_seen + 1, listings_new = listings_new + CASE WHEN is_new THEN 1 ELSE 0 END,
                                listings_changed = listings_changed + CASE WHEN was_changed OR vchanged > 0 THEN 1 ELSE 0 END,
                                variants_changed = variants_changed + vchanged
         WHERE id = p_pull_id;
    END IF;
    RETURN lid;
END$$;

-- After a full pull: a listing not seen in the last N completed pulls of the source is removed (its offers become
-- unknown — a snapshot says so); the match stays. Returns how many listings were marked.
CREATE OR REPLACE FUNCTION inv_mark_removed(p_source_id bigint, p_pull_id bigint DEFAULT NULL) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer := inv_setting_int('removed_after_pulls'); threshold timestamptz; r record; k integer := 0;
BEGIN
    SELECT started_at INTO threshold FROM source_pulls
     WHERE source_id = p_source_id AND kind IN ('scheduled', 'manual') AND status IN ('ok', 'partial', 'running')
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
-- Watches (design §6 "Availability"): a member's or an agent's; fires once per state change (db/013 inv_fire_watches,
-- which needs the notification tables) — here the table and the evaluation of one watch's state.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE watches (
    id                   bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    member_id            bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,    -- who set it (a person or an agent)
    agent_member_id      bigint REFERENCES members(id) ON DELETE SET NULL,            -- an agent to dispatch when it fires (§5 d)
    kind                 text NOT NULL CHECK (kind IN ('back_in_stock', 'price_below', 'cost_below', 'map_breach', 'lead_time_over', 'removed')),
    variant_id           bigint REFERENCES product_variants(id) ON DELETE CASCADE,
    listing_variant_id   bigint REFERENCES listing_variants(id) ON DELETE CASCADE,
    product_id           bigint REFERENCES products(id) ON DELETE CASCADE,
    threshold            numeric(12,2),
    text_me              boolean NOT NULL DEFAULT false,                              -- K6: a text to the member when it fires
    last_state           boolean,                                                     -- the condition as last evaluated
    fired_at             timestamptz,
    fire_count           integer NOT NULL DEFAULT 0,
    active               boolean NOT NULL DEFAULT true,
    note                 text,
    created_at           timestamptz NOT NULL DEFAULT now(),
    updated_at           timestamptz NOT NULL DEFAULT now(),
    CHECK ((variant_id IS NOT NULL)::integer + (listing_variant_id IS NOT NULL)::integer + (product_id IS NOT NULL)::integer = 1),
    CHECK (kind NOT IN ('price_below', 'cost_below', 'lead_time_over') OR threshold IS NOT NULL)
);
CREATE INDEX watches_member_idx ON watches (member_id) WHERE active;
CREATE INDEX watches_variant_idx ON watches (variant_id) WHERE active;
CREATE INDEX watches_lv_idx ON watches (listing_variant_id) WHERE active;
CREATE TRIGGER watches_touch BEFORE UPDATE ON watches FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- The live listing variants a watch looks at: the one named, the ones matched to the variant, or to any variant of the product.
CREATE OR REPLACE FUNCTION inv_watch_targets(p_watch_id bigint) RETURNS SETOF listing_variants
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT lv.* FROM watches w JOIN listing_variants lv
      ON (w.listing_variant_id = lv.id)
      OR (w.variant_id IS NOT NULL AND lv.variant_id = w.variant_id)
      OR (w.product_id IS NOT NULL AND lv.variant_id IN (SELECT id FROM product_variants WHERE product_id = w.product_id))
     WHERE w.id = p_watch_id;
$$;

CREATE OR REPLACE FUNCTION inv_watch_state(p_watch_id bigint) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE w watches%ROWTYPE; st boolean := false;
BEGIN
    SELECT * INTO w FROM watches WHERE id = p_watch_id;
    IF NOT FOUND THEN RETURN NULL; END IF;
    CASE w.kind
        WHEN 'back_in_stock' THEN
            st := EXISTS (SELECT 1 FROM inv_watch_targets(w.id) t JOIN listings l ON l.id = t.listing_id JOIN sources s ON s.id = l.source_id
                           WHERE t.removed_at IS NULL AND t.availability = 'in_stock' AND (w.listing_variant_id IS NOT NULL OR s.role = 'supplier'))
               OR (w.variant_id IS NOT NULL AND EXISTS (SELECT 1 FROM inventory_balances b WHERE b.variant_id = w.variant_id AND b.qty_on_hand - b.qty_allocated - b.qty_floor_model > 0))
               OR (w.product_id IS NOT NULL AND EXISTS (SELECT 1 FROM inventory_balances b JOIN product_variants v ON v.id = b.variant_id WHERE v.product_id = w.product_id AND b.qty_on_hand - b.qty_allocated - b.qty_floor_model > 0));
        WHEN 'price_below' THEN
            st := EXISTS (SELECT 1 FROM inv_watch_targets(w.id) t WHERE t.removed_at IS NULL AND t.price IS NOT NULL AND t.price < w.threshold);
        WHEN 'cost_below' THEN
            st := EXISTS (SELECT 1 FROM inv_watch_targets(w.id) t WHERE t.removed_at IS NULL AND t.cost_price IS NOT NULL AND t.cost_price < w.threshold)
               OR (w.variant_id IS NOT NULL AND EXISTS (SELECT 1 FROM supplier_items si WHERE si.variant_id = w.variant_id AND si.active AND si.cost < w.threshold));
        WHEN 'map_breach' THEN
            st := EXISTS (SELECT 1 FROM inv_watch_targets(w.id) t JOIN product_variants v ON v.id = t.variant_id
                           WHERE t.removed_at IS NULL AND v.map_price IS NOT NULL AND t.price IS NOT NULL AND t.price < v.map_price);
        WHEN 'lead_time_over' THEN
            st := EXISTS (SELECT 1 FROM inv_watch_targets(w.id) t WHERE t.removed_at IS NULL AND t.lead_time_days IS NOT NULL AND t.lead_time_days > w.threshold);
        WHEN 'removed' THEN
            st := EXISTS (SELECT 1 FROM inv_watch_targets(w.id) t WHERE t.removed_at IS NOT NULL)
               AND NOT EXISTS (SELECT 1 FROM inv_watch_targets(w.id) t WHERE t.removed_at IS NULL);
    END CASE;
    RETURN COALESCE(st, false);
END$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON suppliers, supplier_items, sources, source_credentials, source_pulls, source_templates, listings, listing_variants,
    offer_snapshots, match_proposals, watches TO inventory_rw;
GRANT EXECUTE ON FUNCTION inv_sizes_agree(text, text), inv_name_tokens(text), inv_watch_targets(bigint), inv_watch_state(bigint) TO inventory_rw, inventory_records_ro;
REVOKE ALL ON FUNCTION inv_set_match(bigint, bigint, text, numeric, bigint), inv_record_offer(bigint, bigint, boolean), inv_snapshot_heartbeat(), inv_match_listing_variant(bigint),
    inv_listing_match(bigint, bigint, bigint, text), inv_listing_unmatch(bigint), inv_propose_matches(bigint, bigint), inv_proposal_accept(bigint, bigint), inv_proposal_dismiss(bigint, bigint),
    inv_source_pull_start(bigint, text, bigint, text), inv_source_pull_finish(bigint, text, text, jsonb, integer, bigint), inv_source_resume(bigint),
    inv_upsert_listing(bigint, bigint, jsonb), inv_mark_removed(bigint, bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION inv_set_match(bigint, bigint, text, numeric, bigint), inv_record_offer(bigint, bigint, boolean), inv_snapshot_heartbeat(), inv_match_listing_variant(bigint),
    inv_listing_match(bigint, bigint, bigint, text), inv_listing_unmatch(bigint), inv_propose_matches(bigint, bigint), inv_proposal_accept(bigint, bigint), inv_proposal_dismiss(bigint, bigint),
    inv_source_pull_start(bigint, text, bigint, text), inv_source_pull_finish(bigint, text, text, jsonb, integer, bigint), inv_source_resume(bigint),
    inv_upsert_listing(bigint, bigint, jsonb), inv_mark_removed(bigint, bigint) TO inventory_rw;

COMMIT;

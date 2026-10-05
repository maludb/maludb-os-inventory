-- 005: the business's SETTINGS (one row), DOCUMENT NUMBERING, TAX RATES, REASON CODES — and the wall: inv_sees_cost().
--
-- THE ESTATE'S DATA MODEL (design §6.3): inv_settings is the siblings' one-row `app_settings` skeleton (id smallint = 1,
-- updated_at) with this application's columns; `document_sequences` and `tax_rates` are GL db/006's canonical definitions
-- copied VERBATIM (table, columns, types, checks, index names, next_document_number()) — only the `kind` list is ours and
-- the grants name our roles. Numbering is a TABLE updated transactionally, not a SEQUENCE: a rolled-back issue returns the
-- number. Every document of ours takes its number at creation (an internal reference; a hole explains nothing).
--
-- COST IS THE WALL (design §3, D2): inv_sees_cost() is the one function every view, tool and export asks; Warehouse sees
-- cost on receipts through inv_sees_receipt_cost().
BEGIN;

CREATE TABLE inv_settings (
    id                          smallint PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    business_name               text,                                             -- the doors, the crawler's user-agent, the feed
    business_contact_email      citext,                                           -- named in the user-agent (§0.2) and on the customer's page
    business_phone              text,
    business_address            text,
    currency                    char(3) NOT NULL DEFAULT 'USD' CHECK (currency ~ '^[A-Z]{3}$'),   -- one currency (multi-currency Extended)
    units                       text NOT NULL DEFAULT 'imperial' CHECK (units IN ('imperial', 'metric')),   -- shown; stored g / mm
    timezone                    text NOT NULL DEFAULT 'UTC',
    -- the walls and the doors
    sales_sees_cost             boolean NOT NULL DEFAULT false,                   -- D2: Sales sees cost and margin only when on
    supplier_sees_phone         text[] NOT NULL DEFAULT '{ltl,white_glove}',      -- D9: the ships_how kinds whose drop-ship ship-to carries the customer's phone
    feed_shows_quantity         boolean NOT NULL DEFAULT false,                   -- §4: the feed says in_stock with the quantity, or the state only
    order_link_days             integer NOT NULL DEFAULT 180 CHECK (order_link_days BETWEEN 1 AND 3650),      -- §4: dies 180 days after the order closes
    supplier_link_days          integer NOT NULL DEFAULT 90  CHECK (supplier_link_days BETWEEN 1 AND 3650),   -- §4: 90 days after the PO closes
    feed_rate_per_minute        integer NOT NULL DEFAULT 60    CHECK (feed_rate_per_minute BETWEEN 1 AND 100000),
    feed_rate_per_day           integer NOT NULL DEFAULT 10000 CHECK (feed_rate_per_day BETWEEN 1 AND 10000000),
    key_rotation_overlap_hours  integer NOT NULL DEFAULT 24    CHECK (key_rotation_overlap_hours BETWEEN 1 AND 720),
    -- the catalog's vocabulary (§0.3, D5): sizes with synonyms (the matcher's size_key), the attribute keys and their kinds
    sizes                       jsonb NOT NULL DEFAULT '[
        {"key":"twin","name":"Twin","synonyms":["t","twin","single"]},
        {"key":"twin_xl","name":"Twin XL","synonyms":["txl","twin xl","twin extra long","twinxl"]},
        {"key":"full","name":"Full","synonyms":["f","full","double"]},
        {"key":"full_xl","name":"Full XL","synonyms":["fxl","full xl","full extra long"]},
        {"key":"queen","name":"Queen","synonyms":["q","queen"]},
        {"key":"olympic_queen","name":"Olympic Queen","synonyms":["olympic queen","oq","expanded queen"]},
        {"key":"rv_short_queen","name":"RV Short Queen","synonyms":["rv short queen","short queen","rv queen"]},
        {"key":"king","name":"King","synonyms":["k","king","eastern king","standard king"]},
        {"key":"california_king","name":"California King","synonyms":["cal king","california king","ck","calking","western king"]},
        {"key":"split_king","name":"Split King","synonyms":["split king","sk","split eastern king"]},
        {"key":"split_california_king","name":"Split California King","synonyms":["split cal king","split california king","sck"]},
        {"key":"crib","name":"Crib","synonyms":["crib","toddler"]}
    ]'::jsonb,
    attribute_keys              jsonb NOT NULL DEFAULT '[
        {"key":"type","name":"Type","kind":"choice","choices":["innerspring","memory_foam","hybrid","latex","airbed","futon"]},
        {"key":"firmness","name":"Firmness (1-10)","kind":"number"},
        {"key":"firmness_word","name":"Firmness","kind":"choice","choices":["plush","medium","firm","extra_firm"]},
        {"key":"height_in","name":"Height (inches)","kind":"number"},
        {"key":"cover","name":"Cover","kind":"text"},
        {"key":"materials","name":"Materials","kind":"text"},
        {"key":"certifications","name":"Certifications","kind":"multi","choices":["CertiPUR-US","GOTS","GOLS","Oeko-Tex"]},
        {"key":"trial_nights","name":"Trial nights","kind":"number"},
        {"key":"warranty_years","name":"Warranty years","kind":"number"}
    ]'::jsonb,
    -- reorder defaults and the Buyer agent's thresholds (D11, §5)
    reorder_point_default       integer NOT NULL DEFAULT 0 CHECK (reorder_point_default >= 0),
    reorder_qty_default         integer NOT NULL DEFAULT 1 CHECK (reorder_qty_default >= 1),
    cost_source                 text NOT NULL DEFAULT 'last_receipt' CHECK (cost_source IN ('last_receipt', 'feed', 'manual')),   -- D11
    cost_move_pct               numeric(5,2) NOT NULL DEFAULT 5  CHECK (cost_move_pct >= 0),           -- cost moved more than this %
    reference_undercut_pct      numeric(5,2) NOT NULL DEFAULT 10 CHECK (reference_undercut_pct >= 0),  -- a reference under our retail by more than this %
    ack_days                    integer NOT NULL DEFAULT 3 CHECK (ack_days BETWEEN 1 AND 90),          -- a PO awaiting acknowledgment past N days
    buyer_member_id             bigint REFERENCES members(id) ON DELETE SET NULL,   -- D12: INV_BUYER_EMAIL seeds it at install; the settings screen changes it
    -- the crawl policy (§0.2, D8)
    crawl_user_agent            text,                                             -- NULL = built from the business name and contact
    crawl_rate_per_second       numeric(4,2) NOT NULL DEFAULT 1 CHECK (crawl_rate_per_second > 0 AND crawl_rate_per_second <= 10),
    crawl_backoff_minutes       integer[] NOT NULL DEFAULT '{60,1440}',           -- an hour, a day, then paused until a person looks
    crawl_max_pages             integer NOT NULL DEFAULT 100 CHECK (crawl_max_pages BETWEEN 1 AND 10000),   -- 100 × 250 = Shopify's hard stop
    schedule_supplier_minutes   integer NOT NULL DEFAULT 30   CHECK (schedule_supplier_minutes BETWEEN 5 AND 10080),
    schedule_reference_minutes  integer NOT NULL DEFAULT 360  CHECK (schedule_reference_minutes BETWEEN 5 AND 10080),
    schedule_jsonld_minutes     integer NOT NULL DEFAULT 1440 CHECK (schedule_jsonld_minutes BETWEEN 60 AND 10080),
    removed_after_pulls         integer NOT NULL DEFAULT 2 CHECK (removed_after_pulls BETWEEN 1 AND 20),   -- unseen this many pulls = removed
    snapshot_heartbeat_days     integer NOT NULL DEFAULT 1 CHECK (snapshot_heartbeat_days BETWEEN 1 AND 30),
    raw_max_bytes               integer NOT NULL DEFAULT 8192 CHECK (raw_max_bytes BETWEEN 256 AND 1048576),   -- a listing's trimmed raw object
    max_attachment_bytes        bigint NOT NULL DEFAULT 26214400 CHECK (max_attachment_bytes BETWEEN 1048576 AND 1073741824),
    updated_at                  timestamptz NOT NULL DEFAULT now()
);
INSERT INTO inv_settings (id) VALUES (1) ON CONFLICT DO NOTHING;
CREATE TRIGGER inv_settings_touch BEFORE UPDATE ON inv_settings FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE OR REPLACE FUNCTION inv_setting_int(p_name text) RETURNS integer
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE v integer;
BEGIN
    EXECUTE format('SELECT %I::integer FROM inv_settings WHERE id = 1', p_name) INTO v;
    RETURN v;
END$$;

CREATE OR REPLACE FUNCTION inv_setting_text(p_name text) RETURNS text
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE v text;
BEGIN
    EXECUTE format('SELECT %I::text FROM inv_settings WHERE id = 1', p_name) INTO v;
    RETURN v;
END$$;

CREATE OR REPLACE FUNCTION inv_currency() RETURNS char(3)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$ SELECT currency FROM inv_settings WHERE id = 1 $$;

-- ---------------------------------------------------------------------------------------------
-- The vocabularies of §0.3 as functions, so a CHECK and a connector's mapping agree in one place.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION inv_availability_states() RETURNS text[]
    LANGUAGE sql IMMUTABLE AS $$ SELECT ARRAY['in_stock','out_of_stock','pre_order','back_order','limited','discontinued','unknown'] $$;
CREATE OR REPLACE FUNCTION inv_ships_how_kinds() RETURNS text[]
    LANGUAGE sql IMMUTABLE AS $$ SELECT ARRAY['parcel','ltl','white_glove','pickup_only'] $$;
CREATE OR REPLACE FUNCTION inv_identifier_kinds() RETURNS text[]
    LANGUAGE sql IMMUTABLE AS $$ SELECT ARRAY['gtin','upc','ean','mpn','asin','ebay_epid','walmart_item_id','supplier_sku','other'] $$;
CREATE OR REPLACE FUNCTION inv_connectors() RETURNS text[]
    LANGUAGE sql IMMUTABLE AS $$ SELECT ARRAY['shopify','woocommerce','jsonld','feed','manual','ebay','amazon','walmart','inventory_feed','os_sibling'] $$;

-- ---------------------------------------------------------------------------------------------
-- The wall. inv_sees_cost(): cost.read (Buyer, admin), or Sales (orders.write) when the setting says so.
-- inv_sees_receipt_cost(): the same, or Warehouse (stock.receive) — cost is on the receiving paperwork.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION inv_sees_cost() RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN inv_has_right('cost.read')
        OR (inv_has_right('orders.write') AND (SELECT sales_sees_cost FROM inv_settings WHERE id = 1));
END$$;

CREATE OR REPLACE FUNCTION inv_sees_receipt_cost() RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN inv_sees_cost() OR inv_has_right('stock.receive');
END$$;

-- ---------------------------------------------------------------------------------------------
-- Numbering (GL db/006 verbatim; the kind list ours — design §6 "Settings").
-- ---------------------------------------------------------------------------------------------
CREATE TABLE document_sequences (
    kind        text PRIMARY KEY CHECK (kind IN ('sales_order', 'purchase_order', 'goods_receipt', 'return', 'adjustment', 'transfer', 'count')),
    prefix      text NOT NULL CHECK (prefix ~ '^[A-Z][A-Z0-9]{0,7}-?$'),
    next_value  bigint NOT NULL DEFAULT 1 CHECK (next_value > 0),
    padding     smallint NOT NULL DEFAULT 5 CHECK (padding BETWEEN 1 AND 12),
    updated_at  timestamptz NOT NULL DEFAULT now()
);
INSERT INTO document_sequences (kind, prefix, padding) VALUES
    ('sales_order', 'SO-', 5), ('purchase_order', 'PO-', 5), ('goods_receipt', 'GR-', 5), ('return', 'RA-', 5),
    ('adjustment', 'ADJ-', 5), ('transfer', 'TR-', 5), ('count', 'CNT-', 5)
ON CONFLICT DO NOTHING;

-- Row lock via UPDATE ... RETURNING: concurrent callers serialize; a rolled-back caller returns the number.
CREATE OR REPLACE FUNCTION next_document_number(p_kind text) RETURNS text
    LANGUAGE sql SECURITY DEFINER SET search_path = public AS $$
    UPDATE document_sequences
       SET next_value = next_value + 1, updated_at = now()
     WHERE kind = p_kind
    RETURNING prefix || lpad((next_value - 1)::text, padding, '0');
$$;
REVOKE ALL ON FUNCTION next_document_number(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION next_document_number(text) TO inventory_rw;

-- A document's number at creation, by trigger: NEW.number NULL → the sequence's next (one generic trigger, the kind by argument).
CREATE OR REPLACE FUNCTION inv_number_document() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NEW.number IS NULL OR NEW.number = '' THEN
        NEW.number := next_document_number(TG_ARGV[0]);
    END IF;
    RETURN NEW;
END$$;

-- ---------------------------------------------------------------------------------------------
-- Tax rates (GL db/006 verbatim: a percentage; rounded per line, never on the document total).
-- ---------------------------------------------------------------------------------------------
CREATE TABLE tax_rates (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name         text NOT NULL,
    rate         numeric(7,4) NOT NULL CHECK (rate >= 0 AND rate < 100),
    is_default   boolean NOT NULL DEFAULT false,
    archived_at  timestamptz,
    created_at   timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX tax_rates_name_live ON tax_rates (lower(name)) WHERE archived_at IS NULL;
CREATE UNIQUE INDEX tax_rates_one_default ON tax_rates ((true)) WHERE is_default AND archived_at IS NULL;
CREATE TRIGGER tax_rates_touch BEFORE UPDATE ON tax_rates FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
INSERT INTO tax_rates (name, rate, is_default) VALUES ('No tax', 0, true);

-- A line's money, rounded per line (GL's gl_line_money, under our name): subtotal = qty × price − discount; tax; total.
CREATE OR REPLACE FUNCTION inv_line_money(p_qty integer, p_price numeric, p_discount numeric, p_rate numeric,
                                          OUT line_subtotal numeric, OUT line_tax numeric, OUT line_total numeric)
    LANGUAGE sql IMMUTABLE AS $$
    SELECT round(p_qty * p_price - COALESCE(p_discount, 0), 2),
           round(round(p_qty * p_price - COALESCE(p_discount, 0), 2) * COALESCE(p_rate, 0) / 100, 2),
           round(p_qty * p_price - COALESCE(p_discount, 0), 2) + round(round(p_qty * p_price - COALESCE(p_discount, 0), 2) * COALESCE(p_rate, 0) / 100, 2);
$$;

-- ---------------------------------------------------------------------------------------------
-- Reason codes (design §6: adjustment reasons seeded; return reasons seeded — D14). One table, `applies_to` says where.
-- DECISION: the design seeds adjustment reasons under `reason_codes` and return reasons on `return_lines`; both are a
-- coded reason a person picks, so one table carries both, each row saying which document may use it.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE reason_codes (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code        text NOT NULL UNIQUE CHECK (code ~ '^[a-z][a-z0-9_]{0,39}$'),
    name        text NOT NULL,
    applies_to  text[] NOT NULL DEFAULT '{adjustment}' CHECK (applies_to <@ ARRAY['adjustment', 'return', 'transaction']),
    affects_qty boolean NOT NULL DEFAULT true,                                    -- a floor-model reason moves a flag, not a count
    sort_order  integer NOT NULL DEFAULT 0,
    active      boolean NOT NULL DEFAULT true,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER reason_codes_touch BEFORE UPDATE ON reason_codes FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
INSERT INTO reason_codes (code, name, applies_to, affects_qty, sort_order) VALUES
    ('damaged',      'Damaged',           '{adjustment,return}', true,  1),
    ('found',        'Found',             '{adjustment}',        true,  2),
    ('lost',         'Lost',              '{adjustment}',        true,  3),
    ('floor_model',  'Floor model',       '{adjustment}',        false, 4),
    ('sample',       'Sample',            '{adjustment}',        true,  5),
    ('donation',     'Donation',          '{adjustment}',        true,  6),
    ('correction',   'Correction',        '{adjustment}',        true,  7),
    ('comfort',      'Comfort',           '{return}',            true,  10),
    ('wrong_item',   'Wrong item',        '{return}',            true,  11),
    ('changed_mind', 'Changed mind',      '{return}',            true,  12),
    ('warranty',     'Warranty',          '{return}',            true,  13);

GRANT SELECT, INSERT, UPDATE, DELETE ON inv_settings, document_sequences, tax_rates, reason_codes TO inventory_rw;
GRANT EXECUTE ON FUNCTION inv_setting_int(text), inv_setting_text(text), inv_currency(), inv_sees_cost(), inv_sees_receipt_cost(),
    inv_availability_states(), inv_ships_how_kinds(), inv_identifier_kinds(), inv_connectors(), inv_line_money(integer, numeric, numeric, numeric)
    TO inventory_rw, inventory_records_ro, inventory_activity_ro;

COMMIT;

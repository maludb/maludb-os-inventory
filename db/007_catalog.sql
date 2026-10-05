-- 007: the CATALOG — brands, product types, products (the model), product variants (the sellable size or combination),
-- every identifier a seller uses, bundles, prices with their history, images (design §6 "Catalog", §0.3, D5, D11).
--
-- THE ESTATE'S DATA MODEL (design §6.3): NEW — GL's `items` (a price-list line with accounts), Reservations' `products`
-- (a subscription plan) and the Cidery's `items`/`products` (materials and recipes) were compared; none is a sellable
-- catalog with variants and identifiers. These are the canonical catalog tables from 2026-10-05.
--
-- The referee's rules here: a barcode is stored as GTIN-14 (inv_gtin14 — digits only, 8/12/13/14 long, the GS1 check digit
-- verified, left-padded) and is unique when set; `size_key` is derived by trigger from the Size option through the
-- settings' synonyms (so "Cal King", "California King" and "CK" are one key — the matcher never crosses sizes); a price
-- change writes `price_history` with who, when and the reason (`app.price_reason`, set by the handler); a bundle lists
-- components by variant, never nests, and never holds stock as a bundle (db/008 refuses a transaction on it).
BEGIN;

-- ---------------------------------------------------------------------------------------------
-- GTIN: the check digit (GS1 mod-10) and the GTIN-14 normalizer. NULL = not a GTIN (the caller decides what that means).
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION inv_gtin_check_digit(p_body text) RETURNS text
    LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE s integer := 0; i integer; n integer := length(p_body); w integer;
BEGIN
    IF p_body !~ '^[0-9]+$' THEN RETURN NULL; END IF;
    FOR i IN 1..n LOOP
        -- weights 3,1,3,1… from the RIGHT of the body (the digit nearest the check digit weighs 3)
        w := CASE WHEN (n - i) % 2 = 0 THEN 3 ELSE 1 END;
        s := s + substr(p_body, i, 1)::integer * w;
    END LOOP;
    RETURN ((10 - (s % 10)) % 10)::text;
END$$;

CREATE OR REPLACE FUNCTION inv_gtin14(p_raw text) RETURNS text
    LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE d text; n integer;
BEGIN
    IF p_raw IS NULL THEN RETURN NULL; END IF;
    d := regexp_replace(p_raw, '[^0-9]', '', 'g');
    n := length(d);
    IF n NOT IN (8, 12, 13, 14) THEN RETURN NULL; END IF;
    IF inv_gtin_check_digit(substr(d, 1, n - 1)) <> substr(d, n, 1) THEN RETURN NULL; END IF;
    RETURN lpad(d, 14, '0');
END$$;

-- A size's key through the settings' synonyms: "Cal King" → california_king; unknown → the folded text itself (so two
-- unknown spellings still agree with each other); NULL for nothing.
CREATE OR REPLACE FUNCTION inv_size_key(p_size text) RETURNS text
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE folded text; k text;
BEGIN
    IF p_size IS NULL OR btrim(p_size) = '' THEN RETURN NULL; END IF;
    folded := lower(regexp_replace(unaccent(btrim(p_size)), '[^a-zA-Z0-9]+', ' ', 'g'));
    folded := btrim(regexp_replace(folded, '\s+', ' ', 'g'));
    SELECT s->>'key' INTO k
      FROM inv_settings st, jsonb_array_elements(st.sizes) s
     WHERE st.id = 1
       AND (lower(s->>'key') = replace(folded, ' ', '_') OR lower(s->>'name') = folded
            OR EXISTS (SELECT 1 FROM jsonb_array_elements_text(s->'synonyms') syn WHERE lower(syn) = folded))
     LIMIT 1;
    RETURN COALESCE(k, replace(folded, ' ', '_'));
END$$;

-- The Size value in an option_values object, whatever its case ("Size", "size", "SIZE").
CREATE OR REPLACE FUNCTION inv_option_size(p_values jsonb) RETURNS text
    LANGUAGE sql IMMUTABLE AS $$
    SELECT v FROM jsonb_each_text(COALESCE(p_values, '{}'::jsonb)) AS t(k, v) WHERE lower(k) = 'size' LIMIT 1;
$$;

-- ---------------------------------------------------------------------------------------------
-- Brands and product types.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE brands (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name         text NOT NULL,
    website      text,
    supplier_id  bigint,                                       -- the brand's own dealer program (FK added in db/009)
    active       boolean NOT NULL DEFAULT true,
    created_at   timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX brands_name_idx ON brands (lower(name));
CREATE INDEX brands_name_trgm_idx ON brands USING gin (name gin_trgm_ops);
CREATE TRIGGER brands_touch BEFORE UPDATE ON brands FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE product_types (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    key         text NOT NULL UNIQUE CHECK (key ~ '^[a-z][a-z0-9_]{0,39}$'),
    name        text NOT NULL,
    sort_order  integer NOT NULL DEFAULT 0,
    active      boolean NOT NULL DEFAULT true,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER product_types_touch BEFORE UPDATE ON product_types FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
INSERT INTO product_types (key, name, sort_order) VALUES
    ('mattress', 'Mattress', 1), ('foundation', 'Foundation', 2), ('adjustable_base', 'Adjustable base', 3), ('pillow', 'Pillow', 4),
    ('protector', 'Protector', 5), ('sheets', 'Sheets', 6), ('frame', 'Frame', 7), ('topper', 'Topper', 8), ('other', 'Other', 9);

-- ---------------------------------------------------------------------------------------------
-- Products and variants.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE products (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    brand_id         bigint REFERENCES brands(id) ON DELETE RESTRICT,
    product_type_id  bigint NOT NULL REFERENCES product_types(id) ON DELETE RESTRICT,
    name             text NOT NULL,
    description      text,
    attributes       jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(attributes) = 'object'),   -- keys declared in inv_settings.attribute_keys
    kind             text NOT NULL DEFAULT 'single' CHECK (kind IN ('single', 'bundle')),
    status           text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'active', 'discontinued')),
    options          jsonb NOT NULL DEFAULT '["Size"]'::jsonb CHECK (jsonb_typeof(options) = 'array'),   -- the option names in order, Size first
    reorder_point    integer CHECK (reorder_point IS NULL OR reorder_point >= 0),      -- the default for its variants
    ships_how        text CHECK (ships_how IS NULL OR ships_how = ANY (inv_ships_how_kinds())),
    tags             text[] NOT NULL DEFAULT '{}',
    created_by       bigint REFERENCES members(id) ON DELETE SET NULL,
    discontinued_at  timestamptz,
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX products_brand_idx ON products (brand_id);
CREATE INDEX products_type_idx ON products (product_type_id);
CREATE INDEX products_status_idx ON products (status);
CREATE INDEX products_name_trgm_idx ON products USING gin (name gin_trgm_ops);
CREATE INDEX products_attributes_idx ON products USING gin (attributes);
CREATE TRIGGER products_touch BEFORE UPDATE ON products FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE OR REPLACE FUNCTION inv_products_before() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.status = 'discontinued' THEN NEW.discontinued_at := COALESCE(NEW.discontinued_at, now());
    ELSE NEW.discontinued_at := NULL; END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER products_before BEFORE INSERT OR UPDATE ON products FOR EACH ROW EXECUTE FUNCTION inv_products_before();

CREATE TABLE product_variants (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    product_id     bigint NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    sku            text NOT NULL,
    option_values  jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(option_values) = 'object'),   -- {"Size":"Queen"}
    size_key       text,                                                                 -- by trigger from the Size option
    barcode        text,                                                                 -- GTIN-14 by trigger; unique when set
    mpn            text,
    weight_g       integer CHECK (weight_g IS NULL OR weight_g >= 0),
    length_mm      integer CHECK (length_mm IS NULL OR length_mm >= 0),
    width_mm       integer CHECK (width_mm IS NULL OR width_mm >= 0),
    height_mm      integer CHECK (height_mm IS NULL OR height_mm >= 0),
    ships_how      text CHECK (ships_how IS NULL OR ships_how = ANY (inv_ships_how_kinds())),
    retail_price   numeric(12,2) CHECK (retail_price IS NULL OR retail_price >= 0),
    map_price      numeric(12,2) CHECK (map_price IS NULL OR map_price >= 0),
    cost_price     numeric(12,2) CHECK (cost_price IS NULL OR cost_price >= 0),        -- the business's standard cost (D11: the last receipt's by default)
    cost_updated_at timestamptz,
    reorder_point  integer CHECK (reorder_point IS NULL OR reorder_point >= 0),
    reorder_qty    integer CHECK (reorder_qty IS NULL OR reorder_qty >= 1),
    active         boolean NOT NULL DEFAULT true,
    serialized     boolean NOT NULL DEFAULT false,                                       -- reserved (Extended: serial-tracked units)
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX product_variants_sku_idx ON product_variants (lower(sku));
CREATE UNIQUE INDEX product_variants_barcode_idx ON product_variants (barcode) WHERE barcode IS NOT NULL;
CREATE INDEX product_variants_product_idx ON product_variants (product_id);
CREATE INDEX product_variants_size_idx ON product_variants (size_key);
CREATE INDEX product_variants_mpn_idx ON product_variants (lower(mpn)) WHERE mpn IS NOT NULL;
CREATE INDEX product_variants_sku_trgm_idx ON product_variants USING gin (sku gin_trgm_ops);
CREATE TRIGGER product_variants_touch BEFORE UPDATE ON product_variants FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE OR REPLACE FUNCTION inv_variants_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE g text;
BEGIN
    NEW.sku := btrim(NEW.sku);
    IF NEW.sku = '' THEN RAISE EXCEPTION 'A variant needs a SKU' USING ERRCODE = 'check_violation'; END IF;
    NEW.size_key := inv_size_key(inv_option_size(NEW.option_values));
    IF NEW.barcode IS NOT NULL AND btrim(NEW.barcode) <> '' THEN
        g := inv_gtin14(NEW.barcode);
        IF g IS NULL THEN
            RAISE EXCEPTION 'The barcode % is not a GTIN (8, 12, 13 or 14 digits with a valid check digit)', NEW.barcode USING ERRCODE = 'check_violation';
        END IF;
        NEW.barcode := g;
    ELSE
        NEW.barcode := NULL;
    END IF;
    NEW.mpn := NULLIF(btrim(COALESCE(NEW.mpn, '')), '');
    IF TG_OP = 'UPDATE' AND NEW.cost_price IS DISTINCT FROM OLD.cost_price THEN NEW.cost_updated_at := now(); END IF;
    IF TG_OP = 'INSERT' AND NEW.cost_price IS NOT NULL THEN NEW.cost_updated_at := now(); END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER product_variants_before BEFORE INSERT OR UPDATE ON product_variants FOR EACH ROW EXECUTE FUNCTION inv_variants_before();

-- ---------------------------------------------------------------------------------------------
-- Identifiers: every code a seller uses for a variant. A supplier's SKU belongs to that source (source_id, FK in db/009).
-- gtin/upc/ean values are normalized to GTIN-14 so the matcher compares one form.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE variant_identifiers (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    variant_id  bigint NOT NULL REFERENCES product_variants(id) ON DELETE CASCADE,
    kind        text NOT NULL CHECK (kind = ANY (inv_identifier_kinds())),
    value       text NOT NULL,
    source_id   bigint,                                                   -- a supplier's SKU belongs to that source (FK db/009)
    created_by  bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX variant_identifiers_unique_idx ON variant_identifiers (kind, lower(value), COALESCE(source_id, 0));
CREATE INDEX variant_identifiers_variant_idx ON variant_identifiers (variant_id);
CREATE INDEX variant_identifiers_value_idx ON variant_identifiers (lower(value));
CREATE TRIGGER variant_identifiers_touch BEFORE UPDATE ON variant_identifiers FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE OR REPLACE FUNCTION inv_identifiers_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE g text;
BEGIN
    NEW.value := btrim(NEW.value);
    IF NEW.value = '' THEN RAISE EXCEPTION 'An identifier needs a value' USING ERRCODE = 'check_violation'; END IF;
    IF NEW.kind IN ('gtin', 'upc', 'ean') THEN
        g := inv_gtin14(NEW.value);
        IF g IS NULL THEN RAISE EXCEPTION 'The % % is not a valid GTIN', NEW.kind, NEW.value USING ERRCODE = 'check_violation'; END IF;
        NEW.value := g;
    END IF;
    IF NEW.kind = 'supplier_sku' AND NEW.source_id IS NULL THEN
        RAISE EXCEPTION 'A supplier SKU belongs to a source' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER variant_identifiers_before BEFORE INSERT OR UPDATE ON variant_identifiers FOR EACH ROW EXECUTE FUNCTION inv_identifiers_before();

-- ---------------------------------------------------------------------------------------------
-- Bundles (D5, §0.1 "Sets and bundles"): a bundle product's variant (a Queen set) lists component variants with counts.
-- No nesting; never stock as a bundle (db/008).
-- ---------------------------------------------------------------------------------------------
CREATE TABLE bundle_components (
    bundle_variant_id     bigint NOT NULL REFERENCES product_variants(id) ON DELETE CASCADE,
    component_variant_id  bigint NOT NULL REFERENCES product_variants(id) ON DELETE RESTRICT,
    qty                   integer NOT NULL CHECK (qty > 0),
    created_at            timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (bundle_variant_id, component_variant_id),
    CHECK (bundle_variant_id <> component_variant_id)
);
CREATE INDEX bundle_components_component_idx ON bundle_components (component_variant_id);

CREATE OR REPLACE FUNCTION inv_is_bundle_variant(p_variant_id bigint) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT EXISTS (SELECT 1 FROM product_variants v JOIN products p ON p.id = v.product_id WHERE v.id = p_variant_id AND p.kind = 'bundle');
$$;

CREATE OR REPLACE FUNCTION inv_bundle_components_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NOT inv_is_bundle_variant(NEW.bundle_variant_id) THEN
        RAISE EXCEPTION 'Only a variant of a bundle product has components' USING ERRCODE = 'check_violation';
    END IF;
    IF inv_is_bundle_variant(NEW.component_variant_id) THEN
        RAISE EXCEPTION 'A bundle does not contain a bundle' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER bundle_components_before BEFORE INSERT OR UPDATE ON bundle_components FOR EACH ROW EXECUTE FUNCTION inv_bundle_components_before();

-- ---------------------------------------------------------------------------------------------
-- Prices: three on a variant, every change remembered (design §6: "a price's history is price_history").
-- ---------------------------------------------------------------------------------------------
CREATE TABLE price_history (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    variant_id  bigint NOT NULL REFERENCES product_variants(id) ON DELETE CASCADE,
    kind        text NOT NULL CHECK (kind IN ('retail', 'map', 'cost')),
    old_price   numeric(12,2),
    new_price   numeric(12,2),
    changed_by  bigint REFERENCES members(id) ON DELETE SET NULL,
    changed_at  timestamptz NOT NULL DEFAULT now(),
    reason      text,                                                     -- app.price_reason: 'receipt GR-00012', 'feed', 'sale event'
    source_kind text NOT NULL DEFAULT 'manual' CHECK (source_kind IN ('manual', 'last_receipt', 'feed', 'import'))
);
CREATE INDEX price_history_variant_idx ON price_history (variant_id, kind, changed_at DESC);

CREATE OR REPLACE FUNCTION inv_variants_price_history() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE reason text := NULLIF(current_setting('app.price_reason', true), '');
        skind text := COALESCE(NULLIF(current_setting('app.price_source', true), ''), 'manual');
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.retail_price IS NOT NULL THEN INSERT INTO price_history (variant_id, kind, old_price, new_price, changed_by, reason, source_kind) VALUES (NEW.id, 'retail', NULL, NEW.retail_price, app_current_member_id(), reason, skind); END IF;
        IF NEW.map_price IS NOT NULL THEN INSERT INTO price_history (variant_id, kind, old_price, new_price, changed_by, reason, source_kind) VALUES (NEW.id, 'map', NULL, NEW.map_price, app_current_member_id(), reason, skind); END IF;
        IF NEW.cost_price IS NOT NULL THEN INSERT INTO price_history (variant_id, kind, old_price, new_price, changed_by, reason, source_kind) VALUES (NEW.id, 'cost', NULL, NEW.cost_price, app_current_member_id(), reason, skind); END IF;
        RETURN NEW;
    END IF;
    IF NEW.retail_price IS DISTINCT FROM OLD.retail_price THEN INSERT INTO price_history (variant_id, kind, old_price, new_price, changed_by, reason, source_kind) VALUES (NEW.id, 'retail', OLD.retail_price, NEW.retail_price, app_current_member_id(), reason, skind); END IF;
    IF NEW.map_price IS DISTINCT FROM OLD.map_price THEN INSERT INTO price_history (variant_id, kind, old_price, new_price, changed_by, reason, source_kind) VALUES (NEW.id, 'map', OLD.map_price, NEW.map_price, app_current_member_id(), reason, skind); END IF;
    IF NEW.cost_price IS DISTINCT FROM OLD.cost_price THEN INSERT INTO price_history (variant_id, kind, old_price, new_price, changed_by, reason, source_kind) VALUES (NEW.id, 'cost', OLD.cost_price, NEW.cost_price, app_current_member_id(), reason, skind); END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER product_variants_price_history AFTER INSERT OR UPDATE ON product_variants FOR EACH ROW EXECUTE FUNCTION inv_variants_price_history();

-- The handler's way to set one price with a reason (price_set — pauses for an agent, §5).
CREATE OR REPLACE FUNCTION inv_price_set(p_variant_id bigint, p_kind text, p_price numeric, p_reason text DEFAULT NULL, p_source text DEFAULT 'manual') RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    PERFORM set_config('app.price_reason', COALESCE(p_reason, ''), true);
    PERFORM set_config('app.price_source', COALESCE(p_source, 'manual'), true);
    CASE p_kind
        WHEN 'retail' THEN UPDATE product_variants SET retail_price = p_price WHERE id = p_variant_id;
        WHEN 'map'    THEN UPDATE product_variants SET map_price = p_price WHERE id = p_variant_id;
        WHEN 'cost'   THEN UPDATE product_variants SET cost_price = p_price WHERE id = p_variant_id;
        ELSE RAISE EXCEPTION 'A price is retail, map or cost' USING ERRCODE = 'check_violation';
    END CASE;
    PERFORM set_config('app.price_reason', '', true);
    PERFORM set_config('app.price_source', '', true);
END$$;

-- ---------------------------------------------------------------------------------------------
-- Images: an attachment on a product (or one of its variants), ordered, one primary per product.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE product_images (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    product_id     bigint NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    variant_id     bigint REFERENCES product_variants(id) ON DELETE CASCADE,
    attachment_id  bigint NOT NULL REFERENCES attachments(id) ON DELETE CASCADE,
    alt_text       text,
    sort_order     integer NOT NULL DEFAULT 0,
    is_primary     boolean NOT NULL DEFAULT false,
    created_at     timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX product_images_attachment_idx ON product_images (attachment_id);
CREATE UNIQUE INDEX product_images_one_primary ON product_images (product_id) WHERE is_primary;
CREATE INDEX product_images_product_idx ON product_images (product_id, sort_order);

CREATE OR REPLACE FUNCTION inv_product_images_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NEW.variant_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM product_variants v WHERE v.id = NEW.variant_id AND v.product_id = NEW.product_id) THEN
        RAISE EXCEPTION 'The image''s variant belongs to another product' USING ERRCODE = 'check_violation';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM attachments a WHERE a.id = NEW.attachment_id AND a.record_type IN ('product', 'product_variant') AND a.mime_type LIKE 'image/%') THEN
        RAISE EXCEPTION 'A product image is an image attachment of a product or variant' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER product_images_before BEFORE INSERT OR UPDATE ON product_images FOR EACH ROW EXECUTE FUNCTION inv_product_images_before();

GRANT SELECT, INSERT, UPDATE, DELETE ON brands, product_types, products, product_variants, variant_identifiers, bundle_components, price_history, product_images TO inventory_rw;
GRANT EXECUTE ON FUNCTION inv_gtin_check_digit(text), inv_gtin14(text), inv_size_key(text), inv_option_size(jsonb), inv_is_bundle_variant(bigint)
    TO inventory_rw, inventory_records_ro, inventory_activity_ro;
REVOKE ALL ON FUNCTION inv_price_set(bigint, text, numeric, text, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION inv_price_set(bigint, text, numeric, text, text) TO inventory_rw;

COMMIT;

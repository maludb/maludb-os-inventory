-- 010: CUSTOMERS and SALES ORDERS — the customer, the quote that becomes an order, its lines with their fulfilment
-- (stock, drop-ship, backorder, pickup), payments recorded (never charged), shipments, and the customer's door
-- (design §6 "Customers and orders", §4, D9, D10).
--
-- THE ESTATE'S DATA MODEL (design §6.3): `customers` is GL db/009's canonical definition — every column and type kept;
-- ONE DEVIATION RECORDED: `income_account_id` keeps its name and type but carries NO `REFERENCES accounts(id)` — the chart
-- of accounts is the ledger's, not ours; the column holds the ledger's account id when a person fills it, and the
-- ledger's K7 read of our sales (sales_closed) carries it back. `tax_rate_id` keeps its FK (we reuse tax_rates, db/005).
-- APPENDED: phone_alt, source, email_opt_in. `sales_orders`/`sales_order_lines`/`shipments`/`order_payments` are NEW
-- (recorded): the Cidery's sales_orders (premises, destination kinds, packaging configurations) compared; the shared
-- columns keep its names (number, customer_id, status, origin, ordered_on, customer_reference, confirmed_by/at,
-- closed_by/at, cancelled_by/at, cancel_reason); the fulfilment choice per line is new — canonical for a retailer's order.
-- `order_links_secure` is GL db/010's invoice_links_secure shape keyed sales_order_id.
--
-- THE REFEREE'S RULES: a sale allocates at confirmation (refused when the location cannot cover it unless the line is a
-- backorder) and issues at shipment; a drop-ship line's offer (cost, lead time) is snapshotted ON THE LINE; one purchase
-- order per supplier per order is drafted at confirmation and placed by a person (db/011); totals are recomputed by
-- trigger; payment status is derived from the payments recorded; the customer's door is one token with its own lifetime.
BEGIN;

CREATE TABLE customers (
    id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name               text NOT NULL,
    legal_name         text,
    email              citext,
    phone              text,
    billing_address    text,
    shipping_address   text,
    tax_id             text,
    terms_days         integer CHECK (terms_days BETWEEN 0 AND 365),           -- NULL = the business default
    currency           char(3) CHECK (currency ~ '^[A-Z]{3}$'),                 -- NULL = the base currency
    income_account_id  bigint,                                                  -- the ledger's revenue account id — NO FK: the chart is the General Ledger's (design §6.3)
    tax_rate_id        bigint REFERENCES tax_rates(id) ON DELETE SET NULL,
    member_id          bigint REFERENCES members(id) ON DELETE SET NULL,
    notes              text,
    archived_at        timestamptz,
    created_by         bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now(),
    -- appended (design §6)
    phone_alt          text,
    source             text NOT NULL DEFAULT 'walk_in' CHECK (source IN ('walk_in', 'phone', 'web', 'referral', 'other')),
    email_opt_in       boolean NOT NULL DEFAULT false
);
CREATE UNIQUE INDEX customers_name_live ON customers (lower(name)) WHERE archived_at IS NULL;
CREATE INDEX customers_name_trgm_idx ON customers USING gin (name gin_trgm_ops);
CREATE INDEX customers_email_idx ON customers (email);
CREATE INDEX customers_member_idx ON customers (member_id);
CREATE INDEX customers_income_idx ON customers (income_account_id);
CREATE INDEX customers_tax_idx ON customers (tax_rate_id);
CREATE INDEX customers_created_by_idx ON customers (created_by);
CREATE TRIGGER customers_touch BEFORE UPDATE ON customers FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- ---------------------------------------------------------------------------------------------
-- Sales orders.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE sales_orders (
    id                     bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    number                 text NOT NULL UNIQUE,
    customer_id            bigint NOT NULL REFERENCES customers(id) ON DELETE RESTRICT,
    status                 text NOT NULL DEFAULT 'quote' CHECK (status IN ('quote', 'confirmed', 'in_fulfilment', 'shipped', 'delivered', 'closed', 'cancelled')),
    origin                 text NOT NULL DEFAULT 'entered' CHECK (origin IN ('entered', 'phone', 'web', 'agent')),
    salesperson_member_id  bigint REFERENCES members(id) ON DELETE SET NULL,
    location_id            bigint REFERENCES locations(id) ON DELETE SET NULL,     -- the store that sold it
    ordered_on             date NOT NULL DEFAULT current_date,
    promised_on            date,
    delivery_method        text NOT NULL DEFAULT 'delivery' CHECK (delivery_method IN ('pickup', 'delivery', 'parcel', 'ltl', 'white_glove')),
    ship_to_name           text,
    ship_to_address1       text,
    ship_to_address2       text,
    ship_to_city           text,
    ship_to_region         text,
    ship_to_postal         text,
    ship_to_country        char(2),
    ship_to_phone          text,
    ship_to_notes          text,                                                   -- delivery notes: stairs, dog, call first
    tax_rate_id            bigint REFERENCES tax_rates(id) ON DELETE SET NULL,
    subtotal               numeric(12,2) NOT NULL DEFAULT 0,                        -- Σ line_total (after line discounts)
    discount_total         numeric(12,2) NOT NULL DEFAULT 0,                        -- Σ line discounts
    tax_total              numeric(12,2) NOT NULL DEFAULT 0,
    shipping_charge        numeric(12,2) NOT NULL DEFAULT 0 CHECK (shipping_charge >= 0),
    total                  numeric(12,2) NOT NULL DEFAULT 0,
    payment_status         text NOT NULL DEFAULT 'unpaid' CHECK (payment_status IN ('unpaid', 'deposit', 'paid', 'refunded', 'partial_refund')),
    amount_paid            numeric(12,2) NOT NULL DEFAULT 0,
    customer_reference     text,
    notes                  text,
    confirmed_by           bigint REFERENCES members(id) ON DELETE SET NULL,
    confirmed_at           timestamptz,
    closed_by              bigint REFERENCES members(id) ON DELETE SET NULL,
    closed_at              timestamptz,
    cancelled_by           bigint REFERENCES members(id) ON DELETE SET NULL,
    cancelled_at           timestamptz,
    cancel_reason          text,
    created_by             bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at             timestamptz NOT NULL DEFAULT now(),
    updated_at             timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX sales_orders_customer_idx ON sales_orders (customer_id, ordered_on DESC);
CREATE INDEX sales_orders_status_idx ON sales_orders (status, promised_on);
CREATE INDEX sales_orders_salesperson_idx ON sales_orders (salesperson_member_id);
CREATE INDEX sales_orders_location_idx ON sales_orders (location_id);
CREATE INDEX sales_orders_closed_idx ON sales_orders (closed_at) WHERE status = 'closed';
CREATE TRIGGER sales_orders_touch BEFORE UPDATE ON sales_orders FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
CREATE TRIGGER sales_orders_number BEFORE INSERT ON sales_orders FOR EACH ROW EXECUTE FUNCTION inv_number_document('sales_order');

CREATE TABLE sales_order_lines (
    id                      bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    sales_order_id          bigint NOT NULL REFERENCES sales_orders(id) ON DELETE CASCADE,
    line_no                 integer NOT NULL,
    variant_id              bigint NOT NULL REFERENCES product_variants(id) ON DELETE RESTRICT,
    qty                     integer NOT NULL CHECK (qty > 0),
    unit_price              numeric(12,2) NOT NULL CHECK (unit_price >= 0),
    discount                numeric(12,2) NOT NULL DEFAULT 0 CHECK (discount >= 0),
    line_total              numeric(12,2) NOT NULL DEFAULT 0,
    fulfilment_kind         text NOT NULL DEFAULT 'stock' CHECK (fulfilment_kind IN ('stock', 'dropship', 'backorder', 'pickup')),
    location_id             bigint REFERENCES locations(id) ON DELETE RESTRICT,     -- stock / pickup / backorder: where it is filled from
    listing_variant_id      bigint REFERENCES listing_variants(id) ON DELETE SET NULL,   -- drop-ship: the offer it was sold against
    source_id               bigint REFERENCES sources(id) ON DELETE SET NULL,
    offer_cost              numeric(12,2),                                           -- snapshotted at the moment of the sale
    offer_lead_time_days    integer,
    purchase_order_line_id  bigint,                                                  -- once placed (FK in db/011)
    qty_allocated           integer NOT NULL DEFAULT 0 CHECK (qty_allocated >= 0),
    qty_shipped             integer NOT NULL DEFAULT 0 CHECK (qty_shipped >= 0),
    qty_returned            integer NOT NULL DEFAULT 0 CHECK (qty_returned >= 0),
    status                  text NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'allocated', 'ordered', 'shipped', 'delivered', 'cancelled', 'returned')),
    serials                 text[] NOT NULL DEFAULT '{}',                             -- typed at shipment (serial-tracked units are Extended)
    notes                   text,
    created_at              timestamptz NOT NULL DEFAULT now(),
    updated_at              timestamptz NOT NULL DEFAULT now(),
    UNIQUE (sales_order_id, line_no),
    CHECK (fulfilment_kind <> 'dropship' OR listing_variant_id IS NOT NULL),
    CHECK (fulfilment_kind NOT IN ('stock', 'pickup') OR location_id IS NOT NULL)
);
CREATE INDEX sales_order_lines_variant_idx ON sales_order_lines (variant_id);
CREATE INDEX sales_order_lines_lv_idx ON sales_order_lines (listing_variant_id);
CREATE INDEX sales_order_lines_po_line_idx ON sales_order_lines (purchase_order_line_id);
CREATE INDEX sales_order_lines_status_idx ON sales_order_lines (status);
CREATE TRIGGER sales_order_lines_touch BEFORE UPDATE ON sales_order_lines FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- A line's money and its fulfilment facts. Lines are added, changed and removed while the order is a QUOTE; after
-- confirmation only the functions write them (under the writer flag).
CREATE OR REPLACE FUNCTION inv_order_lines_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE st text; lv listing_variants%ROWTYPE; sid bigint; sup bigint; si supplier_items%ROWTYPE; sup_lead integer;
BEGIN
    SELECT status INTO st FROM sales_orders WHERE id = COALESCE(NEW.sales_order_id, OLD.sales_order_id);
    IF COALESCE(current_setting('inv.balance_writer', true), '') <> 'on' AND st IS DISTINCT FROM 'quote' THEN
        RAISE EXCEPTION 'The lines of an order change only while it is a quote (it is %) — cancel a line or the order instead', st USING ERRCODE = 'check_violation';
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    IF inv_is_bundle_variant(NEW.variant_id) THEN
        RAISE EXCEPTION 'A bundle is sold as its components — add them as lines (the bundle''s price on the first)' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.unit_price IS NULL THEN SELECT COALESCE(retail_price, 0) INTO NEW.unit_price FROM product_variants WHERE id = NEW.variant_id; END IF;
    NEW.line_total := round(NEW.qty * NEW.unit_price - NEW.discount, 2);
    IF NEW.line_total < 0 THEN RAISE EXCEPTION 'A line''s discount exceeds its value' USING ERRCODE = 'check_violation'; END IF;
    IF NEW.fulfilment_kind = 'dropship' THEN
        SELECT * INTO lv FROM listing_variants WHERE id = NEW.listing_variant_id;
        IF NOT FOUND THEN RAISE EXCEPTION 'No such offer' USING ERRCODE = 'no_data_found'; END IF;
        IF lv.variant_id IS DISTINCT FROM NEW.variant_id THEN
            RAISE EXCEPTION 'That offer is not matched to this variant' USING ERRCODE = 'check_violation';
        END IF;
        SELECT l.source_id, s.supplier_id INTO sid, sup FROM listings l JOIN sources s ON s.id = l.source_id WHERE l.id = lv.listing_id;
        IF sup IS NULL THEN RAISE EXCEPTION 'A drop-ship line needs a supplier source — that offer is a reference' USING ERRCODE = 'check_violation'; END IF;
        NEW.source_id := sid;
        SELECT * INTO si FROM supplier_items WHERE supplier_id = sup AND variant_id = NEW.variant_id AND active;
        SELECT lead_time_days INTO sup_lead FROM suppliers WHERE id = sup;
        -- the offer's cost and lead time, snapshotted on the line (changed only while a quote, like any line fact)
        IF TG_OP = 'INSERT' OR NEW.listing_variant_id IS DISTINCT FROM OLD.listing_variant_id OR NEW.offer_cost IS NULL THEN
            NEW.offer_cost := COALESCE(lv.cost_price, si.cost, lv.price);
            NEW.offer_lead_time_days := COALESCE(lv.lead_time_days, si.lead_time_days, sup_lead);
        END IF;
        NEW.location_id := NULL;
    ELSE
        NEW.listing_variant_id := NULL; NEW.source_id := NULL; NEW.offer_cost := NULL; NEW.offer_lead_time_days := NULL;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER sales_order_lines_before BEFORE INSERT OR UPDATE OR DELETE ON sales_order_lines FOR EACH ROW EXECUTE FUNCTION inv_order_lines_before();

-- Totals: Σ lines, the order's tax rate on the subtotal (DECISION: one rate per order, rounded on the order — a mattress
-- sale carries one sales tax; a per-line rate is the ledger's concern, not the desk's), shipping, total.
CREATE OR REPLACE FUNCTION inv_order_recompute(p_order_id bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE sub numeric; disc numeric; rate numeric; tax numeric; ship numeric;
BEGIN
    SELECT COALESCE(sum(line_total), 0), COALESCE(sum(discount), 0) INTO sub, disc FROM sales_order_lines WHERE sales_order_id = p_order_id AND status <> 'cancelled';
    SELECT COALESCE(t.rate, 0), o.shipping_charge INTO rate, ship FROM sales_orders o LEFT JOIN tax_rates t ON t.id = o.tax_rate_id WHERE o.id = p_order_id;
    tax := round(sub * COALESCE(rate, 0) / 100, 2);
    UPDATE sales_orders SET subtotal = sub, discount_total = disc, tax_total = tax, total = sub + tax + COALESCE(ship, 0) WHERE id = p_order_id;
END$$;

CREATE OR REPLACE FUNCTION inv_order_lines_after() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    PERFORM inv_order_recompute(COALESCE(NEW.sales_order_id, OLD.sales_order_id));
    RETURN NULL;
END$$;
CREATE TRIGGER sales_order_lines_after AFTER INSERT OR UPDATE OR DELETE ON sales_order_lines FOR EACH ROW EXECUTE FUNCTION inv_order_lines_after();

CREATE OR REPLACE FUNCTION inv_orders_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE sub numeric; rate numeric;
BEGIN
    IF TG_OP = 'UPDATE' AND (NEW.tax_rate_id IS DISTINCT FROM OLD.tax_rate_id OR NEW.shipping_charge IS DISTINCT FROM OLD.shipping_charge) THEN
        SELECT COALESCE(t.rate, 0) INTO rate FROM tax_rates t WHERE t.id = NEW.tax_rate_id;
        NEW.tax_total := round(NEW.subtotal * COALESCE(rate, 0) / 100, 2);
        NEW.total := NEW.subtotal + NEW.tax_total + NEW.shipping_charge;
    END IF;
    IF TG_OP = 'INSERT' AND NEW.tax_rate_id IS NULL THEN
        SELECT id INTO NEW.tax_rate_id FROM tax_rates WHERE is_default AND archived_at IS NULL;
    END IF;
    IF TG_OP = 'INSERT' AND NEW.ship_to_name IS NULL THEN
        SELECT name, shipping_address, phone INTO NEW.ship_to_name, NEW.ship_to_address1, NEW.ship_to_phone FROM customers WHERE id = NEW.customer_id;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER sales_orders_before BEFORE INSERT OR UPDATE ON sales_orders FOR EACH ROW EXECUTE FUNCTION inv_orders_before();

-- ---------------------------------------------------------------------------------------------
-- Payments: a RECORD, never a charge (D10). Status derived.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE order_payments (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    sales_order_id  bigint NOT NULL REFERENCES sales_orders(id) ON DELETE CASCADE,
    kind            text NOT NULL CHECK (kind IN ('deposit', 'balance', 'refund')),
    amount          numeric(12,2) NOT NULL CHECK (amount > 0),
    method          text NOT NULL CHECK (method IN ('cash', 'card', 'check', 'transfer', 'financing', 'other')),
    reference       text,                                                    -- the last four, a check number, the financing contract
    taken_by        bigint REFERENCES members(id) ON DELETE SET NULL,
    taken_at        timestamptz NOT NULL DEFAULT now(),
    note            text,
    created_at      timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX order_payments_order_idx ON order_payments (sales_order_id, taken_at);

CREATE OR REPLACE FUNCTION inv_order_payment_status(p_order_id bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE received numeric; refunded numeric; tot numeric; st text;
BEGIN
    SELECT COALESCE(sum(amount) FILTER (WHERE kind IN ('deposit', 'balance')), 0), COALESCE(sum(amount) FILTER (WHERE kind = 'refund'), 0)
      INTO received, refunded FROM order_payments WHERE sales_order_id = p_order_id;
    SELECT total INTO tot FROM sales_orders WHERE id = p_order_id;
    st := CASE WHEN received = 0 THEN 'unpaid'
               WHEN refunded > 0 AND refunded >= received THEN 'refunded'
               WHEN refunded > 0 THEN 'partial_refund'
               WHEN received >= tot AND tot > 0 THEN 'paid'
               ELSE 'deposit' END;
    UPDATE sales_orders SET payment_status = st, amount_paid = received - refunded WHERE id = p_order_id;
END$$;

CREATE OR REPLACE FUNCTION inv_order_payments_after() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    PERFORM inv_order_payment_status(COALESCE(NEW.sales_order_id, OLD.sales_order_id));
    RETURN NULL;
END$$;
CREATE TRIGGER order_payments_after AFTER INSERT OR UPDATE OR DELETE ON order_payments FOR EACH ROW EXECUTE FUNCTION inv_order_payments_after();

CREATE OR REPLACE FUNCTION inv_order_payments_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE st text;
BEGIN
    SELECT status INTO st FROM sales_orders WHERE id = NEW.sales_order_id;
    IF st = 'cancelled' AND NEW.kind <> 'refund' THEN RAISE EXCEPTION 'Only a refund is recorded on a cancelled order' USING ERRCODE = 'check_violation'; END IF;
    IF NEW.taken_by IS NULL THEN NEW.taken_by := app_current_member_id(); END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER order_payments_before BEFORE INSERT ON order_payments FOR EACH ROW EXECUTE FUNCTION inv_order_payments_before();

-- ---------------------------------------------------------------------------------------------
-- Shipments.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE shipments (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    sales_order_id  bigint NOT NULL REFERENCES sales_orders(id) ON DELETE CASCADE,
    kind            text NOT NULL CHECK (kind IN ('own_delivery', 'parcel', 'ltl', 'dropship', 'pickup')),
    carrier         text,
    tracking_number text,
    tracking_url    text,
    shipped_at      timestamptz NOT NULL DEFAULT now(),
    delivered_at    timestamptz,
    shipped_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    note            text,
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX shipments_order_idx ON shipments (sales_order_id, shipped_at DESC);
CREATE INDEX shipments_open_idx ON shipments (shipped_at) WHERE delivered_at IS NULL;
CREATE TRIGGER shipments_touch BEFORE UPDATE ON shipments FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE shipment_lines (
    id                   bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    shipment_id          bigint NOT NULL REFERENCES shipments(id) ON DELETE CASCADE,
    sales_order_line_id  bigint NOT NULL REFERENCES sales_order_lines(id) ON DELETE CASCADE,
    qty                  integer NOT NULL CHECK (qty > 0),
    serials              text[] NOT NULL DEFAULT '{}',
    created_at           timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX shipment_lines_shipment_idx ON shipment_lines (shipment_id);
CREATE INDEX shipment_lines_order_line_idx ON shipment_lines (sales_order_line_id);

-- ---------------------------------------------------------------------------------------------
-- The customer's door (GL's secure-link shape keyed sales_order_id): minted with the confirmation, rotatable, dies
-- order_link_days after the order closes.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE order_links_secure (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    sales_order_id  bigint NOT NULL REFERENCES sales_orders(id) ON DELETE CASCADE,
    token_hash      text NOT NULL UNIQUE,
    expires_at      timestamptz,
    rotated_at      timestamptz,
    last_used_at    timestamptz,
    view_count      integer NOT NULL DEFAULT 0,
    created_at      timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX order_links_secure_order_idx ON order_links_secure (sales_order_id) WHERE rotated_at IS NULL;

-- Mint (or rotate): the raw token is returned ONCE; only its hash is kept. The previous live link is rotated.
CREATE OR REPLACE FUNCTION inv_order_link_mint(p_order_id bigint) RETURNS text
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE raw text; exp timestamptz;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM sales_orders WHERE id = p_order_id) THEN RAISE EXCEPTION 'No such order' USING ERRCODE = 'no_data_found'; END IF;
    raw := encode(gen_random_bytes(24), 'hex');
    SELECT CASE WHEN closed_at IS NOT NULL THEN closed_at + make_interval(days => inv_setting_int('order_link_days')) END INTO exp FROM sales_orders WHERE id = p_order_id;
    UPDATE order_links_secure SET rotated_at = now() WHERE sales_order_id = p_order_id AND rotated_at IS NULL;
    INSERT INTO order_links_secure (sales_order_id, token_hash, expires_at) VALUES (p_order_id, encode(sha256(raw::bytea), 'hex'), exp);
    RETURN raw;
END$$;

-- The order a presented token opens: live, not rotated, not expired; counts the view. NULL otherwise — the same nothing
-- for every reason.
CREATE OR REPLACE FUNCTION inv_secure_link_order(p_raw text) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE oid bigint;
BEGIN
    IF p_raw !~ '^[a-f0-9]{48}$' THEN RETURN NULL; END IF;
    UPDATE order_links_secure SET last_used_at = now(), view_count = view_count + 1
     WHERE token_hash = encode(sha256(p_raw::bytea), 'hex') AND rotated_at IS NULL AND (expires_at IS NULL OR expires_at > now())
    RETURNING sales_order_id INTO oid;
    RETURN oid;
END$$;

-- ---------------------------------------------------------------------------------------------
-- The verbs. Confirm allocates every stock line and drafts one purchase order per supplier for the drop-ship lines
-- (inv_order_dropships_draft — a no-op here, the real one in db/011); ship issues; deliver; close; cancel releases.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION inv_order_dropships_draft(p_order_id bigint, p_by bigint) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$ BEGIN RETURN 0; END$$;
CREATE OR REPLACE FUNCTION inv_order_dropships_cancel(p_order_id bigint, p_by bigint) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$ BEGIN RETURN 0; END$$;

CREATE OR REPLACE FUNCTION inv_order_confirm(p_order_id bigint, p_by bigint) RETURNS sales_orders
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE o sales_orders%ROWTYPE; l record; avail integer;
BEGIN
    SELECT * INTO o FROM sales_orders WHERE id = p_order_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such order' USING ERRCODE = 'no_data_found'; END IF;
    IF o.status <> 'quote' THEN RAISE EXCEPTION 'Order % is % — only a quote is confirmed', o.number, o.status USING ERRCODE = 'check_violation'; END IF;
    IF NOT EXISTS (SELECT 1 FROM sales_order_lines WHERE sales_order_id = p_order_id) THEN RAISE EXCEPTION 'Order % has no lines', o.number USING ERRCODE = 'check_violation'; END IF;
    PERFORM set_config('inv.balance_writer', 'on', true);
    FOR l IN SELECT * FROM sales_order_lines WHERE sales_order_id = p_order_id AND status = 'open' ORDER BY line_no LOOP
        IF l.fulfilment_kind IN ('stock', 'pickup') THEN
            SELECT COALESCE(b.qty_on_hand - b.qty_floor_model - b.qty_allocated, 0) INTO avail FROM inventory_balances b WHERE b.variant_id = l.variant_id AND b.location_id = l.location_id;
            IF COALESCE(avail, 0) < l.qty THEN
                PERFORM set_config('inv.balance_writer', '', true);
                RAISE EXCEPTION 'Line %: only % available at that location for % — make it a backorder or a drop-ship', l.line_no, COALESCE(avail, 0), l.qty USING ERRCODE = 'check_violation';
            END IF;
            PERFORM inv_allocate(l.variant_id, l.location_id, l.qty);
            PERFORM set_config('inv.balance_writer', 'on', true);
            UPDATE sales_order_lines SET qty_allocated = l.qty, status = 'allocated' WHERE id = l.id;
        END IF;
    END LOOP;
    UPDATE sales_orders SET status = 'confirmed', confirmed_by = p_by, confirmed_at = now() WHERE id = p_order_id RETURNING * INTO o;
    PERFORM set_config('inv.balance_writer', '', true);
    PERFORM inv_order_dropships_draft(p_order_id, p_by);
    RETURN o;
END$$;

-- Ship: the lines given ([{"line_id":…, "qty":…, "serials":[…]}]) in one shipment. A stock, pickup or backorder line
-- issues a `sale` transaction at its location and releases its allocation; a drop-ship line shipped by hand here is the
-- supplier's tracking typed in. The order becomes shipped when every line is, else in_fulfilment.
CREATE OR REPLACE FUNCTION inv_order_ship(p_order_id bigint, p_by bigint, p_lines jsonb, p_kind text DEFAULT 'own_delivery',
                                          p_carrier text DEFAULT NULL, p_tracking text DEFAULT NULL, p_shipped_at timestamptz DEFAULT now()) RETURNS shipments
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE o sales_orders%ROWTYPE; sh shipments%ROWTYPE; e jsonb; l sales_order_lines%ROWTYPE; q integer; g uuid := gen_random_uuid(); c numeric; rel integer;
BEGIN
    SELECT * INTO o FROM sales_orders WHERE id = p_order_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such order' USING ERRCODE = 'no_data_found'; END IF;
    IF o.status NOT IN ('confirmed', 'in_fulfilment') THEN RAISE EXCEPTION 'Order % is % — a confirmed order ships', o.number, o.status USING ERRCODE = 'check_violation'; END IF;
    IF jsonb_typeof(p_lines) <> 'array' OR jsonb_array_length(p_lines) = 0 THEN RAISE EXCEPTION 'A shipment has lines' USING ERRCODE = 'check_violation'; END IF;
    INSERT INTO shipments (sales_order_id, kind, carrier, tracking_number, shipped_at, shipped_by) VALUES (p_order_id, p_kind, p_carrier, p_tracking, p_shipped_at, p_by) RETURNING * INTO sh;
    PERFORM set_config('inv.balance_writer', 'on', true);
    FOR e IN SELECT * FROM jsonb_array_elements(p_lines) LOOP
        SELECT * INTO l FROM sales_order_lines WHERE id = (e->>'line_id')::bigint AND sales_order_id = p_order_id FOR UPDATE;
        IF NOT FOUND THEN RAISE EXCEPTION 'Line % is not on this order', e->>'line_id' USING ERRCODE = 'check_violation'; END IF;
        q := COALESCE((e->>'qty')::integer, l.qty - l.qty_shipped);
        IF q <= 0 OR q > l.qty - l.qty_shipped THEN RAISE EXCEPTION 'Line % ships between 1 and %', l.line_no, l.qty - l.qty_shipped USING ERRCODE = 'check_violation'; END IF;
        IF l.status = 'cancelled' THEN RAISE EXCEPTION 'Line % is cancelled', l.line_no USING ERRCODE = 'check_violation'; END IF;
        INSERT INTO shipment_lines (shipment_id, sales_order_line_id, qty, serials) VALUES (sh.id, l.id, q, COALESCE((SELECT array_agg(x) FROM jsonb_array_elements_text(e->'serials') x), '{}'));
        IF l.fulfilment_kind IN ('stock', 'pickup', 'backorder') THEN
            IF l.location_id IS NULL THEN RAISE EXCEPTION 'Line % has no location to ship from', l.line_no USING ERRCODE = 'check_violation'; END IF;
            SELECT cost_price INTO c FROM product_variants WHERE id = l.variant_id;
            PERFORM inv_post_txn('sale', l.variant_id, l.location_id, -q, c, 'shipment', sh.id, 'sale:' || sh.id || ':' || l.id, 'customer', o.customer_id, NULL, g, p_by, NULL, NULL, p_shipped_at);
            rel := LEAST(q, l.qty_allocated);
            IF rel > 0 THEN PERFORM inv_allocate(l.variant_id, l.location_id, -rel); END IF;
            PERFORM set_config('inv.balance_writer', 'on', true);
        END IF;
        UPDATE sales_order_lines SET qty_shipped = qty_shipped + q, qty_allocated = GREATEST(0, qty_allocated - q),
                                     serials = serials || COALESCE((SELECT array_agg(x) FROM jsonb_array_elements_text(e->'serials') x), '{}'),
                                     status = CASE WHEN qty_shipped + q >= qty THEN (CASE WHEN p_kind = 'pickup' THEN 'delivered' ELSE 'shipped' END) ELSE status END
         WHERE id = l.id;
    END LOOP;
    IF p_kind = 'pickup' THEN UPDATE shipments SET delivered_at = p_shipped_at WHERE id = sh.id RETURNING * INTO sh; END IF;
    UPDATE sales_orders SET status = CASE WHEN NOT EXISTS (SELECT 1 FROM sales_order_lines WHERE sales_order_id = p_order_id AND status NOT IN ('shipped', 'delivered', 'cancelled'))
                                          THEN (CASE WHEN NOT EXISTS (SELECT 1 FROM sales_order_lines WHERE sales_order_id = p_order_id AND status NOT IN ('delivered', 'cancelled')) THEN 'delivered' ELSE 'shipped' END)
                                          ELSE 'in_fulfilment' END
     WHERE id = p_order_id;
    PERFORM set_config('inv.balance_writer', '', true);
    RETURN sh;
END$$;

CREATE OR REPLACE FUNCTION inv_shipment_deliver(p_shipment_id bigint, p_by bigint, p_at timestamptz DEFAULT now()) RETURNS shipments
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE sh shipments%ROWTYPE;
BEGIN
    UPDATE shipments SET delivered_at = COALESCE(delivered_at, p_at) WHERE id = p_shipment_id RETURNING * INTO sh;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such shipment' USING ERRCODE = 'no_data_found'; END IF;
    PERFORM set_config('inv.balance_writer', 'on', true);
    UPDATE sales_order_lines l SET status = 'delivered'
     WHERE l.id IN (SELECT sales_order_line_id FROM shipment_lines WHERE shipment_id = p_shipment_id) AND l.status = 'shipped' AND l.qty_shipped >= l.qty;
    UPDATE sales_orders SET status = 'delivered' WHERE id = sh.sales_order_id AND status IN ('shipped', 'in_fulfilment')
       AND NOT EXISTS (SELECT 1 FROM sales_order_lines WHERE sales_order_id = sh.sales_order_id AND status NOT IN ('delivered', 'cancelled'));
    PERFORM set_config('inv.balance_writer', '', true);
    RETURN sh;
END$$;

CREATE OR REPLACE FUNCTION inv_order_close(p_order_id bigint, p_by bigint) RETURNS sales_orders
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE o sales_orders%ROWTYPE;
BEGIN
    SELECT * INTO o FROM sales_orders WHERE id = p_order_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such order' USING ERRCODE = 'no_data_found'; END IF;
    IF o.status <> 'delivered' THEN RAISE EXCEPTION 'Order % is % — a delivered order closes', o.number, o.status USING ERRCODE = 'check_violation'; END IF;
    UPDATE sales_orders SET status = 'closed', closed_by = p_by, closed_at = now() WHERE id = p_order_id RETURNING * INTO o;
    UPDATE order_links_secure SET expires_at = LEAST(COALESCE(expires_at, 'infinity'::timestamptz), now() + make_interval(days => inv_setting_int('order_link_days')))
     WHERE sales_order_id = p_order_id AND rotated_at IS NULL;
    RETURN o;
END$$;

CREATE OR REPLACE FUNCTION inv_order_cancel(p_order_id bigint, p_by bigint, p_reason text) RETURNS sales_orders
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE o sales_orders%ROWTYPE; l record;
BEGIN
    SELECT * INTO o FROM sales_orders WHERE id = p_order_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such order' USING ERRCODE = 'no_data_found'; END IF;
    IF o.status IN ('closed', 'cancelled') THEN RAISE EXCEPTION 'Order % is already %', o.number, o.status USING ERRCODE = 'check_violation'; END IF;
    IF EXISTS (SELECT 1 FROM sales_order_lines WHERE sales_order_id = p_order_id AND qty_shipped > qty_returned) THEN
        RAISE EXCEPTION 'Order % has shipped lines — take a return instead', o.number USING ERRCODE = 'check_violation';
    END IF;
    PERFORM set_config('inv.balance_writer', 'on', true);
    FOR l IN SELECT * FROM sales_order_lines WHERE sales_order_id = p_order_id AND qty_allocated > 0 LOOP
        PERFORM inv_allocate(l.variant_id, l.location_id, -l.qty_allocated);
        PERFORM set_config('inv.balance_writer', 'on', true);
    END LOOP;
    UPDATE sales_order_lines SET status = 'cancelled', qty_allocated = 0 WHERE sales_order_id = p_order_id AND status <> 'cancelled';
    UPDATE sales_orders SET status = 'cancelled', cancelled_by = p_by, cancelled_at = now(), cancel_reason = p_reason WHERE id = p_order_id RETURNING * INTO o;
    PERFORM set_config('inv.balance_writer', '', true);
    PERFORM inv_order_dropships_cancel(p_order_id, p_by);
    RETURN o;
END$$;

-- Cancel one line of a confirmed order (release its allocation; a placed drop-ship line is cancelled on the PO by hand).
CREATE OR REPLACE FUNCTION inv_order_line_cancel(p_line_id bigint, p_by bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE l sales_order_lines%ROWTYPE;
BEGIN
    SELECT * INTO l FROM sales_order_lines WHERE id = p_line_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such line' USING ERRCODE = 'no_data_found'; END IF;
    IF l.qty_shipped > 0 THEN RAISE EXCEPTION 'Line % has shipped — take a return instead', l.line_no USING ERRCODE = 'check_violation'; END IF;
    PERFORM set_config('inv.balance_writer', 'on', true);
    IF l.qty_allocated > 0 THEN PERFORM inv_allocate(l.variant_id, l.location_id, -l.qty_allocated); PERFORM set_config('inv.balance_writer', 'on', true); END IF;
    UPDATE sales_order_lines SET status = 'cancelled', qty_allocated = 0 WHERE id = p_line_id;
    PERFORM set_config('inv.balance_writer', '', true);
    PERFORM inv_order_recompute(l.sales_order_id);
END$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON customers, sales_orders, sales_order_lines, order_payments, shipments, shipment_lines, order_links_secure TO inventory_rw;
GRANT EXECUTE ON FUNCTION inv_order_recompute(bigint), inv_order_payment_status(bigint) TO inventory_rw;
REVOKE ALL ON FUNCTION inv_order_link_mint(bigint), inv_secure_link_order(text), inv_order_dropships_draft(bigint, bigint), inv_order_dropships_cancel(bigint, bigint),
    inv_order_confirm(bigint, bigint), inv_order_ship(bigint, bigint, jsonb, text, text, text, timestamptz), inv_shipment_deliver(bigint, bigint, timestamptz),
    inv_order_close(bigint, bigint), inv_order_cancel(bigint, bigint, text), inv_order_line_cancel(bigint, bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION inv_order_link_mint(bigint), inv_secure_link_order(text), inv_order_dropships_draft(bigint, bigint), inv_order_dropships_cancel(bigint, bigint),
    inv_order_confirm(bigint, bigint), inv_order_ship(bigint, bigint, jsonb, text, text, text, timestamptz), inv_shipment_deliver(bigint, bigint, timestamptz),
    inv_order_close(bigint, bigint), inv_order_cancel(bigint, bigint, text), inv_order_line_cancel(bigint, bigint) TO inventory_rw;

COMMIT;

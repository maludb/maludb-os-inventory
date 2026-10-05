-- 011: PURCHASING — purchase orders for stock and for drop-ship, their lines, the supplier's events (acknowledged, declined,
-- tracking), the supplier's door, receiving against a PO (design §6 "Purchasing", §4, D9).
--
-- THE ESTATE'S DATA MODEL (design §6.3): NEW (recorded) — the Cidery's purchase_orders (premises_id, purchase units,
-- to_base_factor) compared; ours add the drop-ship half (kind, sales_order_id, the ship-to snapshot, the supplier's events)
-- — the canonical purchase order for a retailer. `supplier_links_secure` is GL db/010's invoice_links_secure shape keyed
-- purchase_order_id; `purchase_order_events` is new (nothing close).
--
-- THE REFEREE'S RULES: a drop-ship PO is drafted at the order's confirmation, one per supplier per order, and PLACED BY A
-- PERSON (money_out pauses an agent — §5); a stock PO is received on a goods receipt; a drop-ship PO's line is received
-- when the customer's line is delivered; a received cost becomes the variant's cost_price when the setting says
-- last_receipt (db/008 for receipts; here for delivered drop-ships); the supplier's door is one token with its own lifetime.
BEGIN;

CREATE TABLE purchase_orders (
    id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    number              text NOT NULL UNIQUE,
    supplier_id         bigint NOT NULL REFERENCES suppliers(id) ON DELETE RESTRICT,
    kind                text NOT NULL DEFAULT 'stock' CHECK (kind IN ('stock', 'dropship')),
    sales_order_id      bigint REFERENCES sales_orders(id) ON DELETE SET NULL,
    status              text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'sent', 'acknowledged', 'partial', 'received', 'closed', 'closed_short', 'cancelled')),
    ship_to_kind        text NOT NULL DEFAULT 'location' CHECK (ship_to_kind IN ('location', 'customer')),
    location_id         bigint REFERENCES locations(id) ON DELETE SET NULL,     -- ship_to_kind location: where it arrives
    ship_to_name        text,                                                   -- ship_to_kind customer: the snapshot from the order
    ship_to_address1    text,
    ship_to_address2    text,
    ship_to_city        text,
    ship_to_region      text,
    ship_to_postal      text,
    ship_to_country     char(2),
    ship_to_phone       text,                                                   -- shown to the supplier only when inv_po_shows_phone() says so
    ship_to_notes       text,
    ordered_on          date NOT NULL DEFAULT current_date,
    expected_on         date,
    supplier_order_ref  text,
    sent_via            text CHECK (sent_via IS NULL OR sent_via IN ('email', 'portal', 'api', 'edi', 'phone')),
    sent_at             timestamptz,
    sent_by             bigint REFERENCES members(id) ON DELETE SET NULL,
    acknowledged_at     timestamptz,
    subtotal            numeric(12,2) NOT NULL DEFAULT 0,
    shipping_cost       numeric(12,2) NOT NULL DEFAULT 0 CHECK (shipping_cost >= 0),
    total               numeric(12,2) NOT NULL DEFAULT 0,
    notes               text,                                                   -- to the supplier (on the door)
    internal_notes      text,                                                   -- never on the door
    approved_by         bigint REFERENCES members(id) ON DELETE SET NULL,
    approved_at         timestamptz,
    closed_at           timestamptz,
    cancelled_by        bigint REFERENCES members(id) ON DELETE SET NULL,
    cancelled_at        timestamptz,
    cancel_reason       text,
    created_by          bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now(),
    CHECK (kind <> 'dropship' OR (sales_order_id IS NOT NULL AND ship_to_kind = 'customer')),
    CHECK (ship_to_kind <> 'location' OR location_id IS NOT NULL)
);
CREATE INDEX purchase_orders_supplier_idx ON purchase_orders (supplier_id, ordered_on DESC);
CREATE INDEX purchase_orders_status_idx ON purchase_orders (status, expected_on);
CREATE INDEX purchase_orders_sales_order_idx ON purchase_orders (sales_order_id);
CREATE TRIGGER purchase_orders_touch BEFORE UPDATE ON purchase_orders FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
CREATE TRIGGER purchase_orders_number BEFORE INSERT ON purchase_orders FOR EACH ROW EXECUTE FUNCTION inv_number_document('purchase_order');

CREATE TABLE purchase_order_lines (
    id                   bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    purchase_order_id    bigint NOT NULL REFERENCES purchase_orders(id) ON DELETE CASCADE,
    line_no              integer NOT NULL,
    variant_id           bigint NOT NULL REFERENCES product_variants(id) ON DELETE RESTRICT,
    supplier_sku         text,
    listing_variant_id   bigint REFERENCES listing_variants(id) ON DELETE SET NULL,   -- the offer it was ordered against
    qty_ordered          integer NOT NULL CHECK (qty_ordered > 0),
    unit_cost            numeric(12,2) NOT NULL DEFAULT 0 CHECK (unit_cost >= 0),
    expected_on          date,
    qty_received         integer NOT NULL DEFAULT 0 CHECK (qty_received >= 0),
    sales_order_line_id  bigint REFERENCES sales_order_lines(id) ON DELETE SET NULL,
    status               text NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'acknowledged', 'declined', 'partial', 'received', 'shipped', 'closed_short', 'cancelled')),
    supplier_note        text,
    tracking_carrier     text,
    tracking_number      text,
    shipped_at           timestamptz,
    created_at           timestamptz NOT NULL DEFAULT now(),
    updated_at           timestamptz NOT NULL DEFAULT now(),
    UNIQUE (purchase_order_id, line_no)
);
CREATE INDEX purchase_order_lines_variant_idx ON purchase_order_lines (variant_id);
CREATE INDEX purchase_order_lines_sales_line_idx ON purchase_order_lines (sales_order_line_id);
CREATE INDEX purchase_order_lines_lv_idx ON purchase_order_lines (listing_variant_id);
CREATE INDEX purchase_order_lines_status_idx ON purchase_order_lines (status);
CREATE TRIGGER purchase_order_lines_touch BEFORE UPDATE ON purchase_order_lines FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

ALTER TABLE sales_order_lines ADD CONSTRAINT sales_order_lines_po_line_fk FOREIGN KEY (purchase_order_line_id) REFERENCES purchase_order_lines(id) ON DELETE SET NULL;
ALTER TABLE goods_receipts ADD CONSTRAINT goods_receipts_po_fk FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE SET NULL;
ALTER TABLE goods_receipt_lines ADD CONSTRAINT goods_receipt_lines_po_line_fk FOREIGN KEY (purchase_order_line_id) REFERENCES purchase_order_lines(id) ON DELETE SET NULL;

-- Lines change while the PO is a draft (the functions write them later under the flag); supplier_sku defaults from the price sheet.
CREATE OR REPLACE FUNCTION inv_po_lines_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE st text; sup bigint;
BEGIN
    SELECT status, supplier_id INTO st, sup FROM purchase_orders WHERE id = COALESCE(NEW.purchase_order_id, OLD.purchase_order_id);
    IF COALESCE(current_setting('inv.balance_writer', true), '') <> 'on' AND st IS DISTINCT FROM 'draft' THEN
        RAISE EXCEPTION 'The lines of a purchase order change only while it is a draft (it is %)', st USING ERRCODE = 'check_violation';
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    IF inv_is_bundle_variant(NEW.variant_id) THEN RAISE EXCEPTION 'A bundle is bought as its components' USING ERRCODE = 'check_violation'; END IF;
    IF NEW.supplier_sku IS NULL THEN
        SELECT supplier_sku INTO NEW.supplier_sku FROM supplier_items WHERE supplier_id = sup AND variant_id = NEW.variant_id;
        IF NEW.supplier_sku IS NULL AND NEW.listing_variant_id IS NOT NULL THEN SELECT sku INTO NEW.supplier_sku FROM listing_variants WHERE id = NEW.listing_variant_id; END IF;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER purchase_order_lines_before BEFORE INSERT OR UPDATE OR DELETE ON purchase_order_lines FOR EACH ROW EXECUTE FUNCTION inv_po_lines_before();

CREATE OR REPLACE FUNCTION inv_po_recompute(p_po_id bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE sub numeric;
BEGIN
    SELECT COALESCE(sum(qty_ordered * unit_cost), 0) INTO sub FROM purchase_order_lines WHERE purchase_order_id = p_po_id AND status NOT IN ('declined', 'cancelled');
    UPDATE purchase_orders SET subtotal = sub, total = sub + shipping_cost WHERE id = p_po_id;
END$$;
CREATE OR REPLACE FUNCTION inv_po_lines_after() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    PERFORM inv_po_recompute(COALESCE(NEW.purchase_order_id, OLD.purchase_order_id));
    RETURN NULL;
END$$;
CREATE TRIGGER purchase_order_lines_after AFTER INSERT OR UPDATE OR DELETE ON purchase_order_lines FOR EACH ROW EXECUTE FUNCTION inv_po_lines_after();
CREATE OR REPLACE FUNCTION inv_po_before() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'UPDATE' AND NEW.shipping_cost IS DISTINCT FROM OLD.shipping_cost THEN NEW.total := NEW.subtotal + NEW.shipping_cost; END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER purchase_orders_before BEFORE UPDATE ON purchase_orders FOR EACH ROW EXECUTE FUNCTION inv_po_before();

-- The supplier's events: what they said, by the door (member_id NULL, source portal) or typed in by a person.
CREATE TABLE purchase_order_events (
    id                      bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    purchase_order_id       bigint NOT NULL REFERENCES purchase_orders(id) ON DELETE CASCADE,
    purchase_order_line_id  bigint REFERENCES purchase_order_lines(id) ON DELETE CASCADE,   -- NULL = the whole order
    kind                    text NOT NULL CHECK (kind IN ('sent', 'placed', 'acknowledge', 'decline', 'tracking', 'received', 'note', 'cancelled')),
    source                  text NOT NULL DEFAULT 'manual' CHECK (source IN ('portal', 'manual', 'email', 'phone')),
    supplier_order_ref      text,
    expected_on             date,
    carrier                 text,
    tracking_number         text,
    shipped_at              timestamptz,
    reason                  text,
    note                    text,
    member_id               bigint REFERENCES members(id) ON DELETE SET NULL,       -- NULL when the supplier did it by the door
    created_at              timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX purchase_order_events_po_idx ON purchase_order_events (purchase_order_id, created_at);
CREATE INDEX purchase_order_events_line_idx ON purchase_order_events (purchase_order_line_id);

-- The supplier's door.
CREATE TABLE supplier_links_secure (
    id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    purchase_order_id  bigint NOT NULL REFERENCES purchase_orders(id) ON DELETE CASCADE,
    token_hash         text NOT NULL UNIQUE,
    expires_at         timestamptz,
    rotated_at         timestamptz,
    last_used_at       timestamptz,
    view_count         integer NOT NULL DEFAULT 0,
    created_at         timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX supplier_links_secure_po_idx ON supplier_links_secure (purchase_order_id) WHERE rotated_at IS NULL;

CREATE OR REPLACE FUNCTION inv_supplier_link_mint(p_po_id bigint) RETURNS text
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE raw text; exp timestamptz;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM purchase_orders WHERE id = p_po_id) THEN RAISE EXCEPTION 'No such purchase order' USING ERRCODE = 'no_data_found'; END IF;
    raw := encode(gen_random_bytes(24), 'hex');
    SELECT CASE WHEN closed_at IS NOT NULL THEN closed_at + make_interval(days => inv_setting_int('supplier_link_days')) END INTO exp FROM purchase_orders WHERE id = p_po_id;
    UPDATE supplier_links_secure SET rotated_at = now() WHERE purchase_order_id = p_po_id AND rotated_at IS NULL;
    INSERT INTO supplier_links_secure (purchase_order_id, token_hash, expires_at) VALUES (p_po_id, encode(sha256(raw::bytea), 'hex'), exp);
    RETURN raw;
END$$;

CREATE OR REPLACE FUNCTION inv_secure_link_purchase_order(p_raw text) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE pid bigint;
BEGIN
    IF p_raw !~ '^[a-f0-9]{48}$' THEN RETURN NULL; END IF;
    UPDATE supplier_links_secure SET last_used_at = now(), view_count = view_count + 1
     WHERE token_hash = encode(sha256(p_raw::bytea), 'hex') AND rotated_at IS NULL AND (expires_at IS NULL OR expires_at > now())
    RETURNING purchase_order_id INTO pid;
    RETURN pid;
END$$;

-- D9: the customer's phone reaches the supplier only for the ships_how kinds the setting names (ltl, white_glove by default).
CREATE OR REPLACE FUNCTION inv_po_shows_phone(p_po_id bigint) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE allowed text[]; k text;
BEGIN
    SELECT supplier_sees_phone INTO allowed FROM inv_settings WHERE id = 1;
    IF NOT EXISTS (SELECT 1 FROM purchase_orders WHERE id = p_po_id AND ship_to_kind = 'customer') THEN RETURN false; END IF;
    RETURN EXISTS (SELECT 1 FROM purchase_order_lines pl JOIN product_variants v ON v.id = pl.variant_id LEFT JOIN products p ON p.id = v.product_id
                    WHERE pl.purchase_order_id = p_po_id AND pl.status NOT IN ('declined', 'cancelled')
                      AND COALESCE(v.ships_how, p.ships_how, 'parcel') = ANY (allowed));
END$$;

-- ---------------------------------------------------------------------------------------------
-- The seams opened in db/010 and db/008, now real.
-- ---------------------------------------------------------------------------------------------
-- One draft PO per supplier for the confirmed order's drop-ship lines, the offer's cost and lead time from the LINE's
-- snapshot, the ship-to the customer's. Returns how many POs were drafted. Never sent here (a person does).
CREATE OR REPLACE FUNCTION inv_order_dropships_draft(p_order_id bigint, p_by bigint) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE o sales_orders%ROWTYPE; sup record; l record; po_id bigint; n integer := 0; ln integer; plid bigint;
BEGIN
    SELECT * INTO o FROM sales_orders WHERE id = p_order_id;
    FOR sup IN SELECT DISTINCT s.supplier_id FROM sales_order_lines sol JOIN sources s ON s.id = sol.source_id
                WHERE sol.sales_order_id = p_order_id AND sol.fulfilment_kind = 'dropship' AND sol.status = 'open' AND sol.purchase_order_line_id IS NULL
    LOOP
        INSERT INTO purchase_orders (supplier_id, kind, sales_order_id, ship_to_kind, ship_to_name, ship_to_address1, ship_to_address2, ship_to_city, ship_to_region,
                                     ship_to_postal, ship_to_country, ship_to_phone, ship_to_notes, ordered_on, created_by, notes)
        VALUES (sup.supplier_id, 'dropship', p_order_id, 'customer', o.ship_to_name, o.ship_to_address1, o.ship_to_address2, o.ship_to_city, o.ship_to_region,
                o.ship_to_postal, o.ship_to_country, o.ship_to_phone, o.ship_to_notes, current_date, p_by, 'Drop-ship for our order ' || o.number)
        RETURNING id INTO po_id;
        ln := 0;
        PERFORM set_config('inv.balance_writer', 'on', true);
        FOR l IN SELECT sol.* FROM sales_order_lines sol JOIN sources s ON s.id = sol.source_id
                  WHERE sol.sales_order_id = p_order_id AND sol.fulfilment_kind = 'dropship' AND sol.status = 'open' AND sol.purchase_order_line_id IS NULL AND s.supplier_id = sup.supplier_id
                  ORDER BY sol.line_no
        LOOP
            ln := ln + 1;
            INSERT INTO purchase_order_lines (purchase_order_id, line_no, variant_id, listing_variant_id, qty_ordered, unit_cost, expected_on, sales_order_line_id)
            VALUES (po_id, ln, l.variant_id, l.listing_variant_id, l.qty, COALESCE(l.offer_cost, 0), current_date + COALESCE(l.offer_lead_time_days, 0), l.id)
            RETURNING id INTO plid;
            UPDATE sales_order_lines SET purchase_order_line_id = plid WHERE id = l.id;
        END LOOP;
        PERFORM set_config('inv.balance_writer', '', true);
        UPDATE purchase_orders SET expected_on = (SELECT max(expected_on) FROM purchase_order_lines WHERE purchase_order_id = po_id) WHERE id = po_id;
        n := n + 1;
    END LOOP;
    RETURN n;
END$$;

CREATE OR REPLACE FUNCTION inv_order_dropships_cancel(p_order_id bigint, p_by bigint) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    PERFORM set_config('inv.balance_writer', 'on', true);
    UPDATE purchase_order_lines SET status = 'cancelled' WHERE purchase_order_id IN (SELECT id FROM purchase_orders WHERE sales_order_id = p_order_id AND kind = 'dropship' AND status = 'draft');
    UPDATE purchase_orders SET status = 'cancelled', cancelled_by = p_by, cancelled_at = now(), cancel_reason = 'the customer''s order was cancelled'
     WHERE sales_order_id = p_order_id AND kind = 'dropship' AND status = 'draft';
    GET DIAGNOSTICS n = ROW_COUNT;
    PERFORM set_config('inv.balance_writer', '', true);
    RETURN n;
END$$;

-- A posted receipt's lines against a PO: qty_received, the line's and the order's status.
CREATE OR REPLACE FUNCTION inv_po_refresh_status(p_po_id bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE st text; live integer; done integer; any_recv integer;
BEGIN
    SELECT status INTO st FROM purchase_orders WHERE id = p_po_id;
    IF st IN ('draft', 'closed', 'closed_short', 'cancelled') THEN RETURN; END IF;
    SELECT count(*) FILTER (WHERE status NOT IN ('declined', 'cancelled')),
           count(*) FILTER (WHERE status = 'received'),
           count(*) FILTER (WHERE qty_received > 0 OR status = 'received')
      INTO live, done, any_recv FROM purchase_order_lines WHERE purchase_order_id = p_po_id;
    UPDATE purchase_orders SET status = CASE WHEN live > 0 AND done = live THEN 'received'
                                             WHEN any_recv > 0 THEN 'partial'
                                             WHEN st = 'partial' THEN 'acknowledged'
                                             ELSE st END
     WHERE id = p_po_id;
END$$;

CREATE OR REPLACE FUNCTION inv_receipt_posted_hook(p_receipt_id bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r goods_receipts%ROWTYPE; l record; pl purchase_order_lines%ROWTYPE;
BEGIN
    SELECT * INTO r FROM goods_receipts WHERE id = p_receipt_id;
    PERFORM set_config('inv.balance_writer', 'on', true);
    FOR l IN SELECT * FROM goods_receipt_lines WHERE goods_receipt_id = p_receipt_id AND purchase_order_line_id IS NOT NULL LOOP
        SELECT * INTO pl FROM purchase_order_lines WHERE id = l.purchase_order_line_id FOR UPDATE;
        IF r.purchase_order_id IS NOT NULL AND pl.purchase_order_id <> r.purchase_order_id THEN
            PERFORM set_config('inv.balance_writer', '', true);
            RAISE EXCEPTION 'Receipt line % belongs to another purchase order', l.line_no USING ERRCODE = 'check_violation';
        END IF;
        UPDATE purchase_order_lines SET qty_received = qty_received + l.qty,
                                        status = CASE WHEN qty_received + l.qty >= qty_ordered THEN 'received' ELSE 'partial' END
         WHERE id = pl.id;
        INSERT INTO purchase_order_events (purchase_order_id, purchase_order_line_id, kind, source, note, member_id)
        VALUES (pl.purchase_order_id, pl.id, 'received', 'manual', format('%s × received on %s', l.qty, r.number), r.posted_by);
        PERFORM inv_po_refresh_status(pl.purchase_order_id);
    END LOOP;
    PERFORM set_config('inv.balance_writer', '', true);
END$$;

-- ---------------------------------------------------------------------------------------------
-- The verbs: send, place, acknowledge, decline a line, tracking, receive against, close, cancel.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION inv_po_send(p_po_id bigint, p_by bigint, p_via text DEFAULT 'email') RETURNS purchase_orders
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE po purchase_orders%ROWTYPE;
BEGIN
    SELECT * INTO po FROM purchase_orders WHERE id = p_po_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such purchase order' USING ERRCODE = 'no_data_found'; END IF;
    IF po.status <> 'draft' THEN RAISE EXCEPTION 'Purchase order % is % — only a draft is sent', po.number, po.status USING ERRCODE = 'check_violation'; END IF;
    IF NOT EXISTS (SELECT 1 FROM purchase_order_lines WHERE purchase_order_id = p_po_id AND status = 'open') THEN RAISE EXCEPTION 'Purchase order % has no open lines', po.number USING ERRCODE = 'check_violation'; END IF;
    UPDATE purchase_orders SET status = 'sent', sent_via = p_via, sent_at = now(), sent_by = p_by, approved_by = p_by, approved_at = now() WHERE id = p_po_id RETURNING * INTO po;
    PERFORM set_config('inv.balance_writer', 'on', true);
    UPDATE sales_order_lines SET status = 'ordered' WHERE purchase_order_line_id IN (SELECT id FROM purchase_order_lines WHERE purchase_order_id = p_po_id) AND status = 'open';
    PERFORM set_config('inv.balance_writer', '', true);
    INSERT INTO purchase_order_events (purchase_order_id, kind, source, member_id, note) VALUES (p_po_id, 'sent', 'manual', p_by, 'sent by ' || p_via);
    RETURN po;
END$$;

-- Placed on the supplier's portal (or by phone) by a person, who records the supplier's reference.
CREATE OR REPLACE FUNCTION inv_po_place(p_po_id bigint, p_by bigint, p_supplier_ref text, p_via text DEFAULT 'portal') RETURNS purchase_orders
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE po purchase_orders%ROWTYPE;
BEGIN
    SELECT * INTO po FROM purchase_orders WHERE id = p_po_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such purchase order' USING ERRCODE = 'no_data_found'; END IF;
    IF po.status = 'draft' THEN po := inv_po_send(p_po_id, p_by, p_via); END IF;
    IF po.status <> 'sent' THEN RAISE EXCEPTION 'Purchase order % is % — a sent order is placed', po.number, po.status USING ERRCODE = 'check_violation'; END IF;
    UPDATE purchase_orders SET supplier_order_ref = COALESCE(p_supplier_ref, supplier_order_ref), sent_via = p_via WHERE id = p_po_id RETURNING * INTO po;
    INSERT INTO purchase_order_events (purchase_order_id, kind, source, member_id, supplier_order_ref) VALUES (p_po_id, 'placed', 'manual', p_by, p_supplier_ref);
    RETURN po;
END$$;

-- Acknowledge: the whole order (line NULL) or one line, with the supplier's reference and an expected date.
CREATE OR REPLACE FUNCTION inv_po_acknowledge(p_po_id bigint, p_line_id bigint, p_supplier_ref text, p_expected_on date, p_source text DEFAULT 'portal', p_by bigint DEFAULT NULL) RETURNS purchase_orders
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE po purchase_orders%ROWTYPE;
BEGIN
    SELECT * INTO po FROM purchase_orders WHERE id = p_po_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such purchase order' USING ERRCODE = 'no_data_found'; END IF;
    IF po.status NOT IN ('sent', 'acknowledged', 'partial') THEN RAISE EXCEPTION 'Purchase order % is % — a sent order is acknowledged', po.number, po.status USING ERRCODE = 'check_violation'; END IF;
    PERFORM set_config('inv.balance_writer', 'on', true);
    UPDATE purchase_order_lines SET status = 'acknowledged', expected_on = COALESCE(p_expected_on, expected_on)
     WHERE purchase_order_id = p_po_id AND status = 'open' AND (p_line_id IS NULL OR id = p_line_id);
    PERFORM set_config('inv.balance_writer', '', true);
    INSERT INTO purchase_order_events (purchase_order_id, purchase_order_line_id, kind, source, supplier_order_ref, expected_on, member_id)
    VALUES (p_po_id, p_line_id, 'acknowledge', p_source, p_supplier_ref, p_expected_on, p_by);
    UPDATE purchase_orders SET supplier_order_ref = COALESCE(p_supplier_ref, supplier_order_ref),
                               expected_on = COALESCE((SELECT max(expected_on) FROM purchase_order_lines WHERE purchase_order_id = p_po_id AND status NOT IN ('declined', 'cancelled')), expected_on),
                               acknowledged_at = COALESCE(acknowledged_at, now()),
                               status = CASE WHEN status = 'sent' AND NOT EXISTS (SELECT 1 FROM purchase_order_lines WHERE purchase_order_id = p_po_id AND status = 'open') THEN 'acknowledged' ELSE status END
     WHERE id = p_po_id RETURNING * INTO po;
    RETURN po;
END$$;

-- Decline a line: the supplier cannot fill it. The customer's line goes back to open — at risk, on the morning note.
CREATE OR REPLACE FUNCTION inv_po_decline_line(p_line_id bigint, p_reason text, p_source text DEFAULT 'portal', p_by bigint DEFAULT NULL) RETURNS purchase_order_lines
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE pl purchase_order_lines%ROWTYPE; st text;
BEGIN
    SELECT * INTO pl FROM purchase_order_lines WHERE id = p_line_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such line' USING ERRCODE = 'no_data_found'; END IF;
    SELECT status INTO st FROM purchase_orders WHERE id = pl.purchase_order_id;
    IF st NOT IN ('sent', 'acknowledged', 'partial') THEN RAISE EXCEPTION 'A line of a sent order is declined (the order is %)', st USING ERRCODE = 'check_violation'; END IF;
    IF pl.status NOT IN ('open', 'acknowledged') THEN RAISE EXCEPTION 'Line % is % — it cannot be declined', pl.line_no, pl.status USING ERRCODE = 'check_violation'; END IF;
    PERFORM set_config('inv.balance_writer', 'on', true);
    UPDATE purchase_order_lines SET status = 'declined', supplier_note = p_reason WHERE id = p_line_id RETURNING * INTO pl;
    UPDATE sales_order_lines SET status = 'open' WHERE purchase_order_line_id = p_line_id AND status = 'ordered';
    PERFORM set_config('inv.balance_writer', '', true);
    INSERT INTO purchase_order_events (purchase_order_id, purchase_order_line_id, kind, source, reason, member_id) VALUES (pl.purchase_order_id, p_line_id, 'decline', p_source, p_reason, p_by);
    PERFORM inv_po_recompute(pl.purchase_order_id);
    PERFORM inv_po_refresh_status(pl.purchase_order_id);
    RETURN pl;
END$$;

-- Tracking on a line: the supplier shipped it. For a drop-ship line that is the customer's shipment: a `dropship`
-- shipment on the sales order, the customer's line shipped.
CREATE OR REPLACE FUNCTION inv_po_tracking(p_line_id bigint, p_carrier text, p_tracking text, p_shipped_at timestamptz DEFAULT now(), p_source text DEFAULT 'portal', p_by bigint DEFAULT NULL) RETURNS purchase_order_lines
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE pl purchase_order_lines%ROWTYPE; po purchase_orders%ROWTYPE; sol sales_order_lines%ROWTYPE; sh shipments%ROWTYPE;
BEGIN
    SELECT * INTO pl FROM purchase_order_lines WHERE id = p_line_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such line' USING ERRCODE = 'no_data_found'; END IF;
    SELECT * INTO po FROM purchase_orders WHERE id = pl.purchase_order_id;
    IF po.status NOT IN ('sent', 'acknowledged', 'partial') THEN RAISE EXCEPTION 'Tracking is added to a sent order (it is %)', po.status USING ERRCODE = 'check_violation'; END IF;
    IF pl.status IN ('declined', 'cancelled', 'received') THEN RAISE EXCEPTION 'Line % is %', pl.line_no, pl.status USING ERRCODE = 'check_violation'; END IF;
    PERFORM set_config('inv.balance_writer', 'on', true);
    UPDATE purchase_order_lines SET status = 'shipped', tracking_carrier = p_carrier, tracking_number = p_tracking, shipped_at = COALESCE(p_shipped_at, now()) WHERE id = p_line_id RETURNING * INTO pl;
    INSERT INTO purchase_order_events (purchase_order_id, purchase_order_line_id, kind, source, carrier, tracking_number, shipped_at, member_id)
    VALUES (po.id, p_line_id, 'tracking', p_source, p_carrier, p_tracking, COALESCE(p_shipped_at, now()), p_by);
    IF po.kind = 'dropship' AND pl.sales_order_line_id IS NOT NULL THEN
        SELECT * INTO sol FROM sales_order_lines WHERE id = pl.sales_order_line_id FOR UPDATE;
        IF sol.status IN ('open', 'ordered') THEN
            INSERT INTO shipments (sales_order_id, kind, carrier, tracking_number, shipped_at, shipped_by, note)
            VALUES (sol.sales_order_id, 'dropship', p_carrier, p_tracking, COALESCE(p_shipped_at, now()), p_by, 'from our supplier on ' || po.number) RETURNING * INTO sh;
            INSERT INTO shipment_lines (shipment_id, sales_order_line_id, qty) VALUES (sh.id, sol.id, sol.qty - sol.qty_shipped);
            UPDATE sales_order_lines SET qty_shipped = qty, status = 'shipped' WHERE id = sol.id;
            UPDATE sales_orders SET status = CASE WHEN NOT EXISTS (SELECT 1 FROM sales_order_lines WHERE sales_order_id = sol.sales_order_id AND status NOT IN ('shipped', 'delivered', 'cancelled'))
                                                  THEN 'shipped' ELSE 'in_fulfilment' END
             WHERE id = sol.sales_order_id AND status IN ('confirmed', 'in_fulfilment');
        END IF;
    END IF;
    PERFORM set_config('inv.balance_writer', '', true);
    RETURN pl;
END$$;

-- A drop-ship line is RECEIVED when the customer's shipment is delivered; its cost becomes the variant's when the setting
-- says last_receipt (D11).
CREATE OR REPLACE FUNCTION inv_dropship_delivered() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE pl record; csrc text; v_old numeric; pon text;
BEGIN
    IF NEW.kind <> 'dropship' OR NEW.delivered_at IS NULL OR OLD.delivered_at IS NOT NULL THEN RETURN NULL; END IF;
    SELECT cost_source INTO csrc FROM inv_settings WHERE id = 1;
    PERFORM set_config('inv.balance_writer', 'on', true);
    FOR pl IN SELECT p.*, po.number AS po_number FROM purchase_order_lines p JOIN purchase_orders po ON po.id = p.purchase_order_id
               WHERE p.sales_order_line_id IN (SELECT sales_order_line_id FROM shipment_lines WHERE shipment_id = NEW.id) AND p.status = 'shipped'
    LOOP
        UPDATE purchase_order_lines SET status = 'received', qty_received = qty_ordered WHERE id = pl.id;
        INSERT INTO purchase_order_events (purchase_order_id, purchase_order_line_id, kind, source, note) VALUES (pl.purchase_order_id, pl.id, 'received', 'manual', 'delivered to the customer');
        PERFORM inv_po_refresh_status(pl.purchase_order_id);
        IF csrc = 'last_receipt' AND pl.unit_cost > 0 THEN
            SELECT cost_price INTO v_old FROM product_variants WHERE id = pl.variant_id;
            IF v_old IS DISTINCT FROM pl.unit_cost THEN PERFORM inv_price_set(pl.variant_id, 'cost', pl.unit_cost, 'drop-ship ' || pl.po_number, 'last_receipt'); END IF;
        END IF;
    END LOOP;
    PERFORM set_config('inv.balance_writer', '', true);
    RETURN NULL;
END$$;
CREATE TRIGGER shipments_dropship_delivered AFTER UPDATE OF delivered_at ON shipments FOR EACH ROW EXECUTE FUNCTION inv_dropship_delivered();

-- Receive against: a DRAFT goods receipt for everything still open on a stock PO; the warehouse edits quantities and
-- costs, then posts it (db/008), which comes back through inv_receipt_posted_hook.
CREATE OR REPLACE FUNCTION inv_receive_against(p_po_id bigint, p_by bigint, p_location_id bigint DEFAULT NULL) RETURNS goods_receipts
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE po purchase_orders%ROWTYPE; r goods_receipts%ROWTYPE; l record; ln integer := 0;
BEGIN
    SELECT * INTO po FROM purchase_orders WHERE id = p_po_id;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such purchase order' USING ERRCODE = 'no_data_found'; END IF;
    IF po.kind <> 'stock' THEN RAISE EXCEPTION 'A drop-ship order is received when the customer''s line is delivered' USING ERRCODE = 'check_violation'; END IF;
    IF po.status NOT IN ('sent', 'acknowledged', 'partial') THEN RAISE EXCEPTION 'Purchase order % is % — a sent order is received', po.number, po.status USING ERRCODE = 'check_violation'; END IF;
    INSERT INTO goods_receipts (supplier_id, purchase_order_id, location_id, created_by, notes)
    VALUES (po.supplier_id, po.id, COALESCE(p_location_id, po.location_id), p_by, 'against ' || po.number) RETURNING * INTO r;
    FOR l IN SELECT * FROM purchase_order_lines WHERE purchase_order_id = p_po_id AND status NOT IN ('declined', 'cancelled', 'received') AND qty_ordered > qty_received ORDER BY line_no LOOP
        ln := ln + 1;
        INSERT INTO goods_receipt_lines (goods_receipt_id, line_no, purchase_order_line_id, variant_id, qty, unit_cost)
        VALUES (r.id, ln, l.id, l.variant_id, l.qty_ordered - l.qty_received, l.unit_cost);
    END LOOP;
    IF ln = 0 THEN RAISE EXCEPTION 'Nothing is open on %', po.number USING ERRCODE = 'check_violation'; END IF;
    RETURN r;
END$$;

CREATE OR REPLACE FUNCTION inv_po_close(p_po_id bigint, p_by bigint) RETURNS purchase_orders
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE po purchase_orders%ROWTYPE; short boolean;
BEGIN
    SELECT * INTO po FROM purchase_orders WHERE id = p_po_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such purchase order' USING ERRCODE = 'no_data_found'; END IF;
    IF po.status NOT IN ('sent', 'acknowledged', 'partial', 'received') THEN RAISE EXCEPTION 'Purchase order % is % — it does not close', po.number, po.status USING ERRCODE = 'check_violation'; END IF;
    short := EXISTS (SELECT 1 FROM purchase_order_lines WHERE purchase_order_id = p_po_id AND status NOT IN ('received', 'declined', 'cancelled'));
    PERFORM set_config('inv.balance_writer', 'on', true);
    UPDATE purchase_order_lines SET status = 'closed_short' WHERE purchase_order_id = p_po_id AND status NOT IN ('received', 'declined', 'cancelled');
    PERFORM set_config('inv.balance_writer', '', true);
    UPDATE purchase_orders SET status = CASE WHEN short THEN 'closed_short' ELSE 'closed' END, closed_at = now() WHERE id = p_po_id RETURNING * INTO po;
    UPDATE supplier_links_secure SET expires_at = LEAST(COALESCE(expires_at, 'infinity'::timestamptz), now() + make_interval(days => inv_setting_int('supplier_link_days')))
     WHERE purchase_order_id = p_po_id AND rotated_at IS NULL;
    RETURN po;
END$$;

CREATE OR REPLACE FUNCTION inv_po_cancel(p_po_id bigint, p_by bigint, p_reason text) RETURNS purchase_orders
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE po purchase_orders%ROWTYPE;
BEGIN
    SELECT * INTO po FROM purchase_orders WHERE id = p_po_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such purchase order' USING ERRCODE = 'no_data_found'; END IF;
    IF po.status NOT IN ('draft', 'sent', 'acknowledged') THEN RAISE EXCEPTION 'Purchase order % is % — close it instead', po.number, po.status USING ERRCODE = 'check_violation'; END IF;
    IF EXISTS (SELECT 1 FROM purchase_order_lines WHERE purchase_order_id = p_po_id AND (qty_received > 0 OR status = 'shipped')) THEN
        RAISE EXCEPTION 'Purchase order % has goods received or shipped — close it short instead', po.number USING ERRCODE = 'check_violation';
    END IF;
    PERFORM set_config('inv.balance_writer', 'on', true);
    UPDATE sales_order_lines SET status = 'open' WHERE purchase_order_line_id IN (SELECT id FROM purchase_order_lines WHERE purchase_order_id = p_po_id) AND status = 'ordered';
    UPDATE purchase_order_lines SET status = 'cancelled' WHERE purchase_order_id = p_po_id AND status <> 'cancelled';
    PERFORM set_config('inv.balance_writer', '', true);
    UPDATE purchase_orders SET status = 'cancelled', cancelled_by = p_by, cancelled_at = now(), cancel_reason = p_reason WHERE id = p_po_id RETURNING * INTO po;
    INSERT INTO purchase_order_events (purchase_order_id, kind, source, reason, member_id) VALUES (p_po_id, 'cancelled', 'manual', p_reason, p_by);
    UPDATE supplier_links_secure SET expires_at = now() WHERE purchase_order_id = p_po_id AND rotated_at IS NULL;
    RETURN po;
END$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON purchase_orders, purchase_order_lines, purchase_order_events, supplier_links_secure TO inventory_rw;
GRANT EXECUTE ON FUNCTION inv_po_recompute(bigint), inv_po_shows_phone(bigint) TO inventory_rw, inventory_records_ro;
REVOKE ALL ON FUNCTION inv_supplier_link_mint(bigint), inv_secure_link_purchase_order(text), inv_po_refresh_status(bigint), inv_po_send(bigint, bigint, text),
    inv_po_place(bigint, bigint, text, text), inv_po_acknowledge(bigint, bigint, text, date, text, bigint), inv_po_decline_line(bigint, text, text, bigint),
    inv_po_tracking(bigint, text, text, timestamptz, text, bigint), inv_receive_against(bigint, bigint, bigint), inv_po_close(bigint, bigint), inv_po_cancel(bigint, bigint, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION inv_supplier_link_mint(bigint), inv_secure_link_purchase_order(text), inv_po_refresh_status(bigint), inv_po_send(bigint, bigint, text),
    inv_po_place(bigint, bigint, text, text), inv_po_acknowledge(bigint, bigint, text, date, text, bigint), inv_po_decline_line(bigint, text, text, bigint),
    inv_po_tracking(bigint, text, text, timestamptz, text, bigint), inv_receive_against(bigint, bigint, bigint), inv_po_close(bigint, bigint), inv_po_cancel(bigint, bigint, text) TO inventory_rw;

COMMIT;

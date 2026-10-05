-- 012: RETURNS — a return authorization on a sales order, its lines with a reason and a disposition, receiving it back
-- (design §6 "Returns", §0.1 "Trials and returns", D14).
--
-- THE ESTATE'S DATA MODEL (design §6.3): NEW — nothing close in the estate.
--
-- THE REFEREE'S RULES: a line returns at most what shipped and was not yet returned; receiving a return with `restock`
-- writes a `return` transaction at the location it comes back to; `floor_model` writes the return AND puts the unit on the
-- floor; `dispose` and `donate` touch no stock (a returned mattress is never resold as new); `return_to_supplier` drafts a
-- note on the supplier's purchase order (a vendor-return document is Extended); the refund is RECORDED (the amount here,
-- the payment on the order — D10), never charged.
BEGIN;

CREATE TABLE return_authorizations (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    number          text NOT NULL UNIQUE,
    sales_order_id  bigint NOT NULL REFERENCES sales_orders(id) ON DELETE RESTRICT,
    customer_id     bigint NOT NULL REFERENCES customers(id) ON DELETE RESTRICT,
    status          text NOT NULL DEFAULT 'requested' CHECK (status IN ('requested', 'approved', 'received', 'closed', 'denied')),
    method          text NOT NULL DEFAULT 'pickup' CHECK (method IN ('pickup', 'drop_off')),
    scheduled_on    date,
    location_id     bigint REFERENCES locations(id) ON DELETE SET NULL,    -- where it comes back to (a line may override)
    refund_amount   numeric(12,2) NOT NULL DEFAULT 0 CHECK (refund_amount >= 0),
    restocking_fee  numeric(12,2) NOT NULL DEFAULT 0 CHECK (restocking_fee >= 0),
    notes           text,
    requested_by    bigint REFERENCES members(id) ON DELETE SET NULL,
    approved_by     bigint REFERENCES members(id) ON DELETE SET NULL,
    approved_at     timestamptz,
    received_by     bigint REFERENCES members(id) ON DELETE SET NULL,
    received_at     timestamptz,
    closed_by       bigint REFERENCES members(id) ON DELETE SET NULL,
    closed_at       timestamptz,
    denied_by       bigint REFERENCES members(id) ON DELETE SET NULL,
    denied_at       timestamptz,
    deny_reason     text,
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX return_authorizations_order_idx ON return_authorizations (sales_order_id);
CREATE INDEX return_authorizations_customer_idx ON return_authorizations (customer_id);
CREATE INDEX return_authorizations_status_idx ON return_authorizations (status, created_at DESC);
CREATE TRIGGER return_authorizations_touch BEFORE UPDATE ON return_authorizations FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
CREATE TRIGGER return_authorizations_number BEFORE INSERT ON return_authorizations FOR EACH ROW EXECUTE FUNCTION inv_number_document('return');

CREATE OR REPLACE FUNCTION inv_returns_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE ost text; cid bigint;
BEGIN
    IF TG_OP = 'INSERT' THEN
        SELECT status, customer_id INTO ost, cid FROM sales_orders WHERE id = NEW.sales_order_id;
        IF ost IS NULL THEN RAISE EXCEPTION 'No such order' USING ERRCODE = 'no_data_found'; END IF;
        IF ost IN ('quote', 'cancelled') THEN RAISE EXCEPTION 'A return is against a confirmed order (it is %)', ost USING ERRCODE = 'check_violation'; END IF;
        NEW.customer_id := cid;
        IF NEW.requested_by IS NULL THEN NEW.requested_by := app_current_member_id(); END IF;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER return_authorizations_before BEFORE INSERT OR UPDATE ON return_authorizations FOR EACH ROW EXECUTE FUNCTION inv_returns_before();

CREATE TABLE return_lines (
    id                   bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    return_id            bigint NOT NULL REFERENCES return_authorizations(id) ON DELETE CASCADE,
    sales_order_line_id  bigint NOT NULL REFERENCES sales_order_lines(id) ON DELETE RESTRICT,
    variant_id           bigint NOT NULL REFERENCES product_variants(id) ON DELETE RESTRICT,
    qty                  integer NOT NULL CHECK (qty > 0),
    reason_code_id       bigint NOT NULL REFERENCES reason_codes(id) ON DELETE RESTRICT,
    disposition          text NOT NULL DEFAULT 'restock' CHECK (disposition IN ('restock', 'floor_model', 'dispose', 'return_to_supplier', 'donate')),
    location_id          bigint REFERENCES locations(id) ON DELETE SET NULL,   -- overrides the header's
    condition_note       text,
    qty_received         integer NOT NULL DEFAULT 0 CHECK (qty_received >= 0),
    created_at           timestamptz NOT NULL DEFAULT now(),
    updated_at           timestamptz NOT NULL DEFAULT now(),
    UNIQUE (return_id, sales_order_line_id)
);
CREATE INDEX return_lines_order_line_idx ON return_lines (sales_order_line_id);
CREATE INDEX return_lines_variant_idx ON return_lines (variant_id);
CREATE TRIGGER return_lines_touch BEFORE UPDATE ON return_lines FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- A line: its variant is the order line's; at most what shipped and is not yet returned (counting other open returns);
-- the reason is a return reason; lines change while requested or approved only (the functions write afterwards).
CREATE OR REPLACE FUNCTION inv_return_lines_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE st text; sol sales_order_lines%ROWTYPE; oid bigint; pending integer; applies text[];
BEGIN
    SELECT status, sales_order_id INTO st, oid FROM return_authorizations WHERE id = COALESCE(NEW.return_id, OLD.return_id);
    IF COALESCE(current_setting('inv.balance_writer', true), '') <> 'on' AND st NOT IN ('requested', 'approved') THEN
        RAISE EXCEPTION 'The lines of a return change while it is requested or approved (it is %)', st USING ERRCODE = 'check_violation';
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    SELECT * INTO sol FROM sales_order_lines WHERE id = NEW.sales_order_line_id;
    IF NOT FOUND OR sol.sales_order_id <> oid THEN RAISE EXCEPTION 'That line is not on the return''s order' USING ERRCODE = 'check_violation'; END IF;
    NEW.variant_id := sol.variant_id;
    SELECT COALESCE(sum(rl.qty), 0) INTO pending FROM return_lines rl JOIN return_authorizations ra ON ra.id = rl.return_id
     WHERE rl.sales_order_line_id = NEW.sales_order_line_id AND ra.status IN ('requested', 'approved') AND rl.id IS DISTINCT FROM NEW.id;
    IF NEW.qty > sol.qty_shipped - sol.qty_returned - pending THEN
        RAISE EXCEPTION 'Line % shipped % and % may still come back', sol.line_no, sol.qty_shipped, GREATEST(0, sol.qty_shipped - sol.qty_returned - pending) USING ERRCODE = 'check_violation';
    END IF;
    SELECT applies_to INTO applies FROM reason_codes WHERE id = NEW.reason_code_id;
    IF NOT ('return' = ANY (COALESCE(applies, '{}'))) THEN RAISE EXCEPTION 'That reason is not a return reason' USING ERRCODE = 'check_violation'; END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER return_lines_before BEFORE INSERT OR UPDATE OR DELETE ON return_lines FOR EACH ROW EXECUTE FUNCTION inv_return_lines_before();

CREATE OR REPLACE FUNCTION inv_return_approve(p_return_id bigint, p_by bigint) RETURNS return_authorizations
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE ra return_authorizations%ROWTYPE;
BEGIN
    SELECT * INTO ra FROM return_authorizations WHERE id = p_return_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such return' USING ERRCODE = 'no_data_found'; END IF;
    IF ra.status <> 'requested' THEN RAISE EXCEPTION 'Return % is % — a requested return is approved', ra.number, ra.status USING ERRCODE = 'check_violation'; END IF;
    IF NOT EXISTS (SELECT 1 FROM return_lines WHERE return_id = p_return_id) THEN RAISE EXCEPTION 'Return % has no lines', ra.number USING ERRCODE = 'check_violation'; END IF;
    UPDATE return_authorizations SET status = 'approved', approved_by = p_by, approved_at = now() WHERE id = p_return_id RETURNING * INTO ra;
    RETURN ra;
END$$;

CREATE OR REPLACE FUNCTION inv_return_deny(p_return_id bigint, p_by bigint, p_reason text) RETURNS return_authorizations
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE ra return_authorizations%ROWTYPE;
BEGIN
    SELECT * INTO ra FROM return_authorizations WHERE id = p_return_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such return' USING ERRCODE = 'no_data_found'; END IF;
    IF ra.status NOT IN ('requested', 'approved') THEN RAISE EXCEPTION 'Return % is % — it cannot be denied', ra.number, ra.status USING ERRCODE = 'check_violation'; END IF;
    UPDATE return_authorizations SET status = 'denied', denied_by = p_by, denied_at = now(), deny_reason = p_reason WHERE id = p_return_id RETURNING * INTO ra;
    RETURN ra;
END$$;

-- Receive: every line as its disposition says; the order's lines count what came back.
CREATE OR REPLACE FUNCTION inv_return_receive(p_return_id bigint, p_by bigint) RETURNS return_authorizations
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE ra return_authorizations%ROWTYPE; l record; loc bigint; g uuid := gen_random_uuid(); c numeric; cust bigint; po_id bigint; fm bigint; tid bigint;
BEGIN
    SELECT * INTO ra FROM return_authorizations WHERE id = p_return_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such return' USING ERRCODE = 'no_data_found'; END IF;
    IF ra.status <> 'approved' THEN RAISE EXCEPTION 'Return % is % — an approved return is received', ra.number, ra.status USING ERRCODE = 'check_violation'; END IF;
    SELECT id INTO fm FROM reason_codes WHERE code = 'floor_model';
    cust := ra.customer_id;
    PERFORM set_config('inv.balance_writer', 'on', true);
    FOR l IN SELECT * FROM return_lines WHERE return_id = p_return_id ORDER BY id LOOP
        loc := COALESCE(l.location_id, ra.location_id);
        SELECT cost_price INTO c FROM product_variants WHERE id = l.variant_id;
        IF l.disposition IN ('restock', 'floor_model') THEN
            IF loc IS NULL THEN PERFORM set_config('inv.balance_writer', '', true); RAISE EXCEPTION 'A restocked line needs a location' USING ERRCODE = 'check_violation'; END IF;
            tid := inv_post_txn('return', l.variant_id, loc, l.qty, c, 'return', p_return_id, 'return:' || l.id, 'customer', cust, l.reason_code_id, g, p_by, NULL, l.condition_note);
            IF l.disposition = 'floor_model' THEN
                PERFORM inv_post_txn('floor_model_in', l.variant_id, loc, l.qty, c, 'return', p_return_id, 'return_floor:' || l.id, 'none', NULL, fm, g, p_by, NULL, 'returned unit to the floor');
            END IF;
        ELSIF l.disposition = 'return_to_supplier' THEN
            SELECT pl.purchase_order_id INTO po_id FROM sales_order_lines sol JOIN purchase_order_lines pl ON pl.id = sol.purchase_order_line_id WHERE sol.id = l.sales_order_line_id;
            IF po_id IS NOT NULL THEN
                INSERT INTO purchase_order_events (purchase_order_id, purchase_order_line_id, kind, source, note, member_id)
                SELECT po_id, sol.purchase_order_line_id, 'note', 'manual', format('Return %s: %s × to return to supplier (%s)', ra.number, l.qty, COALESCE(l.condition_note, 'no note')), p_by
                  FROM sales_order_lines sol WHERE sol.id = l.sales_order_line_id;
                UPDATE purchase_orders SET internal_notes = concat_ws(E'\n', internal_notes, format('Return %s: %s × to return to supplier', ra.number, l.qty)) WHERE id = po_id;
            END IF;
        END IF;   -- dispose, donate: no stock
        PERFORM set_config('inv.balance_writer', 'on', true);
        UPDATE return_lines SET qty_received = l.qty WHERE id = l.id;
        UPDATE sales_order_lines SET qty_returned = qty_returned + l.qty, status = CASE WHEN qty_returned + l.qty >= qty THEN 'returned' ELSE status END WHERE id = l.sales_order_line_id;
    END LOOP;
    PERFORM set_config('inv.balance_writer', '', true);
    UPDATE return_authorizations SET status = 'received', received_by = p_by, received_at = now() WHERE id = p_return_id RETURNING * INTO ra;
    RETURN ra;
END$$;

-- Close: the refund amount and restocking fee recorded (the money itself is an order_payments refund row — D10).
CREATE OR REPLACE FUNCTION inv_return_close(p_return_id bigint, p_by bigint, p_refund_amount numeric DEFAULT NULL, p_restocking_fee numeric DEFAULT NULL) RETURNS return_authorizations
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE ra return_authorizations%ROWTYPE;
BEGIN
    SELECT * INTO ra FROM return_authorizations WHERE id = p_return_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such return' USING ERRCODE = 'no_data_found'; END IF;
    IF ra.status <> 'received' THEN RAISE EXCEPTION 'Return % is % — a received return closes', ra.number, ra.status USING ERRCODE = 'check_violation'; END IF;
    UPDATE return_authorizations SET status = 'closed', closed_by = p_by, closed_at = now(),
                                     refund_amount = COALESCE(p_refund_amount, refund_amount), restocking_fee = COALESCE(p_restocking_fee, restocking_fee)
     WHERE id = p_return_id RETURNING * INTO ra;
    RETURN ra;
END$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON return_authorizations, return_lines TO inventory_rw;
REVOKE ALL ON FUNCTION inv_return_approve(bigint, bigint), inv_return_deny(bigint, bigint, text), inv_return_receive(bigint, bigint), inv_return_close(bigint, bigint, numeric, numeric) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION inv_return_approve(bigint, bigint), inv_return_deny(bigint, bigint, text), inv_return_receive(bigint, bigint), inv_return_close(bigint, bigint, numeric, numeric) TO inventory_rw;

COMMIT;

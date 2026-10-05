-- 008: LOCATIONS and the business's OWN STOCK — the transaction ledger, the balances it maintains, and the documents that
-- post to it: goods receipts, transfers (two halves), adjustments, counts, reversals (design §6 "Locations and stock").
--
-- THE ESTATE'S DATA MODEL (design §6.3): NEW (recorded) — the Cidery's `inventory_transactions`/`inventory_balances` are
-- lot- and bond-keyed (lot_id, premises_id, tax_state, ttb_category: bulk material in bond for a producer); ours are
-- variant-keyed counts of packaged goods. The family columns keep the Cidery's names (group_id, txn_type, qty, unit_cost,
-- counterparty_kind/id, reason_code_id, reference_kind/id, reverses_id, idempotency_key, occurred_at, posted_at) so a
-- reader of one recognises the other; ours is the canonical shape for unit-counted goods. The documents (goods_receipts,
-- inventory_transfers, inventory_adjustments, inventory_counts, reason_codes, locations) keep the Cidery's names and the
-- document family (number, status, lines, posted_by/at) without premises, lots, catch weight or purchase units.
--
-- THE REFEREE'S RULES (CLAUDE.md): a balance is maintained ONLY by the ledger's trigger — a direct write to
-- inventory_balances is refused; a transaction is never updated or deleted (a mistake is reversed); a balance refuses to
-- go negative unless the location allows it; a floor model is on hand AND flagged (qty_floor_model ≤ qty_on_hand); a
-- bundle variant never holds stock; a sale allocates at confirmation (inv_allocate, db/010) and issues at shipment.
BEGIN;

CREATE TABLE locations (
    id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name                text NOT NULL,
    kind                text NOT NULL DEFAULT 'warehouse' CHECK (kind IN ('warehouse', 'showroom', 'store', 'in_transit', 'returns', 'offsite')),
    address             text,
    department_id       bigint REFERENCES departments(id) ON DELETE SET NULL,   -- the department that runs it (a dimension, never a wall — §3)
    kernel_location_id  bigint,                                                 -- the kernel's site, when known (per-store reach is Extended, D4)
    is_sellable         boolean NOT NULL DEFAULT true,                          -- stock here may be allocated to a sale
    allow_negative      boolean NOT NULL DEFAULT false,
    active              boolean NOT NULL DEFAULT true,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX locations_name_idx ON locations (lower(name));
CREATE INDEX locations_department_idx ON locations (department_id);
CREATE TRIGGER locations_touch BEFORE UPDATE ON locations FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- ---------------------------------------------------------------------------------------------
-- The balance: variant × location. Written by the ledger's trigger and by inv_allocate() only.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE inventory_balances (
    variant_id       bigint NOT NULL REFERENCES product_variants(id) ON DELETE RESTRICT,
    location_id      bigint NOT NULL REFERENCES locations(id) ON DELETE RESTRICT,
    qty_on_hand      integer NOT NULL DEFAULT 0,
    qty_allocated    integer NOT NULL DEFAULT 0 CHECK (qty_allocated >= 0),
    qty_floor_model  integer NOT NULL DEFAULT 0 CHECK (qty_floor_model >= 0),
    updated_at       timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (variant_id, location_id)
);
CREATE INDEX inventory_balances_location_idx ON inventory_balances (location_id) WHERE qty_on_hand <> 0;

CREATE OR REPLACE FUNCTION inv_balances_guard() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    IF COALESCE(current_setting('inv.balance_writer', true), '') <> 'on' THEN
        RAISE EXCEPTION 'A balance is maintained only by transactions — post a receipt, an adjustment, a transfer, a count, or allocate'
            USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    NEW.updated_at := now();
    RETURN NEW;
END$$;
CREATE TRIGGER inventory_balances_guard BEFORE INSERT OR UPDATE OR DELETE ON inventory_balances FOR EACH ROW EXECUTE FUNCTION inv_balances_guard();

-- ---------------------------------------------------------------------------------------------
-- The ledger. qty is SIGNED: the effect on qty_on_hand (stock types) or on qty_floor_model (the two floor types).
-- ---------------------------------------------------------------------------------------------
CREATE TABLE inventory_transactions (
    id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    group_id          uuid NOT NULL DEFAULT gen_random_uuid(),               -- the halves of one posting share it
    txn_type          text NOT NULL CHECK (txn_type IN ('receipt', 'issue', 'transfer_out', 'transfer_in', 'adjustment', 'count_correction',
                                                        'sale', 'return', 'floor_model_in', 'floor_model_out', 'reversal')),
    variant_id        bigint NOT NULL REFERENCES product_variants(id) ON DELETE RESTRICT,
    location_id       bigint NOT NULL REFERENCES locations(id) ON DELETE RESTRICT,
    qty               integer NOT NULL CHECK (qty <> 0),
    unit_cost         numeric(12,2) CHECK (unit_cost IS NULL OR unit_cost >= 0),
    counterparty_kind text NOT NULL DEFAULT 'none' CHECK (counterparty_kind IN ('none', 'location', 'supplier', 'customer', 'disposal')),
    counterparty_id   bigint,
    reason_code_id    bigint REFERENCES reason_codes(id) ON DELETE RESTRICT,
    reference_kind    text NOT NULL CHECK (reference_kind IN ('goods_receipt', 'transfer', 'adjustment', 'count', 'shipment', 'return', 'reversal', 'opening')),
    reference_id      bigint NOT NULL,
    reverses_id       bigint REFERENCES inventory_transactions(id) ON DELETE RESTRICT,
    affects           text NOT NULL GENERATED ALWAYS AS (CASE WHEN txn_type IN ('floor_model_in', 'floor_model_out') THEN 'floor' ELSE 'on_hand' END) STORED,
    idempotency_key   text NOT NULL UNIQUE DEFAULT gen_random_uuid()::text,
    note              text,
    occurred_at       timestamptz NOT NULL DEFAULT now(),
    posted_at         timestamptz NOT NULL DEFAULT now(),
    actor_member_id   bigint REFERENCES members(id) ON DELETE SET NULL,
    CHECK (txn_type NOT IN ('receipt', 'transfer_in', 'return', 'floor_model_in') OR qty > 0),
    CHECK (txn_type NOT IN ('issue', 'sale', 'transfer_out', 'floor_model_out') OR qty < 0),
    CHECK ((txn_type = 'reversal') = (reverses_id IS NOT NULL))
);
CREATE INDEX inventory_transactions_variant_idx ON inventory_transactions (variant_id, occurred_at DESC);
CREATE INDEX inventory_transactions_location_idx ON inventory_transactions (location_id, occurred_at DESC);
CREATE INDEX inventory_transactions_reference_idx ON inventory_transactions (reference_kind, reference_id);
CREATE INDEX inventory_transactions_group_idx ON inventory_transactions (group_id);
CREATE INDEX inventory_transactions_time_idx ON inventory_transactions (occurred_at DESC);
CREATE UNIQUE INDEX inventory_transactions_reverses_once ON inventory_transactions (reverses_id) WHERE reverses_id IS NOT NULL;

CREATE OR REPLACE FUNCTION inv_transactions_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF TG_OP IN ('UPDATE', 'DELETE') THEN
        RAISE EXCEPTION 'A transaction is never changed or deleted — reverse it (inv_reverse_transaction)' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF inv_is_bundle_variant(NEW.variant_id) THEN
        RAISE EXCEPTION 'A bundle never holds stock — its components do' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.actor_member_id IS NULL THEN NEW.actor_member_id := app_current_member_id(); END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER inventory_transactions_before BEFORE INSERT OR UPDATE OR DELETE ON inventory_transactions FOR EACH ROW EXECUTE FUNCTION inv_transactions_before();

-- The one writer of a balance.
CREATE OR REPLACE FUNCTION inv_transactions_after() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE b inventory_balances%ROWTYPE; neg boolean;
BEGIN
    PERFORM set_config('inv.balance_writer', 'on', true);
    INSERT INTO inventory_balances (variant_id, location_id) VALUES (NEW.variant_id, NEW.location_id)
    ON CONFLICT (variant_id, location_id) DO NOTHING;
    IF NEW.affects = 'floor' THEN
        UPDATE inventory_balances SET qty_floor_model = qty_floor_model + NEW.qty
         WHERE variant_id = NEW.variant_id AND location_id = NEW.location_id RETURNING * INTO b;
    ELSE
        UPDATE inventory_balances SET qty_on_hand = qty_on_hand + NEW.qty
         WHERE variant_id = NEW.variant_id AND location_id = NEW.location_id RETURNING * INTO b;
    END IF;
    PERFORM set_config('inv.balance_writer', '', true);
    SELECT allow_negative INTO neg FROM locations WHERE id = NEW.location_id;
    IF b.qty_on_hand < 0 AND NOT COALESCE(neg, false) THEN
        RAISE EXCEPTION 'Not enough on hand: % would leave % at location % (negative stock is not allowed there)', NEW.qty, b.qty_on_hand, NEW.location_id
            USING ERRCODE = 'check_violation';
    END IF;
    IF b.qty_floor_model < 0 THEN
        RAISE EXCEPTION 'There are not that many floor models to take off the floor' USING ERRCODE = 'check_violation';
    END IF;
    IF b.qty_floor_model > b.qty_on_hand AND NOT COALESCE(neg, false) THEN
        RAISE EXCEPTION 'A floor model is on hand: % on the floor cannot exceed % on hand', b.qty_floor_model, b.qty_on_hand USING ERRCODE = 'check_violation';
    END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER inventory_transactions_after AFTER INSERT ON inventory_transactions FOR EACH ROW EXECUTE FUNCTION inv_transactions_after();

-- Allocation (a sale's hold on stock) — not a movement, so not a transaction; the only other writer of a balance.
-- A positive delta is refused when the sellable quantity (on hand − floor − allocated) cannot cover it.
CREATE OR REPLACE FUNCTION inv_allocate(p_variant_id bigint, p_location_id bigint, p_delta integer) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE b inventory_balances%ROWTYPE; sellable boolean;
BEGIN
    IF p_delta = 0 THEN RETURN 0; END IF;
    SELECT is_sellable INTO sellable FROM locations WHERE id = p_location_id AND active;
    IF sellable IS DISTINCT FROM true AND p_delta > 0 THEN
        RAISE EXCEPTION 'Stock at that location is not sellable' USING ERRCODE = 'check_violation';
    END IF;
    PERFORM set_config('inv.balance_writer', 'on', true);
    INSERT INTO inventory_balances (variant_id, location_id) VALUES (p_variant_id, p_location_id) ON CONFLICT DO NOTHING;
    SELECT * INTO b FROM inventory_balances WHERE variant_id = p_variant_id AND location_id = p_location_id FOR UPDATE;
    IF p_delta > 0 AND b.qty_on_hand - b.qty_floor_model - b.qty_allocated < p_delta THEN
        PERFORM set_config('inv.balance_writer', '', true);
        RAISE EXCEPTION 'Only % available at that location, % asked', b.qty_on_hand - b.qty_floor_model - b.qty_allocated, p_delta USING ERRCODE = 'check_violation';
    END IF;
    UPDATE inventory_balances SET qty_allocated = GREATEST(0, qty_allocated + p_delta)
     WHERE variant_id = p_variant_id AND location_id = p_location_id RETURNING qty_allocated INTO b.qty_allocated;
    PERFORM set_config('inv.balance_writer', '', true);
    RETURN b.qty_allocated;
END$$;

-- The one way a function posts: every column named, the idempotency key deterministic so a double posting is refused.
CREATE OR REPLACE FUNCTION inv_post_txn(p_type text, p_variant_id bigint, p_location_id bigint, p_qty integer, p_unit_cost numeric,
                                        p_reference_kind text, p_reference_id bigint, p_key text,
                                        p_counterparty_kind text DEFAULT 'none', p_counterparty_id bigint DEFAULT NULL,
                                        p_reason_code_id bigint DEFAULT NULL, p_group uuid DEFAULT NULL, p_actor bigint DEFAULT NULL,
                                        p_reverses bigint DEFAULT NULL, p_note text DEFAULT NULL, p_occurred timestamptz DEFAULT now()) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE tid bigint;
BEGIN
    INSERT INTO inventory_transactions (group_id, txn_type, variant_id, location_id, qty, unit_cost, counterparty_kind, counterparty_id, reason_code_id,
                                        reference_kind, reference_id, reverses_id, idempotency_key, note, occurred_at, actor_member_id)
    VALUES (COALESCE(p_group, gen_random_uuid()), p_type, p_variant_id, p_location_id, p_qty, p_unit_cost, p_counterparty_kind, p_counterparty_id, p_reason_code_id,
            p_reference_kind, p_reference_id, p_reverses, p_key, p_note, p_occurred, COALESCE(p_actor, app_current_member_id()))
    RETURNING id INTO tid;
    RETURN tid;
END$$;

-- A reversal: the opposite movement, linked, once. A floor move reverses as the opposite floor move.
CREATE OR REPLACE FUNCTION inv_reverse_transaction(p_txn_id bigint, p_by bigint, p_note text DEFAULT NULL) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE t inventory_transactions%ROWTYPE; ty text;
BEGIN
    SELECT * INTO t FROM inventory_transactions WHERE id = p_txn_id;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such transaction' USING ERRCODE = 'no_data_found'; END IF;
    IF EXISTS (SELECT 1 FROM inventory_transactions WHERE reverses_id = p_txn_id) THEN
        RAISE EXCEPTION 'That transaction is already reversed' USING ERRCODE = 'check_violation';
    END IF;
    ty := CASE t.txn_type WHEN 'floor_model_in' THEN 'floor_model_out' WHEN 'floor_model_out' THEN 'floor_model_in' ELSE 'reversal' END;
    RETURN inv_post_txn(ty, t.variant_id, t.location_id, -t.qty, t.unit_cost, 'reversal', p_txn_id, 'reversal:' || p_txn_id,
                        t.counterparty_kind, t.counterparty_id, t.reason_code_id, t.group_id, p_by,
                        CASE WHEN ty = 'reversal' THEN p_txn_id END, COALESCE(p_note, 'reverses ' || p_txn_id));
END$$;

-- ---------------------------------------------------------------------------------------------
-- Documents. Lines change only while the header is a draft; posting is a function; a number at creation.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION inv_lines_only_while_draft() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE st text; hid bigint; col text := TG_ARGV[1]; tbl text := TG_ARGV[0];
BEGIN
    IF TG_OP = 'DELETE' THEN EXECUTE format('SELECT ($1).%I', col) INTO hid USING OLD;
    ELSE EXECUTE format('SELECT ($1).%I', col) INTO hid USING NEW; END IF;
    EXECUTE format('SELECT status FROM %I WHERE id = $1', tbl) INTO st USING hid;
    IF st IS DISTINCT FROM TG_ARGV[2] THEN
        RAISE EXCEPTION 'The lines of a % change only while it is %, it is %', replace(tbl, '_', ' '), TG_ARGV[2], st USING ERRCODE = 'check_violation';
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END$$;

-- Goods receipts (a stock PO is received here; a free receipt has no PO).
CREATE TABLE goods_receipts (
    id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    number             text NOT NULL UNIQUE,
    supplier_id        bigint,                                                  -- FK in db/009
    purchase_order_id  bigint,                                                  -- FK in db/011
    location_id        bigint NOT NULL REFERENCES locations(id) ON DELETE RESTRICT,
    status             text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'posted', 'cancelled')),
    delivery_note_ref  text,
    received_on        date NOT NULL DEFAULT current_date,
    notes              text,
    posted_by          bigint REFERENCES members(id) ON DELETE SET NULL,
    posted_at          timestamptz,
    created_by         bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX goods_receipts_supplier_idx ON goods_receipts (supplier_id);
CREATE INDEX goods_receipts_po_idx ON goods_receipts (purchase_order_id);
CREATE INDEX goods_receipts_status_idx ON goods_receipts (status, received_on DESC);
CREATE TRIGGER goods_receipts_touch BEFORE UPDATE ON goods_receipts FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
CREATE TRIGGER goods_receipts_number BEFORE INSERT ON goods_receipts FOR EACH ROW EXECUTE FUNCTION inv_number_document('goods_receipt');

CREATE TABLE goods_receipt_lines (
    id                      bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    goods_receipt_id        bigint NOT NULL REFERENCES goods_receipts(id) ON DELETE CASCADE,
    line_no                 integer NOT NULL,
    purchase_order_line_id  bigint,                                             -- FK in db/011
    variant_id              bigint NOT NULL REFERENCES product_variants(id) ON DELETE RESTRICT,
    qty                     integer NOT NULL CHECK (qty > 0),
    unit_cost               numeric(12,2) CHECK (unit_cost IS NULL OR unit_cost >= 0),
    discrepancy_kind        text NOT NULL DEFAULT 'none' CHECK (discrepancy_kind IN ('none', 'short', 'over', 'damaged', 'wrong_item', 'substitute')),
    discrepancy_note        text,
    putaway_location_id     bigint REFERENCES locations(id) ON DELETE RESTRICT,   -- NULL = the receipt's location
    created_at              timestamptz NOT NULL DEFAULT now(),
    updated_at              timestamptz NOT NULL DEFAULT now(),
    UNIQUE (goods_receipt_id, line_no)
);
CREATE INDEX goods_receipt_lines_variant_idx ON goods_receipt_lines (variant_id);
CREATE INDEX goods_receipt_lines_po_line_idx ON goods_receipt_lines (purchase_order_line_id);
CREATE TRIGGER goods_receipt_lines_touch BEFORE UPDATE ON goods_receipt_lines FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
CREATE TRIGGER goods_receipt_lines_draft BEFORE INSERT OR UPDATE OR DELETE ON goods_receipt_lines FOR EACH ROW EXECUTE FUNCTION inv_lines_only_while_draft('goods_receipts', 'goods_receipt_id', 'draft');

-- What purchasing does after a receipt posts (qty_received on the PO lines, the PO's status): a no-op here, replaced in db/011.
CREATE OR REPLACE FUNCTION inv_receipt_posted_hook(p_receipt_id bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$ BEGIN RETURN; END$$;

CREATE OR REPLACE FUNCTION inv_post_receipt(p_receipt_id bigint, p_by bigint) RETURNS goods_receipts
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r goods_receipts%ROWTYPE; l record; g uuid := gen_random_uuid(); csrc text; v_old numeric;
BEGIN
    SELECT * INTO r FROM goods_receipts WHERE id = p_receipt_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such receipt' USING ERRCODE = 'no_data_found'; END IF;
    IF r.status <> 'draft' THEN RAISE EXCEPTION 'Receipt % is % — only a draft posts', r.number, r.status USING ERRCODE = 'check_violation'; END IF;
    IF NOT EXISTS (SELECT 1 FROM goods_receipt_lines WHERE goods_receipt_id = p_receipt_id) THEN
        RAISE EXCEPTION 'Receipt % has no lines', r.number USING ERRCODE = 'check_violation';
    END IF;
    UPDATE goods_receipts SET status = 'posted', posted_by = p_by, posted_at = now() WHERE id = p_receipt_id RETURNING * INTO r;
    SELECT cost_source INTO csrc FROM inv_settings WHERE id = 1;
    FOR l IN SELECT * FROM goods_receipt_lines WHERE goods_receipt_id = p_receipt_id ORDER BY line_no LOOP
        PERFORM inv_post_txn('receipt', l.variant_id, COALESCE(l.putaway_location_id, r.location_id), l.qty, l.unit_cost,
                             'goods_receipt', p_receipt_id, 'receipt:' || l.id,
                             CASE WHEN r.supplier_id IS NULL THEN 'none' ELSE 'supplier' END, r.supplier_id, NULL, g, p_by, NULL, NULL, r.received_on::timestamptz);
        -- D11: a received cost becomes the variant's standard cost when the setting says last_receipt
        IF csrc = 'last_receipt' AND l.unit_cost IS NOT NULL THEN
            SELECT cost_price INTO v_old FROM product_variants WHERE id = l.variant_id;
            IF v_old IS DISTINCT FROM l.unit_cost THEN
                PERFORM inv_price_set(l.variant_id, 'cost', l.unit_cost, 'receipt ' || r.number, 'last_receipt');
            END IF;
        END IF;
    END LOOP;
    PERFORM inv_receipt_posted_hook(p_receipt_id);
    RETURN r;
END$$;

-- Transfers: two halves. Sending issues at the origin (the stock is in transit on the document); receiving puts it away.
CREATE TABLE inventory_transfers (
    id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    number            text NOT NULL UNIQUE,
    from_location_id  bigint NOT NULL REFERENCES locations(id) ON DELETE RESTRICT,
    to_location_id    bigint NOT NULL REFERENCES locations(id) ON DELETE RESTRICT,
    status            text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'in_transit', 'received', 'cancelled')),
    notes             text,
    shipped_by        bigint REFERENCES members(id) ON DELETE SET NULL,
    shipped_at        timestamptz,
    received_by       bigint REFERENCES members(id) ON DELETE SET NULL,
    received_at       timestamptz,
    created_by        bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at        timestamptz NOT NULL DEFAULT now(),
    updated_at        timestamptz NOT NULL DEFAULT now(),
    CHECK (from_location_id <> to_location_id)
);
CREATE INDEX inventory_transfers_status_idx ON inventory_transfers (status, created_at DESC);
CREATE INDEX inventory_transfers_from_idx ON inventory_transfers (from_location_id);
CREATE INDEX inventory_transfers_to_idx ON inventory_transfers (to_location_id);
CREATE TRIGGER inventory_transfers_touch BEFORE UPDATE ON inventory_transfers FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
CREATE TRIGGER inventory_transfers_number BEFORE INSERT ON inventory_transfers FOR EACH ROW EXECUTE FUNCTION inv_number_document('transfer');

CREATE TABLE inventory_transfer_lines (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    transfer_id   bigint NOT NULL REFERENCES inventory_transfers(id) ON DELETE CASCADE,
    line_no       integer NOT NULL,
    variant_id    bigint NOT NULL REFERENCES product_variants(id) ON DELETE RESTRICT,
    qty           integer NOT NULL CHECK (qty > 0),
    qty_received  integer NOT NULL DEFAULT 0 CHECK (qty_received >= 0),
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now(),
    UNIQUE (transfer_id, line_no)
);
CREATE INDEX inventory_transfer_lines_variant_idx ON inventory_transfer_lines (variant_id);
CREATE TRIGGER inventory_transfer_lines_touch BEFORE UPDATE ON inventory_transfer_lines FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
-- the lines' quantities are a draft's; qty_received is written by inv_transfer_receive under the writer flag
CREATE OR REPLACE FUNCTION inv_transfer_lines_guard() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE st text;
BEGIN
    IF COALESCE(current_setting('inv.balance_writer', true), '') = 'on' THEN RETURN COALESCE(NEW, OLD); END IF;
    SELECT status INTO st FROM inventory_transfers WHERE id = COALESCE(NEW.transfer_id, OLD.transfer_id);
    IF st IS DISTINCT FROM 'draft' THEN
        RAISE EXCEPTION 'The lines of a transfer change only while it is a draft, it is %', st USING ERRCODE = 'check_violation';
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER inventory_transfer_lines_draft BEFORE INSERT OR UPDATE OR DELETE ON inventory_transfer_lines FOR EACH ROW EXECUTE FUNCTION inv_transfer_lines_guard();

CREATE OR REPLACE FUNCTION inv_transfer_send(p_transfer_id bigint, p_by bigint) RETURNS inventory_transfers
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE t inventory_transfers%ROWTYPE; l record; g uuid := gen_random_uuid(); c numeric;
BEGIN
    SELECT * INTO t FROM inventory_transfers WHERE id = p_transfer_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such transfer' USING ERRCODE = 'no_data_found'; END IF;
    IF t.status <> 'draft' THEN RAISE EXCEPTION 'Transfer % is % — only a draft is sent', t.number, t.status USING ERRCODE = 'check_violation'; END IF;
    IF NOT EXISTS (SELECT 1 FROM inventory_transfer_lines WHERE transfer_id = p_transfer_id) THEN
        RAISE EXCEPTION 'Transfer % has no lines', t.number USING ERRCODE = 'check_violation';
    END IF;
    UPDATE inventory_transfers SET status = 'in_transit', shipped_by = p_by, shipped_at = now() WHERE id = p_transfer_id RETURNING * INTO t;
    FOR l IN SELECT * FROM inventory_transfer_lines WHERE transfer_id = p_transfer_id ORDER BY line_no LOOP
        SELECT cost_price INTO c FROM product_variants WHERE id = l.variant_id;
        PERFORM inv_post_txn('transfer_out', l.variant_id, t.from_location_id, -l.qty, c, 'transfer', p_transfer_id, 'transfer_out:' || l.id,
                             'location', t.to_location_id, NULL, g, p_by);
    END LOOP;
    RETURN t;
END$$;

-- Receive: every line in full, or the quantities given ({"<line_id>": qty}); a short receipt leaves the difference in transit
-- on the document (the person adjusts or reverses — a stock loss is never silent).
CREATE OR REPLACE FUNCTION inv_transfer_receive(p_transfer_id bigint, p_by bigint, p_quantities jsonb DEFAULT NULL) RETURNS inventory_transfers
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE t inventory_transfers%ROWTYPE; l record; g uuid := gen_random_uuid(); q integer; c numeric;
BEGIN
    SELECT * INTO t FROM inventory_transfers WHERE id = p_transfer_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such transfer' USING ERRCODE = 'no_data_found'; END IF;
    IF t.status <> 'in_transit' THEN RAISE EXCEPTION 'Transfer % is % — only one in transit is received', t.number, t.status USING ERRCODE = 'check_violation'; END IF;
    PERFORM set_config('inv.balance_writer', 'on', true);
    FOR l IN SELECT * FROM inventory_transfer_lines WHERE transfer_id = p_transfer_id ORDER BY line_no LOOP
        q := COALESCE((p_quantities ->> l.id::text)::integer, l.qty);
        IF q < 0 OR q > l.qty THEN RAISE EXCEPTION 'Line % receives between 0 and %', l.line_no, l.qty USING ERRCODE = 'check_violation'; END IF;
        UPDATE inventory_transfer_lines SET qty_received = q WHERE id = l.id;
        IF q > 0 THEN
            SELECT cost_price INTO c FROM product_variants WHERE id = l.variant_id;
            PERFORM inv_post_txn('transfer_in', l.variant_id, t.to_location_id, q, c, 'transfer', p_transfer_id, 'transfer_in:' || l.id,
                                 'location', t.from_location_id, NULL, g, p_by);
        END IF;
    END LOOP;
    PERFORM set_config('inv.balance_writer', '', true);
    UPDATE inventory_transfers SET status = 'received', received_by = p_by, received_at = now() WHERE id = p_transfer_id RETURNING * INTO t;
    RETURN t;
END$$;

-- Adjustments: a quantity changes with a reason. A reason that does not affect quantity (floor_model) moves units onto
-- (+) or off (−) the showroom floor instead.
CREATE TABLE inventory_adjustments (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    number          text NOT NULL UNIQUE,
    location_id     bigint NOT NULL REFERENCES locations(id) ON DELETE RESTRICT,
    reason_code_id  bigint NOT NULL REFERENCES reason_codes(id) ON DELETE RESTRICT,
    status          text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'posted', 'cancelled')),
    notes           text,
    posted_by       bigint REFERENCES members(id) ON DELETE SET NULL,
    posted_at       timestamptz,
    created_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX inventory_adjustments_location_idx ON inventory_adjustments (location_id, created_at DESC);
CREATE INDEX inventory_adjustments_status_idx ON inventory_adjustments (status);
CREATE TRIGGER inventory_adjustments_touch BEFORE UPDATE ON inventory_adjustments FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
CREATE TRIGGER inventory_adjustments_number BEFORE INSERT ON inventory_adjustments FOR EACH ROW EXECUTE FUNCTION inv_number_document('adjustment');

CREATE TABLE inventory_adjustment_lines (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    adjustment_id  bigint NOT NULL REFERENCES inventory_adjustments(id) ON DELETE CASCADE,
    line_no        integer NOT NULL,
    variant_id     bigint NOT NULL REFERENCES product_variants(id) ON DELETE RESTRICT,
    qty_delta      integer NOT NULL CHECK (qty_delta <> 0),
    unit_cost      numeric(12,2) CHECK (unit_cost IS NULL OR unit_cost >= 0),
    note           text,
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now(),
    UNIQUE (adjustment_id, line_no)
);
CREATE INDEX inventory_adjustment_lines_variant_idx ON inventory_adjustment_lines (variant_id);
CREATE TRIGGER inventory_adjustment_lines_touch BEFORE UPDATE ON inventory_adjustment_lines FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
CREATE TRIGGER inventory_adjustment_lines_draft BEFORE INSERT OR UPDATE OR DELETE ON inventory_adjustment_lines FOR EACH ROW EXECUTE FUNCTION inv_lines_only_while_draft('inventory_adjustments', 'adjustment_id', 'draft');

CREATE OR REPLACE FUNCTION inv_post_adjustment(p_adjustment_id bigint, p_by bigint) RETURNS inventory_adjustments
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE a inventory_adjustments%ROWTYPE; rc reason_codes%ROWTYPE; l record; g uuid := gen_random_uuid(); c numeric;
BEGIN
    SELECT * INTO a FROM inventory_adjustments WHERE id = p_adjustment_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such adjustment' USING ERRCODE = 'no_data_found'; END IF;
    IF a.status <> 'draft' THEN RAISE EXCEPTION 'Adjustment % is % — only a draft posts', a.number, a.status USING ERRCODE = 'check_violation'; END IF;
    SELECT * INTO rc FROM reason_codes WHERE id = a.reason_code_id;
    IF NOT ('adjustment' = ANY (rc.applies_to)) THEN RAISE EXCEPTION 'The reason % is not an adjustment reason', rc.name USING ERRCODE = 'check_violation'; END IF;
    IF NOT EXISTS (SELECT 1 FROM inventory_adjustment_lines WHERE adjustment_id = p_adjustment_id) THEN
        RAISE EXCEPTION 'Adjustment % has no lines', a.number USING ERRCODE = 'check_violation';
    END IF;
    UPDATE inventory_adjustments SET status = 'posted', posted_by = p_by, posted_at = now() WHERE id = p_adjustment_id RETURNING * INTO a;
    FOR l IN SELECT * FROM inventory_adjustment_lines WHERE adjustment_id = p_adjustment_id ORDER BY line_no LOOP
        SELECT COALESCE(l.unit_cost, cost_price) INTO c FROM product_variants WHERE id = l.variant_id;
        IF rc.affects_qty THEN
            PERFORM inv_post_txn('adjustment', l.variant_id, a.location_id, l.qty_delta, c, 'adjustment', p_adjustment_id, 'adjustment:' || l.id,
                                 CASE rc.code WHEN 'donation' THEN 'disposal' ELSE 'none' END, NULL, a.reason_code_id, g, p_by, NULL, l.note);
        ELSE
            PERFORM inv_post_txn(CASE WHEN l.qty_delta > 0 THEN 'floor_model_in' ELSE 'floor_model_out' END, l.variant_id, a.location_id, l.qty_delta, c,
                                 'adjustment', p_adjustment_id, 'adjustment:' || l.id, 'none', NULL, a.reason_code_id, g, p_by, NULL, l.note);
        END IF;
    END LOOP;
    RETURN a;
END$$;

-- Counts: open at a location (every variant with a balance there, the system quantity frozen on the line), count by
-- scanning or typing, post with corrections. DECISION: the correction is counted − ON HAND AT POSTING, not at the start,
-- so a receipt posted during the count is not counted twice; the line keeps system_qty as the number the counter saw.
CREATE TABLE inventory_counts (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    number       text NOT NULL UNIQUE,
    location_id  bigint NOT NULL REFERENCES locations(id) ON DELETE RESTRICT,
    status       text NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'posted', 'cancelled')),
    notes        text,
    started_by   bigint REFERENCES members(id) ON DELETE SET NULL,
    started_at   timestamptz NOT NULL DEFAULT now(),
    posted_by    bigint REFERENCES members(id) ON DELETE SET NULL,
    posted_at    timestamptz,
    created_at   timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX inventory_counts_location_idx ON inventory_counts (location_id, started_at DESC);
CREATE INDEX inventory_counts_status_idx ON inventory_counts (status);
CREATE TRIGGER inventory_counts_touch BEFORE UPDATE ON inventory_counts FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
CREATE TRIGGER inventory_counts_number BEFORE INSERT ON inventory_counts FOR EACH ROW EXECUTE FUNCTION inv_number_document('count');

CREATE TABLE inventory_count_lines (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    count_id     bigint NOT NULL REFERENCES inventory_counts(id) ON DELETE CASCADE,
    variant_id   bigint NOT NULL REFERENCES product_variants(id) ON DELETE RESTRICT,
    system_qty   integer NOT NULL DEFAULT 0,
    counted_qty  integer CHECK (counted_qty IS NULL OR counted_qty >= 0),
    counted_by   bigint REFERENCES members(id) ON DELETE SET NULL,
    counted_at   timestamptz,
    created_at   timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now(),
    UNIQUE (count_id, variant_id)
);
CREATE TRIGGER inventory_count_lines_touch BEFORE UPDATE ON inventory_count_lines FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
CREATE TRIGGER inventory_count_lines_open BEFORE INSERT OR UPDATE OR DELETE ON inventory_count_lines FOR EACH ROW EXECUTE FUNCTION inv_lines_only_while_draft('inventory_counts', 'count_id', 'open');

CREATE OR REPLACE FUNCTION inv_count_start(p_location_id bigint, p_by bigint, p_notes text DEFAULT NULL) RETURNS inventory_counts
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE c inventory_counts%ROWTYPE;
BEGIN
    IF EXISTS (SELECT 1 FROM inventory_counts WHERE location_id = p_location_id AND status = 'open') THEN
        RAISE EXCEPTION 'A count is already open at that location' USING ERRCODE = 'check_violation';
    END IF;
    INSERT INTO inventory_counts (location_id, started_by, notes) VALUES (p_location_id, p_by, p_notes) RETURNING * INTO c;
    INSERT INTO inventory_count_lines (count_id, variant_id, system_qty)
    SELECT c.id, b.variant_id, b.qty_on_hand FROM inventory_balances b WHERE b.location_id = p_location_id AND (b.qty_on_hand <> 0 OR b.qty_floor_model <> 0);
    RETURN c;
END$$;

CREATE OR REPLACE FUNCTION inv_post_count(p_count_id bigint, p_by bigint) RETURNS inventory_counts
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE c inventory_counts%ROWTYPE; l record; g uuid := gen_random_uuid(); onhand integer; delta integer; cost numeric; rc bigint;
BEGIN
    SELECT * INTO c FROM inventory_counts WHERE id = p_count_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such count' USING ERRCODE = 'no_data_found'; END IF;
    IF c.status <> 'open' THEN RAISE EXCEPTION 'Count % is % — only an open count posts', c.number, c.status USING ERRCODE = 'check_violation'; END IF;
    SELECT id INTO rc FROM reason_codes WHERE code = 'correction';
    UPDATE inventory_counts SET status = 'posted', posted_by = p_by, posted_at = now() WHERE id = p_count_id RETURNING * INTO c;
    FOR l IN SELECT * FROM inventory_count_lines WHERE count_id = p_count_id AND counted_qty IS NOT NULL ORDER BY id LOOP
        SELECT COALESCE(b.qty_on_hand, 0) INTO onhand FROM inventory_balances b WHERE b.variant_id = l.variant_id AND b.location_id = c.location_id;
        delta := l.counted_qty - COALESCE(onhand, 0);
        IF delta <> 0 THEN
            SELECT cost_price INTO cost FROM product_variants WHERE id = l.variant_id;
            PERFORM inv_post_txn('count_correction', l.variant_id, c.location_id, delta, cost, 'count', p_count_id, 'count:' || l.id,
                                 'none', NULL, rc, g, p_by);
        END IF;
    END LOOP;
    RETURN c;
END$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON locations, goods_receipts, goods_receipt_lines, inventory_transfers, inventory_transfer_lines,
    inventory_adjustments, inventory_adjustment_lines, inventory_counts, inventory_count_lines TO inventory_rw;
GRANT SELECT, INSERT, UPDATE, DELETE ON inventory_balances TO inventory_rw;      -- the guard trigger refuses the write; the functions pass the flag
GRANT SELECT, INSERT ON inventory_transactions TO inventory_rw;
REVOKE ALL ON FUNCTION inv_allocate(bigint, bigint, integer), inv_post_txn(text, bigint, bigint, integer, numeric, text, bigint, text, text, bigint, bigint, uuid, bigint, bigint, text, timestamptz),
    inv_reverse_transaction(bigint, bigint, text), inv_post_receipt(bigint, bigint), inv_receipt_posted_hook(bigint), inv_transfer_send(bigint, bigint),
    inv_transfer_receive(bigint, bigint, jsonb), inv_post_adjustment(bigint, bigint), inv_count_start(bigint, bigint, text), inv_post_count(bigint, bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION inv_allocate(bigint, bigint, integer), inv_post_txn(text, bigint, bigint, integer, numeric, text, bigint, text, text, bigint, bigint, uuid, bigint, bigint, text, timestamptz),
    inv_reverse_transaction(bigint, bigint, text), inv_post_receipt(bigint, bigint), inv_receipt_posted_hook(bigint), inv_transfer_send(bigint, bigint),
    inv_transfer_receive(bigint, bigint, jsonb), inv_post_adjustment(bigint, bigint), inv_count_start(bigint, bigint, text), inv_post_count(bigint, bigint) TO inventory_rw;

COMMIT;

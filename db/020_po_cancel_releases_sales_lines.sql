-- 020: WHAT SLICE 6 FOUND — an additive fix to db/011 (docs/build-specs/purchasing.md "Built and proven"). A migration is never modified; this one adds.
--
--  inv_po_cancel() (db/011's): cancelling a drop-ship purchase order put the customer's `ordered` lines back to `open` ("the drop-ship lines go back to the
--  picker") but left their `purchase_order_line_id` pointing at the cancelled purchase order line — and inv_order_dropships_draft() drafts only the open
--  lines with NO purchase order line. So the line went back to the picker and nothing could ever draft it again: the purchase-order form listed no open
--  drop-ship line, and "Draft" refused "has no open drop-ship line" for a line that is open. Now the cancel releases the link: the sales line is open
--  again AND free — a draft cancelled before it was ever sent (its sales lines were `open` all along) releases the same way. db/011's body, that one
--  statement changed (the UPDATE of sales_order_lines also frees purchase_order_line_id, for `open` lines as well as `ordered`).
--  A DECLINED line keeps its link on purpose: inv_lines_at_risk() reads the declined purchase order line through it ("declined" is the risk it names).
BEGIN;

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
    UPDATE sales_order_lines SET status = CASE WHEN status = 'ordered' THEN 'open' ELSE status END, purchase_order_line_id = NULL
     WHERE purchase_order_line_id IN (SELECT id FROM purchase_order_lines WHERE purchase_order_id = p_po_id) AND status IN ('open', 'ordered');
    UPDATE purchase_order_lines SET status = 'cancelled' WHERE purchase_order_id = p_po_id AND status <> 'cancelled';
    PERFORM set_config('inv.balance_writer', '', true);
    UPDATE purchase_orders SET status = 'cancelled', cancelled_by = p_by, cancelled_at = now(), cancel_reason = p_reason WHERE id = p_po_id RETURNING * INTO po;
    INSERT INTO purchase_order_events (purchase_order_id, kind, source, reason, member_id) VALUES (p_po_id, 'cancelled', 'manual', p_reason, p_by);
    UPDATE supplier_links_secure SET expires_at = now() WHERE purchase_order_id = p_po_id AND rotated_at IS NULL;
    RETURN po;
END$$;

COMMIT;

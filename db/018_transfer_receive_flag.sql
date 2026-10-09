-- 018: WHAT SLICE 2 FOUND — an additive fix to db/008 (docs/build-specs/stock.md "Built and proven"). A migration is never modified; this one adds.
--
--  1. inv_transfer_receive(): it raised the writer flag once, before its loop, so that it may write the lines' qty_received after the transfer
--     left draft. But every posting lowers the flag — inv_transactions_after() turns it off once the balance is written — so the SECOND line's
--     UPDATE met inv_transfer_lines_guard() with the flag down and the whole receive was refused ("The lines of a transfer change only while it
--     is a draft, it is in_transit"). Any transfer of two lines or more could not be received. The function now raises the flag before every
--     line's UPDATE, as inv_ship() and inv_return_receive() (db/010, db/012) already do after each posting; nothing else changes.
BEGIN;

CREATE OR REPLACE FUNCTION inv_transfer_receive(p_transfer_id bigint, p_by bigint, p_quantities jsonb DEFAULT NULL) RETURNS inventory_transfers
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE t inventory_transfers%ROWTYPE; l record; g uuid := gen_random_uuid(); q integer; c numeric;
BEGIN
    SELECT * INTO t FROM inventory_transfers WHERE id = p_transfer_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such transfer' USING ERRCODE = 'no_data_found'; END IF;
    IF t.status <> 'in_transit' THEN RAISE EXCEPTION 'Transfer % is % — only one in transit is received', t.number, t.status USING ERRCODE = 'check_violation'; END IF;
    FOR l IN SELECT * FROM inventory_transfer_lines WHERE transfer_id = p_transfer_id ORDER BY line_no LOOP
        q := COALESCE((p_quantities ->> l.id::text)::integer, l.qty);
        IF q < 0 OR q > l.qty THEN RAISE EXCEPTION 'Line % receives between 0 and %', l.line_no, l.qty USING ERRCODE = 'check_violation'; END IF;
        PERFORM set_config('inv.balance_writer', 'on', true);        -- raised for every line: the posting below lowers it
        UPDATE inventory_transfer_lines SET qty_received = q WHERE id = l.id;
        PERFORM set_config('inv.balance_writer', '', true);
        IF q > 0 THEN
            SELECT cost_price INTO c FROM product_variants WHERE id = l.variant_id;
            PERFORM inv_post_txn('transfer_in', l.variant_id, t.to_location_id, q, c, 'transfer', p_transfer_id, 'transfer_in:' || l.id,
                                 'location', t.from_location_id, NULL, g, p_by);
        END IF;
    END LOOP;
    UPDATE inventory_transfers SET status = 'received', received_by = p_by, received_at = now() WHERE id = p_transfer_id RETURNING * INTO t;
    RETURN t;
END$$;
REVOKE ALL ON FUNCTION inv_transfer_receive(bigint, bigint, jsonb) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION inv_transfer_receive(bigint, bigint, jsonb) TO inventory_rw;

COMMIT;

-- 006: the two things every record carries — ATTACHMENTS and NOTES (design §6 "Files").
--
-- THE ESTATE'S DATA MODEL (design §6.3): GL db/009's canonical definitions copied VERBATIM (columns, types, index names);
-- the `record_type` CHECK is widened to this application's records. They come BEFORE the catalog (db/007) because a
-- product image is an attachment (product_images → attachments). A file lives under storage/attachments/<record_type>/
-- <record_id>/ and is served through the gate; the records role never sees `storage_path` (db/015).
BEGIN;

CREATE TABLE attachments (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    record_type   text NOT NULL CHECK (record_type IN ('product', 'product_variant', 'supplier', 'source', 'listing', 'customer', 'sales_order',
                                                       'purchase_order', 'goods_receipt', 'shipment', 'return', 'inventory_adjustment', 'inventory_count',
                                                       'inventory_transfer', 'location')),
    record_id     bigint NOT NULL,
    filename      text NOT NULL,
    mime_type     text NOT NULL,
    byte_size     bigint NOT NULL CHECK (byte_size >= 0),
    sha256        text NOT NULL,
    storage_path  text NOT NULL,
    uploaded_by   bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX attachments_record_idx ON attachments (record_type, record_id);
CREATE INDEX attachments_uploaded_by_idx ON attachments (uploaded_by);

CREATE TABLE notes (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    record_type   text NOT NULL,
    record_id     bigint NOT NULL,
    member_id     bigint REFERENCES members(id) ON DELETE SET NULL,
    body          text NOT NULL,
    created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX notes_record_idx ON notes (record_type, record_id, created_at DESC);
CREATE INDEX notes_member_idx ON notes (member_id);

GRANT SELECT, INSERT, UPDATE, DELETE ON attachments, notes TO inventory_rw;

COMMIT;

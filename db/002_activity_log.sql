-- 002: activity memory -- the one funnel (memory.md §2). Exists before the first feature ships; it cannot be
-- backfilled. Every row is shipped to the tenant's one MaluDB as an `activity` episode by mcp/activity_ingest.py
-- (payload key "application": "inventory" first). A RECORD'S HISTORY IS THIS TABLE (design §6): there is no audit table —
-- except OFFERS, whose history is their snapshots (offer_snapshots), and PRICES, whose history is price_history.
-- `before`/`after` carry what the timeline shows: ids, names, SKUs, counts, states and amounts. A CREDENTIAL, A CUSTOMER'S
-- ADDRESS OR PHONE AND A SOURCE'S RAW OBJECT ARE NEVER IN A PAYLOAD; a door's token or a feed key never (a feed key is
-- named by its LABEL in `after`, never by its value).
--
-- THE ESTATE'S DATA MODEL (design §6.3): the contract table (memory.md / Consultant Tracking db/002) with Inventory's audit
-- keys APPENDED: source_id, sales_order_id, purchase_order_id (the trails a source, an order and a purchase order show)
-- and token_id (the feed key an event ran under); and `feed` added to the contract's sources — the availability API's own
-- word (design §4: `web` the UI, `agent` under a run token, `cron` the worker, `portal` the two doors, `feed` the feed).
BEGIN;

CREATE TABLE activity_log (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    occurred_at     timestamptz NOT NULL DEFAULT now(),
    actor_member_id bigint REFERENCES members(id) ON DELETE SET NULL,
    source          text NOT NULL DEFAULT 'web'
                    CHECK (source IN ('web', 'assistant', 'mcp', 'cron', 'agent', 'desk', 'webhook', 'portal', 'api', 'application', 'feed', 'system')),
    action          text NOT NULL,                 -- entity.verb
    screen          text,
    route           text,                          -- "METHOD /path"
    entity_type     text,
    entity_id       bigint,
    before          jsonb,
    after           jsonb,                         -- changed fields only; amount + currency on every money event
    request_id      text,
    session_id      text,
    ip_address      inet,
    agent_run_id    bigint,                        -- the kernel's run id when an agent acted (no FK)
    department_id   bigint,                        -- the department the event concerns (the one that runs a location)
    location_id     bigint,                        -- the location the event concerns (a stock movement, a count, a sale from a store)
    kernel_request_id text,                        -- the kernel's request id for a call that crossed to it
    created_at      timestamptz NOT NULL DEFAULT now(),
    -- appended (design §6.3): Inventory's audit keys (no FKs — history outlives a record)
    source_id          bigint,                     -- the source the event concerns (a pull, a listing, an offer, a credential — never its value)
    sales_order_id     bigint,                     -- the sales order the event concerns (a line, a payment, a shipment, the customer's view)
    purchase_order_id  bigint,                     -- the purchase order the event concerns (send, the supplier's ack, tracking, receipt)
    token_id           bigint                      -- the feed key the event ran under (feed.read); its LABEL is in `after`, never the key
);
CREATE INDEX activity_log_actor_idx   ON activity_log (actor_member_id, occurred_at DESC);
CREATE INDEX activity_log_entity_idx  ON activity_log (entity_type, entity_id, occurred_at DESC);
CREATE INDEX activity_log_action_idx  ON activity_log (action, occurred_at DESC);
CREATE INDEX activity_log_time_idx    ON activity_log (occurred_at DESC);
CREATE INDEX activity_log_request_idx ON activity_log (request_id);
CREATE INDEX activity_log_source_idx  ON activity_log (source_id, occurred_at DESC);
CREATE INDEX activity_log_sales_order_idx    ON activity_log (sales_order_id, occurred_at DESC);
CREATE INDEX activity_log_purchase_order_idx ON activity_log (purchase_order_id, occurred_at DESC);
CREATE INDEX activity_log_token_idx   ON activity_log (token_id, occurred_at DESC);
COMMENT ON TABLE activity_log IS 'The one funnel of what happened (memory.md). A credential, a customer''s address or phone and a source''s raw object are never in a payload; ids, names, SKUs, counts, states and amounts are.';

GRANT INSERT, SELECT ON activity_log TO inventory_rw;
REVOKE ALL ON activity_log FROM inventory_records_ro, inventory_activity_ro;

-- The ingest checkpoint: only ever moves forward.
CREATE TABLE activity_ingest_state (
    id          smallint PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    last_id     bigint NOT NULL DEFAULT 0,
    updated_at  timestamptz NOT NULL DEFAULT now()
);
INSERT INTO activity_ingest_state (id, last_id) VALUES (1, 0) ON CONFLICT DO NOTHING;
GRANT SELECT, UPDATE ON activity_ingest_state TO inventory_rw;

COMMIT;

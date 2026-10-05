-- 004: Inventory's roles and the rights they give (kernel db/145; roles-and-rights.md; the design §3).
--
-- Published to the kernel by app_roles on the records MCP (os.app-roles/1). The kernel grants a SET of roles; the claims and
-- the feed carry them back into members.roles. What a role lets its holder do is the application's to enforce:
-- inv_has_right(right) answers from the catalogue and the mirror. The base role's KEY IS `user` on purpose (the installer's
-- --grant-standing-departments gives the standing departments the role keyed member/user/write) — but this application is
-- NOT granted to standing departments by default (design §13.3): a retailer grants it person by person.
--
-- COST IS THE WALL (design §3): cost_price, a purchase order's unit_cost, a receipt's cost, margin and stock value are the
-- Buyer's and the admin's, the Warehouse's on receipts, Sales' when the setting sales_sees_cost is on. The function that
-- says so, inv_sees_cost(), lives in db/005 beside inv_settings (it needs the setting); the mcp_* views null the cost
-- columns through it. Locations are records, not walls: every role sees every location.
--
-- THE ESTATE'S DATA MODEL (design §6.3): the shape is Consultant Tracking db/004 (ct_ → inv_); the five roles and the
-- rights are this application's.
BEGIN;

CREATE TABLE inv_rights (
    right_key   text PRIMARY KEY CHECK (right_key ~ '^[a-z][a-z0-9_.]{0,59}$'),
    description text NOT NULL,
    sort_order  integer NOT NULL DEFAULT 0
);
CREATE TABLE inv_roles (
    role_key    text PRIMARY KEY CHECK (role_key ~ '^[a-z][a-z0-9_]{0,39}$'),
    name        text NOT NULL,
    description text NOT NULL,
    capability  text NOT NULL CHECK (capability IN ('read', 'write', 'admin')),
    is_admin    boolean NOT NULL DEFAULT false,
    sort_order  integer NOT NULL DEFAULT 0,
    CHECK (NOT is_admin OR capability = 'admin')
);
CREATE UNIQUE INDEX inv_roles_one_admin ON inv_roles (is_admin) WHERE is_admin;
CREATE TABLE inv_role_rights (
    role_key  text NOT NULL REFERENCES inv_roles(role_key) ON DELETE CASCADE,
    right_key text NOT NULL REFERENCES inv_rights(right_key) ON DELETE CASCADE,
    PRIMARY KEY (role_key, right_key)
);

INSERT INTO inv_rights (right_key, description, sort_order) VALUES
 ('inventory.read',      'See the catalog, availability (own stock and the sources'' offers), orders and their status — never cost, margin or a source''s settings', 1),
 ('orders.write',        'Quote and take sales orders: lines, the fulfilment choice per line, delivery', 2),
 ('payments.record',     'Record a payment''s status and amounts on an order (a record, never a charge)', 3),
 ('customers.write',     'Create and change customers', 4),
 ('orders.send',         'Send an order''s link and confirmation email to the customer', 5),
 ('watches.own',         'Set and clear watches of their own', 6),
 ('notes.write',         'Add notes to records', 7),
 ('stock.receive',       'Receive goods: post a goods receipt and put away', 8),
 ('stock.adjust',        'Adjust stock with a reason', 9),
 ('stock.transfer',      'Send and receive transfers between locations', 10),
 ('stock.count',         'Open and post a stock count', 11),
 ('stock.ship',          'Pick, pack and ship an order''s stock lines; record tracking', 12),
 ('returns.receive',     'Receive an authorized return into stock', 13),
 ('catalog.write',       'Create and change products, variants, identifiers, bundles and images', 14),
 ('prices.write',        'Set retail, MAP and cost prices (logged with history)', 15),
 ('sources.write',       'Add and tend sources: settings, schedules, pulls on demand, pause and resume', 16),
 ('sources.credentials', 'Set and rotate a source''s credential — never read it back', 17),
 ('listings.match',      'Match a listing variant to a variant; accept or dismiss proposals', 18),
 ('purchasing.write',    'Draft, send and receive against purchase orders for stock and drop-ship', 19),
 ('suppliers.write',     'Create and change suppliers and their price sheets', 20),
 ('returns.write',       'Authorize returns, set their disposition, record a refund', 21),
 ('watches.all',         'See, set and clear every member''s and agent''s watches', 22),
 ('reports.read',        'Run the reports: stock value, sell-through, margin, source health', 23),
 ('cost.read',           'See cost and margin wherever they appear', 24),
 ('settings.manage',     'Run Inventory: the settings — sizes, attributes, the crawl policy, link lifetimes, thresholds', 25),
 ('feed.keys',           'Mint, rotate and revoke the availability feed''s keys', 26),
 ('exports.all',         'Export everything', 27),
 ('agents.settings',     'See every agent''s dispatches and the Buyer agent''s proposals; link to the kernel for hires and grants', 28),
 ('records.delete',      'Delete what deletion allows (design §6)', 29),
 ('sequences.manage',    'Change the document sequences and tax rates', 30);

INSERT INTO inv_roles (role_key, name, description, capability, is_admin, sort_order) VALUES
 ('viewer',    'Viewer',          'Sees the catalog, availability, orders and their status; never cost, margin or a source''s settings. An external member holds this at most.', 'read',  false, 1),
 ('user',      'Sales',           'On the floor or the phone: finds what can be sold and when, quotes, takes the order, chooses how each line is filled, records the deposit, tells the customer. Sees retail and MAP; cost only when the setting says so.', 'write', false, 2),
 ('warehouse', 'Warehouse',       'Receives, puts away, counts, transfers, adjusts, picks and ships, takes returns in. Sees cost on receipts.', 'write', false, 3),
 ('buyer',     'Buyer',           'Keeps the catalog and prices, tends sources, matches listings, watches prices and MAP, raises purchase orders and places drop-ships, deals with suppliers. Sees cost and margin. Sales'' and Warehouse''s rights too.', 'write', false, 4),
 ('admin',     'Inventory admin', 'Runs Inventory: settings, every record, the feed''s keys, exports, the agents'' settings, deletion, sequences. A super-admin holds this role.', 'admin', true, 5);

INSERT INTO inv_role_rights (role_key, right_key)
SELECT 'viewer', 'inventory.read'
UNION ALL SELECT 'user', r FROM unnest(ARRAY['inventory.read',
                                              'orders.write', 'payments.record', 'customers.write', 'orders.send', 'watches.own', 'notes.write']) r
UNION ALL SELECT 'warehouse', r FROM unnest(ARRAY['inventory.read',
                                                   'stock.receive', 'stock.adjust', 'stock.transfer', 'stock.count', 'stock.ship', 'returns.receive',
                                                   'notes.write', 'watches.own']) r
UNION ALL SELECT 'buyer', r FROM unnest(ARRAY['inventory.read',
                                               'orders.write', 'payments.record', 'customers.write', 'orders.send', 'watches.own', 'notes.write',
                                               'stock.receive', 'stock.adjust', 'stock.transfer', 'stock.count', 'stock.ship', 'returns.receive',
                                               'catalog.write', 'prices.write', 'sources.write', 'sources.credentials', 'listings.match',
                                               'purchasing.write', 'suppliers.write', 'returns.write', 'watches.all', 'reports.read', 'cost.read']) r
UNION ALL SELECT 'admin', right_key FROM inv_rights;

-- The effective roles of a member: what the kernel said, or — for a member the kernel has not yet sent roles for
-- (roles = '{}') — one read from the capability: admin = admin, read = viewer, anything else = user (Sales). A super-admin
-- always holds admin (the kernel lists the role for them; this is the belt to that brace). An inactive or unadmitted
-- member holds nothing.
CREATE OR REPLACE FUNCTION inv_member_roles(p_member_id bigint) RETURNS text[]
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE r text[];
BEGIN
    SELECT CASE
             WHEN m.member_kind = 'human' AND m.business_role = 'super_admin' THEN
                  (SELECT array_agg(DISTINCT x) FROM unnest(m.roles || ARRAY['admin']) x)
             WHEN cardinality(m.roles) > 0 THEN m.roles
             ELSE ARRAY[CASE m.capability WHEN 'admin' THEN 'admin' WHEN 'read' THEN 'viewer' ELSE 'user' END]
           END
      INTO r
      FROM members m
     WHERE m.id = p_member_id AND m.status = 'active' AND m.capability IS NOT NULL;
    RETURN COALESCE(r, '{}');
END$$;

-- The rule: a right the caller's effective roles give. The member must be active and admitted.
CREATE OR REPLACE FUNCTION inv_has_right(p_right text) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN EXISTS (
        SELECT 1 FROM inv_role_rights rr
         WHERE rr.right_key = p_right
           AND rr.role_key = ANY (inv_member_roles(app_current_member_id())));
END$$;

-- Runs Inventory: the super-admin, or a human holding settings.manage.
CREATE OR REPLACE FUNCTION inv_is_admin() RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN app_is_super_admin() OR (app_member_kind() = 'human' AND inv_has_right('settings.manage'));
END$$;

-- Someone who belongs here: active, admitted, and holding at least the Viewer's right (every role gives it).
CREATE OR REPLACE FUNCTION inv_is_member_here() RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN app_is_active_member() AND inv_has_right('inventory.read');
END$$;

-- What app_roles answers, in one read.
CREATE OR REPLACE VIEW mcp_app_roles AS
SELECT r.role_key, r.name, r.description, r.capability, r.is_admin, r.sort_order,
       COALESCE((SELECT array_agg(rr.right_key ORDER BY h.sort_order) FROM inv_role_rights rr
                   JOIN inv_rights h ON h.right_key = rr.right_key WHERE rr.role_key = r.role_key), '{}') AS rights
FROM inv_roles r;

GRANT SELECT ON inv_rights, inv_roles, inv_role_rights, mcp_app_roles TO inventory_rw, inventory_records_ro;
GRANT EXECUTE ON FUNCTION inv_member_roles(bigint), inv_has_right(text), inv_is_admin(), inv_is_member_here()
    TO inventory_rw, inventory_records_ro, inventory_activity_ro;

COMMIT;

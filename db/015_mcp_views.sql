-- 015: WHO SEES WHAT — the mcp_* visibility views, the only thing the two read roles see (memory.md §1; design §3, §6).
-- Every WHERE clause IS the row rule; the servers never filter in Python. Caller checks are scalar subqueries tested ONCE
-- per statement (the kernel's db/160 lesson): (SELECT inv_is_member_here()), (SELECT inv_sees_cost()), (SELECT inv_has_right(…)).
--
-- COST IS THE WALL: every cost column is nulled through (SELECT inv_sees_cost()) — receipts through inv_sees_receipt_cost().
-- A SOURCE'S CREDENTIAL IS NOBODY'S: mcp_source_credentials exposes id, source, kind, label, last4, rotated_at — never
-- the ciphertext. A token hash, a link hash, a storage path and an outbox body are not selected. A customer's contact
-- details are Sales' and up (customers.write / orders.write); a source's settings and user-agent are sources.write's.
-- The primary key is re-aliased <entity>_id.
BEGIN;

CREATE OR REPLACE FUNCTION mcp_admit_agent(p_member_id bigint) RETURNS boolean
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    UPDATE members SET capability = 'write', synced_at = now()
     WHERE id = p_member_id AND member_kind = 'agent' AND status = 'active' AND capability IS NULL;
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n = 1;
END$$;
REVOKE ALL ON FUNCTION mcp_admit_agent(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION mcp_admit_agent(bigint) TO inventory_records_ro, inventory_activity_ro, inventory_rw;

CREATE OR REPLACE FUNCTION mcp_member_kind(p_member_id bigint) RETURNS text
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT member_kind FROM members WHERE id = p_member_id AND status = 'active' AND capability IS NOT NULL;
$$;
REVOKE ALL ON FUNCTION mcp_member_kind(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION mcp_member_kind(bigint) TO inventory_records_ro, inventory_activity_ro, inventory_rw;

-- ---------------------------------------------------------------------------------------------
-- People, departments, settings, vocabulary.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_members WITH (security_barrier = true) AS
SELECT m.id AS member_id, m.display_name, m.member_kind, m.is_agent, m.business_role, m.is_external, m.status, m.capability, m.roles, inv_member_roles(m.id) AS effective_roles,
       m.job_title, m.timezone, CASE WHEN m.id = app_current_member_id() OR (SELECT inv_is_admin()) THEN m.email::text END AS email,
       (SELECT array_agg(dm.department_id) FROM department_members dm WHERE dm.member_id = m.id AND dm.left_at IS NULL) AS department_ids
  FROM members m
 WHERE m.status = 'active' AND m.capability IS NOT NULL AND (SELECT inv_is_member_here());

CREATE OR REPLACE VIEW mcp_departments WITH (security_barrier = true) AS
SELECT d.id AS department_id, d.name, d.description, d.parent_id, d.manager_member_id, d.is_system, d.system_key, d.archived_at
  FROM departments d WHERE (SELECT inv_is_member_here());

CREATE OR REPLACE VIEW mcp_settings WITH (security_barrier = true) AS
SELECT s.business_name, s.business_contact_email, s.business_phone, s.currency, s.units, s.timezone, s.sales_sees_cost, s.supplier_sees_phone, s.feed_shows_quantity,
       s.order_link_days, s.supplier_link_days, s.feed_rate_per_minute, s.feed_rate_per_day, s.key_rotation_overlap_hours, s.sizes, s.attribute_keys,
       s.reorder_point_default, s.reorder_qty_default, s.cost_source, s.cost_move_pct, s.reference_undercut_pct, s.ack_days, s.buyer_member_id,
       CASE WHEN (SELECT inv_has_right('sources.write')) THEN s.crawl_user_agent END AS crawl_user_agent, s.crawl_rate_per_second, s.crawl_backoff_minutes, s.crawl_max_pages,
       s.schedule_supplier_minutes, s.schedule_reference_minutes, s.schedule_jsonld_minutes, s.removed_after_pulls, s.snapshot_heartbeat_days, s.max_attachment_bytes, s.updated_at
  FROM inv_settings s WHERE s.id = 1 AND (SELECT inv_is_member_here());

CREATE OR REPLACE VIEW mcp_document_sequences WITH (security_barrier = true) AS
SELECT d.kind, d.prefix, d.next_value, d.padding, d.updated_at FROM document_sequences d WHERE (SELECT inv_has_right('sequences.manage'));

CREATE OR REPLACE VIEW mcp_tax_rates WITH (security_barrier = true) AS
SELECT t.id AS tax_rate_id, t.name, t.rate, t.is_default, t.archived_at, t.created_at, t.updated_at FROM tax_rates t WHERE (SELECT inv_is_member_here());

CREATE OR REPLACE VIEW mcp_reason_codes WITH (security_barrier = true) AS
SELECT r.id AS reason_code_id, r.code, r.name, r.applies_to, r.affects_qty, r.sort_order, r.active FROM reason_codes r WHERE (SELECT inv_is_member_here());

CREATE OR REPLACE VIEW mcp_attachments WITH (security_barrier = true) AS
SELECT a.id AS attachment_id, a.record_type, a.record_id, a.filename, a.mime_type, a.byte_size, a.sha256, a.uploaded_by, a.created_at
  FROM attachments a WHERE (SELECT inv_is_member_here());

CREATE OR REPLACE VIEW mcp_notes WITH (security_barrier = true) AS
SELECT n.id AS note_id, n.record_type, n.record_id, n.member_id, m.display_name AS member_name, n.body, n.created_at
  FROM notes n LEFT JOIN members m ON m.id = n.member_id WHERE (SELECT inv_is_member_here());

-- ---------------------------------------------------------------------------------------------
-- Catalog.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_brands WITH (security_barrier = true) AS
SELECT b.id AS brand_id, b.name, b.website, b.supplier_id, b.active, b.created_at, b.updated_at FROM brands b WHERE (SELECT inv_is_member_here());

CREATE OR REPLACE VIEW mcp_product_types WITH (security_barrier = true) AS
SELECT t.id AS product_type_id, t.key, t.name, t.sort_order, t.active FROM product_types t WHERE (SELECT inv_is_member_here());

CREATE OR REPLACE VIEW mcp_products WITH (security_barrier = true) AS
SELECT p.id AS product_id, p.name, p.brand_id, b.name AS brand, p.product_type_id, pt.name AS product_type, p.description, p.attributes, p.kind, p.status, p.options,
       p.reorder_point, p.ships_how, p.tags, p.created_by, p.discontinued_at, p.created_at, p.updated_at,
       (SELECT count(*) FROM product_variants v WHERE v.product_id = p.id AND v.active) AS variant_count,
       (SELECT pi.attachment_id FROM product_images pi WHERE pi.product_id = p.id ORDER BY pi.is_primary DESC, pi.sort_order LIMIT 1) AS primary_image_attachment_id
  FROM products p LEFT JOIN brands b ON b.id = p.brand_id JOIN product_types pt ON pt.id = p.product_type_id
 WHERE (SELECT inv_is_member_here());

CREATE OR REPLACE VIEW mcp_product_variants WITH (security_barrier = true) AS
SELECT v.id AS variant_id, v.product_id, p.name AS product_name, b.name AS brand, p.kind, v.sku, v.option_values, v.size_key, inv_size_name(v.size_key) AS size_name, v.barcode, v.mpn,
       v.weight_g, v.length_mm, v.width_mm, v.height_mm, COALESCE(v.ships_how, p.ships_how) AS ships_how, v.retail_price, v.map_price,
       CASE WHEN (SELECT inv_sees_cost()) THEN v.cost_price END AS cost_price, NOT (SELECT inv_sees_cost()) AS cost_withheld,
       CASE WHEN (SELECT inv_sees_cost()) THEN v.cost_updated_at END AS cost_updated_at,
       COALESCE(v.reorder_point, p.reorder_point) AS reorder_point, v.reorder_qty, v.active, v.serialized, v.created_at, v.updated_at,
       COALESCE((SELECT sum(bal.qty_on_hand) FROM inventory_balances bal WHERE bal.variant_id = v.id), 0)::integer AS qty_on_hand,
       COALESCE((SELECT sum(bal.qty_on_hand - bal.qty_allocated - bal.qty_floor_model) FROM inventory_balances bal JOIN locations l ON l.id = bal.location_id AND l.is_sellable WHERE bal.variant_id = v.id), 0)::integer AS qty_available
  FROM product_variants v JOIN products p ON p.id = v.product_id LEFT JOIN brands b ON b.id = p.brand_id
 WHERE (SELECT inv_is_member_here());

CREATE OR REPLACE VIEW mcp_variant_identifiers WITH (security_barrier = true) AS
SELECT i.id AS identifier_id, i.variant_id, i.kind, i.value, i.source_id, i.created_by, i.created_at FROM variant_identifiers i WHERE (SELECT inv_is_member_here());

CREATE OR REPLACE VIEW mcp_bundle_components WITH (security_barrier = true) AS
SELECT c.bundle_variant_id, c.component_variant_id, v.sku AS component_sku, p.name AS component_product, c.qty, c.created_at
  FROM bundle_components c JOIN product_variants v ON v.id = c.component_variant_id JOIN products p ON p.id = v.product_id WHERE (SELECT inv_is_member_here());

CREATE OR REPLACE VIEW mcp_price_history WITH (security_barrier = true) AS
SELECT h.id AS price_history_id, h.variant_id, h.kind, h.old_price, h.new_price, h.changed_by, m.display_name AS changed_by_name, h.changed_at, h.reason, h.source_kind
  FROM price_history h LEFT JOIN members m ON m.id = h.changed_by
 WHERE (SELECT inv_is_member_here()) AND (h.kind <> 'cost' OR (SELECT inv_sees_cost()));

CREATE OR REPLACE VIEW mcp_product_images WITH (security_barrier = true) AS
SELECT i.id AS image_id, i.product_id, i.variant_id, i.attachment_id, i.alt_text, i.sort_order, i.is_primary, i.created_at FROM product_images i WHERE (SELECT inv_is_member_here());

-- ---------------------------------------------------------------------------------------------
-- Locations and stock.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_locations WITH (security_barrier = true) AS
SELECT l.id AS location_id, l.name, l.kind, l.address, l.department_id, l.kernel_location_id, l.is_sellable, l.allow_negative, l.active, l.created_at, l.updated_at
  FROM locations l WHERE (SELECT inv_is_member_here());

CREATE OR REPLACE VIEW mcp_inventory_balances WITH (security_barrier = true) AS
SELECT b.variant_id, v.sku, p.name AS product_name, b.location_id, l.name AS location_name, b.qty_on_hand, b.qty_allocated, b.qty_floor_model,
       b.qty_on_hand - b.qty_allocated - b.qty_floor_model AS qty_available, b.updated_at
  FROM inventory_balances b JOIN product_variants v ON v.id = b.variant_id JOIN products p ON p.id = v.product_id JOIN locations l ON l.id = b.location_id
 WHERE (SELECT inv_is_member_here());

CREATE OR REPLACE VIEW mcp_inventory_transactions WITH (security_barrier = true) AS
SELECT t.id AS transaction_id, t.group_id, t.txn_type, t.affects, t.variant_id, v.sku, t.location_id, l.name AS location_name, t.qty,
       CASE WHEN (SELECT inv_sees_cost()) OR (t.txn_type = 'receipt' AND (SELECT inv_sees_receipt_cost())) THEN t.unit_cost END AS unit_cost,
       t.counterparty_kind, t.counterparty_id, t.reason_code_id, rc.code AS reason_code, t.reference_kind, t.reference_id, t.reverses_id, t.note, t.occurred_at, t.posted_at,
       t.actor_member_id, m.display_name AS actor_name
  FROM inventory_transactions t JOIN product_variants v ON v.id = t.variant_id JOIN locations l ON l.id = t.location_id
  LEFT JOIN reason_codes rc ON rc.id = t.reason_code_id LEFT JOIN members m ON m.id = t.actor_member_id
 WHERE (SELECT inv_is_member_here());

CREATE OR REPLACE VIEW mcp_goods_receipts WITH (security_barrier = true) AS
SELECT r.id AS goods_receipt_id, r.number, r.supplier_id, s.name AS supplier_name, r.purchase_order_id, po.number AS purchase_order_number, r.location_id, l.name AS location_name,
       r.status, r.delivery_note_ref, r.received_on, r.notes, r.posted_by, r.posted_at, r.created_by, r.created_at, r.updated_at
  FROM goods_receipts r LEFT JOIN suppliers s ON s.id = r.supplier_id LEFT JOIN purchase_orders po ON po.id = r.purchase_order_id JOIN locations l ON l.id = r.location_id
 WHERE (SELECT inv_is_member_here());

CREATE OR REPLACE VIEW mcp_goods_receipt_lines WITH (security_barrier = true) AS
SELECT gl.id AS goods_receipt_line_id, gl.goods_receipt_id, gl.line_no, gl.purchase_order_line_id, gl.variant_id, v.sku, gl.qty,
       CASE WHEN (SELECT inv_sees_receipt_cost()) THEN gl.unit_cost END AS unit_cost, gl.discrepancy_kind, gl.discrepancy_note, gl.putaway_location_id, gl.created_at, gl.updated_at
  FROM goods_receipt_lines gl JOIN product_variants v ON v.id = gl.variant_id WHERE (SELECT inv_is_member_here());

CREATE OR REPLACE VIEW mcp_inventory_transfers WITH (security_barrier = true) AS
SELECT t.id AS transfer_id, t.number, t.from_location_id, f.name AS from_location, t.to_location_id, tl.name AS to_location, t.status, t.notes,
       t.shipped_by, t.shipped_at, t.received_by, t.received_at, t.created_by, t.created_at, t.updated_at
  FROM inventory_transfers t JOIN locations f ON f.id = t.from_location_id JOIN locations tl ON tl.id = t.to_location_id WHERE (SELECT inv_is_member_here());

CREATE OR REPLACE VIEW mcp_inventory_transfer_lines WITH (security_barrier = true) AS
SELECT l.id AS transfer_line_id, l.transfer_id, l.line_no, l.variant_id, v.sku, l.qty, l.qty_received, l.created_at, l.updated_at
  FROM inventory_transfer_lines l JOIN product_variants v ON v.id = l.variant_id WHERE (SELECT inv_is_member_here());

CREATE OR REPLACE VIEW mcp_inventory_adjustments WITH (security_barrier = true) AS
SELECT a.id AS adjustment_id, a.number, a.location_id, l.name AS location_name, a.reason_code_id, rc.code AS reason_code, a.status, a.notes, a.posted_by, a.posted_at, a.created_by, a.created_at, a.updated_at
  FROM inventory_adjustments a JOIN locations l ON l.id = a.location_id JOIN reason_codes rc ON rc.id = a.reason_code_id WHERE (SELECT inv_is_member_here());

CREATE OR REPLACE VIEW mcp_inventory_adjustment_lines WITH (security_barrier = true) AS
SELECT l.id AS adjustment_line_id, l.adjustment_id, l.line_no, l.variant_id, v.sku, l.qty_delta, CASE WHEN (SELECT inv_sees_cost()) THEN l.unit_cost END AS unit_cost, l.note, l.created_at, l.updated_at
  FROM inventory_adjustment_lines l JOIN product_variants v ON v.id = l.variant_id WHERE (SELECT inv_is_member_here());

CREATE OR REPLACE VIEW mcp_inventory_counts WITH (security_barrier = true) AS
SELECT c.id AS count_id, c.number, c.location_id, l.name AS location_name, c.status, c.notes, c.started_by, c.started_at, c.posted_by, c.posted_at, c.created_at, c.updated_at,
       (SELECT count(*) FROM inventory_count_lines cl WHERE cl.count_id = c.id) AS line_count,
       (SELECT count(*) FROM inventory_count_lines cl WHERE cl.count_id = c.id AND cl.counted_qty IS NOT NULL AND cl.counted_qty <> cl.system_qty) AS lines_differing
  FROM inventory_counts c JOIN locations l ON l.id = c.location_id WHERE (SELECT inv_is_member_here());

CREATE OR REPLACE VIEW mcp_inventory_count_lines WITH (security_barrier = true) AS
SELECT l.id AS count_line_id, l.count_id, l.variant_id, v.sku, l.system_qty, l.counted_qty, l.counted_qty - l.system_qty AS difference, l.counted_by, l.counted_at, l.created_at, l.updated_at
  FROM inventory_count_lines l JOIN product_variants v ON v.id = l.variant_id WHERE (SELECT inv_is_member_here());

-- ---------------------------------------------------------------------------------------------
-- Suppliers and sources.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_suppliers WITH (security_barrier = true) AS
SELECT s.id AS supplier_id, s.name, s.kind, s.contact_name, s.email, s.phone, s.address, s.notes, s.active, s.website,
       CASE WHEN (SELECT inv_has_right('purchasing.write')) THEN s.account_number END AS account_number, s.terms, s.dropships, s.lead_time_days, s.order_method, s.order_email, s.portal_url, s.min_order,
       s.created_at, s.updated_at
  FROM suppliers s WHERE (SELECT inv_is_member_here());

CREATE OR REPLACE VIEW mcp_supplier_items WITH (security_barrier = true) AS
SELECT si.id AS supplier_item_id, si.supplier_id, s.name AS supplier_name, si.variant_id, v.sku, si.supplier_sku, CASE WHEN (SELECT inv_sees_cost()) THEN si.cost END AS cost, NOT (SELECT inv_sees_cost()) AS cost_withheld,
       si.lead_time_days, si.moq, si.active, si.last_seen_at, si.source_id, si.created_at, si.updated_at
  FROM supplier_items si JOIN suppliers s ON s.id = si.supplier_id JOIN product_variants v ON v.id = si.variant_id WHERE (SELECT inv_is_member_here());

CREATE OR REPLACE VIEW mcp_sources WITH (security_barrier = true) AS
SELECT s.id AS source_id, s.name, s.connector, s.role, s.supplier_id, sp.name AS supplier_name, s.base_url,
       CASE WHEN (SELECT inv_has_right('sources.write')) THEN s.settings END AS settings, s.credential_id, s.schedule_minutes, s.rate_per_second,
       CASE WHEN (SELECT inv_has_right('sources.write')) THEN s.user_agent END AS user_agent, s.robots_state, s.robots_checked_at, s.last_pull_id, s.last_ok_at,
       s.consecutive_failures, s.backoff_until, s.paused_at, s.paused_reason, s.active, s.created_by, s.created_at, s.updated_at
  FROM sources s LEFT JOIN suppliers sp ON sp.id = s.supplier_id WHERE (SELECT inv_is_member_here());

-- NEVER the ciphertext.
CREATE OR REPLACE VIEW mcp_source_credentials WITH (security_barrier = true) AS
SELECT c.id AS credential_id, c.source_id, c.kind, c.label, c.last4, c.rotated_at, c.created_by, c.created_at
  FROM source_credentials c WHERE (SELECT inv_has_right('sources.write'));

CREATE OR REPLACE VIEW mcp_source_pulls WITH (security_barrier = true) AS
SELECT p.id AS pull_id, p.source_id, s.name AS source_name, p.kind, p.started_at, p.finished_at, p.status, p.listings_seen, p.listings_new, p.listings_changed, p.variants_changed,
       p.listings_removed, p.http_requests, p.bytes, p.error, p.policy, p.query, p.started_by
  FROM source_pulls p JOIN sources s ON s.id = p.source_id WHERE (SELECT inv_is_member_here());

CREATE OR REPLACE VIEW mcp_source_templates WITH (security_barrier = true) AS
SELECT t.id AS template_id, t.key, t.name, t.connector, t.role, t.base_url, t.settings, t.brand_hint, t.notes, t.survey_result, t.surveyed_at, t.sort_order, t.active
  FROM source_templates t WHERE (SELECT inv_is_member_here());

CREATE OR REPLACE VIEW mcp_listings WITH (security_barrier = true) AS
SELECT l.id AS listing_id, l.source_id, s.name AS source_name, s.role AS source_role, l.external_id, l.handle, l.url, l.title, l.vendor, l.product_type, l.tags,
       CASE WHEN (SELECT inv_has_right('listings.match')) THEN l.raw END AS raw, l.product_id, l.first_seen_at, l.last_seen_at, l.removed_at, l.created_at, l.updated_at,
       (SELECT count(*) FROM listing_variants lv WHERE lv.listing_id = l.id AND lv.removed_at IS NULL) AS variant_count,
       (SELECT count(*) FROM listing_variants lv WHERE lv.listing_id = l.id AND lv.removed_at IS NULL AND lv.variant_id IS NOT NULL) AS matched_count
  FROM listings l JOIN sources s ON s.id = l.source_id WHERE (SELECT inv_is_member_here());

CREATE OR REPLACE VIEW mcp_listing_variants WITH (security_barrier = true) AS
SELECT lv.id AS listing_variant_id, lv.listing_id, l.source_id, s.name AS source_name, s.role AS source_role, l.title AS listing_title, lv.external_variant_id, lv.title, lv.option_values,
       lv.size_key, inv_size_name(lv.size_key) AS size_name, lv.sku, lv.barcode, lv.barcode_valid, lv.mpn, lv.variant_id, lv.match_kind, lv.match_confidence, lv.matched_by, lv.matched_at,
       lv.price, lv.compare_at_price, lv.currency, CASE WHEN (SELECT inv_sees_cost()) THEN lv.cost_price END AS cost_price, NOT (SELECT inv_sees_cost()) AS cost_withheld,
       lv.availability, lv.qty, lv.lead_time_days, lv.ships_how, lv.url, lv.first_seen_at, lv.last_seen_at, lv.removed_at, lv.created_at, lv.updated_at
  FROM listing_variants lv JOIN listings l ON l.id = lv.listing_id JOIN sources s ON s.id = l.source_id WHERE (SELECT inv_is_member_here());

CREATE OR REPLACE VIEW mcp_offer_snapshots WITH (security_barrier = true) AS
SELECT o.id AS snapshot_id, o.listing_variant_id, o.pull_id, o.observed_at, o.price, o.compare_at_price, CASE WHEN (SELECT inv_sees_cost()) THEN o.cost_price END AS cost_price,
       o.availability, o.qty, o.lead_time_days, o.is_heartbeat
  FROM offer_snapshots o WHERE (SELECT inv_is_member_here());

CREATE OR REPLACE VIEW mcp_match_proposals WITH (security_barrier = true) AS
SELECT mp.id AS proposal_id, mp.listing_variant_id, mp.variant_id, v.sku, p.name AS product_name, mp.confidence, mp.evidence, mp.proposed_by, m.display_name AS proposed_by_name,
       mp.status, mp.decided_by, mp.decided_at, mp.created_at, mp.updated_at
  FROM match_proposals mp JOIN product_variants v ON v.id = mp.variant_id JOIN products p ON p.id = v.product_id LEFT JOIN members m ON m.id = mp.proposed_by
 WHERE (SELECT inv_is_member_here());

CREATE OR REPLACE VIEW mcp_watches WITH (security_barrier = true) AS
SELECT w.id AS watch_id, w.member_id, m.display_name AS member_name, w.agent_member_id, w.kind, w.variant_id, w.listing_variant_id, w.product_id, w.threshold, w.text_me,
       w.last_state, w.fired_at, w.fire_count, w.active, w.note, w.created_at, w.updated_at
  FROM watches w LEFT JOIN members m ON m.id = w.member_id
 WHERE (SELECT inv_is_member_here()) AND (w.member_id = app_current_member_id() OR (SELECT inv_has_right('watches.all')));

-- ---------------------------------------------------------------------------------------------
-- Customers and orders. Contact details for Sales and up; a Viewer sees the name and the status.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_customers WITH (security_barrier = true) AS
SELECT c.id AS customer_id, c.name, c.legal_name,
       CASE WHEN (SELECT inv_has_right('orders.write')) THEN c.email::text END AS email,
       CASE WHEN (SELECT inv_has_right('orders.write')) THEN c.phone END AS phone,
       CASE WHEN (SELECT inv_has_right('orders.write')) THEN c.phone_alt END AS phone_alt,
       CASE WHEN (SELECT inv_has_right('orders.write')) THEN c.billing_address END AS billing_address,
       CASE WHEN (SELECT inv_has_right('orders.write')) THEN c.shipping_address END AS shipping_address,
       c.tax_id, c.terms_days, c.currency, c.income_account_id, c.tax_rate_id, c.member_id, c.notes, c.source, c.email_opt_in, c.archived_at, c.created_by, c.created_at, c.updated_at,
       (SELECT count(*) FROM sales_orders o WHERE o.customer_id = c.id AND o.status NOT IN ('quote', 'cancelled')) AS order_count
  FROM customers c WHERE (SELECT inv_is_member_here());

CREATE OR REPLACE VIEW mcp_sales_orders WITH (security_barrier = true) AS
SELECT o.id AS sales_order_id, o.number, o.customer_id, c.name AS customer_name, o.status, o.origin, o.salesperson_member_id, m.display_name AS salesperson_name, o.location_id, l.name AS location_name,
       o.ordered_on, o.promised_on, o.delivery_method,
       CASE WHEN (SELECT inv_has_right('orders.write')) OR (SELECT inv_has_right('stock.ship')) THEN o.ship_to_name END AS ship_to_name,
       CASE WHEN (SELECT inv_has_right('orders.write')) OR (SELECT inv_has_right('stock.ship')) THEN o.ship_to_address1 END AS ship_to_address1,
       CASE WHEN (SELECT inv_has_right('orders.write')) OR (SELECT inv_has_right('stock.ship')) THEN o.ship_to_address2 END AS ship_to_address2,
       o.ship_to_city, o.ship_to_region, o.ship_to_postal, o.ship_to_country,
       CASE WHEN (SELECT inv_has_right('orders.write')) OR (SELECT inv_has_right('stock.ship')) THEN o.ship_to_phone END AS ship_to_phone,
       o.ship_to_notes, o.tax_rate_id, o.subtotal, o.discount_total, o.tax_total, o.shipping_charge, o.total, o.payment_status, o.amount_paid, o.total - o.amount_paid AS balance_due,
       o.customer_reference, o.notes, o.confirmed_by, o.confirmed_at, o.closed_by, o.closed_at, o.cancelled_by, o.cancelled_at, o.cancel_reason, o.created_by, o.created_at, o.updated_at,
       (o.promised_on IS NOT NULL AND o.promised_on < current_date AND o.status IN ('confirmed', 'in_fulfilment', 'shipped')) AS is_late,
       (SELECT count(*) FROM sales_order_lines sl WHERE sl.sales_order_id = o.id AND sl.status <> 'cancelled') AS line_count
  FROM sales_orders o JOIN customers c ON c.id = o.customer_id LEFT JOIN members m ON m.id = o.salesperson_member_id LEFT JOIN locations l ON l.id = o.location_id
 WHERE (SELECT inv_is_member_here());

CREATE OR REPLACE VIEW mcp_sales_order_lines WITH (security_barrier = true) AS
SELECT sl.id AS line_id, sl.sales_order_id, o.number AS order_number, sl.line_no, sl.variant_id, v.sku, p.name AS product_name, inv_size_name(v.size_key) AS size_name, sl.qty, sl.unit_price, sl.discount, sl.line_total,
       sl.fulfilment_kind, sl.location_id, sl.listing_variant_id, sl.source_id, s.name AS source_name,
       CASE WHEN (SELECT inv_sees_cost()) THEN sl.offer_cost END AS offer_cost, NOT (SELECT inv_sees_cost()) AS cost_withheld, sl.offer_lead_time_days, sl.purchase_order_line_id,
       sl.qty_allocated, sl.qty_shipped, sl.qty_returned, sl.status, sl.serials, sl.notes, sl.created_at, sl.updated_at
  FROM sales_order_lines sl JOIN sales_orders o ON o.id = sl.sales_order_id JOIN product_variants v ON v.id = sl.variant_id JOIN products p ON p.id = v.product_id LEFT JOIN sources s ON s.id = sl.source_id
 WHERE (SELECT inv_is_member_here());

CREATE OR REPLACE VIEW mcp_order_payments WITH (security_barrier = true) AS
SELECT pm.id AS payment_id, pm.sales_order_id, pm.kind, pm.amount, pm.method, pm.reference, pm.taken_by, m.display_name AS taken_by_name, pm.taken_at, pm.note, pm.created_at
  FROM order_payments pm LEFT JOIN members m ON m.id = pm.taken_by
 WHERE (SELECT inv_has_right('payments.record')) OR (SELECT inv_has_right('reports.read'));

CREATE OR REPLACE VIEW mcp_shipments WITH (security_barrier = true) AS
SELECT sh.id AS shipment_id, sh.sales_order_id, sh.kind, sh.carrier, sh.tracking_number, sh.tracking_url, sh.shipped_at, sh.delivered_at, sh.shipped_by, sh.note, sh.created_at, sh.updated_at
  FROM shipments sh WHERE (SELECT inv_is_member_here());

CREATE OR REPLACE VIEW mcp_shipment_lines WITH (security_barrier = true) AS
SELECT sl.id AS shipment_line_id, sl.shipment_id, sl.sales_order_line_id, sl.qty, sl.serials, sl.created_at FROM shipment_lines sl WHERE (SELECT inv_is_member_here());

CREATE OR REPLACE VIEW mcp_order_links WITH (security_barrier = true) AS
SELECT k.id AS link_id, k.sales_order_id, k.expires_at, k.rotated_at, k.last_used_at, k.view_count, k.created_at, k.rotated_at IS NULL AND (k.expires_at IS NULL OR k.expires_at > now()) AS is_live
  FROM order_links_secure k WHERE (SELECT inv_has_right('orders.send'));

-- ---------------------------------------------------------------------------------------------
-- Purchasing.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_purchase_orders WITH (security_barrier = true) AS
SELECT po.id AS purchase_order_id, po.number, po.supplier_id, sp.name AS supplier_name, po.kind, po.sales_order_id, so.number AS sales_order_number, po.status, po.ship_to_kind, po.location_id,
       CASE WHEN (SELECT inv_has_right('purchasing.write')) THEN po.ship_to_name END AS ship_to_name, po.ship_to_city, po.ship_to_region, po.ship_to_postal, po.ship_to_country,
       po.ordered_on, po.expected_on, po.supplier_order_ref, po.sent_via, po.sent_at, po.sent_by, po.acknowledged_at,
       CASE WHEN (SELECT inv_sees_cost()) THEN po.subtotal END AS subtotal, CASE WHEN (SELECT inv_sees_cost()) THEN po.shipping_cost END AS shipping_cost,
       CASE WHEN (SELECT inv_sees_cost()) THEN po.total END AS total, NOT (SELECT inv_sees_cost()) AS cost_withheld,
       po.notes, CASE WHEN (SELECT inv_has_right('purchasing.write')) THEN po.internal_notes END AS internal_notes, po.approved_by, po.approved_at, po.closed_at, po.cancelled_by, po.cancelled_at, po.cancel_reason,
       po.created_by, po.created_at, po.updated_at,
       (SELECT count(*) FROM purchase_order_lines pl WHERE pl.purchase_order_id = po.id) AS line_count
  FROM purchase_orders po JOIN suppliers sp ON sp.id = po.supplier_id LEFT JOIN sales_orders so ON so.id = po.sales_order_id
 WHERE (SELECT inv_is_member_here());

CREATE OR REPLACE VIEW mcp_purchase_order_lines WITH (security_barrier = true) AS
SELECT pl.id AS purchase_order_line_id, pl.purchase_order_id, po.number AS purchase_order_number, pl.line_no, pl.variant_id, v.sku, p.name AS product_name, pl.supplier_sku, pl.listing_variant_id,
       pl.qty_ordered, CASE WHEN (SELECT inv_sees_cost()) THEN pl.unit_cost END AS unit_cost, NOT (SELECT inv_sees_cost()) AS cost_withheld, pl.expected_on, pl.qty_received,
       pl.sales_order_line_id, pl.status, pl.supplier_note, pl.tracking_carrier, pl.tracking_number, pl.shipped_at, pl.created_at, pl.updated_at
  FROM purchase_order_lines pl JOIN purchase_orders po ON po.id = pl.purchase_order_id JOIN product_variants v ON v.id = pl.variant_id JOIN products p ON p.id = v.product_id
 WHERE (SELECT inv_is_member_here());

CREATE OR REPLACE VIEW mcp_purchase_order_events WITH (security_barrier = true) AS
SELECT e.id AS event_id, e.purchase_order_id, e.purchase_order_line_id, e.kind, e.source, e.supplier_order_ref, e.expected_on, e.carrier, e.tracking_number, e.shipped_at, e.reason, e.note, e.member_id, e.created_at
  FROM purchase_order_events e WHERE (SELECT inv_is_member_here());

CREATE OR REPLACE VIEW mcp_supplier_links WITH (security_barrier = true) AS
SELECT k.id AS link_id, k.purchase_order_id, k.expires_at, k.rotated_at, k.last_used_at, k.view_count, k.created_at, k.rotated_at IS NULL AND (k.expires_at IS NULL OR k.expires_at > now()) AS is_live
  FROM supplier_links_secure k WHERE (SELECT inv_has_right('purchasing.write'));

-- ---------------------------------------------------------------------------------------------
-- Returns.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_return_authorizations WITH (security_barrier = true) AS
SELECT ra.id AS return_id, ra.number, ra.sales_order_id, so.number AS order_number, ra.customer_id, c.name AS customer_name, ra.status, ra.method, ra.scheduled_on, ra.location_id,
       ra.refund_amount, ra.restocking_fee, ra.notes, ra.requested_by, ra.approved_by, ra.approved_at, ra.received_by, ra.received_at, ra.closed_by, ra.closed_at, ra.denied_by, ra.denied_at, ra.deny_reason,
       ra.created_at, ra.updated_at
  FROM return_authorizations ra JOIN sales_orders so ON so.id = ra.sales_order_id JOIN customers c ON c.id = ra.customer_id WHERE (SELECT inv_is_member_here());

CREATE OR REPLACE VIEW mcp_return_lines WITH (security_barrier = true) AS
SELECT rl.id AS return_line_id, rl.return_id, rl.sales_order_line_id, rl.variant_id, v.sku, rl.qty, rl.reason_code_id, rc.code AS reason_code, rl.disposition, rl.location_id, rl.condition_note, rl.qty_received, rl.created_at, rl.updated_at
  FROM return_lines rl JOIN product_variants v ON v.id = rl.variant_id JOIN reason_codes rc ON rc.id = rl.reason_code_id WHERE (SELECT inv_is_member_here());

-- ---------------------------------------------------------------------------------------------
-- Notifications, agents, feed, tokens. Own rows, or the admin's view of all.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_notifications WITH (security_barrier = true) AS
SELECT n.id AS notification_id, n.member_id, n.kind, n.record_type, n.record_id, n.title, n.body, n.read_at, n.created_at
  FROM notifications n WHERE (SELECT inv_is_member_here()) AND (n.member_id = app_current_member_id() OR (SELECT inv_is_admin()));

CREATE OR REPLACE VIEW mcp_notification_prefs WITH (security_barrier = true) AS
SELECT p.member_id, p.email_enabled, p.text_enabled, p.kinds, p.text_kinds, p.updated_at
  FROM notification_prefs p WHERE (SELECT inv_is_member_here()) AND (p.member_id = app_current_member_id() OR (SELECT inv_is_admin()));

CREATE OR REPLACE VIEW mcp_notification_outbox WITH (security_barrier = true) AS
SELECT o.id AS outbox_id, o.channel, o.member_id, o.kind, o.record_type, o.record_id, o.subject, o.status, o.attempts, o.detail, o.send_after, o.sent_at, o.created_at
  FROM notification_outbox o WHERE (SELECT inv_is_member_here()) AND (o.member_id = app_current_member_id() OR (SELECT inv_is_admin()));

CREATE OR REPLACE VIEW mcp_agent_dispatches WITH (security_barrier = true) AS
SELECT d.id AS dispatch_id, d.record_type, d.record_id, d.agent_member_id, m.display_name AS agent_name, d.kind, d.via, d.acting_member_id, d.run_id, d.status, d.reply_excerpt, d.detail,
       d.attempts, d.created_at, d.answered_at, d.watch_id, d.listing_variant_id
  FROM agent_dispatches d LEFT JOIN members m ON m.id = d.agent_member_id
 WHERE (SELECT inv_is_member_here()) AND (d.agent_member_id = app_current_member_id() OR d.acting_member_id = app_current_member_id() OR (SELECT inv_has_right('agents.settings')) OR (SELECT inv_has_right('reports.read')));

CREATE OR REPLACE VIEW mcp_buyer_proposals WITH (security_barrier = true) AS
SELECT b.id AS proposal_id, b.kind, b.subject_type, b.subject_id, b.title, CASE WHEN (SELECT inv_sees_cost()) THEN b.detail ELSE b.detail - 'cost' - 'best_cost' - 'margin' END AS detail,
       b.drafted_record_type, b.drafted_record_id, b.status, b.proposed_by, b.note_date, b.decided_by, b.decided_at, b.created_at, b.updated_at
  FROM buyer_proposals b WHERE (SELECT inv_has_right('reports.read')) OR (SELECT inv_has_right('agents.settings')) OR b.proposed_by = app_current_member_id();

CREATE OR REPLACE VIEW mcp_price_lists WITH (security_barrier = true) AS
SELECT p.id AS price_list_id, p.name, p.percent_off_retail, p.notes, p.active, p.created_at, p.updated_at FROM price_lists p WHERE (SELECT inv_has_right('feed.keys'));

-- NEVER the hash.
CREATE OR REPLACE VIEW mcp_feed_keys WITH (security_barrier = true) AS
SELECT k.id AS key_id, k.member_id, k.label, k.consumer_kind, k.price_list_id, k.rate_per_minute, k.rate_per_day, k.rotated_from, k.last_used_at, k.expires_at, k.revoked_at, k.revoked_by, k.created_at,
       k.revoked_at IS NULL AND (k.expires_at IS NULL OR k.expires_at > now()) AS is_live, inv_feed_key_calls_today(k.id) AS calls_today
  FROM feed_keys k WHERE (SELECT inv_has_right('feed.keys'));

CREATE OR REPLACE VIEW mcp_key_usage WITH (security_barrier = true) AS
SELECT u.token_id AS key_id, u.bucket_kind, u.bucket_start, u.calls, u.refused FROM key_usage u WHERE (SELECT inv_has_right('feed.keys'));

CREATE OR REPLACE VIEW mcp_access_tokens_mine WITH (security_barrier = true) AS
SELECT t.id AS token_id, t.label, t.scope, t.last_used_at, t.expires_at, t.revoked_at, t.created_at
  FROM mcp_access_tokens t WHERE (SELECT app_is_active_member()) AND t.member_id = app_current_member_id();

-- ---------------------------------------------------------------------------------------------
-- Activity: a record's history is the log (design §6). Nothing secret is in a payload (the rule); a member sees rows
-- about records anyone here may see — that is everything but the admin-only tables — and their own rows.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_activity_log WITH (security_barrier = true) AS
SELECT a.id AS activity_id, a.occurred_at, a.actor_member_id, m.display_name AS actor_name, m.member_kind = 'agent' AS actor_is_agent, a.source, a.action, a.screen, a.route,
       a.entity_type, a.entity_id, a.before, a.after, a.request_id, a.agent_run_id, a.department_id, a.location_id, a.source_id, a.sales_order_id, a.purchase_order_id, a.token_id
  FROM activity_log a LEFT JOIN members m ON m.id = a.actor_member_id
 WHERE (SELECT inv_is_member_here())
   AND (a.actor_member_id = app_current_member_id()
        OR (SELECT inv_is_admin())
        OR COALESCE(a.entity_type, '') NOT IN ('feed_key', 'source_credential', 'settings', 'inv_settings', 'mcp_access_token'));

-- ---------------------------------------------------------------------------------------------
-- Grants: the read roles see the views and nothing else; the writer reads them too (one shape for screens and tools).
-- ---------------------------------------------------------------------------------------------
GRANT SELECT ON mcp_members, mcp_departments, mcp_settings, mcp_document_sequences, mcp_tax_rates, mcp_reason_codes, mcp_attachments, mcp_notes,
    mcp_brands, mcp_product_types, mcp_products, mcp_product_variants, mcp_variant_identifiers, mcp_bundle_components, mcp_price_history, mcp_product_images,
    mcp_locations, mcp_inventory_balances, mcp_inventory_transactions, mcp_goods_receipts, mcp_goods_receipt_lines, mcp_inventory_transfers, mcp_inventory_transfer_lines,
    mcp_inventory_adjustments, mcp_inventory_adjustment_lines, mcp_inventory_counts, mcp_inventory_count_lines,
    mcp_suppliers, mcp_supplier_items, mcp_sources, mcp_source_credentials, mcp_source_pulls, mcp_source_templates, mcp_listings, mcp_listing_variants, mcp_offer_snapshots, mcp_match_proposals, mcp_watches,
    mcp_customers, mcp_sales_orders, mcp_sales_order_lines, mcp_order_payments, mcp_shipments, mcp_shipment_lines, mcp_order_links,
    mcp_purchase_orders, mcp_purchase_order_lines, mcp_purchase_order_events, mcp_supplier_links, mcp_return_authorizations, mcp_return_lines,
    mcp_notifications, mcp_notification_prefs, mcp_notification_outbox, mcp_agent_dispatches, mcp_buyer_proposals, mcp_price_lists, mcp_feed_keys, mcp_key_usage, mcp_access_tokens_mine
    TO inventory_records_ro, inventory_rw;
GRANT SELECT ON mcp_activity_log TO inventory_activity_ro, inventory_rw;
GRANT SELECT ON mcp_members, mcp_departments, mcp_products, mcp_product_variants, mcp_sources, mcp_sales_orders, mcp_purchase_orders, mcp_locations TO inventory_activity_ro;   -- names beside an activity row

COMMIT;

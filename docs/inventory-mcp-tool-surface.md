# Inventory — MCP tool surface

2026-10-05 · **Phase 1, for the checkpoint.** The read tools that answer every question in `docs/inventory-design.md` §7 (C1–C6, S1–S7,
A1–A14, O1–O7, D1–D6, F1, G1, ACT1–ACT5), the five K7 shares (§8) and the availability feed's document (§4). Writes are never here:
they are the action tools the kernel's Actions MCP builds from `docs/inventory-action-manifest.md`. The one exception in effect,
`source_search` (A8), asks the live connectors and writes what came back like a pull — it does that through PHP (the bridge, below),
never from the read role.

Conventions (`mcp-and-api.md`): Python 3 + FastMCP (`mcp>=1.2,<2`), streamable HTTP at `/mcp`, bound to `127.0.0.1`, one systemd unit
each (`inventory-records-mcp`, `inventory-activity-mcp`, `deploy/`); `mcp/db.py` and `mcp/server_common.py` (already here, the
kit's). Every query runs through `db.fetch_scoped()` with `app.member_id` and `app.role` set **transaction-locally** from the verified
bearer, so the `mcp_*` views (db/015) and the read functions (db/014) decide the rows and answer as the caller; a pooled connection never
carries an identity past its transaction. Tool names are snake_case. Every tool takes one `params` object (Pydantic v2,
`extra="forbid"`, `str_strip_whitespace=True`, a described `Field` on every field). Every list tool takes `limit` (1–100, default 25)
and `offset` (0–10,000). Every tool carries `readOnlyHint: true`, `openWorldHint: false` (`source_search` carries `openWorldHint: true`
— it reaches the web), and every result is a JSON string (`db.to_json`). Ids are bigints, money is `numeric(12,2)` in the business's one
currency, quantities are integers, weights are grams and dimensions millimetres, times are ISO 8601 with the zone, dates are `YYYY-MM-DD`.
**The answers an agent reads aloud carry a `markdown` field beside the facts** — `availability`, `atp`, `get_order`, `order_timeline`,
`get_purchase_order`, `morning_note` — three to twelve lines, cost named only when the caller may see it.

**The reads are the SQL functions and views the screens call — never ad-hoc SQL in Python.** A tool names, below, the function or the
view it reads. Where §7 names a question that no db/014 function answers, the tool is composed over the views and the function it should
have is listed under "Owed to the schema" at the end.

**Errors are answers, not exceptions** (Knowledge's convention — DECISION 1). A tool that cannot answer returns `{"ok": false, "error":
{"code", "message", "retry": bool}}`; it never raises except for the run-facts gate's own refusal (the contract's ToolError). Codes:
`not_found` (no such record, **or one the caller may not see** — the same words), `invalid_argument` (`fields` names it), `ambiguous`
(a name matched several; `candidates[]` ≤ 6 with id and label), `withheld` (the whole answer is cost — `stock_value` for a Viewer),
`bridge_unavailable` (PHP did not answer; `retry: true`), `source_timeout` / `source_blocked` (`source_search`), `too_large`.
**Every list answers `{"ok": true, "count", "rows": [...], "truncated": bool}`; every detail answers `{"ok": true, ...fields}`**
(DECISION 2 — the kernel's resolver reads `rows` from an envelope, `application_actions.py`).

## Cost is the wall — how every tool honours it (design §3, D2)

`inv_sees_cost()` = `cost.read` (Buyer, admin) or Sales when `sales_sees_cost` is on; `inv_sees_receipt_cost()` adds `stock.receive`
(Warehouse, on receipts). Three mechanisms, and a tool says which it relies on (the **wall** column):

| Wall | Means |
|---|---|
| `view` | the `mcp_*` view nulls the cost column and carries `cost_withheld: true`; the tool passes the row through |
| `fn` | the read function nulls cost and carries `cost_withheld` (`inv_availability`, `inv_find`, `inv_atp`, `inv_offer_history`, `inv_price_history` drops cost rows, `inv_reorder_candidates`, `inv_price_exceptions`, `inv_stock_value`, `inv_sell_through`, `inv_order_timeline`, `inv_purchase_orders_open`) |
| `none` | the answer carries no cost field at all |

A tool **never** computes, copies or infers cost in Python: `inv_offers_for_variant()` (cost unnulled) is revoked from the read role, so
offers reach a tool only through `inv_availability()` / `inv_find()` / `mcp_listing_variants`, where the wall stands. A withheld cost is
the literal `null` beside `cost_withheld: true`; the `markdown` says "cost withheld". `stock_value` and `sell_through`'s `cogs`/`margin_pct`
for a caller without the right answer rows whose `value` is null and `cost_withheld: true` — the units still count. The five share
functions (owed, below) are the one place cost crosses unnulled: to the General Ledger, over a connection a super-admin approved.

## The two servers

| | Records | Activity |
|---|---|---|
| FastMCP name | `inventory_records_mcp` | `inventory_activity_mcp` |
| Port | `MCP_RECORDS_PORT` (pinned 8837) | `MCP_ACTIVITY_PORT` (pinned 8838) |
| Role / env | `inventory_records_ro` (`MCP_RECORDS_DB_USER`/`_PASSWORD`) | `inventory_activity_ro` (`MCP_ACTIVITY_DB_USER`/`_PASSWORD`) |
| Sees | 61 `mcp_*` views: the 60 of db/015 but `mcp_activity_log`, plus `mcp_app_roles` (db/004); the db/014 functions granted to it; `mcp_resolve_token`, `mcp_admit_agent`, `mcp_member_kind` | `mcp_activity_log` + `mcp_members`, `mcp_departments`, `mcp_products`, `mcp_product_variants`, `mcp_sources`, `mcp_sales_orders`, `mcp_purchase_orders`, `mcp_locations` (names beside a row) |
| Public path | `inventory.<domain>/mcp/records` (Apache, `ProxyPreserveHost Off`) | `/mcp/activity` |
| Kernel registry endpoint name | `Records MCP` | `Activity MCP` |
| Modules (Phase 4) | `mcp/inv_catalog.py`, `inv_stock.py`, `inv_sources.py`, `inv_orders.py`, `inv_purchasing.py`, `inv_agents.py`, `inv_shares.py`, `inv_misc.py`, each `register(mcp, q, bridge)` | `mcp/activity_server.py` |

**Who may call: three token shapes, one middleware** (`server_common.make_app`).

1. **A person's own token**: `mcp_` + 48 hex, scope `mcp`, minted on My MCP tokens (`mcp_access_tokens`), only its sha256 stored,
   resolved by `mcp_resolve_token(hash, 'mcp')` (active, admitted). **A feed key (`feed_` + 48 hex) is refused here** (401): keys are
   for `/api/v1/availability` alone. A person's token is never tool-filtered; the views and functions decide the rows.
2. **The tenant's signed tokens** (`ACTION_TOKEN_KEY`): `{mid}.{exp}.{hmac}` (the person at the keyboard, through the kernel's
   assistant or the command bar) or the **agent run token** `{mid}.{exp}.{run}.{hmac}`. The member id must already be in the mirror
   and admitted — **an unknown id is refused, never created**. For a run token the server asks the kernel's run-facts call
   (`POST {OS_INTERNAL_URL}/api/v1/runs/facts.php {"token"}`, `Authorization: Bearer {OS_APPLICATION_TOKEN}`) once per run, caches
   300 s, and offers exactly the tools named for `Records MCP` / `Activity MCP`. On first contact a vouched agent is admitted
   (`mcp_admit_agent`) and resolved again. **Fail closed**: the kernel unreachable, `OS_APPLICATION_TOKEN` missing or `valid: false`
   → no tools listed, none callable. **`is_eval` refuses nothing here, since reads are reads** — the one tool that writes,
   `source_search`, asks the sources and answers but **persists nothing** under an eval run (no pull row, no listing, no snapshot, no
   match; logged `source.search` with `after.eval = true`), so the kernel's "nothing changed" check holds. The agent's role (Buyer for
   the two shipped agents, `maludb-os.json`) decides cost like anyone's.
3. **The kernel's own token** (`verify_kernel_token`, 60 s, bound to `APP_KEY = inventory`): `KERNEL_TOOLS = {app_roles,
   sales_closed, purchases_received, stock_valuation, availability_index, customer_orders}` — `app_roles` and the five `shares[]`
   of `maludb-os.json`, nothing else (`server_common.py` is updated in Phase 4; `KERNEL_ONLY` stays empty: every share also answers a
   person or an agent holding the role, as its own tool). No member identity is set, so every view answers nothing; the shares
   answer through the **share functions** (owed, below), people-free. The kernel sends a share's arguments flat; the middleware wraps
   them in `params`. **The kernel passes no consumer today (Knowledge's K26)** — Inventory needs none: nothing a share answers depends
   on who asks; the super-admin's approved connection is the gate.

**Identity on every query.** `app.member_id` = the resolved member, `app.role` = `members.business_role`. The server never filters rows
in Python by who is asking. The Python side only resolves names to ids **through the same views** (a name the caller cannot see is
`not_found`) and trims.

Gate words (db/004, db/005, design §3) — a gate is what the view or function already enforces; the tool only trims:

| Word | Means |
|---|---|
| `reader` | `inv_is_member_here()` — active, admitted, holding `inventory.read`: every role, Viewer up (an external member at most) |
| `sales` | `orders.write` (Sales, Buyer, admin) — customers' contact details, ship-to, payments |
| `warehouse` | the `stock.*` rights (Warehouse, Buyer, admin) — receipts' cost through `inv_sees_receipt_cost()` |
| `buyer` | `reports.read` · `catalog.write` · `sources.write` · `listings.match` · `purchasing.write` · `watches.all` (Buyer, admin) — the function names the one it tests |
| `cost` | `inv_sees_cost()` — see the wall |
| `admin` | `inv_is_admin()` / `settings.manage` · `feed.keys` · `agents.settings` · `sequences.manage` |
| `own` | the caller's own: watches (`watches.own`), notifications, tokens, dispatches they caused |
| `kernel` | the kernel's own token: `app_roles` and the five shares |

**Locations are records, not walls** (D4): every role sees every location. **A source's credential is nobody's**: `mcp_source_credentials`
carries id, kind, label, last4, rotated_at — the ciphertext is in no view, no tool, no log. **A customer's address and phone** are the
view's (`sales`, or `stock.ship`); a Viewer gets the name and the status.

### The bridge (how `source_search` reaches the connectors without the read role writing)

`A8` asks the live connectors and writes listings like a pull. The read role cannot write and the connectors are PHP (`app/sources/`).
Knowledge's pattern (DECISION 3): the records server posts to PHP on the **internal port only**:

```
POST http://127.0.0.1:{APP_INTERNAL_PORT}/internal/bridge.php
X-INV-Bridge: {unix_ts}.{hex hmac_sha256(ACTIONS_RELAY_KEY, "inv-bridge:" + unix_ts + "." + sha256_hex(body))}
Content-Type: application/json
{"op": "source_search", "caller": {"kind": "member", "member_id": 26}, "door": "mcp" | "agent", "run_id": 812 | null,
 "request_id": "…" | null, "is_eval": false, "args": {"q": "Casper Original", "size": "queen", "sources": [3, 7] | null, "limit": 20}}
```

PHP refuses with 401 unless `REMOTE_ADDR` is loopback, the timestamp is within ±30 s and the HMAC verifies (the `inv-bridge:` prefix
separates it from the actions relay); a `member` caller must be an active, admitted mirror row (403 `unknown_member`); PHP sets
`app.member_id` and applies the same gate as the Find screen's button (`require_right('orders.write')`); `source_search` is the **only op**
in v1 (any other answers 404). The path is **not** on the vhost's public allow-list. Built by slice 4 (the PHP side) and Phase 4 (the Python
side). No new env key.

## Records server: `inventory_records_mcp`

### Roles (kernel db/145)
`app_roles` — no arguments — answers `os.app-roles/1`: `{"schema", "application": "inventory", "rights": [{key, description}] (30),
"roles": [{key, name, description, capability, is_admin, rights[]}] (5: viewer, user, warehouse, buyer, admin)}` from `mcp_app_roles`
and `inv_rights` (db/004). Callers: `kernel` and anyone admitted.

### Catalog (C1–C6)
| Tool | Q | Call it when | Params | Returns | Reads | Gate · wall |
|---|---|---|---|---|---|---|
| `find_products` | C1 | What we sell by words, brand, type, size, attribute, price band, status; `q` alone resolves a product by name (the registry's `product`). Not for one variant's stock (`find`) | `q?` (≤ 120; digits = an id), `brand?` (id or name), `product_type?` (key or id), `kind?` (single, bundle), `status?` (draft, active, discontinued; default not discontinued), `attributes?` (object, `@>`), `price_min?`, `price_max?` (retail, over any variant), `size?` (a size word — products with a variant of that `size_key`), `limit`, `offset` | rows `{product_id, name, brand_id, brand, product_type_id, product_type, kind, status, options, attributes, ships_how, variant_count, primary_image_attachment_id, reorder_point, tags, updated_at, label}` | `mcp_products`, `mcp_product_variants` (the size and price filters) | reader · none |
| `get_product` | C1, C2 | One product in full: its variants with stock and prices, identifiers, bundle components, images, the sources listing it, notes, attachments | `product` (id or name) | `{product, variants: [the `product_variants` row], identifiers: [{identifier_id, variant_id, kind, value, source_id}], bundle_components: [...], images: [{image_id, variant_id, attachment_id, alt_text, is_primary}], listings: [{listing_id, source_id, source, source_role, title, matched_count, variant_count}], notes: [{note_id, member_name, body, created_at}], attachments: [{attachment_id, filename, mime_type, byte_size}]}` | `mcp_products`, `mcp_product_variants`, `mcp_variant_identifiers`, `mcp_bundle_components`, `mcp_product_images`, `mcp_listings`, `mcp_notes`, `mcp_attachments` | reader · view (`cost_price` null unless `cost`) |
| `product_variants` | C2 | The variants of a product: SKU, size, option values, barcode, MPN, dims and weight, how it ships, retail, MAP, cost (when permitted), reorder point, on hand and available | `product` (id or name), `active_only?` (true), `limit` (≤ 100) | rows of `mcp_product_variants` `{variant_id, product_id, product_name, brand, kind, sku, option_values, size_key, size_name, barcode, mpn, weight_g, length_mm, width_mm, height_mm, ships_how, retail_price, map_price, cost_price, cost_withheld, cost_updated_at, reorder_point, reorder_qty, active, qty_on_hand, qty_available}` | `mcp_product_variants` | reader · view |
| `get_variant` | C2 | One variant: the row above with its identifiers, its own stock by location, its best offer and its price history (newest 10) | `variant` (id or SKU) | `{variant, identifiers[], own: [{location_id, location, kind, on_hand, allocated, floor_model, available, sellable}], on_order, best_offer, best_lead_time_days, state, price_history: [10 rows]}` — `own`, `best_offer`, `on_order`, `state` are `inv_availability()`'s fields | `mcp_product_variants`, `mcp_variant_identifiers`, `inv_availability(variant_id)`, `inv_price_history(variant_id)` | reader · view + fn |
| `variant_by_identifier` | C3 | Which variant has this GTIN / UPC / EAN / SKU / MPN / ASIN / eBay EPID / Walmart item id / supplier SKU. Exact, after GTIN-14 normalisation. One answer or `not_found`; several (an MPN shared across sizes) → `ambiguous` with the candidates | `value` (1–64), `kind?` (gtin, upc, ean, mpn, asin, ebay_epid, walmart_item_id, supplier_sku, sku, other), `source?` (a supplier SKU belongs to a source) | `{variant_id, product_id, product, brand, sku, size_name, barcode, mpn, matched_on: {kind, value, source_id}, retail_price, qty_available, state}` | `inv_find(value)` keeping rows with `score >= 9` (an identifier or SKU match is scored 10 / 9), then `mcp_variant_identifiers` for `matched_on` (DECISION 4 — no new function; `inv_find` already normalises GTINs with `inv_gtin14()`) | reader · fn |
| `bundle_components` | C4 | What is in this bundle (a Queen set: mattress + foundation), with each component's quantity | `variant` (the bundle's variant id or SKU) | rows `{component_variant_id, component_sku, component_product, qty}` | `mcp_bundle_components` | reader · none |
| `bundle_availability` | C4 | Is every component of the bundle available, how many sets from stock, and the set's best lead time | `variant` | `inv_bundle_availability()`'s object verbatim: `{variant: {variant_id, product_id, product, sku, kind}, prices: {retail, map}, components: [{variant_id, sku, product, qty, own_available, sets_from_stock, best_lead_time_days}], sets_available, best_lead_time_days, state, as_of}` | `inv_bundle_availability(variant_id)` | reader · none (a bundle carries no cost) |
| `price_history` | C5 | How retail, MAP and cost changed on a variant, when, by whom and why; cost rows only for `cost` | `variant`, `kind?` (retail, map, cost), `since?`, `limit` (≤ 100) | rows `{changed_at, kind, old_price, new_price, changed_by, changed_by_name, reason, source_kind}` | `inv_price_history(variant_id)` | reader (cost rows: `cost`) · fn (the function drops cost rows) |
| `catalog_gaps` | C6 | Which variants have no GTIN, no cost, no retail, no image, a retail under MAP, an empty bundle — the Buyer's tidy-up list | `gap?` (no_gtin, no_cost, no_retail, no_image, retail_under_map, empty_bundle), `brand?`, `limit`, `offset` | rows `{variant_id, sku, product_name, size_name, gap}` | `inv_catalog_gaps()` (`catalog.write`) | buyer · fn (`no_cost` rows exist only for `cost`) |

### Stock (S1–S7)
| Tool | Q | Call it when | Params | Returns | Reads | Gate · wall |
|---|---|---|---|---|---|---|
| `stock_levels` | S1 | On hand, allocated, floor models and available for a variant, a product or a brand — one row per variant × location, with totals per variant. Not for "can we sell it" (`availability`) | `variant?` or `product?` or `brand?` (one), `location?`, `nonzero_only?` (true), `limit` (≤ 100), `offset` | rows `{variant_id, sku, product_name, location_id, location_name, qty_on_hand, qty_allocated, qty_floor_model, qty_available, updated_at}` + `totals: {on_hand, allocated, floor_model, available}` | `mcp_inventory_balances`, `mcp_product_variants` | reader · none |
| `stock_by_location` | S1 | What a location holds: every variant with a balance there, totals, the location's facts; `q` alone on `find_locations` resolves the location | `location` (id or name), `brand?`, `product_type?`, `limit`, `offset` | `{location: {location_id, name, kind, address, department_id, is_sellable, allow_negative, active}, rows: [as `stock_levels`], totals}` | `mcp_locations`, `mcp_inventory_balances` | reader · none |
| `stock_movements` | S2 | What moved — receipts, issues, transfers, adjustments, counts, sales, returns, reversals — for a variant, a location, a period, a reference | `variant?`, `location?`, `txn_type?[]` (receipt, issue, transfer_out, transfer_in, adjustment, count_correction, sale, return, floor_model_in, floor_model_out, reversal), `reference_kind?` + `reference_id?`, `from?`, `to?` (default the last 7 days), `limit`, `offset` | rows `{transaction_id, group_id, txn_type, affects, variant_id, sku, location_id, location_name, qty, unit_cost, counterparty_kind, counterparty_id, reason_code, reference_kind, reference_id, reverses_id, note, occurred_at, posted_at, actor_member_id, actor_name}` | `mcp_inventory_transactions` | reader (§7 says Warehouse; the view admits every reader and nulls `unit_cost` — DECISION 5: the tool does not narrow a view) · view (receipts' cost through `inv_sees_receipt_cost()`) |
| `transfers_open` | S3 | What is in transit between locations and what waits to be received; or one transfer with its lines | `transfer?` (id or number — then the lines), `from_location?`, `to_location?`, `status?[]` (draft, in_transit, received, cancelled; default draft + in_transit), `limit`, `offset` | rows `{transfer_id, number, from_location_id, from_location, to_location_id, to_location, status, shipped_at, received_at, notes, line_count}`; with `transfer`: `{transfer, lines: [{transfer_line_id, line_no, variant_id, sku, qty, qty_received}]}` | `mcp_inventory_transfers`, `mcp_inventory_transfer_lines` | reader · none |
| `receipts_open` | S3 | Goods receipts drafted and not yet posted (what is waiting at the dock), or one receipt with its lines; posted ones with `status: posted` | `receipt?` (id or number), `supplier?`, `purchase_order?`, `location?`, `status?[]` (draft, posted, cancelled; default draft), `from?`, `to?`, `limit`, `offset` | rows `{goods_receipt_id, number, supplier_id, supplier_name, purchase_order_id, purchase_order_number, location_id, location_name, status, delivery_note_ref, received_on, posted_at, posted_by}`; with `receipt`: `{receipt, lines: [{goods_receipt_line_id, line_no, purchase_order_line_id, variant_id, sku, qty, unit_cost, discrepancy_kind, discrepancy_note, putaway_location_id}]}` | `mcp_goods_receipts`, `mcp_goods_receipt_lines` | reader · view (`unit_cost` for `warehouse` or `cost`) |
| `counts` | S4 | The stock counts at a location: open and posted, how many lines differed | `location?`, `status?` (open, posted), `since?`, `limit`, `offset` | rows `{count_id, number, location_id, location_name, status, started_by, started_at, posted_by, posted_at, line_count, lines_differing, notes}` | `mcp_inventory_counts` | reader · none |
| `count_lines` | S4 | What the count found, line by line: system quantity, counted quantity, the difference, and (posted) the correction it wrote | `count` (id or number), `differing_only?` (false), `limit` (≤ 100), `offset` | `{count, rows: [{count_line_id, variant_id, sku, system_qty, counted_qty, difference, counted_by, counted_at, correction_transaction_id?}]}` — the correction from `mcp_inventory_transactions` (`reference_kind = 'count'`) | `mcp_inventory_counts`, `mcp_inventory_count_lines`, `mcp_inventory_transactions` | reader · none |
| `stock_value` | S5 | What stock is worth at cost as of a moment, by location, brand, type or variant; units always, value for `cost` | `as_of?` (default now), `by?` (location, brand, type, variant; default location) | rows `{group_id, group_name, units, value, cost_withheld}` + `total: {units, value}` | `inv_stock_value(as_of, by)` (`reports.read`) | buyer · fn |
| `sell_through` | S6 | What shipped in the last N days by variant, brand, type or salesperson: units, revenue, COGS and margin (for `cost`), on hand and weeks of cover (by variant) | `days?` (1–365, default 30), `by?` (variant, brand, type, salesperson; default variant), `limit`, `offset` | rows `{group_id, group_name, units_sold, revenue, cogs, margin_pct, cost_withheld, units_on_hand, weeks_of_cover}` | `inv_sell_through(days, by)` (`reports.read`) | buyer · fn |
| `reorder_candidates` | S7 | Which variants are at or under their reorder point (available + on order ≤ point), each with the cheapest in-stock supplier offer — the reorder run's list | `brand?`, `supplier?` (only candidates whose best offer is that supplier's), `limit`, `offset` | rows `{variant_id, sku, product_name, size_name, on_hand, allocated, available, on_order, reorder_point, reorder_qty, best_listing_variant_id, best_source_id, best_supplier_id, best_supplier_name, best_cost, cost_withheld, best_lead_time_days, best_availability}` | `inv_reorder_candidates()` (`reports.read`) | buyer · fn |

### Sources, offers and availability — the heart (A1–A14)
| Tool | Q | Call it when | Params | Returns | Reads | Gate · wall |
|---|---|---|---|---|---|---|
| `find` | A1 | **Find what we can sell** — the one search behind the Find screen and the command bar: by name, brand, SKU, GTIN, MPN, size, type, attribute, price band, in stock only, ships within N days; each variant with its own availability, its best supplier offer and the best lead time. `q` alone resolves a variant (the registry's `variant`). Not for one variant in full (`availability`) | `q?` (≤ 120), `size?` (a size word or key), `brand?` (id or name), `product_type?` (key or id), `status?`, `in_stock_only?` (false), `max_lead_days?`, `price_min?`, `price_max?`, `attributes?` (object), `limit` (≤ 100, default 25) | rows of `inv_find()` `{variant_id, product_id, product_name, brand, product_type, kind, sku, size_key, size_name, barcode, mpn, retail_price, map_price, cost_price, cost_withheld, ships_how, own_available, own_on_hand, own_floor_model, best_offer: {listing_variant_id, source_id, source, supplier, cost, price, availability, lead_time_days, ships_how, as_of, stale} | null, best_lead_time_days, state (in_stock, from_supplier, back_order, unavailable), score, label}` ranked by score | `inv_find(q, size, filters jsonb, limit)` — filters `{brand_id, product_type_id, status, price_min, price_max, attributes, in_stock_only, max_lead_days}`; a brand name is resolved through `mcp_brands` first | reader · fn |
| `availability` | A1 | **Can we sell this — now, and if not who ships it fastest and cheapest**: own stock by location (on hand, allocated, floor, available), every supplier offer ranked (in stock first, then cost, then lead time), the reference prices, our retail/MAP/cost, on order, the best lead time, the state. The tool to call before promising anything | `variant` (id or SKU) | `inv_availability()`'s object verbatim: `{variant: {...}, prices: {retail, map, cost, cost_withheld, margin_pct}, own: [...], own_available, on_order, offers: [{listing_variant_id, source_id, source, connector, supplier_id, supplier, price, compare_at_price, cost, cost_withheld, availability, qty, lead_time_days, ships_how, url, as_of, stale, removed, rank}], references: [{listing_variant_id, source_id, source, connector, price, compare_at_price, availability, url, as_of, stale, removed, rank}], best_lead_time_days, state, as_of}` + `markdown` (a bundle answers `bundle_availability`'s shape — the function does that itself) | `inv_availability(variant_id)` | reader · fn |
| `atp` | A2 | Can we promise N of it by a date, and from where — stock at a location, or a supplier's offer by its lead time; the reason in words | `variant`, `qty?` (≥ 1, default 1), `by?` (a date) | `inv_atp()`'s object: `{can_promise, from (stock, source, null), location_id, location, qty_available | source_id, source, supplier_id, supplier, listing_variant_id, cost, cost_withheld, availability, lead_time_days, ships_how, stale, by, reason}` + `markdown` | `inv_atp(variant_id, qty, when)` | reader (§7 says Sales; the function admits a reader — DECISION 5) · fn |
| `find_sources` | A3 | The sources: connector, role, supplier, health, schedule, last pull; `q` alone resolves a source by name (the registry's `source`); `templates: true` lists the source templates instead (the known stores and feeds a person can add from — DECISION 6: no separate tool) | `q?`, `connector?` (shopify, woocommerce, jsonld, feed, manual), `role?` (supplier, reference), `supplier?`, `health?` (ok, stale, failing, blocked, paused, manual, never_pulled, inactive), `include_inactive?` (false), `templates?` (false), `limit`, `offset` | rows `{source_id, name, connector, role, supplier_id, supplier_name, base_url, schedule_minutes, rate_per_second, robots_state, last_ok_at, consecutive_failures, backoff_until, paused_at, paused_reason, active, health, last_pull_at, last_status, listings_live, variants_live, variants_unmatched, label}` (`settings` and `user_agent` only for `sources.write` — the view); templates: `{template_id, key, name, connector, role, base_url, brand_hint, notes, survey_result, surveyed_at}` | `mcp_sources` joined to `inv_source_health()`; `mcp_source_templates` | reader (settings: buyer) · none |
| `get_source` | A3 | One source in full: its facts, settings (for `sources.write`), its credential's label and last four (never the value), its health, its last five pulls with their policy facts, counts of listings live / matched / unmatched | `source` (id or name) | `{source (as above + settings, user_agent), credential: {credential_id, kind, label, last4, rotated_at} | null, health: inv_source_health()'s row, pulls: [5 × source_pulls row], counts: {listings_live, variants_live, variants_matched, variants_unmatched}}` | `mcp_sources`, `mcp_source_credentials`, `inv_source_health()`, `mcp_source_pulls` | reader (settings, credential label: buyer) · none |
| `source_health` | A3 | Every source's health in one list — ok, stale, failing, blocked, paused, manual, never pulled — with the next pull due, the back-off, the last error, the counts; the morning note's "sources" heading | `health?[]`, `role?`, `limit` (≤ 100) | rows of `inv_source_health()` `{source_id, name, connector, role, supplier_name, active, health, paused_at, paused_reason, robots_state, schedule_minutes, last_pull_id, last_pull_at, last_status, last_error, last_ok_at, consecutive_failures, backoff_until, next_due_at, stale, listings_live, variants_live, variants_unmatched}` | `inv_source_health()` | reader · none |
| `source_listings` | A4 | What a source lists, with the current price and availability per variant and which are matched to ours; `q` alone resolves a listing by title across sources (the registry's `listing`) | `source?` (required unless `q`), `q?` (title ILIKE / trigram), `matched?` (true: only matched; false: only unmatched), `include_removed?` (false), `limit`, `offset` | rows `{listing_id, source_id, source_name, source_role, external_id, handle, url, title, vendor, product_type, tags, product_id, first_seen_at, last_seen_at, removed_at, variant_count, matched_count, variants: [{listing_variant_id, title, size_name, sku, barcode, mpn, price, compare_at_price, currency, cost_price, cost_withheld, availability, qty, lead_time_days, variant_id, match_kind}]}` (`raw` never — `get_listing` for `listings.match`) | `mcp_listings`, `mcp_listing_variants` | reader · view |
| `get_listing` | A4 | One listing in full: its variants with their current offers and matches (how each was made, by whom, with what confidence), the open proposals, the raw fields read (for `listings.match`), the orders sold against it | `listing` (id) or `listing_variant` (id — its listing) | `{listing, variants: [listing_variants row + match: {variant_id, sku, product, match_kind, match_confidence, matched_by, matched_by_name, matched_at}], proposals: [mcp_match_proposals rows, proposed], raw?, sold_lines: [{sales_order_id, order_number, line_id, status}]}` | `mcp_listings`, `mcp_listing_variants`, `mcp_match_proposals`, `mcp_sales_order_lines` | reader (`raw`: buyer — the view) · view |
| `offers_for_variant` | A5 | The offers for this variant across every source, current and ranked — suppliers by availability, cost and lead time; references by price; each with as-of and staleness. Not the own stock (`availability` gives both) | `variant`, `role?` (supplier, reference), `include_removed?` (false) | rows = `inv_availability(variant)->offers ∪ references` with `source_role` added, in rank order | `inv_availability(variant_id)` (DECISION 7: `inv_offers_for_variant()` is the writer's alone; the function's two arrays are the wall-safe read) | reader · fn |
| `offer_history` | A6 | **How this offer's price and availability moved** since a date — the snapshots as a series for a chart (one point per change, plus the daily heartbeat), and the summary: first/last/min/max price, last out-of-stock, longest gap | `listing_variant` (id), `since?` (default 90 days ago), `until?`, `limit` (≤ 2,000 points, default 500) | `{listing_variant_id, source, title, size_name, currency, series: [{observed_at, price, compare_at_price, cost_price, cost_withheld, availability, qty, lead_time_days, is_heartbeat, pull_id}], summary: {points, first_at, last_at, price_min, price_max, price_first, price_last, changes, last_out_of_stock_at, days_out_of_stock}}` — a silence between points means "unchanged" | `inv_offer_history(lv_id, since)` | reader · fn |
| `availability_timeline` | A6 | When did this offer go out of stock, come back, get removed — the runs of availability states with their durations (derived from the same snapshots; DECISION 8: no new function) | `listing_variant`, `since?` (default 180 days ago) | rows `{availability, from, to, days}` oldest first + `{current, last_change_at, out_of_stock_runs, days_out_of_stock}` | `inv_offer_history(lv_id, since)` collapsed in Python by consecutive `availability` | reader · none |
| `unmatched_listings` | A7 | Which listing variants are unmatched (the match queue), each with the matcher's best proposal and its evidence; the morning note's "unmatched" heading | `source?`, `with_proposal_only?` (false), `min_confidence?`, `limit`, `offset` | rows of `inv_unmatched_listings()` `{listing_variant_id, listing_id, source_id, source_name, title, variant_title, vendor, sku, barcode, mpn, size_name, price, availability, proposals, best_proposal: {proposal_id, variant_id, sku, product, confidence, evidence, proposed_by} | null, first_seen_at, last_seen_at}` | `inv_unmatched_listings(source_id)` (`listings.match`) | buyer · none |
| `match_proposals` | A7 | The proposals for a listing variant, or for a variant, or every open one: who proposed (the matcher, the Buyer agent), the evidence, the fate; `q` alone resolves one by the listing's title (the registry's `proposal` is ids only — see the resolve table) | `listing_variant?` or `variant?`, `status?` (proposed, accepted, dismissed; default proposed), `proposed_by?` (member), `limit`, `offset` | rows `{proposal_id, listing_variant_id, listing_title, source_name, variant_id, sku, product_name, confidence, evidence, proposed_by, proposed_by_name, status, decided_by, decided_at, created_at}` | `mcp_match_proposals`, `mcp_listing_variants` | reader (the view admits a reader; §7 says Buyer — DECISION 5) · none |
| `source_search` | A8 | **Ask the sources that can search live** for a query (a brand and a size) and show what came back, merged and matched; a Find with "ask the sources now". Call it when the last pull may be stale or the catalog has no match. Costs HTTP: not for every question. **It writes**: a `source_pulls` row (kind `search`, the query ≤ 120 characters), the listings and offers read (upserted like a pull — snapshots on change, the matcher's six rules on the new ones, watches evaluated) — except under an eval run (persists nothing) | `q` (2–120), `size?`, `sources?[]` (ids or names; default every active source whose connector `has_search` — `shopify`, `woocommerce` in v1 — not paused, not blocked, not backing off), `limit?` (1–20 per source, default 20) | `{query, size, asked: [{source_id, source, status (ok, no_search, timeout, blocked, failed, skipped), pull_id, listings_seen, listings_new, listings_changed, ms, error}], rows: [the matched offers as `offers_for_variant` rows + {matched: bool, variant_id, sku, product_name}, unmatched listing variants with `matched: false`], eval: bool}` | the bridge `source_search` → PHP: `inv_source_pull_start(source, 'search', member, q)`, the connector's `search(source, q, limit)`, `inv_upsert_listing()`, `inv_source_pull_finish()`, `inv_fire_watches()` — the worker's pull path, once per source | sales (`orders.write`; Warehouse and Viewer: `not_found`-worded refusal `insufficient_right`) · fn (the rows come back through `inv_availability` / `mcp_listing_variants`) · **rate and timeout**: every request goes through the one HTTP client (`app/sources/http.php`): at most the source's `rate_per_second` (default 1 a second per host, `Crawl-delay` honoured), `ETag` cached, 20 s per request; the bridge gives each source **25 s** and the whole call **60 s** (DECISION 9), sources asked **in sequence** (one request at a time per host is the policy); a 403/429/captcha marks the source blocked and answers `blocked` for it; at most **5 sources** a call (`invalid_argument` beyond) |
| `source_pulls` | A9 | The pulls of a source (or every source's), newest first: kind, status, what was seen, new, changed, removed, the HTTP count and bytes, the error, the policy facts followed | `source?`, `status?[]` (running, ok, partial, failed, blocked), `kind?` (scheduled, manual, search, probe), `since?`, `limit`, `offset` | rows `{pull_id, source_id, source_name, kind, started_at, finished_at, status, listings_seen, listings_new, listings_changed, variants_changed, listings_removed, http_requests, bytes, error, policy, query, started_by}` | `mcp_source_pulls` | reader (§7 says Buyer; the view admits a reader — DECISION 5) · none |
| `get_pull` | A9 | One pull in full: the row above, the source, and what it changed — the listing variants whose offer changed in it (their snapshots), the listings first seen and removed | `pull` (id) | `{pull, source: {source_id, name, connector, role}, changed: [{listing_variant_id, listing_title, title, size_name, price, availability, cost_price, cost_withheld}] (≤ 100), new_listings: [{listing_id, title}] (≤ 50), removed_listings: [...] (≤ 50)}` | `mcp_source_pulls`, `mcp_offer_snapshots` (`pull_id`), `mcp_listing_variants`, `mcp_listings` | reader · view |
| `price_exceptions` | A10 | Which retail prices are under MAP, which references undercut ours by more than the setting (`reference_undercut_pct`, 10 %), which costs moved more than the setting (`cost_move_pct`, 5 %) in the last 30 days; the morning note's "prices" heading | `kind?[]` (retail_under_map, reference_undercut, cost_moved), `brand?`, `limit`, `offset` | rows of `inv_price_exceptions()` `{kind, variant_id, sku, product_name, size_name, retail_price, map_price, cost_price, cost_withheld, reference_price, source_id, source_name, pct, detail}` | `inv_price_exceptions()` (`reports.read`) | buyer · fn |
| `lines_at_risk` | A11 | Which sold lines are at risk: a drop-ship whose offer is gone or declined, whose source is failing, past its expected date; a stock line with nothing on hand — with the order, the customer, the promise date and the salesperson; the morning note's first heading | `risk?[]` (declined, offer_gone, source_failing, overdue, no_stock), `salesperson?` (member; `me`), `limit`, `offset` | rows of `inv_lines_at_risk()` `{sales_order_id, order_number, line_id, line_no, variant_id, sku, product_name, customer_name, promised_on, fulfilment_kind, line_status, risk, detail, salesperson_member_id}` | `inv_lines_at_risk()` | reader (§7 says Sales — DECISION 5) · none |
| `my_watches` | A12 | What I am watching (or, for `watches.all`, what anyone or any agent is watching): kind, target, threshold, last state, when it last fired, whether it texts me | `member?` (`watches.all` only; default me), `kind?` (back_in_stock, price_below, cost_below, map_breach, lead_time_over, removed), `active_only?` (true), `variant?` / `listing_variant?` / `product?`, `limit`, `offset` | rows `{watch_id, member_id, member_name, agent_member_id, kind, variant_id, listing_variant_id, product_id, target: {sku | title, product, size_name}, threshold, text_me, last_state, fired_at, fire_count, active, note, created_at}` | `mcp_watches` (own rows, or all for `watches.all` — the view) | own (all: buyer) · none |
| `watch_events` | A12 | What fired: my watch notifications (what, when, read or not) and, for a watch naming an agent, the dispatch it caused and the agent's reply excerpt | `watch?`, `since?` (default 30 days), `unread_only?` (false), `limit`, `offset` | rows `{notification_id, watch_id, kind, title, body, record_type, record_id, created_at, read_at, dispatch: {dispatch_id, agent_name, status, reply_excerpt, answered_at} | null}` + `watches: [{watch_id, fired_at, fire_count}]` (DECISION 10: a watch's fires are its notifications — `mcp_notifications` kind `watch`, own or admin — joined to `mcp_agent_dispatches` by `watch_id`; another member's fires show only as `fired_at`/`fire_count` from `mcp_watches`) | `mcp_notifications`, `mcp_agent_dispatches`, `mcp_watches` | own (admin: all) · none |
| `supplier_items` | A13 | A supplier's price sheet: their SKU, cost, lead time, MOQ per variant, when last seen in a feed and from which source; or one variant's across suppliers | `supplier?` or `variant?` (one), `active_only?` (true), `stale_days?` (only rows not seen for N days), `limit`, `offset` | rows `{supplier_item_id, supplier_id, supplier_name, variant_id, sku, supplier_sku, cost, cost_withheld, lead_time_days, moq, active, last_seen_at, source_id, updated_at}` | `mcp_supplier_items` | reader (§7 says Buyer; the view admits a reader with cost nulled — DECISION 5) · view |
| `lead_time_actuals` | A14 | How a supplier's promised lead times compare with the actual, from the purchase-order events: lines, promised and actual averages, drift, on-time per cent — one supplier or all | `supplier?` | rows `{supplier_id, supplier_name, lines, promised_avg_days, actual_avg_days, drift_days, on_time_pct}` | `inv_lead_time_actuals(supplier_id)` (`reports.read`) | buyer · none |

### Customers and orders (O1–O7)
| Tool | Q | Call it when | Params | Returns | Reads | Gate · wall |
|---|---|---|---|---|---|---|
| `find_orders` | O1 | The orders by status, customer, salesperson, store, promised date, payment status, words (number, customer, reference); late marked; `q` alone resolves an order by number (the registry's `sales_order`) | `q?`, `status?[]` (quote, confirmed, in_fulfilment, shipped, delivered, closed, cancelled; default open = confirmed + in_fulfilment + shipped), `customer?`, `salesperson?` (member; `me`), `location?`, `payment_status?`, `promised_from?`, `promised_to?`, `ordered_from?`, `ordered_to?`, `late?`, `limit`, `offset` | rows of `mcp_sales_orders` `{sales_order_id, number, customer_id, customer_name, status, origin, salesperson_member_id, salesperson_name, location_id, location_name, ordered_on, promised_on, delivery_method, ship_to_city, ship_to_region, total, payment_status, amount_paid, balance_due, customer_reference, is_late, line_count, confirmed_at, closed_at, label}` (the ship-to name, address lines and phone only for `sales` / `stock.ship` — the view) | `mcp_sales_orders` | reader · none |
| `orders_late` | O1 | Which orders are past their promised date and still open, oldest first, with the lines not yet shipped and why (a line at risk is marked) | `salesperson?`, `location?`, `limit`, `offset` | rows as `find_orders` + `days_late`, `open_lines: [{line_id, line_no, sku, fulfilment_kind, status, risk?}]` | `mcp_sales_orders` (`is_late`), `mcp_sales_order_lines`, `inv_lines_at_risk()` | reader · none |
| `get_order` | O2 | **What is on order N**: the customer, the lines with how each is filled (stock at which location, drop-ship from which source at what lead time, backorder, pickup) and where each is (allocated, ordered, shipped, delivered), the payments, the shipments with tracking, the drop-ship purchase orders, the returns, the customer link's state (live, expiry — never the token), notes | `order` (id or number) | `{order (the view's row), customer: {customer_id, name, email?, phone?}, lines: [mcp_sales_order_lines row], payments: [mcp_order_payments row] (for `payments.record` / `reports.read`), shipments: [{shipment + lines}], dropships: [mcp_purchase_orders rows for this order], returns: [mcp_return_authorizations rows], link: {is_live, expires_at, view_count, last_used_at} | null (for `orders.send`), notes[], attachments[]}` + `markdown` | `mcp_sales_orders`, `mcp_customers`, `mcp_sales_order_lines`, `mcp_order_payments`, `mcp_shipments`, `mcp_shipment_lines`, `mcp_purchase_orders`, `mcp_return_authorizations`, `mcp_order_links`, `mcp_notes`, `mcp_attachments` | reader (contact, ship-to, payments, link: the views') · view (`offer_cost` null unless `cost`) |
| `order_timeline` | O2 | The order's story in order: quote, confirmation, payments, drop-ship POs drafted, the supplier's acknowledgments, declines and tracking, shipments, delivery, returns, close or cancellation, and its activity rows | `order`, `limit` (≤ 200) | rows of `inv_order_timeline()` `{at, kind, title, detail, member_id, member_name}` + `markdown` | `inv_order_timeline(order_id)` | reader · fn (a PO's `total` in `detail` null unless `cost`) |
| `find_customers` | O3 | A customer by name, email, phone or reference; `q` alone resolves one (the registry's `customer`). Contact details for `sales` | `q?`, `source?` (walk_in, phone, web, referral, other), `include_archived?` (false), `limit`, `offset` | rows of `mcp_customers` `{customer_id, name, legal_name, email, phone, phone_alt, billing_address, shipping_address, tax_id, terms_days, currency, income_account_id, tax_rate_id, member_id, source, email_opt_in, archived_at, order_count, created_at, label}` | `mcp_customers` | reader (contact: sales — the view) · none |
| `get_customer` | O3 | One customer: the row, their orders (open first), their returns, their notes | `customer` (id, name or email) | `{customer, orders: [find_orders rows] (≤ 50), returns: [mcp_return_authorizations rows], notes[]}` | `mcp_customers`, `mcp_sales_orders`, `mcp_return_authorizations`, `mcp_notes` | reader · none |
| `customer_orders` | O3 · **share HD3** | What a customer bought, returned, and what is open for them — by customer, or by email (a support ticket's requester); as the K7 share it answers `os.inventory-orders/1` by email alone, people-free | `customer?` (id or name) or `email?` (one), `status?[]` (default every status but quote and cancelled), `open_only?` (false), `limit` (≤ 50) | rows `{sales_order_id, number, status, ordered_on, promised_on, delivery_method, total, payment_status, balance_due, line_count, lines: [{line_no, product_name, size_name, qty, fulfilment_kind, status, shipped_at?, tracking?: {carrier, number, url}}], is_late}` + `returns: [...]`; **for the kernel: the share document (below)** | `mcp_customers`, `mcp_sales_orders`, `mcp_sales_order_lines`, `mcp_shipments`; kernel: `inv_share_customer_orders(email)` (owed) | reader (by `email`: sales — an email is a contact detail; DECISION 11) · kernel · none |
| `payments_due` | O4 | What is unpaid, what deposits are held, what is due at delivery this week — per order with the balance; or one order's payments | `order?`, `due_within_days?` (default 7 — by `promised_on`), `payment_status?[]` (default unpaid + deposit), `salesperson?`, `limit`, `offset` | rows `{sales_order_id, number, customer_name, status, promised_on, delivery_method, total, amount_paid, balance_due, payment_status, last_payment: {kind, amount, method, taken_at} | null}` + `totals: {balance_due, deposits_held}`; with `order`: `payments: [mcp_order_payments rows]` | `mcp_sales_orders`, `mcp_order_payments` (`payments.record` or `reports.read`) | sales (`payments.record`) · none |
| `fulfilment_today` | O5 | What is to be picked, packed, delivered or collected today (or a day), by location: the stock and pickup lines allocated and not shipped on orders promised by then, grouped by location and delivery method; the drop-ship lines expected today beside them | `day?` (default today), `location?`, `delivery_method?[]`, `limit` (≤ 100 orders) | `{day, groups: [{location_id, location_name, delivery_method, orders: [{sales_order_id, number, customer_name, promised_on, ship_to_city, lines: [{line_id, line_no, sku, product_name, size_name, qty, qty_allocated, qty_shipped, fulfilment_kind, status}]}]}], dropships_expected: [{purchase_order_id, number, supplier_name, sales_order_number, expected_on, tracking?}]}` | `mcp_sales_orders`, `mcp_sales_order_lines`, `mcp_purchase_order_lines` composed in Python (the function it should share with the screen is owed: `inv_fulfilment_today`) | reader (§7 says Warehouse — DECISION 5) · none |
| `shipments_open` | O6 | Which shipments are out — carrier, tracking, shipped when, not yet delivered — and the delivered ones in a period; or one order's | `order?`, `kind?` (own_delivery, parcel, ltl, dropship, pickup), `delivered?` (false = out), `from?`, `to?`, `limit`, `offset` | rows `{shipment_id, sales_order_id, order_number, customer_name, kind, carrier, tracking_number, tracking_url, shipped_at, delivered_at, shipped_by, note, lines: [{sales_order_line_id, sku, qty, serials}]}` | `mcp_shipments`, `mcp_shipment_lines`, `mcp_sales_orders` | reader · none |
| `dropships_untracked` | O6 | Which drop-ship purchase orders have lines past their expected date with no tracking — the supplier to chase, the customer waiting; the morning note's sixth heading with `purchase_orders_open` | `supplier?`, `days_past?` (default 0), `limit`, `offset` | rows `{purchase_order_id, number, supplier_id, supplier_name, sales_order_id, sales_order_number, customer_name, expected_on, days_past, lines: [{purchase_order_line_id, line_no, sku, qty_ordered, status, expected_on}]}` | `inv_purchase_orders_open()` (`untracked_past_expected`), `mcp_purchase_order_lines`, `mcp_sales_orders` | reader · fn (`total` null unless `cost`) |
| `sales_summary` | O7 | What we sold by brand, type, salesperson or variant over the last N days at retail, and (for `cost`) at margin; `by: day` or `week` is the owed function's (below) — until it lands the tool answers `invalid_argument` for those two with the words "group by day and week come with inv_sales_summary" | `days?` (default 30), `by?` (variant, brand, type, salesperson; later day, week; default brand), `limit`, `offset` | rows `{group_id, group_name, units_sold, revenue, cogs, margin_pct, cost_withheld}` + `totals` | `inv_sell_through(days, by)` (`reports.read`) — DECISION 12; owed: `inv_sales_summary(from, to, by)` keyed on orders (sold), not shipments | buyer (`reports.read`; §7 says Sales at retail — the function demands `reports.read`; a Sales caller gets `insufficient_right` until the owed function admits a reader with margin nulled) · fn |

### Purchasing and returns (D1–D6)
| Tool | Q | Call it when | Params | Returns | Reads | Gate · wall |
|---|---|---|---|---|---|---|
| `find_purchase_orders` | D1 | Purchase orders by status, kind, supplier, sales order, period, words; `q` alone resolves one by number (the registry's `purchase_order`) | `q?`, `status?[]` (draft, sent, acknowledged, partial, received, closed, closed_short, cancelled; default open = draft + sent + acknowledged + partial), `kind?` (stock, dropship), `supplier?`, `sales_order?`, `ordered_from?`, `ordered_to?`, `expected_before?`, `limit`, `offset` | rows of `mcp_purchase_orders` `{purchase_order_id, number, supplier_id, supplier_name, kind, sales_order_id, sales_order_number, status, ship_to_kind, location_id, ship_to_city, ship_to_region, ordered_on, expected_on, supplier_order_ref, sent_via, sent_at, acknowledged_at, subtotal, shipping_cost, total, cost_withheld, line_count, closed_at, cancelled_at, label}` (`ship_to_name`, `internal_notes` for `purchasing.write` — the view) | `mcp_purchase_orders` | reader · view |
| `purchase_orders_open` | D1 | Which purchase orders are open — drafted, sent, awaiting acknowledgment past N days (`ack_days`), acknowledged, partially received; which are past their expected date; drop-ships untracked past expected | `awaiting_ack?`, `overdue?`, `kind?`, `supplier?`, `limit`, `offset` | rows of `inv_purchase_orders_open()` `{purchase_order_id, number, kind, status, supplier_id, supplier_name, sales_order_id, sales_order_number, ordered_on, sent_at, expected_on, total, cost_withheld, lines, lines_open, lines_shipped, lines_received, days_waiting, awaiting_ack, overdue, untracked_past_expected}` | `inv_purchase_orders_open()` | reader (§7 says Buyer — DECISION 5) · fn |
| `get_purchase_order` | D2 | One purchase order in full: the lines with the offer each was ordered against, what the supplier said (acknowledged, declined, tracking — the events), what is received (the receipts), the ship-to (a location, or the customer's city and region; the full address for `purchasing.write`), the supplier link's state (never the token), notes | `purchase_order` (id or number) | `{purchase_order, supplier: {supplier_id, name, order_method, order_email, lead_time_days}, lines: [mcp_purchase_order_lines row + offer: {listing_variant_id, source_name, price, availability, lead_time_days}], events: [mcp_purchase_order_events rows], receipts: [mcp_goods_receipts rows], link: {is_live, expires_at, view_count} | null (for `purchasing.write`), notes[], attachments[]}` + `markdown` | `mcp_purchase_orders`, `mcp_suppliers`, `mcp_purchase_order_lines`, `mcp_listing_variants`, `mcp_purchase_order_events`, `mcp_goods_receipts`, `mcp_supplier_links`, `mcp_notes`, `mcp_attachments` | reader · view |
| `purchase_order_events` | D2 | What the supplier said and when — acknowledgments with their reference and expected date, declines with reasons, tracking per line — by the door (`portal`) or by hand; one PO's or every one's since a date | `purchase_order?`, `kind?[]` (acknowledge, decline, tracking, note), `source?` (portal, web, agent), `since?`, `limit`, `offset` | rows `{event_id, purchase_order_id, purchase_order_number, purchase_order_line_id, line_no, sku, kind, source, supplier_order_ref, expected_on, carrier, tracking_number, shipped_at, reason, note, member_id, member_name, created_at}` | `mcp_purchase_order_events`, `mcp_purchase_order_lines` | reader · none |
| `supplier_open_orders` | D3 | What is on order from a supplier and their open balance of goods: every open PO with its lines ordered − received, the units and (for `cost`) the value outstanding | `supplier` (id or name), `kind?`, `limit`, `offset` | `{supplier: {supplier_id, name, lead_time_days, dropships, terms}, rows: [purchase_orders_open row + lines: [{purchase_order_line_id, sku, qty_ordered, qty_received, outstanding, unit_cost, cost_withheld, status}]], totals: {units_outstanding, value_outstanding, cost_withheld}}` | `mcp_suppliers`, `inv_purchase_orders_open()`, `mcp_purchase_order_lines` | reader · fn + view |
| `order_dropships` | D4 | Which drop-ships are placed for a customer's order and where each is: the PO per supplier, its status, the supplier's acknowledgment, tracking, expected and delivered dates, per line | `order` (id or number) | rows `{purchase_order_id, number, supplier_name, status, sent_at, acknowledged_at, expected_on, supplier_order_ref, lines: [{purchase_order_line_id, sales_order_line_id, line_no, sku, qty_ordered, status, expected_on, tracking_carrier, tracking_number, shipped_at}]}` | `mcp_purchase_orders` (`sales_order_id`), `mcp_purchase_order_lines` | reader · view |
| `find_returns` | D5 | Returns by status, order, customer, period; `q` alone resolves one by number (the registry's `return`) | `q?`, `status?[]` (requested, approved, received, closed, denied; default open = requested + approved + received), `order?`, `customer?`, `from?`, `to?`, `limit`, `offset` | rows of `mcp_return_authorizations` `{return_id, number, sales_order_id, order_number, customer_id, customer_name, status, method, scheduled_on, location_id, refund_amount, restocking_fee, requested_by, approved_at, received_at, closed_at, denied_at, deny_reason, created_at, label}` | `mcp_return_authorizations` | reader · none |
| `get_return` | D5 | One return: the lines with reason, disposition, quantity received, condition; the order; what was refunded | `return` (id or number) | `{return, order: {sales_order_id, number, customer_name}, lines: [{return_line_id, sales_order_line_id, variant_id, sku, qty, reason_code, disposition, location_id, condition_note, qty_received}], notes[], attachments[]}` | `mcp_return_authorizations`, `mcp_return_lines`, `mcp_sales_orders`, `mcp_notes`, `mcp_attachments` | reader · none |
| `returns_open` | D5 | Which returns are requested, approved, received and awaiting disposition or close — with days open and the dispositions; the morning note's seventh heading | `status?[]`, `limit`, `offset` | rows of `inv_returns_open()` `{return_id, number, status, sales_order_id, order_number, customer_id, customer_name, method, scheduled_on, lines, dispositions, refund_amount, created_at, days_open}` | `inv_returns_open()` | reader · none |
| `morning_note` | D6 | **The morning note — everything the Buyer agent found (or would find) today**: the seven headings with their counts and their first rows, and the proposals it recorded for the day with their fate. The composite the Home screen shows | `date?` (default today), `rows_per_heading?` (1–25, default 5) | `{date, headings: {lines_at_risk: {count, rows}, reorder: {count, rows}, prices: {count, rows}, unmatched: {count, rows}, sources: {count, rows (health ≠ ok)}, purchase_orders: {awaiting_ack: {count, rows}, overdue: {count, rows}, untracked: {count, rows}}, returns: {count, rows}}, proposals: [mcp_buyer_proposals rows for the date], drafted: [{kind, drafted_record_type, drafted_record_id, status}]}` + `markdown` | `inv_lines_at_risk()`, `inv_reorder_candidates()`, `inv_price_exceptions()`, `inv_unmatched_listings()`, `inv_source_health()`, `inv_purchase_orders_open()`, `inv_returns_open()`, `mcp_buyer_proposals` | buyer (`reports.read` + `listings.match`) · fn |
| `buyer_proposals` | D6 | What the Buyer agent proposed — reorders with drafted POs, matches, prices, lines at risk, sources, overdue POs, returns — and what a person accepted or dismissed; by day, kind, status | `date?`, `from?`, `to?`, `kind?[]` (reorder, match, price, at_risk, source, po_overdue, return), `status?` (proposed, accepted, dismissed), `limit`, `offset` | rows `{proposal_id, kind, subject_type, subject_id, title, detail, drafted_record_type, drafted_record_id, status, proposed_by, note_date, decided_by, decided_at, created_at}` | `mcp_buyer_proposals` (the view strips `cost`, `best_cost`, `margin` from `detail` for a caller without `cost`) | buyer (`reports.read` / `agents.settings`; the proposer sees its own) · view |

### Feed and agents (F1, G1)
| Tool | Q | Call it when | Params | Returns | Reads | Gate · wall |
|---|---|---|---|---|---|---|
| `feed_keys` | F1 | Which availability-feed keys exist, for whom (website, another installation, a partner with its price list), their limits, last use, today's count, live or revoked; the price lists with `with_price_lists`. **Never a key's value or hash** | `q?` (label), `consumer_kind?`, `live?` (default true), `with_price_lists?` (false), `limit`, `offset` | rows of `mcp_feed_keys` `{key_id, member_id, label, consumer_kind, price_list_id, price_list_name, rate_per_minute, rate_per_day, rotated_from, last_used_at, expires_at, revoked_at, revoked_by, created_at, is_live, calls_today}` (+ `price_lists: [{price_list_id, name, percent_off_retail, active}]`) | `mcp_feed_keys`, `mcp_price_lists` | admin (`feed.keys`) · none |
| `key_usage` | F1 | How much each key read this month (or a period): calls and refusals per day, per key; or one key's minute buckets for the last hour | `key?`, `from?` (default the 1st of this month), `to?`, `bucket?` (day, minute; default day), `limit` (≤ 100) | rows `{key_id, label, bucket_start, calls, refused}` + `totals: [{key_id, label, calls, refused}]` | `mcp_key_usage`, `mcp_feed_keys` | admin (`feed.keys`) · none |
| `agents_here` | G1 | Which agents hold a role here, what role, what each last did (its last activity row), its pending and failed dispatches, its proposals today | `limit` | rows `{member_id, display_name, effective_roles, job_title, last_action: {action, occurred_at, entity_type, entity_id} | null (the activity server's domain — DECISION 13: `agents_here` reads `mcp_agent_dispatches` and `mcp_buyer_proposals` for recency; "what it last did" in full is `actor_timeline`), dispatches_pending, dispatches_failed, proposals_today}` | `mcp_members` (`is_agent`), `mcp_agent_dispatches`, `mcp_buyer_proposals` | reader (§7 says Buyer; the counts come from views gated `reports.read` / `agents.settings` — a Viewer gets names and roles with nulls) · none |
| `agent_dispatches` | G1 | Which dispatches to agents are pending, answered, refused, failed, awaiting approval — a watch that named an agent, an ask, a duty proposal — with the asker, the run, the reply excerpt | `status?[]` (sent, answered, refused, failed, awaiting_approval), `agent?` (member), `kind?` (watch, ask, duty_proposal), `watch?`, `since?`, `limit`, `offset` | rows `{dispatch_id, record_type, record_id, agent_member_id, agent_name, kind, via, acting_member_id, run_id, request_id, status, reply_excerpt (≤ 200), detail, attempts, created_at, answered_at, watch_id, listing_variant_id}` | `mcp_agent_dispatches` (own as agent or asker; all for `agents.settings` / `reports.read` — the view) | own (all: buyer) · none |

### People, places, parties, vocabulary, mine (the registry's resolvers; views no question names)
| Tool | Call it when | Params | Returns | Reads | Gate · wall |
|---|---|---|---|---|---|
| `find_members` | A person or an agent by name — a salesperson, the Buyer, an agent to name on a watch; `q` alone resolves one (the registry's `member`, `salesperson`, `agent`, `owner`). The contract's name | `q?`, `kind?` (human, agent), `department?`, `limit`, `offset` | rows `{member_id, display_name, member_kind, is_agent, is_external, business_role, roles, effective_roles, job_title, department_ids}` (`email` only one's own or the admin's — the view) | `mcp_members` | reader · none |
| `find_departments` | A department by name — the one that runs a location; `q` alone resolves one (the registry's `department`) | `q?`, `include_archived?` (false), `limit` | rows `{department_id, name, description, parent_id, manager_member_id, is_system, system_key, archived_at}` | `mcp_departments` | reader · none |
| `find_locations` | The locations by name or kind — sellable or not, which department runs it, the kernel's site when known; `q` alone resolves one (the registry's `location`, `from_location`, `to_location`) | `q?`, `kind?` (warehouse, showroom, store, in_transit, returns, offsite), `sellable_only?` (false), `include_inactive?` (false), `limit` | rows `{location_id, name, kind, address, department_id, kernel_location_id, is_sellable, allow_negative, active, units_on_hand}` | `mcp_locations`, `mcp_inventory_balances` (the units) | reader · none |
| `find_suppliers` | A supplier by name, with contact, terms, whether they drop-ship, lead time, how they take orders; `q` alone resolves one (the registry's `supplier`) | `q?`, `dropships?`, `include_inactive?` (false), `limit`, `offset` | rows `{supplier_id, name, kind, contact_name, email, phone, address, website, terms, dropships, lead_time_days, order_method, order_email, portal_url, min_order, active, notes, sources: [{source_id, name, connector}], items_count}` (`account_number` for `purchasing.write` — the view) | `mcp_suppliers`, `mcp_sources`, `mcp_supplier_items` | reader · none |
| `find_brands` | A brand by name, its website, its own dealer program (a supplier) when it has one; `q` alone resolves one (the registry's `brand` — DECISION 14: `product_create` names a brand; product types are a seeded key list, passed as the key and never resolved) | `q?`, `include_inactive?` (false), `limit` | rows `{brand_id, name, website, supplier_id, supplier_name, active, product_count}` | `mcp_brands`, `mcp_products` | reader · none |
| `find_listing_variants` | A listing variant by title, SKU, GTIN or MPN across every source — the sellable unit an action names (`listing_match`, `watch_set` on a listing variant, `order_line_fulfilment_set` with an offer); `q` alone resolves one (the registry's `listing_variant`); a GTIN or SKU is exact, words are trigram | `q?`, `source?`, `matched?`, `include_removed?` (false), `limit`, `offset` | rows of `mcp_listing_variants` `{listing_variant_id, listing_id, source_id, source_name, source_role, listing_title, title, size_name, sku, barcode, mpn, variant_id, match_kind, price, compare_at_price, currency, cost_price, cost_withheld, availability, qty, lead_time_days, ships_how, url, last_seen_at, removed_at, label}` (`label` = "Malouf · Zinus 12" Green Tea · Queen · $312 · in stock") | `mcp_listing_variants` | reader · view |
| `get_settings` | The business's vocabulary and rules an agent needs before it writes: currency, units, the sizes with their synonyms (the `size_key`s), the attribute keys and kinds, the product types, brands count, reason codes, tax rates, the reorder defaults, the thresholds (`cost_move_pct`, `reference_undercut_pct`, `ack_days`), link lifetimes, the feed's limits, `sales_sees_cost`, `supplier_sees_phone`, the Buyer; the crawl policy and user-agent for `sources.write`; the document sequences for `sequences.manage` | `part?` (settings, sizes, attributes, product_types, reason_codes, tax_rates, sequences; default settings + sizes + attributes + product_types) | `{settings: mcp_settings row (minus the parts), sizes[], attribute_keys[], product_types: [{product_type_id, key, name}], reason_codes: [{reason_code_id, code, name, applies_to, affects_qty}], tax_rates: [{tax_rate_id, name, rate, is_default}], sequences?: [{kind, prefix, next_value, padding}]}` | `mcp_settings`, `mcp_product_types`, `mcp_reason_codes`, `mcp_tax_rates`, `mcp_document_sequences` | reader (crawl user-agent: buyer; sequences: admin — the views) · none |
| `my_notifications` | The bell: my notices (a watch fired, a line at risk, a pull failed, a supplier's acknowledgment or tracking, a return, the morning note, a mention), unread first, and my preferences (email, text, which kinds) | `unread_only?` (false), `kind?[]`, `limit`, `offset` | `{prefs: {email_enabled, text_enabled, kinds, text_kinds}, rows: [{notification_id, kind, record_type, record_id, title, body, read_at, created_at}]}` | `mcp_notifications`, `mcp_notification_prefs` | own (admin: all) · none |
| `my_tokens` | My own MCP tokens: label, scope, last use, expiry, revoked. **Never a value or a hash** | — | rows of `mcp_access_tokens_mine` | `mcp_access_tokens_mine` | own · none |

### The long tail
`records_search` — the contract's guarded search: one read-only `SELECT` / `WITH … SELECT` over the `mcp_*` views as the asking member
(`db.run_search`): one statement, no `;`, the keyword deny-list, `SET LOCAL statement_timeout = '5s'`, wrapped `SELECT * FROM (…) _q
LIMIT 200`, under `inventory_records_ro`. Its docstring **lists the 61 readable views by name** (db/004's `mcp_app_roles` and db/015's
61 but `mcp_activity_log`) and the db/014 functions granted to the read role. `SEARCH_DENY` (db.py) keeps `set_config`,
`current_setting`, `mcp_admit_agent`, `mcp_resolve_token`, `inv_secure_link_`, `inv_feed_key_`, `source_credentials`,
`next_document_number`, `pg_`, `dblink`, `lo_import`, `lo_export` and **adds `inv_share_`** (the five share functions carry cost
unnulled: a person reaches them never, the kernel's token alone — DECISION 15) and `inv_resolve_feed_key`, `inv_rate_ok`
(they write `last_used_at` / `key_usage`; the read role has no grant, the name is denied regardless). Params: `sql` (≤ 4,000), `limit`
(≤ 200). Gate: the views' own.

## The shares — for the kernel's token (K7, design §8, `maludb-os.json` `shares[]`)

Every share answers a versioned, **people-free** document: `{"schema": "os.inventory-<name>/1", "generated_at", "application":
"inventory", "currency", …}` — no member id, no salesperson, no agent, no customer's address or phone, no source's name where the
design forbids it. The kernel sends the arguments flat; the middleware wraps them. Unscoped (`"scoped": false`): no `scope_id`. The
kernel refuses an answer over **256 kB** and waits **8 s** per post — every share is paged or bounded to stay well under both. A share
called by a person or an agent (the same tool name, their own token) answers the **same document** through the gated read (the
function's or the view's wall) — `sales_closed`, `purchases_received`, `stock_valuation` need `reports.read` (Buyer) since they carry
cost; `availability_index` any reader; `customer_orders` as above. **Logged** `share.read` on every kernel call through db/017's
`inv_log_share_read(p_tool, p_consumer, p_count, p_request_id)` (SECURITY DEFINER, granted to the read role — reconciled 2026-10-05 to the row as
built): `source = 'mcp'`, `actor_member_id` NULL, `entity_type = 'share'`, `after = {tool, consumer: "<the consumer when the kernel sends it, else
null>", count, request_id}` — the request id lives in the payload only; never the rows.

| Share | For | Arguments (flat) | Function (owed — below) |
|---|---|---|---|
| `sales_closed` | the General Ledger (G2): the sales journal | `from` (date, req), `to` (date, req; ≤ 92 days), `offset?` (0), `limit?` (≤ 500, default 200) | `inv_share_sales_closed(from, to, offset, limit)` |
| `purchases_received` | the General Ledger (G2): bills per supplier | `from`, `to`, `offset?`, `limit?` | `inv_share_purchases_received(from, to, offset, limit)` |
| `stock_valuation` | the General Ledger (G2): the inventory asset at close | `as_of` (date, req), `by?` (location, brand; default location) | `inv_share_stock_valuation(as_of, by)` |
| `availability_index` | any sibling (Help Desk's "is it in stock", Spaces' expert) | `q?` or `gtin?` or `sku?` (one), `size?`, `limit?` (≤ 100, default 25) | `inv_share_availability_index(query jsonb, limit)` |
| `customer_orders` | Help Desk (HD3): a ticket's requester | `email` (req), `open_only?` (true), `limit?` (≤ 50) | `inv_share_customer_orders(email, open_only, limit)` |

**`os.inventory-sales/1`** (`sales_closed`): orders **closed** (`status = 'closed'`, `closed_at` in the period, by the business's timezone)
— the ledger's journal entries; cancelled and quotes never.
```json
{"schema": "os.inventory-sales/1", "generated_at": "…", "application": "inventory", "currency": "USD",
 "period": {"from": "2026-09-01", "to": "2026-09-30"}, "count": 2, "offset": 0, "limit": 200, "truncated": false,
 "orders": [{"sales_order_id": 412, "number": "SO-000412", "ordered_on": "2026-09-03", "closed_at": "2026-09-12T18:02:00+00:00",
   "customer": {"customer_id": 88, "name": "Alvarez, Maria", "income_account_id": 4010 | null, "tax_id": null, "ledger_ref": null},
   "location": {"location_id": 2, "name": "Main Street store"}, "delivery_method": "ltl",
   "lines": [{"line_no": 1, "sku": "CSP-ORIG-Q", "product_name": "Casper Original", "size": "Queen", "qty": 1, "unit_price": 1295.00, "discount": 0.00,
              "line_total": 1295.00, "fulfilment_kind": "stock", "qty_returned": 0}],
   "subtotal": 1295.00, "discount_total": 0.00, "tax": {"tax_rate_id": 1, "name": "Cook County 10.25%", "rate": 10.25, "amount": 132.74},
   "shipping_charge": 99.00, "total": 1526.74,
   "payments": [{"kind": "deposit", "method": "card", "amount": 500.00, "taken_at": "2026-09-03", "reference": "…"},
                {"kind": "balance", "method": "card", "amount": 1026.74, "taken_at": "2026-09-12", "reference": "…"}],
   "payments_by_method": {"card": 1526.74}, "refunds": 0.00, "amount_paid": 1526.74, "balance_due": 0.00,
   "cogs": 742.00 | null, "returns": [{"return_id": 9, "number": "RA-000009", "refund_amount": 0.00, "closed_at": "…"}]}],
 "totals": {"subtotal": …, "discount_total": …, "tax": …, "shipping_charge": …, "total": …, "payments_by_method": {…}, "refunds": …, "cogs": …}}
```
No salesperson, no ship-to beyond the city (none at all — the ledger does not need it), no customer email or phone; `ledger_ref` is reserved
(null until the ledger writes nothing back — it never does; the ledger matches by `customer.name` or `income_account_id`). `cogs` = Σ
`qty × COALESCE(offer_cost, cost_price)` over the lines — the one cost figure, for the ledger's COGS entry (D10: no invoice document).

**`os.inventory-purchases/1`** (`purchases_received`): goods receipts **posted** in the period (stock) and drop-ship PO lines **delivered**
(`status = 'received'`, their `shipped_at`/the order's delivery in the period), at cost, by supplier — the ledger's bills.
```json
{"schema": "os.inventory-purchases/1", "generated_at", "application": "inventory", "currency", "period": {"from", "to"}, "count", "offset", "limit", "truncated",
 "suppliers": [{"supplier_id": 5, "name": "Malouf", "account_number": "D-4471" | null, "terms": "Net 30",
   "receipts": [{"goods_receipt_id": 31, "number": "GR-000031", "purchase_order_id": 77, "purchase_order_number": "PO-000077", "supplier_order_ref": "M-88213",
     "received_on": "2026-09-14", "posted_at": "…", "location": {"location_id": 1, "name": "Warehouse"}, "delivery_note_ref": "…",
     "lines": [{"line_no": 1, "sku": "ZNS-GT12-Q", "supplier_sku": "GT12Q", "product_name": "Zinus 12\" Green Tea", "size": "Queen", "qty": 4, "unit_cost": 189.00, "line_cost": 756.00,
                "discrepancy_kind": null}],
     "subtotal": 756.00}],
   "dropships": [{"purchase_order_id": 80, "number": "PO-000080", "kind": "dropship", "sales_order_number": "SO-000412", "supplier_order_ref": "…", "sent_at": "…",
     "lines": [{"line_no": 1, "sku": "…", "supplier_sku": "…", "qty": 1, "unit_cost": 312.00, "line_cost": 312.00, "shipped_at": "…", "delivered_at": "…"}],
     "shipping_cost": 0.00, "subtotal": 312.00, "total": 312.00}],
   "total": 1068.00}],
 "totals": {"receipts": …, "dropships": …, "total": …}}
```
A drop-ship's ship-to (the customer's address) is **never** here; the sales order number is, so the ledger can tie the bill to the sale.

**`os.inventory-valuation/1`** (`stock_valuation`): stock at the variant's standard cost as of a moment (the ledger summed to it), by
location or brand — the period close.
```json
{"schema": "os.inventory-valuation/1", "generated_at", "application": "inventory", "currency", "as_of": "2026-09-30", "by": "location",
 "rows": [{"group_id": 1, "group_name": "Warehouse", "units": 148, "value": 31240.00, "variants": 37}],
 "totals": {"units": 203, "value": 44102.00}, "cost_basis": "standard (product_variants.cost_price; cost_source = last_receipt)"}
```

**`os.inventory-availability/1`** (`availability_index`): per variant, our retail, own availability **state**, the best lead time and how
it ships — the feed's answer **without quantities, without a partner price, without cost, without a source's name**.
```json
{"schema": "os.inventory-availability/1", "generated_at", "application": "inventory", "currency",
 "query": {"q": "casper original", "size": "queen"}, "count": 1,
 "rows": [{"variant_id": 210, "sku": "CSP-ORIG-Q", "gtin": "00812345000123", "name": "Casper Casper Original", "brand": "Casper", "product_type": "Mattress", "size": "Queen",
           "retail_price": 1295.00, "map_price": 1295.00, "availability": "in_stock" | "back_order" | "out_of_stock", "lead_time_days": 0, "ships_how": "ltl", "as_of": "…"}]}
```
`availability` is the feed's three states (`inv_feed_answer`'s rule: own sellable stock → `in_stock`; a best lead time → `back_order`;
else `out_of_stock`), so a sibling and the website agree.

**`os.inventory-orders/1`** (`customer_orders`): a customer's orders by the **customer's email** (exact, case-insensitive, `customers.email`)
— for a support ticket. No address, no phone, no cost, no source, no supplier name (a drop-ship line reads "ships from our supplier"), no
salesperson.
```json
{"schema": "os.inventory-orders/1", "generated_at", "application": "inventory", "currency", "email": "maria@example.com", "found": true,
 "customer": {"customer_id": 88, "name": "Alvarez, Maria"}, "count": 1,
 "orders": [{"sales_order_id": 412, "number": "SO-000412", "status": "in_fulfilment", "ordered_on": "…", "promised_on": "2026-09-12", "delivery_method": "ltl", "is_late": false,
   "total": 1526.74, "payment_status": "deposit", "balance_due": 1026.74,
   "lines": [{"line_no": 1, "product_name": "Casper Original", "size": "Queen", "qty": 1, "fulfilment": "from stock" | "ships from our supplier" | "backordered" | "pickup",
              "status": "allocated", "expected_on": "2026-09-12" | null, "shipment": {"carrier": "…", "tracking_number": "…", "tracking_url": "…", "shipped_at": "…", "delivered_at": null} | null}],
   "returns": [{"number": "RA-000009", "status": "approved", "scheduled_on": "…"}],
   "order_page_live": true}]}
```
`found: false` with `orders: []` when no customer has that email — the same words as a customer with no orders (`found: true, count: 0`)
are **not** used: the ticket needs to know whether we know them. The order page's token is never here (`order_page_live` only).

## The availability feed's document (`GET /api/v1/availability`, bearer = a feed key; design §4)

Not an MCP tool. The PHP endpoint resolves the key (`inv_resolve_feed_key(sha256)`), counts the call (`inv_rate_ok(key_id)`: 60 a minute,
10,000 a day by default; 429 `rate_limited` with `Retry-After`, a refused call never charged to the day), then calls **`inv_feed_answer(key_id,
query jsonb)`** as the writer with the key's minter as the acting member, and wraps its answer:
```json
{"schema": "os.inventory-feed/1", "generated_at": "…", "application": "inventory", "currency": "USD",
 "query": {"gtin": "…"} | {"sku": "…"} | {"q": "…(≤ 120)", "size": "…"}, "count": 1, "as_of": "…",
 "results": [{"sku": "CSP-ORIG-Q", "gtin": "00812345000123", "name": "Casper Casper Original", "size": "Queen", "retail_price": 1295.00, "currency": "USD",
              "partner_price": 1036.00 | null, "availability": "in_stock" | "back_order" | "out_of_stock", "quantity": 2 | null, "lead_time_days": 0, "ships_how": "ltl"}]}
```
`partner_price` only for a `partner` key with a price list (`retail × (1 − percent_off_retail/100)`); `quantity` only when
`feed_shows_quantity` is on and the state is `in_stock`; **never cost, never a source's name**; at most 25 results. `?gtin=` or `?sku=` is
exact; `?q=` (+ `&size=`) runs `inv_find(q, size, '{}', 25)`. Errors share one body `{"error": {"code", "message"}}`: 401 `unauthorized`
(one body for no key, a wrong key, a revoked or expired key, an `mcp_` token, a minter no longer admitted), 422 `invalid` (no `gtin`,
`sku` or `q`; `q` over 120), 429 `rate_limited` (`limit: "minute" | "day"`, `retry_after`). Logged `feed.read` (source `feed`,
`token_id`, `after = {key_label, count, query_kind, query_excerpt ≤ 120}`), `feed.rate_limited` (`limit_hit`, `key_label`). CORS from
`API_CORS_ORIGINS` only, never `Allow-Credentials`. (DECISION 16: the schema id `os.inventory-feed/1`; the design names the document
only by its fields.)

## Activity server: `inventory_activity_mcp`

One view, `mcp_activity_log` (every row about a record anyone here may see — everything but `feed_key`, `source_credential`, `settings`,
`inv_settings`, `mcp_access_token` entities —, one's own rows, the admin all), plus names from `mcp_members`, `mcp_departments`,
`mcp_products`, `mcp_product_variants`, `mcp_sources`, `mcp_sales_orders`, `mcp_purchase_orders`, `mcp_locations`. Every row:
`{activity_id, occurred_at, actor_member_id, actor_name, actor_is_agent, source, action, screen, route, entity_type, entity_id, before,
after, request_id, agent_run_id, department_id, location_id, source_id, sales_order_id, purchase_order_id, token_id}` with the names of
the audit keys beside them (`source_name`, `sales_order_number`, `purchase_order_number`, `location_name`).

| Tool | Q | Call it when | Params | Returns | Reads | Gate |
|---|---|---|---|---|---|---|
| `record_history` | ACT1 | Who did what to a record, in order: a product, a variant (its price sets and identifiers), a location, a receipt, a transfer, a count, a supplier, a source (its pulls, probes, pauses, credential sets — never a value), a listing (matches, proposals), a watch, a customer, an order (every line, payment, shipment, the customer's views), a purchase order (send, the supplier's acknowledgments, declines, tracking by the door, receipts), a return, a feed key (admin), a dispatch. **Not** an offer's history (`offer_history`) nor a price's (`price_history`) | `entity_type` (product, product_variant, location, goods_receipt, inventory_transfer, inventory_adjustment, inventory_count, supplier, source, listing, listing_variant, match_proposal, watch, customer, sales_order, purchase_order, return_authorization, feed_key, agent_dispatch, buyer_proposal, member) + `entity_id`, **or** one of `sales_order_id` / `purchase_order_id` / `source_id` (everything that touched it — the audit keys), `since?`, `order?` (asc default), `limit` (≤ 200, default 100) | rows, oldest first | `mcp_activity_log` | the view (the record visible to the caller) |
| `actor_timeline` | ACT2 | What a person or an agent did here since a time — an agent's pulls, proposals and drafts this week; a salesperson's orders; the Buyer's sends | `member` (id or name; `me`), `since?` (default 7 days), `until?`, `action?` (a prefix: `order.`, `source.`), `source?` (web, agent, cron, portal, feed, mcp, application), `limit`, `offset` | rows, newest first | `mcp_activity_log`, `mcp_members` | own trail: anyone; an agent's trail: any reader; another person's: `reports.read` or the admin (DECISION 17 — Spaces' rule in Inventory's words) |
| `recent_activity` | ACT3 | What changed since I last looked — orders, stock, sources, purchasing — across what I may see; grouped by family when asked | `since` (req), `family?[]` (catalog, stock, sources, listings, offers, orders, purchasing, returns, feed, agents, admin — a prefix set on `action`), `exclude_mine?` (true), `exclude_screen_views?` (true), `group?` (false: by family with counts), `limit`, `offset` | rows newest first, or `groups: [{family, count, latest_at, rows (≤ 10)}]` | `mcp_activity_log` | the view |
| `who_touched` | ACT1, ACT2 | Everyone who acted on an order, a purchase order, a source, a product or a location in a period, with counts and first/last time — the siblings' tool | `sales_order?` / `purchase_order?` / `source?` / `product?` / `location?` (one), `since?`, `until?`, `limit` | rows `{actor_member_id, actor_name, actor_is_agent, actions, first_at, last_at, by_action: {action: count}}` | `mcp_activity_log`, `mcp_members` | the view |
| `source_incidents` | ACT4 | When did source X last fail, how often, why — the `source.pull_fail`, `pull_blocked`, `pause`, `resume`, `probe` rows with their errors and policy facts, per source, with counts per day; or every source's incidents since a time (the Buyer's "source reliability" report) | `source?`, `since?` (default 30 days), `kind?[]` (pull_fail, pull_blocked, pause, resume, probe), `limit`, `offset` | rows + `summary: [{source_id, source_name, failures, blocks, pauses, last_failure_at, last_error}]` | `mcp_activity_log` (`action LIKE 'source.%'`, `source_id`), `mcp_sources` | reader (§7 says Buyer; the view admits a reader and an error message is not a secret — DECISION 5) |
| `share_reads` | ACT4 | What sibling applications read of ours through the kernel — which tool, how often, how much, when (the `share.read` rows as db/017's `inv_log_share_read()` writes them — reconciled 2026-10-05: `after->>'consumer'`, `after->>'tool'`, `(after->>'count')::int`, `after->>'request_id'`) | `since?` (default 30 days), `consumer?` (the sibling's catalog key), `tool?`, `group?` (true: by consumer and tool) | grouped `{consumer, tool, calls, count, last_at}` or rows `{occurred_at, consumer, tool, count, request_id}` | `mcp_activity_log` (`action = 'share.read'`, `entity_type = 'share'`) | admin (`settings.manage`; `reports.read` sees the counts — DECISION 18) |
| `activity_search` | ACT5 | Search the activity for a phrase — in actions, entity types, and the names, numbers, SKUs, labels and titles in `after` (`after->>'name'`, `'number'`, `'sku'`, `'title'`, `'label'`, `'key_label'`, `'query_excerpt'`, `'error'`); **or**, for the long tail, one guarded `SELECT` over `mcp_activity_log` and the name views (`sql`: the contract's guarded search — `db.run_search`, 5 s, 200 rows, the same deny-list) — DECISION 19: one tool, two modes, seven activity tools as the design counts | `q?` (2–200) **or** `sql?` (one of them), `since?`, `family?`, `limit` | rows | `mcp_activity_log` + the name views | the view |

## What the log must carry for these tools (the manifest's log-payload rules; design §6 "Activity memory", CLAUDE.md)

Every row: `actor_member_id` (null for the feed, the portal doors and the worker's own passes), `source` (`web` the UI · `agent` under a run
token · `assistant` the kernel's assistant token · `mcp` a person's MCP token (`source_search`) · `cron` the worker · `portal` the two doors ·
`feed` the availability API · `mcp` with no actor and `entity_type = 'share'` also a share read (db/017's `inv_log_share_read()` — reconciled 2026-10-05) · `application` · `api` · `system`), `action` (`entity.verb`), `entity_type` + `entity_id`, and the
**audit keys** the record says: `source_id` for anything about a source, a pull, a listing, an offer, a credential, a watch on a listing;
`sales_order_id` for a line, a payment, a shipment, a return, the customer's view; `purchase_order_id` for a send, an event, a receipt
against it; `location_id` for a movement, a count, a transfer's two halves, a sale from a store; `department_id` when the location names
one; `token_id` for a feed key's call; `request_id` = the run's under a run token; `agent_run_id`. **Never**: a credential or its
ciphertext, a token or key value or hash, a secure link's token, a customer's address lines or phone (the city and region are allowed), a
supplier's account number, a source's `raw` object or a page's body, more than 120 characters of a search query, an error over 200
characters. **Allowed and expected**: ids, names, SKUs, GTINs, numbers (`SO-000412`), counts, states, amounts with `currency` on every
money event, before/after of the fields changed (amounts and prices included; cost included — the log is admin-and-own-and-public-record
by the view, and `price_history` / the snapshots are the authoritative histories).

- `product.create|update|discontinue|delete`: `after.name`, `brand`, `product_type`, `kind`, `status`, the fields changed; `delete`: `variants`, `name`.
  `variant.create|update|delete`: `sku`, `size_key`, `barcode`, `mpn`, the fields changed. `variant.price_set`: `kind`, `before.price`, `after.price`,
  `reason`, `currency`. `variant.identifier_add|identifier_remove`: `kind`, `value`, `source_id`. `variant.bundle_set`: `components: [{variant_id, sku, qty}]`.
  `product.image_add|image_remove`: `attachment_id`, `filename`, `is_primary`. `brand.create|update`, `product_type.save`: `name`.
- `location.create|update|archive`: `name`, `kind`, `is_sellable`, `allow_negative`, `department_id`.
  `stock.receive` (`goods_receipt`): `number`, `supplier_id`, `purchase_order_id`, `location_id`, `lines`, `units`, `amount` (Σ cost), `currency`, `discrepancies`.
  `stock.adjust`: `number`, `location_id`, `reason_code`, `lines`, `units_delta`, `amount`. `stock.transfer_send|transfer_receive`: `number`, `from_location_id`,
  `to_location_id`, `lines`, `units`, `short: [{sku, qty, qty_received}]`. `stock.count_start|count_post`: `number`, `location_id`, `lines`, `lines_differing`,
  `units_delta`. `stock.allocate|issue|return|reverse`: `transaction_id`, `group_id`, `txn_type`, `sku`, `location_id`, `qty`, `reference_kind`, `reference_id`,
  `reverses_id`. `stock.floor_model` (reconciled 2026-10-05 — the manifest's one event; `txn_type` `floor_model_in|floor_model_out` stays on the transaction): `sku`, `location_id`, `direction` (in or out), `qty`.
- `supplier.create|update|archive`: `name`, `dropships`, `order_method`, `lead_time_days` — **never `account_number`**. `supplier_item.save|remove`:
  `supplier_id`, `sku`, `supplier_sku`, `cost`, `lead_time_days`, `moq`.
- `source.create|update|delete`: `name`, `connector`, `role`, `supplier_id`, `base_url`, `schedule_minutes`, `rate_per_second`, the settings **keys** changed
  (never a credential, never a mapping's sample rows). `source.credential_set|credential_rotate`: `credential_id`, `kind`, `label`, `last4`. `source.schedule_set`:
  `before/after.schedule_minutes`. `source.pause|resume`: `reason`. `source.probe`: `state`, `message` (≤ 200), `http_status`. `source.pull_start`: `pull_id`,
  `kind`, `query` (≤ 120). `source.pull_done|pull_fail|pull_blocked`: `pull_id`, `status`, `listings_seen`, `listings_new`, `listings_changed`, `variants_changed`,
  `listings_removed`, `http_requests`, `bytes`, `error` (≤ 200), `policy` (robots state, crawl delay, etag hits — never the user-agent's contact if it is a person's),
  `backoff_until`. `source.search`: `pull_id`, `query` (≤ 120), `size`, `sources` (ids), `listings_seen`, `listings_new`, `ms`, `eval` (bool).
- `listing.new|changed|removed|forget`: `listing_id`, `source_id`, `external_id`, `title` (≤ 200), `variants` (count). `listing.match|unmatch`: `listing_variant_id`,
  `variant_id`, `sku`, `match_kind`, `confidence`. `listing.propose|accept|dismiss`: `proposal_id`, `listing_variant_id`, `variant_id`, `sku`, `confidence`,
  `evidence` (the keys: brand, name_tokens count, size, dims_mm, type), `proposed_by`. `offer.change`: `listing_variant_id`, `source_id`, `pull_id`, `changed`
  (the field names: price, availability, qty, lead_time_days, cost_price) — **the counts and names, not the values; the values are the snapshot**.
- `watch.set|clear`: `watch_id`, `kind`, `variant_id` / `listing_variant_id` / `product_id`, `threshold`, `text_me`, `agent_member_id`. `watch.fire`: `watch_id`,
  `kind`, `target` (sku or title), `state`, `notified`, `texted` (bool — the kernel's notification id, never the number), `dispatched` (dispatch id).
- `customer.create|update|archive|delete`: `name`, `source`, `email_opt_in`, the fields changed by **name** (`email`, `phone`, `shipping_address` — "changed",
  not the value).
- `order.quote|confirm|line_add|line_update|line_fulfilment_set|line_cancel|close|cancel`: `number`, `customer_id`, `customer_name`, `salesperson_member_id`,
  `location_id`, `status`, `lines`, `total`, `currency`; a line: `line_id`, `line_no`, `sku`, `qty`, `unit_price`, `fulfilment_kind`, `location_id`,
  `listing_variant_id`, `source_id`, `offer_cost`, `offer_lead_time_days`; `confirm`: `allocated: [{sku, location_id, qty}]`, `dropships_drafted: [po ids]`,
  `backordered`; `cancel`: `reason`, `released`. `order.send`: `number`, `to` ("the customer's email" — the fact, not the address), `link_id`, `rotated`.
  `order.notify`: `kind` (delivery_date, delay), `number`. `order.customer_view` (source `portal`): `number`, `link_id`, `view_count` — never the token.
  `order.payment|refund`: `payment_id`, `number`, `kind`, `method`, `amount`, `currency`, `reference`, `payment_status`. `order.ship|deliver`: `shipment_id`,
  `number`, `kind`, `carrier`, `tracking_number`, `lines: [{sku, qty}]`, `issued: [{sku, location_id, qty}]`.
- `purchase_order.draft|send|place|close|cancel`: `number`, `supplier_id`, `supplier_name`, `kind`, `sales_order_id`, `ship_to_kind`, `location_id`, `lines`,
  `total`, `currency`, `sent_via`, `supplier_order_ref`, `reason`; **never the ship-to address when it is the customer's**. `purchase_order.supplier_view|
  supplier_ack|supplier_decline|supplier_tracking` (source `portal`, or `web` by hand): `number`, `line_id`, `supplier_order_ref`, `expected_on`, `reason`,
  `carrier`, `tracking_number`, `shipped_at`, `link_id` — never the token. `purchase_order.receive`: `number`, `goods_receipt_id`, `lines`, `units`, `short`.
  `supplier.message`: `supplier_id`, `purchase_order_id`, `subject` (≤ 200), `length`.
- `return.request|approve|receive|disposition|close|deny`: `number`, `sales_order_id`, `customer_id`, `status`, `method`, `scheduled_on`, `lines: [{sku, qty,
  reason_code, disposition}]`, `refund_amount`, `restocking_fee`, `currency`, `restocked: [{sku, location_id, qty}]`, `deny_reason`.
- `feed.key_mint|key_rotate|key_revoke`: `key_id`, `label`, `consumer_kind`, `price_list_id`, `rate_per_minute`, `rate_per_day`, `rotated_from`, `expires_at` —
  **never the key**. `feed.read` (source `feed`, `token_id`): `key_label`, `count`, `query_kind` (gtin, sku, q), `query_excerpt` (≤ 120). `feed.rate_limited`:
  `limit_hit`, `key_label`. `price_list.save`: `name`, `percent_off_retail`.
- `agent.dispatch|reply|fail`: `dispatch_id`, `agent_member_id`, `kind`, `via`, `run_id`, `request_id`, `status`, `reply_length`, `watch_id`, `listing_variant_id`.
  `buyer.propose|accept|dismiss|note`: `proposal_id`, `kind`, `subject_type`, `subject_id`, `title`, `drafted_record_type`, `drafted_record_id`; `note`:
  `note_date`, `counts` (the seven headings), `sent_to` ("the Buyer" / `#inventory`).
- `report.run`: `report`, `params` (by, days, as_of — never rows). `export.download`: `export`, `format`, `rows`, `period`. `share.read` (as built by db/017's `inv_log_share_read(p_tool, p_consumer, p_count, p_request_id)` — reconciled 2026-10-05: source `mcp`,
  `actor_member_id` NULL, `entity_type` `share`): `tool`, `consumer`, `count`, `request_id` — the request id in the payload only, never the rows. `settings.save`, `sequence.set`, `tax_rate.save`, `reason_code.save`: the fields changed (`crawl_user_agent`:
  "changed"). `token.mint|revoke`: `label`, `scope` — never a value. `member.sign_on`, `member.sign_on.refused` (`reason`), `member.refused`, `directory.sync`,
  `notification.send` (`kind`, `channel`, `record_type`, `record_id` — never a body), `screen.view` (`screen`, `route`, the record's audit key).

## Entity resolution for the action tools (the registry's `resolve` block — `deploy/kernel-registry-inventory.json`)

Each tool, called with `q` alone (digits = an id, otherwise exact on a number / SKU / GTIN, else `ILIKE` or trigram, at most 6 candidates),
answers its normal envelope — `{"ok": true, "count", "rows": [...]}` — and the kernel reads `rows` (`application_actions.resolve_via_mcp`
accepts `rows`, `candidates`, `results`, `items`). Every resolver is **people-free and cheap**: one view, one statement, no function that
ranks offers (`find` with `q` alone still runs `inv_find`, whose per-row availability is the one cost the variant resolver pays — it is the
one search; the kit's wrapper named `inv_find`, which is the SQL function's name, not a tool's: **fixed to `find`**). Every row carries a
`label`. The kit's wrapper is corrected as follows (the lead or Phase 4 rewrites the JSON; the names in it today were placeholders):

| Entity | Param(s) | Tool (with `q`) | id field | label field | Was in the wrapper |
|---|---|---|---|---|---|
| `product` | `product` | `find_products` | `product_id` | `label` ("Casper Original — Casper, Mattress") | `name` → `label` |
| `variant` | `variant`, `component`, `bundle` | `find` | `variant_id` | `label` ("CSP-ORIG-Q — Casper Original, Queen") | tool `inv_find` → `find`; `sku` → `label` |
| `brand` | `brand` | `find_brands` | `brand_id` | `name` | **added** |
| `location` | `location`, `from_location`, `to_location`, `putaway_location` | `find_locations` | `location_id` | `name` | ✓ (+ `putaway_location`) |
| `supplier` | `supplier` | `find_suppliers` | `supplier_id` | `name` | ✓ |
| `source` | `source` | `find_sources` | `source_id` | `name` | ✓ |
| `listing` | `listing` | `source_listings` | `listing_id` | `label` ("Zinus 12\" Green Tea — Malouf") | tool `find_listings` → `source_listings` |
| `listing_variant` | `listing_variant`, `offer` | `find_listing_variants` | `listing_variant_id` | `label` | **split out** of `listing` (the wrapper resolved both to one id field) |
| `customer` | `customer` | `find_customers` | `customer_id` | `label` ("Alvarez, Maria — maria@…" for `sales`; the name alone otherwise) | `name` → `label` |
| `sales_order` | `order`, `sales_order` | `find_orders` | `sales_order_id` | `label` ("SO-000412 — Alvarez, Maria, 3 Sep") | `number` → `label` |
| `purchase_order` | `purchase_order`, `po` | `find_purchase_orders` | `purchase_order_id` | `label` ("PO-000077 — Malouf, stock, sent") | `number` → `label` |
| `return` | `return`, `return_authorization` | `find_returns` | `return_id` | `label` ("RA-000009 — SO-000412, Alvarez") | **added** |
| `member` | `member`, `salesperson`, `agent`, `owner`, `buyer` | `find_members` | `member_id` | `display_name` | ✓ (+ `buyer`) |
| `department` | `department` | `find_departments` | `department_id` | `name` | ✓ |
| `line`, `purchase_order_line`, `shipment`, `payment`, `receipt`, `receipt_line`, `transfer`, `adjustment`, `count`, `count_line`, `transaction`, `identifier`, `image`, `watch`, `proposal` (a match proposal), `buyer_proposal`, `credential`, `pull`, `feed_key`, `price_list`, `dispatch`, `notification`, `token`, `attachment`, `note`, `template` (a source template's key), `product_type` (a key), `reason_code` (a code), `tax_rate` | **ids or keys only** — never resolved by name; an agent reads them from `get_order`, `get_purchase_order`, `receipts_open`, `transfers_open`, `counts`, `count_lines`, `stock_movements`, `get_product`, `my_watches`, `match_proposals`, `buyer_proposals`, `get_source`, `source_pulls`, `feed_keys`, `agent_dispatches`, `my_notifications`, `my_tokens`, `get_settings`, `find_sources(templates)` | | | |

A repeated parameter (`lines[]`, `variants[]`, `quantities[]`) is a list of ids or objects, never resolved by name (the wrapper's note).

## Coverage of the question inventory (design §7 → tools)

| # | Tools |
|---|---|
| C1 | `find_products`, `get_product` · C2 `product_variants`, `get_variant` · C3 `variant_by_identifier` · C4 `bundle_components`, `bundle_availability` · C5 `price_history` · C6 `catalog_gaps` |
| S1 | `stock_levels`, `stock_by_location` · S2 `stock_movements` · S3 `transfers_open`, `receipts_open` · S4 `counts`, `count_lines` · S5 `stock_value` · S6 `sell_through` · S7 `reorder_candidates` |
| A1 | `availability`, `find` · A2 `atp` · A3 `find_sources`, `get_source`, `source_health` · A4 `source_listings`, `get_listing` · A5 `offers_for_variant` · A6 `offer_history`, `availability_timeline` · A7 `unmatched_listings`, `match_proposals` · A8 `source_search` · A9 `source_pulls`, `get_pull` · A10 `price_exceptions` · A11 `lines_at_risk` · A12 `my_watches`, `watch_events` · A13 `supplier_items` · A14 `lead_time_actuals` |
| O1 | `find_orders`, `orders_late` · O2 `get_order`, `order_timeline` · O3 `find_customers`, `get_customer`, `customer_orders` · O4 `payments_due` · O5 `fulfilment_today` · O6 `shipments_open`, `dropships_untracked` · O7 `sales_summary` |
| D1 | `find_purchase_orders`, `purchase_orders_open` · D2 `get_purchase_order`, `purchase_order_events` · D3 `supplier_open_orders` · D4 `order_dropships` · D5 `find_returns`, `get_return`, `returns_open` · D6 `morning_note`, `buyer_proposals` |
| F1 | `feed_keys`, `key_usage` · G1 `agents_here`, `agent_dispatches` |
| ACT1 | `record_history` (+ `who_touched`) · ACT2 `actor_timeline` · ACT3 `recent_activity` · ACT4 `source_incidents`, `share_reads` · ACT5 `activity_search` |
| contract | `app_roles`, `records_search`, `find_members`; the shares `sales_closed`, `purchases_received`, `stock_valuation`, `availability_index`, `customer_orders` |
| support | `find_departments`, `find_locations`, `find_suppliers`, `find_brands`, `find_listing_variants` (resolvers), `get_settings`, `my_notifications`, `my_tokens` |

Every question of §7 has at least one named tool and every tool names its question. **No question is without a tool.**

**Design §3 rights → the read tools they open** (a right never widens reach: locations are not walls; cost is)
| Right | Read tools |
|---|---|
| `inventory.read` | every `reader` tool: the catalog, stock levels and movements, sources, listings, offers, histories, availability, `find`, `atp`, orders and their status, purchase orders and their events, returns, the resolvers, `get_settings`, `app_roles`, `records_search`, the activity server's `reader` tools |
| `orders.write` | `source_search`; customers' contact details and the ship-to on `find_customers`, `get_customer`, `get_order`, `find_orders`; `customer_orders` by email |
| `payments.record` | `payments_due`; `payments` on `get_order` |
| `orders.send` | the link's state on `get_order` |
| `watches.own` | `my_watches` (own), `watch_events` |
| `stock.receive` | `unit_cost` on `receipts_open`, receipts' rows of `stock_movements` (`inv_sees_receipt_cost`) |
| `stock.ship` | the ship-to on `find_orders` / `get_order` |
| `catalog.write` | `catalog_gaps` |
| `sources.write` | `settings`, `user_agent` on `find_sources` / `get_source`; the credential's label and last4; `crawl_user_agent` on `get_settings` |
| `listings.match` | `unmatched_listings`, `raw` on `get_listing`, `morning_note` |
| `purchasing.write` | `ship_to_name`, `internal_notes`, the supplier link's state on `get_purchase_order`; `account_number` on `find_suppliers` |
| `watches.all` | `my_watches` with `member` |
| `reports.read` | `stock_value`, `sell_through`, `sales_summary`, `reorder_candidates`, `price_exceptions`, `lead_time_actuals`, `morning_note`, `buyer_proposals`, `agent_dispatches` (all), `payments_due`, the three ledger shares as a person, `actor_timeline` of another person |
| `cost.read` | every cost field everywhere (the wall) |
| `settings.manage` | `share_reads`; `mcp_activity_log`'s admin rows; `my_notifications` of everyone |
| `feed.keys` | `feed_keys`, `key_usage` |
| `agents.settings` | `agent_dispatches` (all), `buyer_proposals` (all) |
| `sequences.manage` | `sequences` on `get_settings` |

**No view unreachable.** db/004 + db/015 define 62 `mcp_*` views; each is read by a named tool (as well as by `records_search`):
`mcp_app_roles` → `app_roles` · `mcp_members` → `find_members`, `agents_here` · `mcp_departments` → `find_departments` · `mcp_settings`,
`mcp_document_sequences`, `mcp_tax_rates`, `mcp_reason_codes`, `mcp_product_types` → `get_settings` · `mcp_attachments`, `mcp_notes` →
the `get_*` tools · `mcp_brands` → `find_brands` · `mcp_products` → `find_products`, `get_product` · `mcp_product_variants` →
`product_variants`, `get_variant` · `mcp_variant_identifiers` → `get_product`, `get_variant`, `variant_by_identifier` ·
`mcp_bundle_components` → `bundle_components` · `mcp_price_history` → (`price_history` reads the function; the view serves
`records_search`) · `mcp_product_images` → `get_product` · `mcp_locations` → `find_locations`, `stock_by_location` ·
`mcp_inventory_balances` → `stock_levels` · `mcp_inventory_transactions` → `stock_movements`, `count_lines` · `mcp_goods_receipts`,
`mcp_goods_receipt_lines` → `receipts_open`, `get_purchase_order` · `mcp_inventory_transfers`, `mcp_inventory_transfer_lines` →
`transfers_open` · `mcp_inventory_adjustments`, `mcp_inventory_adjustment_lines` → `stock_movements` (`reference_kind = adjustment`;
the documents themselves only through `records_search` — DECISION 20: no `adjustments` tool; an adjustment is a movement with a reason) ·
`mcp_inventory_counts`, `mcp_inventory_count_lines` → `counts`, `count_lines` · `mcp_suppliers` → `find_suppliers`, `supplier_open_orders`
· `mcp_supplier_items` → `supplier_items` · `mcp_sources` → `find_sources`, `get_source` · `mcp_source_credentials` → `get_source` ·
`mcp_source_pulls` → `source_pulls`, `get_pull` · `mcp_source_templates` → `find_sources(templates)` · `mcp_listings` →
`source_listings`, `get_listing` · `mcp_listing_variants` → `find_listing_variants`, `get_listing` · `mcp_offer_snapshots` → `get_pull`
(the series: `offer_history`'s function) · `mcp_match_proposals` → `match_proposals` · `mcp_watches` → `my_watches` · `mcp_customers` →
`find_customers`, `get_customer` · `mcp_sales_orders`, `mcp_sales_order_lines` → `find_orders`, `get_order` · `mcp_order_payments` →
`payments_due`, `get_order` · `mcp_shipments`, `mcp_shipment_lines` → `shipments_open` · `mcp_order_links` → `get_order` ·
`mcp_purchase_orders`, `mcp_purchase_order_lines` → `find_purchase_orders`, `get_purchase_order` · `mcp_purchase_order_events` →
`purchase_order_events` · `mcp_supplier_links` → `get_purchase_order` · `mcp_return_authorizations`, `mcp_return_lines` → `find_returns`,
`get_return` · `mcp_notifications`, `mcp_notification_prefs` → `my_notifications`, `watch_events` · `mcp_notification_outbox` →
`records_search` only (the admin's; DECISION 21: no tool — an outbox row is the worker's business) · `mcp_agent_dispatches` →
`agent_dispatches` · `mcp_buyer_proposals` → `buyer_proposals` · `mcp_price_lists`, `mcp_feed_keys`, `mcp_key_usage` → `feed_keys`,
`key_usage` · `mcp_access_tokens_mine` → `my_tokens` · `mcp_activity_log` → the activity server. **Every db/014 function granted to the
read role has a tool**: `inv_availability` → `availability`; `inv_bundle_availability` → `bundle_availability`; `inv_atp` → `atp`;
`inv_find` → `find`; `inv_offer_history` → `offer_history`; `inv_price_history` → `price_history`; `inv_reorder_candidates`,
`inv_lines_at_risk`, `inv_price_exceptions`, `inv_unmatched_listings`, `inv_source_health`, `inv_stock_value`, `inv_sell_through`,
`inv_lead_time_actuals`, `inv_order_timeline`, `inv_purchase_orders_open`, `inv_returns_open`, `inv_catalog_gaps` → their namesakes.
`inv_offers_for_variant`, `inv_sources_due`, `inv_feed_answer` are the writer's alone (db/014's `REVOKE`), as designed.

## Decisions taken where the design or the schema is silent

1. **Errors are answers** (`{"ok": false, "error": {code, message, retry}}`), Knowledge's convention; `not_found` for a record the caller may not see.
2. **Every list answers an envelope** `{"ok", "count", "rows", "truncated"}` (the kernel's resolver reads `rows`); the wrapper's note "a plain LIST" is corrected.
3. **`source_search` writes through a bridge** to PHP on the internal port (`/internal/bridge.php`, HMAC over the body with `ACTIONS_RELAY_KEY`, prefix `inv-bridge:`, loopback, ±30 s) — Knowledge's pattern; the read role never writes; the one op in v1.
4. **`variant_by_identifier` is `inv_find` kept at score ≥ 9** — no new function; GTIN-14 normalisation is the function's.
5. **A tool never narrows a view**: where §7 names a higher role than the view or function enforces (S2, A2, A7's proposals, A9, A11, A13, D1's open list, O5, ACT4's incidents), the tool admits what the SQL admits and the table says so. Cost is the wall, not the row.
6. **Source templates are `find_sources(templates: true)`**, not a tool of their own.
7. **`offers_for_variant` reads `inv_availability()`'s two arrays** (`inv_offers_for_variant` is revoked from the read role).
8. **`availability_timeline` collapses `inv_offer_history()`'s series** in Python — no new function.
9. **`source_search`'s budget**: the source's `rate_per_second` (default 1/s per host), 20 s per request (`InvHttp`), 25 s per source, 60 s per call, sources in sequence, at most 5 sources, ≤ 20 listings per source; under `is_eval` nothing persists.
10. **`watch_events` = `mcp_notifications` kind `watch` ⨝ `mcp_agent_dispatches` by `watch_id`**; another member's fires show as `fired_at` / `fire_count` only.
11. **`customer_orders` by `email` needs `orders.write`** (an email is a contact detail the view gives Sales); by `customer` any reader; as a share, the kernel alone.
12. **`sales_summary` answers brand / type / salesperson / variant through `inv_sell_through()`** and `invalid_argument` for `day` / `week` until `inv_sales_summary` lands (owed).
13. **`agents_here`'s "what it last did"** is counts from `mcp_agent_dispatches` / `mcp_buyer_proposals`; the full trail is the activity server's `actor_timeline`.
14. **`find_brands` is a resolver** (`product_create` names a brand); product types, reason codes, tax rates and source templates are keys, never resolved.
15. **`SEARCH_DENY` adds `inv_share_`**, `inv_resolve_feed_key`, `inv_rate_ok` (Phase 4, `mcp/db.py`).
16. **The feed's document is `os.inventory-feed/1`** wrapping `inv_feed_answer()`'s fields.
17. **`actor_timeline`'s gate**: own trail anyone; an agent's trail any reader; another person's `reports.read` or the admin.
18. **`share_reads`** needs `settings.manage` for the rows; `reports.read` sees the grouped counts.
19. **`activity_search` has two modes** — a phrase (`q`) or the contract's guarded `SELECT` (`sql`) — so the activity server has seven tools as the design counts them.
20. **No `adjustments` tool**: an adjustment is a movement with a reason (`stock_movements`); the document rows are `records_search`'s.
21. **No outbox tool**: `mcp_notification_outbox` is `records_search`'s (the admin's).
22. **The five shares are also people's tools** under the same names (`KERNEL_ONLY` empty): the GL ones need `reports.read`; `availability_index` any reader; the kernel's path uses the share functions, a person's the gated function or view.
23. **The `markdown` field** on `availability`, `atp`, `get_order`, `order_timeline`, `get_purchase_order`, `morning_note` — what the expert reads aloud; cost named only when the caller may see it.

## Owed to the schema (db/ not modified here — the lead adds an additive migration, db/016)

The read role (`inventory_records_ro`) has no member when the kernel calls, and every db/014 read function demands one
(`inv_require_reader()`); the ledger's shares must also carry **cost unnulled**. So the five shares need their own `SECURITY DEFINER`
functions, granted to `inventory_records_ro`, that take no caller into account, answer people-free, and are reached by **no view and no
other tool** (`SEARCH_DENY` adds `inv_share_`):

| Signature | Answers | Notes |
|---|---|---|
| `inv_share_sales_closed(p_from date, p_to date, p_offset integer DEFAULT 0, p_limit integer DEFAULT 200) RETURNS jsonb` | `os.inventory-sales/1` | closed orders by `closed_at` in the business's timezone; `cogs` = Σ qty × COALESCE(offer_cost, cost_price); payments by method; refunds; `limit` ≤ 500; `p_to − p_from` ≤ 92 days (`check_violation` beyond) |
| `inv_share_purchases_received(p_from date, p_to date, p_offset integer DEFAULT 0, p_limit integer DEFAULT 200) RETURNS jsonb` | `os.inventory-purchases/1` | posted receipts by `posted_at`; drop-ship lines `received` by the customer line's delivery in the period; grouped by supplier; never a ship-to |
| `inv_share_stock_valuation(p_as_of date, p_by text DEFAULT 'location') RETURNS jsonb` | `os.inventory-valuation/1` | `inv_stock_value()`'s query with cost unnulled and no caller check; `p_by` in (location, brand) |
| `inv_share_availability_index(p_q jsonb, p_limit integer DEFAULT 25) RETURNS jsonb` | `os.inventory-availability/1` | `inv_feed_answer()`'s loop without a key: no `quantity`, no `partner_price`; `p_q` = `{"q","size"}` or `{"gtin"}` or `{"sku"}`; ≤ 100 |
| `inv_share_customer_orders(p_email citext, p_open_only boolean DEFAULT true, p_limit integer DEFAULT 25) RETURNS jsonb` | `os.inventory-orders/1` | exact email; `found`; lines' fulfilment in the customer's words; a shipment's tracking; `order_page_live` from `order_links_secure` (never the token) |

And two functions a screen and a tool should share, so they never disagree (the tools are built over the views until these land; neither
blocks Phase 1):

| Signature | For | Notes |
|---|---|---|
| `inv_fulfilment_today(p_day date DEFAULT current_date, p_location_id bigint DEFAULT NULL) RETURNS TABLE (location_id, location_name, delivery_method, sales_order_id, order_number, customer_name, promised_on, ship_to_city, line_id, line_no, variant_id, sku, product_name, size_name, qty, qty_allocated, qty_shipped, fulfilment_kind, line_status)` | O5 `fulfilment_today` and the Fulfilment today screen (§9) | stock, pickup and backorder lines allocated and not shipped on confirmed / in-fulfilment orders promised on or before `p_day`; `inv_require_reader()` |
| `inv_sales_summary(p_from date, p_to date, p_by text) RETURNS TABLE (group_id bigint, group_key text, group_name text, orders bigint, units_sold bigint, revenue numeric, discount numeric, tax numeric, cogs numeric, margin_pct numeric, cost_withheld boolean)` | O7 `sales_summary` and the Sales summary and margin report (§9) | keyed on confirmed orders by `ordered_on` (sold, not shipped — `inv_sell_through` is what shipped); `p_by` in (day, week, month, brand, type, salesperson, variant, location); `inv_require_reader()` with `cogs` / `margin_pct` nulled below `cost.read` (so Sales sees retail as §7 says) |

Also noted, not schema: `mcp/db.py`'s `SEARCH_DENY` (DECISION 15) and `mcp/server_common.py`'s `KERNEL_TOOLS` (the five shares) are
Phase 4's; `html/internal/bridge.php` and `require_right` on `source_search` are slice 4's; `deploy/kernel-registry-inventory.json`'s
`resolve` block is rewritten to the table above (the lead's or Phase 4's).

## Size

Records: **83 tools** — 68 named in §7 (`customer_orders` counted once: it is O3's tool and the HD3 share), `app_roles`, `records_search`,
the four other shares (`sales_closed`, `purchases_received`, `stock_valuation`, `availability_index`), six resolvers (`find_members`,
`find_departments`, `find_locations`, `find_suppliers`, `find_brands`, `find_listing_variants`) and three support tools (`get_settings`,
`my_notifications`, `my_tokens`); of these, **five are shares**, **one writes through the bridge** (`source_search`), and 19 call a db/014
function. The design's "about 62" was an undercount of its own §7 (68 names). Activity: **7 tools**. The resolve block names **14
entities**. Five share documents, one feed document. Phase 4 builds them as `mcp/records_server.py` with `inv_catalog`, `inv_stock`,
`inv_sources`, `inv_orders`, `inv_purchasing`, `inv_agents`, `inv_shares`, `inv_misc` (each `register(mcp, q, bridge)`) and
`mcp/activity_server.py`, over the views and functions the screens read.

## Open questions
(none)

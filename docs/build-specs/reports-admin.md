# Build spec: reports, home, admin and the trail (slice 9)

Built by the worker model from this spec, replicating slice 3's files, gates and proof style (`docs/build-specs/sources.md`, the exemplar) and
slice 1's CRUD pattern for the admin's small tables. What exists at the end: the **Home** every person lands on is real — the morning note's seven
headings as counts that open their lists, the lines at risk, the pulls failed or blocked, the unmatched listings, the purchase orders awaiting
acknowledgment, today's deliveries and pickups, and the role's own block (Sales: my open orders and their next step; Warehouse: to receive, to pick,
to count; the admin: the feed's usage and the dispatches); the **Reports** — stock value, sell-through and cover, sales summary and margin (under the
wall), price exceptions, source health and reliability, lead-time actuals, catalog gaps — each a screen with filters and a CSV, and the **Exports** —
the accounting system's three files (`sales_closed`, `purchases_received`, `stock_valuation`), the catalog and the listings as CSV or JSON downloads;
the **Admin** — every column of `inv_settings` on one form grouped as design §9 says, the document sequences, the tax rates, the reason codes, the
**Agents** page (read-only — hiring, grants and duties are the kernel's); and the **trail** made whole (a sentence for every event of the manifest, a
record's history by its audit keys). Nothing new in the schema; nothing here sends, spends or deletes.
Schema: `inv_settings`, `document_sequences`, `tax_rates`, `reason_codes`, `inv_setting_int()`, `inv_currency()` (db/005); `inv_stock_value()`,
`inv_sell_through()`, `inv_lead_time_actuals()`, `inv_price_exceptions()`, `inv_source_health()`, `inv_catalog_gaps()`, `inv_lines_at_risk()`,
`inv_reorder_candidates()`, `inv_unmatched_listings()`, `inv_purchase_orders_open()`, `inv_returns_open()` (db/014); **db/016's
`inv_sales_summary(p_from, p_to, p_by)` and `inv_fulfilment_today(p_day, p_location_id)`, and the five `inv_share_*` the exports call** (built beside
this spec); the views `mcp_settings`, `mcp_document_sequences`, `mcp_tax_rates`, `mcp_reason_codes`, `mcp_members`, `mcp_sales_orders`,
`mcp_sales_order_lines`, `mcp_goods_receipts`, `mcp_inventory_counts`, `mcp_feed_keys`, `mcp_agent_dispatches`, `mcp_buyer_proposals`,
`mcp_products`, `mcp_product_variants`, `mcp_variant_identifiers`, `mcp_brands`, `mcp_product_types`, `mcp_listing_variants`, `mcp_sources`,
`mcp_activity_log` (db/015). Never modify them. **The database is the referee**: a report is its function's rows with cost nulled by `inv_sees_cost()`;
a setting's bounds are the table's CHECKs (PHP mirrors each bound so the person reads a field's name, never a constraint's); a sequence's number
only rises; one default tax rate (the partial unique index); a reason's code is unique; the shares carry cost unnulled and are reached only behind
the gate below.

## Divergences from the design, the manifest and the tool surface
1. **`catalog_gaps()` and `fulfilment_today()` are query functions of this slice** (the lead's list of the tools whose readers slices 7–9 build), though
   their screens are slice 1's (`catalog-gaps`) and slice 5's (`fulfilment-today`). The signatures below are the contract: **slice 1 writes `catalog_gaps()` in
   `app/features/catalog/queries.php` and slice 5 writes `fulfilment_today()` in `app/features/orders/queries.php`, each at this signature, and this
   slice calls them** (reconciled 2026-10-05 — catalog.md and orders.md name the same signatures). The Reports hub links the catalog gaps to slice 1's
   screen. **DECISION 1.**
2. **The `margin` report is the `sales` report with the cost columns** — one function (`inv_sales_summary`), one screen; `report=margin` opens it
   grouped by brand with `cogs` and `margin_pct` shown, "cost withheld" for a caller below the wall. **DECISION 2.**
3. **`/reports/{report}` needs a rewrite** neither `deploy/apache-inventory.conf` nor `tests/dev_router.php` carries today (`report.php?report=` — the
   manifest's convention): this slice adds one line to each, before the generic rules. **DECISION 3.**
4. **`export_download` in JSON mode carries no file**: it answers `{ok, did, rows, bytes, period, location}` and the person downloads from the
   screen (an agent reads the same data through the records tools). **DECISION 4.**
5. **The three accounting files need `exports.all`, or `reports.read` with `sees_cost()`** (the manifest's "reports.read or exports.all", narrowed: the
   files carry cost unnulled — `inv_share_*` is people-free and nulls nothing); the catalog and the listings need `reports.read` and null cost through
   the views. **DECISION 5.**
6. **`mcp_settings` omits `business_address` and `raw_max_bytes`** though `settings_save` names both: the settings form reads `inv_settings` as the
   writer (`find_settings()`), not the view; the tool surface's `get_settings` is told (reported). **DECISION 6.**
7. **A reason code's `code` is fixed after creation** (`inv_return_receive()` looks `floor_model` up by code; a seeded code is referenced by name);
   `reason_code_save` with `reason` changes the name, `applies_to`, `affects_qty`, `sort_order`, `active` — never the code. **DECISION 7.**
8. **The Agents page asks the kernel nothing** (it exposes no agents endpoint — Consultant Tracking's finding): the page is built from `maludb-os.json`
   (the declared agents), the mirror (`mcp_members` `is_agent`, admitted), `mcp_agent_dispatches`, `mcp_buyer_proposals` and the agents' own
   `activity_log` rows; a declared agent is matched to a mirror agent by `display_name` (case-insensitive), else by `job_title`. **DECISION 8.**
9. **The shell's "me" rows are Phase 2's** (`my-settings`, `tokens`, `notifications`; `prefs_save`, `notification_read`, `token_mint`, `token_revoke`) —
   as every sibling's shell built them (Consultant Tracking, Spaces, Knowledge); this slice proves tokens for every role and claims nothing of them.
   **DECISION 9** — reported to the lead to reconcile with `sso-shell.md`.

## Screens (375 px is the design for Home; the reports at 1280 px and usable at 375 px; the admin pages usable at 375 px, designed at 1280 px)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `home` | `/` | **the morning note** (`home-note`): the seven headings each a count that opens its list — at risk (→ `#home-at-risk`), reorder (→ `/stock/?below_reorder=1`), prices (→ `/reports/price-exceptions`), unmatched (→ `/matching/`), sources (→ `/sources/?health=failing`), purchase orders (awaiting ack → `/purchasing/?awaiting_ack=1`, untracked → `/purchasing/?no_tracking=1`), returns (→ `/returns/?awaiting_disposition=1`) — a heading the person's rights withhold is not shown (`morning_note()`'s `withheld`); **lines at risk** (`home-at-risk`: `inv_lines_at_risk()` rows ≤ 25 — the order, the line, the customer's name, why, the salesperson; each → `order-view`); **pulls failed or blocked** (`home-sources`: `inv_source_health()` rows with health ∈ failing · blocked · paused, each → `source-view`); **unmatched listings** (`home-unmatched`: the count and the five newest of `inv_unmatched_listings()` → `match-queue`; `listings.match` only); **purchase orders awaiting acknowledgment** (`home-po-ack`: `inv_purchase_orders_open()` with `awaiting_ack`, each → `purchase-order-view`); **today's deliveries and pickups** (`home-today`: `fulfilment_today()` counts by location and delivery method → `/orders/today`); **for Sales** (`orders.write`; `home-my-orders`): my open orders (`mcp_sales_orders` where `salesperson_member_id` = me and status ∈ quote · confirmed · in_fulfilment · shipped, promised soonest first, ≤ 10) with the **next step** (`order_next_step()`: quote → "Confirm", confirmed unpaid → "Record a deposit", confirmed/in_fulfilment → "Ship" (a stock line allocated) or "Waiting on <supplier>" (a drop-ship ordered) or "Backorder — choose a source", shipped → "Deliver", delivered → "Close", late → "Late by N days" first); **for Warehouse** (`stock.receive|stock.ship|stock.count`; `home-warehouse`): to receive (draft receipts `mcp_goods_receipts` + stock purchase orders expected today or earlier, `inv_purchase_orders_open()` kind `stock`), to pick (`fulfilment_today()`'s stock and pickup lines by location), to count (open `mcp_inventory_counts`); **for the admin** (`settings.manage|feed.keys|agents.settings`; `home-admin`): the feed's usage today (live keys, calls today — `key_usage_summary()`, slice 7's) and the dispatches (pending, running, awaiting approval, failed — `mcp_agent_dispatches` counts → `dispatch-list`); each region a card with its link; an empty region says what will appear; **the order on a phone**: the note, at risk, my orders / the warehouse block, today, sources, unmatched, POs, the admin |
| `trail` | `/trail?product=&variant=&source=&order=&purchase_order=&member=&action=&period=` | my own rows newest first (`mcp_activity_log` where `actor_member_id` = me), or a record's history when a key is given: `source` → `source_id`, `order` → `sales_order_id`, `purchase_order` → `purchase_order_id` (the audit keys — everything that touched it), `product` / `variant` → `entity_type` + `entity_id` (`product`, `product_variant`) plus the rows whose `after.product_id` / `after.variant_id` name it, `member` → that actor (`reports.read` or the admin for another person; an agent's rows any reader — the tool surface's DECISION 17); filters `action` (a prefix — `order.`, `source.`) and `period` (1 · 7 · 30 · 90 days; 30 by default); 50 a page (`trail-results`, `trail-row-{activity_id}`); every row a sentence (`activity_sentence()`) with the actor (an agent chipped, the worker "the worker", the feed "the feed (<key label>)", a door "the customer" / "the supplier") and a link to the record (`activity_record_link()`); JSON lists the same rows |
| `report-list` | `/reports/` | the reports as cards (`report-card-{report}`): stock value, sell-through and cover, sales summary, margin, price exceptions, source health and reliability, lead-time actuals, catalog gaps (→ slice 1's `/catalog/gaps`); each with one line on what it answers and who may read it; `require_right('reports.read')` (catalog gaps: `catalog.write`) |
| `report` | `/reports/{report}?from=&to=&location=&brand=&type=&supplier=&source=&by=&days=&as_of=&kind=` | one report (`report-{report}`): the filter form in the page header (`report-form`, the fields the report takes — below), **Run** (`report-form-run-btn`, `hx-post` to `/reports/run.php` with `format=html`, `hx-target="#report-results"`) and **CSV** (`report-form-csv-btn`, a form post with `format=csv` — a download); the results (`report-results`): a table (`report-results-table`, `report-row-{n}`) with the totals row, the "cost withheld" note when the caller is below the wall, the period and the group-by shown; the GET renders the form and the results for the query-string values (so a link lands on a filled report); `report` ∈ stock-value · sell-through · sales · margin · price-exceptions · source-health · lead-times (else 404 "No such report.") |
| `export-list` | `/exports/?from=&to=` | the downloads (`export-list`): a card per export (`export-card-{export}`): **Sales closed** (`os.inventory-sales/1`), **Purchases received** (`os.inventory-purchases/1`), **Stock valuation** (`os.inventory-valuation/1`) — "the accounting system's files"; **The catalog**; **The listings** (a source picker); each with its fields (a period `from`/`to` ≤ 92 days for the first two, `as_of` and `by` for the valuation, `source` for the listings) and two buttons **CSV** / **JSON** (`export-card-{export}-{csv|json}-btn`, form posts to `/exports/download.php`); a note on what each carries and that the three accounting files carry cost; a reader below the wall sees the three cards disabled with "cost withheld — the admin or the Buyer exports these" |
| `admin-settings` | `/admin/settings` | one form over `inv_settings` (`admin-settings-form`, every column but `id`/`updated_at`), in seven groups as cards (the field ids `admin-settings-field-{column}`): **The business** — `business_name` (≤ 120), `business_contact_email`, `business_phone` (≤ 40), `business_address` (≤ 500), `currency` (`^[A-Z]{3}$`), `units` (imperial · metric), `timezone` (a `DateTimeZone` identifier; a select); **Who sees what, and the doors** — `sales_sees_cost` (a switch), `supplier_sees_phone[]` (checkboxes parcel · ltl · white_glove · pickup_only), `feed_shows_quantity`, `order_link_days` (1–3650), `supplier_link_days` (1–3650); **The feed** — `feed_rate_per_minute` (1–100000), `feed_rate_per_day` (1–10000000), `key_rotation_overlap_hours` (1–720); **The catalog's vocabulary** — `sizes` (a JSON textarea `[{key, name, synonyms[]}]`, keys `^[a-z][a-z0-9_]*$` unique, names required, synonyms lower-cased and unique across every size; a read-only table beside it renders the current list — `admin-settings-sizes-table`), `attribute_keys` (a JSON textarea `[{key, name, kind (choice · number · text · multi), choices[]?}]`, keys unique, `choices` required for choice and multi; the rendered table `admin-settings-attributes-table`); **Buying and the Buyer** — `reorder_point_default` (≥ 0), `reorder_qty_default` (≥ 1), `cost_source` (last_receipt · feed · manual), `cost_move_pct` (0–999.99), `reference_undercut_pct` (0–999.99), `ack_days` (1–90), `buyer` (a picker of active humans holding a role here, `members_for_pick()` filtered to `member_kind = human` — the morning note's recipient; empty = the first super-admin); **The crawl policy** — `crawl_user_agent` (≤ 300; empty = built from the business name and contact; a value must contain `(+` followed by a URL or `mailto:` — connectors.md §6.2's rule), `crawl_rate_per_second` (0.01–10, two decimals), `crawl_backoff_minutes[]` (1–3 whole numbers 1–43200, rising — the ladder), `crawl_max_pages` (1–10000), `schedule_supplier_minutes` (5–10080), `schedule_reference_minutes` (5–10080), `schedule_jsonld_minutes` (60–10080), `removed_after_pulls` (1–20), `snapshot_heartbeat_days` (1–30), `raw_max_bytes` (256–1048576); **Files** — `max_attachment_bytes` as **MB** in the form (1–1024; bytes in the row); Save (`admin-settings-form-save-btn`) / Cancel in the pinned header; the `updated_at` shown |
| `sequence-list` | `/admin/sequences` | the seven document sequences (`mcp_document_sequences`) as a table (`sequence-list-table`, `sequence-row-{kind}`): kind, prefix, next value, padding, the next number as it will read (`prefix || lpad(next_value, padding, '0')`), updated; per row an inline form (`sequence-row-{kind}-form`: prefix, next value, padding; **Save** with a confirm "A number is never reused — raising the next value leaves a hole") |
| `tax-rate-list` | `/admin/tax-rates/` | the tax rates (`mcp_tax_rates`) as a table (`tax-rate-list-table`, `tax-rate-row-{id}`): name, rate (four decimals shown as a percent), default (a chip), used by (orders and customers counting it — `mcp_sales_orders.tax_rate_id`, `mcp_customers.tax_rate_id`); archived ones apart under a heading; **Add a tax rate** (`tax-rate-list-add-btn`); per row **Edit** (`tax-rate-row-{id}-edit-btn`) and **Archive** (`tax-rate-row-{id}-archive-btn`, a confirm; not on the default) |
| `tax-rate-add` / `tax-rate-edit` | `/admin/tax-rates/new`, `/admin/tax-rates/{tax_rate}/edit` | the form (`tax-rate-form`): name (`tax-rate-form-field-name`, 1–80, required, unique among live rates ignoring case), rate (`-rate`, 0–99.9999, a percent, four decimals), default (`-is_default`, a switch — "the default applies to a new order with no rate of its own"); Save / Cancel in the pinned header |
| `reason-code-list` | `/admin/reason-codes/` | the reason codes (`mcp_reason_codes`) as a table in `sort_order` (`reason-code-list-table`, `reason-code-row-{id}`): code, name, applies to (chips adjustment · return · transaction), affects quantity, active; **Add a reason code** (`reason-code-list-add-btn`); per row **Edit** (`reason-code-row-{id}-edit-btn`) |
| `reason-code-add` / `reason-code-edit` | `/admin/reason-codes/new`, `/admin/reason-codes/{reason_code}/edit` | the form (`reason-code-form`): name (`reason-code-form-field-name`, 1–80, required), code (`-code`; on add: lower-case `^[a-z][a-z0-9_]{0,39}$`, from the name when blank — letters and digits, spaces to `_`; on edit: shown read-only — DECISION 7), applies to (`-applies_to`, checkboxes adjustment · return; at least one), affects quantity (`-affects_qty`, a switch, on by default), sort order (`-sort_order`, 0–999), active (`-active`); Save / Cancel |
| `agent-list` | `/admin/agents` | **read-only**: the declared agents of `maludb-os.json` (`agents[]`) each matched to a mirror agent (DECISION 8) as a card (`agent-card-{member_id}` or `agent-card-declared-{key}`): name, the job (the declaration's `name` and the mirror's `job_title`), roles here (`mcp_members.roles`), hired here (a member holding a role, active) or "Not hired here yet", the duty (`duty.name`, `schedule_cron` in words — "every day at 06:30"), the skills carried (`skills[]` by name), **last action** (the newest `activity_log` row by that actor: the sentence and when), **last dispatch** (`mcp_agent_dispatches` newest: status and when), pending and failed dispatches (counts → `dispatch-list?agent=`), proposals today (`mcp_buyer_proposals` by `proposed_by`, `note_date = today` → `proposal-list`); then every other mirror agent holding a role here (not declared) as a plainer card; a note: "Hiring, grants, duties and approvals are the kernel's" with links to the OS (`OS_LAUNCHER_URL`'s host with `app.` → `os.`: `/agents`, `/ai/runs`) — `agent-list-os-link`; no form, no button that posts; `require_right('agents.settings')` |

### The reports — fields, function, columns
| `report` | Fields | Function | Columns (the function's; cost columns null below the wall, the note shown) |
|---|---|---|---|
| `stock-value` | `as_of` (a date-time, now by default), `by` (location · brand · type · variant; location) | `inv_stock_value(as_of, by)` | group, units, value; totals |
| `sell-through` | `days` (1–365; 30), `by` (variant · brand · type · salesperson; variant) | `inv_sell_through(days, by)` | group, units sold, revenue, COGS, margin %, on hand, weeks of cover (variant only); totals |
| `sales` | `from`, `to` (dates; this month by default; ≤ 366 days), `by` (day · week · month · brand · type · salesperson · variant · location; brand) | `inv_sales_summary(from, to, by)` | group, orders, units, revenue, discount, tax, COGS, margin %; totals |
| `margin` | the same as `sales`, `by` brand by default | `inv_sales_summary(from, to, by)` | the same, COGS and margin first; "cost withheld" below the wall (DECISION 2) |
| `price-exceptions` | `kind[]` (retail_under_map · reference_undercut · cost_moved; all), `brand` | `inv_price_exceptions()` filtered in PHP by `kind` and `brand` (the function takes none) | kind, SKU, product, size, retail, MAP, cost, reference price, source, %, detail |
| `source-health` | `health[]` (ok · stale · failing · blocked · paused · manual · never_pulled · inactive; all), `role`, `days` (the reliability window, 30) | `inv_source_health()` + `source_reliability(days)` (`activity_log`: `source.pull_done` / `pull_fail` / `pull_blocked` per `source_id` → pulls, failures, blocks, `success_pct`, last failure, last error ≤ 200) | source, connector, role, supplier, health, last pull, last ok, failures in a row, next due, listings live, unmatched, pulls (N days), success %, last error |
| `lead-times` | `supplier` | `inv_lead_time_actuals(supplier)` | supplier, lines, promised avg, actual avg, drift, on-time % |
`report_run` (`/reports/run.php`, POST): `report` (one of the seven), the fields above (each validated: a date `YYYY-MM-DD`, `by` in the report's list,
`days` within bounds, a `brand`/`supplier`/`source`/`location` an id of its view), `format` (html · csv; html): **html** answers the results partial
(`app/views/reports/partials/results.php`) for the HTMX target, or the whole report screen for a plain post; **csv** answers a download
(`Content-Disposition: attachment; filename="<report>-<date>.csv"`, `text/csv; charset=utf-8`, a UTF-8 BOM, the columns as headed, amounts with
two decimals, dates ISO); **JSON mode** answers `{ok, did, rows (≤ 500), totals, truncated, report, params}`; log `report.run` (`report`, `params`:
`by`, `days`, `from`, `to`, `as_of`, `kind`, `format` — never rows). `require_right('reports.read')`.

### The exports (`export_download`, `/exports/download.php`, POST)
| `export` | Fields | Source | CSV rows |
|---|---|---|---|
| `sales_closed` | `from`, `to` (≤ 92 days — the function's rule, the handler's 422 before it) | `share_sales_closed()` (slice 7's reader; pages of 500 until `truncated` is false — the handler loops the offset) | one row per order line: order number, ordered on, closed at, customer, location, delivery method, line no, SKU, product, size, qty, unit price, discount, line total, fulfilment, qty returned, the order's subtotal, discount, tax name, tax rate, tax, shipping, total, amount paid, balance due, refunds, COGS, payments by method (one column per method seen) |
| `purchases_received` | `from`, `to` | `share_purchases_received()` | one row per receipt line and per drop-ship line: supplier, account number, terms, kind (receipt · dropship), document number, purchase order, supplier ref, received on / delivered at, location, line no, SKU, supplier SKU, product, size, qty, unit cost, line cost, discrepancy, sales order number (drop-ships) |
| `stock_valuation` | `as_of` (a date; today), `by` (location · brand) | `share_stock_valuation()` | one row per group: group, units, value, variants; a totals row; `cost_basis` in the JSON |
| `catalog` | none | `mcp_products` ⨝ `mcp_product_variants` ⨝ `mcp_variant_identifiers` ⨝ `mcp_brands` ⨝ `mcp_product_types` (`catalog_export_rows()`) | one row per variant: product, brand, type, kind, status, SKU, size, GTIN, MPN, the other identifiers (`kind:value` joined by `;`), weight g, L/W/H mm, ships how, retail, MAP, cost (when `sees_cost()`), reorder point, reorder qty, active, on hand, available |
| `listings` | `source` (one, or every active source) | `mcp_listing_variants` (`listings_export_rows()`) | one row per listing variant: source, role, listing title, variant title, size, SKU, barcode, MPN, price, compare at, currency, cost (when `sees_cost()` — the view nulls it), availability, qty, lead time, ships how, matched SKU, match kind, first seen, last seen, removed at, URL |
**Format** `csv` or `json` (the JSON is the share's document verbatim for the three accounting files; `{schema: "os.inventory-catalog/1" | "os.inventory-listings/1",
generated_at, application, currency, count, rows}` for the two others — DECISION 10: the two local documents are named here, versioned, for the
download alone). **Gate** (DECISION 5): the three accounting files — `exports.all`, or `reports.read` and `sees_cost()` (else 403 "The accounting files
carry cost — the admin or the Buyer exports them."); the catalog and the listings — `reports.read`. The download: `Content-Disposition: attachment;
filename="<export>-<from>-<to>.csv|json"`, `X-Content-Type-Options: nosniff`, streamed; at most 50,000 rows (`too_large` 422 beyond: "narrow the
period"). Log `export.download` (`export`, `format`, `rows`, `period` (`from`, `to` or `as_of`), `source_id` for the listings — never rows). JSON mode:
DECISION 4.

### The settings (`settings_save`, `/admin/settings.php`, POST)
- `require_right('settings.manage')`; **any field of the settings** — a field left out stays as it was (`req_has()`); every number through `inv_int()`
  with the table's bounds (above; `crawl_rate_per_second`, `cost_move_pct`, `reference_undercut_pct` as decimals with their own check); `currency`
  upper-cased, three letters; `timezone` ∈ `DateTimeZone::listIdentifiers()`; `supplier_sees_phone[]` ⊆ `inv_ships_how_kinds()`; `crawl_backoff_minutes[]`
  one to three rising whole numbers; `sizes` and `attribute_keys` parsed as JSON (a parse error → 422 naming the field with the parser's line), validated
  as above; `buyer` an active human of the mirror holding a role here (`inv_member_roles(id) <> '{}'`), empty → NULL; `crawl_user_agent` the honest-UA
  rule; `max_attachment_bytes` from MB (`× 1048576`); then one UPDATE of the changed columns; log `settings.save` (`before`/`after` of the changed fields —
  `inv_diff()`; `crawl_user_agent`: "changed", not the value; `sizes`/`attribute_keys`: the count of entries and the keys added or removed); **`other` for an
  agent**; `HX-Trigger: settingsChanged`; location `/admin/settings#<the first group changed>`. A changed size synonym applies to variants saved
  afterwards (the trigger runs on write); the form says so under the sizes table. **A removed size key that a variant's `size_key` uses is refused**
  ("Size 'olympic_queen' is used by 3 variants — rename it, do not remove it" — `product_variants.size_key` counted; DECISION 11).
- `PARTIAL_UPDATE_TARGETS` gains `/admin/settings.php => ['inv_settings', '_settings', 'inv_settings', 'id']` with the handler treating a missing
  `_settings` as 1 (the one row; `mcp_settings` has no `id` column, so the writer reads its own table — DECISION 12; the kit's `partial_update_prefill()`
  runs `SELECT 1 FROM inv_settings WHERE id = :id` as the visibility check, which the writer may).

### Sequences, tax rates, reason codes
- **`sequence_set`** (`/admin/sequences.php`, POST; `sequences.manage`): `kind` (one of the seven), `prefix` (`^[A-Z][A-Z0-9]{0,7}-?$`), `next_value`
  (≥ the current `next_value` — "never below what was issued": lowering → 422 "The next value cannot go below <current> — a number is never reused"),
  `padding` (1–12); one UPDATE; log `sequence.set` (`kind`, `before`/`after`); **`other`**; confirm; `HX-Trigger: settingsChanged`.
- **`tax_rate_save`** (`/admin/tax-rates/save.php`; `settings.manage`): `tax_rate` (an id to change) or new; name (unique among live rates — the index →
  `inv_guard()`'s "already taken"), rate (0–99.9999), `is_default` — when yes, the previous default's `is_default` cleared **in the same transaction before
  the write** (the partial unique index would otherwise refuse with a 23505 the guard words as a name clash — DECISION 13); an archived rate cannot be
  edited (422); log `tax_rate.save` (`name`, `rate`, `is_default`, the fields changed); **`other`**; location `/admin/tax-rates/#tax-rate-row-{id}`;
  `HX-Trigger: settingsChanged`.
- **`tax_rate_archive`** (`/admin/tax-rates/archive.php`; `settings.manage`): not the default ("Make another rate the default first."); `archived_at = now()`;
  orders and customers that name it keep it; log `tax_rate.archive`; confirm.
- **`reason_code_save`** (`/admin/reason-codes/save.php`; `settings.manage`): `reason` (an id to change) or new; the fields above; a duplicate code → the
  UNIQUE → 422 "That code is already used."; `applies_to[]` ⊆ adjustment · return · transaction, at least one; log `reason_code.save` (`code`, `name`,
  `applies_to`, `affects_qty`, `active`, the fields changed); location `/admin/reason-codes/#reason-code-row-{id}`; `HX-Trigger: settingsChanged`.

## Home — `home_summary(PDO, int $memberId): array`
One function, one call per region, each honouring its right and answering `null` for a region the person has no right to (the view omits it):
`note` (`morning_note($pdo, null, 0)` — counts only, `withheld` per heading), `at_risk` (`inv_lines_at_risk()` ≤ 25 — every reader), `sources`
(`inv_source_health()` filtered), `unmatched` (`listings.match`: the count and five rows), `po_ack` (`inv_purchase_orders_open()` `awaiting_ack`),
`today` (`fulfilment_today()` summarized: per location and delivery method the orders and lines), `my_orders` (`orders.write`: `my_open_orders()` with
`order_next_step()`), `warehouse` (`stock.receive|stock.ship|stock.count`: `to_receive`, `to_pick`, `to_count`), `admin` (`settings.manage|feed.keys|
agents.settings`: `feed` from `key_usage_summary()` (slice 7's), `dispatches` counts), `bell` (the five newest unread of `mcp_notifications` — Phase 2's
`find_my_notifications()`), `may` (the rights the shell already computes). Phase 2's `html/index.php` (the shell's home with its regions null and named)
is **replaced** by this slice's real one; the JSON answers the same keys. Home logs `screen.view` and writes nothing else.

## The trail — `activity_sentence(array $r): string`
One sentence per event of the manifest (its "Log events" list and the tool surface's payload rules) and of the worker, the doors, the feed and the kit:
every `entity.verb` the registry knows (`mcp/action_registry.json`'s actions' `log` column, read at build time by the proof to assert coverage) plus
`source.pull_start|pull_done|pull_fail|pull_blocked|probe|search`, `listing.new|changed|removed`, `offer.change`, `watch.fire`, `agent.dispatch|reply|
fail`, `feed.read|rate_limited`, `share.read`, `member.sign_on|sign_on.refused|refused`, `directory.sync`, `notification.send|skip|fail`, `worker.pass`,
`screen.view`, `order.customer_view`, `purchase_order.supplier_view|supplier_ack|supplier_decline|supplier_tracking` — none answers "unknown"; an
event outside the list reads "<actor> did <action> on <entity>" and the proof lists it as a gap. The sentence names the record by its number, SKU,
name or label from `after` (the tool surface's payload rules put them there), never a value the rules forbid. `activity_record_link(array $r): ?array`
(`['href', 'label']`) from `entity_type`/`entity_id` and the audit keys (`sales_order_id` → `/orders/{id}`, `purchase_order_id` → `/purchasing/{id}`,
`source_id` → `/sources/{id}`, `location_id` → `/locations/{id}`, `token_id` → `/admin/feed-keys/?key=`, a product/variant/customer/return/listing by
entity). Phase 2's `html/trail.php` skeleton (if built) is replaced by this slice's whole page.

## Files (exactly these)
- `html/index.php` (`home`, real) · `app/features/home/{queries,present}.php` · `app/views/home/{dashboard,partials/note,partials/at-risk,partials/sources,partials/unmatched,partials/po-ack,partials/today,partials/my-orders,partials/warehouse,partials/admin,partials/bell}.php`
- `html/trail.php` (`trail`) · `app/features/activity/{queries,present}.php` (`activity_sentence()`, `activity_record_link()`) · `app/views/activity/{trail,partials/rows}.php`
- `html/reports/index.php` (`report-list`) · `html/reports/report.php` (`report`) · `html/reports/run.php` (`report_run`) · `app/features/reports/{queries,present,csv}.php` · `app/views/reports/{index,report,partials/form,partials/results,partials/row-stock-value,partials/row-sell-through,partials/row-sales,partials/row-price-exceptions,partials/row-source-health,partials/row-lead-times}.php`
- `html/exports/index.php` (`export-list`) · `html/exports/download.php` (`export_download`) · `app/features/exports/{queries,present,documents,csv}.php` · `app/views/exports/{index,partials/card}.php`
- `html/admin/settings.php` (`admin-settings` GET, `settings_save` POST) · `html/admin/sequences.php` (`sequence-list` GET, `sequence_set` POST) · `html/admin/tax-rates/index.php` · `form.php` · `save.php` · `archive.php` · `html/admin/reason-codes/index.php` · `form.php` · `save.php` · `html/admin/agents.php` (`agent-list`)
- `app/features/admin/{queries,present,write,handler}.php` (`handler.php`: the gate `require_right('settings.manage')` / `sequences.manage`, the field readers for the settings' groups) · `app/features/agents/queries.php` — slice 8's file **gains** `agents_here()` (one function appended; nothing else changes)
- `app/views/admin/{settings,sequences,agents}.php` · `app/views/admin/tax-rates/{index,form}.php` · `app/views/admin/reason-codes/{index,form}.php` · `app/views/admin/partials/{settings-group,sizes-table,attributes-table,sequence-row,tax-rate-row,reason-code-row,agent-card}.php`
- `deploy/apache-inventory.conf` and `tests/dev_router.php` — the `/reports/{report}` rewrite (DECISION 3), one line each
- `html/settings/index.php`, `html/settings/tokens/*.php` — Phase 2's, untouched; proven here for every role
- `tests/phase3/slice9/{run.sh,lib.php,home.php,trail.php,reports.php,exports.php,settings.php,tables.php,agents.php,json.php,browser.mjs}`

## Query functions (signatures fixed; PDO first)
- `home_summary(PDO, int $memberId): array` · `my_open_orders(PDO, int $memberId, int $limit = 10): array` (`mcp_sales_orders`) · `order_next_step(PDO, array $order): string` (`mcp_sales_order_lines`) · `warehouse_block(PDO): array` (`['to_receive' => [...], 'to_pick' => [...], 'to_count' => [...]]`) · `admin_block(PDO): array` (`['feed' => …, 'dispatches' => {sent, running, awaiting_approval, failed}]`)
- `fulfilment_today(PDO, ?string $day = null, ?int $locationId = null): array` (`inv_fulfilment_today(day, location)` grouped as the tool surface: `{day, groups: [{location_id, location_name, delivery_method, orders: [{…, lines: [...]}]}], dropships_expected: [...]}` — the drop-ships from `inv_purchase_orders_open()` kind `dropship` with `expected_on = day`; DECISION 1) — slice 5's, in `app/features/orders/queries.php` (reconciled 2026-10-05)
- `stock_value(PDO, ?string $asOf = null, string $by = 'location'): array` (`['rows', 'totals']`) · `sell_through(PDO, int $days = 30, string $by = 'variant'): array` · `sales_summary(PDO, string $from, string $to, string $by = 'brand'): array` (`inv_sales_summary`) · `price_exceptions(PDO, array $kinds = [], ?int $brandId = null): array` · `source_health(PDO, array $healths = [], ?string $role = null): array` · `source_reliability(PDO, int $days = 30): array` (keyed by `source_id`) · `lead_time_actuals(PDO, ?int $supplierId = null): array` · `catalog_gaps(PDO, ?string $gap = null, ?int $brandId = null): array` (`inv_catalog_gaps()` filtered; DECISION 1 — slice 1's, in `app/features/catalog/queries.php`, reconciled 2026-10-05) · `report_spec(string $report): ?array` (the fields, the function, the columns, the default `by`) · `report_rows(PDO, string $report, array $params): array` (`['rows', 'totals', 'cost_withheld', 'truncated']`) · `report_csv(string $report, array $result): string`
- `export_document(PDO, string $export, array $params): array` (the document: the share's, or the two local ones) · `catalog_export_rows(PDO): array` · `listings_export_rows(PDO, ?int $sourceId): array` · `document_csv(string $export, array $doc): string` · `export_may(string $export): bool` (DECISION 5)
- `find_settings(PDO): array` (`inv_settings`, the one row, as the writer) · `save_settings(PDO, array $fields, int $by): array` (`['changed' => [...]]`) · `settings_groups(): array` (the seven groups and their columns, in order) · `size_keys_in_use(PDO): array` (`size_key` → count) · `find_sequences(PDO): array` · `set_sequence(PDO, string $kind, array $fields, int $by): array` · `find_tax_rates(PDO, bool $includeArchived = true): array` (with `used_by`) · `find_tax_rate(PDO, int $id): ?array` · `save_tax_rate(PDO, ?int $id, array $fields, int $by): int` · `archive_tax_rate(PDO, int $id, int $by): void` · `find_reason_codes(PDO, bool $includeInactive = true): array` · `find_reason_code(PDO, int $id): ?array` · `save_reason_code(PDO, ?int $id, array $fields, int $by): int` · `code_from_name(string $name): string`
- `agents_here(PDO): array` (DECISION 8: the declared agents matched, the rest after; each `{member_id, display_name, job_title, declared_key, roles, hired, duty, skills, last_action: {action, occurred_at, sentence}, last_dispatch: {status, created_at}, dispatches_pending, dispatches_failed, proposals_today}`) · `declared_agents(): array` (from `maludb-os.json`)
- `find_my_activity(PDO, int $memberId, array $filters, int $page = 1): array` · `find_record_activity(PDO, array $keys, array $filters, int $page = 1): array` · `activity_sentence(array $r): string` · `activity_record_link(array $r): ?array` · `activity_actor(array $r): string`

## Handlers (every one: `inv_handler_begin()`; the gate; `inv_guard()`; `log_activity`; `emit_action_status()`; `inv_done()`; `HX-Trigger`)
- `reports/run.php` (`report_run`): `reports.read`; `report.run`; html → the results partial (no location); csv → the download; JSON → the rows; no `HX-Trigger`.
- `exports/download.php` (`export_download`): DECISION 5's gate; `export.download`; the download, or JSON (DECISION 4).
- `admin/settings.php` (`settings_save`): `settings.manage`; `settings.save`; **other**; `settingsChanged`. `admin/sequences.php` (`sequence_set`): `sequences.manage`; `sequence.set`; **other**; confirm. `admin/tax-rates/save.php` (`tax_rate_save`): `settings.manage`; `tax_rate.save`; **other**. `admin/tax-rates/archive.php` (`tax_rate_archive`): `settings.manage`; `tax_rate.archive`; confirm. `admin/reason-codes/save.php` (`reason_code_save`): `settings.manage`; `reason_code.save`.
- The screens: `home` and `trail`: `require_login()`; `report-list`, `report`, `export-list`: `reports.read`; `admin-settings`, `tax-rate-*`, `reason-code-*`: `settings.manage`; `sequence-list`: `sequences.manage`; `agent-list`: `agents.settings`; `log_screen_view()` on each (`report` with `after.report` and the params; `trail` with the record keys; `home` nothing more).

## Manifest rows claimed
Screens (14): `home`, `trail`, `report-list`, `report`, `export-list`, `admin-settings`, `sequence-list`, `tax-rate-list`, `tax-rate-add`, `tax-rate-edit`, `reason-code-list`, `reason-code-add`, `reason-code-edit`, `agent-list`
Actions (7): `settings_save`, `sequence_set`, `tax_rate_save`, `tax_rate_archive`, `reason_code_save`, `report_run`, `export_download`

| Kind | Rows | Section of the manifest |
|---|---|---|
| Screens (14) | `home` (real), `trail` (whole) | Home, me and the shell (Phase 2 and slice 9) |
| | `report-list`, `report`, `export-list`, `admin-settings`, `sequence-list`, `tax-rate-list`, `tax-rate-add`, `tax-rate-edit`, `reason-code-list`, `reason-code-add`, `reason-code-edit`, `agent-list` | Reports, home, admin and tokens (slice 9) |
| Actions (7) | `settings_save` (**other**), `sequence_set` (**other**), `tax_rate_save` (**other**), `tax_rate_archive`, `reason_code_save`, `report_run`, `export_download` | Reports, home, admin and tokens (slice 9) |
| Left to slice 8 (`returns-worker.md`) | `dispatch-list` (screen) | Reports, home, admin and tokens — the retry action and the dispatches pass are slice 8's; a slice ships whole |
| Left to slice 7 (`feed.md`) | `connection-list` (screen) | Reports, home, admin and tokens — "who outside reads us" is one subject with the feed's keys (design §10) |
| Left to the shell (Phase 2, `sso-shell.md`) | `notifications`, `my-settings`, `tokens` (screens); `prefs_save`, `notification_read`, `token_mint`, `token_revoke` (actions) | Home, me and the shell — the shell's in every sibling (DECISION 9); this slice proves `tokens` for every role and changes nothing of them |
Every row of the manifest's slice 9 section is accounted for: twelve claimed here, two left to slices 8 and 7 by name. `PARTIAL_UPDATE_TARGETS` gains
`/admin/settings.php => ['inv_settings', '_settings', 'inv_settings', 'id']` (DECISION 12), `/admin/tax-rates/save.php => ['tax_rates', 'tax_rate',
'mcp_tax_rates', 'tax_rate_id']`, `/admin/reason-codes/save.php => ['reason_codes', 'reason', 'mcp_reason_codes', 'reason_code_id']`.

## Activity log events
`settings.save` (the fields changed; `crawl_user_agent` as "changed"), `sequence.set` (`kind`, `before`/`after`), `tax_rate.save`, `tax_rate.archive`,
`reason_code.save`, `report.run` (`report`, `params` — never rows), `export.download` (`export`, `format`, `rows`, `period`, `source_id`), `screen.view`
(`home`, `trail` with the record keys, every report and admin page). Nothing else: the Agents page posts nothing; Home writes nothing.

## Notifications this slice queues
None.

## The 375 px rule
Home's regions stack in the phone order, each a card as tall as its content, the counts as 44 px chips; a report's filter form collapses into the
page header's offcanvas on a phone and the results table scrolls inside `.table-responsive` (never the page); the exports as cards; the settings'
seven groups as stacked cards with the pinned Save; the admin tables as cards under 992 px; the agents as cards everywhere; `scrollWidth` = viewport.

## Status vocabulary
Home: a late order `danger`, a line at risk `danger`, a failing source `warning`, a blocked or paused source `danger`, a PO awaiting ack past
`ack_days` `warning`, today's lines `info`, a dispatch failed `danger`, awaiting approval `warning`. Report health chips: ok `success`, stale
`warning`, failing `warning`, blocked `danger`, paused `secondary`, manual `secondary`, never_pulled `dark`, inactive `secondary`; a margin below 0
`danger`, weeks of cover under 2 `warning`; a price exception `retail_under_map` `danger`, `reference_undercut` `warning`, `cost_moved` `info`.
Admin: the default tax rate `success`, archived `secondary`; a reason inactive `secondary`; an agent hired `success`, not hired `secondary`. Ids:
`home-note`, `home-note-{heading}`, `home-at-risk`, `home-at-risk-row-{line_id}`, `home-sources`, `home-unmatched`, `home-po-ack`, `home-today`,
`home-my-orders`, `home-my-order-{id}`, `home-warehouse`, `home-admin`, `home-bell`, `trail-form`, `trail-results`, `trail-row-{activity_id}`,
`report-card-{report}`, `report-{report}`, `report-form`, `report-form-field-{name}`, `report-form-run-btn`, `report-form-csv-btn`, `report-results`,
`report-results-table`, `report-row-{n}`, `report-cost-withheld`, `export-list`, `export-card-{export}`, `export-card-{export}-field-{name}`,
`export-card-{export}-{csv|json}-btn`, `admin-settings-form`, `admin-settings-group-{group}`, `admin-settings-field-{column}`,
`admin-settings-sizes-table`, `admin-settings-attributes-table`, `admin-settings-form-save-btn`, `admin-settings-form-cancel-btn`,
`sequence-list-table`, `sequence-row-{kind}`, `sequence-row-{kind}-form`, `sequence-row-{kind}-save-btn`, `tax-rate-list-table`, `tax-rate-row-{id}`,
`tax-rate-row-{id}-{edit|archive}-btn`, `tax-rate-list-add-btn`, `tax-rate-form`, `tax-rate-form-field-{name}`, `tax-rate-form-save-btn`,
`reason-code-list-table`, `reason-code-row-{id}`, `reason-code-row-{id}-edit-btn`, `reason-code-list-add-btn`, `reason-code-form`,
`reason-code-form-field-{name}`, `reason-code-form-save-btn`, `agent-list`, `agent-card-{member_id}`, `agent-card-declared-{key}`,
`agent-list-os-link`, `nav-home`, `nav-trail`, `nav-report-list`, `nav-export-list`, `nav-admin-settings`, `nav-sequence-list`, `nav-tax-rate-list`,
`nav-reason-code-list`, `nav-agent-list`.

## Vocabulary
A **report** is one of the seven db/014–016 functions presented with filters; its **CSV** is the same rows; an **export** is a versioned document
downloaded — the three **accounting files** are the ledger's shares (`os.inventory-sales/1`, `os.inventory-purchases/1`, `os.inventory-valuation/1`)
read by the same functions the kernel reads; **the wall** is `inv_sees_cost()` — a report below it shows units and revenue and says "cost withheld";
a **setting** is a column of the one `inv_settings` row; a **sequence** numbers one document kind and only rises; the **Buyer** (the setting) is the
person the morning note goes to; the **trail** is the activity log read as sentences — a record's history by its audit keys; **next step** is what
an order waits for, said in a verb.

## Out of scope for this slice
The slices' own actions linked from Home and the admin pages (1–8); the feed keys, price lists and the Connections page (slice 7); the dispatches
page and the retry (slice 8); the bell, prefs and tokens (Phase 2 — proven here, not built); the MCP tools over these functions (`stock_value`,
`sell_through`, `sales_summary`, `fulfilment_today`, `lead_time_actuals`, `price_exceptions`, `source_health`, `catalog_gaps`, `agents_here`,
`get_settings` — Phase 4); hiring, grants, duties and connection approval (the kernel's); a kernel agents endpoint (an open item of Consultant
Tracking's — not assumed); charts on the reports (the price and availability chart is slice 3's listing view; the reports are tables in v1);
demand forecasting (Extended).

## Proof (`tests/phase3/slice9/run.sh`: the scratch database `inv_dev9`; the worlds of slices 1–8 composed by `lib.php` `admin_world()` (the fixtures of the earlier proofs' `lib.php` files called in order); the app on 8607; the fake kernel on 8602; members through `bin/dev_handoff.php`; curl with signed action and run tokens; headless Chromium at 375 × 740 and 1280 × 800; `bin/build_action_registry.php --check` reads **every** screen and action of the manifest as built (108 / 132 — nothing a stub); `bin/sync_approvals.php --check`; ≥ 150 checks)
- [ ] **Home (`home.php`, ≥ 36)**: Nora's (Buyer) home: the seven counts matching the seven functions, each heading linking where the table says,
  the at-risk rows naming SO-1 and why, the failing source, the five unmatched, the PO awaiting ack, today's deliveries by store; Sam's: the note without
  `reorder`, `prices`, `unmatched` (withheld), his three open orders with the next step in words ("Confirm", "Record a deposit", "Waiting on Malouf",
  "Late by 2 days" first), no warehouse or admin block; Wes's: to receive (the draft receipt and the stock PO due), to pick (the allocated Queen at the
  warehouse), to count (the open count); the admin's: the feed's calls today and the dispatch counts, the bell's five; Vera's: the note's reader headings
  (at risk, sources, POs, returns), nothing else; an external Viewer (Ann) the same; the JSON answers the same keys per role; Home writes only
  `screen.view`; a region's empty state names what will appear.
- [ ] **The trail (`trail.php`, ≥ 14)**: Sam's own rows newest first with sentences; `?order=1` → every row with `sales_order_id = 1` (the quote, the
  confirmation, the return's rows); `?source=` and `?purchase_order=`; `?variant=` by entity; `?member=<Nora>` by Sam → 403 in words, by Nora herself → her
  rows, by the admin → hers; `?member=<the expert>` by Sam → the agent's rows (any reader); the filters `action=order.` and `period=1`; **every action of
  the registry and every worker/door/feed event has a sentence** (a fixture row per event; none reads "did <action>"); every row's link resolves to a
  page that answers 200 (the links of twenty sampled rows fetched).
- [ ] **Reports (`reports.php`, ≥ 36)**: `stock-value` by location → the warehouse's units and value as Nora, `value null` and the note as Sam, `by variant`
  and `by brand`; `sell-through` 30 days by variant → units, revenue, COGS, margin, weeks of cover for the shipped Queen; `sales` this month by brand →
  the confirmed orders' revenue and tax, `by day` and `by week` (db/016), `by salesperson` naming Sam; `margin` → COGS first and "cost withheld" for Sam;
  `price-exceptions` → the three kinds and the `kind[]` filter; `source-health` → Malouf `ok`, the blocked reference, the reliability window's pulls and
  success % from the log rows; `lead-times` → Malouf's promised vs actual from the PO events; a bad `by` → 422 naming `by`; a `report` outside the seven →
  404; `format=csv` → a download with the BOM, the headed columns and two-decimal amounts; `report.run` logged with the params and never rows; a Viewer →
  403 (`reports.read`); the GET with query-string values renders filled.
- [ ] **Exports (`exports.php`, ≥ 20)**: `sales_closed` CSV for September → one row per line of SO-2 with the payments by method and COGS, the JSON = the
  share's document; `purchases_received` → the receipt's and the drop-ship's rows, no ship-to; `stock_valuation` as of today by brand; a 93-day period →
  422 before the function; the catalog CSV with every variant and its identifiers, cost present for Nora and absent for Sam; the listings CSV for Malouf
  with the matched SKU and cost nulled for Sam; Sam exporting `sales_closed` → 403 "carry cost" (DECISION 5); the admin and Nora → 200; `export.download`
  logged with `rows` and `period`; `Content-Disposition` names the file; JSON mode (an agent) → `{ok, rows, bytes, period, location}` and no file.
- [ ] **Settings (`settings.php`, ≥ 28)**: every column of `inv_settings` saved through the form and read back (a value per column, the row compared);
  `order_link_days` 0 → 422 naming the field (PHP's bound, the CHECK never reached); `currency` `usd` → upper-cased; `timezone` `Mars/Olympus` → 422;
  `sizes` with a duplicate key → 422, with a synonym shared by two sizes → 422, removing `queen` while variants use it → 422 "used by 6 variants";
  `attribute_keys` with a `choice` kind and no choices → 422; `crawl_user_agent` without `(+` → 422 "an honest user-agent names the business and a contact";
  `crawl_backoff_minutes` `[60, 30]` → 422 "rising"; `max_attachment_bytes` 25 MB → 26214400 in the row; `buyer` = Sam → `buyer_member_id` 41, an agent →
  422, empty → NULL; a field left out stays (`req_has()`); `settings.save` logged with the changed fields only, `crawl_user_agent` as "changed"; a Buyer →
  403; the agent's `settings_save` carries `other` in the registry; `settingsChanged` triggered.
- [ ] **Sequences, tax rates, reason codes (`tables.php`, ≥ 24)**: `sequence_set` raising `sales_order` to 100 → the next order is `SO-00100`; lowering
  → 422 "never reused"; a prefix `so-` → 422; padding 13 → 422; Nora (no `sequences.manage`) → 403; a new tax rate "Cook County 10.25 %" made default →
  the old default cleared in the same transaction, one default remains; a duplicate name → "already taken"; archiving the default → 422 "Make another
  rate the default first"; archiving the other → gone from the live list, an order naming it keeps it; editing an archived rate → 422; a reason code
  "Water damage" → `water_damage`, applies to return, shown in slice 8's return form; a code `Damaged` again → 422 "already used"; editing a seeded code
  keeps its code (the form's read-only field; a posted `code` ignored and said so in `did`); no `applies_to` → 422; the inactive reason leaves the picker
  and stays on old lines.
- [ ] **Agents (`agents.php`, ≥ 8)**: the two declared agents matched to the mirror (the expert hired, the Buyer "not hired here yet" until the fixture
  adds member 47 as "Stock Buyer"), their roles, the duty in words ("every day at 06:30"), the skills; the last action from the log and the last dispatch
  from slice 8's rows; the pending/failed counts; the OS links' host `os.`; no form on the page; Nora → 403; the admin → 200.
- [ ] **Tokens, for every role (≥ 10)**: Sam, Nora, Wes, Vera and the admin each mint a token (shown once), the list shows it, revoke works; an agent
  (run token) → 403 on mint; a revoked token refused by `mcp_resolve_token()`; `token.mint`/`token.revoke` logged without a value — Phase 2's handlers,
  proven whole here.
- [ ] **JSON mode (`json.php`, ≥ 12)**: `settings_save` under a signed action token with `_partial=1` keeps every other column; `sequence_set`,
  `tax_rate_save`, `reason_code_save`, `report_run`, `export_download` answer their facts; a 422 answers `{error: {code: invalid, fields}}`; the home and
  trail answer `data`; the registry's `--check` reads 108 of 108 screens and 132 of 132 actions built; `sync_approvals --check` in sync (26).
- [ ] **375 × 740 and 1280 × 800 (`browser.mjs`, ≥ 20)**: Home's regions in the phone order for Sam and Wes; a report's filters in the offcanvas at 375
  and the results table scrolling inside its card; Run through HTMX replacing `#report-results`; the exports' cards; the settings' seven groups and the
  pinned Save, the sizes table rendering from the textarea; the sequences' inline forms; the tax-rate and reason-code forms; the agents' cards; every
  control ≥ 44 px, `scrollWidth` = viewport, no console errors; JavaScript off: the settings form saves.

## Built and proven
Not built.

## Decisions recorded (the DECISIONs above, in one place)
1. `catalog_gaps()` (slice 1) and `fulfilment_today()` (slice 5) are written by their screens' slices at the signatures fixed here; this slice calls them (reconciled 2026-10-05).
2. `margin` is the `sales` report with the cost columns first — one function, one screen.
3. The `/reports/{report}` rewrite is added to the vhost and the dev router by this slice.
4. `export_download` in JSON mode answers the facts, never the file.
5. The three accounting files need `exports.all`, or `reports.read` with `sees_cost()`; the two others `reports.read`.
6. The settings form reads `inv_settings` as the writer; `mcp_settings` lacks `business_address` and `raw_max_bytes` (reported to the tool surface).
7. A reason code's `code` is fixed after creation.
8. The Agents page asks the kernel nothing; declared agents are matched by `display_name`, else `job_title`.
9. `my-settings`, `tokens`, `notifications` and their four actions are the shell's (Phase 2); this slice proves tokens for every role.
10. The catalog and listings downloads are versioned locally as `os.inventory-catalog/1` and `os.inventory-listings/1` — download documents, not shares.
11. A size key in use by a variant cannot be removed from the settings.
12. The settings' partial-update target reads `inv_settings` itself (the view has no `id`); a missing `_settings` means row 1.
13. Making a tax rate the default clears the previous default in the same transaction, before the write.

## Open questions
(none)

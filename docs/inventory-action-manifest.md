# Inventory Action Manifest

2026-10-05 · **Phase 1, for the checkpoint**

> The registry the command bar (through the kernel's chat endpoint), the kernel's Actions MCP and the screens resolve against.
> A screen or action missing here cannot be reached by voice or by an agent — unfinished design, like a question with no tool.
> Pairs with `db/` (tables), `docs/inventory-mcp-tool-surface.md` (read tools), `docs/build-specs/connectors.md` and the slice
> specs in `docs/build-specs/`. Generated into `mcp/action_registry.json` by `bin/build_action_registry.php`; an action whose
> file does not exist yet is registered but not exposed. The approval categories here ARE `maludb-os.json` `approvals[]`
> (`bin/sync_approvals.php` rewrites that block from this file; `--check` in every proof).

## Conventions

### Screens
- **Ids** are kebab-case `{entity}-list`, `{entity}-add`, `{entity}-view`, `{entity}-edit`, plus named screens.
- **Canonical URLs** need the rewrites in `deploy/apache-inventory.conf`: `/{x}/new` → `form.php`, `/{x}/{id}/edit` → `form.php?id=`,
  `/{x}/{id}` → `view.php?id=`, `/{x}/{id}/{page}` → `{page}.php?id=`, `/reports/{report}` → `report.php?report=`, the two doors
  `/o/{token}` → `o.php` and `/s/{token}` → `s.php`. A path parameter is written by its entity's name (`/products/{product}`,
  `/orders/{sales_order}`, `/purchasing/{purchase_order}`); every record is bigint-keyed. **Find, an order and receiving are designed
  at 375 px first** (a salesperson on the floor, a warehouse hand with a phone); the catalog, sources and reports at 1280; no screen is
  a modal; records get full-page create/edit; named things (products, sources, suppliers, customers) are cards; levels, movements,
  listings, lines and admin lists are tables.
- **Prefill params** become query-string values the GET controller reads.
- **Every screen partial stamps** `data-screen`, `data-entity`, `data-record-id` on `#page-content`, so "this order", "sell this" and
  "watch that" resolve against the page the user is on.
- **The two doors** (`/o/{token}` the customer's order page, `/s/{token}` the supplier's purchase-order page) are screens with no
  session; the supplier's three POSTs are **not actions** (no agent reaches them — the token is the authority, `source = portal`); they
  are listed apart at the end. The availability feed (`GET /api/v1/availability`, a feed key) is an endpoint, not a screen.

### Actions
- **Name** `{entity}_{verb}`; the **log event** `{entity}.{verb}` is what the handler writes with `log_activity()` (with `source_id`,
  `sales_order_id`, `purchase_order_id` as the record says — the audit keys of `activity_log`) and what the kernel's approval policies
  match — so an action an agent's call must pause on has **its own log event**. The events are design §6's; the ones this manifest
  adds beyond that list (line-level edits of a document, a draft's header, a link rotation, the admin's small tables, notes and
  attachments, a person's own preferences) are named on the same `entity.verb` rule and listed in "Size" at the end.
  `.delete` destroys; `.archive` keeps a record out of the way, readable; `.cancel` ends a document that was never posted or an order
  that will not be filled; `.forget` drops a listing's match and hides it from the queue (the listing itself stays until its source
  removes it).
- **Endpoints** are POST to `/{base}/{file}` (a file written absolutely names its own directory) with `require_post()`, `verify_csrf()`
  (or the relayed action token), the right (`require_right()`), the record's reach and `log_activity()`. **A write calls the database's
  verb** (`inv_order_confirm()`, `inv_po_send()`, `inv_post_receipt()`, `inv_listing_match()`, `inv_price_set()`, …) and shows its
  sentence as a field error; PHP never re-implements a rule the database keeps (a balance that would go negative, a match across sizes,
  a line changed after posting, a credential read back). Handlers report through `emit_action_status()`; a create's `location` ends in
  the new record's id and the reply carries `record_id`.
- **Params:** **bold** = required; `[]` = repeated; parentheses = a hint (no comma or semicolon inside; no note after the last
  parameter). A param named for a record (`product`, `variant`, `component`, `bundle`, `location`, `from_location`, `to_location`,
  `supplier`, `source`, `listing`, `listing_variant`, `customer`, `order`, `purchase_order`, `member`, `salesperson`, `agent`,
  `department`) accepts an id or a name, resolved through the application's own tool (`deploy/kernel-registry-inventory.json`'s resolve
  block, the tool surface's resolution table); a variant also accepts its SKU or GTIN, an order or a purchase order its number, a
  customer their email. Every other record param (`receipt`, `adjustment`, `transfer`, `count`, `shipment`, `line`, `return`,
  `proposal`, `watch`, `key`, `price_list`, `brand`, `product_type`, `tax_rate`, `reason`, `note`, `attachment`, `transaction`,
  `template`, `dispatch`, `token`) is an id (or the document's number where it has one). Dates are `YYYY-MM-DD`; money is decimal in the
  settings' currency; quantities are whole numbers; weights are grams and dimensions millimetres unless the settings say imperial;
  `lines` on a document is JSON (`[{variant, qty, …}]`) when an agent sends several at once. `any field of X` = X's fields, all
  optional, a partial update.
- **Who** is the right of design §3 enforced in the endpoint (`inv_has_right()`): `inventory.read`, `orders.write`, `payments.record`,
  `customers.write`, `orders.send`, `watches.own`, `notes.write` (Sales); `stock.receive`, `stock.adjust`, `stock.transfer`, `stock.count`,
  `stock.ship`, `returns.receive` (Warehouse); `catalog.write`, `prices.write`, `sources.write`, `sources.credentials`, `listings.match`,
  `purchasing.write`, `suppliers.write`, `returns.write`, `watches.all`, `reports.read` (Buyer); `settings.manage`, `feed.keys`,
  `exports.all`, `agents.settings`, `records.delete`, `sequences.manage` (admin). `own` = the record's own person (their watch, their
  note, their tokens, their preferences). A super-admin holds every right. An agent is granted like a person; `is_eval` refuses every
  write. **Cost is the wall**: a param or a reply carrying cost is withheld from anyone `inv_sees_cost()` refuses.
- **Undo** is the inverse `undo_last` applies; — means it cannot be undone and the reply says so (a posted movement is reversed by
  `stock_reverse`, never undone).
- **Confirm (✔)** = the command bar asks first (destructive, reaches a customer or a supplier, commits money, or changes stock
  with no document).
- **Agent approval** names the kernel's category that pauses the action when an **agent** performs it (design §5, D13):
  `money_out` (sending or placing a purchase order — a commitment to pay a supplier), `external_send` (content leaves the business:
  the order confirmation and its link, a notice to a customer, a message to a supplier, a feed key for an outsider), `other` for the
  policy defaults that pause (confirming an order, recording a payment or a refund, authorizing a return) and for configuration
  (a price, a source, its credential, its schedule, the settings, a sequence, a tax rate), `deletion` (deleting, cancelling an order
  or a purchase order, forgetting a listing, adjusting stock with no document, posting a count). People are never paused. The
  categories are exactly `maludb-os.json` `approvals[]` — 26 actions.
- **Refresh:** a data action returns `HX-Trigger: {entity}Changed` (camelCase entity).
- **Log payloads** (what `before`/`after` must carry) are in the tool surface; **a credential, a customer's address and phone, a
  source's raw object and a secure token are never in a payload** (ids, names, SKUs, counts, states and amounts are).

### Result contract
```json
{"status": "success", "did": "Confirmed SO-00042 for Alvarez: 1 line allocated at Warehouse, 1 drop-ship PO drafted (Malouf)", "record_id": 42, "undo_id": "act_31", "refresh": "orderChanged"}
{"status": "pending_approval", "did": "Sending PO-00017 to Malouf waits for a person's approval", "approval_request_id": 17}
{"status": "error", "message": "Warehouse cannot cover 2 × ZN-12-Q (1 on hand, 1 allocated) — make the line a backorder or choose a source."}
```

## Home, me and the shell (Phase 2 and slice 9)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `home` | `/` | home: the morning note's seven headings each a count that opens its list; lines at risk; pulls failed or blocked; unmatched listings; purchase orders awaiting acknowledgment; today's deliveries and pickups; for Sales my open orders and their next step; for Warehouse to receive, to pick, to count; for the admin feed usage and dispatches |
| `notifications` | `/notifications` | what the bell holds (a watch fired, a line at risk, a pull failed, a supplier's acknowledgment or tracking, a return, the morning note), and to mark it read |
| `trail` | `/trail` | my own activity trail, or a record's history (params: `product`, `variant`, `source`, `order`, `purchase_order`, `member`) |
| `my-settings` | `/settings/` | how I am told (email, a text for the watches I chose, which kinds), my time zone |
| `tokens` | `/settings/tokens/` | my own MCP tokens: mint one, revoke one |

Reconciled 2026-10-05: `home` and `trail` are slice 9's rows (reports-admin.md — Phase 2 renders their placeholders); `notifications`, `my-settings`,
`tokens` and the four actions below are Phase 2's (sso-shell.md).

Actions (base `/settings/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `prefs_save` | `prefs.php` | email_enabled (yes or no), text_enabled (yes or no), kinds[], text_kinds[], timezone | restore prior | | | `prefs.save` | own |
| `notification_read` | `notifications/read.php` | notification (empty marks all) | — | | | `notification.read` | own |
| `token_mint` | `tokens/mint.php` | **label** | token_revoke | | | `token.mint` | own |
| `token_revoke` | `tokens/revoke.php` | **token** | — | ✔ | | `token.revoke` | own |

## The catalog (slice 1 — the CRUD pattern)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `product-list` | `/products/` | the products as cards by brand and type with their variant count, stock and best offer; filters (params: `q`, `brand`, `type`, `status`, `kind`) |
| `product-add` | `/products/new` | to make a product: brand, type, name, description, attributes, kind (single or bundle), options (Size first), how it ships, the reorder default (params: `brand`, `type`) |
| `product-view` | `/products/{product}` | one product: the variants table with stock, best offer and the three prices; identifiers; bundle components; images; the sources listing it; price history; notes; attachments; the trail (params: `tab`) |
| `product-edit` | `/products/{product}/edit` | to change a product's brand, type, name, description, attributes, options, how it ships, reorder default, status |
| `product-images` | `/products/{product}/images` | the product's images: upload, order, the primary one, alt text; per variant when a size looks different |
| `variant-add` | `/variants/new` | to add a variant: SKU, option values (Size …), barcode, MPN, weight and dimensions, how it ships, retail, MAP and cost, reorder point and quantity (params: `product`) |
| `variant-view` | `/variants/{variant}` | one variant: own stock by location, every source's current offer ranked, the price history chart, identifiers, bundle components, open order and purchase-order lines, watches (params: `tab`) |
| `variant-edit` | `/variants/{variant}/edit` | to change a variant's SKU, options, barcode, MPN, dimensions, how it ships, reorder point; the prices are set on the variant with a reason |
| `variant-identifiers` | `/variants/{variant}/identifiers` | the codes sellers use for this variant (GTIN, UPC, EAN, MPN, ASIN, eBay EPID, Walmart item id, a supplier's SKU per source); add one, remove one |
| `variant-bundle` | `/variants/{variant}/bundle` | the bundle editor: the component variants and their counts (resolved by size), the availability the components give |
| `brand-list` | `/brands/` | the brands with their product counts and the dealer program they map to (params: `q`) |
| `brand-add` | `/brands/new` | to add a brand: name, website, the supplier that is its dealer program |
| `brand-edit` | `/brands/{brand}/edit` | to change a brand's name, website, supplier, active |
| `product-type-list` | `/product-types/` | the product types (Mattress, Foundation, …) in order; add or rename one |
| `catalog-gaps` | `/catalog/gaps` | what the catalog is missing: variants with no GTIN, no retail, no cost, no dimensions, no image, no source; products with no variant (params: `gap`) |
| `catalog-import` | `/catalog/import` | to import the business's SKUs from a CSV (name, brand, type, size, SKU, GTIN, MPN, prices): a preview of the first rows, the column mapping, the result |

Actions (base `/products/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `product_create` | `save.php` | **name**, **type** (a product type), brand, description, attributes (JSON by attribute key), kind (single or bundle — single by default), options[] (the option names — Size first), ships_how (parcel or ltl or white_glove or pickup_only), reorder_point, tags[], status (draft or active — draft by default) | product_delete | | | `product.create` | catalog.write |
| `product_update` | `save.php` | **product**, any field of product_create | restore prior | | | `product.update` | catalog.write |
| `product_discontinue` | `discontinue.php` | **product** (its variants stop selling — stock and history stay), discontinued (yes or no — no reactivates) | product_discontinue | ✔ | | `product.discontinue` | catalog.write |
| `product_delete` | `delete.php` | **product** (one with no stock movement and no order line and no match) | — | ✔ | deletion | `product.delete` | records.delete |
| `catalog_import` | `import.php` | **file** (multipart CSV), mapping (JSON column to field), update_existing (yes or no — matched by SKU or GTIN) | — | ✔ | | `product.import` | catalog.write |
| `variant_create` | `/variants/save.php` | **product**, **sku**, option_values (JSON — `{"Size":"Queen"}`), barcode (a GTIN — normalized), mpn, weight_g, length_mm, width_mm, height_mm, ships_how, retail_price, map_price, cost_price, reorder_point, reorder_qty | variant_delete | | | `variant.create` | catalog.write |
| `variant_update` | `/variants/save.php` | **variant**, any field of variant_create | restore prior | | | `variant.update` | catalog.write |
| `variant_delete` | `/variants/delete.php` | **variant** (one with no stock movement and no order line and no match) | — | ✔ | deletion | `variant.delete` | records.delete |
| `identifier_add` | `/variants/identifiers/add.php` | **variant**, **kind** (gtin or upc or ean or mpn or asin or ebay_epid or walmart_item_id or supplier_sku or other), **value**, source (the supplier source a supplier_sku belongs to) | identifier_remove | | | `variant.identifier_add` | catalog.write |
| `identifier_remove` | `/variants/identifiers/remove.php` | **identifier** | identifier_add | ✔ | | `variant.identifier_remove` | catalog.write |
| `bundle_set` | `/variants/bundle.php` | **bundle** (a bundle product's variant), **components** (JSON — a list of variant and qty — the whole list) | restore prior | | | `variant.bundle_set` | catalog.write |
| `image_add` | `images/add.php` | **product**, file (multipart image — required when image is absent), image (an existing image's id — with it and no file the alt text and primary and order are updated), variant (a size that looks different), alt_text, is_primary (yes or no), sort_order | image_remove | | | `product.image_add` | catalog.write |
| `image_remove` | `images/remove.php` | **image** | — | ✔ | | `product.image_remove` | catalog.write |
| `price_set` | `/variants/price.php` | **variant**, **kind** (retail or map or cost), **price**, reason | restore prior | | other | `variant.price_set` | prices.write |
| `brand_save` | `/brands/save.php` | **name**, website, supplier (the brand's own dealer program), active (yes or no), brand (to change an existing one) | restore prior | | | `brand.save` | catalog.write |
| `product_type_save` | `/product-types/save.php` | **name**, key (lowercase — from the name by default), sort_order, active (yes or no), product_type (to change an existing one) | restore prior | | | `product_type.save` | catalog.write |

Reconciled 2026-10-05: `image_add` takes `image` (an existing image's id) — with it and no `file` the alt text, the primary flag and the order are
updated (catalog.md); `file` is required only when `image` is absent.

## Locations and stock (slice 2)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `stock-levels` | `/stock/` | the levels: variant × location — on hand, allocated, floor models, available; filters; export (params: `location`, `brand`, `type`, `q`, `below_reorder`) |
| `movement-list` | `/stock/movements` | the movement ledger: every transaction with its type, document, counterparty, cost when permitted; filters (params: `variant`, `location`, `type`, `from`, `to`) |
| `floor-model-list` | `/stock/floor-models` | the floor models by location — on hand and flagged; take one in or out (params: `location`) |
| `location-list` | `/locations/` | the locations as cards: kind, department, sellable, allows negative, on-hand count |
| `location-add` | `/locations/new` | to add a location: name, kind (warehouse or showroom or store or in_transit or returns or offsite), address, department, sellable, allow negative |
| `location-view` | `/locations/{location}` | one location: its levels, recent movements, open transfers and counts (params: `tab`) |
| `location-edit` | `/locations/{location}/edit` | to change a location's name, kind, address, department, sellable, allow negative, active |
| `receipt-list` | `/receipts/` | the goods receipts by status with their supplier, purchase order and location (params: `status`, `supplier`, `location`) |
| `receipt-add` | `/receipts/new` | to receive: against a purchase order (its open lines prefilled) or free; a line per variant with qty and cost; a put-away location; discrepancies; **a barcode field that takes a scanner's keystrokes** (params: `purchase_order`, `supplier`, `location`) |
| `receipt-view` | `/receipts/{receipt}` | one receipt: lines, discrepancies, the movements it posted, attachments (a delivery note) |
| `receipt-edit` | `/receipts/{receipt}/edit` | to change a draft receipt's lines, costs, put-away and delivery-note reference |
| `adjustment-list` | `/adjustments/` | the adjustments by status with their location and reason (params: `status`, `location`, `reason`) |
| `adjustment-add` | `/adjustments/new` | to adjust: a location, a reason (damaged, found, lost, sample, donation, correction), lines with the quantity change and cost (params: `location`, `reason`, `variant`) |
| `adjustment-view` | `/adjustments/{adjustment}` | one adjustment: its lines and the movements it posted |
| `adjustment-edit` | `/adjustments/{adjustment}/edit` | to change a draft adjustment's reason, lines and notes |
| `transfer-list` | `/transfers/` | the transfers by status: from, to, lines, shipped and received when (params: `status`, `location`) |
| `transfer-add` | `/transfers/new` | to move stock between locations: from, to, lines (params: `from_location`, `to_location`, `variant`) |
| `transfer-view` | `/transfers/{transfer}` | one transfer: lines with sent and received quantities; Send, Receive |
| `transfer-edit` | `/transfers/{transfer}/edit` | to change a draft transfer's locations, lines and notes |
| `count-list` | `/counts/` | the counts by status and location with their corrections (params: `status`, `location`) |
| `count-add` | `/counts/new` | to start a count at a location (params: `location`) |
| `count-view` | `/counts/{count}` | the counting screen: the location's variants with the system quantity, type or scan the counted quantity; the differences; Post with corrections |

Actions (base `/stock/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `location_create` | `/locations/save.php` | **name**, kind (warehouse or showroom or store or in_transit or returns or offsite — warehouse by default), address, department, is_sellable (yes or no — yes by default), allow_negative (yes or no — no by default) | location_archive | | | `location.create` | settings.manage |
| `location_update` | `/locations/save.php` | **location**, any field of location_create | restore prior | | | `location.update` | settings.manage |
| `location_archive` | `/locations/archive.php` | **location** (one with nothing on hand or allocated), active (yes or no — no archives) | location_archive | ✔ | | `location.archive` | settings.manage |
| `receipt_draft` | `/receipts/save.php` | **location** (where it lands), supplier, purchase_order (its open lines are prefilled), delivery_note_ref, received_on, lines (JSON — variant or barcode with qty and unit_cost and putaway_location), notes | receipt_cancel | | | `stock.receipt_draft` | stock.receive |
| `receipt_line_add` | `/receipts/lines/save.php` | **receipt** (a draft), **variant** (or its barcode as scanned), **qty**, unit_cost, purchase_order_line, putaway_location, discrepancy_kind (none or short or over or damaged or substitute), discrepancy_note, line (to change an existing one) | receipt_line_remove | | | `stock.receipt_line` | stock.receive |
| `receipt_line_remove` | `/receipts/lines/remove.php` | **line** (a draft receipt's) | — | | | `stock.receipt_line_remove` | stock.receive |
| `receipt_post` | `/receipts/post.php` | **receipt** (a draft — every line becomes a receipt movement and the cost follows the settings) | — | ✔ | | `stock.receive` | stock.receive |
| `receipt_cancel` | `/receipts/cancel.php` | **receipt** (a draft) | — | ✔ | | `stock.receipt_cancel` | stock.receive |
| `adjustment_draft` | `/adjustments/save.php` | **location**, **reason** (a reason code), lines (JSON — variant with qty_delta and unit_cost), notes | adjustment_cancel | | | `stock.adjustment_draft` | stock.adjust |
| `adjustment_line_add` | `/adjustments/lines/save.php` | **adjustment** (a draft), **variant**, **qty_delta** (signed — never zero), unit_cost, note, line (to change an existing one) | adjustment_line_remove | | | `stock.adjustment_line` | stock.adjust |
| `adjustment_line_remove` | `/adjustments/lines/remove.php` | **line** (a draft adjustment's) | — | | | `stock.adjustment_line_remove` | stock.adjust |
| `stock_adjust` | `/adjustments/post.php` | **adjustment** (a draft — a quantity changes with no document but this one) | — | ✔ | deletion | `stock.adjust` | stock.adjust |
| `adjustment_cancel` | `/adjustments/cancel.php` | **adjustment** (a draft) | — | ✔ | | `stock.adjustment_cancel` | stock.adjust |
| `transfer_draft` | `/transfers/save.php` | **from_location**, **to_location**, lines (JSON — variant with qty), notes | transfer_cancel | | | `stock.transfer_draft` | stock.transfer |
| `transfer_line_add` | `/transfers/lines/save.php` | **transfer** (a draft), **variant**, **qty**, line (to change an existing one) | transfer_line_remove | | | `stock.transfer_line` | stock.transfer |
| `transfer_line_remove` | `/transfers/lines/remove.php` | **line** (a draft transfer's) | — | | | `stock.transfer_line_remove` | stock.transfer |
| `transfer_send` | `/transfers/send.php` | **transfer** (a draft — the lines leave the from location into transit) | — | ✔ | | `stock.transfer_send` | stock.transfer |
| `transfer_receive` | `/transfers/receive.php` | **transfer** (in transit), quantities (JSON — line to qty received when short) | — | ✔ | | `stock.transfer_receive` | stock.transfer |
| `transfer_cancel` | `/transfers/cancel.php` | **transfer** (a draft) | — | ✔ | | `stock.transfer_cancel` | stock.transfer |
| `count_start` | `/counts/start.php` | **location**, notes | count_cancel | | | `stock.count_start` | stock.count |
| `count_line_set` | `/counts/lines/save.php` | **count** (an open one), **variant** (or its barcode as scanned), **counted_qty** | restore prior | | | `stock.count_line` | stock.count |
| `count_post` | `/counts/post.php` | **count** (an open one — every difference becomes a count correction) | — | ✔ | deletion | `stock.count_post` | stock.count |
| `count_cancel` | `/counts/cancel.php` | **count** (an open one — nothing posted) | — | ✔ | | `stock.count_cancel` | stock.count |
| `floor_model_set` | `floor-model.php` | **variant**, **location**, **direction** (in or out), qty (1 by default), note | floor_model_set | | | `stock.floor_model` | stock.adjust |
| `stock_reverse` | `reverse.php` | **transaction** (a posted movement — a reversal is posted against it), note | — | ✔ | | `stock.reverse` | stock.adjust |

## Sources, connectors, listings and matching — THE EXEMPLAR (slice 3)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `source-list` | `/sources/` | the sources as cards with the connector's badge, role, health, last pull and listing counts; "Add from a template" (params: `q`, `connector`, `role`, `health`) |
| `source-add` | `/sources/new` | to add a source: a template or a connector, name, URL, role (supplier or reference), the supplier, schedule, rate, user-agent, and the connector's own settings — collections, a column mapping with a preview of the file's first rows, a sitemap, search terms (params: `template`, `supplier`, `connector`) |
| `source-view` | `/sources/{source}` | one source: health and the robots state, the last pulls with their policy facts, the schedule, the settings, the credential's label and last four; Probe, Pull now, Pause, Resume (params: `tab`) |
| `source-edit` | `/sources/{source}/edit` | to change a source's name, URL, role, supplier, schedule, rate, user-agent and connector settings |
| `source-listings` | `/sources/{source}/listings` | the source's listings as a table: match state, price, availability, last seen; removed apart (params: `match`, `q`, `availability`) |
| `source-pulls` | `/sources/{source}/pulls` | the source's pulls: when, kind, status, listings seen, new, changed, requests, bytes, the policy followed, the error (params: `status`) |
| `source-credential` | `/sources/{source}/credential` | to set or replace the source's credential (kind, label, the secret — shown never again) |
| `source-template-list` | `/sources/templates` | the known stores and feeds with their connector and the survey's finding; pick one to add |
| `listing-view` | `/listings/{listing}` | one listing: its variants with their current offers, **the price and availability chart** of a variant over time, the match and how it was made, the raw fields read; match, unmatch, propose, forget (params: `listing_variant`, `since`) |
| `match-queue` | `/matching/` | the unmatched listing variants with the matcher's and the Buyer's proposals and their evidence; accept, pick another variant, dismiss, "not ours" (params: `source`, `q`, `min_confidence`) |
| `supplier-item-list` | `/supplier-items/` | a supplier's price sheet: supplier SKU, cost, lead time, MOQ per variant; the feed that keeps it; add or change a row (params: `supplier`, `q`) |

Actions (base `/sources/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `source_create` | `save.php` | **name**, connector (shopify or woocommerce or jsonld or feed or manual) or template (a source template that fills the rest), base_url, role (supplier or reference — reference by default), supplier (required for a supplier source), settings (JSON — the connector's own), schedule_minutes (0 for manual — the role's default otherwise), rate_per_second, user_agent | source_delete | | other | `source.create` | sources.write |
| `source_update` | `save.php` | **source**, any field of source_create | restore prior | | | `source.update` | sources.write |
| `source_credential_set` | `credential.php` | **source**, **kind** (api_key or oauth_client or basic or sftp_password or sftp_key or rsa_signing or bearer), **label**, **secret** (sealed — never shown or logged — replacing one is logged as rotated) | — | ✔ | other | `source.credential_set` | sources.credentials |
| `source_schedule_set` | `schedule.php` | **source**, **schedule_minutes** (0 for manual), rate_per_second | restore prior | | other | `source.schedule_set` | sources.write |
| `source_probe` | `probe.php` | **source** (the robots check then one page then the credential's handshake — the answer is ok / blocked / misconfigured) | — | | | `source.probe` | sources.write |
| `source_pull` | `pull.php` | **source** (a pull now — within the source's rate and the crawl policy) | — | | | `source.pull_start` | sources.write |
| `source_search` | `search.php` | **q** (what to ask the live sources for), source (one source — every searchable one by default), size | — | | | `source.search` | orders.write |
| `source_pause` | `pause.php` | **source**, reason | source_resume | | | `source.pause` | sources.write |
| `source_resume` | `resume.php` | **source** (the back-off and failure count are cleared) | source_pause | | | `source.resume` | sources.write |
| `source_delete` | `delete.php` | **source** (its listings and offers and pulls and credential go with it — matches on our variants are cleared) | — | ✔ | deletion | `source.delete` | records.delete |
| `listing_match` | `/listings/match.php` | **listing_variant**, **variant** (sizes must agree — an agent may do this only by identifier or for a proposal a person made) | listing_unmatch | | | `listing.match` | listings.match |
| `listing_unmatch` | `/listings/unmatch.php` | **listing_variant** | listing_match | ✔ | | `listing.unmatch` | listings.match |
| `match_propose` | `/listings/propose.php` | **listing_variant**, variant (the one proposed — the matcher scores every candidate when empty), confidence (0 to 1), evidence (JSON — what matched) | proposal_dismiss | | | `listing.propose` | listings.match |
| `proposal_accept` | `/listings/proposals/accept.php` | **proposal** (a person's — an agent never accepts its own) | listing_unmatch | | | `listing.accept` | listings.match |
| `proposal_dismiss` | `/listings/proposals/dismiss.php` | **proposal** (remembered — not proposed again) | — | | | `listing.dismiss` | listings.match |
| `listing_forget` | `/listings/forget.php` | **listing** ("not ours" — its variants are unmatched and hidden from the queue) | — | ✔ | deletion | `listing.forget` | listings.match |
| `supplier_item_save` | `/supplier-items/save.php` | **supplier**, **variant**, supplier_sku, cost, lead_time_days, moq (1 by default), active (yes or no) | restore prior | | | `supplier.item_save` | suppliers.write |
| `supplier_item_remove` | `/supplier-items/remove.php` | **item** (a supplier price-sheet row) | — | ✔ | | `supplier.item_remove` | suppliers.write |

Reconciled 2026-10-05: `source_search`'s Who is `orders.write` (design §7 A8: Sales), as the tool surface and the bridge `html/internal/bridge.php`
(slice 4) enforce; the manifest's `inventory.read` was the odd one out.

## Find, availability and watches (slice 4)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `find` | `/find` | one box: a product, brand, SKU, GTIN or MPN; chips for size, type, firmness, price band, in stock only, ships within N days; cards per variant with our retail and MAP, own stock by location, supplier offers ranked with cost when permitted, reference prices; "ask the sources now", "Sell this", "Watch" (params: `q`, `size`, `type`, `firmness`, `price_min`, `price_max`, `in_stock`, `ships_within`) |
| `watch-list` | `/watches/` | my watches (the Buyer's: everyone's): what each watches for, its threshold, when it last fired; clear one (params: `kind`, `fired`, `member`) |

Actions (base `/watches/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `watch_set` | `save.php` | **kind** (back_in_stock or price_below or cost_below or map_breach or lead_time_over or removed), variant or listing_variant or product (what to watch), threshold (a price or days — required for price_below and cost_below and lead_time_over), agent (an agent to dispatch when it fires), text_me (yes or no), note | watch_clear | | | `watch.set` | watches.own |
| `watch_clear` | `clear.php` | **watch** (mine — the Buyer's: anyone's) | watch_set | | | `watch.clear` | own or watches.all |

## Customers and sales orders (slice 5)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `customer-list` | `/customers/` | the customers as cards with their open orders and last order; search by name, email or phone (params: `q`, `source`, `archived`) |
| `customer-add` | `/customers/new` | to add a customer: name, email, phone, a second phone, billing and shipping address, how they came (walk-in, phone, web, referral), tax rate, email opt-in, notes |
| `customer-view` | `/customers/{customer}` | one customer: their orders, returns, notes, attachments, the trail (params: `tab`) |
| `customer-edit` | `/customers/{customer}/edit` | to change a customer's details, addresses, source, tax rate, opt-in |
| `order-list` | `/orders/` | the orders as a table by status with the customer, salesperson, store, total, payment state, promised date — late marked (params: `status`, `customer`, `salesperson`, `location`, `late`, `from`, `to`) |
| `order-add` | `/orders/new` | to write a quote: a customer picker or a new customer; lines with the **availability picker per line** — stock at a location, a source's offer with its lead time and cost when permitted, or backorder; delivery method and ship-to; promised date; tax (params: `customer`, `variant`, `qty`, `fulfilment`, `listing_variant`, `location`) |
| `order-view` | `/orders/{sales_order}` | one order: the customer, lines with their fulfilment and where each is, payments and the balance, shipments with tracking, the drop-ship purchase orders, the customer's link, the timeline, notes; Confirm, Record payment, Ship, Deliver, Close, Cancel, Send (params: `tab`) |
| `order-edit` | `/orders/{sales_order}/edit` | to change a quote's or an unconfirmed order's customer, lines, fulfilment, delivery, ship-to, promised date, tax, notes |
| `order-confirm` | `/orders/{sales_order}/confirm` | the confirmation: what allocates where, which drop-ship purchase orders are drafted per supplier, what cannot be covered and must become a backorder; Confirm |
| `order-payment` | `/orders/{sales_order}/payment` | to record a deposit, a balance or a refund: amount, method, reference (params: `kind`) |
| `order-ship` | `/orders/{sales_order}/ship` | to ship: pick the lines, the kind (own delivery, parcel, LTL, pickup), carrier and tracking; a stock line issues; a drop-ship line's tracking comes from the supplier |
| `order-send` | `/orders/{sales_order}/send` | the confirmation email as the customer will see it, with the order link; Send |
| `fulfilment-today` | `/orders/today` | today's deliveries and pickups by store: what to pick, what is waiting on a supplier (params: `date`, `location`) |
| `shipment-list` | `/shipments/` | the shipments: order, kind, carrier, tracking, shipped and delivered (params: `kind`, `from`, `to`, `undelivered`) |
| `shipment-view` | `/shipments/{shipment}` | one shipment: its lines, tracking, Deliver |

Actions (base `/orders/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `customer_create` | `/customers/save.php` | **name**, email, phone, phone_alt, billing_address, shipping_address, source (walk_in or phone or web or referral or other), tax_rate, terms_days, email_opt_in (yes or no), notes | customer_archive | | | `customer.create` | customers.write |
| `customer_update` | `/customers/save.php` | **customer**, any field of customer_create | restore prior | | | `customer.update` | customers.write |
| `customer_archive` | `/customers/archive.php` | **customer**, archived (yes or no — yes by default) | customer_archive | ✔ | | `customer.archive` | customers.write |
| `customer_delete` | `/customers/delete.php` | **customer** (one with no order) | — | ✔ | deletion | `customer.delete` | records.delete |
| `quote_create` | `save.php` | **customer** (or a new one by name and email and phone), lines (JSON — variant with qty and unit_price and fulfilment_kind and location or listing_variant), delivery_method (pickup or delivery or parcel or ltl or white_glove), promised_on, ship_to_name, ship_to_address, ship_to_city, ship_to_region, ship_to_postal, ship_to_country, ship_to_phone, ship_to_notes, location (the store that sold it), salesperson (me by default), tax_rate, discount, shipping, customer_reference, notes | order_cancel | | | `order.quote` | orders.write |
| `order_update` | `save.php` | **order** (a quote or an unconfirmed order), any field of quote_create | restore prior | | | `order.update` | orders.write |
| `order_line_add` | `lines/save.php` | **order** (a quote or unconfirmed), **variant**, **qty**, unit_price (the retail by default), discount, fulfilment_kind (stock or dropship or backorder or pickup), location (a stock line's), listing_variant (a drop-ship line's offer — its cost and lead time are snapshotted), notes | order_line_cancel | | | `order.line_add` | orders.write |
| `order_line_update` | `lines/save.php` | **line**, any field of order_line_add | restore prior | | | `order.line_update` | orders.write |
| `order_line_fulfilment_set` | `lines/fulfilment.php` | **line**, **fulfilment_kind** (stock or dropship or backorder or pickup), location (for stock), listing_variant (for a drop-ship) | restore prior | | | `order.line_fulfilment_set` | orders.write |
| `order_line_cancel` | `lines/cancel.php` | **line** (an allocation is released — an ordered drop-ship line asks the Buyer to cancel its purchase order line) | — | ✔ | | `order.line_cancel` | orders.write |
| `order_confirm` | `confirm.php` | **order** (a quote — every stock line allocates and one drop-ship purchase order per supplier is drafted) | order_cancel | ✔ | other | `order.confirm` | orders.write |
| `order_send` | `send.php` | **order** (the confirmation email with the order link to the customer's email), message (words of my own above the order) | — | ✔ | external_send | `order.send` | orders.send |
| `order_notify` | `notify.php` | **order**, **kind** (delivery_date or delay or ready_for_pickup or shipped), message, promised_on (a new date) | — | ✔ | external_send | `order.notify` | orders.send |
| `payment_record` | `payments/save.php` | **order**, **kind** (deposit or balance), **amount**, **method** (cash or card or check or transfer or financing or other), reference (the last four or a check number), taken_at, note | — | ✔ | other | `order.payment` | payments.record |
| `refund_record` | `payments/refund.php` | **order**, **amount**, **method**, reference, note | — | ✔ | other | `order.refund` | payments.record |
| `order_ship` | `ship.php` | **order**, **lines** (JSON — line with qty), kind (own_delivery or parcel or ltl or pickup — own_delivery by default), carrier, tracking, shipped_at, serials[] (typed at shipment) | — | ✔ | | `order.ship` | stock.ship |
| `order_deliver` | `deliver.php` | **shipment** (or order — its undelivered shipments), delivered_at | — | | | `order.deliver` | stock.ship |
| `order_close` | `close.php` | **order** (delivered and paid — the customer's link starts its 180 days) | — | | | `order.close` | orders.write |
| `order_cancel` | `cancel.php` | **order**, **reason** (allocations released — drafted drop-ship purchase orders cancelled — a sent one asks the Buyer) | — | ✔ | deletion | `order.cancel` | orders.write |
| `order_link_rotate` | `link-rotate.php` | **order** (a new customer link — the old one stops — sent with the next order_send) | — | ✔ | | `order.link_rotate` | orders.send |

## Purchasing, drop-ship orders, receiving and the supplier's door (slice 6)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `purchase-order-list` | `/purchasing/` | the purchase orders as a table by status and kind with the supplier, the sales order for a drop-ship, expected date, acknowledgment, tracking (params: `status`, `kind`, `supplier`, `awaiting_ack`, `no_tracking`) |
| `purchase-order-add` | `/purchasing/new` | to draft a purchase order: supplier, kind (stock or drop-ship), ship-to (a location or the customer), lines from the reorder candidates or a sales order's drop-ship lines, the offer each is ordered against with its cost (params: `supplier`, `order`, `reorder`, `variant`) |
| `purchase-order-view` | `/purchasing/{purchase_order}` | one purchase order: lines with their state, the supplier's events (acknowledged, declined, tracking), receipts, the supplier's link, the email preview; Send, Place, Acknowledge, Tracking, Receive, Close, Cancel (params: `tab`) |
| `purchase-order-edit` | `/purchasing/{purchase_order}/edit` | to change a draft purchase order's supplier, ship-to, lines, costs, expected date, notes |
| `purchase-order-send` | `/purchasing/{purchase_order}/send` | the purchase order as the supplier will see it, with their link; Send by email, or Mark placed with the portal reference |
| `purchase-order-receive` | `/purchasing/{purchase_order}/receive` | to receive against it: a receipt drafted from the open lines at a location (opens the receipt) |
| `supplier-list` | `/suppliers/` | the suppliers as cards: kind, drop-ships, lead time, open orders, sources (params: `q`, `dropships`) |
| `supplier-add` | `/suppliers/new` | to add a supplier: name, kind, contact, email, phone, address, website, account number, terms, drop-ships, lead time, order method (email, portal, api, edi, phone), order email, portal URL, minimum order |
| `supplier-view` | `/suppliers/{supplier}` | one supplier: items (the price sheet), open purchase orders, lead-time actuals, sources, notes, attachments (params: `tab`) |
| `supplier-edit` | `/suppliers/{supplier}/edit` | to change a supplier's details, dealer-program fields and order method |

Actions (base `/purchasing/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `supplier_create` | `/suppliers/save.php` | **name**, kind (manufacturer or distributor or wholesaler or marketplace or vendor or other), contact_name, email, phone, address, website, account_number, terms, dropships (yes or no), lead_time_days, order_method (email or portal or api or edi or phone), order_email, portal_url, min_order, notes | supplier_archive | | | `supplier.create` | suppliers.write |
| `supplier_update` | `/suppliers/save.php` | **supplier**, any field of supplier_create | restore prior | | | `supplier.update` | suppliers.write |
| `supplier_archive` | `/suppliers/archive.php` | **supplier** (one with no open purchase order), active (yes or no — no archives) | supplier_archive | ✔ | | `supplier.archive` | suppliers.write |
| `supplier_message` | `/suppliers/message.php` | **supplier**, **subject**, **body** (an email to the supplier's order address — other than a purchase order itself), purchase_order (to mention one) | — | ✔ | external_send | `supplier.message` | purchasing.write |
| `purchase_order_draft` | `save.php` | **supplier**, kind (stock or dropship — stock by default), order (the sales order a drop-ship fills — its ship-to is the customer's), location (a stock order's ship-to), lines (JSON — variant with qty and unit_cost and listing_variant and sales_order_line), expected_on, shipping_cost, notes | purchase_order_cancel | | | `purchase_order.draft` | purchasing.write |
| `purchase_order_update` | `save.php` | **purchase_order** (a draft), any field of purchase_order_draft | restore prior | | | `purchase_order.update` | purchasing.write |
| `purchase_order_line_add` | `lines/save.php` | **purchase_order** (a draft), **variant**, **qty**, unit_cost (the supplier item's or the offer's by default), supplier_sku, listing_variant (the offer it is ordered against), sales_order_line (the drop-ship line it fills), expected_on, line (to change an existing one) | purchase_order_line_remove | | | `purchase_order.line_add` | purchasing.write |
| `purchase_order_line_update` | `lines/save.php` | **line**, any field of purchase_order_line_add | restore prior | | | `purchase_order.line_update` | purchasing.write |
| `purchase_order_line_remove` | `lines/remove.php` | **line** (a draft purchase order's) | — | | | `purchase_order.line_remove` | purchasing.write |
| `purchase_order_send` | `send.php` | **purchase_order** (a draft — emailed to the supplier's order address with their link), via (email or phone — email by default), message | — | ✔ | money_out | `purchase_order.send` | purchasing.write |
| `purchase_order_place` | `place.php` | **purchase_order** (a draft placed on the supplier's portal or by API or EDI — recorded as sent), **supplier_ref** (their order reference), via (portal or api or edi — portal by default) | — | ✔ | money_out | `purchase_order.place` | purchasing.write |
| `purchase_order_acknowledge` | `acknowledge.php` | **purchase_order** (by hand — they called or wrote), supplier_ref, expected_on, line (one line — the whole order by default) | — | | | `purchase_order.supplier_ack` | purchasing.write |
| `purchase_order_line_decline` | `decline.php` | **line**, **reason** (the supplier cannot fill it — the sales line goes back to the picker) | — | ✔ | | `purchase_order.supplier_decline` | purchasing.write |
| `purchase_order_tracking` | `tracking.php` | **line**, **carrier**, **tracking**, shipped_at (a drop-ship line becomes shipped on its sales order) | — | | | `purchase_order.supplier_tracking` | purchasing.write |
| `purchase_order_receive` | `receive.php` | **purchase_order** (a stock order — a receipt is drafted from the open lines), location (the order's ship-to by default) | receipt_cancel | | | `purchase_order.receive` | stock.receive |
| `purchase_order_close` | `close.php` | **purchase_order** (received or short — the open lines close short) | — | ✔ | | `purchase_order.close` | purchasing.write |
| `purchase_order_cancel` | `cancel.php` | **purchase_order**, **reason** (a sent one is cancelled with the supplier by you — the drop-ship lines go back to the picker) | — | ✔ | deletion | `purchase_order.cancel` | purchasing.write |
| `purchase_order_link_rotate` | `link-rotate.php` | **purchase_order** (a new supplier link — the old one stops — sent with the next purchase_order_send) | — | ✔ | | `purchase_order.link_rotate` | purchasing.write |

## The availability feed (slice 7)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `feed-key-list` | `/admin/feed-keys/` | the feed keys: label, consumer kind, the partner price list, rate limits, usage today, last used, rotated or revoked; mint, rotate, revoke |
| `feed-key-add` | `/admin/feed-keys/new` | to mint a feed key: label, consumer (website or installation or partner), the partner's price list, rate limits — the key shown once |
| `price-list-list` | `/admin/price-lists/` | the partner price lists: name, percent off retail, the keys using each |
| `price-list-add` | `/admin/price-lists/new` | to add a price list: name, percent off retail, notes |
| `price-list-edit` | `/admin/price-lists/{price_list}/edit` | to change a price list's name, percent, notes, active |

Actions (base `/admin/feed-keys/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `feed_key_mint` | `mint.php` | **label**, consumer_kind (website or installation or partner — website by default), price_list (a partner's price list), rate_per_minute, rate_per_day, expires_at | feed_key_revoke | ✔ | external_send | `feed.key_mint` | feed.keys |
| `feed_key_rotate` | `rotate.php` | **key** (a new key is minted — the old one lives for the overlap hours in the settings) | — | ✔ | | `feed.key_rotate` | feed.keys |
| `feed_key_revoke` | `revoke.php` | **key** | — | ✔ | | `feed.key_revoke` | feed.keys |
| `price_list_save` | `/admin/price-lists/save.php` | **name**, **percent_off_retail**, notes, active (yes or no), price_list (to change an existing one) | restore prior | | | `price_list.save` | feed.keys |

## Returns, the Buyer agent's data, notifications and the worker (slice 8)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `return-list` | `/returns/` | the returns as a table by status with the order, customer, lines, method, scheduled date, disposition (params: `status`, `customer`, `awaiting_disposition`) |
| `return-add` | `/returns/new` | to request a return: the order, its shipped lines with quantities and reasons (comfort, damaged, wrong item, changed mind, warranty), pickup or drop-off and its date, the disposition per line, notes (params: `order`) |
| `return-view` | `/returns/{return}` | one return: lines, reasons, dispositions, received quantities, the refund and restocking fee recorded, photos; Approve, Deny, Receive, Disposition, Close |
| `return-edit` | `/returns/{return}/edit` | to change a requested or approved return's lines, method, date, dispositions, notes |
| `return-receive` | `/returns/{return}/receive` | to receive the goods: per line the quantity back, its condition, where it lands — restock writes the movement |
| `proposal-list` | `/proposals/` | the Buyer agent's proposals: reorders with their drafted purchase orders, matches, prices, lines at risk; accept or dismiss each (params: `kind`, `status`) |

Actions (base `/returns/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `return_request` | `save.php` | **order** (a confirmed order with shipped lines), lines (JSON — line with qty and reason and disposition), method (pickup or drop_off — pickup by default), scheduled_on, location (where it comes back to), notes | — | | | `return.request` | orders.write |
| `return_update` | `save.php` | **return** (requested or approved), any field of return_request | restore prior | | | `return.update` | orders.write |
| `return_line_add` | `lines/save.php` | **return** (requested or approved), **line** (the sales order line), **qty**, **reason** (a return reason code), disposition (restock or floor_model or dispose or return_to_supplier or donate — restock by default), location, condition_note, return_line (to change an existing one) | return_line_remove | | | `return.line_add` | orders.write |
| `return_line_remove` | `lines/remove.php` | **return_line** (of a requested or approved return) | — | | | `return.line_remove` | orders.write |
| `return_authorize` | `approve.php` | **return** (requested — the customer may send it back) | return_deny | ✔ | other | `return.approve` | returns.write |
| `return_deny` | `deny.php` | **return** (requested), **reason** | — | ✔ | | `return.deny` | returns.write |
| `return_receive` | `receive.php` | **return** (approved), quantities (JSON — return_line to qty received when short), condition_notes (JSON — return_line to note) | — | ✔ | | `return.receive` | returns.receive |
| `return_disposition_set` | `disposition.php` | **return_line**, **disposition** (restock or floor_model or dispose or return_to_supplier or donate), location (where a restock lands) | restore prior | | | `return.disposition` | returns.write |
| `return_close` | `close.php` | **return** (received), refund_amount (recorded — a refund_record on the order follows), restocking_fee | — | ✔ | | `return.close` | returns.write |
| `buyer_propose` | `/proposals/save.php` | **kind** (reorder or match or price or at_risk), **subject** (what it is about), variant or listing_variant or line or purchase_order (the record), drafted_record (the purchase order or proposal drafted), evidence (JSON), confidence (0 to 1) | buyer_proposal_dismiss | | | `buyer.propose` | purchasing.write |
| `buyer_proposal_accept` | `/proposals/accept.php` | **proposal** (a Buyer proposal — a person's act — the drafted record stays drafted) | — | | | `buyer.accept` | purchasing.write |
| `buyer_proposal_dismiss` | `/proposals/dismiss.php` | **proposal**, reason | — | | | `buyer.dismiss` | purchasing.write |
| `morning_note_send` | `/proposals/morning-note.php` | **body** (the Buyer agent's seven headings), to_member (the settings' Buyer by default) | — | | | `buyer.note` | agents.settings |
| `note_add` | `/notes/add.php` | **record_type** (product or variant or supplier or source or listing or customer or order or purchase_order or receipt or return or location), **record** (its id), **body** | note_delete | | | `note.add` | notes.write |
| `note_delete` | `/notes/delete.php` | **note** (mine — the admin's: anyone's) | — | ✔ | | `note.delete` | own or records.delete |
| `attachment_add` | `/files/upload.php` | **file** (multipart), **record_type** (product or variant or supplier or source or listing or customer or order or purchase_order or receipt or shipment or return or adjustment or count or transfer or location), **record** (its id) | attachment_delete | | | `attachment.add` | notes.write |
| `attachment_delete` | `/files/delete.php` | **attachment** | — | ✔ | | `attachment.delete` | own or records.delete |
| `dispatch_retry` | `/admin/dispatches/retry.php` | **dispatch** (a failed one) | — | | | `agent.dispatch` | agents.settings |

Reconciled 2026-10-05: `morning_note_send`'s Who is `agents.settings` (a person); an agent holding `reports.read` may run it — returns-worker.md's
DECISION, so Phase 4's gate matches.

## Reports, home, admin and tokens (slice 9)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `report-list` | `/reports/` | the reports as cards: stock value, sell-through and cover, sales summary and margin, price exceptions, source health and reliability, lead-time actuals |
| `report` | `/reports/{report}` | one report with its filters and a CSV: stock-value, sell-through, sales, margin, price-exceptions, source-health, lead-times (params: `from`, `to`, `location`, `brand`, `type`, `supplier`, `source`, `by`) |
| `export-list` | `/exports/` | the downloads: sales closed, purchases received, stock valuation (the accounting system's files), the catalog, the listings — CSV and JSON for a period (params: `from`, `to`) |
| `admin-settings` | `/admin/settings` | the settings: the business (name, contact, address — the doors and the crawler's user-agent), currency, units, time zone, Sales sees cost, the supplier sees the phone for which shipping kinds, the feed shows quantities, link lifetimes, feed rate limits, the sizes and their synonyms, the attribute keys, reorder defaults, the cost source, the thresholds (cost move, reference undercut, acknowledgment days), the Buyer, the crawl policy (user-agent, rate, back-off, max pages, cadences, removed-after, heartbeat), the attachment limit |
| `sequence-list` | `/admin/sequences` | the document sequences (sales orders, purchase orders, receipts, returns, adjustments, transfers, counts): prefix, next value, padding |
| `tax-rate-list` | `/admin/tax-rates/` | the tax rates with the default marked; archived apart |
| `tax-rate-add` | `/admin/tax-rates/new` | to add a tax rate: name, rate, default |
| `tax-rate-edit` | `/admin/tax-rates/{tax_rate}/edit` | to change a tax rate's name, rate, default, or archive it |
| `reason-code-list` | `/admin/reason-codes/` | the reason codes for adjustments and returns, in order, with whether each changes quantity |
| `reason-code-add` | `/admin/reason-codes/new` | to add a reason code: code, name, applies to (adjustment or return), affects quantity, order |
| `reason-code-edit` | `/admin/reason-codes/{reason_code}/edit` | to change a reason code's name, applies to, affects quantity, order, active |
| `agent-list` | `/admin/agents` | the agents holding a role here, their last run, their pending and failed dispatches — read-only; hiring and grants link to the kernel |
| `dispatch-list` | `/admin/dispatches` | every dispatch: pending, running, answered, failed; retry a failed one (params: `status`, `agent`) |
| `connection-list` | `/admin/connections` | which sibling applications read what of ours (the five shares) and when — the kernel's facts, read-only |

Actions (base `/admin/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `settings_save` | `settings.php` | business_name, business_contact_email, business_phone, business_address, currency, units (imperial or metric), timezone, sales_sees_cost (yes or no), supplier_sees_phone[] (shipping kinds), feed_shows_quantity (yes or no), order_link_days, supplier_link_days, feed_rate_per_minute, feed_rate_per_day, key_rotation_overlap_hours, sizes (JSON), attribute_keys (JSON), reorder_point_default, reorder_qty_default, cost_source (last_receipt or feed or manual), cost_move_pct, reference_undercut_pct, ack_days, buyer (the member the morning note goes to), crawl_user_agent, crawl_rate_per_second, crawl_backoff_minutes[], crawl_max_pages, schedule_supplier_minutes, schedule_reference_minutes, schedule_jsonld_minutes, removed_after_pulls, snapshot_heartbeat_days, raw_max_bytes, max_attachment_bytes | restore prior | | other | `settings.save` | settings.manage |
| `sequence_set` | `sequences.php` | **kind** (sales_order or purchase_order or goods_receipt or return or adjustment or transfer or count), prefix, next_value (never below what was issued), padding | restore prior | ✔ | other | `sequence.set` | sequences.manage |
| `tax_rate_save` | `tax-rates/save.php` | **name**, **rate** (a percent), is_default (yes or no), tax_rate (to change an existing one) | restore prior | | other | `tax_rate.save` | settings.manage |
| `tax_rate_archive` | `tax-rates/archive.php` | **tax_rate** (not the default one) | — | ✔ | | `tax_rate.archive` | settings.manage |
| `reason_code_save` | `reason-codes/save.php` | **name**, code (lowercase — from the name by default), applies_to[] (adjustment or return), affects_qty (yes or no — yes by default), sort_order, active (yes or no), reason (to change an existing one) | restore prior | | | `reason_code.save` | settings.manage |
| `report_run` | `/reports/run.php` | **report** (stock-value or sell-through or sales or margin or price-exceptions or source-health or lead-times), from, to, location, brand, type, supplier, source, by (location or brand or type or supplier), format (html or csv — html by default) | — | | | `report.run` | reports.read |
| `export_download` | `/exports/download.php` | **export** (sales_closed or purchases_received or stock_valuation or catalog or listings), **format** (csv or json), from, to, as_of (a valuation's date), source (a listings export's source) | — | | | `export.download` | reports.read or exports.all |

## The two doors (no session; not actions)

Screens:

| Screen id | URL | When the visitor wants… |
| --- | --- | --- |
| `customer-door` | `/o/{token}` | the customer's order: number and date, the lines at retail, delivery or pickup with the promised date, payment status and the balance due, tracking links, the business's name and contact, "message us"; read-only, `noindex`, rate-limited; opening it is logged `order.customer_view` with `source = portal`; the link dies 180 days after the order closes; no cost, no source, no supplier anywhere on it |
| `supplier-door` | `/s/{token}` | the supplier's purchase order: the business's account number with them, the lines with their SKU and the agreed cost, the ship-to (a location or the customer's address for a drop-ship — the phone when the settings allow it for that shipping kind), delivery notes; opening it is logged `purchase_order.supplier_view` with `source = portal`; the link dies 90 days after the order closes |

The supplier's three POSTs, each with the token, CSRF-protected like the kernel's signing page, rate-limited, written by the database's
verb with `p_source = 'portal'` and notified to the Buyer — **not actions** (no agent reaches them; the Buyer records the same three
things by hand with `purchase_order_acknowledge`, `purchase_order_line_decline` and `purchase_order_tracking`):

| POST | Fields | Database verb | Log |
| --- | --- | --- | --- |
| `POST /s/{token}/acknowledge` | supplier_ref, expected_on, line (one line — the whole order by default) | `inv_po_acknowledge()` | `purchase_order.supplier_ack` |
| `POST /s/{token}/decline` | line, reason | `inv_po_decline_line()` | `purchase_order.supplier_decline` |
| `POST /s/{token}/tracking` | line, carrier, tracking, shipped_at | `inv_po_tracking()` | `purchase_order.supplier_tracking` |

A dead or unknown token answers one page: "This link has expired. Ask the business for a new one." The token is never logged.
`GET /api/v1/availability` (a feed key in the Authorization header; `?gtin=` or `?sku=` or `?q=`) answers `inv_feed_answer()` and
logs `feed.read` with the key's label — an endpoint of `maludb-os.json`, not a screen.

## Size
5 + 16 + 22 + 11 + 2 + 15 + 10 + 5 + 6 + 14 = 106 screens (plus the two doors — 108 in the registry); 132 actions, 26 of them with an approval
category (the categories are exactly `maludb-os.json` `approvals[]`): `money_out` 2 (`purchase_order_send`, `purchase_order_place`),
`external_send` 4 (`order_send`, `order_notify`, `supplier_message`, `feed_key_mint`), `deletion` 9 (`product_delete`, `variant_delete`,
`source_delete`, `listing_forget`, `order_cancel`, `purchase_order_cancel`, `customer_delete`, `stock_adjust`, `count_post`), `other` 11
(`order_confirm`, `payment_record`, `refund_record`, `return_authorize`, `price_set`, `source_create`, `source_credential_set`,
`source_schedule_set`, `settings_save`, `sequence_set`, `tax_rate_save`).

**Log events beyond design §6's list** (named on its `entity.verb` rule; the slice specs and the tool surface's payload rules carry
them): `product.import`, `product.image_add`, `product.image_remove`, `brand.save`, `product_type.save`; `stock.receipt_draft`,
`stock.receipt_line`, `stock.receipt_line_remove`, `stock.receipt_cancel`, `stock.adjustment_draft`, `stock.adjustment_line`,
`stock.adjustment_line_remove`, `stock.adjustment_cancel`, `stock.transfer_draft`, `stock.transfer_line`, `stock.transfer_line_remove`,
`stock.transfer_cancel`, `stock.count_line`, `stock.count_cancel`, `stock.floor_model`; `supplier.item_save`, `supplier.item_remove`,
`supplier.message`; `order.update`, `order.notify`, `order.link_rotate`; `purchase_order.update`, `purchase_order.line_add`,
`purchase_order.line_update`, `purchase_order.line_remove`, `purchase_order.link_rotate`; `return.update`, `return.line_add`,
`return.line_remove`; `price_list.save`; `note.add`, `note.delete`, `attachment.add`, `attachment.delete`; `sequence.set`,
`tax_rate.save`, `tax_rate.archive`, `reason_code.save`; `prefs.save`, `notification.read`. Design §6's `source.credential_rotate` is
not a separate action: `source_credential_set` replacing an existing credential logs `source.credential_set` with `rotated: true` in
the payload, so the kernel's one policy pauses both. Design §5's `export_own` is `export_download` here (what the caller's rights
permit). The worker's own events (`source.pull_done|pull_fail|pull_blocked`, `listing.new|changed|removed`, `offer.change`,
`watch.fire`, `agent.reply|fail`, `feed.read`, `share.read`, `member.sign_on`, `directory.sync`, `screen.view`) are written by the
worker, the doors, the feed, the kit and the shares — never by an action.

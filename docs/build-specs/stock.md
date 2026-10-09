# Build spec: locations and stock — the ledger's screens (slice 2)

Built by the planning-class model on slice 1's pattern. What exists at the end: an admin makes the locations; a warehouse hand with a phone
receives a delivery by scanning each carton's barcode into a receipt and posts it; moves stock to the showroom on a transfer the showroom
receives; adjusts a damaged unit with a reason; opens a count, scans the shelf, posts the corrections; flags a floor model; reverses a posting
made in error — and **every one of these is a transaction the database posted**, never a balance written by hand. Levels and movements are
tables anyone here reads; cost shows only behind the wall (Warehouse on receipts).
Schema: `locations`, `inventory_balances` (+ `inv_balances_guard()`), `inventory_transactions` (+ `inv_transactions_before()`,
`inv_transactions_after()`), `inv_allocate()` (slice 5's — not called here), `inv_post_txn()`, `inv_reverse_transaction()`, `goods_receipts`,
`goods_receipt_lines`, `inv_post_receipt()`, `inv_receipt_posted_hook()`, `inventory_transfers`, `inventory_transfer_lines`,
`inv_transfer_send()`, `inv_transfer_receive()`, `inventory_adjustments`, `inventory_adjustment_lines`, `inv_post_adjustment()`,
`inventory_counts`, `inventory_count_lines`, `inv_count_start()`, `inv_post_count()`, `inv_lines_only_while_draft()`,
`inv_transfer_lines_guard()` (db/008); `reason_codes`, `document_sequences`, `inv_number_document()`, `inv_sees_cost()`,
`inv_sees_receipt_cost()` (db/005); `inv_gtin14()`, `inv_is_bundle_variant()` (db/007); `inv_own_stock()` (db/014); the views
`mcp_locations`, `mcp_inventory_balances`, `mcp_inventory_transactions`, `mcp_goods_receipts`, `mcp_goods_receipt_lines`,
`mcp_inventory_transfers`, `mcp_inventory_transfer_lines`, `mcp_inventory_adjustments`, `mcp_inventory_adjustment_lines`,
`mcp_inventory_counts`, `mcp_inventory_count_lines`, `mcp_reason_codes`, `mcp_suppliers`, `mcp_purchase_orders`, `mcp_purchase_order_lines`,
`mcp_product_variants`, `mcp_variant_identifiers`, `mcp_departments`, `mcp_activity_log` (db/015). Never modify them. **The database is the
referee**: a balance is maintained only by the ledger's trigger (a direct write is refused), a transaction is never changed or deleted (it is
reversed, once), a balance never goes negative unless the location allows it, a floor model is on hand and flagged, a bundle never holds stock,
a document's lines change only while it is a draft (a count's while open), a count is open once per location, a reversal is posted once; the
handlers call the posting verb inside `inv_guard()` and show its sentence as a 422; PHP computes no balance.

## Screens (receiving and counting are designed at 375 px first — a phone in the warehouse; the rest usable at 375, designed at 1280)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `stock-levels` | `/stock/?location=&brand=&type=&q=&below_reorder=&page=&format=` | the table `levels-table` (`level-row-{variant}-{location}`): SKU (a link to `variant-view`), product, size, location, on hand, allocated, floor, available (`mcp_inventory_balances`, nonzero rows), a `below reorder` mark when available + on order ≤ the point (the variant's, the product's, the setting's); filters in one row; totals per variant in a footer row when filtered to one variant; `?format=csv` answers the same rows as `text/csv` (what the screen shows, logged `screen.view` with `after.format = csv` — DECISION: the formal exports are slice 9's); 100 a page |
| `movement-list` | `/stock/movements?variant=&location=&type=&from=&to=&page=` | the ledger as a table `movements-table` (`movement-row-{id}`): when, type chip, SKU (a link), location, qty (signed, red when negative), unit cost (walled — "—" with the title "cost withheld"), document (`reference_kind` + the document's number, a link to its view), counterparty (a location, a supplier, a customer, "disposal"), reason, by; **Reverse** on a posted row that has no reversal (`stock.adjust`, `hx-confirm`); the last 30 days by default; 100 a page |
| `floor-model-list` | `/stock/floor-models?location=` | by location, the variants with `qty_floor_model > 0` (`floor-row-{variant}-{location}`): SKU, product, size, on the floor, on hand; the form at the top **Take in / Take out**: variant (the picker `/variants/pick`), location, direction, qty (1), note → `floor_model_set` |
| `location-list` | `/locations/` | cards (`location-card-{id}`): name, kind chip, the department that runs it, address's first line, "sellable" / "not sellable", "allows negative" when so, units on hand (Σ `qty_on_hand`), archived `dark` when inactive; **New location** (`settings.manage`) |
| `location-add` / `location-edit` | `/locations/new`, `/locations/{location}/edit` | the form: name, kind (select of the six), address (textarea), department (select of `find_live_departments()` + none), sellable (checkbox, on), allow negative (checkbox, off), active (`location-edit` only; archiving goes through **Archive** on the view) |
| `location-view` | `/locations/{location}?tab=` | the header: name, kind chip, department, address; **Edit**, **Archive** / **Restore** (`settings.manage`, `hx-confirm`); the tabs — **Levels** (the levels table filtered to it), **Movements** (the last 50), **Transfers** (open ones from or to it), **Counts** (open and the last five posted), **Trail**; `data-entity="location"` |
| `receipt-list` | `/receipts/?status=&supplier=&location=&page=` | the table `receipts-table` (`receipt-row-{id}`): number (a link), status chip, supplier, purchase order, location, received on, lines, posted by/at; drafts first; **New receipt** (`stock.receive`) |
| `receipt-add` | `/receipts/new?purchase_order=&supplier=&location=` | the header form (below) → **Create** lands on `receipt-view` of the draft, where the lines are made; with `?purchase_order=` the open lines of that stock PO (`mcp_purchase_order_lines`: `qty_ordered − qty_received > 0`) are listed as checked rows to prefill — the create posts them as `lines` JSON |
| `receipt-view` | `/receipts/{receipt}` | **the receiving screen** (a draft): the header (number, supplier, PO, location, received on, delivery note); **the scan field** `receipt-form-field-scan` (autofocus; a scanner's keystrokes end in Enter — `stock-scan.js` catches Enter in this field, prevents the submit and POSTs `receipt_line_add` with `variant = <the code>`, `qty = 1`; without JavaScript the field is its own form posting the same); the line form (variant picker, qty, unit cost when `sees_receipt_cost()`, PO line (a select of the PO's open lines), put-away location, discrepancy kind, note); the lines table `receipt-lines-table` (`receipt-line-row-{id}`: line, SKU, product, size, qty, cost (walled by `inv_sees_receipt_cost()` — the view), put-away, discrepancy chip, **Edit** inline (Pattern C), **Remove**); the footer: units, the amount at cost (walled); **Post** (`hx-confirm` "Post GR-00012: N units into Warehouse?"), **Cancel** (`hx-confirm`); a posted receipt: the lines read-only, the movements it posted (`mcp_inventory_transactions` where `reference_kind = 'goods_receipt'`), attachments (a delivery note — slice 8's `attachment_add`), the trail; `data-entity="goods_receipt"` |
| `receipt-edit` | `/receipts/{receipt}/edit` | a draft's header: supplier, purchase order, location, received on, delivery note ref, notes |
| `adjustment-list` | `/adjustments/?status=&location=&reason=&page=` | the table (`adjustment-row-{id}`): number, status chip, location, reason, lines, posted by/at; **New adjustment** (`stock.adjust`) |
| `adjustment-add` | `/adjustments/new?location=&reason=&variant=` | the header: location, reason (select of `mcp_reason_codes` where `'adjustment' = ANY (applies_to)` and active), notes; with `?variant=` one line prefilled (qty delta empty) |
| `adjustment-view` | `/adjustments/{adjustment}` | a draft: the header; the scan field (adds a line with `qty_delta = -1`? — no: DECISION, a scan on an adjustment adds the variant with `qty_delta` left for the person to type, focused — a count is where scanning counts); the line form (variant, qty delta signed and never zero, unit cost when `sees_cost()`, note); the lines table (`adjustment-line-row-{id}`); **Post** (`hx-confirm`; the manifest's `stock_adjust`), **Cancel**; posted: the movements it posted (`floor_model_in/out` rows for a `floor_model` reason) and the trail; `data-entity="inventory_adjustment"` |
| `adjustment-edit` | `/adjustments/{adjustment}/edit` | a draft's reason, notes (the location changes only while no line exists — the handler's sentence) |
| `transfer-list` | `/transfers/?status=&location=&page=` | the table (`transfer-row-{id}`): number, status chip, from → to, lines, units, sent by/at, received by/at; **New transfer** (`stock.transfer`) |
| `transfer-add` | `/transfers/new?from_location=&to_location=&variant=` | from, to (two selects of active locations; equal refused by the CHECK in words), notes; a first line when `?variant=` |
| `transfer-view` | `/transfers/{transfer}` | a draft: the header, the scan field (adds a line of 1 or increments), the line form (variant, qty), the lines table (`transfer-line-row-{id}`: SKU, product, size, qty, received), **Send** (`hx-confirm` "Send TR-00003: N units leave Warehouse?"), **Cancel**; in transit: the lines with a **qty received** input per line (prefilled with qty), **Receive** (`hx-confirm`; short lines say "N left in transit on the document"); received: read-only with both halves' movements; `data-entity="inventory_transfer"` |
| `transfer-edit` | `/transfers/{transfer}/edit` | a draft's from, to, notes |
| `count-list` | `/counts/?status=&location=&page=` | the table (`count-row-{id}`): number, status chip, location, started by/at, lines, differing, posted by/at; **Start a count** (`stock.count`) |
| `count-add` | `/counts/new?location=` | location (select), notes → **Start** (`count_start`) lands on `count-view` |
| `count-view` | `/counts/{count}` | **the counting screen** (open): the header (number, location, started); the scan field `count-form-field-scan` (Enter → `count_line_set` with `variant = <code>` and `counted_qty = <the line's counted + 1>` — the script keeps the running count per SKU; without JavaScript the field posts `counted_qty` typed beside it); the lines table `count-lines-table` (`count-line-row-{id}`: SKU, product, size, system qty, **counted** (a number input per row, saved on change — Pattern C, `count_line_set`), difference (coloured), counted by); a variant not on the count (nothing on hand) scanned → a new line with `system_qty` = the balance now (0) and `counted_qty` 1 (DECISION); the footer: lines counted / total, differing; **Post** (`hx-confirm` "Post CNT-00002: N corrections?"; `count_post`), **Cancel**; posted: read-only with the corrections' movements; `data-entity="inventory_count"` |

## The receipt header form (`receipt-form`, ids `receipt-form-field-{name}`)
| Field | Input | Required | Rule |
|---|---|---|---|
| location | select (active locations) | yes | where it lands; a line's put-away location overrides |
| supplier | select (`mcp_suppliers` active + none) | no | a free receipt has none |
| purchase_order | select of open stock POs of the supplier (`mcp_purchase_orders`, kind stock, status sent/acknowledged/partial) + none; shown after a supplier is chosen (a small `hx-get` to `/receipts/po-options?supplier=`, Pattern A) | no | the PO's supplier must match (the handler's sentence) |
| received_on | date | yes | today by default; not in the future |
| delivery_note_ref | text ≤ 100 | no | |
| notes | textarea ≤ 2,000 | no | |
| lines | JSON (an agent, or the PO prefill) `[{variant|barcode, qty, unit_cost, putaway_location, purchase_order_line, discrepancy_kind, discrepancy_note}]` | no | each line through the same validation as `receipt_line_add` |

A line (`receipt-line-form`): variant — the picker, or `barcode` as scanned (resolved by `variant_by_scan()`: `inv_gtin14()` against
`product_variants.barcode` or a gtin/upc/ean identifier, else the SKU exact; none → "No variant has the code 00812345000123."); qty ≥ 1;
unit_cost ≥ 0 (shown and accepted only for `sees_receipt_cost()` — a Sales caller sending one is refused in words); purchase_order_line (a line of
the receipt's PO, same variant); putaway_location (active); discrepancy_kind none/short/over/damaged/wrong_item/substitute + note. **A scan of a
variant already on the draft increments that line's qty by one** (DECISION) — a typed add makes a new line. A bundle variant → the trigger's
sentence when posted; the handler refuses it at the line ("A bundle never holds stock — receive its components") so the scan says so at once.

## Posting, step by step (what each handler calls)
- **Receipt** `receipt_post` → `SELECT * FROM inv_post_receipt(:id, :me)`: every line a `receipt` transaction at its put-away location, cost on
  the row; with `cost_source = last_receipt` the variant's cost follows (`price_history` source `last_receipt`, reason "receipt GR-…");
  `inv_receipt_posted_hook()` updates the PO (db/011's). Log `stock.receive` (`number`, `supplier_id`, `purchase_order_id`, `location_id`,
  `lines`, `units`, `amount` Σ qty × cost, `currency`, `discrepancies` count) with `purchase_order_id` and `location_id` as audit keys.
- **Adjustment** `stock_adjust` → `inv_post_adjustment(:id, :me)`: an `adjustment` transaction per line (a `floor_model` reason posts
  `floor_model_in` / `floor_model_out` instead — the units move onto or off the floor, the count unchanged); a donation's counterparty is
  `disposal`. **deletion** for an agent. Log `stock.adjust` (`number`, `location_id`, `reason_code`, `lines`, `units_delta`, `amount`).
- **Transfer** `transfer_send` → `inv_transfer_send(:id, :me)`: `transfer_out` at the origin (negative; refused in the trigger's words when the
  origin cannot cover it and does not allow negative); the stock is "in transit on the document". `transfer_receive` →
  `inv_transfer_receive(:id, :me, :quantities::jsonb)` with `{"<line_id>": qty}` from the per-line inputs (every line in full when absent):
  `transfer_in` at the destination for what arrived; a short line leaves the difference on the document (the screen says "N left in transit —
  adjust or reverse"). Log `stock.transfer_send` / `stock.transfer_receive` (`number`, `from_location_id`, `to_location_id`, `lines`, `units`,
  `short: [{sku, qty, qty_received}]`) with `location_id` = the origin on send, the destination on receive.
- **Count** `count_start` → `inv_count_start(:location, :me, :notes)` (one open per location — the sentence); `count_line_set` → UPDATE the line's
  `counted_qty`, `counted_by`, `counted_at` (INSERT a line for a scanned variant with none); `count_post` → `inv_post_count(:id, :me)`: a
  `count_correction` per counted line whose counted ≠ on hand **now** (the schema's decision), reason `correction`; uncounted lines change
  nothing. **deletion** for an agent. Log `stock.count_start` (`number`, `location_id`, `lines`) / `stock.count_post` (`number`, `location_id`,
  `lines`, `lines_differing`, `units_delta`).
- **Floor model** `floor_model_set` → one adjustment: INSERT `inventory_adjustments` (location, reason `floor_model`), one line (`qty_delta` = +qty
  for in, −qty for out, the note), `inv_post_adjustment()` — all in one transaction (DECISION: the schema has no floor verb; a floor move IS an
  adjustment with the non-counting reason, and the document is its record). Log `stock.floor_model` (`sku`, `location_id`, `direction`, `qty`,
  `adjustment_id`).
- **Reverse** `stock_reverse` → `inv_reverse_transaction(:txn, :me, :note)`: the opposite movement, linked, once ("already reversed" → 422).
  Log `stock.reverse` (`transaction_id`, `reverses_id`, `txn_type`, `sku`, `location_id`, `qty`, `reference_kind`, `reference_id`).
- **Drafts**: `receipt_draft` / `adjustment_draft` / `transfer_draft` INSERT the header (the number by trigger) and the `lines` given, in one
  transaction; `*_line_add` / `*_line_remove` write the lines (the draft guard refuses after posting, in its words); `*_cancel` sets `status =
  cancelled` on a draft (a posted one → "GR-00012 is posted — reverse its movements instead"); `receipt_update` and the other header edits are
  the `*_draft` file with the document's id (`receipt`, `adjustment`, `transfer` — the manifest's `any field of`).
- **Locations**: `location_create` / `location_update` INSERT / UPDATE; `location_archive` with `active` no → refused while any balance at it has
  `qty_on_hand <> 0 OR qty_allocated <> 0 OR qty_floor_model <> 0` ("Warehouse still holds 148 units — move or adjust them first"); yes restores.

## Files (exactly these)
- `html/stock/index.php` (`stock-levels`) · `movements.php` (`movement-list`) · `floor-models.php` (`floor-model-list`) · `floor-model.php` (`floor_model_set`) · `reverse.php` (`stock_reverse`)
- `html/locations/index.php` (`location-list`) · `form.php` (`location-add`, `location-edit`) · `view.php` (`location-view`) · `save.php` · `archive.php`
- `html/receipts/index.php` (`receipt-list`) · `form.php` (`receipt-add`, `receipt-edit`) · `view.php` (`receipt-view`) · `save.php` · `post.php` · `cancel.php` · `po-options.php` (Pattern A) · `lines/save.php` · `lines/remove.php`
- `html/adjustments/index.php` · `form.php` · `view.php` · `save.php` · `post.php` (`stock_adjust`) · `cancel.php` · `lines/save.php` · `lines/remove.php`
- `html/transfers/index.php` · `form.php` · `view.php` · `save.php` · `send.php` · `receive.php` · `cancel.php` · `lines/save.php` · `lines/remove.php`
- `html/counts/index.php` · `form.php` (`count-add`) · `view.php` (`count-view`) · `start.php` · `post.php` · `cancel.php` · `lines/save.php`
- `app/features/stock/{queries,present,write,handler}.php` (`handler.php`: `document_from_request()` for the four documents, `require_draft()`, `stock_log()` — `location_id` on every row, `purchase_order_id` on a receipt's) · `app/features/locations/{queries,present,write,handler}.php`
- `app/views/stock/{levels,movements,floor-models,partials/level-row,partials/movement-row,partials/scan-field,partials/line-form}.php` · `app/views/locations/{index,form,view,partials/location-card}.php` · `app/views/receipts/{index,form,view,partials/receipt-row,partials/line-row,partials/po-lines}.php` · `app/views/adjustments/{index,form,view,partials/line-row}.php` · `app/views/transfers/{index,form,view,partials/line-row}.php` · `app/views/counts/{index,form,view,partials/count-line-row}.php`
- `html/assets/js/stock-scan.js` (the scan field: Enter → the POST; the running count on `count-view`; re-bound on `htmx:afterSwap`)
- `app/auth.php` gains `sees_receipt_cost(): bool` (`inv_sees_receipt_cost()`, cached per request) beside `sees_cost()`
- `app/partial_update.php`: `PARTIAL_UPDATE_TARGETS` gains `'/locations/save.php' => ['locations', 'location', 'mcp_locations', 'location_id']`, `'/receipts/save.php' => ['goods_receipts', 'receipt', 'mcp_goods_receipts', 'goods_receipt_id']`, `'/adjustments/save.php' => ['inventory_adjustments', 'adjustment', 'mcp_inventory_adjustments', 'adjustment_id']`, `'/transfers/save.php' => ['inventory_transfers', 'transfer', 'mcp_inventory_transfers', 'transfer_id']`
- `app/features/shell/nav.php`: `back_link()` learns `/locations/{id}` "the location", `/receipts/{id}` "the receipt", `/adjustments/{id}` "the adjustment", `/transfers/{id}` "the transfer", `/counts/{id}` "the count"; `record_url()` learns location, goods_receipt, inventory_adjustment, inventory_transfer, inventory_count

## Query functions (signatures fixed; the tools of Phase 4 call these — S1–S4 and the `location` resolver)
- `stock_levels(PDO, array $filters, int $limit = 100, int $offset = 0): array` (`mcp_inventory_balances` ⨝ `mcp_product_variants`; `variant`, `product`, `brand`, `product_type`, `location`, `q`, `below_reorder`, `nonzero_only` true) + `stock_totals(PDO, array $filters): array` (`{on_hand, allocated, floor_model, available}`) — tool `stock_levels`
- `stock_by_location(PDO, int $locationId, array $filters, int $limit = 100, int $offset = 0): array` (`{location, rows, totals}`) — tool `stock_by_location`
- `stock_movements(PDO, array $filters, int $limit = 100, int $offset = 0): array` (`mcp_inventory_transactions`; `variant`, `location`, `txn_type[]`, `reference_kind` + `reference_id`, `from`, `to` — the last 7 days by default for the tool, 30 for the screen) + `movement_document_url(array $row): ?string` — tool `stock_movements`
- `find_locations(PDO, ?string $q = null, ?string $kind = null, bool $sellableOnly = false, bool $includeInactive = false, int $limit = 100): array` (`mcp_locations` + units on hand) — the resolver `find_locations` · `find_location(PDO, int $id): ?array` · `location_holds(PDO, int $id): int` (units on hand + allocated + floor — the archive check)
- `transfers_open(PDO, array $filters, int $limit = 100, int $offset = 0): array` (`mcp_inventory_transfers`; `from_location`, `to_location`, `status[]` default draft + in_transit) · `find_transfer(PDO, int $id): ?array` · `transfer_lines(PDO, int $transferId): array` (`mcp_inventory_transfer_lines`) — tool `transfers_open`
- `receipts_open(PDO, array $filters, int $limit = 100, int $offset = 0): array` (`mcp_goods_receipts`; `supplier`, `purchase_order`, `location`, `status[]` default draft, `from`, `to`) · `find_receipt(PDO, int $id): ?array` · `receipt_lines(PDO, int $receiptId): array` (`mcp_goods_receipt_lines`) · `receipt_movements(PDO, int $receiptId): array` · `po_open_lines(PDO, int $purchaseOrderId): array` (`mcp_purchase_order_lines` with `qty_ordered − qty_received > 0`) · `po_options(PDO, int $supplierId): array` — tool `receipts_open`
- `counts(PDO, array $filters, int $limit = 100, int $offset = 0): array` (`mcp_inventory_counts`; `location`, `status`, `since`) · `find_count(PDO, int $id): ?array` · `count_lines(PDO, int $countId, bool $differingOnly = false): array` (`mcp_inventory_count_lines`; on a posted count each row + `correction_transaction_id` from `mcp_inventory_transactions` where `reference_kind = 'count'`) — tools `counts`, `count_lines`
- `adjustments(PDO, array $filters, int $limit = 100, int $offset = 0): array` · `find_adjustment(PDO, int $id): ?array` · `adjustment_lines(PDO, int $id): array` (the screens'; no tool — the tool surface's DECISION 20) · `adjustment_reasons(PDO): array` (`mcp_reason_codes` for `adjustment`)
- `document_movements(PDO, string $referenceKind, int $referenceId): array` (`mcp_inventory_transactions`) · `floor_models(PDO, ?int $locationId): array`
- `variant_by_scan(PDO, string $code): ?array` (`mcp_product_variants` by GTIN-14 — `inv_gtin14()` against `barcode` or `mcp_variant_identifiers` gtin/upc/ean — else SKU exact; null when none; a bundle answers with `kind = 'bundle'` so the handler may refuse it)
- Writes (`write.php`): `save_location(PDO, ?int $id, array $fields): int` · `archive_location(PDO, int $id, bool $active): void` · `save_receipt(PDO, ?int $id, array $fields, array $lines, int $by): int` · `save_receipt_line(PDO, int $receiptId, ?int $lineId, array $fields): int` (the next `line_no`; an increment when the variant is already on the draft and no `line` was given — the scan rule) · `remove_receipt_line(PDO, int $lineId): array` · `post_receipt(PDO, int $id, int $by): array` (the row + the movements, units, amount) · `cancel_document(PDO, string $table, int $id, string $draftStatus): void` · `save_adjustment(...)`, `save_adjustment_line(...)`, `remove_adjustment_line(...)`, `post_adjustment(PDO, int $id, int $by): array` · `save_transfer(...)`, `save_transfer_line(...)`, `remove_transfer_line(...)`, `send_transfer(PDO, int $id, int $by): array`, `receive_transfer(PDO, int $id, int $by, array $quantities): array` (the row + `short[]`) · `start_count(PDO, int $locationId, int $by, ?string $notes): array` · `set_count_line(PDO, int $countId, int $variantId, int $countedQty, int $by): int` · `post_count(PDO, int $id, int $by): array` (the row + corrections, units_delta) · `set_floor_model(PDO, int $variantId, int $locationId, string $direction, int $qty, ?string $note, int $by): array` (the adjustment id + the transaction) · `reverse_transaction(PDO, int $txnId, int $by, ?string $note): array` (the new row)

## Handlers (every one: `inv_handler_begin()`; the right; `inv_guard()`; `log_activity` with `location_id` (and `purchase_order_id` on a receipt); `inv_done()`; `HX-Trigger: stockChanged` — `locationChanged` for a location)
- `locations/save.php` (`location_create` / `location_update`): `settings.manage`; `location.create` (`after`: name, kind, is_sellable, allow_negative, department_id) / `location.update` (`inv_diff()`); location `/locations/{id}`. `locations/archive.php` (`location_archive`): `settings.manage`; `location_holds()` → 422; `location.archive` (`after.active`).
- `receipts/save.php` (`receipt_draft`, with `receipt` the header update): `stock.receive`; a PO of another supplier → 422; `stock.receipt_draft` (`number`, `supplier_id`, `purchase_order_id`, `location_id`, `lines`); location `/receipts/{id}`. `receipts/lines/save.php` (`receipt_line_add`): `stock.receive`; `unit_cost` only for `sees_receipt_cost()`; `stock.receipt_line` (`line_id`, `sku`, `qty`, `unit_cost`, `discrepancy_kind`, `incremented: bool`); location `/receipts/{receipt}#receipt-line-row-{id}`; a Pattern C request answers the row. `receipts/lines/remove.php` (`receipt_line_remove`): `stock.receipt_line_remove`. `receipts/post.php` (`receipt_post`): `stock.receive` as above; location `/receipts/{id}`. `receipts/cancel.php` (`receipt_cancel`): `stock.receipt_cancel`.
- `adjustments/save.php` (`adjustment_draft` / the header): `stock.adjust`; a reason not for adjustments → 422 (the posting's sentence, checked early); `stock.adjustment_draft`; `adjustments/lines/save.php` (`adjustment_line_add`): `qty_delta` ≠ 0; `unit_cost` only for `sees_cost()`; `stock.adjustment_line`; `adjustments/lines/remove.php`: `stock.adjustment_line_remove`; `adjustments/post.php` (`stock_adjust`): `stock.adjust`; **deletion**; `adjustments/cancel.php`: `stock.adjustment_cancel`.
- `transfers/save.php` (`transfer_draft` / the header): `stock.transfer`; `stock.transfer_draft` (`number`, `from_location_id`, `to_location_id`, `lines`); `transfers/lines/save.php` (`transfer_line_add`): `stock.transfer_line`; `transfers/lines/remove.php`; `transfers/send.php` (`transfer_send`): `stock.transfer_send`; `transfers/receive.php` (`transfer_receive`): `quantities` JSON `{line: qty}` or `qty[<line>]` from the form; `stock.transfer_receive`; `transfers/cancel.php`: `stock.transfer_cancel`.
- `counts/start.php` (`count_start`): `stock.count`; `stock.count_start`; location `/counts/{id}`. `counts/lines/save.php` (`count_line_set`): `variant` or its barcode; `counted_qty` ≥ 0; `stock.count_line` (`sku`, `system_qty`, `counted_qty`); a Pattern C request answers the row. `counts/post.php` (`count_post`): `stock.count_post`; **deletion**. `counts/cancel.php` (`count_cancel`): `stock.count_cancel`.
- `stock/floor-model.php` (`floor_model_set`): `stock.adjust`; direction in/out; qty ≥ 1 (1 by default); `stock.floor_model`; location `/stock/floor-models?location={location}`.
- `stock/reverse.php` (`stock_reverse`): `stock.adjust`; `stock.reverse`; location `/stock/movements?variant={variant}#movement-row-{new id}`.

## Manifest rows claimed
Screens (22): `stock-levels`, `movement-list`, `floor-model-list`, `location-list`, `location-add`, `location-view`, `location-edit`, `receipt-list`, `receipt-add`, `receipt-view`, `receipt-edit`, `adjustment-list`, `adjustment-add`, `adjustment-view`, `adjustment-edit`, `transfer-list`, `transfer-add`, `transfer-view`, `transfer-edit`, `count-list`, `count-add`, `count-view`
Actions (25): `location_create`, `location_update`, `location_archive`, `receipt_draft`, `receipt_line_add`, `receipt_line_remove`, `receipt_post`, `receipt_cancel`, `adjustment_draft`, `adjustment_line_add`, `adjustment_line_remove`, `stock_adjust`, `adjustment_cancel`, `transfer_draft`, `transfer_line_add`, `transfer_line_remove`, `transfer_send`, `transfer_receive`, `transfer_cancel`, `count_start`, `count_line_set`, `count_post`, `count_cancel`, `floor_model_set`, `stock_reverse`
Agent approvals: `stock_adjust`, `count_post` (`deletion`).

| Action | File | Log | Who | Approval |
|---|---|---|---|---|
| `location_create` | `/locations/save.php` | `location.create` | settings.manage | |
| `location_update` | `/locations/save.php` | `location.update` | settings.manage | |
| `location_archive` | `/locations/archive.php` | `location.archive` | settings.manage | |
| `receipt_draft` | `/receipts/save.php` | `stock.receipt_draft` | stock.receive | |
| `receipt_line_add` | `/receipts/lines/save.php` | `stock.receipt_line` | stock.receive | |
| `receipt_line_remove` | `/receipts/lines/remove.php` | `stock.receipt_line_remove` | stock.receive | |
| `receipt_post` | `/receipts/post.php` | `stock.receive` | stock.receive | |
| `receipt_cancel` | `/receipts/cancel.php` | `stock.receipt_cancel` | stock.receive | |
| `adjustment_draft` | `/adjustments/save.php` | `stock.adjustment_draft` | stock.adjust | |
| `adjustment_line_add` | `/adjustments/lines/save.php` | `stock.adjustment_line` | stock.adjust | |
| `adjustment_line_remove` | `/adjustments/lines/remove.php` | `stock.adjustment_line_remove` | stock.adjust | |
| `stock_adjust` | `/adjustments/post.php` | `stock.adjust` | stock.adjust | deletion |
| `adjustment_cancel` | `/adjustments/cancel.php` | `stock.adjustment_cancel` | stock.adjust | |
| `transfer_draft` | `/transfers/save.php` | `stock.transfer_draft` | stock.transfer | |
| `transfer_line_add` | `/transfers/lines/save.php` | `stock.transfer_line` | stock.transfer | |
| `transfer_line_remove` | `/transfers/lines/remove.php` | `stock.transfer_line_remove` | stock.transfer | |
| `transfer_send` | `/transfers/send.php` | `stock.transfer_send` | stock.transfer | |
| `transfer_receive` | `/transfers/receive.php` | `stock.transfer_receive` | stock.transfer | |
| `transfer_cancel` | `/transfers/cancel.php` | `stock.transfer_cancel` | stock.transfer | |
| `count_start` | `/counts/start.php` | `stock.count_start` | stock.count | |
| `count_line_set` | `/counts/lines/save.php` | `stock.count_line` | stock.count | |
| `count_post` | `/counts/post.php` | `stock.count_post` | stock.count | deletion |
| `count_cancel` | `/counts/cancel.php` | `stock.count_cancel` | stock.count | |
| `floor_model_set` | `/stock/floor-model.php` | `stock.floor_model` | stock.adjust | |
| `stock_reverse` | `/stock/reverse.php` | `stock.reverse` | stock.adjust | |

Every row of the manifest's stock section is claimed; none is left to another slice. (The tools `stock_value`, `sell_through` are slice 9's
query functions and `reorder_candidates` slice 8's — their functions exist in db/014; their screens are the reports and the morning note.)

## Activity log events (every row: `location_id`; a receipt's `purchase_order_id`)
`location.create|update|archive`, `stock.receipt_draft|receipt_line|receipt_line_remove|receive|receipt_cancel`,
`stock.adjustment_draft|adjustment_line|adjustment_line_remove|adjust|adjustment_cancel`,
`stock.transfer_draft|transfer_line|transfer_line_remove|transfer_send|transfer_receive|transfer_cancel`,
`stock.count_start|count_line|count_post|count_cancel`, `stock.floor_model`, `stock.reverse`, `screen.view` (the document views with
`after.number`). Amounts and costs are expected in `after` (the log is the record; the views wall the reader).

## Notifications this slice queues
None (a receipt against a purchase order tells nobody here; slice 6's PO view reads the receipt).

## Status vocabulary
Document status chips: draft `secondary`, posted `success`, cancelled `dark`; a transfer in_transit `info`, received `success`; a count open
`warning`, posted `success`. Movement type chips: receipt `success`, issue / sale / transfer_out `danger`, transfer_in / return `success`,
adjustment `warning`, count_correction `info`, floor_model_in / floor_model_out `secondary`, reversal `dark`. Discrepancy chips: short / damaged
/ wrong_item `danger`, over / substitute `warning`. Location kind chips: warehouse `primary`, showroom `info`, store `success`, in_transit
`secondary`, returns `warning`, offsite `dark`. Ids: `levels-table`, `levels-filters`, `level-row-{variant}-{location}`, `movements-table`,
`movement-row-{id}`, `movement-row-{id}-reverse`, `floor-table`, `floor-row-{variant}-{location}`, `floor-form`, `floor-form-field-{name}`,
`location-list`, `location-card-{id}`, `location-form`, `location-form-field-{name}`, `receipts-table`, `receipt-row-{id}`, `receipt-form`,
`receipt-form-field-{name}`, `receipt-form-field-scan`, `receipt-line-form`, `receipt-line-form-field-{name}`, `receipt-lines-table`,
`receipt-line-row-{id}`, `receipt-post`, `receipt-cancel`, `adjustment-form`, `adjustment-line-form`, `adjustment-lines-table`,
`adjustment-line-row-{id}`, `transfer-form`, `transfer-line-form`, `transfer-lines-table`, `transfer-line-row-{id}`, `transfer-line-row-{id}-received`,
`transfer-send`, `transfer-receive`, `count-form`, `count-form-field-scan`, `count-lines-table`, `count-line-row-{id}`, `count-line-row-{id}-counted`,
`count-post`.

## Mobile rule (375 × 740)
The receiving and counting screens are one column: the scan field first and sticky under the header, the line form folded under "Add by
hand", the lines table scrolling inside the page with SKU · qty · (cost) visible and the rest under a tap; Post and Cancel in the page header
(the form-header rule); every control ≥ 44 px; `scrollWidth` = viewport. The levels and movements tables scroll inside `.table-responsive`.

## Vocabulary
*location* (where stock is kept — a record, never a wall), *balance* (on hand, allocated, floor model; available = on hand − allocated −
floor), *transaction / movement* (a signed row of the ledger — the only thing that changes a balance), *document* (a receipt, an adjustment,
a transfer, a count — numbered at creation, posted by a function), *put-away* (the line's location when not the receipt's), *in transit* (sent
and not yet received — on the document), *correction* (a count's posting), *floor model* (on hand and flagged; a `floor_model` adjustment moves
it), *reversal* (the opposite movement, linked, once), *scan* (a barcode typed by a scanner, Enter-terminated).

## Out of scope for this slice
Allocation and issue by a sale (`inv_allocate()`, shipments — slice 5); receiving against a purchase order from the PO's own screen
(`purchase_order_receive` — slice 6, which opens this slice's receipt), the supplier's door; returns' `return` transactions (slice 8); stock value,
sell-through and the exports (slice 9); the reorder run (slice 8); serial numbers (Extended).

## Proof (`tests/phase3/slice2/run.sh`: the scratch database `inv_dev2b`, the application on 8601, the fake kernel 8602, the fake MaluDB 8603,
the fake MaluMail 8606; members through dev hand-offs, curl with signed action and run tokens, headless Chromium at 375 × 740 and 1280 × 800;
the registry `--check`; `sync_approvals --check`)
Target: **≥ 240 checks** (locations 20, receipts 50, adjustments 25, transfers 35, counts 30, floor 15, reverse 15, levels and movements 20, visibility 20, json 15, browser 40).
The world (`tests/phase3/slice2/lib.php` `stock_world()`): slice 1's `catalog_world()` (the six Cloudrest sizes with GTINs, the topper, the pillow,
the Queen set); the owner makes "SMOKE Warehouse" (warehouse, allows negative off), "SMOKE Showroom" (showroom, sellable), "SMOKE Returns"
(returns, not sellable); Wes (Warehouse), Nora (Buyer), Sam (Sales), Vera (Viewer).
- [ ] **Locations**: three created; a duplicate name refused; Nora (no `settings.manage`) → 403 in words; archive refused while units are on hand (after the receipt below) with the holding sentence; an empty one archived and restored; the cards with units on hand; `location.create|archive` logged with `location_id`.
- [ ] **Receipts**: Wes drafts a free receipt at the Warehouse; scans the Queen's GTIN (the fixture's) → a line of 1; scans it again → qty 2 on the same line; a UPC of 12 digits resolves; an unknown code → "No variant has the code …"; a bundle's SKU → "A bundle never holds stock — receive its components"; types a line of 4 King at cost 189.00 with put-away Showroom; a line on a posted receipt refused in the trigger's words; posts → `receipt` transactions at the put-away locations, the balances 2 and 4, the King's `cost_price` 189.00 with history source `last_receipt` and reason "receipt GR-…"; a second post → "GR-… is posted — only a draft posts"; cancel refused after posting; a draft with no lines → "has no lines"; Sam's unit_cost on a line → refused in words (no `sees_receipt_cost()`); `stock.receive` logged with `units`, `amount`, `currency`, `location_id`; the JSON reply's `record_id` and `location`.
- [ ] **Adjustments**: Wes drafts a `damaged` adjustment at the Warehouse, a line −1 Queen, posts → on hand 1, the movement's counterparty none and reason damaged; a `donation` → counterparty disposal; a zero delta refused by the CHECK in words; −5 where 1 is on hand → the trigger's "Not enough on hand" sentence and the draft stays a draft; a return reason (`comfort`) on an adjustment → 422 at the draft; cancel a draft; `stock.adjust` logged with `units_delta`, `amount`.
- [ ] **Transfers**: a draft Warehouse → Showroom of 2 King, from = to refused by the CHECK; send → `transfer_out` −2 at the Warehouse (on hand 2), status in_transit, a line added after sending refused by the guard; receive 1 short (`quantities`) → `transfer_in` +1 at the Showroom, `qty_received` 1, the screen says "1 left in transit"; a full receive on a second transfer; receiving a draft → "only one in transit is received"; sending more than on hand → the trigger's sentence; `stock.transfer_send|transfer_receive` logged with `short`.
- [ ] **Counts**: Wes starts a count at the Showroom (lines for the 4 King and 1 Queen… the balances there); a second start at the same location → "A count is already open"; scans the King three times → counted 3; types 0 for a line; scans a Twin with nothing there → a new line system 0 counted 1; a receipt posted mid-count moves on hand; post → corrections = counted − on hand NOW (the schema's rule proven: the mid-count receipt is not counted twice), reason `correction`, uncounted lines untouched; a line changed after posting refused; `count_post` **deletion** in the registry; cancel an open count posts nothing.
- [ ] **Floor models**: Nora takes a Queen in at the Showroom → `floor_model_in` +1, `qty_floor_model` 1, on hand unchanged, available −1; out of 2 where 1 is flagged → "There are not that many floor models"; a floor model cannot exceed on hand (the trigger); the list shows it; the adjustment document exists with reason `floor_model`; `stock.floor_model` logged with `direction`.
- [ ] **Reverse**: the damaged adjustment's movement reversed → a `reversal` +1 linked by `reverses_id`, on hand back to 2; reversing it again → "already reversed"; a floor move reversed as the opposite floor move; a direct `UPDATE inventory_transactions` and a direct `UPDATE inventory_balances` by SQL refused in the referee's words; `stock.reverse` logged with `reverses_id`.
- [ ] **Levels and movements**: the levels table agrees with `inv_own_stock()` for every variant; filters by location, brand, q (SKU), below_reorder (a reorder point set on the Queen); the CSV answers the same rows; the movements table shows every posting with its document link and reason; the type filter; the 30-day default; `?format=csv` logged as `screen.view` with `format`.
- [ ] **Who sees**: Vera reads levels and movements with cost "—"; Wes sees cost on receipt rows and receipt lines (`inv_sees_receipt_cost()`) but not on an adjustment's `unit_cost`; Nora sees every cost; Sam cannot open `/receipts/`, `/counts/` (403 in words) but reads `/stock/`; the levels view admits every reader (locations are not walls).
- [ ] **JSON mode**: every handler under a signed action token answers `{ok, did, record_id, location, refresh}`; a `receipt_draft` with `lines` JSON (one by barcode) lands whole; `transfer_receive` with `quantities`; `_partial=1` on a receipt header keeps the untouched fields; 422 `{error: {code: invalid, fields}}`; the expert (run token + relay) drafts a transfer as `source agent` (its `stock_adjust` pauses on the MCP path — Phase 4).
- [ ] **375 × 740 and 1280 × 800**: the receiving screen on the phone — the scan field focused, a typed code + Enter adds a line without a page load, the lines table readable, Post through a dialog; the counting screen's running count; the transfer's per-line received inputs; the location cards stack; every control ≥ 44 px, `scrollWidth` = viewport, no console errors; JavaScript off: the scan field posts as a form and the count's typed quantities save; the registry reads 43 screens and 45 actions built.

**Decisions taken in this spec (not questions):**
- A floor move is an adjustment with the `floor_model` reason, drafted and posted in one transaction by `floor_model_set` (the schema has no floor verb; the document is the record). Its log event is the manifest's `stock.floor_model` with `after.direction`.
- A scan of a variant already on a draft receipt or transfer increments that line by one; a scan on an adjustment adds the variant and leaves the delta to type; a scan on a count adds one to the running count, and a variant with no line gets one with `system_qty` = the balance now.
- The levels screen's `?format=csv` is the same rows as `text/csv`, logged `screen.view`; the formal exports are slice 9's `export_download`.
- The movements screen defaults to the last 30 days (the tool to 7 — the tool surface's); the same query function with a different default.
- A location is archived only when nothing is on hand, allocated or on the floor there (the handler's sentence; the schema has no rule).
- A receipt's PO select reads `mcp_purchase_orders` / `mcp_purchase_order_lines` (db/011 exists); slice 6 builds the PO's own screens and `purchase_order_receive`, which opens this slice's receipt with `?purchase_order=`.
- `unit_cost` on a receipt line is accepted only from a caller `inv_sees_receipt_cost()` admits; on an adjustment line only from `inv_sees_cost()`; others are refused in words rather than silently dropped.

## Open questions
(none)

## Built and proven
**2026-10-09 — BUILT and proven by the planning model** (`tests/phase3/slice2/run.sh` on the scratch database `inv_dev2b`: **325 checks green under php
-S and under a real Apache** — world 5, locations 22, receipts 46, adjustments 25, transfers 36, counts 28, floor 16, reverse 16, levels and movements 27,
visibility 27, json 34, browser 41 at 375 × 740, 1280 × 800 and JavaScript off; the registry — **43 screens and 45 actions built**, 25 placeholders — and
the approvals in step). Phase 2 and slice 1 re-run green, Phase 0 green (42 + 517 + 511), the Phase 1 claim checks green (52), the installer's plan
clean. Every file of "Files" is built, plus `app/views/stock/partials/{levels-table,movements-table}.php` (the two tables the location view, the levels,
the movements and the documents share), `app/views/receipts/partials/po-options.php` (Pattern A's fragment) and **`db/018_transfer_receive_flag.sql`**.

**Found and fixed (not questions):**
- **A transfer of two lines or more could never be received** (`db/008` `inv_transfer_receive()`): it raised the writer flag once before its loop, but
  every posting lowers it (`inv_transactions_after()`), so the second line's `qty_received` met `inv_transfer_lines_guard()` and the whole receive was
  refused in the guard's words. `db/018` raises the flag before every line's UPDATE, as `inv_ship()` and the returns already do. Additive; the schema
  proof stays 517.
- **A table's CHECK can speak before the trigger's sentence**: taking off more floor models than are flagged trips `inventory_balances`'s
  `qty_floor_model >= 0` CHECK before `inv_transactions_after()` can say "There are not that many floor models to take off the floor", and the kit
  answered the raw "new row … violates check constraint". `db_message()` now maps the ledger's CHECK names to their sentences (`DB_CHECK_SENTENCES`)
  and never shows a raw constraint text; an unmapped one answers the fallback.
- **The theme clips `.main-content`** (`overflow: hidden auto`), which makes it the sticky scan field's scroller: `.main-content:has(.scan-sticky)` lifts
  it, and the field sticks at 80 px, under the fixed header.

**Decisions taken while building (not questions):**
- **The running count is the server's**: a count scan that carries no quantity adds one to the line's counted quantity inside the handler's transaction
  (the count row locked), so two fast scans never race; a typed or Pattern C quantity sets it. `stock-scan.js` queues every Enter and posts them in order,
  clears the field at once and refreshes the page region from the last landing when the queue is empty — the spec's "the script keeps the running
  count" is kept by the server instead, which is the same count and cannot lose a scan.
- **The scan field posts `barcode`**; `variant` from the picker is an id, and an agent may pass a SKU or a code as `variant` (a number of eight digits or
  more, or any non-number, is resolved as a code). The scan rule (increment the line) applies only to a resolved code.
- **An adjustment's scan stops at the delta**: the field carries a "Change" input; Enter with no change typed moves the focus there, Enter there sends.
- **A scan lands on the scan form, a typed line on its row** (no-JavaScript landing): repeated scans keep the field in view.
- **`unit_cost` on a receipt line from a caller without `inv_sees_receipt_cost()` is refused in words**, as the spec says — but that sentence cannot be
  reached today: `stock.receive`, the right every receipt handler needs, is itself what `inv_sees_receipt_cost()` admits. The proof shows Sam refused at
  the door ("You may not receive goods.") instead; the adjustment's refusal (Wes, `stock.adjust` without `cost.read`) is reached and proven.
- **A receipt line against a PO line with no cost given takes the PO line's cost**; the PO prefill posts `po_lines[]` + `po_qty[<line>]` from the form
  (an agent sends `lines` JSON with `purchase_order_line`); the supplier is taken from the PO when the form leaves it empty.
- **A location's Active box on the edit form obeys the archive rule** (refused while anything is held there), not only the Archive button.
- **The levels screen is titled "Stock levels"** (the menu says Levels, the tab says Stock); the totals row shows when filtered to one variant or one
  location; Phase 2's browser proof now opens the built levels from the Stock tab.
- Header actions (Post, Cancel, Send, Receive) sit in one wrapping row (`.doc-actions`); the receive form's button is in the header through `form=`.
- The proof clicks three no-JavaScript buttons with `force` — under the theme's smooth scrolling and a sticky field Playwright never reads them as
  "stable"; a person's tap is unaffected (the JavaScript-on taps are not forced).

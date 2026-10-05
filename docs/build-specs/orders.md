# Build spec: customers and sales orders — slice 5

The first slice after the handoff (the worker model's). What exists at the end: customers as cards and a form; a quote written on a phone with the
**availability picker per line** (slice 4's compact partial — stock at a location, a supplier's offer with its lead time and cost behind the wall, pickup,
backorder); the quote confirmed — every stock line allocated, one drop-ship purchase order **drafted** per supplier (the purchasing slice owns the PO
screens; this slice owns the call); payments and refunds **recorded**, never charged (D10); the confirmation email with the customer's link; shipping
(pick lines, carrier, tracking — a stock line issues); delivery; close; cancel; the customer's door `/o/<token>`; fulfilment today; the shipments list.
Replicates slice 1 (the catalog) for cards, forms and the record page, slice 4 for the picker, and GL's receivables for the lines editor and the door.
Schema: `customers`, `sales_orders`, `sales_order_lines`, `order_payments`, `shipments`, `shipment_lines`, `order_links_secure`, `inv_order_lines_before()`,
`inv_order_recompute()`, `inv_orders_before()`, `inv_order_payment_status()`, `inv_order_payments_before()`, `inv_order_link_mint()`, `inv_secure_link_order()`,
`inv_order_confirm()`, `inv_order_ship()`, `inv_shipment_deliver()`, `inv_order_close()`, `inv_order_cancel()`, `inv_order_line_cancel()` (db/010);
`inv_order_dropships_draft()`, `inv_order_dropships_cancel()`, `inv_dropship_delivered()` (db/011); `inv_allocate()`, `inv_post_txn()` (db/008); `inv_availability()`,
`inv_atp()`, `inv_order_timeline()`, `inv_lines_at_risk()`, `inv_purchase_orders_open()` (db/014); **`inv_fulfilment_today()`** (db/016 — the tool surface's "Owed to
the schema": `(p_day date DEFAULT current_date, p_location_id bigint DEFAULT NULL) RETURNS TABLE (location_id, location_name, delivery_method, sales_order_id,
order_number, customer_name, promised_on, ship_to_city, line_id, line_no, variant_id, sku, product_name, size_name, qty, qty_allocated, qty_shipped,
fulfilment_kind, line_status)`); `inv_notify()`, `inv_expire_links()` (db/013); `inv_settings`, `tax_rates` (db/005); the views `mcp_customers`, `mcp_sales_orders`,
`mcp_sales_order_lines`, `mcp_order_payments`, `mcp_shipments`, `mcp_shipment_lines`, `mcp_order_links`, `mcp_purchase_orders`, `mcp_purchase_order_lines`,
`mcp_return_authorizations`, `mcp_locations`, `mcp_tax_rates`, `mcp_members`, `mcp_product_variants`, `mcp_notes`, `mcp_attachments`, `mcp_settings` (db/015).
Never modify them. **The database is the referee**: a line's price and total, the order's totals and tax, a drop-ship line's snapshotted offer (cost, lead
time, source — refused against a reference or an unmatched offer), a bundle sold as its components, lines changed only while a quote, allocation at
confirmation (refused when the location cannot cover it), the issue at shipment, the payment status, the link's hash and life — all in db/010 and db/011; the
handlers call the verbs inside `inv_guard()` and show their sentences as 422s.

## Screens (375 px is the design for the order form and the order page — a salesperson on a tablet; the lists at 1280)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `customer-list` | `/customers/?q=&source=&archived=` | Cards `customer-card-{id}`: name, email and phone (for `orders.write`), open orders, last order; search name / email / phone (trigram on the name, exact on email and phone); archived hidden unless `archived=1`; **New customer** for `customers.write` |
| `customer-add` / `customer-edit` | `/customers/new`, `/customers/{customer}/edit` | The form (`customer-form`, ids `customer-form-field-{name}`) |
| `customer-view` | `/customers/{customer}?tab=` | Facts; tabs **Orders** (open first, `find_orders` for the customer), **Returns** (`mcp_return_authorizations` — the records; the screens are slice 8's), **Notes**, **Attachments**, **Trail** (`mcp_activity_log`, the kit's trail partial); **New quote** (→ `/orders/new?customer=`), Edit, Archive / Unarchive, Delete (`records.delete`, no order) |
| `order-list` | `/orders/?status=&customer=&salesperson=&location=&late=&from=&to=` | A table (`order-list-table`, rows `order-row-{id}`): number, customer, salesperson, store, ordered, promised (**late** `danger` with the days), status chip, total, payment chip, balance; default statuses open (confirmed, in_fulfilment, shipped); `late=1`; `from`/`to` on `ordered_on`; 50 a page; **New quote** |
| `order-add` / `order-edit` | `/orders/new?customer=&variant=&qty=&fulfilment=&listing_variant=&location=`, `/orders/{sales_order}/edit` | **The order form** (below) — a quote; `order-edit` opens only a quote (anything else → 422 "SO-00042 is confirmed — cancel a line or the order instead") |
| `order-view` | `/orders/{sales_order}?tab=` | **The order page** (below) |
| `order-confirm` | `/orders/{sales_order}/confirm` | What confirming will do, line by line; **Confirm** |
| `order-payment` | `/orders/{sales_order}/payment?kind=` | Record a deposit, a balance or a refund |
| `order-ship` | `/orders/{sales_order}/ship` | Pick the lines, the kind, carrier, tracking, serials; **Ship** |
| `order-send` | `/orders/{sales_order}/send` | The confirmation email as the customer will see it; a message of my own; **Send** |
| `fulfilment-today` | `/orders/today?date=&location=` | Today's deliveries and pickups by store and method (`inv_fulfilment_today()`), the drop-ships expected today beside them |
| `shipment-list` | `/shipments/?kind=&from=&to=&undelivered=` | Shipments: order, kind, carrier, tracking, shipped, delivered |
| `shipment-view` | `/shipments/{shipment}` | One shipment: its lines, tracking, **Deliver** |
| `customer-door` | `/o/{token}` | The customer's order page — no session, read-only (below) |

Fragments (not screens): `GET /orders/{sales_order}/lines` (the lines editor region re-rendered — the page-of-a-record rewrite), `GET /customers/ship-to?customer=`
(the customer's shipping block for the form), slice 4's `/find/pick`, `/find/availability?compact=1`, `/find/atp`.

## Customers
- **`customer_create` / `customer_update`** (`/customers/save.php`): `customers.write`. Fields in order: **name** (required, ≤ 200; a live duplicate → the guard's
  23505 → 422 "That name is already taken."), legal_name (≤ 200), email (a valid address or empty), phone, phone_alt (≤ 40 each), billing_address, shipping_address
  (textareas ≤ 500), source (`walk_in`, `phone`, `web`, `referral`, `other` — `walk_in` by default), tax_rate (a live `mcp_tax_rates` id or empty), terms_days (0–365
  or empty), tax_id (≤ 40), email_opt_in (yes/no), notes. `income_account_id` is shown read-only when filled and set by nobody here (the ledger's — Extended);
  `currency` is never a field (one currency — the settings'); `member_id` is never a field in v1 (a customer who is a member is Extended). `customer` names an
  existing one for an update (`any field of` — a field left out stays). Log `customer.create|update` (`name`, `source`, `email_opt_in`, the fields changed **by
  name** — "email changed", never the value); location `/customers/{id}`; `HX-Trigger: customerChanged`. `PARTIAL_UPDATE_TARGETS` gains
  `'/customers/save.php' => ['customers', 'customer', 'mcp_customers', 'customer_id']`.
- **`customer_archive`** (`/customers/archive.php`): `customers.write`; `archived` yes (default) / no; archiving one with an order not closed or cancelled → 422
  "Close or cancel their N open orders first." (DECISION: quotes count as open — they are cancelled first); sets `archived_at`; log `customer.archive`.
- **`customer_delete`** (`/customers/delete.php`): `records.delete`; `DELETE` refused by the FK (`23503`) → 422 "Alvarez has orders; archive them instead.";
  log `customer.delete` (`name`) before the delete; **deletion** for agents; location `/customers/`.
- A Viewer sees the cards without email or phone (the view nulls them); the form and the New button need `customers.write`.

## The order form (`order-form`, `data-screen="order-add|order-edit"`)
- **Customer**: `order-form-field-customer` — `app/views/shared/customer-select.php`, the ONE customer picker (a select2 over `mcp_customers` not archived,
  searchable by name and email; `?customer=` preselects), **or a new one**: `order-form-new-customer` (shown by "+ New customer"): `new_customer_name`,
  `new_customer_email`, `new_customer_phone` (DECISION: the manifest's "or a new one by name and email and phone" are these three fields; a name given with no
  `customer` creates the customer first — `customer.create` logged — then the quote). Picking a customer loads the ship-to block
  (`hx-get="/customers/ship-to?customer=" hx-target="#order-form-ship-to" hx-trigger="change"`); the trigger fills it on INSERT when left blank anyway.
- **Header**: location (`order-form-field-location`, the store: active locations; default the first of kind `store` or `showroom`, else the first active —
  DECISION), salesperson (active human members; default me), delivery_method (`pickup`, `delivery`, `parcel`, `ltl`, `white_glove`; `delivery` by default),
  promised_on (a date, **on or after `ordered_on`** — DECISION; empty allowed), the **ship-to block** (`order-form-ship-to`: ship_to_name, ship_to_address1,
  ship_to_address2, ship_to_city, ship_to_region, ship_to_postal, ship_to_country (2 letters upper-cased), ship_to_phone, ship_to_notes ≤ 500 — hidden
  when `pickup`), tax_rate (live rates; the default rate preselected), shipping (`shipping_charge` ≥ 0), customer_reference (≤ 100), notes.
- **The lines editor** (`order-lines`): rows `order-line-{n}` (`lines[n][…]` for a new row; on the edit form an existing line's row is `order-line-{line_id}` and posts
  on its own, below): **variant** — a search box (`order-line-{n}-search`, `hx-get="/find/pick?q=" hx-trigger="keyup changed delay:300ms" hx-target="#order-line-{n}-pick"`)
  whose pick fills `lines[n][variant]`, the label and the retail into `unit_price` (a small script; without it the list's buttons are links that submit the
  pick); **qty** (`order-line-{n}-qty`, whole ≥ 1, default 1), **unit_price** (decimal ≥ 0; the retail when empty — the trigger), **discount** (decimal ≥ 0; the
  trigger refuses one over the line's value), **the availability picker** (`order-line-{n}-fulfilment`: `hx-get="/find/availability?variant=&compact=1&qty=&field=lines[n]"
  hx-trigger="load, change from:#order-line-{n}-qty"` — slice 4's compact partial: the radios `lines[n][fulfilment]` = `stock:{location}` | `pickup:{location}` |
  `dropship:{listing_variant}` | `backorder` with `lines[n][backorder_location]`, the promise line beneath), notes (≤ 200), **Remove**. `?variant=&qty=&fulfilment=&listing_variant=&location=`
  prefill the first row (Find's "Sell this"). **A bundle picked** expands into its components as rows (`mcp_bundle_components`, qty × the set's qty, the set's
  retail on the first component and 0 on the rest — DECISION: the trigger refuses a bundle as a line; the form does the expansion the SQL's sentence asks for).
  "+ Add line" (`order-lines-add`) clones a blank row (a script); without JavaScript the form always carries one blank row more than it has.
- **The totals panel** (`order-totals`: subtotal, discount, tax, shipping, total) is re-rendered with the lines (the server's figures — never computed in the browser).
- **`quote_create`** (`/orders/save.php` without `order`): `orders.write`; `customer` or the three new-customer fields (else 422 "Pick a customer or name a new one.");
  at least one line (else 422 "A quote has at least one line."); INSERT `sales_orders` (status `quote`; `origin` = `agent` under a run token, else `entered` —
  DECISION; `created_by` me) then each line through `save_order_line()` (the trigger prices it, snapshots a drop-ship's offer, refuses what it refuses); `lines`
  may be JSON for an agent (`[{variant, qty, unit_price, discount, fulfilment_kind, location, listing_variant, notes}]`) or the form's `lines[n]`; a line's
  fulfilment arrives either as the picker's `fulfilment` (`kind:id`) or as `fulfilment_kind` + `location` / `listing_variant` (the manifest's shape) — both are
  read by `order_line_from_request()` (DECISION). The header's `discount` (the manifest lists it) is accepted **only with exactly one line**, as that line's
  discount; otherwise 422 "A discount is on a line." (DECISION — the schema keeps discounts on lines). Log `order.quote` (`number, customer_id, customer_name,
  salesperson_member_id, location_id, status, lines, total, currency`); `sales_order_id` and `location_id` on the row; location `/orders/{id}`; `orderChanged`.
- **`order_update`** (`/orders/save.php` with `order`): a quote only (the trigger's sentence otherwise); the header's fields (`any field of`); `lines` JSON from an
  agent replaces nothing — it ADDS lines (DECISION: lines are edited one by one; an agent cancels a line it does not want); log `order.update` (the fields
  changed). `PARTIAL_UPDATE_TARGETS` gains `'/orders/save.php' => ['sales_orders', 'order', 'mcp_sales_orders', 'sales_order_id']`.
- **`order_line_add` / `order_line_update`** (`/orders/lines/save.php`): `orders.write`; `order` (a quote) or `line` (a quote's line); the fields above; INSERT /
  UPDATE `sales_order_lines` (the next `line_no` on insert); log `order.line_add|line_update` (`line_id, line_no, sku, qty, unit_price, fulfilment_kind, location_id,
  listing_variant_id, source_id, offer_cost, offer_lead_time_days`); the answer re-renders `#order-lines` + `#order-totals` (`HX-Trigger: orderChanged`; the edit
  form's regions `hx-get="/orders/{id}/lines"` on it); location `/orders/{order}/edit#order-line-{line_id}`.
- **`order_line_fulfilment_set`** (`/orders/lines/fulfilment.php`): a quote's line; `fulfilment` (`kind:id`) or `fulfilment_kind` + `location` / `listing_variant`;
  UPDATE the line (the trigger re-snapshots the offer when the listing variant changed, nulls what does not apply); log `order.line_fulfilment_set`.
- **`order_line_cancel`** (`/orders/lines/cancel.php`): `orders.write`; a line of a quote or a confirmed order; `inv_order_line_cancel(line, me)` (releases its
  allocation; a shipped line → the sentence); when the line was `ordered` (a sent drop-ship PO line) the Buyer is told (`inv_notify(buyer, 'order', 'sales_order',
  order, 'Line N of SO-… was cancelled — cancel PO-… line with <supplier>')` — DECISION: the Buyer is `inv_settings.buyer_member_id`, else the first active
  super-admin of the mirror); log `order.line_cancel` (`line_id, sku, qty, status_before, released`); a quote shows a cancelled line struck through.

## The order page (`order-view`, `data-entity="sales_order"`)
- Header: the number, the status chip, the customer (a link), salesperson, store, ordered / promised (late `danger`), delivery method, the totals, the payment
  chip and **balance due**; `order-view-actions` by state and right: **Edit** (quote, `orders.write`), **Confirm** (quote), **Record payment** (not cancelled;
  `payments.record`), **Refund** (any with `amount_paid > 0`), **Send** (confirmed or later, not cancelled; `orders.send`), **Notify**, **Ship** (confirmed /
  in_fulfilment with a stock, pickup or backorder line not fully shipped; `stock.ship`), **Deliver** (an undelivered shipment; `stock.ship`), **Close**
  (delivered), **Cancel** (not closed / cancelled, nothing shipped), **Rotate link** (`orders.send`).
- Regions: **`order-lines`** (Line · SKU / product / size · Qty · Unit · Discount · Total · Fulfilment — the kind chip and where: the location's name / the
  source's name + "N days" + the cost for `sees_cost()` / "backorder" / "pickup at …" · Status chip · Allocated / Shipped; a cancelled line struck through);
  **`order-payments`** (`mcp_order_payments` for `payments.record` or `reports.read`: when, kind, amount, method, reference, by; the balance line);
  **`order-shipments`** (one card per shipment `order-shipment-{id}`: kind chip, carrier, tracking as a link when `tracking_url`, shipped, delivered, its lines;
  **Deliver**); **`order-dropships`** (`mcp_purchase_orders` for this order: number → `/purchasing/{id}`, supplier, status chip, acknowledged, expected, the
  lines' tracking — the Buyer's screens are slice 6's); **`order-link`** (`mcp_order_links`, for `orders.send`: "live" / "expires <date>" / "none yet — sent
  with the confirmation", opened N times, last opened; **Rotate**); **`order-timeline`** (`inv_order_timeline()` as a list — `order-timeline-{n}`; a PO's
  `total` is the function's, nulled behind the wall); **`order-notes`** and **`order-attachments`** (the lists from `mcp_notes` / `mcp_attachments`; the add
  forms are slice 8's `note_add` / `attachment_add` — the partials include `app/views/shared/note-form.php` and `attachment-form.php` **when the file exists**
  (DECISION — slice 8 ships them; until then the lists alone)); the trail (`tab=trail`).
- A Viewer sees the page without the ship-to lines, phone, payments and link (the views'); a Warehouse hand sees the ship-to (`stock.ship`) and nothing of money.

## Confirm, send, notify, pay, ship, deliver, close, cancel, the link
- **`order-confirm`** (the screen): per line what will happen — stock/pickup: "allocates N at <location> (M available)" (`inv_own_stock`) or, when M < N, `danger`
  "cannot cover: M available — make it a backorder or choose a source" with two inline buttons posting `order_line_fulfilment_set` (`backorder` at that
  location; the picker reopened); dropship: "drafts a purchase order to <supplier> · N days" (+ the cost for `sees_cost()`), grouped by supplier so the count
  of POs is visible; backorder: "waits for stock at <location>"; **Confirm** (`order-confirm-submit`, `hx-confirm` on the command bar's side only — the screen is
  the confirmation).
- **`order_confirm`** (`/orders/confirm.php`): `orders.write`; `inv_order_confirm(order, me)` (the SQL allocates, refuses, drafts one PO per supplier through
  `inv_order_dropships_draft()`); log `order.confirm` (`number, customer_id, …, allocated: [{sku, location_id, qty}], dropships_drafted: [po ids], backordered:
  [sku…], total, currency`); **other** for agents; **no email is sent by confirming** (that is `order_send`); location `/orders/{id}`; `orderChanged`.
- **`order_send`** (`/orders/send.php`): `orders.send`; the order confirmed or later and not cancelled (a quote → 422 "Confirm it first — a quote is not sent
  in v1." — DECISION); the customer's email required (422 "The customer has no email address."); `message` ≤ 2,000; in ONE transaction: `inv_order_link_mint(order)`
  → the raw token (the previous live link is rotated by the function — **every send mints anew; the raw token lives nowhere but the email**, DECISION), the mail
  from `order_confirmation_mail()`, `malumail_send([...])`; a 2xx with `accepted` → commit; MaluMail's 400 (suppressed / rejected) → roll back, 422 "MaluMail
  refused the address: <reason>"; a transport error or 5xx → roll back, 503 "Mail could not be sent — try again."; no `MALUMAIL_API_KEY` → 503 "Email is not
  configured — the installer's mail step writes the key." Log `order.send` (`number, to: "the customer's email", link_id, rotated (a live link existed), message_id`
  — never the address, never the token); **external_send**; location `/orders/{id}#order-link`.
- **The mail** (`app/features/orders/mail.php` — `order_confirmation_mail(array $order, array $lines, array $settings, string $rawToken, ?string $message): array`
  → `['subject', 'text', 'html']`): subject "Your order SO-00042 from <business_name>"; the body: the business's name and contact, the number and date, the lines
  at retail (product · size · qty · unit price · total), the totals, the payment status and balance due, the delivery method and promised date (or "pickup at
  <store>"), the message when given, **the link** `inv_public_url('/o/' . $rawToken)` ("See your order, its delivery and tracking here") where `inv_public_url()`
  = `INV_PUBLIC_BASE_URL` else `APP_URL`; the business contact as `reply_to`. **Never a supplier's name, a source, a cost, a note.** The payload:
  `['from' => env('MAIL_FROM'), 'from_name' => env('MAIL_FROM_NAME') ?: business_name, 'to' => $email, 'reply_to' => business_contact_email, 'subject', 'text', 'html']`
  (the `malumail-send` skill's keys). `order_notice_mail(array $order, string $kind, ?string $message, ?string $promisedOn): array` — the four notices (below).
- **`order_notify`** (`/orders/notify.php`): `orders.send`; `kind` ∈ `delivery_date`, `delay`, `ready_for_pickup`, `shipped`; `message` ≤ 2,000; `promised_on`
  (a date; with `delivery_date` / `delay` it UPDATES the order's `promised_on`); the email from `order_notice_mail()` — the subject by kind ("Your order SO-… —
  a new delivery date" / "— a delay" / "— ready for pickup" / "— shipped"), the facts, the message; **no link in a notice** (DECISION: the raw token is not kept;
  "see your order page from your confirmation email"); the same MaluMail refusal rules; log `order.notify` (`kind, number, promised_on`); **external_send**.
- **`payment_record`** (`/orders/payments/save.php`): `payments.record`; `kind` ∈ `deposit`, `balance`; `amount` > 0 decimal; `method` ∈ `cash`, `card`, `check`,
  `transfer`, `financing`, `other`; `reference` ≤ 100 (the last four, a check number); `taken_at` (a date-time, default now); `note` ≤ 500; INSERT `order_payments`
  (the trigger derives the status; a cancelled order takes a refund only — the sentence); log `order.payment` (`payment_id, number, kind, method, amount,
  currency, reference, payment_status`); **other**; location `/orders/{id}#order-payments`. **`refund_record`** (`/orders/payments/refund.php`): kind `refund`;
  `amount` ≤ `amount_paid` (422 "Refund exceeds what was paid (N)." — DECISION); log `order.refund`; **other**.
- **`order-ship`** (the screen, `stock.ship`): the stock, pickup and backorder lines not fully shipped (`order-ship-line-{line_id}`: SKU / product / size, where
  it ships from, to ship (`lines[line_id][qty]` ≤ qty − shipped, default the remainder; 0 = leave out), serials (`lines[line_id][serials]`, comma-separated));
  the drop-ship lines listed read-only ("tracking comes from the supplier — Purchasing"); kind (`own_delivery`, `parcel`, `ltl`, `pickup`; the order's delivery
  method suggests it: pickup → pickup, parcel → parcel, ltl / white_glove → ltl, delivery → own_delivery), carrier (≤ 60), tracking (≤ 100), shipped_at
  (default now); **Ship**. **`order_ship`** (`/orders/ship.php`): the lines as the form's `lines[line_id]` or an agent's JSON `[{line_id, qty, serials}]`;
  `inv_order_ship(order, me, lines, kind, carrier, tracking, shipped_at)` (a backorder line with no location, a cancelled line, a qty over the remainder → the
  SQL's sentences; a drop-ship line sent by an agent is accepted as the SQL accepts it — PHP decides nothing twice); `tracking_url` (DECISION: from a fixed map
  in `present.php` — `ups`, `fedex`, `usps`, `dhl` by carrier word, else null) written on the shipment; log `order.ship` (`shipment_id, number, kind, carrier,
  tracking_number, lines: [{sku, qty}], issued: [{sku, location_id, qty}]` from the group's `sale` transactions); location `/shipments/{id}`; `orderChanged`.
- **`order_deliver`** (`/orders/deliver.php`): `stock.ship`; `shipment` (one) or `order` (every undelivered shipment of it); `delivered_at` (default now);
  `inv_shipment_deliver()` each (the dropship trigger receives the PO lines and sets the cost — db/011); log `order.deliver` (`shipment_id, number, delivered_at`);
  location `/orders/{id}#order-shipments`.
- **`order_close`** (`/orders/close.php`): `orders.write`; **a balance due > 0 is refused**: 422 "SO-… has N due — record the payment or a refund first." (DECISION —
  the manifest says "delivered and paid"; the SQL checks delivered); `inv_order_close()` (the link's 180 days start); log `order.close`; location `/orders/{id}`.
- **`order_cancel`** (`/orders/cancel.php`): `orders.write`; `reason` required (≤ 500); `inv_order_cancel()` (allocations released, lines cancelled, the DRAFT
  drop-ship POs cancelled by the SQL); then for each drop-ship PO of the order in `sent`, `acknowledged` or `partial` the Buyer is told (`inv_notify(buyer, 'order',
  'purchase_order', po, 'Cancel PO-… with <supplier>: SO-… was cancelled')`); log `order.cancel` (`number, reason, released: [{sku, location_id, qty}],
  dropships_cancelled, dropships_to_cancel_by_hand`); **deletion**; location `/orders/{id}`.
- **`order_link_rotate`** (`/orders/link-rotate.php`): `orders.send`; `inv_order_link_mint(order)` — the old link dies at once, the new raw token is **discarded**
  (the next `order_send` mints and mails again — DECISION, so a token never rests in a table or a log); log `order.link_rotate` (`number, link_id`); location
  `/orders/{id}#order-link`. **The expiry pass** `worker_pass_links_expire()` (`bin/worker.php`, this slice fills it): `inv_expire_links()` → `['expired' => n]` — it
  covers the supplier links too (one function; slice 6 adds nothing to the pass — DECISION).

## Fulfilment today and shipments
- **`fulfilment-today`**: `date` (default today), `location`; `inv_fulfilment_today(day, location)` grouped by location then delivery method (`today-{location_id}-{method}`),
  each order (`today-order-{id}`: number, customer, promised, city, the lines with to-pick qty and the status chip, **Ship** → `/orders/{id}/ship` for `stock.ship`);
  beside them **`today-dropships`**: the drop-ship PO lines expected on that day (`mcp_purchase_order_lines` ⨝ `mcp_purchase_orders` kind `dropship`, `expected_on`
  = day, status in open / acknowledged / shipped — number, supplier, sales order, tracking). For Sales the page opens on their store (`location` = the first
  location of their orders today); for Warehouse on every location. Empty: "Nothing to deliver or collect on <date>."
- **`shipment-list`**: `mcp_shipments` ⨝ `mcp_sales_orders`: order (link), customer, kind chip, carrier, tracking (link), shipped, delivered; `undelivered=1`
  default; `kind`, `from`/`to` on `shipped_at`; rows `shipment-row-{id}`. **`shipment-view`**: the shipment, its lines (`mcp_shipment_lines` ⨝ `mcp_sales_order_lines`:
  SKU, product, size, qty, serials), the tracking link, **Deliver** (`stock.ship`); `data-entity="shipment"`.

## The public door — the customer's order page (D9, D10)
- `GET /o/<48 hex>` (`html/o.php`; the vhost's and the dev router's rewrite exist): `$GLOBALS['__public_door'] = 'portal'`; the kit starts an anonymous session (the
  bootstrap always does) and **nothing sets `app.member_id`** — the `mcp_*` views answer nothing, so the page reads the base tables as the writer through
  `public_order()` (GL's decision); `inv_secure_link_order($token)` → the order id or NULL (one dead page, **404**: "This link has expired. Ask the business for a
  new one." — the same page for a malformed, unknown, rotated or expired token; the token is never echoed). **Rate limits** (DECISION — constants in
  `app/features/public/order.php`, no table): `ORDER_DOOR_LIMIT_PER_LINK_HOUR = 60` and `ORDER_DOOR_LIMIT_PER_IP_HOUR = 300`, counted from `activity_log` rows
  `order.customer_view` in the last hour by `sales_order_id` and by `ip_address`; over → **429** "Too many requests — try again in a few minutes." (the view is
  not logged, the link's `view_count` still moved — acceptable).
- The page (`app/views/public/order.php` in the bare public layout `app/views/public/layout.php` — this slice makes it; slice 6 reuses it for `/s/`): `<meta
  name="robots" content="noindex, nofollow">` and `X-Robots-Tag: noindex`; the business's name, contact email and phone (`inv_settings`); the number and date;
  the status **in the customer's words** (`quote` "Quote" · `confirmed` "Confirmed" · `in_fulfilment` "Being prepared" · `shipped` "Shipped" · `delivered`
  "Delivered" · `closed` "Completed" · `cancelled` "Cancelled"); the lines (product · size · qty · unit price · total; the fulfilment in the customer's words:
  "from stock" · **"ships from our supplier"** · "backordered" (+ " — expected <date>" from the PO line's `expected_on` when a drop-ship is placed) · "pickup";
  a cancelled line omitted); the totals; the payment status and **balance due**; the delivery method and promised date; **the ship-to** (name, address lines,
  city, region, postal, notes — the customer's own; **never the phone**; nothing for pickup but the store's name and address); the shipments (kind in words,
  carrier, **tracking as a link** when `tracking_url`, shipped, delivered); the returns' numbers and states when any; **"Message us"** —
  `mailto:<business_contact_email>?subject=Order SO-00042`. **No cost, no source, no supplier's name, no internal note, no salesperson anywhere.** Plain HTML,
  no HTMX, whole at 375 with JavaScript off. Logged `order.customer_view` (source `portal`, actor null, `sales_order_id`, `after: {number, link_id, view_count}`).

## Files (exactly these)
- `html/customers/index.php` · `form.php` · `view.php` · `save.php` · `archive.php` · `delete.php` · `ship-to.php` (the fragment)
- `html/orders/index.php` · `form.php` · `view.php` · `lines.php` (the fragment) · `confirm.php` (GET the screen `order-confirm`; POST the action — one file, the
  manifest's `confirm.php`) · `payment.php` (the screen) · `ship.php` (GET the screen, POST the action) · `send.php` (GET the screen, POST the action) ·
  `notify.php` · `deliver.php` · `close.php` · `cancel.php` · `link-rotate.php` · `today.php` (`fulfilment-today`) · `save.php`
- `html/orders/lines/save.php` · `fulfilment.php` · `cancel.php` · `html/orders/payments/save.php` · `refund.php`
- `html/shipments/index.php` · `view.php`
- `html/o.php`
- `app/features/customers/queries.php` · `present.php` · `write.php` · `handler.php` — `app/features/orders/queries.php` · `present.php` · `write.php` · `handler.php` ·
  `mail.php` — `app/features/shipments/queries.php` · `present.php` — `app/features/public/order.php`
- `app/views/customers/index.php` · `form.php` · `view.php` · `partials/customer-card.php` · `partials/ship-to.php` — `app/views/shared/customer-select.php` (the ONE
  picker) — `app/views/orders/index.php` · `form.php` · `view.php` · `confirm.php` · `payment.php` · `ship.php` · `send.php` · `today.php` · `partials/order-row.php` ·
  `partials/lines.php` · `partials/line-row.php` · `partials/totals.php` · `partials/payments.php` · `partials/shipments.php` · `partials/dropships.php` ·
  `partials/link-state.php` · `partials/timeline.php` — `app/views/shipments/index.php` · `view.php` — `app/views/public/layout.php` · `order.php` · `dead-link.php`
- `bin/worker.php` (`worker_pass_links_expire()` filled in) · `app/partial_update.php` (the two targets)

## Query functions (signatures fixed)
- Customers: `find_customers(PDO, array $filters, int $limit = 100, int $offset = 0): array` · `count_customers(PDO, array $filters): int` · `find_customer(PDO, int $id): ?array`
  (`mcp_customers` + open order count, last order) · `find_customer_by_email(PDO, string $email): ?array` · `save_customer(PDO, ?int $id, array $fields, int $by): int` ·
  `archive_customer(PDO, int $id, bool $archived): void` · `delete_customer(PDO, int $id): void` · `customer_open_orders(PDO, int $customerId): int` · `customer_ship_to(PDO, int $customerId): array`
- Orders: `find_orders(PDO, array $filters, int $limit = 50, int $offset = 0): array` · `count_orders(PDO, array $filters): int` · `find_order(PDO, int $id): ?array`
  (`mcp_sales_orders` + lines, payments (as the view admits), shipments with lines, drop-ship POs, returns, the link state, notes and attachments counts) ·
  `find_order_by_number(PDO, string $number): ?array` · `order_lines(PDO, int $orderId): array` (`mcp_sales_order_lines` with the location's and source's names) ·
  `find_order_line(PDO, int $lineId): ?array` · `draft_quote(PDO, array $head, array $lines, int $by): int` · `update_order(PDO, int $id, array $head, int $by): array` (the diff) ·
  `save_order_line(PDO, int $orderId, ?int $lineId, array $fields): int` · `set_line_fulfilment(PDO, int $lineId, array $fulfilment): array` · `cancel_order_line(PDO, int $lineId, int $by): array` ·
  `order_line_from_request(array $row): array` (present.php: `fulfilment` `kind:id` or `fulfilment_kind` + ids → the line's columns) · `expand_bundle_line(PDO, int $bundleVariantId, int $qty, ?string $price): array`
  · `confirm_preview(PDO, int $orderId): array` (per line what confirming will do) · `confirm_order(PDO, int $id, int $by): array` (`allocated`, `dropships_drafted`, `backordered`) ·
  `record_payment(PDO, int $orderId, array $fields, int $by): int` · `ship_order(PDO, int $orderId, array $lines, string $kind, ?string $carrier, ?string $tracking, ?string $shippedAt, int $by): array` (the shipment + `issued`) ·
  `deliver_shipment(PDO, int $shipmentId, ?string $at, int $by): array` · `close_order(PDO, int $id, int $by): array` · `cancel_order(PDO, int $id, int $by, string $reason): array` ·
  `mint_order_link(PDO, int $orderId): string` · `send_order(PDO, int $orderId, ?string $message, int $by): array` (the transaction: mint, mail, commit; `['link_id', 'message_id', 'rotated']`) ·
  `notify_order(PDO, int $orderId, string $kind, ?string $message, ?string $promisedOn, int $by): array` · `order_timeline(PDO, int $orderId, int $limit = 200): array` ·
  `order_dropships(PDO, int $orderId): array` · `order_link_state(PDO, int $orderId): ?array` · `notify_buyer(PDO, string $kind, string $recordType, int $recordId, string $title, ?string $body = null): ?int` (the Buyer rule above; in `app/features/orders/write.php`, reused by slice 6) ·
  `tracking_url(?string $carrier, ?string $tracking): ?string` (present.php) · `fulfilment_today(PDO, ?string $day = null, ?int $locationId = null): array` (reports-admin.md DECISION 1's signature — written here, in this file, by this slice and called by slice 9's home; the shape `{day, groups: [{location_id, location_name, delivery_method, orders: [{…, lines: [...]}]}], dropships_expected: [...]}` from `inv_fulfilment_today()` grouped and `dropships_expected()`; reconciled 2026-10-05) ·
  `dropships_expected(PDO, string $day): array` · `order_refused(Throwable $e): never` (the slice's prelude, as the exemplar's)
- Shipments: `find_shipments(PDO, array $filters, int $limit = 100, int $offset = 0): array` · `find_shipment(PDO, int $id): ?array` (+ lines)
- Mail: `order_confirmation_mail(array $order, array $lines, array $settings, string $rawToken, ?string $message): array` · `order_notice_mail(array $order, string $kind, ?string $message, ?string $promisedOn, array $settings): array` · `inv_public_url(string $path): string` (in `app/features/orders/mail.php`; slice 6 reuses it)
- Public: `public_order(string $rawToken): ?array` (`inv_secure_link_order()` then the base rows — the order, lines with the PO line's `expected_on`, shipments, returns, the settings; as the writer, no acting member) ·
  `door_rate_ok(PDO, int $orderId, string $ip): bool` · `customer_status_word(string $status): string` · `customer_fulfilment_word(array $line): string`

## Handlers (every one: `inv_handler_begin()`; the right; the record through its view (`require_visible`); `inv_guard()` around one transaction; `log_activity` with `sales_order_id` (+ `location_id` the store); `emit_action_status` / `inv_done`; `HX-Trigger: orderChanged` or `customerChanged`)
As listed above. `confirm.php`, `ship.php`, `send.php`, `payment.php` serve their screen on GET (`require_login()`, the right, `log_screen_view()`) and the action on
POST. The door (`o.php`) runs with no acting member, `source = portal`, never through the views, never through `inv_handler_begin()`.

## Manifest rows claimed
Screens (16): `customer-list`, `customer-add`, `customer-view`, `customer-edit`, `order-list`, `order-add`, `order-view`, `order-edit`, `order-confirm`, `order-payment`, `order-ship`, `order-send`, `fulfilment-today`, `shipment-list`, `shipment-view`, `customer-door`
Actions (20): `customer_create`, `customer_update`, `customer_archive`, `customer_delete`, `quote_create`, `order_update`, `order_line_add`, `order_line_update`, `order_line_fulfilment_set`, `order_line_cancel`, `order_confirm`, `order_send`, `order_notify`, `payment_record`, `refund_record`, `order_ship`, `order_deliver`, `order_close`, `order_cancel`, `order_link_rotate`
The sixteenth screen is the door `customer-door`. Agent approvals (as the manifest and `maludb-os.json`): `order_confirm`, `payment_record`, `refund_record`
(`other`); `order_send`, `order_notify` (`external_send`); `order_cancel`, `customer_delete` (`deletion`). `PARTIAL_UPDATE_TARGETS` gains `/customers/save.php` and
`/orders/save.php`.
Left to another slice, and why: `purchase_order_draft` and every purchase-order row (slice 6 — this slice calls `inv_order_dropships_draft()` only through
`inv_order_confirm()`); `return_request` and the returns screens (slice 8 — the order page links its returns); `note_add`, `attachment_add` (slice 8); the
availability partials (slice 4 — consumed here).

## Activity log events (every row: `sales_order_id`; the store as `location_id`; a customer's rows `entity_type = customer`)
`customer.create|update|archive|delete`, `order.quote|update|line_add|line_update|line_fulfilment_set|line_cancel|confirm|send|notify|payment|refund|ship|deliver|close|cancel|link_rotate`,
`order.customer_view` (the door, source `portal`, actor null), `screen.view` (on `order-view` `after.number`). **Never an address line, a phone, a token, a note's
text, a message's text, a customer's email** (the fact "the customer's email" only); amounts with `currency` on every money event; `offer_cost` on a line's row.

## Notifications this slice queues and sends
| Step | Who is told | How |
|---|---|---|
| `order_send` | the customer — the confirmation with the link | **MaluMail inline** (`malumail_send()`), never the outbox (its recipients are members — db/013) |
| `order_notify` | the customer — a date, a delay, ready, shipped | MaluMail inline |
| an order with a sent drop-ship PO is cancelled; an `ordered` line is cancelled | the Buyer (`inv_settings.buyer_member_id`, else the first super-admin) | `notifications` kind `order` (+ the outbox per prefs; slice 8 sends) |
| a watch set by a salesperson fires on a sold-out size | — slice 4's | — |

## Status vocabulary
Order status: `quote` secondary · `confirmed` info · `in_fulfilment` info · `shipped` primary · `delivered` success · `closed` dark · `cancelled` danger; **late** a `danger`
badge with the days. Line status: `open` secondary · `allocated` info · `ordered` info · `shipped` primary · `delivered` success · `cancelled` dark (struck) · `returned` warning.
Fulfilment kind chips: `stock` `feather-box` · `dropship` `feather-truck` · `backorder` `feather-clock` warning · `pickup` `feather-shopping-bag`. Payment status: `unpaid`
warning · `deposit` info · `paid` success · `refunded` dark · `partial_refund` secondary. Shipment kind: `own_delivery`, `parcel`, `ltl`, `dropship`, `pickup` as words; undelivered `info`, delivered `success`.
Ids: `customer-card-{id}`, `customer-form`, `customer-form-field-{name}`, `customer-list-search`, `order-list-table`, `order-row-{id}`, `order-form`, `order-form-field-{name}`,
`order-form-new-customer`, `order-form-ship-to`, `order-lines`, `order-lines-add`, `order-line-{n}`, `order-line-{n}-search`, `order-line-{n}-pick`, `order-line-{n}-qty`,
`order-line-{n}-fulfilment`, `order-totals`, `order-view-actions`, `order-payments`, `order-shipments`, `order-shipment-{id}`, `order-dropships`, `order-link`, `order-timeline`,
`order-timeline-{n}`, `order-notes`, `order-attachments`, `order-confirm-line-{line_id}`, `order-confirm-submit`, `order-ship-line-{line_id}`, `order-ship-submit`, `order-send-preview`,
`order-send-message`, `order-send-submit`, `today-{location_id}-{method}`, `today-order-{id}`, `today-dropships`, `shipment-row-{id}`, `shipment-view-deliver`, `public-order`, `public-order-lines`,
`public-order-shipments`, `public-order-message`.

## The 375 px rule
The order form is phone-first: the customer picker and the header stacked; each line a card with the search box, qty stepper (≥ 44 px), price, the picker's
radios one per row, the promise line; "+ Add line" and Save in the pinned form header (`page-header-form`); the totals panel at the bottom. The order page: the
header, then the regions stacked, every table in `.table-responsive`, the action buttons stacked full width. The confirm, ship, payment and send screens are
one-column forms. The lists are tables at 1280 and wrapped at 375. The door is plain HTML with no fixed widths. JavaScript off: the pick list's buttons are
links that re-render the form with the variant chosen, the picker loads as a plain include when the row has a variant, one blank row at a time.

## Vocabulary
**quote** = an order not yet confirmed (the only "unconfirmed order"); **confirm** = the business promises — stock allocates, drop-ships draft; **allocated** =
held on the shelf for this line; **issue** = the `sale` movement at shipment; **drop-ship** = the supplier ships to the customer against the offer snapshotted on
the line; **backorder** = waits for stock at a named location; **pickup** = the customer collects; **payment** = a record of money, never a charge; **the link** =
the customer's door, minted fresh at every send, dead 180 days after close.

## Out of scope for this slice
Purchase orders, sending or placing them, the supplier's door (6); returns (8); notes and attachments' add forms (8); the outbox sender (8); the sales reports and
the home's "my open orders" (9); a customer login, a public catalog, an invoice document, a processor (rejected by design); multi-currency (Extended); serial-tracked
units beyond the typed `serials` (Extended).

## Proof (`tests/phase3/slice5/run.sh`: the scratch database `inv_dev5`; the fake kernel at 8602; **the fake MaluMail** `php -S 127.0.0.1:8606 tests/fake_malumail.php`
(`FAKE_MALUMAIL_STATE`, `FAKE_MALUMAIL_LOG`); the app at 8607 through `tests/dev_router.php`; members through `bin/dev_handoff.php`; curl with signed action and run
tokens; headless Chromium at 375 × 740 and 1280 × 800; the registry and `sync_approvals --check`) — **at least 200 checks, the door included**
The world (`tests/phase3/slice5/lib.php` `order_world()`): slice 4's world (the Purple product in six sizes, the set, the two suppliers with their matched offers,
the Warehouse with 4 Queens and the Showroom's floor model) plus the customer SMOKE Alvarez (`alvarez-<run>@example.invalid`, a shipping address, `email_opt_in`),
a second customer with no email, the Cook County tax rate as the default, the Buyer set to Nora.
- [ ] **Customers** (≈ 25): create with every field, a duplicate name → 422 in the guard's words, a bad email → 422 field error, `terms_days` 400 → 422; update keeps
  what was left out (`_partial=1` under a token too); the log row says "email changed" and never the address; Vera's cards show no email or phone, Sam's do; search by
  email exact and by name trigram; archive refused with a quote open, allowed after its cancel, the card hidden then shown with `archived=1`; unarchive; delete refused
  with an order in words, allowed for a customer with none (`records.delete` — the Owner; Nora 403); the view's tabs.
- [ ] **A quote on a phone** (≈ 45): `/orders/new?customer=&variant=<Queen>&qty=1&fulfilment=stock&location=<Warehouse>` prefills the first row with the picker's
  Warehouse radio checked; the pick list answers "purple" with ≤ 20 rows; a quote with three lines — a Queen from stock, a Cal King drop-ship from Malouf's offer, a
  King drop-ship from the Zinus feed — numbered `SO-`, status quote, the ship-to taken from the customer by the trigger, the default tax rate; the lines priced at
  retail with the Cal King's discount, the drop-ship lines carrying `offer_cost` and `offer_lead_time_days` and `source_id` (the proof's figures as Phase 0's: 1749 and
  3 days; 980 and 5 days); the totals and tax recomputed; `order.quote` logged with the audit keys; a drop-ship against a reference → the trigger's sentence as a
  422; against another variant's offer → 422; a stock line with no location → 422; **the set picked expands to its components** (the mattress at the set's price,
  the foundation at 0); a header `discount` with two lines → 422 "A discount is on a line."; a new customer by name and email creates both (two log rows); no
  customer → 422; no lines → 422; a 121-character note → 422; `order_update` changes the promised date and refuses a date before `ordered_on`; `order_line_add` on
  the edit form re-renders `#order-lines` and `#order-totals`; `order_line_update` changes qty and the total follows; `order_line_fulfilment_set` from `stock:…` to
  `dropship:…` re-snapshots the offer and nulls the location, from the manifest's `fulfilment_kind` + `listing_variant` shape too; `order_line_cancel` on a quote
  strikes the line and the totals drop it; a line added to a confirmed order → the trigger's sentence; Vera → 403 in words everywhere; Wes (Warehouse) cannot quote.
- [ ] **Confirm** (≈ 30): the confirm screen says "allocates 1 at Warehouse (3 available)", "drafts a purchase order to Zinus · 3 days", "… to Malouf · 5 days", the
  cost figures for Nora and not for Sam; with the Queen's qty raised to 9 the screen says "cannot cover" and the confirm → the SQL's sentence as a 422 with nothing
  allocated; "make it a backorder" posts `order_line_fulfilment_set` and the confirm passes; after confirm: status confirmed, `confirmed_by` Sam, the Queen
  allocated (`qty_allocated` 1 on the balance and the line), the backorder line open and holding nothing, **two draft drop-ship POs, one per supplier**, addressed to
  the customer, their lines carrying the LINE's snapshotted cost and lead time and the supplier's SKU, the order line pointing at its PO line, `order.confirm`
  logged with `allocated`, `dropships_drafted` (two ids), `backordered`; **no email was sent by confirming** (the fake's log is empty); confirming twice → 422; a
  quote with no lines → 422; the order page shows the two POs under `order-dropships` with links, and the timeline's `po_draft` rows (the PO total shown for Nora,
  null for Sam in JSON); the expert (run token) confirms on the actions path and the registry lists `order_confirm` as `other`.
- [ ] **Money** (≈ 20): a deposit of 1,000 by card `4242` → status deposit, `amount_paid`, `taken_by` Sam; a balance to the total → paid; a refund over what was paid →
  422 in words; a refund of 200 → partial_refund; a refund on a cancelled order allowed, a deposit refused (the trigger's sentence); Vera and Wes → 403; the
  payments panel for Sam and Nora, absent for Wes; `order.payment` rows with `currency`; the balance on the page and in JSON.
- [ ] **Send, the link and the door** (≈ 35): send on a quote → 422 "Confirm it first…"; on the confirmed order → one MaluMail call in the fake's log with the subject
  "Your order SO-… from SMOKE Business", the text carrying `/o/<48 hex>` once, the lines at retail, the balance due, **no "Malouf", no "Zinus", no "cost", no
  "source"** (grep), `reply_to` the business contact; `order.send` logged with `link_id` and `rotated: false`, never the address; `mcp_order_links` says live, 0 views;
  a second send → a new link, the first dead (404 on the door), `rotated: true`; the customer with no email → 422; a suppressed address (`suppressed@…`) → 422 and
  **no link row written** (the rollback); `flaky@…` → 503 and no row; without `MALUMAIL_API_KEY` → 503 in words; **the door**: the live token answers 200 with
  `noindex`, the number, "Confirmed", the three lines with "from stock" / "ships from our supplier" ×2, the totals, "Balance due", the ship-to name and address and
  **never the phone**, the mailto; **never a supplier's name, a cost figure, a source, a salesperson** (grep the HTML); `view_count` 1 and `order.customer_view` logged
  with source portal and actor null; a malformed token → 404 the dead page; the rotated one → 404; after close the link gets `expires_at` 180 days out, backdated
  by the proof → 404 and `inv_expire_links()` marks it rotated (the worker's pass answers 1); 61 views in an hour → 429 on the 61st; `order_notify delay` with a new
  promised date mails the notice without a link and moves `promised_on`; `order_link_rotate` kills the live link and keeps no token (no row, no log row carries 48 hex).
- [ ] **Ship, deliver, close, cancel** (≈ 35): the ship screen lists the Queen line (1 to ship) and the backorder line (no stock yet) and the drop-ship lines read-only;
  shipping the Queen as `own_delivery` → a shipment, `qty_shipped` 1, the line shipped, **a `sale` transaction at the Warehouse for −1 at the variant's cost**, the
  allocation released (`qty_allocated` 0), the order `in_fulfilment` (the drop-ships are not shipped), `order.ship` logged with `issued`; shipping a line twice → the
  SQL's sentence; the backorder line with 0 on hand → "Only 0 available…" from the ledger; a `parcel` with carrier `UPS` and a number gets a `tracking_url`;
  `pickup` → delivered at once; Sam (no `stock.ship`) → 403, Wes ships; **deliver**: the shipment delivered, the Queen line delivered; the supplier's tracking on
  the Zinus PO line (the proof calls `inv_po_tracking()` as slice 6 will) → a `dropship` shipment on the order, the line shipped; delivering it → the PO line
  received and, with `cost_source = last_receipt`, the variant's cost set with the reason "drop-ship PO-…"; when every line is delivered the order is delivered;
  **close** with a balance due → 422 in words, after the balance → closed with `closed_at`, the link's expiry set; close twice → 422; **cancel** a fresh confirmed
  order with a reason: allocations released, the draft POs cancelled by the SQL, `order.cancel` with `released` and `dropships_cancelled`; a cancel with a sent PO →
  the Buyer's notification "Cancel PO-…" (Nora's bell) and `dropships_to_cancel_by_hand` 1; cancel with a shipped line → "take a return instead"; a reason missing →
  422; `order_line_cancel` of an `ordered` line → the Buyer told; the expert's `order_cancel` is `deletion` in the registry.
- [ ] **Today and shipments** (≈ 10): `fulfilment-today` for today lists the confirmed order under Warehouse · own delivery with the Queen to pick and the backorder
  waiting; a promised date tomorrow → empty today and listed for `date=tomorrow`; the drop-ship expected on its date beside; the shipments list with `undelivered=1`,
  the kind filter; the shipment page with its line and Deliver.
- [ ] **JSON mode** (≈ 15): every handler under a signed action token answers `{ok, did, record_id, location, refresh}` with its facts (`number`, `allocated`,
  `dropships_drafted`, `shipment_id`, `issued`, `link_id`, `payment_status`); `_partial=1` keeps a customer's address; 422 `{error: {code: invalid, fields}}`; the
  expert (run token + relay) writes a quote with `lines` JSON (source `agent`, `origin` agent), may not send (it holds `user` in the fixture — `orders.send` is Sales':
  it may; the kernel's pause is Phase 4's), records with `request_id`.
- [ ] **375 × 740 and 1280 × 800** (≈ 25): the quote on the phone — pick a variant from the list, the picker's radios loaded, qty 2 reloads the picker and the promise
  line, the Save in the pinned header, landing on the order page; the order page's regions stacked, the Confirm screen, Ship with a checkbox per line, the door at
  375 whole with JavaScript off; at 1280 the order list as a table with the late badge, the form two-column; every control ≥ 44 px, `scrollWidth` = viewport, no
  console errors; JavaScript off: the pick list's links, one blank line at a time, the form guard absent and the save still landing.

## Built and proven
(not yet)

## Decisions taken while writing (2026-10-05)
- A quote IS the "unconfirmed order"; lines change only while a quote (the trigger); on the edit form a line is saved, re-fulfilled or cancelled on its own; a quote's
  cancelled line is struck, never deleted (the manifest has no `order_line_remove`).
- The new customer on the form is `new_customer_name` / `_email` / `_phone`; `origin` is `agent` under a run token, else `entered`; the header `discount` applies only with
  exactly one line; the store defaults to the first store or showroom; `promised_on` is not before `ordered_on`; a bundle picked expands into its components with the
  set's price on the first.
- A line's fulfilment is read from the picker's `kind:id` or from the manifest's `fulfilment_kind` + `location` / `listing_variant`; a backorder names its location.
- Confirming sends no email; `order_send` needs a confirmed order and mints a fresh link at every send — the raw token lives in the email alone; `order_link_rotate` kills
  the live link and discards the new token until the next send; a notice carries no link.
- The customer's and the supplier's email go through `malumail_send()` inline, never the outbox (its recipients are members — db/013's rule); a MaluMail refusal rolls
  the mint back; the payload keys are the `malumail-send` skill's.
- `order_close` refuses a balance due; a refund may not exceed what was paid; `tracking_url` comes from a fixed four-carrier map; a drop-ship line in an agent's `lines`
  for `order_ship` is accepted as the SQL accepts it.
- The Buyer told of a cancel touching a sent PO or an ordered line is `inv_settings.buyer_member_id`, else the first active super-admin; `notify_buyer()` lives in
  `app/features/orders/write.php` and slice 6 reuses it.
- `worker_pass_links_expire()` is filled here with `inv_expire_links()` (one function for both doors); slice 6 adds nothing to it.
- The door reads base tables as the writer, never the views; its limits are two constants counted from the activity log; the dead page is one 404 for every reason;
  the status and fulfilment words are the customer's; the ship-to is shown without the phone.
- Notes and attachments on the order page are lists; the add forms are slice 8's partials, included when their files exist.
- `inv_fulfilment_today()` is db/016's (the schema builder's); the screen reads it as the tool will.

## Open questions
(none)

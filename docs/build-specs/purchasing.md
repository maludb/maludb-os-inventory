# Build spec: purchasing, drop-ship orders, receiving and the supplier's door — slice 6

The worker model's. What exists at the end: suppliers as cards, a view (the price sheet, open orders, lead-time actuals, sources) and a form; purchase
orders **for stock** (drafted by hand from the reorder candidates or a variant) and **for drop-ship** (drafted by the confirmation — slice 5 — or by this
slice's "draft for order"); lines; **send by email with the supplier's link**, or **mark placed** on a portal with the supplier's reference; the supplier's
acknowledgment, a declined line and tracking **by hand**; **receive against** → a draft goods receipt (slice 2's screen); close; cancel; the link rotated;
**the supplier's door `/s/<token>` with its three CSRF-protected POSTs**; the events as rows; the lead-time actuals. Replicates slice 5 (orders) for the
record page, the lines editor, the mail and the door, and slice 1 for cards and forms. **Supplier price sheets (`supplier_items`) are slice 3's** (`supplier-item-list`,
`supplier_item_save`, `supplier_item_remove`) — read here, never written.
Schema: `suppliers`, `supplier_items` (db/009); `purchase_orders`, `purchase_order_lines`, `purchase_order_events`, `supplier_links_secure`, `inv_po_lines_before()`,
`inv_po_recompute()`, `inv_po_before()`, `inv_supplier_link_mint()`, `inv_secure_link_purchase_order()`, `inv_po_shows_phone()`, `inv_order_dropships_draft()`,
`inv_order_dropships_cancel()`, `inv_po_refresh_status()`, `inv_receipt_posted_hook()`, `inv_po_send()`, `inv_po_place()`, `inv_po_acknowledge()`, `inv_po_decline_line()`,
`inv_po_tracking()`, `inv_dropship_delivered()`, `inv_receive_against()`, `inv_po_close()`, `inv_po_cancel()` (db/011); `goods_receipts`, `goods_receipt_lines`,
`inv_post_receipt()` (db/008 — slice 2's screens post); `inv_reorder_candidates()`, `inv_lead_time_actuals()`, `inv_purchase_orders_open()`, `inv_availability()`,
`inv_on_order()` (db/014); `inv_notify()`, `inv_expire_links()` (db/013); `inv_settings` (`supplier_sees_phone`, `supplier_link_days`, `ack_days`, `buyer_member_id`);
the views `mcp_suppliers`, `mcp_supplier_items`, `mcp_sources`, `mcp_purchase_orders`, `mcp_purchase_order_lines`, `mcp_purchase_order_events`, `mcp_supplier_links`,
`mcp_goods_receipts`, `mcp_goods_receipt_lines`, `mcp_sales_orders`, `mcp_sales_order_lines`, `mcp_listing_variants`, `mcp_product_variants`, `mcp_locations`, `mcp_members`,
`mcp_notes`, `mcp_attachments`, `mcp_settings` (db/015). Never modify them. **The database is the referee**: a line's supplier SKU from the price sheet, the totals,
lines changed only while a draft, a bundle bought as its components, what a sent order may do next (acknowledge, decline, track, receive, close), a drop-ship line
shipped = the customer's shipment, a drop-ship line received = the customer's delivery (and the cost that follows), a stock order received only on a goods
receipt, the phone shown to a supplier only for the kinds the setting names, the link's hash and life — all in db/011 and db/008; the handlers call the verbs
inside `inv_guard()` and show their sentences.

## Screens (375 px works — a Buyer acknowledges on a phone; designed at 1280)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `supplier-list` | `/suppliers/?q=&dropships=` | Cards `supplier-card-{id}`: name, kind chip, "drop-ships" mark, lead time, open purchase orders, sources; inactive hidden unless `q`; **New supplier** for `suppliers.write` |
| `supplier-add` / `supplier-edit` | `/suppliers/new`, `/suppliers/{supplier}/edit` | The form (`supplier-form`, ids `supplier-form-field-{name}`) |
| `supplier-view` | `/suppliers/{supplier}?tab=` | Facts (the account number for `purchasing.write`); tabs **Items** (the price sheet from `mcp_supplier_items` — SKU, supplier SKU, cost (walled), lead time, MOQ, last seen; a link to `/supplier-items/?supplier=` — slice 3's screen edits it), **Open orders** (`inv_purchase_orders_open()` for the supplier), **Lead times** (`inv_lead_time_actuals(id)`'s row for `reports.read` + the last 20 shipped lines: PO, SKU, expected, shipped, days late), **Sources** (`mcp_sources` where `supplier_id`), **Notes**, **Attachments**, **Trail**; **New purchase order** (→ `/purchasing/new?supplier=`), Edit, Archive |
| `purchase-order-list` | `/purchasing/?status=&kind=&supplier=&awaiting_ack=&no_tracking=` | A table (`po-list-table`, rows `po-row-{id}`): number, supplier, kind chip, the sales order (a link) for a drop-ship, status chip, ordered, expected (**overdue** `danger`), acknowledged, tracking (a `feather-truck` when any line has it), total (walled); default statuses open (draft, sent, acknowledged, partial); `awaiting_ack=1` / `no_tracking=1` from `inv_purchase_orders_open()`'s flags; **New purchase order** |
| `purchase-order-add` / `purchase-order-edit` | `/purchasing/new?supplier=&order=&reorder=&variant=`, `/purchasing/{purchase_order}/edit` | **The purchase-order form** (below); edit opens a draft only (else 422 "PO-00017 is sent — close or cancel it instead") |
| `purchase-order-view` | `/purchasing/{purchase_order}?tab=` | **The purchase-order page** (below) |
| `purchase-order-send` | `/purchasing/{purchase_order}/send` | The email as the supplier will see it; a message of my own; **Send by email**; or **Mark placed** with the supplier's reference and how; or **Sent by phone** |
| `purchase-order-receive` | `/purchasing/{purchase_order}/receive` | The open lines and the receiving location; **Draft the receipt** → the receipt opens (slice 2's `receipt-edit`) |
| `supplier-door` | `/s/{token}` (+ `POST /s/{token}/acknowledge`, `/decline`, `/tracking`) | The supplier's purchase order — no session; the three actions (below) |

Fragments: `GET /purchasing/{purchase_order}/lines` (the lines editor region), `GET /purchasing/line-defaults?supplier=&variant=` (the default cost, supplier SKU, MOQ and
the supplier's offers for a line), slice 4's `/find/pick`.

## Suppliers
- **`supplier_create` / `supplier_update`** (`/suppliers/save.php`): `suppliers.write`. Fields in order: **name** (required, ≤ 200; a live duplicate → 23505 → 422), kind
  (`manufacturer`, `distributor`, `wholesaler`, `marketplace`, `vendor`, `other` — the manifest's six; the table also admits the Cidery's words, which the form never
  offers), contact_name, email, phone (≤ 120 / ≤ 40), address (≤ 500), website (a URL or empty), **account_number** (≤ 60 — the field is on the form for
  `suppliers.write`; the page shows it for `purchasing.write`; **never in a log row**), terms (≤ 60), dropships (yes/no), lead_time_days (0–365 or empty), order_method
  (`email`, `portal`, `api`, `edi`, `phone`; `email` by default), order_email (an address or empty), portal_url (a URL or empty), min_order (decimal ≥ 0 or empty),
  notes. `supplier` names an existing one (`any field of`). Log `supplier.create|update` (`name, dropships, order_method, lead_time_days`, the fields changed by name —
  never `account_number`); location `/suppliers/{id}`; `HX-Trigger: supplierChanged`. `PARTIAL_UPDATE_TARGETS` gains `'/suppliers/save.php' => ['suppliers', 'supplier',
  'mcp_suppliers', 'supplier_id']`.
- **`supplier_archive`** (`/suppliers/archive.php`): `active` no (default) / yes; archiving one with a purchase order in draft, sent, acknowledged or partial → 422
  "Close or cancel their N open purchase orders first."; `UPDATE suppliers SET active`; log `supplier.archive`; the card hidden from the list.
- **`supplier_message`** (`/suppliers/message.php`): `purchasing.write`; `subject` (required ≤ 200), `body` (required ≤ 5,000), `purchase_order` (optional — its number
  is prefixed to the subject "PO-00017: …" and a `purchase_order_events` row kind `note` source `email` records "emailed: <subject>" — DECISION); to = `order_email`
  else `email` (none → 422 "The supplier has no email address."); `malumail_send()` (the slice 5 payload keys; the refusal rules below); log `supplier.message`
  (`supplier_id, purchase_order_id, subject (≤ 200), length` — never the body); **external_send**; location `/suppliers/{id}` (or the PO's page when named).

## The purchase-order form (`po-form`, `data-screen="purchase-order-add|purchase-order-edit"`)
- **Header**: supplier (`po-form-field-supplier`: active suppliers; `?supplier=` preselects; fixed once lines exist — changing it on edit clears the lines' supplier
  SKUs: DECISION — the form says so and the handler re-reads them through the trigger by nulling `supplier_sku`), **kind** (`stock` / `dropship`), for `stock`:
  **location** (the ship-to: active locations, default the first warehouse), for `dropship`: **order** (the sales order the drop-ship fills — a search by number
  through `mcp_sales_orders`; `?order=` preselects), expected_on (a date), shipping_cost (decimal ≥ 0), notes (**to the supplier** — on the door and in the email, ≤ 2,000),
  internal_notes (never on the door, ≤ 2,000).
- **A stock order's lines editor** (`po-lines`, rows `po-line-{n}`, `lines[n][…]`): **variant** (the search box over `/find/pick`, as the order form's), **supplier_sku**
  (≤ 60; empty = the price sheet's by the trigger), **qty** (`qty` ≥ 1; the MOQ shown as a hint from the price sheet), **unit_cost** (decimal ≥ 0; **prefilled** by
  `/purchasing/line-defaults?supplier=&variant=`: the supplier item's `cost`, else the supplier's in-stock offer's `cost_price` else its `price` (from
  `inv_availability()`'s offers for that `supplier_id`), else the variant's `cost_price`, else 0 — DECISION, the manifest's "the supplier item's or the offer's by
  default"), **listing_variant** (a select of that supplier's offers for the variant from the same fragment — optional; "the offer it is ordered against"),
  expected_on (a date; empty = the header's), **Remove**. Prefill: `?reorder=1` → one row per `inv_reorder_candidates()` row whose `best_supplier_id` = the supplier
  (qty = `reorder_qty`, cost = `best_cost`, listing_variant = `best_listing_variant_id`); `?variant=` → one row. The totals panel (`po-totals`: subtotal, shipping, total)
  is the server's.
- **A drop-ship order is drafted by the database, never line by line** (DECISION): with `kind = dropship` the form shows the sales order's open drop-ship lines
  (`fulfilment_kind = dropship`, status `open`, `purchase_order_line_id` null) grouped by supplier (`po-form-dropship-{supplier_id}`: the lines with the LINE's snapshotted
  cost and lead time) and one **Draft** button; `purchase_order_draft` with `kind = dropship` and `order` calls `inv_order_dropships_draft(order, me)` — one PO per supplier
  with open lines, the ship-to the customer's, the lines' `sales_order_line_id` set by the function; `lines` sent with a drop-ship draft are ignored (the function's
  rows ARE the lines); nothing open → 422 "SO-… has no open drop-ship line." The manifest's `supplier` on a drop-ship draft narrows nothing (every supplier with open
  lines is drafted — the function's rule); the reply's `record_id` is the first PO drafted and `did` names them all.
- **`purchase_order_draft`** (`/purchasing/save.php` without `purchase_order`): `purchasing.write`; stock: supplier and location required, at least one line (422
  "A purchase order has at least one line."); INSERT `purchase_orders` (`kind stock`, `ship_to_kind location`, `created_by` me) then the lines through
  `save_po_line()` (the trigger: a bundle refused, the supplier SKU filled); `lines` may be JSON for an agent (`[{variant, qty, unit_cost, supplier_sku, listing_variant,
  expected_on}]`); log `purchase_order.draft` (`number, supplier_id, supplier_name, kind, sales_order_id, ship_to_kind, location_id, lines, total, currency`); **never the
  ship-to address when it is the customer's**; `purchase_order_id` on every row; location `/purchasing/{id}`; `HX-Trigger: purchaseOrderChanged`.
- **`purchase_order_update`** (`/purchasing/save.php` with `purchase_order`): a draft only (else 422 in words — the SQL guards the lines, the handler the header:
  "PO-… is sent — only a draft changes"); `any field of` the header (`supplier` on a drop-ship draft is refused: "A drop-ship's supplier is the offer's"); `lines` JSON
  adds lines; log `purchase_order.update` (the fields changed). `PARTIAL_UPDATE_TARGETS` gains `'/purchasing/save.php' => ['purchase_orders', 'purchase_order',
  'mcp_purchase_orders', 'purchase_order_id']`.
- **`purchase_order_line_add` / `purchase_order_line_update` / `purchase_order_line_remove`** (`/purchasing/lines/save.php`, `remove.php`): a draft's lines; INSERT (the
  next `line_no`) / UPDATE / DELETE `purchase_order_lines` (the trigger refuses outside a draft; a line of a drop-ship draft may change its cost and expected date but
  not its variant or quantity — DECISION: the customer's line fixes them; the handler refuses "A drop-ship line's variant and quantity are the customer's"); log
  `purchase_order.line_add|line_update|line_remove` (`line_id, line_no, sku, supplier_sku, qty_ordered, unit_cost, listing_variant_id, expected_on`); the answer
  re-renders `#po-lines` + `#po-totals` (`HX-Trigger: purchaseOrderChanged`).

## The purchase-order page (`purchase-order-view`, `data-entity="purchase_order"`)
- Header: the number, the status chip, the kind chip, the supplier (a link; their order method and account number for `purchasing.write`), the sales order (a link)
  and the customer's name for a drop-ship, ordered / expected (**overdue** `danger`), `supplier_order_ref`, sent via and when, acknowledged when, the totals (walled);
  `po-view-actions` by state and right (`purchasing.write` unless said): **Edit** (draft), **Send / Place** (draft → `/purchasing/{id}/send`; **Place** also on `sent`),
  **Acknowledge** (sent / acknowledged / partial — an inline form `po-ack-form`: supplier_ref, expected_on, a line select with "the whole order"), **Receive**
  (a stock order, sent / acknowledged / partial; `stock.receive`), **Close** (sent / acknowledged / partial / received), **Cancel** (draft / sent / acknowledged),
  **Rotate link** (sent or later).
- Regions: **`po-lines`** (Line · SKU / product / size · Supplier SKU · Ordered · Received · Unit cost (walled) · Line cost · Expected · Status chip · Tracking (carrier
  + number, shipped) · the offer it was ordered against (source · price · availability · as of) · the customer's line for a drop-ship (SO line N) · per line, on a
  sent order: **Decline** (`po-line-{id}-decline`, an inline reason form) and **Tracking** (`po-line-{id}-tracking`, an inline form: carrier, tracking, shipped_at));
  **`po-events`** (`mcp_purchase_order_events`: when, kind chip, **source** chip (`portal` "by the supplier" · `manual` · `email` · `phone`), line, reference /
  expected / carrier + tracking / reason / note, by); **`po-receipts`** (`mcp_goods_receipts` for this PO → `/receipts/{id}`, status, received on); **`po-link`**
  (`mcp_supplier_links` for `purchasing.write`: live / expires / none yet, opened N times, last opened; **Rotate**); **`po-ship-to`** (the location's name and
  address, or for a drop-ship the customer's name, address, notes and — marked "shown to the supplier" or "not shown" by `inv_po_shows_phone()` — the phone; the
  full address for `purchasing.write`, the city and region for everyone else — the view's rule); **`po-notes`**, **`po-attachments`** (lists; the add forms slice
  8's partials when their files exist); the trail.

## Send, place, acknowledge, decline, tracking, receive, close, cancel, the link
- **`purchase-order-send`** (the screen): `po-send-preview` (the email's subject and text as `purchase_order_mail()` renders it, the link shown as `/s/<a new link>` in
  words — the token is minted at send, never before); `po-send-message` (≤ 2,000); **Send by email** (`po-send-submit`, `via=email`); for `order_method = phone` a
  **Sent by phone** button (`via=phone` — records sent, mails nothing — DECISION); **Mark placed** (`po-place-form`: `supplier_ref` required ≤ 100, `via` ∈ `portal`,
  `api`, `edi` — `portal` by default; a link to `portal_url` beside it).
- **`purchase_order_send`** (`/purchasing/send.php`): `purchasing.write`; a draft with an open line (the SQL); `via` ∈ `email`, `phone` (`email` by default); for
  `email`: the supplier's `order_email` else `email` (none → 422 "The supplier has no email address — mark it placed or sent by phone."); in ONE transaction:
  `inv_po_send(po, me, via)` → `inv_supplier_link_mint(po)` (the raw token once — **it lives nowhere but the email**) → `purchase_order_mail()` → `malumail_send()`; a 2xx
  with `accepted` → commit; MaluMail's 400 → roll back, 422 "MaluMail refused the address: <reason>"; a transport error or 5xx → roll back, 503 "Mail could not be
  sent — try again."; no key → 503 "Email is not configured — the installer's mail step writes the key."; for `phone`: `inv_po_send(po, me, 'phone')`, no link
  minted (DECISION: a supplier reached by phone records nothing on a door; the Buyer records by hand). Log `purchase_order.send` (`number, supplier_id, supplier_name,
  kind, sales_order_id, ship_to_kind, location_id, lines, total, currency, sent_via, link_id, message_id` — never the ship-to address); **money_out**; location
  `/purchasing/{id}#po-link`; `purchaseOrderChanged`. The sales order's drop-ship lines become `ordered` (the SQL).
- **The mail** (`app/features/purchasing/mail.php` — `purchase_order_mail(array $po, array $lines, array $supplier, array $settings, string $rawToken, ?string $message,
  bool $showPhone): array` → `['subject', 'text', 'html']`): subject "Purchase order PO-00017 from <business_name>"; the body: the business's name, contact email and
  phone; "Your account for us: <account_number>" when set; the number and date; the lines (**supplier SKU** · our SKU · product · size · qty · unit cost · line cost);
  shipping and total; the expected date; **the ship-to**: a location's name and address, or for a drop-ship "Ship to our customer:" the name, address lines, city,
  region, postal, country, the delivery notes, **and the phone only when `inv_po_shows_phone(po)`** (`$showPhone`); the notes to the supplier (never
  `internal_notes`); the message; **the link** `inv_public_url('/s/' . $rawToken)` ("Acknowledge, decline a line or add tracking here"); `reply_to` the business
  contact. Payload keys as slice 5's. `purchase_order_link_mail(...)` — the "Updated link for PO-…" mail (below).
- **`purchase_order_place`** (`/purchasing/place.php`): `purchasing.write`; `supplier_ref` required (≤ 100); `via` ∈ `portal`, `api`, `edi` (`portal` default);
  `inv_po_place(po, me, ref, via)` (a draft is sent first by the SQL; a sent one gets the reference); **no link, no email** (placed on their portal — DECISION); log
  `purchase_order.place` (`number, supplier_order_ref, sent_via, …`); **money_out**; location `/purchasing/{id}`.
- **`purchase_order_acknowledge`** (`/purchasing/acknowledge.php`): `purchasing.write`; `supplier_ref` (≤ 100), `expected_on` (a date), `line` (one line of this PO, or empty
  = the whole order); `inv_po_acknowledge(po, line, ref, expected, 'manual', me)`; log `purchase_order.supplier_ack` (source `web`: `number, line_id, supplier_order_ref,
  expected_on`); location `/purchasing/{id}#po-events`.
- **`purchase_order_line_decline`** (`/purchasing/decline.php`): `line` (of a sent order), `reason` required (≤ 500); `inv_po_decline_line(line, reason, 'manual', me)` (the
  customer's line goes back to `open` — the SQL); **the order's salesperson is told** (`inv_notify(salesperson, 'line_at_risk', 'sales_order', order, 'Line N of SO-… was
  declined by <supplier>: <reason>')` — DECISION, by hand or by the door; nobody when the PO is for stock); log `purchase_order.supplier_decline` (`number, line_id, reason`);
  location `/purchasing/{id}#po-line-{line}`.
- **`purchase_order_tracking`** (`/purchasing/tracking.php`): `line`, `carrier` (≤ 60), `tracking` required (≤ 100), `shipped_at` (a date-time, default now);
  `inv_po_tracking(line, carrier, tracking, shipped_at, 'manual', me)` (a drop-ship line → the customer's `dropship` shipment and the line shipped — the SQL); the
  shipment's `tracking_url` set by slice 5's `tracking_url()` (DECISION — the function writes none); log `purchase_order.supplier_tracking` (`number, line_id, carrier,
  tracking_number, shipped_at`); location `/purchasing/{id}#po-line-{line}`.
- **`purchase-order-receive`** (the screen, `stock.receive`): the open lines (ordered − received each) and the receiving location (default the order's ship-to);
  **Draft the receipt**. **`purchase_order_receive`** (`/purchasing/receive.php`): `stock.receive`; `location` (default the PO's); `inv_receive_against(po, me, location)`
  (a drop-ship → the SQL's sentence; nothing open → its sentence) → a DRAFT goods receipt with a line per open PO line at the PO's cost; log `purchase_order.receive`
  (`number, goods_receipt_id, lines, units, short: []`); location `/receipts/{id}/edit` (slice 2's `receipt-edit` — the warehouse corrects quantities and costs and
  **posts** with slice 2's `receipt_post`, whose hook fills `qty_received`, writes the `received` events and moves the PO to partial / received); undo = `receipt_cancel`.
- **`purchase_order_close`** (`/purchasing/close.php`): `inv_po_close()` (open lines `closed_short`; the link's 90 days start); log `purchase_order.close` (`number, status`
  closed / closed_short, `lines_short`); location `/purchasing/{id}`. **`purchase_order_cancel`** (`/purchasing/cancel.php`): `reason` required; `inv_po_cancel()` (goods
  received or shipped → its sentence; the sales lines back to `open`; the link expires at once); **the salesperson is told** for a drop-ship (`line_at_risk`, "PO-…
  with <supplier> was cancelled: <reason>"); log `purchase_order.cancel` (`number, reason, kind, sales_order_id`); **deletion**; location `/purchasing/{id}`.
- **`purchase_order_link_rotate`** (`/purchasing/link-rotate.php`): `purchasing.write`; on a **draft**: `inv_supplier_link_mint()` and the new token discarded (the
  next send mints again); on a **sent, acknowledged or partial** order: mint and **email the new link at once** (`purchase_order_link_mail()` — "Updated link for
  PO-00017": the number, "the previous link no longer works", the new link; to the supplier's order email, the same refusal rules) — DECISION: `inv_po_send()` sends
  a draft only, so a sent order's new link can reach the supplier no other way; no email → 422 "The supplier has no email address — the old link is gone; there is
  nowhere to send the new one." before anything changes; log `purchase_order.link_rotate` (`number, link_id, mailed`); location `/purchasing/{id}#po-link`. The expiry
  pass is slice 5's `worker_pass_links_expire()` (`inv_expire_links()` covers `supplier_links_secure`); this slice adds nothing to it.

## The public door — the supplier's purchase-order page (D9)
- `GET /s/<48 hex>` (`html/s.php`; the vhost's and the dev router's rewrites exist for `/s/{token}` and `/s/{token}/{do}`): `$GLOBALS['__public_door'] = 'portal'`; the
  anonymous session the bootstrap opens carries the CSRF token; **no acting member** — the page reads base tables as the writer through `public_purchase_order()`;
  `inv_secure_link_purchase_order($token)` → the id or NULL → the one dead page **404** "This link has expired. Ask the business for a new one." (slice 5's
  `dead-link.php`; the token never echoed). **Rate limits** (DECISION — constants in `app/features/public/purchase_order.php`): `SUPPLIER_DOOR_LIMIT_PER_LINK_HOUR = 60`
  views, `SUPPLIER_DOOR_POST_LIMIT_PER_LINK_HOUR = 30` POSTs, `SUPPLIER_DOOR_LIMIT_PER_IP_HOUR = 300`, counted from `activity_log` rows with source `portal` and the
  `purchase_order_id` / `ip_address` in the last hour; over → **429**.
- The page (`app/views/public/purchase-order.php` in slice 5's `app/views/public/layout.php`): `noindex` meta and header; the business's name, contact email and
  phone; "Your account for us: <account_number>"; the number, date, status **in the supplier's words** (`sent` "New — please acknowledge" · `acknowledged`
  "Acknowledged" · `partial` "Partly shipped / received" · `received` "Received — thank you" · `closed` / `closed_short` "Closed" · `cancelled` "Cancelled");
  the lines (`public-po-line-{id}`: supplier SKU · our SKU · product · size · qty · unit cost · line cost · status in words · expected · tracking); shipping and
  total; **the ship-to** (`public-po-ship-to`: the location's name and address, or "Ship to our customer:" name, address, notes, **the phone when
  `inv_po_shows_phone()`**); the notes to the supplier; **never `internal_notes`, never another order, never a cost the supplier did not agree (the line's
  `unit_cost` is theirs)**. **The three forms** — each a plain `<form method="post" action="/s/<token>/<do>">` with `csrf_field()` and a honeypot `website` —
  shown only while the status is `sent`, `acknowledged` or `partial` (else "This order is <status>; nothing more to do here."): **Acknowledge**
  (`public-po-ack`: `supplier_ref` ≤ 100, `expected_on` a date, `line` a select "the whole order" + the open lines), **Decline a line** (`public-po-decline`:
  `line` the open or acknowledged lines, `reason` required ≤ 500), **Add tracking** (`public-po-tracking`: `line` the lines not declined / cancelled / received,
  `carrier` ≤ 60, `tracking` required ≤ 100, `shipped_at` date-time default now). Plain HTML, no HTMX, whole at 375 with JavaScript off. Logged
  `purchase_order.supplier_view` (source `portal`, actor null, `purchase_order_id`, `after: {number, link_id, view_count}`).
- **`POST /s/<token>/acknowledge | decline | tracking`** (the same `html/s.php`, `do` from the rewrite): `require_post()`; the token re-resolved by
  `inv_secure_link_purchase_order()` (a dead token on POST → the 404 page; the view count moves — accepted); `verify_csrf()` (a missing or wrong token → 403 the page
  with "The form expired — reload the page."); the honeypot filled → 200 the page unchanged, nothing written, nothing logged; the POST limit; the fields validated
  (a bad date, a missing reason → the page with a `danger` box naming the field); `line` must belong to this PO (else the danger box); then the verb with
  **`p_source = 'portal'`, `p_by = NULL`**: `inv_po_acknowledge(po, line, ref, expected, 'portal', NULL)` · `inv_po_decline_line(line, reason, 'portal', NULL)` ·
  `inv_po_tracking(line, carrier, tracking, shipped_at, 'portal', NULL)`; the SQL's refusal (`P0001`) → the page with the sentence in a `danger` box (422); success →
  the page re-rendered with a `success` box ("Acknowledged — thank you." / "Line N declined." / "Tracking added for line N."). Each success: logged
  `purchase_order.supplier_ack | supplier_decline | supplier_tracking` (source `portal`, actor null, `purchase_order_id`, `after: {number, line_id, supplier_order_ref,
  expected_on, reason, carrier, tracking_number, shipped_at, link_id}` — never the token); **the Buyer is told** (`notify_buyer()` from slice 5 — kinds `po_ack`,
  `po_decline`, `po_tracking`: "PO-00017 acknowledged by Malouf — ref M-88213, expected <date>" / "Malouf declined line N of PO-…: <reason>" / "Malouf shipped line N
  of PO-…: UPS 1Z…"); a decline also tells the salesperson (`line_at_risk`, as by hand); tracking on a drop-ship line makes the customer's shipment (the SQL) and
  slice 5's `tracking_url()` is written on it.

## Files (exactly these)
- `html/suppliers/index.php` · `form.php` · `view.php` · `save.php` · `archive.php` · `message.php`
- `html/purchasing/index.php` · `form.php` · `view.php` · `lines.php` (the fragment) · `line-defaults.php` (the fragment) · `send.php` (GET the screen, POST the action) ·
  `receive.php` (GET the screen, POST the action) · `place.php` · `acknowledge.php` · `decline.php` · `tracking.php` · `close.php` · `cancel.php` · `link-rotate.php` · `save.php`
- `html/purchasing/lines/save.php` · `remove.php`
- `html/s.php`
- `app/features/suppliers/queries.php` · `present.php` · `write.php` · `handler.php` — `app/features/purchasing/queries.php` · `present.php` · `write.php` · `handler.php` ·
  `mail.php` — `app/features/public/purchase_order.php`
- `app/views/suppliers/index.php` · `form.php` · `view.php` · `partials/supplier-card.php` · `partials/items.php` · `partials/lead-times.php` — `app/views/shared/supplier-select.php`
  (the ONE supplier picker, beside slice 5's `customer-select.php`) — `app/views/purchasing/index.php` · `form.php` · `view.php` · `send.php` · `receive.php` · `partials/po-row.php` ·
  `partials/lines.php` · `partials/line-row.php` · `partials/totals.php` · `partials/dropship-draft.php` · `partials/events.php` · `partials/receipts.php` · `partials/link-state.php` ·
  `partials/ship-to.php` · `partials/ack-form.php` · `partials/line-decline-form.php` · `partials/line-tracking-form.php` — `app/views/public/purchase-order.php`
- `app/partial_update.php` (the two targets)

## Query functions (signatures fixed)
- Suppliers: `find_suppliers(PDO, array $filters, int $limit = 100, int $offset = 0): array` (`mcp_suppliers` + open PO count, source count) · `count_suppliers(PDO, array $filters): int` ·
  `find_supplier(PDO, int $id): ?array` · `save_supplier(PDO, ?int $id, array $fields, int $by): int` · `archive_supplier(PDO, int $id, bool $active): void` · `supplier_open_order_count(PDO, int $id): int` ·
  `supplier_items_for(PDO, int $supplierId, int $limit = 200): array` (`mcp_supplier_items`) · `supplier_open_orders(PDO, int $supplierId): array` (`inv_purchase_orders_open()` kept to the supplier) ·
  `supplier_lead_times(PDO, int $supplierId): ?array` (`inv_lead_time_actuals(id)` — null for a caller without `reports.read`, the view's refusal caught) · `supplier_shipped_lines(PDO, int $supplierId, int $limit = 20): array` ·
  `supplier_sources(PDO, int $supplierId): array` · `message_supplier(PDO, int $supplierId, string $subject, string $body, ?int $purchaseOrderId, int $by): array` (the mail + the event row)
- Purchase orders: `find_purchase_orders(PDO, array $filters, int $limit = 50, int $offset = 0): array` · `count_purchase_orders(PDO, array $filters): int` · `find_purchase_order(PDO, int $id): ?array`
  (`mcp_purchase_orders` + the supplier, lines with their offer and the customer's line, events, receipts, the link state, the sales order, notes and attachments counts) ·
  `find_purchase_order_by_number(PDO, string $number): ?array` · `po_lines(PDO, int $poId): array` · `find_po_line(PDO, int $lineId): ?array` · `draft_purchase_order(PDO, array $head, array $lines, int $by): int` ·
  `draft_dropships_for_order(PDO, int $orderId, int $by): array` (`inv_order_dropships_draft()` → the POs drafted) · `order_open_dropship_lines(PDO, int $orderId): array` (grouped by supplier) ·
  `update_purchase_order(PDO, int $id, array $head, int $by): array` · `save_po_line(PDO, int $poId, ?int $lineId, array $fields): int` · `remove_po_line(PDO, int $lineId): void` ·
  `po_line_defaults(PDO, int $supplierId, int $variantId): array` (`['supplier_sku', 'unit_cost', 'moq', 'offers' => [...]]`) · `po_line_from_request(array $row): array` (present.php) ·
  `reorder_rows_for(PDO, int $supplierId): array` (`inv_reorder_candidates()` kept to the supplier) · `send_purchase_order(PDO, int $poId, string $via, ?string $message, int $by): array`
  (the transaction: send, mint, mail, commit; `['link_id', 'message_id', 'sent_via']`) · `place_purchase_order(PDO, int $poId, string $ref, string $via, int $by): array` ·
  `acknowledge_purchase_order(PDO, int $poId, ?int $lineId, ?string $ref, ?string $expected, string $source, ?int $by): array` · `decline_po_line(PDO, int $lineId, string $reason, string $source, ?int $by): array` ·
  `track_po_line(PDO, int $lineId, ?string $carrier, string $tracking, ?string $shippedAt, string $source, ?int $by): array` (+ the shipment's `tracking_url`) ·
  `receive_against(PDO, int $poId, ?int $locationId, int $by): array` (the receipt) · `close_purchase_order(PDO, int $poId, int $by): array` · `cancel_purchase_order(PDO, int $poId, int $by, string $reason): array` ·
  `rotate_supplier_link(PDO, int $poId, int $by): array` (`['link_id', 'mailed']`) · `po_events(PDO, int $poId): array` · `po_receipts(PDO, int $poId): array` · `po_link_state(PDO, int $poId): ?array` ·
  `po_shows_phone(PDO, int $poId): bool` (`inv_po_shows_phone()`) · `notify_salesperson_line(PDO, int $salesOrderLineId, string $kind, string $title): ?int` · `po_refused(Throwable $e): never`
- Mail: `purchase_order_mail(array $po, array $lines, array $supplier, array $settings, string $rawToken, ?string $message, bool $showPhone): array` · `purchase_order_link_mail(array $po, array $supplier, array $settings, string $rawToken): array` · `supplier_message_mail(array $supplier, array $settings, string $subject, string $body, ?array $po): array`
- Public: `public_purchase_order(string $rawToken): ?array` (the PO, lines, supplier, settings, `shows_phone` — as the writer) · `supplier_status_word(string $status): string` · `door_post_rate_ok(PDO, int $poId, string $ip): bool` · `door_view_rate_ok(PDO, int $poId, string $ip): bool` ·
  `door_acknowledge(PDO, int $poId, array $post): array` · `door_decline(PDO, int $poId, array $post): array` · `door_tracking(PDO, int $poId, array $post): array` (each: validate, the verb as `portal`, the log row, the notices; `['ok', 'message']`)

## Handlers (every one: `inv_handler_begin()`; the right; the record through its view; `inv_guard()` around one transaction; `log_activity` with `purchase_order_id` (+ `sales_order_id` for a drop-ship, `location_id` for a stock order's ship-to); `inv_done`; `HX-Trigger: purchaseOrderChanged` or `supplierChanged`)
As listed above. `send.php` and `receive.php` serve their screen on GET and the action on POST. The door (`s.php`) runs with no acting member, `source = portal`,
never through the views, never through `inv_handler_begin()`; its POSTs go through `require_post()` + `verify_csrf()` + the limits + the verbs.

## Manifest rows claimed
Screens (11): `purchase-order-list`, `purchase-order-add`, `purchase-order-view`, `purchase-order-edit`, `purchase-order-send`, `purchase-order-receive`, `supplier-list`, `supplier-add`, `supplier-view`, `supplier-edit`, `supplier-door`
Actions (18): `supplier_create`, `supplier_update`, `supplier_archive`, `supplier_message`, `purchase_order_draft`, `purchase_order_update`, `purchase_order_line_add`, `purchase_order_line_update`, `purchase_order_line_remove`, `purchase_order_send`, `purchase_order_place`, `purchase_order_acknowledge`, `purchase_order_line_decline`, `purchase_order_tracking`, `purchase_order_receive`, `purchase_order_close`, `purchase_order_cancel`, `purchase_order_link_rotate`
The eleventh screen is the door `supplier-door`. Agent approvals (as the manifest and
`maludb-os.json`): `purchase_order_send`, `purchase_order_place` (`money_out`); `supplier_message` (`external_send`); `purchase_order_cancel` (`deletion`).
The supplier's three POSTs (the manifest's last table): `POST /s/{token}/acknowledge` → `inv_po_acknowledge()` · `/decline` → `inv_po_decline_line()` · `/tracking` →
`inv_po_tracking()`, each `source = portal` — **not actions**. `PARTIAL_UPDATE_TARGETS` gains `/suppliers/save.php` and `/purchasing/save.php`.
Left to another slice, and why: `supplier-item-list`, `supplier_item_save`, `supplier_item_remove` (slice 3's rows — the price sheet is the feed's and the Buyer's
sheet; read here); `receipt-edit`, `receipt_post`, `receipt_cancel` (slice 2 — "receive against" lands there); `order_confirm` (slice 5 — it drafts through the same
function this slice's "draft for order" calls); `note_add`, `attachment_add` (slice 8).

## Activity log events (every row: `purchase_order_id`; a drop-ship's rows also `sales_order_id`; a stock order's `location_id`)
`supplier.create|update|archive`, `supplier.message`, `purchase_order.draft|update|line_add|line_update|line_remove|send|place|close|cancel|link_rotate|receive`,
`purchase_order.supplier_ack|supplier_decline|supplier_tracking` (source `web` by hand, `portal` by the door), `purchase_order.supplier_view` (the door), `screen.view`
(on `purchase-order-view` `after.number`). **Never `account_number`, never the ship-to address when it is the customer's, never a token, never `internal_notes`, never a
message's body**; amounts with `currency`; cost is allowed on a row (the log is the admin's and the view's).

## Notifications this slice queues and sends
| Step | Who is told | How |
|---|---|---|
| `purchase_order_send` by email; `purchase_order_link_rotate` on a sent order; `supplier_message` | the supplier | **MaluMail inline** (`malumail_send()`), never the outbox |
| the supplier acknowledges / declines a line / adds tracking **by the door** | the Buyer (`notify_buyer()` — `inv_settings.buyer_member_id`, else the first super-admin) | `notifications` kinds `po_ack`, `po_decline`, `po_tracking` (+ the outbox per prefs; slice 8 sends) |
| a drop-ship line is declined (by the door or by hand); a drop-ship PO is cancelled | the sales order's salesperson | `line_at_risk` |
| a stock order is received | — nobody; the receipt's posting is slice 2's and the morning note counts the rest | — |

## Status vocabulary
PO status: `draft` secondary · `sent` info · `acknowledged` primary · `partial` warning · `received` success · `closed` dark · `closed_short` dark (with "short") · `cancelled`
danger; **overdue** a `danger` badge; **awaiting acknowledgment** a `warning` badge past `ack_days`. Line status: `open` secondary · `acknowledged` info · `declined` danger
(struck) · `partial` warning · `shipped` primary · `received` success · `closed_short` dark · `cancelled` dark. Kind chips: `stock` `feather-box` · `dropship` `feather-truck`.
Event source chips: `portal` "by the supplier" info · `manual` secondary · `email` · `phone`. Supplier kind chips as words; "drop-ships" `feather-truck` success.
Ids: `supplier-card-{id}`, `supplier-form`, `supplier-form-field-{name}`, `supplier-view-tabs`, `supplier-items`, `supplier-open-orders`, `supplier-lead-times`, `supplier-sources`,
`po-list-table`, `po-row-{id}`, `po-form`, `po-form-field-{name}`, `po-form-dropship-{supplier_id}`, `po-form-draft`, `po-lines`, `po-lines-add`, `po-line-{n}`, `po-line-{n}-search`,
`po-line-{n}-pick`, `po-line-{n}-qty`, `po-line-{n}-cost`, `po-totals`, `po-view-actions`, `po-line-{id}`, `po-line-{id}-decline`, `po-line-{id}-tracking`, `po-ack-form`, `po-events`,
`po-event-{id}`, `po-receipts`, `po-link`, `po-ship-to`, `po-notes`, `po-attachments`, `po-send-preview`, `po-send-message`, `po-send-submit`, `po-send-phone`, `po-place-form`,
`po-receive-lines`, `po-receive-location`, `po-receive-submit`, `public-po`, `public-po-line-{id}`, `public-po-ship-to`, `public-po-ack`, `public-po-decline`, `public-po-tracking`,
`public-po-flash`.

## The 375 px rule
The PO page works on a phone — the Buyer acknowledges from the parking lot: the header, then the regions stacked, the lines table in `.table-responsive`, the inline
acknowledge / decline / tracking forms one column with controls ≥ 44 px, the actions stacked. The form is designed at 1280 (the lines editor two-column) and
usable at 375 (a line a card). The door is plain HTML, three one-column forms, whole at 375 with JavaScript off. The lists are tables wrapped at 375.

## Vocabulary
**a purchase order** = addressed to one supplier, for stock (ships to a location) or for a drop-ship (ships to the customer, one per supplier per sales order);
**send** = emailed with the supplier's link (`money_out` for an agent); **place** = recorded as placed on their portal, with their reference; **acknowledge** = the
supplier confirms, with a reference and a date, the whole order or a line; **decline** = the supplier cannot fill a line — the customer's line is open again, at
risk; **tracking** = the supplier shipped a line — for a drop-ship, the customer's shipment; **receive against** = a draft goods receipt for what is still open;
**closed short** = closed with lines never received; **the link** = the supplier's door, minted at send, mailed anew on rotate, dead 90 days after close.

## Out of scope for this slice
The price sheet's screen and actions and the feed that keeps it (3); the goods receipt's edit and posting (2); the sales order's screens and the confirmation that drafts
(5); returns to a supplier (Extended, D14); API and EDI ordering (Extended, D9); a supplier account (rejected by design); the outbox sender (8); the lead-time and
source reports (9); the records tools (Phase 4).

## Proof (`tests/phase3/slice6/run.sh`: the scratch database `inv_dev6`; the fake kernel at 8602; the fake MaluMail at 8606; the app at 8607 through `tests/dev_router.php`;
members through `bin/dev_handoff.php`; curl with signed action and run tokens and a cookie jar for the door's CSRF; headless Chromium at 375 × 740 and 1280 × 800; the
registry and `sync_approvals --check`) — **at least 200 checks, the door's three POSTs and a replayed and an expired token included**
The world (`tests/phase3/slice6/lib.php` `purchasing_world()`): slice 5's world (the suppliers SMOKE Malouf — portal, drop-ships, lead 5, account `DLR-1001`, order email —
and SMOKE Zinus — email, lead 3; their matched offers; the customer; a confirmed order with a stock Queen and two drop-ship lines whose confirmation drafted one PO per
supplier), the Queen under its reorder point with Zinus the cheapest in-stock offer, `supplier_sees_phone` at its default (`ltl`, `white_glove`), the Buyer Nora, the
order's salesperson Sam, a Cal King line that ships `ltl` and a King that ships `parcel`.
- [ ] **Suppliers** (≈ 25): create with every field, a duplicate name → 422, a bad URL and a bad email → field errors; the card shows "drop-ships" and the lead time;
  Vera's page shows no account number, Nora's does; the log row never carries it; update keeps what was left out; archive refused with an open PO in words, allowed
  after its cancel; the view's items tab lists the price sheet with cost for Nora and without for Sam, the open orders tab, the lead-times tab for Nora (`reports.read`)
  and "—" for Sam, the sources tab; `supplier_message` with a subject and body → one MaluMail call to the order email, `supplier.message` logged with the length and
  never the body, the `note` event on the named PO; no email → 422; `external_send` in the registry.
- [ ] **A stock order** (≈ 40): `/purchasing/new?supplier=<Zinus>&reorder=1` prefills the Queen row from the reorder candidates (qty = reorder_qty, Zinus's cost, its
  listing variant); `line-defaults` answers the price sheet's SKU, cost and MOQ for Malouf, the offer's cost when the sheet has none; a draft with two lines numbered
  `PO-`, `ship_to_kind location`, the supplier SKUs filled by the trigger, the totals; a bundle as a line → the trigger's sentence; a line with no variant → 422; qty 0 →
  422; `purchase_order.draft` logged with the keys; `line_add` / `line_update` / `line_remove` on the draft re-render the regions and recompute; a line on a sent
  order → the SQL's sentence; `update` of the expected date, of the supplier (the SKUs re-read); Sam → 403 in words (no `purchasing.write`); the expert (run token)
  drafts (its `purchase_order_draft` is free in the registry).
- [ ] **A drop-ship order** (≈ 20): the two POs the confirmation drafted show on the list with the sales order link and under the order; `/purchasing/new?order=<SO>&kind=dropship`
  lists no open line (all drafted) and the draft → 422 "has no open drop-ship line"; cancel the Malouf draft (its sales line back to open) → the form lists the Cal
  King under Malouf and **Draft** makes a new PO through `inv_order_dropships_draft()` with the line's snapshotted cost and `sales_order_line_id`; `supplier` on a
  drop-ship update → 422 in words; a drop-ship line's qty change → 422 "the customer's"; its cost change allowed; the PO page shows the customer's name and, for Nora,
  the address with the phone marked "shown to the supplier" (Cal King ships `ltl`); the parcel-only PO marks it "not shown".
- [ ] **Send and place** (≈ 30): the send screen's preview carries the lines with supplier SKUs, the ship-to, the account number, the notes and not the internal
  notes; send by email on Zinus's PO → one MaluMail call to `dealers@zinus.invalid` with "Purchase order PO-…", the text carrying `/s/<48 hex>` once, the customer's
  address and **the phone** for the `ltl` drop-ship and **no phone** for the parcel one (a second PO), **no "internal"** (grep); status sent, `sent_via email`, `sent_by`,
  the `sent` event, the sales lines `ordered`, `purchase_order.send` logged with `link_id` and never the address; `mcp_supplier_links` live; sending twice → 422;
  a supplier with no email → 422 in words; `suppressed@…` → 422 and **no link row** (the rollback), status still draft; `flaky@…` → 503 and nothing changed; sent by
  phone → sent, no mail, no link; **place** on a draft with ref `M-88213` → sent then the reference, the `placed` event, no mail; place without a ref → 422; place on an
  acknowledged one → the SQL's sentence; the expert's `purchase_order_send` and `purchase_order_place` are `money_out` in the registry.
- [ ] **By hand** (≈ 25): acknowledge the whole order with a ref and a date → lines acknowledged, `acknowledged_at`, `expected_on` moved, the `acknowledge` event source
  `manual` with Nora, `purchase_order.supplier_ack` logged source `web`; acknowledge one line; acknowledge a draft → 422; decline a line with a reason → the line
  declined and struck, the customer's line `open` again, the PO total dropped, **Sam's notification `line_at_risk`**, the event, the log; decline without a reason →
  422; decline a shipped line → 422; tracking on the Cal King line (`UPS`, `1Z…`) → the line shipped, **a `dropship` shipment on the sales order with `tracking_url`**,
  the customer's line shipped, the event, the log; tracking without a number → 422; tracking on a received line → 422; the events table shows every row with its
  source chip.
- [ ] **Receive against, close, cancel** (≈ 25): receive against the stock PO → a DRAFT goods receipt with a line per open line at the PO's cost, landing on
  `/receipts/{id}/edit`, `purchase_order.receive` logged; receive a drop-ship → the SQL's sentence; receive a draft PO → 422; Sam → 403 (no `stock.receive`), Wes may;
  posting the receipt (the proof calls `inv_post_receipt()` as slice 2's handler does) short by one → the PO `partial`, `qty_received`, the `received` event, the
  variant's cost set by `last_receipt`; close → `closed_short` with the open line `closed_short`, the link's 90 days set; close a draft → 422; cancel a draft →
  cancelled, its link expired at once; cancel with goods received → "close it short instead"; cancel a sent drop-ship → the sales line `open`, Sam told; `deletion`
  in the registry; delivering the customer's drop-ship shipment (slice 5's verb, called by the proof) → the PO line `received` and the PO `received`.
- [ ] **The door** (≈ 40): the live token → 200 with `noindex`, "New — please acknowledge", the account number, the lines with supplier SKUs and costs, the ship-to
  with the phone for the `ltl` PO and without for the parcel PO, the notes, **never "internal"**, the three forms with a CSRF field; `supplier_view` logged source
  portal, actor null, `view_count` 1; **acknowledge** (a cookie jar, the page's CSRF token, `supplier_ref`, `expected_on`, the whole order) → 200 the success box,
  the lines acknowledged, the event source `portal` with `member_id` null, `purchase_order.supplier_ack` source portal, **Nora's `po_ack` notification**; a POST
  without the CSRF token → 403 "The form expired…" and nothing written; the honeypot filled → 200 and nothing written, nothing logged; **decline** line 1 with a
  reason → the line declined, the sales line open, Nora's `po_decline`, **Sam's `line_at_risk`**; a line of another PO → the danger box, nothing written; no reason →
  the danger box; **tracking** on line 2 → the line shipped, the customer's `dropship` shipment, Nora's `po_tracking`; tracking on the declined line → the SQL's
  sentence in the box; after close the forms are gone ("nothing more to do here"); **a replayed token** — after `purchase_order_link_rotate` the old token → 404 on
  GET and on each of the three POSTs (nothing written), the new link mailed ("Updated link for PO-…" in the fake's log, the old link named as dead) and opening;
  **an expired token** — the closed PO's `expires_at` backdated by the proof → 404, and `inv_expire_links()` (slice 5's pass) marks it rotated; a cancelled PO's
  link → 404 at once; a malformed token → 404; the 61st view in an hour → 429; the 31st POST → 429; the 404 page never echoes the token.
- [ ] **JSON mode** (≈ 10): every handler under a signed action token answers `{ok, did, record_id, location, refresh}` with its facts (`number`, `link_id`,
  `goods_receipt_id`, `sent_via`, the POs drafted for a drop-ship); `_partial=1` keeps a supplier's fields; 422 `{error: {code: invalid, fields}}`; the registry reads
  the 11 screens and 18 actions built; `sync_approvals --check` clean.
- [ ] **375 × 740 and 1280 × 800** (≈ 15): the PO page on a phone — the acknowledge form inline, the per-line decline and tracking forms, the actions stacked; the door
  at 375 whole with JavaScript off, a decline submitted through the browser; at 1280 the list with the overdue and awaiting-acknowledgment badges, the form's lines
  editor with the pick list and the defaults loading, the send screen's preview; every control ≥ 44 px, `scrollWidth` = viewport, no console errors.

## Built and proven
**2026-10-10 — BUILT and proven by a worker (Sonnet 5.5)** (`tests/phase3/slice6/run.sh` on the scratch database `inv_dev6`; :8606 is the fixture server while the world is built, then the fake MaluMail the purchase orders' e-mails go to:
**343 checks green under php -S and 343 under a real Apache** — world 10, suppliers 40, stock 54, dropship 29, send 36, hand 29, receive 35, door 52, json 30, browser 28 at 375 × 740, 1280 × 800 and JavaScript off; the registry — **83 screens and 103 actions
built** (the eleven screens and eighteen actions of this slice), **13 placeholders** — and the approvals in step). Every file of "Files" is built (plus `html/assets/js/purchasing.js`, the pick list's glue, and `app/views/purchasing/partials/line-defaults.php`).
The earlier suites re-run green — slices 5, 4, 3, 2, 1, Phase 2 (its placeholder count 13 and its examples moved to `/admin/connections`, `/admin/dispatches`, `/returns/`, `/reports/`), Phase 0 (42 + 517 + 511), the Phase 1 claim checks — and the
installer's plan is clean (57 steps). The spec's "Open questions" stayed empty: nothing stopped the slice. **One migration: `db/020_po_cancel_releases_sales_lines.sql`.**

**Found and fixed (not questions):**
- **`inv_po_cancel()` left the customer's drop-ship line tied to the cancelled purchase order line** (db/011): it put `ordered` lines back to `open` but kept `purchase_order_line_id`, and `inv_order_dropships_draft()` drafts only lines with no
  purchase order line — so a cancelled drop-ship's line could never be drafted again (the form listed nothing, "Draft" refused "has no open drop-ship line" for an open line), which the spec's proof ("cancel the Malouf draft → the form lists the Cal King under
  Malouf and Draft makes a new PO") needs. db/020 copies the function's body and frees the link for `open` and `ordered` lines alike. A DECLINED line keeps its link on purpose (`inv_lines_at_risk()` reads the declined line through it).
- **Two functions named `supplier_sources`**: slice 1's `supplier_sources(PDO)` (every supplier source, for an identifier's select) and the spec's `supplier_sources(PDO, int)`. The spec's is `supplier_sources_of()`.
- **`po_status_chip()`** (slice 5's, shown on the order page and today's drop-ships) now speaks the spec's vocabulary (acknowledged primary, closed_short dark "Closed short", cancelled danger, partial "Partly received") and takes an optional id.
- **The SQL's order of refusals for a cancel**: a PO with goods received is `partial`, and `inv_po_cancel()` checks the status first — "PO-… is partial — close it instead"; "…has goods received or shipped — close it short instead" is what an order with a SHIPPED line (status sent / acknowledged) answers. The proof asserts both.
- **The fixture's expert is a Sales agent** (it holds `user`): it may not raise purchase orders (403, as it should). The proof gives its mirror row the Buyer role for the one step that proves "an agent drafts" and puts it back.
- **The Shopify pull already keeps a price sheet** (`supplier_items` for Malouf, no cost): the world gives the Queen's entry a cost of 700.00 and a minimum of 2 instead of inserting one, and the Cal King's cost falls through to the offer's (1299.00, "the sheet has none").

**Decisions taken while building (not questions):**
- **The fixtures' figures, not the spec's**: the spec's "send on Zinus's PO … the ltl drop-ship and the parcel one" is Malouf's drop-ship (the Cal King, ships ltl, phone shown) and Zinus's (the King, parcel, no phone); the stock order the send proof mails is Zinus's.
  Zinus' Queen is out of stock in the feed fixture, so the reorder candidate is the Twin (reorder point 5, quantity 6, Zinus the cheapest in-stock offer at 349.50); a re-run lifts its reorder point above what is on order.
- **A drop-ship draft refuses a quote** ("SO-… is a quote — confirm it first; its drop-ships are drafted then.") and a cancelled or closed order, and a line whose source has no supplier ("Line N's source has no supplier…"); the order is looked up by id or by number.
- **A line's lines/save and lines/remove leave the "only a draft changes" refusal to the SQL** (the trigger's sentence: "The lines of a purchase order change only while it is a draft (it is sent)"); the header's refusal is the handler's. A drop-ship's line is never added or removed here.
- **Changing a draft's supplier clears the lines' supplier SKUs AND their offers** (the offers are the old supplier's); the form says so.
- **The account number**: on the supplier's form only for a holder of `purchasing.write` (a save from anyone else keeps it — the base row is read for "a field left out stays"); never in a log row or any view for the rest.
- **Rotating a link**: refused for a received, closed or cancelled order ("… its link is not rotated" — a new live link on a cancelled order would outlive its cancel); the button shows from `sent` on; the API still rotates a draft's.
- **The ship-to's full address and phone are read from the base table for `purchasing.write`** (the view carries the city and region); the PO page marks the phone "shown to the supplier" / "not shown" by `inv_po_shows_phone()`.
- **The door's POST limit counts the three logged success rows** (`supplier_ack`, `supplier_decline`, `supplier_tracking`, source portal) per link (30) and per address (300); the view limit the `supplier_view` rows (60 / 300). The honeypot `website` is off-screen, never counted as a control. The Buyer is told by `notify_buyer()`; a decline also tells the salesperson (`line_at_risk`, by hand and by the door).
- **The purchase-order page's tabs are `po`, `notes`, `attachments`, `trail`**; the lines table is read-only and the Decline / Tracking forms are a card per live line under it (a table cell is no place for a form on a phone); the acknowledge form is a `<details>`.
- **`po_for_mail()` reads the base tables** (the caller holds purchasing.write): the account number, the full ship-to, the supplier SKUs; the mail never carries the internal notes (the proof greps for "internal").
- **`purchasing.js`** (≈ 75 lines) is the only script: it fills a picked variant's line and fetches `/purchasing/line-defaults` (an HTML fragment whose data attributes the script copies into the cost box); JavaScript off, the typed SKU and the server's defaults do the same job.
- The send screen shows "Sent by phone" only for a supplier whose order method is phone; the action itself (`via=phone`) is open to every order.

## Decisions taken while writing (2026-10-05)
- A drop-ship purchase order is drafted by `inv_order_dropships_draft()` alone (the confirmation's call, and this slice's "draft for order"); `lines` on a drop-ship
  draft are ignored; a drop-ship line's variant and quantity are the customer's and never change here; its cost and expected date may.
- A stock line's `unit_cost` defaults from the price sheet, else the supplier's offer, else the variant's cost, else 0 — read by the `line-defaults` fragment and the
  handler alike; changing a draft's supplier re-reads the supplier SKUs.
- The link is minted at send and lives in the email alone; `via=phone` sends nothing and mints nothing; placing mints nothing; rotating a sent order's link mails the
  new link at once (a draft's rotate just rotates) because `inv_po_send()` sends a draft only; no supplier email → the rotate is refused before anything changes.
- A MaluMail refusal rolls the send back (status stays draft, no link row); the payload keys are the `malumail-send` skill's; the supplier's phone goes in the mail and
  on the door only when `inv_po_shows_phone()` says so.
- A declined drop-ship line (by hand or by the door) and a cancelled drop-ship PO tell the sales order's salesperson (`line_at_risk`); the door's three POSTs tell the
  Buyer (`po_ack`, `po_decline`, `po_tracking`) through slice 5's `notify_buyer()`; by hand the Buyer is not told (they did it).
- `supplier_message` with a PO named writes a `note` event (source `email`) on it; `tracking_url` on the customer's drop-ship shipment is slice 5's map, written here.
- The door: the honeypot is `website`; a missing CSRF token is a 403 with the page; the limits are three constants counted from the activity log; the dead page is one
  404 for every reason; the status words are the supplier's; the forms vanish once the order is received, closed or cancelled.
- `supplier_items` and the goods receipt's editing and posting are slices 3 and 2; the expiry pass is slice 5's; nothing here changes `bin/worker.php`.

## Open questions
(none)

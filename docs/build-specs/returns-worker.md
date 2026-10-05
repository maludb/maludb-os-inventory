# Build spec: returns, the Buyer agent's data, notifications and the worker's passes (slice 8)

Built by the worker model from this spec, replicating slice 3's files, gates and proof style (`docs/build-specs/sources.md`, the exemplar) and
slice 5's document pattern (a header with lines, the verbs as handlers — `docs/build-specs/orders.md`). What exists at the end: a salesperson
requests a **return** on a shipped order (lines with a reason, pickup or drop-off, a disposition per line), the Buyer approves or denies it, the
warehouse **receives** it (a restock writes the `return` movement, a floor model goes on the floor, dispose and donate touch no stock, a return
to the supplier notes the supplier's purchase order), the Buyer closes it with the refund and restocking fee recorded; **the Buyer agent's data**
— the seven headings of the morning note composed from the schema's seven functions, `buyer_proposals` recorded, accepted and dismissed, the
note sent to the Buyer through the bell and the outbox; **notifications** — one queueing function, the kinds with their words and links, the
bell's rows linking to their records, the outbox sent by the worker (email through MaluMail, texts to members through the kernel's K6);
**the worker's passes this slice owns** (`outbox`, `dispatches`, `key_usage_prune` — reconciled 2026-10-05): the outbox, the dispatches (a watch
that named an agent becomes one chat turn through the kernel as that agent, the reply a notification), key-usage pruning — each idempotent, each
its own try, the pass logged once as `worker.pass`; **notes and attachments** on any record, with the gated file door. Nothing here calls a model,
sends to a customer or a supplier, spends or deletes a record of another slice.
Schema: `return_authorizations`, `return_lines`, `inv_returns_before()`, `inv_return_lines_before()`, `inv_return_approve()`,
`inv_return_deny()`, `inv_return_receive()`, `inv_return_close()` (db/012); `reason_codes` (`applies_to @> '{return}'`, db/005); `notifications`,
`notification_prefs`, `notification_outbox`, `inv_notify()`, `agent_dispatches`, `buyer_proposals`, `inv_watch_title()`, `inv_fire_watches()`,
`inv_prune_key_usage()`, `inv_expire_links()` (db/013); `inv_snapshot_heartbeat()` (db/009); `inv_lines_at_risk()`, `inv_reorder_candidates()`,
`inv_price_exceptions()`, `inv_unmatched_listings()`, `inv_source_health()`, `inv_purchase_orders_open()`, `inv_returns_open()` (db/014);
`inv_settings.buyer_member_id`, `snapshot_heartbeat_days`, `max_attachment_bytes`, `ack_days` (db/005); `attachments`, `notes` (db/006); the
views `mcp_return_authorizations`, `mcp_return_lines`, `mcp_sales_orders`, `mcp_sales_order_lines`, `mcp_reason_codes`, `mcp_locations`,
`mcp_notifications`, `mcp_notification_prefs`, `mcp_notification_outbox`, `mcp_agent_dispatches`, `mcp_buyer_proposals`, `mcp_members`,
`mcp_notes`, `mcp_attachments`, `mcp_watches` and the record views the file door checks (db/015). Never modify them. **The database is the
referee**: a return is against a confirmed order; a line returns at most what shipped and is not yet returned (other open returns counted); a
reason is a return reason; lines change while requested or approved; the status moves only through the four verbs; receiving writes the
movements its dispositions say and counts `qty_returned` on the order's lines; a watch fires once per state change; a notification goes to
the channels the person's preferences allow and a dedupe key makes a second queue a no-op; a feed key's buckets prune on the schema's clock.

## Divergences from the design, the manifest and the kit
1. **The worker's passes go to the slice whose data they touch** (the lead's division, reconciled 2026-10-05): `pulls` → slice 3;
   `snapshots_heartbeat` and `watches` → slice 4 (find.md, as written — `inv_fire_watches()` runs in slice 4's pass and the `watch.fire` rows are
   logged there); `links_expire` → slice 5 (orders.md, as written — one function for both doors); **`outbox`, `dispatches` and `key_usage_prune` →
   this slice.** `bin/worker.php`'s header comment and `connectors.md` §6.3 step 10 say exactly this division; this slice fills its three stubs and
   changes nothing else of `bin/worker.php`. **DECISION 1.**
2. **The heartbeat pass does not mark removals.** The stub's comment says "and the removals"; `connectors.md` §6.3 step 5 decided removals happen
   inside a full pull (`inv_mark_removed()` after an `ok` read). The pass is the heartbeat alone — and it is slice 4's (find.md says the same;
   kept here as the record). **DECISION 2.**
3. **`return_receive`'s short quantities** (the manifest: "quantities — return_line to qty received when short"): `inv_return_receive()` receives
   every line at its `qty`. The handler **lowers the line's `qty` to the quantity received** (the trigger allows a change while approved) and removes
   a line received at 0, before calling the verb; the log carries `short: [{return_line_id, sku, requested, received}]`. The return never receives
   more than was requested. **DECISION 3.**
4. **Closing a return records no payment.** `inv_return_close()` records the refund amount and restocking fee on the return; the money itself is
   slice 5's `refund_record` on the order (D10) — the return view links "Record the refund" with the amount prefilled. **DECISION 4.**
5. **The morning note's recipient when no Buyer is set** is the lowest-id active human super-admin of the mirror (design D12: "the first super-admin
   until set"); none → the note is not sent and the handler says so (422 "No Buyer is set — Settings › the Buyer"). **DECISION 5.**
6. **`morning_note_send` does not call the kernel's `message_send`.** `message_send` is the agent's own tool on the kernel's Actions MCP (os/buyer.md:
   the agent sends it to the Buyer's assistant itself); the application's action queues the `morning_note` notification (the bell, the email by the
   person's prefs) from the same words. **DECISION 6.**
7. **The reply of a dispatched agent is a notification of kind `agent_drafted`** (the kinds list has no `agent_reply`; what a watch's agent did is
   "drafted"), to the member whose watch fired, record `agent_dispatch` / the dispatch id. **DECISION 7.**
8. **Backoff without a column.** `agent_dispatches` has no `next_attempt_at`: a transport failure leaves the row `sent` with `attempts + 1` and the
   sentence in `detail`; the pass retries it when `created_at + 2^attempts minutes ≤ now()`; the third failure (`attempts = 3`) makes it `failed`;
   a run the kernel accepted (202) keeps `run_id` and is polled (`GET ?run=`) on the next passes, `failed` after 10 minutes. **DECISION 8.**
9. **The attachment helper is Phase 2's; the partials are this slice's.** `app/attachments.php` (`attachment_store()`, `attachment_delete()`,
   `attachment_path()`, `inv_can_see_attachment()` — sso-shell.md's signatures, the one contract) and `html/files.php` (the door) are **built in
   Phase 2** (slice 1's `image_add` uses them first); this slice adds `app/features/files/queries.php` (`find_attachments()`, `attachment_record()`,
   `thumb_path()` and the `/files/{id}/thumb` resizing), `app/features/notes/queries.php` and the partials `app/views/shared/{notes,attachments}.php`,
   and keeps the Phase 2 helper unchanged. **DECISION 9** (reconciled 2026-10-05).
10. **Awaiting disposition** (`return-list?awaiting_disposition=1`, the Buyer's seventh heading): a return in `received` not yet closed, or in `approved`
    with `scheduled_on` on or before today (the goods should be back). **DECISION 10.**

## Screens (375 px is the design for receiving — a warehouse hand with a phone; the rest usable at 375 px, designed at 1280 px)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `return-list` | `/returns/?status=&customer=&awaiting_disposition=` | the returns (`mcp_return_authorizations`) as a table (`return-list-table`): number, status chip, the order (→ `order-view`), the customer (→ `customer-view`), lines (count), method, scheduled date, dispositions (chips, from `mcp_return_lines`), refund (`return-row-{id}`); filters as chips (status, awaiting disposition) and a customer picker; **Request a return** (`return-list-add-btn` → `return-add`, disabled with a note until an order is chosen? — no: `return-add` asks for the order) |
| `return-add` | `/returns/new?order=` | the form (`return-form`): the order (`return-form-field-order`: a picker over `mcp_sales_orders` with shipped lines — `status` in confirmed · in_fulfilment · shipped · delivered · closed and some line with `qty_shipped − qty_returned > 0`; prefilled by `?order=`), then its returnable lines as rows (`return-form-line-{line_id}`: the SKU and product, shipped, already returned, **quantity** (`-qty`, 0–the returnable; 0 = not returned), **reason** (`-reason`, the return reasons of `mcp_reason_codes` where `'return' = ANY(applies_to)` and active), **disposition** (`-disposition`: restock · floor_model · dispose · return_to_supplier · donate; restock by default), location override (`-location`), condition note (`-condition_note`)), method (`-method`: pickup · drop_off), scheduled on (`-scheduled_on`), where it comes back to (`-location`, a sellable or `returns`-kind location; required when any line restocks or goes on the floor and has no override — PHP's sentence, before the verb's at receiving), notes (`-notes`); Save / Cancel in the pinned header; **at 375 px** each line is a card with the three controls stacked |
| `return-view` | `/returns/{return}` | one return (`return-view`): the header (number, status chip, the order and customer links, method and date, where it comes back to, requested by/when, approved/received/closed/denied by/when with the deny reason), the lines table (`return-line-{id}`: SKU, product, size, qty, received, reason, disposition chip, location, condition note; per line **Disposition** (`return-line-{id}-disposition-btn` → an inline form, `return_disposition_set`) while requested/approved), the refund and restocking fee recorded, **Record the refund** (→ `/orders/{sales_order}/payment?kind=refund&amount=`) when closed with a refund and the order's payments do not yet hold a refund of that amount, the buttons by status: **Approve** (`return-view-approve-btn`, a confirm), **Deny** (`return-view-deny-btn` → an inline reason form), **Receive** (`return-view-receive-btn` → `return-receive`), **Close** (`return-view-close-btn` → an inline form: refund amount, restocking fee), **Edit** (`return-view-edit-btn`, while requested/approved); notes and attachments (the shared partials — a return photo); the timeline (`return-view-timeline`: the activity rows with `entity_type = 'return_authorization'` and the order's `return.*` rows); `data-screen="return-view" data-entity="return" data-record-id="{id}"` |
| `return-edit` | `/returns/{return}/edit` | the form of `return-add` pre-filled, the order fixed; lines added, changed or removed; refused when the return is not requested or approved (the trigger's words as a 422) |
| `return-receive` | `/returns/{return}/receive` | **375 px first**: the approved return's lines as cards (`return-receive-line-{id}`): the SKU and product, requested qty, **received** (`-qty_received`, a stepper 0–qty, qty by default), the disposition (shown, with its location — a restock without a location shows the location picker here, required), condition (`-condition_note`, a short text); a barcode field at the top (`return-receive-scan`) that accepts a scanner's keystrokes and focuses the matching line's card (by the variant's barcode or SKU; no match → a flash "not on this return"); **Receive** (`return-receive-save-btn`, a confirm "This writes the stock movements") in the pinned header; GET renders, POST is the `return_receive` handler (the manifest names one file) |
| `proposal-list` | `/proposals/?kind=&status=&date=` | the Buyer agent's proposals (`mcp_buyer_proposals`) as cards (`proposal-card-{id}`): kind chip, title, the detail's facts (for a reorder: the variant, on hand, reorder point, the best supplier and cost when permitted; a match: the listing and the proposed variant with the evidence; a price: the two numbers; at risk: the order line), the subject (→ its record), the drafted record when one (→ `purchase-order-view` or `match-queue`), proposed by (the agent chip) and when, the note date; **Accept** (`proposal-card-{id}-accept-btn`) and **Dismiss** (`proposal-card-{id}-dismiss-btn` → an inline reason form) on a proposed one; tabs proposed · accepted · dismissed; filters by kind and date; the morning note's `markdown` for the day above the cards (`proposal-list-note`, collapsed by default) |
| `dispatch-list` | `/admin/dispatches?status=&agent=` | **claimed from the manifest's slice 9 section** (the retry action is this slice's and the pass that fills the list is this slice's — a slice ships whole): every dispatch (`mcp_agent_dispatches`) as a table (`dispatch-list-table`, `dispatch-row-{id}`): the agent (chip), the asker, kind, via, the watch's title (`detail`), status chip, attempts, the run id (→ the OS's AI Ops when `OS_LAUNCHER_URL` is set: its host with `app.` → `os.`, `/ai/runs/{run_id}`), when, answered when, the reply's excerpt; **Retry** (`dispatch-row-{id}-retry-btn`) on a failed one; filters by status and agent; `require_right('agents.settings')` |

## Returns
- **Request** (`return_request`, `orders.write`): the order (an id or number through the resolver; `mcp_sales_orders` — a quote or a cancelled order →
  the trigger's words "A return is against a confirmed order (it is quote)"); `lines` as JSON `[{line, qty, reason, disposition?, location?,
  condition_note?}]` (from the form: `lines[<line_id>][qty|reason|disposition|location|condition_note]`); a line with `qty` 0 is skipped; at least
  one line → else 422 "Choose at least one line to return."; `reason` is a reason code id or its `code` (resolved through `mcp_reason_codes`);
  `qty` above the returnable → the trigger's sentence ("Line 2 shipped 1 and 1 may still come back") as a 422 naming `lines[<id>][qty]`; `method`,
  `scheduled_on` (a date, may be past — a drop-off that happened), `location` (`mcp_locations`, active), `notes`. One transaction: the header (the
  trigger numbers it `RA-…`, sets `customer_id` and `requested_by`), then the lines. Log `return.request` (`sales_order_id`, `after`: `number`,
  `customer_id`, `status`, `method`, `scheduled_on`, `lines: [{sku, qty, reason_code, disposition}]`, `currency`); notify the Buyer (`return`,
  "Return RA-… requested on SO-…", dedupe `return:<id>:requested`); `HX-Trigger: returnChanged`; location `/returns/{id}`.
- **Update** (`return_update`, `orders.write`): the header's `method`, `scheduled_on`, `location`, `notes` and, from the form, the whole set of lines
  (added, changed, removed — `return_line_add`/`return_line_remove` are the same writes one at a time for an agent); refused when the status is not
  requested or approved (the trigger's sentence). Log `return.update` (`inv_diff()` of the header; `lines` after).
- **Line add / remove** (`return_line_add`, `return_line_remove`, `orders.write`): `return` (requested or approved), `line` (the sales order line — must be
  the return's order: the trigger), `qty`, `reason`, `disposition`, `location`, `condition_note`; `return_line` to change an existing one (UPDATE);
  a second row for the same order line → the UNIQUE → 422 "That line is already on this return — change its quantity." (the handler checks first).
  Log `return.line_add` / `return.line_remove` (`return_line_id`, `sku`, `qty`, `reason_code`, `disposition`).
- **Approve** (`return_authorize`, `returns.write`; `approve.php`): `SELECT * FROM inv_return_approve(:id, :by)` ("a requested return is approved",
  "has no lines"); log `return.approve` (`number`, `status`, `lines`); notify the requester (`return`, "Return RA-… approved — <method> on <date>",
  dedupe `return:<id>:approved`); **`other` for an agent**; confirm.
- **Deny** (`return_deny`, `returns.write`): `reason` required (1–500); `inv_return_deny(:id, :by, :reason)` (requested or approved); log `return.deny`
  (`deny_reason`); notify the requester (`return`, the reason in the body); confirm.
- **Receive** (`return_receive`, `returns.receive`; `receive.php` GET + POST): an approved return; `quantities` as JSON or the form's
  `lines[<return_line_id>][qty_received]`, `condition_notes` likewise; per line: received = requested when absent; 0 → the line is deleted (allowed
  while approved — the trigger); less → the line's `qty` lowered; `condition_note` written; a restock or floor-model line with no location (line nor
  header) → 422 naming the line ("Say where <sku> comes back to") — PHP's sentence before the verb's; then `SELECT * FROM inv_return_receive(:id,
  :by)` in the same transaction — the movements (`return`, `floor_model_in`) are the verb's, the supplier's note too; log `return.receive`
  (`sales_order_id`, `location_id` = the header's, `after`: `number`, `lines`, `restocked: [{sku, location_id, qty}]`, `floor: [...]`,
  `disposed`, `donated`, `to_supplier: [{sku, qty, purchase_order_id}]`, `short: [...]`); notify the requester and the order's salesperson when
  different (`return`, "Return RA-… received", dedupe `return:<id>:received`); the lines' `qty_returned` and the order lines' status follow (the
  verb); confirm ("This writes the stock movements"); location `/returns/{id}`.
- **Disposition** (`return_disposition_set`, `returns.write`): `return_line`, `disposition`, `location` (required for restock and floor_model when
  the header has none — else the header's stands); while requested or approved (the trigger); log `return.disposition` (`return_line_id`, `sku`,
  `before.disposition`, `after.disposition`, `location_id`).
- **Close** (`return_close`, `returns.write`): a received return; `refund_amount` (≥ 0, two decimals; ≤ the order's `amount_paid` — PHP's check:
  "The refund cannot exceed what was paid (…)"), `restocking_fee` (≥ 0); `inv_return_close(:id, :by, :refund, :fee)`; log `return.close`
  (`refund_amount`, `restocking_fee`, `currency`); notify the requester (`return`, "Return RA-… closed — refund <amount> to record"); the view then
  links the refund (DECISION 4); confirm.
- **Who sees**: every reader sees every return (the views; locations are not walls); the customer's name is on the row, never their contact details.
- **Reasons** come from `reason_codes` with `'return' = ANY (applies_to)`; a reason made inactive stays on old lines and leaves the picker (slice 9's
  `reason_code_save`).

## The Buyer agent's data
- **`morning_note(PDO, ?string $date = null, int $rowsPerHeading = 5): array`** (`app/features/buyer/queries.php`) — the composite of the tool surface's
  `morning_note`: `date` (today by default, the business's time zone); `headings`: `lines_at_risk` (`inv_lines_at_risk()`), `reorder`
  (`inv_reorder_candidates()`), `prices` (`inv_price_exceptions()`), `unmatched` (`inv_unmatched_listings(NULL)`), `sources` (`inv_source_health()`
  rows whose `health` ∉ {ok, manual, inactive}), `purchase_orders` → `awaiting_ack`, `overdue`, `untracked` (`inv_purchase_orders_open()` filtered
  by its three booleans), `returns` (`inv_returns_open()`); each heading `{count, rows (≤ rowsPerHeading), withheld: bool}` — **a function the caller's
  rights refuse (`insufficient_privilege`) makes that heading `{count: null, rows: [], withheld: true}` rather than failing the note** (Home shows
  what the person may see — DECISION 11); `proposals`: `mcp_buyer_proposals` rows with `note_date = date`; `drafted`: the distinct
  `{kind, drafted_record_type, drafted_record_id, status}` of them; `markdown`: `morning_note_markdown($note)` — the runbook's shape (the date line,
  the seven counts on one line, "Needs a hand today:" with up to five rows chosen in heading order — at risk first, then reorder drafts, prices,
  sources blocked, unmatched proposals —, then the drafts and proposals by number); cost named only when `sees_cost()`; **never a customer's address
  or phone, never a supplier's name beside a customer's**.
- **Propose** (`buyer_propose`, `purchasing.write`; `/proposals/save.php`): `kind` ∈ reorder · match · price · at_risk (the schema also admits
  source · po_overdue · return — accepted when sent; the manifest lists four); `subject` → `title` (1–200); the record → `subject_type`/`subject_id`:
  `variant` → `product_variant`, `listing_variant` → `listing_variant`, `line` → `sales_order_line`, `purchase_order` → `purchase_order` (exactly one
  required; resolved through its view — unseen = 404); `drafted_record` → `drafted_record_type` by kind (`reorder` → `purchase_order`, `match` →
  `match_proposal`; other kinds: none) and the id (checked in `mcp_purchase_orders` / `mcp_match_proposals`); `evidence` (JSON object) and
  `confidence` (0–1) into `detail` (`{evidence, confidence, ...the facts the agent sent}`); `proposed_by` = the caller; `note_date` = today.
  **One open proposal per (kind, subject)**: a `proposed` one → 422 "Already proposed on <date> (#id)"; a `dismissed` one in the last 30 days → 422
  "Dismissed on <date>: <reason>" (the Buyer's rule "not made again"); an `accepted` one does not block (a new day, a new reorder). Log
  `buyer.propose` (`proposal_id`, `kind`, `subject_type`, `subject_id`, `title`, `drafted_record_type`, `drafted_record_id`). No category.
  **DECISION 12.**
- **Accept** (`buyer_proposal_accept`, `purchasing.write`, **a person's act** — `require_human()`; an agent → 403 "A person accepts a proposal."):
  `status = accepted`, `decided_by/at`; the drafted record stays drafted (the person sends the PO from `purchase-order-view`, accepts the match in the
  match queue — the handler changes nothing else); log `buyer.accept`; `HX-Trigger: proposalChanged`. **DECISION 13.**
- **Dismiss** (`buyer_proposal_dismiss`, `purchasing.write`; a person or an agent): `reason` (≤ 500) kept in `detail.dismiss_reason`; `status =
  dismissed`, `decided_by/at`; log `buyer.dismiss` (`reason`).
- **The note** (`morning_note_send`; `/proposals/morning-note.php`): gate **`agents.settings`, or an agent holding `reports.read`** (the manifest's Who is `agents.settings` — a
  person; the agent path is this slice's DECISION, so Phase 4's gate admits the Buyer by `reports.read` with `current_member()['member_kind'] ===
  'agent'` — reconciled 2026-10-05); `body` (1–20,000 characters — the seven headings as the agent wrote them);
  `to_member` (a member id; default `inv_settings.buyer_member_id`, else DECISION 5's super-admin); `send_morning_note()` queues `notify(to,
  'morning_note', 'morning_note', <yyyymmdd as an integer>, 'Morning note — <date>', body, 'morning_note:<date>:<to>', false)` — the bell row and
  the email by the person's prefs; a second send the same day to the same person is a no-op (`inv_notify()` returns NULL on the dedupe) answered
  `{ok: true, queued: false, did: "already sent today"}`; log `buyer.note` (`note_date`, `counts` — the seven counts from `morning_note()` computed
  by the handler, not parsed from the body —, `sent_to`: the member id and "the Buyer" when it is the settings' Buyer, `queued: bool`). No category
  (the manifest). **The kernel's `message_send` is the agent's own** (DECISION 6).
- **The Buyer's grants** (`maludb-os.json` `agents[].tool_grants` → Actions MCP: `purchase_order_draft`, `match_propose`, `watch_set`, `note_add`,
  `buyer_propose`, `morning_note_send` — the last two added with Phase 1, reconciled 2026-10-05). `buyer_propose` on the expert's grants is Phase 4's
  call (an open item in `docs/phase1-consistency.md`); nothing here changes `maludb-os.json`.

## Notifications (`app/features/notify/{kinds,queue,send,present}.php`)
- **The kinds** (`kinds.php`: `NOTIFY_KINDS`, every value of `notifications.kind` with a label, a feather icon and a colour): `watch` "A watch fired"
  `feather-eye` `info` · `line_at_risk` "A sold line is at risk" `feather-alert-triangle` `danger` · `pull_failed` "A pull failed" `feather-download-cloud`
  `warning` · `pull_blocked` "A source is blocked" `feather-slash` `danger` · `po_ack` "A supplier acknowledged" `feather-check` `success` ·
  `po_decline` "A supplier declined a line" `feather-x-circle` `danger` · `po_tracking` "A supplier added tracking" `feather-truck` `info` · `return`
  "A return" `feather-rotate-ccw` `warning` · `morning_note` "The morning note" `feather-sunrise` `secondary` · `mention` "A mention" `feather-at-sign`
  `info` · `order` "An order" `feather-shopping-bag` `info` · `agent_drafted` "An agent drafted" `feather-cpu` `secondary` · `unmatched` "Unmatched
  listings" `feather-link` `secondary`.
- **The links** (`notification_record_url(array $n): ?string`, `present.php`): `watch` → `/watches/`; `source` → `/sources/{id}`; `source_pull` →
  `/sources/{source_id}/pulls`; `sales_order` → `/orders/{id}`; `sales_order_line` → `/orders/{sales_order_id}#line-{id}`; `purchase_order` →
  `/purchasing/{id}`; `return` → `/returns/{id}`; `buyer_proposal` → `/proposals/`; `morning_note` → `/proposals/?date=<the date>`; `listing_variant` →
  `/listings/{listing_id}?listing_variant={id}`; `agent_dispatch` → `/admin/dispatches` (an admin) else `/watches/`; unknown → null. Phase 2's bell page
  (`html/notifications.php`, `app/views/notifications/partials/row.php`) **is amended by this slice** to render the kind's icon and label and the link —
  the one change to Phase 2's files.
- **The queueing function** (`queue.php`): `notify(PDO, int $memberId, string $kind, ?string $recordType, ?int $recordId, string $title, ?string $body = null,
  ?string $dedupe = null, bool $forceText = false): ?int` = `SELECT inv_notify(…)` (the function decides the channels from the prefs, the dedupe, the
  email address at send time); an **eval run** (`$GLOBALS['__run_facts']['is_eval']`) queues nothing and returns null; `buyer_member(PDO): ?int`
  (the settings' Buyer, else DECISION 5's super-admin); `notify_buyer(PDO, …)` = `notify(buyer_member(), …)`, a no-op with a log line (`error_log`) when
  none. Every slice queues through `notify()` from this slice on; slices 3–6 built before it call `inv_notify()` directly (connectors.md §6.3 step 9) —
  equivalent, since `notify()` adds only the eval guard.
- **The sender** (`send.php`, the worker's `outbox` pass): `outbox_send_batch(PDO, int $limit, DateTimeImmutable $now): array` — queued rows with
  `send_after ≤ now`, oldest first, up to `$limit`, each its own try: **email** — the recipient's address = `to_email` else the mirror's current
  `members.email` (none → `skipped` `no_address`); `MALUMAIL_API_KEY` empty → `skipped` `unconfigured` (logged once a day: `notification.skip` with
  `code unconfigured`, the rest silent); `malumail_send(['to' => …, 'subject' => subject ?? the title, 'text' => body, 'html' => body_html ?? the
  rendered `app/views/mail/notice.php` (the title, the body as paragraphs, the record's link as an absolute URL from `INV_PUBLIC_BASE_URL`, the
  business's name — never another person's details), 'from' => MAIL_FROM, 'from_name' => MAIL_FROM_NAME])`: 200 with the address accepted → `sent`,
  `provider_ref` = `message_id`, `sent_at`; 200 with it rejected or 400 (suppressed) → `skipped` with the reason (never retried); 401/403 → `failed`
  at once (`bad_key`, logged `notification.fail`); 429/5xx or a transport error → `attempts + 1`, `send_after = now + 2^attempts minutes` (2, 4, 8,
  16), the fifth failure → `failed`; **text** — `kernel_send_text(member_id, body (≤ 480), kind . ':' . record_type . ':' . record_id)`: `sent` →
  `sent` with `provider_ref` = the kernel's notification id; `skipped` (`no_sender`, `not_held`, `no_verified_phone`, `opted_out`, `rate_limited`,
  `invalid`) → `skipped` with the code — **the email row stands**; `unconfigured` → `skipped` `unconfigured`; `retry` → the same backoff and cap.
  Each outcome logged `notification.send` | `notification.skip` | `notification.fail` (`kind`, `channel`, `record_type`, `record_id`, `code`,
  `attempts` — **never a body, never an address**); the pass answers `['sent', 'skipped', 'retried', 'failed']`.
- **Prefs and the bell** are Phase 2's (`my-settings`, `prefs_save`, `notifications`, `notification_read` — listed below as left to the shell).

## The worker's passes (`bin/worker.php`, one pass a minute from `inventory-worker.timer`; the advisory lock `hashtext('inventory_worker')`; `$GLOBALS['__public_door'] = 'cron'`; `--passes=` runs the named ones whether due or not; `--limit`; `INV_WORKER_NOW` outside production)
| Pass | Every | Does (`worker_pass_<name>(PDO, int $limit, DateTimeImmutable $now): array`) |
|---|---|---|
| `pulls` | minute | **slice 3's** — untouched |
| `snapshots_heartbeat` | — | **slice 4's** (find.md) — untouched here; left to slice 4 (reconciled 2026-10-05). The heartbeat marks no removals (DECISION 2) |
| `watches` | — | **slice 4's** (find.md: `inv_fire_watches()` and the `watch.fire` rows) — untouched here; left to slice 4 (reconciled 2026-10-05). The function notifies the member (texted when `text_me` — K6 by this slice's outbox) and inserts the `agent_dispatches` row this slice's `dispatches` pass turns into a chat turn |
| `outbox` | minute | `outbox_send_batch($pdo, $limit, $now)` |
| `dispatches` | minute | `dispatches_pass($pdo, $limit, $now)` (below) |
| `links_expire` | — | **slice 5's** (orders.md: `inv_expire_links()`, one function for both doors) — untouched here; left to slice 5 (reconciled 2026-10-05) |
| `key_usage_prune` | minute (cheap: `key_usage_time_idx`) | `SELECT inv_prune_key_usage()` → `['pruned' => n]` |
Left to slices 4 and 5 (reconciled 2026-10-05): `snapshots_heartbeat` and `watches` (find.md), `links_expire` (orders.md) — this slice fills `outbox`,
`dispatches` and `key_usage_prune` only.
A pass's error is caught by the driver (`errors[]` with the sentence, never a body); the report is one JSON line and one `worker.pass` row (the
counts; `at`; `seconds`); exit 1 when a pass erred. **Nothing a pass writes names a customer's address, a credential or a key.**

### The dispatch loop (`app/features/agents/dispatch.php`)
1. **Due**: `agent_dispatches` with `status = 'sent'` and (`run_id IS NULL` and (`attempts ≤ 1` or `created_at + 2^attempts minutes ≤ now`)) — up to
   `$limit`, oldest first; and the **running** ones (`status = 'sent' AND run_id IS NOT NULL`) to poll. Kinds in v1: `watch` (from `inv_fire_watches()`;
   `via chat`); `ask` and `duty_proposal` rows (none are made in v1) are dispatched the same way with their `detail` as the utterance.
2. **The utterance** (`dispatch_utterance(PDO, array $d): string`): for a watch — "<detail (the watch's title)>. Set by <the watch's member's name>.
   <N> sold lines wait on this variant: <order numbers>. Look at it (`availability`, `get_listing`) and draft what should be drafted — a purchase order,
   a match proposal, a watch — then say in two lines what you found. Propose; never send, place, price or delete." — the sold lines from
   `mcp_sales_order_lines` (status open · allocated · ordered, the watch's variant or the listing variant's match); the context carries
   `screen: 'watch'`, `entity: 'watch'`, `record_id: watch_id`.
3. **The call**: `ask_assistant($pdo, (int) $d['acting_member_id'], $utterance, $context, (string) $d['agent_member_id'])` — the kernel's chat endpoint
   as that agent (`?agent=<id>`), the watch's member as the acting person (`X-Acting-Member`), the application token (`app/api/kernel.php`; 60 s wait
   inside, 75 s cap). **At most five dispatches a pass** (the pass's own budget: 5 × 75 s < the 10-minute cadence the lock allows — DECISION 15).
4. **The answer**: `finished` with a reply → `status answered`, `run_id`, `request_id` (the kernel's, when sent), `reply_excerpt` = the first 200
   characters, `answered_at`; the member told: `notify(acting_member_id, 'agent_drafted', 'agent_dispatch', dispatch_id, "<agent>: <watch title>",
   reply (≤ 2,000 characters), 'dispatch:<id>:reply')` (DECISION 7); log `agent.reply` (`dispatch_id`, `agent_member_id`, `kind`, `via`, `run_id`,
   `request_id`, `status`, `reply_length`, `watch_id`, `listing_variant_id`, `cost`, `currency` when the kernel sent them — never the words);
   `approval` set (`pending_approval`) → `status awaiting_approval`, `detail` = "approval #<id> awaits a person" (the agent's own write paused — the
   kernel's, not ours); a `run_id` with `finished false` (202 not finished in 60 s) → `run_id` kept, status stays `sent` (running); **refused**
   (`error` with HTTP 400/403/404/409/422) → `status refused`, `detail` = the kernel's sentence, log `agent.fail` (`attempts`, `detail` ≤ 200);
   unreachable or 5xx → `attempts + 1`, `detail` = the sentence, stays `sent` (DECISION 8); the third such failure → `status failed`, log
   `agent.fail`, the member told once (`notify(…, 'agent_drafted', …, "<agent> could not answer: <watch title>", detail, 'dispatch:<id>:failed')`).
5. **Polling** (`poll_running_dispatches()`): `kernel_call('GET', '/api/v1/agents/chat.php?run=<run_id>')` for each running row; finished → step 4;
   not finished and `created_at + 10 minutes < now` → `failed` "the run did not finish"; unreachable → left for the next pass.
6. `dispatch_retry` (`/admin/dispatches/retry.php`, `agents.settings`): a `failed` or `refused` row → `status sent`, `run_id NULL`, `detail NULL`,
   `attempts` kept (DECISION 8's clock makes it due at once), `answered_at NULL`; log `agent.dispatch` (`dispatch_id`, `agent_member_id`, `kind`,
   `after.retry = true`); `HX-Trigger: dispatchChanged`.
7. Every call and outcome: log `agent.dispatch` when the call is made (`attempts`), then `agent.reply` or `agent.fail`. **K8** (the kernel waking an
   agent) replaces step 3 when it lands; nothing else changes.

## Notes and attachments (every record)
- **Notes** (`note_add`, `notes.write`; `note_delete`, own or `records.delete`): `record_type` ∈ product · variant (`product_variant` in the table) ·
  supplier · source · listing · customer · order (`sales_order`) · purchase_order · receipt (`goods_receipt`) · return (`return_authorization`) ·
  location — the manifest's words mapped to the table's (`NOTE_RECORD_TYPES` in `app/features/notes/queries.php`: word → [table type, view, id column,
  URL]); the record must be visible through its view (`require_visible()`; unseen = 404); `body` 1–5,000; log `note.add` (`note_id`, `record_type`,
  `record_id`, `length` — the body's words are the record's, in `notes`, not in the log) / `note.delete`; `HX-Trigger: noteChanged`; the location is the
  record's page `#notes`. The partial `app/views/shared/notes.php` (data: `recordType`, `recordId`, `notes` from `find_notes()`) renders the list
  (`note-{id}`, the author, when, the body as paragraphs, Delete for the author or the admin) and the add form (`notes-form`, `notes-form-field-body`,
  `notes-form-save-btn`) — included by every record view (`view('shared/notes.php', …)`).
- **Attachments** (`attachment_add`, `notes.write`; `attachment_delete`, own or `records.delete`): `record_type` ∈ the manifest's fourteen words mapped to
  `attachments.record_type`'s CHECK (`ATTACHMENT_RECORD_TYPES`: product · variant → `product_variant` · supplier · source · listing · customer · order →
  `sales_order` · purchase_order · receipt → `goods_receipt` · shipment · return · adjustment → `inventory_adjustment` · count → `inventory_count` ·
  transfer → `inventory_transfer` · location); multipart `file`; the size cap = the lesser of `inv_settings.max_attachment_bytes` and `ATTACHMENT_MAX_BYTES`
  (DECISION 16); MIME allow-list by sniffing (`finfo`): `image/png`, `image/jpeg`, `image/webp`, `image/gif`, `application/pdf`, `text/csv`,
  `text/plain`, `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` — else 422 "That kind of file is not accepted (images, PDF, CSV,
  XLSX, text)."; stored under `storage/attachments/<record_type>/<record_id>/<attachment_id>-<safe filename>` (Phase 2's `attachment_store()` returns the id; the row by `inv_can_see_attachment()` — reconciled 2026-10-05);
  `sha256`, `byte_size`, `mime_type`, `uploaded_by`; log `attachment.add` (`attachment_id`, `record_type`, `record_id`, `filename`, `byte_size`,
  `mime_type`) / `attachment.delete`; `HX-Trigger: attachmentChanged`. The partial `app/views/shared/attachments.php` (data: `recordType`, `recordId`,
  `attachments`, `may_add`) renders the list (`attachment-{id}`: a thumbnail for an image, the name, the size, who, when, Download, Delete) and the
  upload form (`attachments-form`, `attachments-form-field-file`, `attachments-form-save-btn`; on a phone the file input accepts `capture`).
- **The door** (`html/files.php`; `/files/{id}` and `/files/{id}/thumb` — already rewritten by the vhost and the dev router): `require_login()`; the
  attachment's record visible through its view (else 404 "File not found."); `X-Content-Type-Options: nosniff`, `Content-Disposition: inline` for an
  image or a PDF, `attachment` otherwise, the stored MIME type; the thumb (images only; GD, 320 px on the long side, cached under
  `storage/attachments/.thumbs/<id>.jpg`; a non-image → 404); logged `attachment.view`? — **no (DECISION 17)**: a file view is not an event; the
  record's `screen.view` suffices.
- `product_images` (slice 1) are attachments of `record_type = 'product'` made through Phase 2's `attachment_store()`; slice 1's `image_remove` calls
  `attachment_delete()` (DECISION 9, reconciled 2026-10-05).

## Files (exactly these)
- `html/returns/index.php` (`return-list`) · `form.php` (`return-add`, `return-edit`) · `view.php` (`return-view`) · `save.php` (`return_request`, `return_update`) · `lines/save.php` (`return_line_add`) · `lines/remove.php` (`return_line_remove`) · `approve.php` (`return_authorize`) · `deny.php` · `receive.php` (`return-receive` GET, `return_receive` POST) · `disposition.php` · `close.php`
- `html/proposals/index.php` (`proposal-list`) · `save.php` (`buyer_propose`) · `accept.php` · `dismiss.php` · `morning-note.php` (`morning_note_send`)
- `html/notes/add.php` · `html/notes/delete.php` · `html/files/upload.php` · `html/files/delete.php` · `html/files.php` (the door)
- `html/admin/dispatches.php` (`dispatch-list`) · `html/admin/dispatches/retry.php` (`dispatch_retry`)
- `html/notifications.php` + `app/views/notifications/partials/row.php` — Phase 2's, amended (the kind's icon and label, the record link)
- `app/features/returns/{queries,present,write,handler}.php` (`handler.php`: load the return or the line, the gate, `return_log()` — `sales_order_id` on every row)
- `app/features/buyer/{queries,present,write}.php` (`morning_note()`, `morning_note_markdown()`, the proposals) · `app/features/notify/{kinds,queue,send,present}.php` · `app/features/agents/{dispatch,queries,present}.php` · `app/features/notes/queries.php` · `app/features/files/queries.php`
- `app/features/worker/passes.php` (the three pass bodies this slice fills — `worker_pass_outbox()`, `worker_pass_dispatches()`, `worker_pass_key_usage_prune()` (reconciled 2026-10-05) — stay in `bin/worker.php` as the kit laid them out (one function per pass there), and `passes.php` holds what they call: `outbox_send_batch()` is `notify/send.php`'s, `dispatches_pass()` is `agents/dispatch.php`'s; the heartbeat's and the watches' helpers are slice 4's, in find.md)
- `app/views/returns/{index,form,view,receive,partials/row,partials/line-row,partials/line-form,partials/receive-line,partials/deny-form,partials/close-form,partials/timeline}.php` · `app/views/proposals/{index,partials/card,partials/dismiss-form,partials/note}.php` · `app/views/admin/{dispatches,partials/dispatch-row}.php` · `app/views/shared/{notes,attachments}.php` · `app/views/mail/notice.php`
- `bin/worker.php` — the three pass bodies `outbox`, `dispatches`, `key_usage_prune` filled; nothing else of the file (DECISION 1, reconciled 2026-10-05)
- `tests/fake_kernel.php` — the chat endpoint's states this slice needs (200 finished, 202 then finished on the GET, 403, `pending_approval`, 500) exist (`state.chat`, `state.chat_status`); `tests/fake_malumail.php` (Phase 0's); the K6 states
- `tests/phase3/slice8/{run.sh,lib.php,returns.php,receive.php,proposals.php,note.php,notify.php,outbox.php,dispatch.php,passes.php,notes_files.php,json.php,browser.mjs}`

## Query functions (signatures fixed; PDO first)
- `find_returns(PDO, array $f, int $page = 1): array` (`mcp_return_authorizations` + the lines' dispositions; `f`: `status[]`, `customer`, `order`, `awaiting_disposition`, `from`, `to`; 50 a page) · `find_return(PDO, int $id): ?array` (`mcp_return_authorizations`; null = unseen) · `return_lines(PDO, int $returnId): array` (`mcp_return_lines` ⨝ `mcp_sales_order_lines` for shipped/returned) · `returnable_lines(PDO, int $orderId): array` (`mcp_sales_order_lines` with `qty_shipped − qty_returned − pending > 0`; `pending` from `return_lines` of open returns) · `orders_with_returnable_lines(PDO, string $q = ''): array` (the picker) · `return_reasons(PDO): array` (`mcp_reason_codes`, `'return' = ANY(applies_to)`, active)
- `request_return(PDO, int $orderId, array $header, array $lines, int $by): int` · `update_return(PDO, int $id, array $header, ?array $lines, int $by): array` (`['changed']`) · `add_return_line(PDO, int $returnId, array $line): int` · `update_return_line(PDO, int $lineId, array $fields): void` · `remove_return_line(PDO, int $lineId): void` · `approve_return(PDO, int $id, int $by): array` · `deny_return(PDO, int $id, int $by, string $reason): array` · `receive_return(PDO, int $id, array $quantities, array $conditionNotes, int $by): array` (`['return', 'restocked', 'floor', 'disposed', 'donated', 'to_supplier', 'short']`) · `set_return_disposition(PDO, int $lineId, string $disposition, ?int $locationId): array` · `close_return(PDO, int $id, int $by, ?float $refund, ?float $fee): array` · `return_timeline(PDO, int $id, int $limit = 100): array` (`activity_log` by `entity_type = 'return_authorization'` and the order's `return.*` rows)
- `morning_note(PDO, ?string $date = null, int $rowsPerHeading = 5): array` · `morning_note_markdown(array $note): string` · `morning_note_counts(array $note): array` (the seven integers, null when withheld)
- `find_proposals(PDO, array $f, int $page = 1): array` (`mcp_buyer_proposals` with the subject's label and the drafted record's number; `f`: `kind[]`, `status`, `date`, `from`, `to`) · `find_proposal(PDO, int $id): ?array` · `make_proposal(PDO, array $fields, int $by): int` · `accept_proposal(PDO, int $id, int $by): array` · `dismiss_proposal(PDO, int $id, ?string $reason, int $by): array` · `recently_dismissed(PDO, int $days = 30): array` · `send_morning_note(PDO, string $body, ?int $toMember, int $by): array` (`['queued' => bool, 'to' => member id, 'notification_id' => ?int]`)
- `notify(PDO, int $memberId, string $kind, ?string $recordType, ?int $recordId, string $title, ?string $body = null, ?string $dedupe = null, bool $forceText = false): ?int` · `buyer_member(PDO): ?int` · `notify_buyer(PDO, string $kind, ?string $recordType, ?int $recordId, string $title, ?string $body = null, ?string $dedupe = null): ?int` · `notification_record_url(array $n): ?string` · `notification_kind(string $kind): array` (`['label', 'icon', 'color']`) · `outbox_send_batch(PDO, int $limit, DateTimeImmutable $now): array` · `render_notice_mail(array $row, ?string $url): string`
- `dispatches_pass(PDO, int $limit, DateTimeImmutable $now): array` (`['called', 'answered', 'running', 'awaiting_approval', 'refused', 'retried', 'failed', 'polled']`) · `dispatches_due(PDO, int $limit, DateTimeImmutable $now): array` · `dispatch_utterance(PDO, array $d): string` · `dispatch_agent(PDO, array $d, DateTimeImmutable $now): string` (the outcome word) · `poll_running_dispatches(PDO, DateTimeImmutable $now): array` · `record_dispatch_outcome(PDO, int $id, string $status, array $facts): void` · `find_dispatches(PDO, array $f, int $page = 1): array` (`mcp_agent_dispatches` ⨝ `mcp_members` for the asker) · `retry_dispatch(PDO, int $id, int $by): void`
- `heartbeat_due(DateTimeImmutable $now, bool $forced): bool` · `mark_heartbeat_ran(DateTimeImmutable $now): void` · `log_fired_watches(PDO, DateTimeImmutable $since): int`
- `find_notes(PDO, string $recordType, int $recordId): array` (`mcp_notes`) · `add_note(PDO, string $recordType, int $recordId, string $body, int $by): int` · `delete_note(PDO, int $noteId, int $by): array` (`['record_type', 'record_id']`) · `note_record(string $word): array` (`NOTE_RECORD_TYPES[$word]` or 422) · `record_url(string $recordType, int $recordId): string`
- `find_attachments(PDO, string $recordType, int $recordId): array` (`mcp_attachments`) · `thumb_path(array $a): ?string` · `attachment_record(string $word): array` (`ATTACHMENT_RECORD_TYPES[$word]` or 422) — and Phase 2's `app/attachments.php`, used as built (reconciled 2026-10-05, sso-shell.md's signatures): `attachment_store(PDO, string $recordType, int $recordId, array $file, int $by): int` (the id; the cap and the MIME list — DECISION 16) · `inv_can_see_attachment(PDO, int $id): ?array` (the `mcp_attachments` row or null; the base table's `storage_path` read only after the view admitted it) · `attachment_path(array $row): string` · `attachment_delete(PDO, int $id): void` (the row and the file; the handler reads the record's type and id through `inv_can_see_attachment()` first, for its location and its log)

## Handlers (every one: `inv_handler_begin()`; the gate; `inv_guard()`; `log_activity` with `sales_order_id` on a return's row; `emit_action_status()`; `inv_done()`; `HX-Trigger`)
- `returns/save.php` (`return_request` without `return`, `return_update` with it): `require_right('orders.write')`; the order visible (`mcp_sales_orders`, else 404); the lines parsed from JSON (`lines`) or the form; `return.request` / `return.update`; location `/returns/{id}`; `returnChanged`. The two actions share the file as the manifest says; `return` present = update.
- `returns/lines/save.php` (`return_line_add`; `return_line` present = change), `returns/lines/remove.php` (`return_line_remove`): `orders.write`; `return.line_add` / `return.line_remove`; location `/returns/{id}#return-line-{line}`.
- `returns/approve.php` (`return_authorize`): `require_right('returns.write')`; `return.approve`; **other**; confirm. `returns/deny.php` (`return_deny`): `returns.write`; `return.deny`; confirm.
- `returns/receive.php` (`return_receive`; GET = the screen `return-receive` with `require_right('returns.receive')` and `log_screen_view()`): POST `returns.receive`; `return.receive` with `location_id`; confirm; `returnChanged` + `stockChanged`.
- `returns/disposition.php` (`return_disposition_set`): `returns.write`; `return.disposition`. `returns/close.php` (`return_close`): `returns.write`; `return.close`; confirm.
- `proposals/save.php` (`buyer_propose`): `purchasing.write`; `buyer.propose`; location `/proposals/#proposal-card-{id}`; `proposalChanged`. `proposals/accept.php` (`buyer_proposal_accept`): `purchasing.write` + `require_human()`; `buyer.accept`. `proposals/dismiss.php` (`buyer_proposal_dismiss`): `purchasing.write`; `buyer.dismiss`.
- `proposals/morning-note.php` (`morning_note_send`): `agents.settings` or an agent with `reports.read` (else 403 "The morning note is the Buyer agent's or the admin's."); `buyer.note`; location `/proposals/?date=`; `notificationChanged`.
- `notes/add.php` (`note_add`): `notes.write` + the record visible; `note.add`; location `record_url() . '#notes'`; `noteChanged`. `notes/delete.php` (`note_delete`): own or `records.delete`; `note.delete`; confirm.
- `files/upload.php` (`attachment_add`): `notes.write` + visible; multipart; `attachment.add`; location `record_url() . '#attachments'`; `attachmentChanged`. `files/delete.php` (`attachment_delete`): own or `records.delete`; `attachment.delete`; the file unlinked after the row; confirm.
- `admin/dispatches/retry.php` (`dispatch_retry`): `agents.settings`; a failed or refused row (else 422 "Only a failed dispatch is retried."); `agent.dispatch` (`retry: true`); `dispatchChanged`.
- The screens: `return-list`, `return-view`: `require_right('inventory.read')`; `return-add`, `return-edit`: `orders.write`; `return-receive`: `returns.receive`; `proposal-list`: `require_any_right('reports.read|agents.settings')`; `dispatch-list`: `agents.settings`; `log_screen_view()` on each (a return's with `sales_order_id`).
- The worker writes with `actor_member_id` null, `source cron`, through `log_activity()` — never through a handler.

## Manifest rows claimed
Screens (7): `return-list`, `return-add`, `return-view`, `return-edit`, `return-receive`, `proposal-list`, `dispatch-list`
Actions (18): `return_request`, `return_update`, `return_line_add`, `return_line_remove`, `return_authorize`, `return_deny`, `return_receive`, `return_disposition_set`, `return_close`, `buyer_propose`, `buyer_proposal_accept`, `buyer_proposal_dismiss`, `morning_note_send`, `note_add`, `note_delete`, `attachment_add`, `attachment_delete`, `dispatch_retry`

| Kind | Rows | Section of the manifest |
|---|---|---|
| Screens (7) | `return-list`, `return-add`, `return-view`, `return-edit`, `return-receive`, `proposal-list` | Returns, the Buyer agent's data, notifications and the worker (slice 8) |
| | `dispatch-list` | Reports, home, admin and tokens (slice 9) — **claimed here** (the retry action and the pass are this slice's; reports-admin.md lists it as left to slice 8) |
| Actions (18) | `return_request`, `return_update`, `return_line_add`, `return_line_remove`, `return_authorize` (**other**), `return_deny`, `return_receive`, `return_disposition_set`, `return_close`, `buyer_propose`, `buyer_proposal_accept`, `buyer_proposal_dismiss`, `morning_note_send`, `note_add`, `note_delete`, `attachment_add`, `attachment_delete`, `dispatch_retry` | Returns, the Buyer agent's data, notifications and the worker (slice 8) |
| Left to the shell (Phase 2, `sso-shell.md`) | `notifications`, `my-settings` (screens); `notification_read`, `prefs_save` (actions) | Home, me and the shell — the bell page, the prefs form and their two handlers are the shell's in every sibling (Consultant Tracking, Spaces, Knowledge); this slice amends the bell's row partial (the kinds and links) and queues what the bell shows |
Every row of the manifest's slice 8 section is claimed. `PARTIAL_UPDATE_TARGETS` gains `/returns/save.php => ['return_authorizations', 'return',
'mcp_return_authorizations', 'return_id']` and `/returns/lines/save.php => ['return_lines', 'return_line', 'mcp_return_lines', 'return_line_id']`.

## Activity log events
`return.request|update|line_add|line_remove|approve|deny|receive|disposition|close` (every row with `sales_order_id`; `receive` with `location_id`;
payloads as the tool surface: `number`, `status`, `method`, `scheduled_on`, `lines`, `refund_amount`, `restocking_fee`, `currency`, `restocked`,
`deny_reason`, `short`), `buyer.propose|accept|dismiss|note`, `note.add|delete` (`note_id`, `record_type`, `record_id`, `length`),
`attachment.add|delete` (`attachment_id`, `record_type`, `record_id`, `filename`, `byte_size`, `mime_type`), `watch.fire` (cron), `notification.send|
skip|fail` (cron; `kind`, `channel`, `record_type`, `record_id`, `code`, `attempts` — never a body or an address), `agent.dispatch|reply|fail` (cron,
or the retry by a person), `worker.pass` (cron; the counts), `screen.view`. **A note's body, a reply's words, an utterance, an email body, a phone
number, an address and a file's content are in no payload.**

## Notifications this slice queues (and sends)
| Step | Who is told | Kind |
|---|---|---|
| a return is requested | the Buyer (`buyer_member()`) | `return` |
| approved · denied · received · closed | the requester (received: the order's salesperson too) | `return` |
| the morning note is sent | the Buyer named (`to_member`) | `morning_note` |
| a watch fired (the function) | the watch's member (texted when `text_me`) | `watch` |
| a dispatched agent replied, or failed for good | the watch's member | `agent_drafted` |
Every queued outbox row of every slice (email, text) is sent by this slice's `outbox` pass.

## The 375 px rule
Receiving is designed at 375 px: one card per line with a 44 px stepper, the scan field at the top keeping focus, the Receive button pinned in the header;
the return form's lines as cards under 992 px (the table from 992 px — one query, two shapes); the proposals as cards everywhere; the dispatch table as cards
at 375; the notes and attachments partials stack under the record; `scrollWidth` = viewport; no modal — the inline forms (deny, close, disposition, dismiss)
open in place under their button.

## Status vocabulary
Return status: requested `warning`, approved `info`, received `success`, closed `secondary`, denied `dark`. Disposition chips: restock `success`,
floor_model `info`, dispose `dark`, return_to_supplier `warning`, donate `secondary`. Proposal kinds: reorder `feather-shopping-cart`, match
`feather-link`, price `feather-tag`, at_risk `feather-alert-triangle`, source `feather-rss`, po_overdue `feather-clock`, return `feather-rotate-ccw`;
proposal status proposed `info`, accepted `success`, dismissed `secondary`. Dispatch status: sent `secondary` ("pending" with no run id, "running" with one),
answered `success`, awaiting_approval `warning`, refused `dark`, failed `danger`. Outbox: queued `secondary`, sent `success`, skipped `warning`, failed
`danger`. Ids: `return-list-table`, `return-row-{id}`, `return-list-add-btn`, `return-form`, `return-form-field-{name}`, `return-form-line-{line_id}`,
`return-form-line-{line_id}-{qty|reason|disposition|location|condition_note}`, `return-form-save-btn`, `return-form-cancel-btn`, `return-view`,
`return-view-{approve|deny|receive|close|edit}-btn`, `return-line-{id}`, `return-line-{id}-disposition-btn`, `return-view-timeline`, `return-receive-scan`,
`return-receive-line-{id}`, `return-receive-line-{id}-{qty_received|condition_note|location}`, `return-receive-save-btn`, `proposal-list-note`,
`proposal-card-{id}`, `proposal-card-{id}-{accept|dismiss}-btn`, `dispatch-list-table`, `dispatch-row-{id}`, `dispatch-row-{id}-retry-btn`,
`notes`, `note-{id}`, `notes-form`, `notes-form-field-body`, `notes-form-save-btn`, `attachments`, `attachment-{id}`, `attachments-form`,
`attachments-form-field-file`, `attachments-form-save-btn`, `nav-return-list`, `nav-proposal-list`, `nav-dispatch-list`.

## Vocabulary
A **return** (a return authorization, `RA-…`) is a customer's goods coming back against a shipped order; a **disposition** is what happens to a
returned unit (a returned mattress is never resold as new — restock is for sealed goods, the Buyer decides); **received** writes the stock; **closed**
records the money (the refund itself is recorded on the order). A **proposal** (a Buyer proposal) is what the Buyer agent found and drafted,
awaiting a person; it is not a **match proposal** (the matcher's, slice 3). The **morning note** is the seven headings; its **data** is the seven
functions; **sending** it is the bell and the outbox. A **dispatch** is a watch that named an agent, turned into one chat turn; a **pass** is one
run of one worker step; the **outbox** is every email and text waiting to go, per recipient and channel.

## Out of scope for this slice
The returns' refund payment (slice 5's `refund_record`); a vendor-return document (Extended); the watches' handlers and screen (slice 4 — this slice
fires them); the pulls pass (slice 3); the bell page, the prefs form and tokens (Phase 2); the MCP tools `morning_note`, `buyer_proposals`,
`agent_dispatches`, `agents_here` (Phase 4, over `morning_note()`, `mcp_buyer_proposals`, `mcp_agent_dispatches`); the Buyer's duty itself (the
kernel's cron) and its `message_send`; K8 wakes (owed); texts to customers (K28); Spaces' `#inventory` post (Spaces' grant); the admin's Agents page
(slice 9); the settings that govern these passes (slice 9's `settings_save`).

## Proof (`tests/phase3/slice8/run.sh`: the scratch database `inv_dev8`; the app on 8607; the fake kernel on 8602 (chat and K6 states); the fake MaluMail on 8606; `INV_WORKER_NOW` where a pass selects by the clock; members through `bin/dev_handoff.php`; curl with signed action and run tokens; headless Chromium at 375 × 740 and 1280 × 800; `bin/build_action_registry.php --check`; `bin/sync_approvals.php --check`; ≥ 200 checks)
The world (`lib.php` `returns_world()`): the fixture's members; the catalog with the Casper Original (six sizes) and the Zinus (six); the warehouse
(2 Queens on hand) and the showroom; a supplier source (Malouf) with offers and a reference; **SO-1** (Maria, Sam's) confirmed and shipped: line 1
a stock Queen (shipped 1), line 2 a drop-ship King (shipped by the supplier's tracking); **SO-2** delivered and paid in full (1,526.74), closed;
Nora the Buyer named in `inv_settings.buyer_member_id`; a watch of Sam's (`back_in_stock`, the Zinus Queen listing variant, `text_me`, agent = the
expert 45); a watch of Nora's (`price_below` 300, no agent); a feed key with 40-day-old usage buckets; an order link expired yesterday in SQL.
- [ ] **Request and lines (`returns.php`, ≥ 30)**: Sam requests a return on SO-1 line 1 (comfort, restock, the warehouse) → `RA-00001` requested,
  `customer_id` set by the trigger, `requested_by` Sam, logged with `sales_order_id`; Nora told (`return`, in-app and email queued by her prefs); a
  return on a quote → the trigger's sentence; qty 2 on a line that shipped 1 → "Line 1 shipped 1 and 1 may still come back" naming the field; an
  adjustment reason (`damaged` applies to both — accepted; `found` → "That reason is not a return reason"); the same order line twice on one return →
  422 "already on this return"; a second return on the same line while the first is open → the trigger's pending count refuses; `return_update`
  changes the method and date and keeps the lines; `return_line_add` on a closed return → the trigger's words; Vera (Viewer) → 403; the list filters by
  status and by `awaiting_disposition` (empty until received); the view shows the line, the disposition chip and Approve/Deny only.
- [ ] **Approve, deny, disposition (≥ 18)**: approve by Nora → approved, Sam told; approve twice → "a requested return is approved"; Sam approving →
  403 (`returns.write`); deny without a reason → 422; a second return denied with its reason shown; disposition set to `floor_model` on line 1 with the
  showroom; `dispose` needs no location; a disposition change after receipt → the trigger's words.
- [ ] **Receive (`receive.php`, ≥ 26)**: Wes (Warehouse) opens `/returns/1/receive` (375 px: the card, the stepper, the scan field); receiving with the
  default quantity → `received`, a `return` movement at the showroom? — no: the line's location is the showroom (floor model): a `return` transaction
  **and** a `floor_model_in` at the showroom, `qty_floor_model` + 1 there, the order line's `qty_returned` 1 and status `returned`; a restock line with no
  location → 422 "Say where CSP-ORIG-Q comes back to" before anything moves; a short receipt (requested 2, received 1 on a second return built in SQL)
  lowers the line and logs `short`; received at 0 → the line removed and the return still receives the rest; a `return_to_supplier` line on the King
  (the drop-ship) writes no movement, adds a `purchase_order_events` note and a line in the PO's `internal_notes`; `dispose` and `donate` move nothing;
  `return.receive` logged with `location_id`, `restocked`, `floor`, `to_supplier`; Sam and nobody else told (Sam is the salesperson and the requester —
  one notice, the dedupe); receiving an approved return by Sam → 403 (`returns.receive`); receiving twice → the verb's words; the scan field with the
  Queen's GTIN focuses its card (browser).
- [ ] **Close (≥ 10)**: close with refund 1,295.00 and fee 50.00 → closed, the amounts on the row, no `order_payments` row written (DECISION 4), the view's
  "Record the refund" link carrying the amount; a refund above `amount_paid` → 422; closing a received return by a Viewer → 403; the list's
  `awaiting_disposition` held the return between receipt and close and not after; `inv_returns_open()` no longer lists it; the order's timeline shows
  the return's rows.
- [ ] **The morning note's data (`note.php`, ≥ 22)**: with a reorder candidate (the Zinus Queen under its point with Malouf's in-stock offer), a line at
  risk (SO-1's King: the offer out of stock in SQL), a price exception (a reference undercutting by 15 %), an unmatched Malouf listing, a blocked source,
  a PO sent 5 days ago unacknowledged (`ack_days` 3), the open return: `morning_note()` as Nora → the seven headings with the right counts, `rows`
  capped at 5, `markdown` naming the five things in heading order with Malouf's cost (Nora sees cost), **no customer phone, no address**; as Sam →
  `reorder`, `prices`, `unmatched` withheld (`count null`, `withheld true`), `lines_at_risk` present, the markdown without cost; `morning_note('2026-10-04')`
  → the proposals of that date only; the counts match the seven functions called directly.
- [ ] **Proposals (`proposals.php`, ≥ 24)**: the Buyer agent (run token + relay, member 45? — the fixture's agent is the expert; the proof hires a
  `SMOKE Buyer` agent 47 in the mirror with the `buyer` role through the directory fixture) proposes a reorder with a drafted PO (drafted first by
  `purchase_order_draft` — slice 6's handler) → `proposed`, `drafted_record_type purchase_order`, logged `buyer.propose` with `source agent`; the same
  (kind, subject) again → 422 "Already proposed"; a match proposal with evidence and confidence; `at_risk` on SO-1 line 2; an unseen variant → 404;
  Nora accepts the reorder → accepted, `decided_by` Nora, the PO still a draft; the agent accepting → 403 "A person accepts"; Nora dismisses the match
  with a reason → dismissed, `detail.dismiss_reason`; proposing it again within 30 days → 422 "Dismissed on …"; after 31 days (the row's `decided_at`
  moved back in SQL) → allowed; the list's tabs and the kind filter; the `mcp_buyer_proposals` view strips `best_cost` for Sam; the morning note's
  `proposals` and `drafted` list them.
- [ ] **The note sent (≥ 12)**: the agent sends the note (`morning_note_send` with the markdown) → Nora's bell holds `morning_note` with the body, an email
  queued (`subject` "Morning note — …"), `buyer.note` logged with the seven counts and `sent_to`; sending again the same day → `queued false`, "already
  sent today", no second row; `to_member` Sam → Sam's bell; the admin may send; Sam (a person without `agents.settings`) → 403; with `buyer_member_id`
  NULL → the first super-admin (member 1) is the recipient; with no super-admin either (the mirror edited in SQL on a copy? — the proof sets member 1
  inactive, then restores) → 422 "No Buyer is set".
- [ ] **Notifications and the bell (`notify.php`, ≥ 16)**: `notify()` to Sam with his prefs default → one `notifications` row and one email outbox row
  (his kinds include `return`), no text row; with `text_enabled` and `watch` in `text_kinds` → a text row for a watch; a dedupe key queued twice →
  one row and the second call returns null; a kind outside his `kinds` → the bell row only; an eval run (`X-Eval-Run`, the fake's facts `is_eval`) →
  nothing queued; the bell page shows the kind's icon and label and each row links to its record (`/returns/1`, `/watches/`, `/proposals/?date=`);
  `notification_record_url()` for every record type of `NOTIFY_KINDS`' links; `buyer_member()` = Nora, then member 1, then null.
- [ ] **The outbox (`outbox.php`, ≥ 28)**: the pass sends Nora's email through the fake (the log holds `to`, `subject`, the record's absolute link, never
  another person's details), `provider_ref` = the message id, `sent_at`, `notification.send` logged with `kind` and `channel` and no body; a text through
  K6 (`ok`) → `sent` with `kernel:<id>`; each K6 refusal (`no_sender`, `not_held`, `no_verified_phone`, `opted_out`, `rate_limited`) → `skipped` with the
  code and the email row still sent; a 500 → `attempts 2`, `send_after` 4 minutes on, the pass skips it until due (`INV_WORKER_NOW` moved) and after
  five → `failed` with `notification.fail`; a `suppressed` address → `skipped` and never retried; a `flaky` (502) → retried; no `MALUMAIL_API_KEY` →
  `skipped unconfigured`, logged once a day; a member without an email → `skipped no_address`; `--limit=1` sends one; the pass's counts.
- [ ] **Watches and dispatches (`dispatch.php`, ≥ 26)**: the Zinus Queen listing goes `in_stock` in SQL → the `watches` pass fires Sam's watch once
  (`fired_at`, `fire_count 1`), a `watch` notice for Sam with a text row (`text_me`), an `agent_dispatches` row (`kind watch`, `via chat`, `acting`
  Sam, `watch_id`, `listing_variant_id`), `watch.fire` logged with `notified`, `texted`, `dispatched`; a second pass fires nothing; the listing out
  and in again → fires again (`fire_count 2`); the `dispatches` pass calls the fake kernel with `?agent=45`, `X-Acting-Member: 41`, an utterance naming
  the watch and "SO-1" as a waiting line, `conversation_id` empty; the fake's 200 reply → `answered`, `reply_excerpt` ≤ 200, `run_id`, Sam's
  `agent_drafted` notice with the reply, `agent.reply` logged with `reply_length` and no words; the fake's 202 (state `chat` with `finished false`)
  → `sent` with `run_id`, then the next pass GETs the run → answered; the fake's 403 → `refused` with its sentence and no retry; `pending_approval` →
  `awaiting_approval` with the approval id in `detail`; a 500 → `attempts 2`, not due for 4 minutes, then retried, the third failure → `failed`, Sam told
  once ("could not answer"); `dispatch_retry` by the admin → `sent` and answered on the next pass; by Nora → 403; the dispatch list filtered by status
  and agent, the run link's host `os.`; at most five dispatches a pass (six due → five called, one left).
- [ ] **The other pass (`passes.php`, ≥ 10)** — the heartbeat's and `links_expire`'s proofs are slice 4's and slice 5's (reconciled 2026-10-05):
  `key_usage_prune` → the 40-day-old day buckets gone, today's kept, the hour-old
  minute buckets gone; every pass idempotent on a second run (the same counts, nothing new); the lock refuses a second concurrent pass; a pass that
  throws (the fake MaluMail stopped → the outbox marks retries, no throw; a forced `SELECT 1/0` through a test hook? — no hook: the proof renames a
  function in the scratch database to make `watches` throw) lands in `errors[]`, the other passes ran, exit 1; `worker.pass` once per pass with the
  counts and no body.
- [ ] **Notes and files (`notes_files.php`, ≥ 22)**: a note on SO-1 by Sam → `notes` row, `note.add` with `length` and no body, the order view's
  `#notes` lists it; a note on `return` 1; on an unseen record (a feed key? — not a note record type: 422 "not a record that takes notes"); Vera (no
  `notes.write`) → 403; Sam deletes his own, Nora may not delete Sam's (403) but the admin may; a PNG attached to the return (a return photo) → stored
  under `storage/attachments/return/1/`, `sha256` right, the thumb served at `/files/{id}/thumb` (320 px), the file at `/files/{id}` inline with
  `nosniff`; a 30 MB file → 422 (the setting's cap); an `.exe` renamed `.pdf` → 422 (sniffed); a PDF → `attachment` disposition; an unauthenticated
  `/files/{id}` → 302 to the launcher; a Viewer sees the return's photo (every reader sees returns); delete unlinks the file and the thumb; every
  record type word of the manifest maps to a view (`ATTACHMENT_RECORD_TYPES` has fourteen entries, `NOTE_RECORD_TYPES` eleven).
- [ ] **JSON mode (`json.php`, ≥ 14)**: every handler under a signed action token answers `{ok, did, record_id, location, refresh}` with its facts
  (`return_request`: number, lines; `return_receive`: restocked, short; `buyer_propose`: proposal_id; `morning_note_send`: queued, to); `return_update`
  with `_partial=1` keeps the lines and the notes when only `scheduled_on` is sent; a 422 answers `{error: {code: invalid, fields}}`; the expert (run
  token + relay) may request a return (`orders.write` through `buyer`? — the expert holds `buyer`, which includes Sales' rights) and its `return_authorize`
  carries the `other` category in the registry (the hook pauses it on the MCP path — Phase 4); `bin/build_action_registry.php --check` reads the seven
  screens and eighteen actions built; `bin/sync_approvals.php --check` in sync (26).
- [ ] **375 × 740 and 1280 × 800 (`browser.mjs`, ≥ 20)**: the return form's line cards and the table; the receive screen on the phone with the stepper and
  the scan; the view's inline deny and close forms open in place; the proposal cards with Accept/Dismiss; the dispatch table as cards; the bell with
  icons and links; the notes and attachments partials on the return view, an upload through the form; every control ≥ 44 px, `scrollWidth` = viewport,
  no console errors; JavaScript off: the receive form posts and the return is received.

## Built and proven
Not built.

## Decisions recorded (the DECISIONs above, in one place)
1. The passes follow their data (reconciled 2026-10-05): `pulls` slice 3; `snapshots_heartbeat`, `watches` slice 4; `links_expire` slice 5; `outbox`, `dispatches`, `key_usage_prune` slice 8 — `bin/worker.php`'s header and `connectors.md` §6.3 step 10 say the same.
2. The heartbeat pass (slice 4's) marks no removals (the pull does — connectors.md §6.3 step 5).
3. A short receipt lowers the line's quantity (0 removes it) before `inv_return_receive()`; the log carries `short`.
4. Closing a return records no payment; the refund is slice 5's `refund_record`, linked with the amount.
5. No Buyer set → the lowest-id active human super-admin; none → 422.
6. `morning_note_send` queues the notification only; the kernel's `message_send` is the agent's own tool.
7. An agent's reply is a notification of kind `agent_drafted` to the watch's member.
8. Dispatch backoff from `created_at + 2^attempts` minutes; three attempts; a 202 run polled for 10 minutes.
9. Phase 2 builds `app/attachments.php` (`attachment_store`, `attachment_delete`, `attachment_path`, `inv_can_see_attachment`) and `html/files.php`; this slice adds the partials, `find_attachments()`, `attachment_record()` and `thumb_path()` and keeps the helper unchanged (reconciled 2026-10-05).
10. Awaiting disposition = received and not closed, or approved and past its scheduled date.
11. `morning_note()` answers a withheld heading as `{count: null, withheld: true}` rather than failing.
12. One open Buyer proposal per (kind, subject); a dismissal blocks the same for 30 days; an acceptance does not.
13. `buyer_proposal_accept` is a person's (`require_human()`); the drafted record stays drafted.
14. (Moved to slice 4 with the pass — find.md decides the heartbeat's rhythm; reconciled 2026-10-05.)
15. At most five dispatches a pass (the chat endpoint's 75-second cap × 5 under the one-minute timer and the lock).
16. The attachment cap is the lesser of the setting and `ATTACHMENT_MAX_BYTES`; the MIME list is sniffed.
17. Serving a file is not logged; the record's `screen.view` suffices.

## Open questions
(none)

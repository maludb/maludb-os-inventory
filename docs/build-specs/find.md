# Build spec: Find, availability and watches — slice 4

Built by the planning-class model beside the exemplar (slice 3, `connectors.md`), before the handoff. What exists at the end: **the one screen a
salesperson on the floor uses** — type a product, brand, SKU, GTIN or MPN and see, per variant, our retail and MAP, our own stock by location,
every supplier's offer ranked with its lead time (and its cost, behind the wall), the reference prices, when each was last seen; ask the
searchable sources live and watch the cards fill; "Sell this" into a quote; "Watch" for a change. The **availability partial** and the **ATP
partial** every later screen reuses (the variant page, the order form's picker). **Watches**: set, cleared, fired once per state change by the
worker, the member told (a text when they chose it — K6), an agent dispatched when named. **The bridge** the records server's `source_search`
tool reaches the live connectors through. The command bar's `find` and `availability` tools read the same two SQL functions this screen reads —
nothing of it is PHP.
Schema: `inv_find()`, `inv_availability()`, `inv_bundle_availability()`, `inv_atp()`, `inv_own_stock()`, `inv_best_lead_time()`, `inv_on_order()`,
`inv_size_name()`, `inv_source_health()` (db/014); `watches`, `inv_watch_targets()`, `inv_watch_state()`, `inv_snapshot_heartbeat()` (db/009);
`inv_notify()`, `inv_watch_title()`, `inv_fire_watches()`, `notifications`, `notification_outbox`, `agent_dispatches` (db/013); `inv_sees_cost()`,
`inv_settings` (db/005); the views `mcp_product_variants`, `mcp_products`, `mcp_product_types`, `mcp_brands`, `mcp_locations`, `mcp_sources`,
`mcp_listing_variants`, `mcp_listings`, `mcp_watches`, `mcp_notifications`, `mcp_agent_dispatches`, `mcp_members`, `mcp_settings` (db/015); slice 3's
`inv_source_search_live()` (`app/sources/pulls.php`), `inv_connectors()` and `inv_connector()` (`app/sources/registry.php`), `InvHttp`. Never modify them.
**The database is the referee**: what a Find answers, how offers rank (suppliers in stock first, then cost, then lead time; references by price),
what is stale, what a promise can rest on, whether cost is shown (`inv_sees_cost()`), a watch's one target and its threshold, when a watch fires
(once per state change, never per pull) — all in db/014, db/009 and db/013. PHP renders, gates, logs; it decides nothing twice.

## Screens (375 px is the design — Find is used on a tablet on the floor and a phone in the aisle; the watch list at 1280 is an admin table)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `find` | `/find?q=&size=&type=&firmness=&price_min=&price_max=&in_stock=&ships_within=` | **The box** (`find-q`, autofocus, `inputmode="search"`): a product, brand, SKU, GTIN or MPN; **the chips** (`find-chips`): size, type, firmness, price band, "in stock only", "ships within N days"; **the results** (`find-results`): cards per variant (`find-card-{variant_id}`); **Ask the sources now** (`find-ask-sources`, `orders.write`): one live card per searchable source (`find-live-{source_id}`), each filling on its own; **Sell this**, **Watch** on every card |
| `watch-list` | `/watches/?kind=&fired=&member=&cleared=` | My watches (`watches.all`: everyone's): the target, the kind, the threshold, whether the condition holds now, when it last fired and how often, text and agent marks; **Clear** on each; the Buyer filters by member |

Fragments (Pattern A endpoints, not screens — never in the registry; each `inventory.read` unless said): `GET /find/availability?variant=&compact=&qty=&field=`
(the availability partial), `GET /find/atp?variant=&qty=&by=` (the promise line), `GET /find/pick?q=&size=&limit=` (a variant pick list for a form's
line), `GET /find/sources?q=&size=` (the live fan-out shell, `orders.write`), `GET /find/source-search?source=&q=&size=` (one source asked live,
`orders.write`), `GET /watches/form?variant=|listing_variant=|product=&return_to=` (the inline watch form, `watches.own`). And `POST /internal/bridge.php`
(the records server's door — its own gate, below).

## The Find screen
- **The query**: `q` trimmed, at most 120 characters (longer → 422 "Keep it under 120 characters."); `size` a size word or key (`inv_find` normalises it);
  `type` a product type **key** → `product_type_id` through `mcp_product_types`; `firmness` one of the settings' `firmness_word` choices (plush, medium,
  firm, extra_firm) → `attributes: {"firmness_word": …}`; the price band `price_min`/`price_max` (decimals ≥ 0); `in_stock=1` → `in_stock_only: true`;
  `ships_within` ∈ {3, 7, 14} → `max_lead_days`. Unknown values are ignored, never refused (a chip is a link). The filters object is exactly `inv_find()`'s
  (`brand_id` is reached by typing the brand — there is no brand chip). Limit **50** (DECISION): the count line says "the first 50 — narrow it" at 50.
- **An empty Find** (no `q`, no chip) renders the box, the chips and the hint "Type a product, brand, SKU, GTIN or MPN — or pick a size" and runs no query
  (DECISION: the catalog's product list is the browse). Chips alone (no `q`) run `inv_find(NULL, size, filters, 50)`.
- **The form** is `hx-get="/find" hx-target="#page-content" hx-swap="innerHTML"` with **no `hx-push-url` attribute**; the controller answers
  `HX-Push-Url: /find?<the query as sent>` (DECISION — the rule "never `hx-push-url="true"`" with a query that changes). A chip is an `<a>` to the same
  URL with that parameter toggled (`find-chip-size-{key}`, `find-chip-type-{key}`, `find-chip-firmness-{word}`, `find-chip-price-{1..4}`, `find-chip-in-stock`,
  `find-chip-ships-{3|7|14}`), `hx-get` + `hx-target="#page-content"`, the active one `btn-primary`, the rest `btn-outline-secondary`. Price bands (DECISION):
  1 = under 500 (`price_max=499.99`), 2 = 500–999.99, 3 = 1,000–1,999.99, 4 = 2,000 and over (`price_min=2000`), in the settings' currency.
- **A card** (`find-card-{variant_id}`, the RecordCard look of `design-system`): the product name (a link to `/products/{product_id}`), brand · type ·
  the size chip; the SKU (a link to `/variants/{variant_id}`), the GTIN when set, the MPN when set; the **state chip** (`find-card-{id}-state`); the prices
  (`find-card-{id}-prices`: "Retail 1,599.00 · MAP 1,499.00"; cost beside them **only when `sees_cost()`** — there is never a "withheld" word on a
  screen, the column simply is not there — DECISION); **the availability region** (`find-card-{id}-availability`) rendered first from `inv_find()`'s
  compact facts ("3 available · best lead 0 days" / "from Malouf in 5 days · in stock" / "back order" / "unavailable") and replaced by the full
  partial when the card scrolls into view: `hx-get="/find/availability?variant={id}" hx-trigger="revealed, offerChanged from:body" hx-swap="innerHTML"`
  (DECISION: one `inv_find()` for the list, one `inv_availability()` per card as it is revealed — a phone shows three or four at a time);
  **the actions** (`find-card-{id}-actions`, full-width buttons ≥ 44 px): **Sell this** (`find-card-{id}-sell`, for `orders.write`) — a plain link to
  `/orders/new?variant={id}&qty=1&fulfilment=<kind>&location=<id>|listing_variant=<id>` with the recommended fulfilment (`recommended_fulfilment()`,
  below); **Watch** (`find-card-{id}-watch`, for `watches.own`) — `hx-get="/watches/form?variant={id}&return_to=<this Find URL>" hx-target="#find-card-{id}-watch-form"
  hx-swap="innerHTML"` opening the inline form under the card (no modal).
- **Recommended fulfilment** (`recommended_fulfilment(array $availability, int $qty = 1): array` in `present.php`, DECISION): state `in_stock` → `stock` at the
  sellable location with the most `available` (≥ qty; else the first sellable with any); `from_supplier` or `back_order` → `dropship` with `offers[0].listing_variant_id`
  (the rank-1 offer not removed); `unavailable` → `backorder` with no location (the order form's picker asks for one). A bundle → `stock` at the location
  of its first component's best stock, else `backorder` (a bundle is sold as its components — the order form expands it, slice 5).
- **"as of"** is shown relative ("12 min ago", "3 days ago" — `format_ts()`'s date beyond 7 days) with the **stale** chip (`warning`) when the function says
  so; a **removed** offer is not on Find (it is on the variant's page and in `offers_for_variant`).
- **Ask the sources now** (`find-ask-sources`): shown when `q` is set, the member holds `orders.write` and `searchable_sources()` is not empty;
  `hx-get="/find/sources?q=&size=" hx-target="#find-live" hx-swap="innerHTML"`. `/find/sources` answers `find-live` with one placeholder card per searchable
  source (`find-live-{source_id}`: the name, the connector badge, a spinner `htmx-indicator`), each `hx-get="/find/source-search?source={id}&q=&size="
  hx-trigger="load" hx-swap="outerHTML"` — **the fan-out is the browser's** (connectors.md §6.6): the sources are asked in parallel by separate requests, each
  one source. **Searchable** = `mcp_sources` rows that are active, whose connector's `capabilities()['has_search']` is true (`shopify`, `woocommerce` in v1),
  not paused, whose `inv_source_health()` health is not `blocked` and whose `backoff_until` is null or past (DECISION — the same rule `inv_source_search_live()`
  applies, read once to draw the cards).
- **One source asked** (`/find/source-search`, `orders.write`; `source` required and searchable else 404 "Source not found."; `q` 2–120): `set_time_limit(30)`;
  `inv_source_search_live($pdo, $sourceId, $q, 10, current_member_id())` (10 a screen — connectors.md DECISION); the answer replaces the placeholder:
  `find-live-{source_id}` with a status chip — `ok` → "N listings · M matched to ours"; `no live search` / `paused` / `backing off` / `a pull is running` →
  the sentence and "from the last pull:" over the rows the function answered; `blocked` → "blocked — the source is backing off" (`danger`); then the rows
  (`find-live-{source_id}-row-{listing_variant_id}`): title · size · price · (cost when `sees_cost()` and the source is a supplier) · the availability chip ·
  lead time · **matched** → the SKU as a link to `/variants/{variant_id}` / **unmatched** → "not in our catalog" and, for `listings.match`, a link to
  `/matching/?source={id}&q=<the listing's title>`. The rows come from `mcp_listing_variants` by the ids the function wrote (the view walls cost). The
  answer carries `HX-Trigger: offerChanged`, so every revealed card's availability partial reloads as each source answers (DECISION — no counting of
  answers on the client). Logged by the function: `source.search` (`source_id`; `after: {pull_id, query ≤ 120, size, listings_seen, listings_new, ms}`).
- **The command bar** on this screen is Phase 2's shell; "can we sell a Queen ProAdapt" is the expert's `find` + `availability` tools over the same two
  functions (Phase 4). `data-screen="find" data-entity="variant" data-record-id=""` on `#page-content`; a card carries `data-variant-id`.
- `screen.view` is logged with `after: {q (≤ 120), size, type, firmness, price_min, price_max, in_stock, ships_within, results}`.

## The availability partial (`/find/availability` — reused by the variant page (slice 1) and the order form (slice 5))
- `variant` required; `require_visible($pdo, 'mcp_product_variants', 'variant_id', $id, 'Variant')`; `variant_availability()` = `inv_availability($id)` decoded.
- **Full shape** (no `compact`): `availability-{id}` holding **`availability-{id}-state`** (one line: "In stock — ships today" `success` / "From Malouf in 5 days"
  `info` / "Back order" `warning` / "Unavailable" `secondary`; "On order: N" when `on_order > 0`); **`availability-{id}-own`** (a `.table-responsive` table:
  Location · On hand · Allocated · Floor · Available; a non-sellable location muted with "not sellable"; a totals row; none → "Nothing on the shelf");
  **`availability-{id}-offers`** (Source · Supplier · Price · Cost (the column exists only for `sees_cost()`) · Availability chip · Qty · Lead time · Ships ·
  As of (+ the stale chip) · a `feather-external-link` to `url`; in rank order; removed ones last, struck through, "removed"); **`availability-{id}-references`**
  (Source · Price · Compare at · Availability · As of · link). **A bundle** (`variant.kind = 'bundle'` — the function answers the bundle shape itself):
  `availability-{id}-components` (Component · Per set · Available · Sets · Best lead) and the state line "N sets from stock".
- **Compact shape** (`compact=1&qty=N&field=<prefix>`, the order form's **availability picker per line**, DECISION): a radio group named
  `<prefix>[fulfilment]` (ids `<prefix-slug>-choice-…`, the slug = the prefix with `[`/`]` → `-`): one **`stock:{location_id}`** per sellable location with
  `available ≥ qty` ("Warehouse — 3 available"); one **`pickup:{location_id}`** per the same locations ("Pickup at Showroom"); one **`dropship:{listing_variant_id}`**
  per supplier offer not removed with availability in (`in_stock`, `limited`, `pre_order`, `back_order`) ("Malouf · in stock · 5 days" + " · cost 312.00" for
  `sees_cost()`; the stale chip); and **`backorder`** always last, with a select `<prefix>[backorder_location]` of the sellable locations (DECISION: a
  backorder names the location it will ship from — the shipment needs one — defaulting to the form's store, else the first sellable warehouse). The
  checked radio is `recommended_fulfilment()`'s. Under the group, **the promise line** (`atp-{id}`, `inv_atp(variant, qty)`'s `reason`, `danger` when
  `can_promise` is false). Slice 5's handler reads `kind:id`. A bundle in compact shape answers "Sold as its components — add them as lines" and no radios.

## The ATP partial (`/find/atp?variant=&qty=&by=`)
`variant_atp()` = `inv_atp(variant, qty, by)` (`qty` 1–999, default 1; `by` a date or empty): `atp-{variant_id}` — the `reason` as one sentence, the
"from" (a location, or a supplier with the cost for `sees_cost()`), "by <date>"; `can_promise` false → a `warning` box. The order form reloads it when a
line's qty or the promised date changes (`hx-get` with `hx-trigger="change"`).

## The pick list (`/find/pick?q=&size=&limit=`)
`find_variants($q, $size, [], min(limit, 20))` → `pick-results`: one button per variant (`pick-{variant_id}`, `data-variant-id`, `data-sku`,
`data-label`, `data-retail`, `data-state`): "CSP-ORIG-Q — Casper Original, Queen · 1,295.00 · in stock"; `q` under 2 characters → the empty list with
"Type a SKU, GTIN or a name". The order form (slice 5) and the purchase-order form (slice 6) type into a line's search box and pick from this list.

## Watches
- **The inline form** (`/watches/form`, `watches.own`; one of `variant`, `listing_variant`, `product` required and visible through its view): `watch-form`
  (ids `watch-form-field-{name}`): **kind** (a select: `back_in_stock`, `price_below`, `cost_below` — offered only for `sees_cost()` —, `map_breach`,
  `lead_time_over`, `removed`; the default `back_in_stock` when the target's state is not `in_stock`, else `price_below`), **threshold** (a decimal for
  `price_below`/`cost_below`, prefilled with the best offer's price else the retail; whole days 0–365 for `lead_time_over`; hidden for the others), **text_me**
  (a checkbox: "text me when it fires — if your phone is verified in the OS"), **agent** (a select of agents from `agents_for_pick()` — shown only for
  `watches.all`; DECISION: naming an agent costs a chat turn when it fires, so only someone who may direct agents does it), **note** (≤ 500), the hidden
  target and `return_to`; Save (`watch-form-save`, `hx-post="/watches/save.php"`). The variant page (slice 1) includes the same partial on its watches tab
  once this slice is built; the watch list has no add form — a watch is set from Find or a record (DECISION).
- **`watch_set`** (`html/watches/save.php`): `inv_handler_begin()`; `require_right('watches.own')`; fields: `kind` (required, one of the six), exactly one of
  `variant` / `listing_variant` / `product` (ids — a SKU or GTIN arrives resolved by the registry; a target the caller cannot see → 404 "Variant not found."
  through its `mcp_*` view), `threshold` (required for `price_below`, `cost_below`, `lead_time_over`: a decimal ≥ 0, days 0–365 whole for `lead_time_over`;
  **refused for the other kinds** with "A threshold means nothing for back_in_stock." — DECISION, so an agent learns the vocabulary), `agent` (a member of
  kind `agent`, active and admitted; needs `watches.all` → 403 in words otherwise), `text_me` (yes/no), `note` (≤ 500). `cost_below` needs `sees_cost()`
  (403 "You may not see cost or margin."). **Dedupe** (DECISION, the schema has no unique index): an active watch of mine with the same kind, target and
  threshold → 422 "You already watch that.|{id}" (the guard's `sentence|id` shape: the reply names the existing watch). INSERT `watches` with `member_id` = me
  (an agent under a run token sets its own), then `UPDATE watches SET last_state = inv_watch_state(id)` (DECISION: evaluated at once, so the list shows
  "now" immediately and a condition already true does not fire on the next pass — the same rule `inv_fire_watches()` applies on first sight). Log
  `watch.set` (`watch_id, kind, variant_id, listing_variant_id, product_id, threshold, text_me, agent_member_id`; `source_id` of a listing variant's source).
  `inv_done("Watching " . title, id, inv_land(return_path('/watches/'), 'watched', 'watch-row-' . id), 'watchChanged', ['title' => …, 'current' => last_state])`
  — the location ends in the id whether it lands on the list or back on Find.
- **`watch_clear`** (`html/watches/clear.php`): `watch` through `mcp_watches` (own, or anyone's for `watches.all` — the view decides; not found → 404);
  already cleared → 422 "That watch is already cleared."; `UPDATE watches SET active = false` (DECISION: cleared, never deleted — its notifications keep their
  record; the list shows cleared ones on request); log `watch.clear` (`watch_id, kind`); location `/watches/#watch-row-{id}`; `HX-Trigger: watchChanged`.
- **The list** (`/watches/`): `watch-list-table` rows `watch-row-{id}`: Target (a link: the variant's SKU and name / the listing variant's title and source /
  the product's name), Kind chip, Threshold, **Now** (`success` dot when `last_state`, `secondary` when false, "—" when null), Last fired (relative) × `fire_count`,
  Text (`feather-message-square` when `text_me`), Agent (the name as an `agent` chip), Set by (`watches.all` only), **Clear** (`watch-row-{id}-clear`,
  `hx-post="/watches/clear.php"`, `hx-confirm`). Filters `watch-filter-kind`, `watch-filter-fired` (any / fired / never), `watch-filter-member` (`watches.all`),
  `watch-filter-cleared` ("show cleared"). Active first, then `fired_at` desc, then newest; 100 a page, server-rendered pagination. Empty: "Nothing watched.
  Set one from Find or a variant's page." Reads `mcp_watches` joined to `mcp_product_variants`, `mcp_listing_variants`, `mcp_products`, `mcp_members`.
- **Firing — the worker's passes** (`bin/worker.php`, this slice fills two stubs): `worker_pass_watches()` reads `t0 = clock_timestamp()`, calls
  `inv_fire_watches()` (the member told through `inv_notify()` — in-app, email per their prefs, a **text row with `p_force_text`** when `text_me` —, the named
  agent dispatched as `agent_dispatches` kind `watch`), then for each `watches WHERE fired_at >= t0` logs `watch.fire` (`watch_id, kind, target` (the SKU or
  title), `state: true`, `notified` (the notification id), `texted` (a text outbox row exists for this fire's dedupe key), `dispatched` (the dispatch id or null);
  `source_id` for a listing-variant watch; source `cron`, actor null) and answers `['fired' => n]`. `worker_pass_snapshots_heartbeat()` calls
  `inv_snapshot_heartbeat()` and answers `['snapshots' => n]` (removals are the pull's — connectors.md §6.3 step 7). The connectors spec (§6.3 step 10) names
  both passes as this slice's; the worker runs `pulls` then `snapshots_heartbeat` then `watches` each minute. **The text is K6's**: the queued `text` row is
  sent by slice 8's `outbox` pass through `kernel_send_text()` (the kernel decides the phone, the grant, the opt-out and the 30 a day); this slice queues it and
  proves the row. A watch that names an agent is answered by slice 8's `dispatches` pass (one chat turn as that agent).

## The bridge (`html/internal/bridge.php` — the tool surface's "The bridge", DECISION 3 there)
- `POST` only (405 otherwise). Refused **401** unless: `REMOTE_ADDR` is `127.0.0.1` or `::1`; the header `X-INV-Bridge: {unix_ts}.{hex}` parses; `|now − ts| ≤ 30`;
  `hex === hash_hmac('sha256', 'inv-bridge:' . ts . '.' . hash('sha256', $rawBody), env('ACTIONS_RELAY_KEY'))` compared with `hash_equals()`. The body is JSON
  `{op, caller: {kind, member_id}, door: "mcp"|"agent", run_id, request_id, is_eval, args: {q, size, sources, limit}}`; `op !== 'source_search'` → **404**
  `{"error": {"code": "no_such_op"}}`; `caller.kind !== 'member'` or no active, admitted mirror row (`members.status = 'active' AND capability IS NOT NULL`) →
  **403** `unknown_member`. Then `$_SESSION['member_id'] = member` (no session is started — the array lives for this request), `db_apply_context($pdo)` (sets
  `app.member_id`), `$GLOBALS['__activity_source'] = door === 'agent' ? 'agent' : 'mcp'` (**one additive line in `app/activity.php`**: `default_activity_source()`
  answers `$GLOBALS['__activity_source']` first when set — DECISION, so the function's own `source.search` rows say who asked), and the same gate as the Find
  button: `has_right('orders.write')` else **403** `insufficient_right`. `q` 2–120, `size` optional, `sources` ≤ 5 ids (each must be searchable — an unknown or
  unsearchable id answers `status: skipped` for it, never a refusal), default every searchable source, `limit` 1–20 (default 20).
- **Runs** the sources **in sequence**: for each, `inv_source_search_live($pdo, $sid, $q, $limit, $member)` with **25 s a source** and **60 s the whole call**
  (`set_time_limit(70)`; a source reached after the budget answers `status: timeout`); the answer per source `{source_id, source, status (ok | no_search |
  timeout | blocked | failed | skipped), pull_id, listings_seen, listings_new, listings_changed, ms, error (≤ 200)}`; the rows = `mcp_listing_variants` rows for
  every `listing_ids` the function wrote (as the caller — the wall stands) + `matched` (`variant_id IS NOT NULL`), `sku`, `product_name`. The envelope:
  `{"ok": true, "query", "size", "asked": [...], "rows": [...], "eval": false}`; a refusal `{"ok": false, "error": {"code", "message"}}` with the status above.
- **Under `is_eval`** (DECISION): nothing persists — the bridge asks `inv_connector($row['connector'])->search($source, $q, $limit)` directly through
  `app/sources/` (the `$source` array from `inv_source_for_connector()`), writes no pull row, no listing, no snapshot, no match; answers the normalized listings'
  variants as rows `{title, size_name, sku, barcode, price, availability, lead_time_days, matched: false}` with `"eval": true`; logs one `source.search` per
  source with `after.eval = true` and the counts (the kernel's "nothing changed" check holds — a log row is not a change the eval runner counts).
- Not on the public allow-list: this slice adds `RewriteRule ^/internal/ - [R=404,L]` to the **public** vhost in `deploy/apache-inventory.conf` (the internal
  port serves it; the proof's `php -S` plays the internal port). No new env key (`ACTIONS_RELAY_KEY` is the contract's). The Python side is Phase 4's.

## Files (exactly these)
- `html/find/index.php` (`find`) · `html/find/availability.php` · `html/find/atp.php` · `html/find/pick.php` · `html/find/sources.php` · `html/find/source-search.php`
- `html/watches/index.php` (`watch-list`) · `html/watches/form.php` (the inline form fragment) · `html/watches/save.php` · `html/watches/clear.php`
- `html/internal/bridge.php`
- `app/features/find/queries.php` · `present.php` · `bridge.php` (`bridge_verify()`, `bridge_run_source_search()`)
- `app/features/watches/queries.php` · `present.php` · `write.php` · `handler.php` (`watch_from_request()`, `watch_log()` — `source_id` on a listing-variant watch, `watch_path()`)
- `app/views/find/index.php` · `partials/chips.php` · `partials/find-card.php` · `partials/availability.php` · `partials/availability-compact.php` · `partials/atp.php` ·
  `partials/pick.php` · `partials/live-sources.php` · `partials/live-card.php`
- `app/views/watches/index.php` · `partials/watch-form.php` · `partials/watch-row.php`
- `bin/worker.php` (`worker_pass_watches()`, `worker_pass_snapshots_heartbeat()` filled in) · `app/activity.php` (the one line in `default_activity_source()`) ·
  `deploy/apache-inventory.conf` (the `/internal/` 404 on the public vhost)

## Query functions (signatures fixed)
- `find_variants(PDO, ?string $q, ?string $size, array $filters, int $limit = 50): array` (`inv_find()`; `best_offer` decoded) · `find_filters(array $get, array $productTypes, array $firmness): array` (present.php — the chip values to `inv_find()`'s filters object) · `find_product_types(PDO): array` (`mcp_product_types`, active, in order) · `find_sizes(PDO): array` (`mcp_settings.sizes`) · `firmness_choices(PDO): array` (`mcp_settings.attribute_keys` → `firmness_word`)
- `variant_availability(PDO, int $variantId): ?array` (`inv_availability()`, decoded; null when the variant is not visible) · `variant_atp(PDO, int $variantId, int $qty = 1, ?string $by = null): array` · `recommended_fulfilment(array $availability, int $qty = 1): array` (present.php: `['kind', 'location_id', 'listing_variant_id']`) · `picker_choices(array $availability, int $qty, array $locations): array` (present.php — the compact shape's radios) · `sellable_locations(PDO): array` (`mcp_locations` active and sellable)
- `searchable_sources(PDO): array` (`mcp_sources` ⨝ `inv_source_health()` ⨝ `inv_connectors()` capabilities) · `live_search_source(PDO, int $sourceId, string $q, int $limit, ?int $by): array` (`inv_source_search_live()` + the `mcp_listing_variants` rows by the ids written; the status word)
- `find_watches(PDO, array $filters, int $limit = 100, int $offset = 0): array` · `count_watches(PDO, array $filters): int` · `find_watch(PDO, int $id): ?array` (`mcp_watches` + the target's label and link) · `watch_target(PDO, string $kind, int $id): ?array` (the target through its view: label, state) · `watch_exists(PDO, int $memberId, string $kind, array $target, ?string $threshold): ?int` · `save_watch(PDO, array $fields, int $memberId): int` (INSERT, then `last_state`) · `clear_watch(PDO, int $id): void` · `agents_for_pick(PDO): array` (`members_for_pick()` kept to `member_kind = 'agent'`)
- `bridge_verify(string $rawBody, string $header, string $remoteAddr, int $now): ?string` (the refusal code or null) · `bridge_caller(PDO, array $body): ?int` (the admitted member id or null) · `bridge_run_source_search(PDO, array $args, int $memberId, bool $isEval): array` (the envelope)
- `fire_watches(PDO): array` (`t0`, `inv_fire_watches()`, the fired rows with their notification, text row and dispatch ids — the worker's pass and the proof read it)

## Handlers (every one: `inv_handler_begin()`; the right; the record through its view; `inv_guard()`; `log_activity` with the audit key; `inv_done()`; `HX-Trigger: watchChanged`)
- `watches/save.php` (`watch_set`) and `watches/clear.php` (`watch_clear`) as above. The fragments are GET controllers (`require_login()`, the right, `log_screen_view()` never —
  a fragment is not a screen). `internal/bridge.php` is neither (its own gate above). `PARTIAL_UPDATE_TARGETS` gains nothing (a watch is created, never updated).

## Manifest rows claimed
Screens (2): `find`, `watch-list`
Actions (2): `watch_set`, `watch_clear`

| Kind | Name | Here |
|---|---|---|
| screen | `find` | `html/find/index.php` |
| screen | `watch-list` | `html/watches/index.php` |
| action | `watch_set` | `html/watches/save.php` — `watches.own`; no approval category |
| action | `watch_clear` | `html/watches/clear.php` — own or `watches.all`; no approval category |
Left to another slice, and why: **`source_search`** (the manifest lists it under slice 3, base `/sources/`, file `search.php`) stays slice 3's — it is the
command bar's action over the same `inv_source_search_live()`; this slice's `/find/source-search` is a fragment of the Find screen, not an action, and the
bridge is the records tool's door, not an action. **`variant-view`** (slice 1) includes this slice's partials once built; it is slice 1's row.

## Activity log events
`watch.set|clear` (the handlers; `source_id` on a listing-variant watch), `watch.fire` (the worker's pass, source `cron`, actor null), `source.search` (written by
`inv_source_search_live()` for every live ask — from Find as `web`, from the bridge as `mcp` or `agent` with `agent_run_id` and `request_id`, with `after.eval`
under an eval run), `screen.view` on `find` (`after.q` ≤ 120 and the chips) and `watch-list`. **No row carries a customer, a credential or a raw listing**;
a threshold is an amount and is allowed.

## Notifications this slice queues (`inv_fire_watches()` → `inv_notify()`; the senders are slice 8's `outbox` and `dispatches` passes)
| Step | Who is told | Kind |
|---|---|---|
| a watch fires | the member who set it — in-app always; email per prefs; **a text row (K6) when `text_me`** (`p_force_text`) | `watch` (dedupe `watch:{id}:{fire_count}`) |
| a watch naming an agent fires | the agent — an `agent_dispatches` row (`watch`, `chat`, the watch and listing variant on it, the title as `detail`) | slice 8's dispatch → one chat turn |
Nothing is emailed to a customer or a supplier here.

## Status vocabulary
State chips: `in_stock` success · `from_supplier` info · `back_order` warning · `unavailable` secondary. Offer availability chips: `in_stock` success · `limited` info ·
`pre_order`, `back_order` warning · `out_of_stock`, `discontinued` danger · `unknown` secondary; **stale** a `warning` chip; **removed** `dark`. Watch kinds:
`back_in_stock` success · `price_below`, `cost_below` info · `map_breach` danger · `lead_time_over` warning · `removed` dark; "Now" a `success` dot. Live card
status: ok success · no live search / paused / backing off / a pull is running secondary · blocked danger · timeout warning.
Ids: `find-q`, `find-chips`, `find-chip-*`, `find-count`, `find-results`, `find-card-{id}`, `find-card-{id}-state`, `find-card-{id}-prices`,
`find-card-{id}-availability`, `find-card-{id}-actions`, `find-card-{id}-sell`, `find-card-{id}-watch`, `find-card-{id}-watch-form`, `find-ask-sources`,
`find-live`, `find-live-{source_id}`, `find-live-{source_id}-row-{listing_variant_id}`, `availability-{id}`, `availability-{id}-state|own|offers|references|components`,
`<prefix-slug>-choice-stock-{location_id}|pickup-{location_id}|dropship-{listing_variant_id}|backorder`, `atp-{id}`, `pick-results`, `pick-{variant_id}`,
`watch-form`, `watch-form-field-{name}`, `watch-form-save`, `watch-list-table`, `watch-row-{id}`, `watch-row-{id}-clear`, `watch-filter-{kind|fired|member|cleared}`.

## The 375 px rule
Find is the phone screen: the box full width with the search keyboard; the chips one horizontal strip (`d-flex flex-nowrap overflow-auto`, each ≥ 44 px);
the cards stacked, a card's tables inside `.table-responsive`, the three actions stacked full width; the live cards stacked under the results; `scrollWidth`
= the viewport; the bottom tab bar's Find tab (Phase 2) lands here. The watch list is a table at 1280 and cards-of-rows at 375 (the `.table-responsive`
wrapper; the Clear control ≥ 44 px). The availability partial fits a 375 card (the own-stock table first, the offers table scrolling sideways inside its
wrapper). JavaScript off: the Find form is a plain GET, every chip a plain link, the availability region shows `inv_find()`'s compact facts and a "Details"
link to `/variants/{id}`, "Ask the sources now" is a link to `/find/sources?…` whose cards carry a "Ask" link each, the watch form is a page at `/watches/form?…`.

## Vocabulary
**own** = the business's stock; **available** = on hand − allocated − floor; **offer** = a supplier source's current price and availability for a listing
variant matched to ours; **reference** = a reference source's; **as of** = the last snapshot or sighting; **stale** = not confirmed within twice the source's
schedule; **best lead time** = 0 when sellable stock exists, else the best in-stock supplier offer's days; **state** = in stock / from supplier / back order /
unavailable; **a watch** = one target, one kind, one threshold, one member; **fires** = the condition became true since the last evaluation.

## Out of scope for this slice
The variant page and the product list (1), stock documents (2), sources, listings, the match queue and `source_search`'s action file (3), orders and the
picker's consumer (5), purchase orders (6), the feed (7), the outbox sender, the dispatches pass and the K6 call itself (8), the home's counts (9), the
records tools in Python (Phase 4 — `find`, `availability`, `atp`, `my_watches`, `watch_events`, `source_search` over this bridge).

## Proof (`tests/phase3/slice4/run.sh`: the scratch database `inv_dev4` from `tests/setup_dev.sh`; the fixture server `tests/fixtures/sources/router.php` at
8606 as `tests/phase0/connectors.sh` starts it; the fake kernel `tests/fake_kernel.php` at 8602 (`FAKE_KERNEL_STATE`); the app `php -S 127.0.0.1:8607 -t html
tests/dev_router.php`; members through `bin/dev_handoff.php`; curl with signed action and run tokens; headless Chromium at 375 × 740 and 1280 × 800;
`php bin/build_action_registry.php` and `php bin/sync_approvals.php --check`) — **at least 150 checks**
The world (`tests/phase3/slice4/lib.php` `find_world()`): the fixture's members (Nora 40 the Buyer, Sam 41 Sales, Wes 42 Warehouse, Vera 43 a Viewer, Ann 44
external, the expert 45, Omar 46 no grant, the Owner 1); locations SMOKE Warehouse (sellable), SMOKE Showroom (sellable, a floor model), SMOKE Returns (not
sellable); brand SMOKE Purple with a product in six sizes and a set (bundle), a SMOKE Casper Original; suppliers SMOKE Malouf (shopify fixture source, has
search, lead 5) and SMOKE Zinus (feed fixture source, lead 3); a reference source SMOKE Casper site (shopify fixture); a paused source and a blocked one;
stock: 4 Queens at the Warehouse (1 allocated), 1 King floor model at the Showroom; offers matched on the Queen, King and Cal King; a Twin nobody offers.
- [ ] **Find** (≈ 40): by name ("purple hybrid" — the six sizes and the set, the foundations after), by SKU exact (score 9, first), by GTIN (normalised,
  score 10), by MPN, by brand word; `size=queen` → three; the type chip, the firmness chip, each price band, "in stock only" (the Queen and the King's
  supplier offer stay, the Twin goes), `ships_within=3` (the Zinus-offered one stays, Malouf's 5-day goes); an empty Find runs no query and shows the hint;
  a 121-character `q` → 422; the 50 cap and its line; `HX-Push-Url` carries the query; the card's prices, state chip, the compact facts; **cost on a card for
  Nora, absent for Sam, absent for Vera** (no "withheld" word anywhere in the HTML); "Sell this" links `/orders/new?variant=…&fulfilment=stock&location=<Warehouse>`
  for the Queen, `…fulfilment=dropship&listing_variant=<Malouf's>` for the Cal King, `fulfilment=backorder` for the Twin; Sell this absent for Vera and Ann; Watch
  absent for Vera (no `watches.own`); `screen.view` logged with `q` cut at 120.
- [ ] **The availability partial** (≈ 30): the Queen's own table (Warehouse 4 on hand · 1 allocated · 3 available; Showroom absent), offers ranked (Zinus in
  stock 3 days before Malouf 5 days when cheaper; a pre-order offer after; a removed one last and struck), references by price, the state line, "On order"
  after a sent stock PO; stale chip when `last_seen_at` is backdated; the cost column exists for Nora, not for Sam, and **appears for Sam when
  `sales_sees_cost` is on**; the bundle shape (components, sets, "sold as its components" in compact); a variant the caller cannot see → 404; **compact**: the
  radios for qty 1 (stock Warehouse, pickup Warehouse, pickup Showroom? — no: the Showroom holds a floor model only, 0 available → no radios for it), dropship
  Zinus and Malouf, backorder with its location select; for qty 5 the Warehouse radio is gone (3 available) and the recommended radio is Zinus's; `field=lines[2]`
  names `lines[2][fulfilment]` and ids `lines-2-choice-…`.
- [ ] **ATP** (≈ 10): 1 Queen → stock at the Warehouse, by today; 5 Queens → Zinus in 3 days; 5 by tomorrow → "a supplier has it, but not by …"; the Twin →
  "not in stock and no supplier offers it"; qty 0 → the function's sentence as a 422; the partial's warning box.
- [ ] **Live** (≈ 25): `/find/sources` draws a card per searchable source — Malouf and the Casper site, not Zinus (feed: no search), not the paused one, not the
  blocked one; each `/find/source-search` answers its card: Malouf ok with N listings, the matched row linking the SKU, an unmatched row with the match-queue
  link for Nora and without it for Sam; the Casper site ok (a reference: no cost column even for Nora); a `source_pulls` row of kind `search` per ask with the
  query; the listings written (a new listing variant, a snapshot); asking the paused source directly → "paused" with the last pull's rows; the blocked one →
  blocked; a source id that is not searchable → 404; Vera → 403 in words; `HX-Trigger: offerChanged` on the answer; `source.search` logged with `source_id`,
  the query, `listings_seen`; a second ask within the rate is spaced by the host lock (the fixture log shows ≥ 1 s between requests to one host).
- [ ] **Watches** (≈ 35): Sam sets `back_in_stock` on the Twin (last_state false at once), `price_below 300` on the Cal King's Malouf offer (a listing variant,
  `source_id` on the log row), a product watch; a threshold on `back_in_stock` → 422 in words; `price_below` without one → 422; `cost_below` by Sam → 403, by
  Nora ok; naming the expert by Sam → 403, by Nora ok; the same watch twice → 422 naming the existing id; a variant he cannot see → 404; the list shows his
  three with the Now column; Vera has no list (403 in words); Nora's list shows everyone's with the member filter; Sam cannot clear Nora's (404), clears his own
  (`active` false, `watch.clear` logged), clearing twice → 422; "show cleared" lists it; **firing**: a feed pull (the fixture) puts the Twin in stock →
  `worker_pass_watches` fires it once: `notifications` row for Sam (kind `watch`, the title "Back in stock: …"), an email outbox row (his default kinds), **a text
  outbox row because he chose `text_me`** (channel text, body ≤ 300), `watch.fire` logged with `notified`, `texted: true`, `dispatched: null`; the pass again →
  fires nothing (the state held); the Twin out again and back → fires a second time (`fire_count` 2, a new dedupe key); Nora's agent watch fires → an
  `agent_dispatches` row (kind watch, via chat, `watch_id`, the expert) and `dispatched` on the log row; a price drop under 300 fires the price watch; the
  heartbeat pass answers its count and writes snapshots only for stale variants.
- [ ] **The bridge** (≈ 15): a POST without the header → 401; a wrong HMAC → 401; a 31-second-old timestamp → 401; from a non-loopback `REMOTE_ADDR` (the
  proof's `php -S` is loopback — the check is unit-tested through `bridge_verify()` with a forged address) → 401; `op: "foo"` → 404; an unknown member → 403
  `unknown_member`; Omar (no grant) → 403; Vera → 403 `insufficient_right`; Sam with `q: "purple hybrid"` → `ok`, `asked` for Malouf and the Casper site with
  `ok`, `rows` with `matched` and the wall (no cost for Sam; cost for Nora); `sources: [<Zinus>]` → `skipped`; six sources → 422 `invalid_argument`; the
  `source.search` rows say source `mcp` and the member; `door: "agent"` with `run_id` → source `agent` and `agent_run_id`; **`is_eval: true`** → `eval: true`,
  rows from the connector, **no new `source_pulls`, listings or snapshots** (counts equal before and after), the log row with `after.eval = true`.
- [ ] **JSON mode** (≈ 10): `watch_set` and `watch_clear` under a signed action token answer `{ok, did, record_id, location, refresh}` (the location ending in
  the id); 422 `{error: {code: invalid, fields}}`; the expert (run token + relay) sets its own watch (`member_id` 45, source `agent`) and may not name an agent
  (it holds `user` in the fixture — 403 in words); the registry reads `find` and `watch-list` built and `watch_set`, `watch_clear` built; `sync_approvals --check` clean.
- [ ] **375 × 740 and 1280 × 800** (≈ 20): Find on the phone — the box, the chip strip scrolling sideways without page scroll, three cards with their
  availability loaded on reveal (the fourth not yet), Sell this and Watch ≥ 44 px, the inline watch form under a card and its Save landing back on Find with
  `#watch-row-…`; "Ask the sources now" filling two live cards within 15 s and the revealed cards reloading (the `offerChanged` trigger); at 1280 the same
  with the cards in a grid; the watch list at both sizes; every control ≥ 44 px, `scrollWidth` = viewport, no console errors; JavaScript off: a plain Find, a
  plain chip, the watch form as a page.

## Built and proven
**2026-10-10 — BUILT and proven by the planning model** (`tests/phase3/slice4/run.sh` on the scratch database `inv_dev4` with the fixture server on
8606: **305 checks green under php -S and 306 under a real Apache** — world 11, find 54, availability 40, atp and the pick list 19, live 33, watches 74,
bridge 31 (32 under Apache: the public name's 404), json 14, browser 27 at 375 × 740, 1280 × 800 and JavaScript off; the registry — **56 screens and 65
actions built**, 19 placeholders — and the approvals in step). Every file of "Files" is built; the worker's `snapshots_heartbeat` and `watches` passes
are live. The earlier suites re-run green — slice 3 (290), slice 2 (325), slice 1 (310), Phase 2 (329; its placeholder count 19 and the bell's watch
link with the way back before the fragment), Phase 0 (42 + 517 + 511), the Phase 1 claim checks (52) — and the installer's plan is clean (57 steps).

**Found and fixed (not questions):**
- **`inv_source_search_live()` did not log**: slice 3's action wrote `source.search` itself, so the Find card and the bridge would have asked a source
  with no trail. The function now writes the row around its work (`after`: pull_id, query ≤ 120, size, status, found, listings_seen, listings_new, ms,
  eval) and `/sources/search.php` no longer does — one row per ask whoever asks, saying `web`, `assistant`, `mcp` or `agent`.
- **`variant_availability()` was slice 1's already** (`catalog/queries.php`, the same function): Find reads that one; no second definition.
- **A JSON caller lost the record a refusal points at**: the guard's `sentence|id` shape put the id only in `X-Action-Data`. `inv_guard()` now answers
  a JSON caller `{error: {code, message, record_id}}` — the duplicate watch names the existing one in the body (the kit's change; every slice gains it).
- **`one_value()` reads a boolean `false` as "no row"** (`fetchColumn()`): a watch evaluated false came back `current: null`. The kit gains
  `one_row()`; the save handler reads the state through it. A kit lesson: never read a boolean through `one_value()`.
- **`with_back()` put `?back=` after a fragment**: a watch's place is `/watches/#watch-row-{id}` (it has no page of its own — `record_url()` now says
  so), and the bell's link became `/watches/#watch-row-7?back=…`. `with_back()` now puts the way back before the fragment.
- **A blocked source answered "backing off"**: the live function's reason for a walled source is its back-off; `live_search_source()` names it
  `blocked` when `inv_source_health()` says so (the spec's `danger` chip), and a pause for breath stays `backing off`.

- **Phase 0's home check flaked** (green on a rerun, here and in slice 3): `echo "$home" | grep -q` under `pipefail` fails when `grep -q` exits on
  its match and `echo` takes SIGPIPE on a large page. The two lines read a here-string now; three runs in a row green.

**Decisions taken while building (not questions):**
- **The cards load on `intersect once`, not `revealed`**: htmx's `revealed` listens to the window's scroll and never fired for the cards below the
  fold in this shell; an IntersectionObserver does. A phone shows one card (≈ 320 px) at a time, not three — the proof checks the card in view loaded,
  the ones below not yet, and the last one loading when scrolled to.
- **"Sell this" names the location without an `inv_availability()` per card**: one query (`best_stock_locations()`: the sellable location with the
  most available per variant — a bundle's first component's) beside the one `inv_find()`; the rest of `recommended_fulfilment()` reads the row's best offer.
- **The world is the spec's on the fixtures that exist**: the Shopify, feed and WooCommerce fixtures sell the Cloudrest Hybrid, so the spec's "Purple
  hybrid" is the catalog's SMOKE Cloudrest Hybrid; Malouf is the Shopify fixture as a supplier (lead 5), Zinus the feed (lead 3), the Casper site the
  Shopify fixture as a reference, the paused source the WooCommerce fixture (Meadowlark — asked with "meadowlark"), the blocked one the router's wall.
  Every hybrid size is offered by someone, so the spec's "Twin nobody offers" is the **Foundation King** (nobody holds or offers it). Where the spec said
  Zinus for 5 Queens, the fixtures say Malouf (Zinus is out of Queens); the ranking "the cheaper first" is proven on the Twin (Zinus 349.50 before
  Malouf 699.00); a pre-order offer is Zinus's Queen set to `pre_order` for one check; the firing is proven by stock arriving, selling out and arriving
  again (a ledger posting), and the price watch by the fixture's second version read by Malouf's next pull (999.00 → 949.00 under 950).
- **The handler reads ids**: a SKU or GTIN arrives resolved by the registry (the spec's words); the proofs send ids.
- **`lead_time_over` reads the listing's own lead time** (`inv_watch_state()`, db/009 — never modified): Malouf's Shopify listing states none, so a
  watch for "over 4 days" on the Cal King is false though the supplier's default is 5. Recorded, not changed — the referee decides; a later schema may
  read `inv_offers_for_variant()`'s lead time instead.
- **The threshold field shows only for the kinds that have one** — a few lines in the shell's inline script (`layout.php`), with JavaScript off the
  field shows and is left empty for the kinds that refuse it.
- **The variant page's Watches card gains its Watch button** (slice 1's page; the inline form loads into it).
- **Under Apache the proof's port 8601 is the PUBLIC vhost** (the rendered `*:80`): the bridge proof calls the internal vhost on 8607 there and checks
  that the public name answers 404 under `/internal/`.

## Decisions taken while writing (2026-10-05)
- The Find list is one `inv_find()` (limit 50); each card's availability is one `inv_availability()` loaded when the card is revealed, and reloaded on `offerChanged`.
- An empty Find runs no query; the chips alone do. The form carries no `hx-push-url`; the server answers `HX-Push-Url` with the query. Price bands are the four fixed ones.
- Cost is a column that exists for `sees_cost()` and is absent otherwise — never a "withheld" label on a screen (the tools say the word).
- "Sell this" carries the recommended fulfilment of `recommended_fulfilment()`; a backorder carries no location (the picker asks).
- The compact availability partial IS the order form's picker: radios valued `stock:{location}`, `pickup:{location}`, `dropship:{listing_variant}`, `backorder`
  (+ `backorder_location`), named by the caller's `field` prefix, the promise line beneath. Slice 5 and slice 6 read `kind:id`.
- Searchable sources are drawn by the same rule the live function applies; each source's answer triggers `offerChanged`; the browser fans out.
- A watch's threshold is refused for the kinds that have none; `cost_below` needs the wall's right; naming an agent needs `watches.all`; a duplicate is refused
  naming the existing watch; `last_state` is evaluated on save; clearing deactivates, never deletes; the list has no add form.
- The worker's `watches` pass logs `watch.fire` per fired watch from `fired_at >= t0`; `snapshots_heartbeat` is one call; the text row is queued here and sent by
  slice 8's outbox pass; the dispatch is answered by slice 8's dispatches pass.
- The bridge: loopback + HMAC (`inv-bridge:` prefix) + ±30 s, `orders.write`, ≤ 5 sources in sequence with 25 s each and 60 s in all; under `is_eval` the
  connector is asked directly and nothing is written; `default_activity_source()` honours `$GLOBALS['__activity_source']` (one additive line) so the rows say
  `mcp` or `agent`; the public vhost answers 404 under `/internal/`.
- The connectors spec (§6.6), the tool surface and this spec now agree (reconciled 2026-10-05): the tool reaches PHP through the bridge with 25 s a source,
  60 s a call, ≤ 5 sources and ≤ 20 listings each; `/sources/search.php` stays the command bar's action (slice 3). The manifest, the tool surface, the Find
  button, the bridge and slice 3's action file all enforce `orders.write` on `source_search` (design A8: Sales — asking a source costs HTTP).

## Open questions
(none)

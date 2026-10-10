# Build spec: the availability feed, its keys, the price lists and the five shares' readers (slice 7)

Built by the worker model from this spec, replicating slice 3's files, gates and proof style (`docs/build-specs/sources.md`, the exemplar)
and slice 1's CRUD pattern for the two admin lists. What exists at the end: an outside system — the business's website, another installation
of this application, a partner store — holds a **feed key** and asks `GET /api/v1/availability?gtin=|sku=|q=` and gets, per variant, our
retail, our availability state, the best lead time and how it ships (a partner key also gets its **partner price**); the 61st call in a
minute and the 10,001st in a day are refused with a 429 and `Retry-After`; the admin mints a key (shown once), rotates it with a 24-hour
overlap, revokes it, and reads each key's usage per day; the admin keeps **price lists** (a named percentage off retail) and names one on a
partner key; the five K7 shares have their PHP readers (thin wrappers over db/016's `inv_share_*`) that Phase 4's records server and slice 9's
exports call; the Connections page shows which sibling applications read what of ours. **The smallest slice** (design §10).
Schema: `feed_keys`, `price_lists`, `key_usage`, `inv_feed_keys_before()` (the limits default from the settings), `inv_resolve_feed_key()`,
`inv_rate_ok()`, `inv_feed_key_calls_today()`, `inv_feed_key_rotate()`, `inv_feed_key_revoke()`, `inv_prune_key_usage()` (db/013);
`inv_feed_answer()` (db/014 — the writer's alone); `inv_settings.feed_rate_per_minute`, `feed_rate_per_day`, `key_rotation_overlap_hours`,
`feed_shows_quantity`, `currency` (db/005); `activity_log.token_id` and the source `feed` (db/002); **db/016's `inv_share_sales_closed()`,
`inv_share_purchases_received()`, `inv_share_stock_valuation()`, `inv_share_availability_index()`, `inv_share_customer_orders()`** (built
beside this spec — the tool surface's "Owed to the schema"); the views `mcp_feed_keys` (never the hash), `mcp_price_lists`, `mcp_key_usage`,
`mcp_members`, `mcp_activity_log` (db/015). Never modify them. The kit's API door: `app/api/bootstrap.php` (`api_cors()`, `api_require_get()`,
`api_bearer_token()`, `api_json()`, `api_error()`). **The database is the referee**: a key resolves only while live, unexpired and its minter
still admitted; the minute bucket refuses the 61st call and the day the 10,001st and a refused call is never charged to the day; a partner
key alone may name a price list (the CHECK); rotation keeps the old key the overlap and no longer; the feed's answer is `inv_feed_answer()`'s —
never cost, never a source's name, the quantity only when the setting says so.

## Divergences from the design and the tool surface
1. **A session is not admitted to the feed.** Knowledge's token API admits the application's own pages; here a person uses Find. `/api/v1/availability`
   answers **401 to anything but a feed key** (`feed_` + 48 hex): no bearer, a wrong one, a person's `mcp_` token, a revoked, expired or rotated-out key,
   a minter no longer admitted — one body. **DECISION 1.**
2. **`share.read` for the kernel's calls is written by Phase 4's server, not here** — through db/017's `inv_log_share_read(p_tool, p_consumer,
   p_count, p_request_id)` (SECURITY DEFINER, granted to `inventory_records_ro`, which has no INSERT on `activity_log` itself). The row as built
   (reconciled 2026-10-05): `source = 'mcp'`, `actor_member_id` NULL, `action = 'share.read'`, `entity_type = 'share'`, `after = {tool, consumer, count,
   request_id}` — `activity_log` has no `direction` column and the row carries no `from`, `to`, `rows` or `ms`. The Connections page reads whatever
   `share.read` rows exist (`after->>'consumer'`, `after->>'tool'`, `(after->>'count')::int`) and says "nothing read yet" when there are none. The
   functions this slice writes log nothing when called by PHP (an export is logged as `export.download` by slice 9). **DECISION 2.**
3. **The design's "usage counted per key per day" and the tool surface's `key_usage` minute buckets** are both `key_usage`'s; the screen shows the day
   buckets of the last 35 days and, on request, the minute buckets of the last hour (what `inv_prune_key_usage()` keeps). **DECISION 3.**

## Screens (usable at 375 px, designed at 1280 px — the admin's screens; the feed has no screen)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `feed-key-list` | `/admin/feed-keys/?key=&bucket=` | the keys (`mcp_feed_keys`) as a table (`feed-key-list-table`): label, consumer kind (a chip), the partner's price list, per minute / per day, **today's count** (`calls_today`), last used, status (live · expiring at <time> when rotated · revoked · expired), minted by and when, "rotated from" linking the older key (`feed-key-row-{id}`); **Mint a key** (`feed-key-list-mint-btn` → `feed-key-add`); per row **Rotate** (`feed-key-row-{id}-rotate-btn`, a confirm) and **Revoke** (`feed-key-row-{id}-revoke-btn`, a confirm); the copy-once box after a mint or a rotate (below); with `?key=<id>` the key's **usage** beneath (`feed-key-usage`: a table of day buckets, newest first — date, calls, refused — and the totals; `&bucket=minute` the minute buckets of the last hour); a note on the feed's URL (`INV_PUBLIC_BASE_URL` + `/api/v1/availability`) with a curl example using `KEY` as a placeholder (`feed-key-list-howto`) |
| `feed-key-add` | `/admin/feed-keys/new` | the form (`feed-key-form`): label (`feed-key-form-field-label`, 1–80, required — what will use the key), consumer kind (`-consumer_kind`: website · installation · partner; website by default), price list (`-price_list`: the active price lists; shown and required only for partner — a script toggles the row; without JavaScript the row is always shown and a non-partner key with a price list → the CHECK's sentence), calls per minute (`-rate_per_minute`) and per day (`-rate_per_day`; blank = the settings' defaults, shown as the placeholder; 1–100000 / 1–10000000), expires (`-expires_at`, a date, optional); **Mint** (`feed-key-form-save-btn`) / Cancel (`feed-key-form-cancel-btn`) in the pinned header |
| `price-list-list` | `/admin/price-lists/` | the price lists (`mcp_price_lists`) as a table (`price-list-list-table`): name, percent off retail, active, the keys using each (count, from `mcp_feed_keys`), notes (`price-list-row-{id}`); **Add a price list** (`price-list-list-add-btn`); per row **Edit** (`price-list-row-{id}-edit-btn`) |
| `price-list-add` / `price-list-edit` | `/admin/price-lists/new`, `/admin/price-lists/{price_list}/edit` | the form (`price-list-form`): name (`price-list-form-field-name`, 1–80, required, unique ignoring case), percent off retail (`-percent_off_retail`, 0–99.99, two decimals, required), notes (`-notes`), active (`-active`, a switch — a list made inactive stops pricing its keys' answers: the partner price becomes retail, the function's rule); Save / Cancel in the pinned header |
| `connection-list` | `/admin/connections` | **read-only** (claimed from the manifest's slice 9 section — design §10 names Connections with the feed, and both are "who outside reads us"): the five shares this application declares (`maludb-os.json` `shares[]`: tool, what it answers, its document — `connections-shares`); the `share.read` rows of `activity_log` (`entity_type = 'share'`; reconciled 2026-10-05 to db/017's row) **grouped by consumer and tool** (calls, the sum of `count`, last read — `connections-readers`) and the last 50 rows (`share-reads`: when, consumer, tool, count, request id); the reads this application declares (`reads[]` — none in v1: "Inventory reads nothing of another application in version 1" — `connections-reads`); a note: "Approving a connection between applications is a super-admin's decision in the OS (`bin/app_connection.php`)" linking the OS's application page (`OS_LAUNCHER_URL`'s host with `app.` → `os.`, path `/applications`) — `connections-os-link` |

### The copy-once box
After a mint or a rotate the raw key is put in `$_SESSION['minted_feed_key'] = ['key_id', 'raw', 'old_expires_at' => ?time]` and the redirect lands on
`/admin/feed-keys/#feed-key-row-{id}`; the page renders it once in a `success` box (`feed-key-minted`) — the value in a read-only field
(`feed-key-minted-value`) with a Copy button (`feed-key-minted-copy-btn`), "Shown once — store it now; it cannot be shown again." and, after a
rotate, "The old key stops working at <time>." — and unsets it. It is never in a URL, a log row, a notice, an HTMX header or the JSON of any
read. JSON mode (an agent under an action token, after its approval) gets it once in the action's response (`key`), as the kit's `token_mint` does.

## The keys
- **Mint** (`feed_key_mint`): `feed.keys`; label required; `consumer_kind` ∈ website · installation · partner (website by default); `price_list` only
  with `partner` (else 422 `fields.price_list` "A price list goes on a partner's key." — PHP's sentence before the CHECK's); an inactive price list →
  422 "That price list is inactive."; limits blank → NULL at insert → the trigger fills the settings' defaults (`inv_feed_keys_before`); a limit above
  the settings' default is allowed to the admin (the admin is the only minter — no second tier); `expires_at` a date ≥ tomorrow, stored as that day's
  end in the business's time zone; the raw key is `'feed_' . bin2hex(random_bytes(24))`, stored as `hash('sha256', $raw)` with `scope = 'feed'`
  and `member_id` = the minter. **`external_send` for an agent.** Log `feed.key_mint` (`key_id` as `entity_id`, entity `feed_key`, `token_id` =
  the key's id, `after`: `label`, `consumer_kind`, `price_list_id`, `rate_per_minute`, `rate_per_day`, `expires_at`) — never the raw key or its hash.
- **Rotate** (`feed_key_rotate`): `feed.keys`; a live key only (`inv_feed_key_rotate()`'s words otherwise: "Only a live key is rotated"); a new raw key,
  `SELECT inv_feed_key_rotate(:old, :new_hash)` → the new id (the same minter, label, consumer, price list and limits; `rotated_from` set); the old
  key's `expires_at` becomes `now() + key_rotation_overlap_hours` (24) unless already sooner — both work until then; the copy-once box shows the
  new key and the old key's end. Log `feed.key_rotate` (entity the NEW key; `after`: `label`, `consumer_kind`, `rotated_from`, `old_expires_at`).
  No category (the manifest: a rotation reaches nobody new).
- **Revoke** (`feed_key_revoke`): `feed.keys`; `SELECT inv_feed_key_revoke(:key, :by)`; a key already revoked → 422 "That key is already revoked."
  (PHP checks `mcp_feed_keys.revoked_at` first — the function is silent); it answers 401 at once (`inv_resolve_feed_key()` returns nothing);
  revoking a key mid-overlap revokes only it. Log `feed.key_revoke` (`after.label`). No category.
- **A revoked grant shuts the door**: a minter whose capability is withdrawn in the mirror (`members.capability` NULL) or deactivated resolves to
  nothing (`inv_resolve_feed_key()`'s join) within the directory sync's minute — every key they minted answers 401 until an admin rotates it under
  their own name? — no: **a key's minter is for life (DECISION 4)**; the admin revokes it and mints a new one. The keys page marks such a key
  "minter no longer here" (`danger`).
- **Price lists** (`price_list_save`): `feed.keys`; name unique ignoring case (the index → `inv_guard()`'s "That name is already taken." is right here);
  `percent_off_retail` 0–99.99; `active`; `price_list` names an existing one (update). Log `price_list.save` (`name`, `percent_off_retail`, `active`,
  the fields changed — `inv_diff()`). No category.

## The feed (`GET /api/v1/availability`; the document is the tool surface's `os.inventory-feed/1`)
`html/api/v1/availability.php`, in this order, every call:
1. `api_cors()` (CORS from `API_CORS_ORIGINS` only, `GET, OPTIONS`, never `Allow-Credentials`; an `OPTIONS` answers 204 and stops); `api_require_get()`.
2. **The key**: `feed_authenticate()` (added to `app/api/bootstrap.php` — the kit's one change): `api_bearer_token()` must match `^feed_[0-9a-f]{48}$`;
   `SELECT * FROM inv_resolve_feed_key(:hash)` with `hash('sha256', $raw)`; no row → **401** `{"error": {"code": "unauthorized", "message": "A valid
   feed key is required."}}` — one body for every failure (DECISION 1). On success: `$_SESSION['member_id']` = the key's `member_id` (the minter
   is the acting member — `inv_feed_answer()` runs as the writer with the minter's identity, the function's comment), `$GLOBALS['__public_door'] =
   'feed'` (every log row's source), `$GLOBALS['__feed_key'] = <the row>`, `db_apply_context()`, `header_remove('Set-Cookie')`.
3. **The query**: exactly one of `gtin`, `sku`, `q` (+ optional `size`); none, or two, or `q` over 120 characters, or `size` over 40 → **422**
   `{"error": {"code": "invalid", "message": "Say gtin, sku or q (up to 120 characters).", "fields": {...}}}` — read before the rate check, so a
   malformed call is not charged? — **no: the rate check comes first (DECISION 5)** — a flood of malformed calls still counts; step 3 and 4 swap:
4. **The rate**: `SELECT * FROM inv_rate_ok(:key_id)` — `ok` false → **429** `{"error": {"code": "rate_limited", "message": "Too many calls
   (minute).", "limit": "minute" | "day", "retry_after": <seconds>}}` with `Retry-After: <seconds>` (≥ 1); logged **once per bucket**: when
   `key_usage.refused` for that key, bucket kind and bucket start has just become 1 (`SELECT refused FROM key_usage WHERE …` after the call) the
   handler logs `feed.rate_limited` (source `feed`, `token_id`, `after`: `key_label`, `limit_hit`, `retry_after`) — a flood writes one row a minute,
   not thousands. `limit_hit = 'revoked'` or `'no_key'` (a race with a revoke) → 401 as in step 2.
   Then the query of step 3 (422 for a bad one — counted, as decided).
5. **The answer**: `SELECT inv_feed_answer(:key_id, :q::jsonb)` with `q` = `{"gtin": …}` | `{"sku": …}` | `{"q": …, "size": …}` (size omitted when
   empty); wrapped as `{"schema": "os.inventory-feed/1", "generated_at": <now ISO 8601>, "application": "inventory", "currency": <inv_currency()>}
   + the function's object` (`query`, `count`, `results[]`, `as_of`). `Cache-Control: no-store`. At most 25 results (the function's).
6. **Log** `feed.read` (source `feed`, `actor_member_id` NULL, `token_id`, entity `feed_key` / the key id, `after`: `key_label`, `count`, `query_kind`
   (gtin · sku · q), `query_excerpt` (≤ 120 characters — the value of gtin/sku, or `q` cut; never more)). A `feed.read` row per answered call: the
   design's "every answer logged".
Errors share one body `{"error": {"code", "message"}}`; a 500 is `server_error` (the kit's handler). `partner_price` appears only for a `partner`
key whose price list is active (`retail × (1 − percent/100)`, two decimals); `quantity` only when `feed_shows_quantity` is on and the state is
`in_stock`; **never cost, never a source's name** — the function's; the handler adds nothing and removes nothing.
- The vhost allow-list already carries `/api/v1/availability` (`deploy/apache-inventory.conf`) and `tests/dev_router.php` already rewrites it: this slice
  changes neither. `maludb-os.json` already declares the endpoint (`auth_kind api_key`).
- A key answers through **Find's function** (`inv_find`) for `q`, so the feed and the screen agree; a GTIN is normalized by the function (`inv_gtin14`).

## The five shares' readers (`app/features/shares/queries.php` — Phase 4's server and slice 9's exports call these; the tools are Phase 4's)
Each is a thin wrapper: one `SELECT inv_share_<name>(…)`, the `jsonb` decoded, **nothing added, nothing removed** — the document is the function's
(the tool surface's five shapes). The functions carry cost unnulled and take no caller into account: **PHP calls them only from a handler that has
already gated** (`exports.all`, or `reports.read` with `sees_cost()` — slice 9's rule; Phase 4's server, the kernel's token). They are never
reachable from a screen a Viewer opens. The functions must be executable by `inventory_rw` (db/016's grant — reported to the lead as a requirement).
- `share_sales_closed(PDO, string $from, string $to, int $offset = 0, int $limit = 200): array` — `p_to − p_from` ≤ 92 days and `limit` ≤ 500 are the function's
  (`check_violation` → the caller's 422 through `inv_guard()`).
- `share_purchases_received(PDO, string $from, string $to, int $offset = 0, int $limit = 200): array`
- `share_stock_valuation(PDO, string $asOf, string $by = 'location'): array` (`by` ∈ location · brand — the function's)
- `share_availability_index(PDO, array $query, int $limit = 25): array` (`$query` = `['q' => …, 'size' => …]` | `['gtin' => …]` | `['sku' => …]`; ≤ 100)
- `share_customer_orders(PDO, string $email, bool $openOnly = true, int $limit = 25): array`
- `declared_shares(): array` (from `maludb-os.json` `shares[]`: tool, description, scoped) · `declared_reads(): array` (`reads[]`, empty in v1)
- `share_reads(PDO, int $limit = 50): array` (the last `share.read` rows — `entity_type = 'share'` — from `activity_log` as the writer: `occurred_at`, `after->>'consumer'`, `after->>'tool'`, `(after->>'count')::int`, `after->>'request_id'`; reconciled 2026-10-05) · `share_readers(PDO, int $days = 30): array`
  (grouped: `consumer`, `tool`, `calls`, `count` (the sum), `last_at`)
A proof probe (`tests/phase3/slice7/shares.php`) calls the five readers directly and compares each document's `schema` and top-level keys with the
tool surface's shapes — the one place slice 7 proves db/016's functions from PHP.

## Files (exactly these)
- `html/api/v1/availability.php` (the feed)
- `html/admin/feed-keys/index.php` (`feed-key-list`) · `form.php` (`feed-key-add`) · `mint.php` · `rotate.php` · `revoke.php`
- `html/admin/price-lists/index.php` (`price-list-list`) · `form.php` (`price-list-add`, `price-list-edit`) · `save.php`
- `html/admin/connections.php` (`connection-list`)
- `app/features/feed/{queries,present,write,handler}.php` (`handler.php`: load the key or the price list, the gate — `require_right('feed.keys')` —, `feed_log()`: `token_id` on every key row) · `app/features/shares/queries.php` · `app/features/connections/{queries,present}.php`
- `app/api/bootstrap.php` — the kit's: gains `feed_authenticate(): array` and `feed_rate_limit(array $key): void` (step 4 above); nothing else changes
- `app/views/admin/feed-keys/{index,form,partials/key-row,partials/key-minted,partials/usage}.php` · `app/views/admin/price-lists/{index,form,partials/row}.php` · `app/views/admin/{connections,partials/share-read-row,partials/reader-row}.php`
- `tests/phase3/slice7/{run.sh,lib.php,keys.php,api.php,rate.php,rotate.php,partner.php,shares.php,connections.php,json.php,browser.mjs}`

## Query functions (signatures fixed; PDO first)
- `find_feed_keys(PDO, array $f = []): array` (`mcp_feed_keys` ⨝ `mcp_price_lists` for the name ⨝ `mcp_members` for the minter's name; `f`: `live` (default every key), `consumer_kind`, `q` on the label; every key, newest first) · `find_feed_key(PDO, int $keyId): ?array` (`mcp_feed_keys`; null when the caller lacks `feed.keys`)
- `mint_feed_key(PDO, array $fields, int $by): array` (`['key_id', 'raw']`; INSERT on `feed_keys` with the hash) · `rotate_feed_key(PDO, int $keyId, int $by): array` (`['key_id', 'raw', 'old_key_id', 'old_expires_at']`; `inv_feed_key_rotate()`) · `revoke_feed_key(PDO, int $keyId, int $by): void` (`inv_feed_key_revoke()`)
- `key_usage(PDO, int $keyId, string $bucket = 'day', ?string $from = null, ?string $to = null): array` (`mcp_key_usage`; `['rows' => [{bucket_start, calls, refused}], 'totals' => {calls, refused}]`; `day`: the last 35 days by default; `minute`: the last 60 minutes) · `key_usage_summary(PDO): array` (every live key's `calls_today`, for slice 9's Home)
- `find_price_lists(PDO, bool $activeOnly = false): array` (`mcp_price_lists` with `keys_using` from `mcp_feed_keys`) · `find_price_list(PDO, int $id): ?array` · `save_price_list(PDO, ?int $id, array $fields, int $by): int` (INSERT/UPDATE on `price_lists`)
- `feed_authenticate(): array` (the key row of `inv_resolve_feed_key()`, or 401) · `feed_rate_limit(array $key): void` (429 or nothing; logs `feed.rate_limited` once per bucket) · `feed_answer(PDO, int $keyId, array $query): array` (`inv_feed_answer()` wrapped in the document) · `feed_query_from_request(): array` (`['kind' => gtin|sku|q, 'query' => [...], 'excerpt' => ≤ 120]`, or 422)
- the shares' readers above (`app/features/shares/queries.php`)
- `declared_shares(): array` · `declared_reads(): array` · `share_reads(PDO, int $limit = 50): array` · `share_readers(PDO, int $days = 30): array` (`app/features/connections/queries.php`)

## Handlers (every one: `inv_handler_begin()`; `require_right('feed.keys')`; `inv_guard()`; `log_activity` with `token_id`; `emit_action_status()`; `inv_done()`; `HX-Trigger: feedChanged`)
- `admin/feed-keys/mint.php` (`feed_key_mint`): the fields (label 1–80 required; consumer kind in the three; `price_list` an active row of `mcp_price_lists` through `inv_ref()`, refused without `partner`; the limits through `inv_int()` with the table's bounds, nullable; `expires_at` a date ≥ tomorrow); `mint_feed_key()`; the copy-once session value; `feed.key_mint`; location `/admin/feed-keys/#feed-key-row-{id}`; JSON: `{ok, did, record_id, location, key}` (the raw key once); **external_send**.
- `admin/feed-keys/rotate.php` (`feed_key_rotate`): `key` an id of `mcp_feed_keys`; `rotate_feed_key()`; the copy-once value with `old_expires_at`; `feed.key_rotate`; JSON carries `key` (the new raw) and `old_expires_at`; location as above on the new row; **confirm** (`hx-confirm`).
- `admin/feed-keys/revoke.php` (`feed_key_revoke`): `key`; already revoked → 422; `revoke_feed_key()`; `feed.key_revoke`; **confirm**.
- `admin/price-lists/save.php` (`price_list_save`): `price_list` (an id to change) or a new row; name 1–80 required, percent 0–99.99 (`numeric(5,2)` — two decimals; a third → 422 "two decimals"), notes ≤ 2,000, active; `price_list.save` with `inv_diff()`; location `/admin/price-lists/#price-list-row-{id}`; `HX-Trigger: feedChanged`.
- The screens: `feed-key-list`, `feed-key-add`, `price-list-*`: `require_right('feed.keys')`; `connection-list`: `require_any_right('settings.manage|agents.settings')`; `log_screen_view()` on each (`feed-key-list` with `after.key` when `?key=`).
- The feed's handler (`html/api/v1/availability.php`) is a door, not an action: no CSRF, no session cookie, no `log_screen_view()`; it logs `feed.read` and `feed.rate_limited` as above.

## Manifest rows claimed
Screens (6): `feed-key-list`, `feed-key-add`, `price-list-list`, `price-list-add`, `price-list-edit`, `connection-list`
Actions (4): `feed_key_mint`, `feed_key_rotate`, `feed_key_revoke`, `price_list_save`

| Kind | Rows | Section of the manifest |
|---|---|---|
| Screens (6) | `feed-key-list`, `feed-key-add`, `price-list-list`, `price-list-add`, `price-list-edit` | The availability feed (slice 7) |
| | `connection-list` | Reports, home, admin and tokens (slice 9) — **claimed here**: design §10 lists Connections beside the feed; the page is "who outside reads us", one subject with the keys (reports-admin.md lists it as left to slice 7) |
| Actions (4) | `feed_key_mint` (**external_send**), `feed_key_rotate`, `feed_key_revoke`, `price_list_save` | The availability feed (slice 7) |
The feed endpoint and the five share readers are doors and functions, not actions. Every row of the manifest's slice 7 section is claimed; nothing
of it is left to another slice. `PARTIAL_UPDATE_TARGETS` (`app/partial_update.php`) gains `/admin/price-lists/save.php => ['price_lists',
'price_list', 'mcp_price_lists', 'price_list_id']`; a key is never partially updated (mint, rotate, revoke are whole acts).

## Activity log events
`feed.key_mint`, `feed.key_rotate`, `feed.key_revoke` (entity `feed_key`, `token_id` = the key; `after` as the tool surface's payload rules — **never
the key or its hash**), `price_list.save`, `feed.read` (source `feed`, `token_id`, `after`: `key_label`, `count`, `query_kind`, `query_excerpt` ≤ 120),
`feed.rate_limited` (once per bucket; `after`: `key_label`, `limit_hit`, `retry_after`), `screen.view` (the four admin screens; `feed-key-list` with
`after.key`). `share.read` rows are Phase 4's server's, through db/017's `inv_log_share_read()` (divergence 2; reconciled 2026-10-05). A query's text beyond 120 characters, a key, its hash or a partner's price list applied
to a named customer are in no payload.

## Notifications this slice queues
None.

## The 375 px rule
The keys table and the price lists table render as cards under 992 px (`d-lg-none` card list, `d-none d-lg-block` table — the same rows, one query);
the mint form is a full page with the pinned header; the copy-once box's Copy button and the Rotate/Revoke buttons are ≥ 44 px; the Connections
page's three sections stack; no horizontal scroll (`scrollWidth` = viewport) at 375 × 740.

## Status vocabulary
Key status: live `success`, expiring (rotated, inside the overlap) `warning` with the time, revoked `dark`, expired `secondary`, minter no longer
here `danger`. Today's count `danger` at ≥ 90 % of the day's limit. Consumer kind chips: website `info`, installation `secondary`, partner `success`.
A price list inactive `secondary`. Share reader rows: a read within 24 h `success`, older `secondary`. Ids: `feed-key-list-table`, `feed-key-row-{id}`,
`feed-key-row-{id}-rotate-btn`, `feed-key-row-{id}-revoke-btn`, `feed-key-list-mint-btn`, `feed-key-minted`, `feed-key-minted-value`,
`feed-key-minted-copy-btn`, `feed-key-usage`, `feed-key-usage-row-{bucket_start}`, `feed-key-list-howto`, `feed-key-form`, `feed-key-form-field-{name}`,
`feed-key-form-save-btn`, `feed-key-form-cancel-btn`, `price-list-list-table`, `price-list-row-{id}`, `price-list-row-{id}-edit-btn`,
`price-list-list-add-btn`, `price-list-form`, `price-list-form-field-{name}`, `price-list-form-save-btn`, `price-list-form-cancel-btn`,
`connections-shares`, `connections-readers`, `connections-reader-row-{n}`, `share-reads`, `share-read-row-{activity_id}`, `connections-reads`,
`connections-os-link`, `nav-feed-key-list`, `nav-price-list-list`, `nav-connection-list`.

## Vocabulary
A **feed key** is a bearer the admin mints for one outside consumer (a website, another installation of this application, a partner store) —
never a person's token (`mcp_access_tokens` is a person's, read-only, slice 9/Phase 2); its **consumer kind** says who; a **partner price list**
is a named percentage off retail a partner key answers with; **usage** is `key_usage`'s buckets (minute and day); a **share** is one of the five
records-server tools the kernel's token may call for a sibling application; a **connection** is the super-admin's approval in the OS that lets a
sibling read a share — nothing here creates one; a **share read** is one `share.read` row (db/017: source `mcp`, no actor, `entity_type` `share`, `after = {tool, consumer, count, request_id}`) — a sibling reading us; Inventory reads nothing of a sibling in v1 (`reads[]` is empty), so no row of the other direction exists (reconciled 2026-10-05).

## Out of scope for this slice
The records MCP server and the five share tools themselves, `KERNEL_TOOLS`, `SEARCH_DENY` (Phase 4); the call of the `share.read` logger (db/017's
`inv_log_share_read()` exists; Phase 4's server calls it — divergence 2, reconciled 2026-10-05); the `inventory_feed` **client** connector (another installation as a source — Extended, D7); the keyed
marketplace connectors (Extended); CORS origins per key, a public widget (Extended); the worker's `key_usage_prune` pass (slice 8 — `returns-worker.md`);
the admin's feed settings (`feed_rate_per_minute`, `feed_rate_per_day`, `key_rotation_overlap_hours`, `feed_shows_quantity` — slice 9's
`settings_save`); the exports that call the share readers (slice 9); Help Desk's, the ledger's and Spaces' consumption (G2, HD3, S1 — owed by them).

## Proof (`tests/phase3/slice7/run.sh`: the scratch database `inv_dev7` (`tests/setup_dev.sh` with `INV_DEV_DB=inv_dev7`), the app on 8607 (`php -S` with `tests/dev_router.php`), the fake kernel on 8602; members through `bin/dev_handoff.php`; curl with feed keys, signed action and run tokens; a PHP probe (`shares.php`) calling the share readers; headless Chromium at 375 × 740 and 1280 × 800; `bin/build_action_registry.php --check`; `bin/sync_approvals.php --check`; ≥ 120 checks)
The world (`lib.php` `feed_world()`): the fixture's members (the admin 1, Nora the Buyer 40, Sam 41, Vera 43); two products with six sizes each and
GTINs (one bundle); a warehouse with 2 × Casper Original Queen on hand and 1 allocated, a showroom with a floor model; a supplier source with an
in-stock offer for the Zinus Queen (lead time 4 days) and a reference source undercutting; `feed_shows_quantity` off; the price list **Dealer 20**
(20 %) and **Inactive 5** (inactive); three keys: **Website** (website), **Partner Store** (partner, Dealer 20), **Sister installation** (installation,
`rate_per_day` 3).
- [ ] **Mint (`keys.php`, ≥ 22)**: the admin mints "Website" → the copy-once box shows `feed_` + 48 hex once (a reload does not show it again); the row
  stores only the sha256 (`feed_keys.token_hash`), the raw key appears nowhere in `activity_log` (searched across every payload); the limits 60 / 10,000
  filled by the trigger; `feed.key_mint` logged with `token_id` and the label; a partner key with no price list → 422 naming `price_list`; a website key
  with a price list → 422 "A price list goes on a partner's key."; an inactive price list → 422; a limit of 0 → 422 from `inv_int()`; `expires_at`
  yesterday → 422; Nora (Buyer) → 403 in words; Vera → 403; the list shows the three keys with their chips and "rotated from" empty; `?key=` shows
  the empty usage table.
- [ ] **The feed (`api.php`, ≥ 30)**: `?gtin=` with the Website key → 200, `schema os.inventory-feed/1`, `currency USD`, one result with `sku`, `gtin`,
  `name`, `size`, `retail_price`, `availability in_stock`, `quantity null` (the setting off), `lead_time_days 0`, `ships_how`, **no `partner_price`, no
  `cost`, no `source`** (the keys of the result are exactly the document's ten); `?sku=` exact and case-insensitive; `?q=casper&size=queen` → the
  Queen only; `?q=zinus` → `back_order` with `lead_time_days 4` (the supplier's offer, unnamed); a bundle → its state from the components; a
  discontinued product absent; `feed_shows_quantity` on → `quantity 1` (on hand − allocated − floor); a GTIN typed with 12 digits normalized to 14;
  `?q=` of 121 characters → 422 `invalid` with `fields.q`; no parameter → 422; `gtin` and `sku` together → 422; no bearer, `Bearer nonsense`, a
  person's `mcp_` token (minted by `bin/mint_mcp_token.php`), a revoked key, an expired key → **the same 401 body**; `OPTIONS` from an allowed origin
  → 204 with `Access-Control-Allow-Origin` and no `Allow-Credentials`; from another origin → no CORS headers; a `POST` → 405; `Set-Cookie` never sent;
  every answered call logged `feed.read` with `source feed`, `token_id`, `key_label`, `count`, `query_kind` and an excerpt of at most 120 characters;
  `screen.view` never; a feed call under an eval run? — there is none: a key is not a run.
- [ ] **The rate (`rate.php`, ≥ 18)**: the Website key with `rate_per_minute` set to 5 in SQL: 5 calls → 200; the 6th → 429, `limit minute`,
  `Retry-After` ≤ 60, `feed.rate_limited` logged once; the 7th and 8th → 429 and no further row; the day bucket's `calls` = 5 (the refused calls were
  never charged to the day); the Sister key (`rate_per_day 3`): the 4th call → 429 `limit day`, `retry_after` ≤ 86,400; `inv_rate_ok()` with a fixed
  `p_at` on the next minute → ok again; a key the admin revokes between calls → 401 on the next call (not 429).
- [ ] **Rotate (`rotate.php`, ≥ 12)**: rotate Website → a new key shown once, `rotated_from` = the old id, the old key's `expires_at` = now + 24 h (the
  setting); both keys answer; with the old key's `expires_at` moved back in SQL the old one → 401 and the new one still answers; the new key's usage
  is its own (buckets by `token_id`); rotating a revoked key → the function's words; `feed.key_rotate` logged with both ids; the list marks the old key
  "expiring at".
- [ ] **Revoke (≥ 8)**: revoked → 401 on the next call; `feed.key_revoke` logged; revoking again → 422 "already revoked"; the minter's capability set NULL in
  the mirror (the directory fixture's incremental feed applied to the admin? — to Nora: a key minted by Nora in SQL for the proof) → 401 for her key, the
  admin's keys unaffected; the page marks hers "minter no longer here".
- [ ] **Partner (`partner.php`, ≥ 10)**: the Partner Store key → `partner_price` = retail × 0.80 to two decimals on every result; the Website key → no
  `partner_price` key at all; Dealer 20 made inactive → the partner key answers `partner_price` = retail (the function's rule: the inactive list's
  percent is not read) — then active again; the price list's `keys_using` = 1; `price_list_save` with a duplicate name → 422 "already taken"; 100 %
  → 422; a Buyer → 403.
- [ ] **The shares (`shares.php`, ≥ 20)**: with a closed order (confirmed, shipped, delivered, paid, closed in SQL), a posted receipt and a delivered
  drop-ship line in the period: `share_sales_closed('2026-09-01', '2026-09-30')` → `schema os.inventory-sales/1`, `count 1`, the order with its lines,
  tax, `payments_by_method`, `cogs` unnulled, **no salesperson, no email, no phone, no address** (the keys checked); `share_purchases_received()` →
  `os.inventory-purchases/1` with the receipt under its supplier and the drop-ship with `sales_order_number` and **no ship-to**;
  `share_stock_valuation('2026-09-30')` → `os.inventory-valuation/1` with units and value by location, `by brand` too; a 93-day period → 422 through
  `inv_guard()`; `share_availability_index(['q' => 'casper', 'size' => 'queen'])` → `os.inventory-availability/1` with `availability`, no `quantity`,
  no `partner_price`, no source; `share_customer_orders('maria@example.invalid')` → `os.inventory-orders/1`, `found true`, the line's fulfilment in
  the customer's words ("ships from our supplier"), `order_page_live`, no address, no phone, **no token**; an unknown email → `found false`, `orders []`;
  `declared_shares()` → the five tools of `maludb-os.json` in order.
- [ ] **Connections (`connections.php`, ≥ 8)**: the admin sees the five shares with their documents, "nothing read yet" with no `share.read` rows, then
  — after the proof writes two `share.read` rows through `inv_log_share_read('sales_closed', 'gl', 120, 'req-1')` and `('customer_orders', 'helpdesk', 3,
  'req-2')` (reconciled 2026-10-05) — the grouped readers (2) and the rows (2) with their counts and request ids; `reads` says "nothing in version 1"; the OS link's host says `os.`; Nora (`agents.settings`? no
  — Buyer) → 403 in words; the admin and a member with `settings.manage` → 200.
- [ ] **JSON mode (`json.php`, ≥ 10)**: `feed_key_mint` under a signed action token answers `{ok, did, record_id, location, key}` once and the key never
  again; `feed_key_rotate` answers `key` and `old_expires_at`; `feed_key_revoke` and `price_list_save` answer their facts; `price_list_save` with
  `_partial=1` keeps the notes when only the percent is sent; a 422 answers `{error: {code: invalid, fields}}`; the expert (run token + relay) may mint
  a key through the handler (the category is the registry's — the kernel's hook pauses it as `external_send` on the MCP path, Phase 4 proves the
  pause); `bin/build_action_registry.php --check` reads the six screens and four actions as built; `bin/sync_approvals.php --check` in sync.
- [ ] **375 × 740 and 1280 × 800 (`browser.mjs`, ≥ 12)**: the keys table at 1280 and as cards at 375; the mint form with the price-list row toggling on
  "partner"; the copy-once box with Copy; Rotate through its confirm; the usage table under `?key=`; the price-list form; the Connections page's
  three sections stacked at 375; every control ≥ 44 px, `scrollWidth` = viewport, no console errors.

## Built and proven
**2026-10-10 — BUILT and proven by a worker (Sonnet 5.5)** (`tests/phase3/slice7/run.sh` on the scratch database `inv_dev7`; :8606 is the fixture server only while the world is built — the feed needs no mail): **225 checks green under php -S and 225 under a
real Apache** (under Apache :8601 is the PUBLIC vhost, which carries the feed on its allow-list) — world 4, keys 27, api 46, rate 18, rotate 23, partner 19, shares 30, connections 16, json 21, browser 21 at 375 × 740, 1280 × 800 and JavaScript off; the
registry — **89 screens and 107 actions built** (the six screens and four actions of this slice), **10 placeholders** — and the approvals in step. Every file of "Files" is built, plus `html/assets/js/feed.js` (the price-list row's toggle and Copy — about 30
lines; JavaScript off, the row is always there and the key sits in a read-only field) and `app/views/admin/feed-keys/partials/key-row.php` serving both the table row and the card. The earlier suites re-run green — slices 6, 5, 4, 3, 2, 1, Phase 2 (its
placeholder count 10 and its examples moved to `/admin/dispatches`, `/returns/`, `/reports/`, `/admin/settings`; its vhost check now reads the feed's 401 and 405 where it read 404; its browser proof opens the Dispatches placeholder where it opened Connections; slice 6's json proof, which pinned the registry's built counts to 83 and 103, now says "at least" — a later slice only adds), Phase 0 (42 + 517 + 511), the Phase 1 claim checks — and the installer's plan is
clean (57 steps). The spec's "Open questions" stayed empty: nothing stopped the slice. **One migration: `db/021_bundle_state_in_the_feed.sql`.**

**Found and fixed (not questions):**
- **A bundle had no state of its own in the feed** (db/014's `inv_feed_answer()`, and db/016's `inv_share_availability_index()` which repeats its loop): both read `inv_own_stock()` and `inv_best_lead_time()` of the variant itself, which for a set (the Queen set: a mattress and a foundation) is
  nothing — a set holds no stock — so the feed and the availability share said `out_of_stock` for a set whose parts were on the shelf. Find and the order form get it right through `inv_availability()` / `inv_bundle_availability()`; the spec's proof reads "a bundle → its state from the
  components" and the design says the feed answers from the same SQL as Find. db/021 adds one internal helper, `inv_variant_supply(variant)` (a set's available count is its scarcest component's — sellable available ÷ the component's quantity —, its lead time the longest of the components' and
  NULL when any component can be supplied by nobody, 0 when the set is in stock; a single variant as before), with no caller check (the feed's key and the kernel's token have no reader), and copies both functions' bodies with that one line changed.
- **`inv_feed_answer()` builds `partner_price` as a null for every key** (`jsonb_build_object('partner_price', CASE … END)` keeps a null member), but the document says "`partner_price` only for a partner key" and the proof "no `partner_price` key at all". `feed_answer()` leaves the member out
  of every result for a key that is not a partner's (a partner key whose variant has no retail keeps it null). Done in PHP, not in SQL: the function is the writer's alone and the database answer is not wrong, only wider than the document.
- **`jsonb` sorts an object's keys by length** (`sku, gtin, name, size, currency, quantity, …`): `feed_answer()` re-orders each result into the document's order (sku, gtin, name, size, retail_price, currency, partner_price, availability, quantity, lead_time_days, ships_how).
- **`log_screen_view()` takes no `after`**: the keys page with `?key=` logs through `feed_screen_view()` (the same row, with the key as entity, `token_id` and `after.key`); the three other admin screens use the same helper.
- **`emit_action_status()` writes its data into `X-Action-Data` under an action token**: the raw key is therefore never given to it — `feed_key_done()` answers a JSON caller through `respond_saved()` alone and a browser through the session.
- **The expert is a Sales agent in the fixtures** (role `user`, no `feed.keys`): the proof shows its 403 in words, then gives its mirror row the admin role for the one step that proves "an agent mints a key through the handler" and puts it back. The spec's "the expert may mint" holds for an agent granted the admin role.
- **The fixtures' figures, not the spec's** (the world is slice 4's/5's `order_world()`, not the spec's Casper Original): the Queen has 4 on hand and 1 allocated, so `feed_shows_quantity` on says 3, not 1; "`?q=zinus` → back_order lead 4" became the Cal King (back_order, Malouf's 5 days) and the King (a floor model only: back_order, Zinus' 3 days); the "Queen only" query is `q=cloudrest&size=queen` (the mattress, its foundation and the set).

**Decisions taken while building (not questions):**
- **Table and cards carry different ids**: `feed-key-row-{id}` (table row, with `-rotate-btn`, `-revoke-btn`, `-status`, `-today`, `-label`, `-from`) and `feed-key-card-{id}` (the phone's card with the same suffixes); the same for the price lists (`price-list-row-{id}` / `price-list-card-{id}`). Both are rendered, one is hidden by CSS (`d-none d-lg-block` / `d-lg-none`), so an id is never repeated.
- **The key's state** (`feed_key_state()`): revoked › expired › minter gone (`minter no longer here`, danger — the minter is no longer in `mcp_members`, which lists active admitted members only) › expiring (a successor exists and `expires_at` is set: "expiring at <time>", warning) › live. A key with a future `expires_at` and no successor is simply live.
- **Rotating a key that has run out** (expired, not revoked) is refused with the same sentence as a revoked one ("Only a live key is rotated"): the function itself only checks `revoked_at`, and a rotation of a dead key would hand out a key that answers while its parent does not.
- **A partner's key names a price list at mint** ("A partner's key names the price list it answers with."): the spec's table says required for partner; the CHECK only forbids the reverse.
- **The feed's `size` is ignored with `gtin` and `sku`** (it qualifies a `q`); two of the three, none, an empty value or an array are a 422 `invalid`, with `fields` naming the parameters at fault (`query` when none was given). `sku` is exact (a prefix finds nothing).
- **A minter who lost the right to read** (`inv_feed_answer()` → `insufficient_privilege`) answers the same 401.
- **The Connections page opens to `settings.manage` or `agents.settings`** (`require_any_right`); the menu item stays `settings.manage` (sso-shell's), so a holder of only `agents.settings` reaches the page by its address. Its `share_reads()` and `share_readers()` read `activity_log` as the writer. The page's section ids: `connections-shares`, `connections-readers`, `share-reads`, `connections-reads`, `connections-os-note` (with the link `connections-os-link`).
- **`declared_shares()` / `declared_reads()` live in `app/features/connections/queries.php`** (the spec names them under both shares and connections); `declared_shares()` adds each share's `document`.
- **The feed drops its session** (`feed_drop_session()`): no `Set-Cookie`, nothing saved, even on a 401, a 405 or a 422.
- **`feed.read` and `feed.rate_limited` carry `actor_member_id` NULL explicitly** (the minter is the acting member for the database, not the actor of the row).
- **The expiry** is "a date from tomorrow on" in the business's time zone; stored as that day's last second (`23:59:59`), so a key "expires on Oct 13" works through the 13th.
- **Limits above the settings' defaults are allowed** (only the admin mints); the bounds are the table's (1–100000 a minute, 1–10000000 a day).
- **Day buckets are UTC days** (`date_trunc('day', now())` in a UTC session, as `inv_rate_ok()` makes them); the usage page says "Day (UTC)".
- **`price_list_save` logs the notes' change by name only** (`changed: ['notes']`), never their words; creating a list logs its name, percent and active flag.
- **`API_CORS_ORIGINS`** is appended to the scratch environment by `run.sh` (`https://shop.example.invalid`) for the CORS checks.


## Decisions recorded (the DECISIONs above, in one place)
1. The feed admits feed keys only — never a session, never a person's `mcp_` token; one 401 body for every failure.
2. `share.read` for the kernel's calls is Phase 4's server's to log through db/017's `inv_log_share_read()` (source `mcp`, no actor, `entity_type` `share`, `after = {tool, consumer, count, request_id}` — reconciled 2026-10-05); this slice's readers log nothing.
3. Usage on the screen: day buckets of the last 35 days; the minute buckets of the last hour on request — what `inv_prune_key_usage()` keeps.
4. A key's minter is for life (the schema's `member_id`); a minter who left makes the key dead — the admin revokes and mints anew, never re-parents.
5. The rate is counted before the query is parsed: a malformed flood still counts against the key.
6. The Connections page is slice 7's (claimed from the manifest's slice 9 section); its `share.read` rows are db/017's shape, read by `after->>'consumer'`, `after->>'tool'`, `after->>'count'` (reconciled 2026-10-05).
7. `feed_authenticate()` and `feed_rate_limit()` live in the kit's `app/api/bootstrap.php` beside `api_authenticate()` — the feed is the token API's
   second door, not a new module; the kit's `api_authenticate()` (an `api`-scope personal token) stays unused by Inventory in version 1.
8. `PARTIAL_UPDATE_TARGETS` gains the price list only; keys are whole acts.

## Open questions
(none)

# Build spec: Phase 2 — sign-on, the mirror, the shell (before any slice)

What exists at the end: a person clicks Inventory on the launcher — or types `inventory.<domain>` on a phone — and lands, signed in,
on a home that looks finished though it holds no product yet: the sidebar lists Home · Find · Catalog · Stock · Sources · Orders ·
Customers · Purchasing · Returns · Reports · Admin, each shown only to a role that may open it; the bell; the command bar answering
through the kernel; the bottom tab bar at 375 px; every other menu item a placeholder naming the slice that builds it. The mirror
refreshes every minute; a revoked grant shuts every door within a minute; cost is hidden from a Viewer; every request is logged. The
kit is in the repository already (Phase 0: Consultant Tracking's identity, roles and sign-on files rewritten to rights with cost as the
wall, the MCP common files, the HTTP helper and sync; proven without a kernel by `tests/phase0/run.sh`, 42 checks); this phase wires
the shell around it and proves it.
Schema: `members` (with `is_agent`, `is_external`, `roles`), `departments`, `department_members`, `directory_sync_state`, `sso_nonces`,
`member_sessions`, `app_current_member_id()`, `app_is_active_member()`, `app_is_super_admin()`, `app_member_kind()` (db/001);
`activity_log`, `activity_ingest_state` (db/002); `mcp_access_tokens` + `mcp_resolve_token()` (db/003); `inv_rights`, `inv_roles`,
`inv_role_rights`, `inv_member_roles()`, `inv_has_right()`, `inv_is_admin()`, `inv_is_member_here()`, `mcp_app_roles` (db/004);
`inv_settings`, `inv_sees_cost()`, `inv_sees_receipt_cost()` (db/005); `attachments` (db/006); `notifications`, `notification_prefs`
(db/013); `mcp_members`, `mcp_departments`, `mcp_settings`, `mcp_attachments`, `mcp_notifications`, `mcp_notification_prefs`,
`mcp_access_tokens_mine`, `mcp_activity_log` (db/015). Never modify them. **The database is the referee**: who belongs here
(`inv_is_member_here()`), what a role may do (`inv_has_right()`), whether cost shows (`inv_sees_cost()`) — PHP asks, never re-decides;
`has_right()`, `require_right()`, `is_inv_admin()`, `sees_cost()` in `app/auth.php` are the one-call mirrors.

## Files
**Already in the repository (Phase 0, proven):** `app/bootstrap.php` (env from `config/.env` or `INV_DEV_ENV`, session cookie `INVSID`,
`json_mode_begin()`, the action-token / approval-replay path, the per-request mirror re-check), `app/db.php`, `app/http.php`
(`render_screen()`, `view()`, `e()`, `refuse()`, `emit_action_status()`, `respond_saved()`, `respond_invalid()`, `saved_go()`,
`hx_location()`, `hx_trigger()`, `csrf_field()`, `verify_csrf()`), `app/handler.php` (`inv_handler_begin()`, `inv_guard()`,
`inv_refuse_fields()`, `inv_done()`, `inv_diff()`, `inv_land()`, `inv_notice()`, `req_val()`, `request_list()`, `inv_yes()`, `inv_int()`,
`inv_ref()`, `members_for_pick()`, `find_live_departments()`, `require_any_right()`), `app/auth.php` (`has_right()`, `require_right()`,
`is_inv_admin()`, `require_admin()`, `require_human()`, `require_visible()`, `sees_cost()`, `is_member_here()`, `RIGHT_WORDS`, the token
verifiers, `run_facts()`, `kernel_call()`), `app/directory.php` (the mirror appliers incl. `mirror_apply_roles()` and `access[]`),
`app/activity.php` (`log_activity()` with the audit keys `source_id`, `sales_order_id`, `purchase_order_id`, `location_id`, `token_id`;
`log_screen_view()`), `app/mail.php`, `app/partial_update.php`, `app/api/bootstrap.php`, `app/api/kernel.php` (`ask_assistant()`,
`assistant_refresh_events()`, `kernel_send_text()`, `kernel_read()`), `app/sources/*` (slice 3's connectors — untouched here),
`app/views/shared/{flash,message}.php`, `app/views/sso/refused.php`, `app/views/home/placeholder.php` (replaced by this phase),
`html/index.php` (the Phase 0 placeholder — made real here), `html/sso.php`, `html/sso/logout.php`, `html/logout.php`, `html/api/v1/health.php`,
`html/manifest.webmanifest`, `html/assets/` (the design system verbatim + `app-overrides.css`, `htmx.min.js`, `select2`), `bin/directory_sync.php`
(`--full`, `--from-file`), `bin/mint_mcp_token.php`, `bin/build_action_registry.php`, `bin/sync_approvals.php`, `bin/dev_handoff.php`,
`bin/dev_directory.json`, `bin/worker.php` (every pass a stub), `mcp/activity_ingest.py`, `mcp/db.py`, `mcp/server_common.py`,
`tests/dev_router.php`, `tests/setup_dev.sh`, `tests/fake_kernel.php`, `tests/fake_maludb.php`, `tests/fake_malumail.php`, `deploy/*`.
**Phase 2 makes real (exactly these):**
- `app/features/shell/nav.php` — the ONE menu table: `nav_groups()`, `nav_tabs()`, `nav_item()`, `nav_has_right()`, `role_badge()`,
  `pg_text_array()`, `hx_link()`, `back_link()`, `here_url()`, `with_back()`, `NAV_SLICES`, `render_nav_stub()` (Spaces'
  `app/features/shell/nav.php` copied and rewritten to Inventory's menu and rights — the table below).
- `app/features/shell/queries.php` — `bell_count()`, `business_name()`, `my_roles()`.
- `app/features/home/queries.php` — `home_summary()`.
- `app/features/settings/{queries,present}.php` — prefs, notifications, tokens.
- `app/features/activity/{queries,present}.php` — the trail.
- `app/attachments.php` — the attachment helper every later slice stores files with (`attachment_store()`, `attachment_delete()`,
  `attachment_path()`, `inv_can_see_attachment()`) and `html/files.php` (the gated door `/files/{id}` and `/files/{id}/thumb`). **These four
  signatures (Query functions, below) are the one attachment contract: returns-worker.md DECISION 9 and catalog.md name them** (reconciled 2026-10-05).
- `app/views/layout.php` (the nxl shell), `app/views/shared/{header,sidebar,tab-bar,assistant-bar,assistant-reply,notice}.php`,
  `app/views/home/dashboard.php`, `app/views/settings/{index,notifications,tokens}.php`, `app/views/activity/trail.php`.
- `html/index.php` (screen `home`), `html/login.php` (302 to `OS_LAUNCHER_URL/launcher?app=inventory` — never a login form),
  `html/notifications.php` (screen `notifications`) + `html/settings/notifications/read.php`, `html/settings/index.php` (screen `my-settings`) +
  `html/settings/prefs.php`, `html/settings/tokens/{index,mint,revoke}.php` (screen `tokens`), `html/trail.php` (screen `trail`),
  `html/assistant/ask.php` (the command bar's POST), `html/files.php`.
- The placeholders, one controller per not-yet-built menu item, each exactly `render_nav_stub('<nav id>', '<slice>', '<right>')`:
  `html/find.php`, `html/products/index.php`, `html/brands/index.php`, `html/product-types/index.php`, `html/catalog/gaps.php`, `html/catalog/import.php`,
  `html/stock/index.php`, `html/stock/movements.php`, `html/stock/floor-models.php`, `html/locations/index.php`, `html/receipts/index.php`,
  `html/adjustments/index.php`, `html/transfers/index.php`, `html/counts/index.php`, `html/sources/index.php`, `html/sources/templates.php`,
  `html/matching/index.php`, `html/supplier-items/index.php`, `html/watches/index.php`, `html/orders/index.php`, `html/orders/today.php`,
  `html/shipments/index.php`, `html/customers/index.php`, `html/purchasing/index.php`, `html/suppliers/index.php`, `html/returns/index.php`,
  `html/proposals/index.php`, `html/reports/index.php`, `html/exports/index.php`, `html/admin/settings.php`, `html/admin/sequences.php`,
  `html/admin/tax-rates/index.php`, `html/admin/reason-codes/index.php`, `html/admin/feed-keys/index.php`, `html/admin/price-lists/index.php`,
  `html/admin/agents.php`, `html/admin/dispatches.php`, `html/admin/connections.php`. The registry builder reads a controller that calls
  `render_nav_stub()` as unbuilt; removing the call is the slice's first step.
- `tests/phase2/` — `servers.sh`, `lib.php`, `sso.php`, `gates.php`, `sync.php`, `ingest.php`, `kernel_compat.php`, `vhost.php`, `browser.mjs`,
  `run.sh` (Spaces' suite copied and rewritten: `sp_` → `inv_`, the ports 8601–8607, the fixture of `bin/dev_directory.json`).
- `storage/` (`attachments/`, `exports/`, `http-cache/`) `www-data`-owned, mode 0770, before `apply`: already in `deploy/ROOT_STEPS.sh` (step 0b,
  db/016's builder); Phase 2 verifies it (the attachment door needs it first) — reconciled 2026-10-05.

## Inventory is not Spaces
- **Nobody is in by default** (D3): the installer grants no standing department; a person holds a role because a super-admin granted one.
  The base role's key is `user` (Sales). The header's badge shows the highest role held: Inventory admin › Buyer › Warehouse › Sales › Viewer;
  a super-admin shows "Super-admin". `role_badge()` reads `members.roles` through `inv_member_roles()` — the effective roles, so a member the
  kernel sent no roles for shows the role their capability implies.
- **Cost is the wall, not a menu.** The shell hides nothing by cost; the views null it. `sees_cost()` is read once per request and passed to
  the layout as `$seesCost` so a partial may say "cost withheld" instead of an empty cell.
- **Two panes, not three.** At 1280 px: the sidebar (280 px) and the main pane. At 375 px: one pane and the bottom tab bar **Home · Find ·
  Orders · Stock · Me**; the sidebar opens as the offcanvas from the header's menu button (`a#mobile-collapse`, the theme's own).
- **The menu is data** (`nav_groups()`), every item gated by a right; the sidebar and the tab bar read the same table, so an item and the
  right that opens its screen never disagree. The table, exactly (`[id, url, icon, label, right]`; `|` = any one of):

| Group | id | URL | Icon | Label | Right |
|---|---|---|---|---|---|
| Inventory | `home` | `/` | feather-home | Home | inventory.read |
| | `find` | `/find` | feather-search | Find | inventory.read |
| Catalog | `product-list` | `/products/` | feather-package | Products | inventory.read |
| | `brand-list` | `/brands/` | feather-tag | Brands | inventory.read |
| | `product-type-list` | `/product-types/` | feather-grid | Product types | inventory.read |
| | `catalog-gaps` | `/catalog/gaps` | feather-alert-circle | Catalog gaps | catalog.write |
| | `catalog-import` | `/catalog/import` | feather-upload | Import | catalog.write |
| Stock | `stock-levels` | `/stock/` | feather-layers | Levels | inventory.read |
| | `location-list` | `/locations/` | feather-map-pin | Locations | inventory.read |
| | `movement-list` | `/stock/movements` | feather-list | Movements | inventory.read |
| | `receipt-list` | `/receipts/` | feather-download | Receive | stock.receive |
| | `adjustment-list` | `/adjustments/` | feather-edit-3 | Adjust | stock.adjust |
| | `transfer-list` | `/transfers/` | feather-repeat | Transfers | stock.transfer |
| | `count-list` | `/counts/` | feather-check-square | Counts | stock.count |
| | `floor-model-list` | `/stock/floor-models` | feather-home | Floor models | inventory.read |
| Sources | `source-list` | `/sources/` | feather-rss | Sources | inventory.read |
| | `source-template-list` | `/sources/templates` | feather-book-open | Templates | sources.write |
| | `match-queue` | `/matching/` | feather-link | Match queue | listings.match |
| | `supplier-item-list` | `/supplier-items/` | feather-file-text | Price sheets | inventory.read |
| | `watch-list` | `/watches/` | feather-eye | Watches | watches.own |
| Orders | `order-list` | `/orders/` | feather-shopping-cart | Orders | inventory.read |
| | `fulfilment-today` | `/orders/today` | feather-truck | Fulfilment today | inventory.read |
| | `shipment-list` | `/shipments/` | feather-send | Shipments | inventory.read |
| | `customer-list` | `/customers/` | feather-users | Customers | inventory.read |
| Purchasing | `purchase-order-list` | `/purchasing/` | feather-clipboard | Purchase orders | inventory.read |
| | `supplier-list` | `/suppliers/` | feather-briefcase | Suppliers | inventory.read |
| Returns | `return-list` | `/returns/` | feather-corner-up-left | Returns | inventory.read |
| | `proposal-list` | `/proposals/` | feather-inbox | Buyer proposals | reports.read\|agents.settings |
| Reports | `report-list` | `/reports/` | feather-bar-chart-2 | Reports | reports.read |
| | `export-list` | `/exports/` | feather-download-cloud | Exports | reports.read\|exports.all |
| Me | `my-settings` | `/settings/` | feather-settings | My settings | inventory.read |
| | `notifications` | `/notifications` | feather-bell | Notifications | inventory.read |
| | `tokens` | `/settings/tokens/` | feather-key | Tokens | inventory.read |
| | `trail` | `/trail` | feather-activity | My trail | inventory.read |
| Admin | `admin-settings` | `/admin/settings` | feather-sliders | Settings | settings.manage |
| | `sequence-list` | `/admin/sequences` | feather-hash | Sequences | sequences.manage |
| | `tax-rate-list` | `/admin/tax-rates/` | feather-percent | Tax rates | settings.manage |
| | `reason-code-list` | `/admin/reason-codes/` | feather-bookmark | Reason codes | settings.manage |
| | `feed-key-list` | `/admin/feed-keys/` | feather-key | Feed keys | feed.keys |
| | `price-list-list` | `/admin/price-lists/` | feather-percent | Price lists | feed.keys |
| | `agent-list` | `/admin/agents` | feather-cpu | Agents | agents.settings |
| | `dispatch-list` | `/admin/dispatches` | feather-zap | Dispatches | agents.settings |
| | `connection-list` | `/admin/connections` | feather-share-2 | Connections | settings.manage |

  The ids ARE the manifest's screen ids; `NAV_SLICES` names the slice of every placeholder: catalog items slice 1; stock items 2; sources
  items 3; `find`, `watch-list` 4; orders, customers, fulfilment, shipments 5; purchasing, suppliers 6; feed keys, price lists 7; returns,
  proposals 8; reports, exports, admin settings, sequences, tax rates, reason codes, agents, dispatches, connections 9.
- **The tab bar** (`nav_tabs()`): Home `/`, Find `/find`, Orders `/orders/`, Stock `/stock/`, Me `/settings/` — every one `inventory.read`.
- **Presence and status are not here** (Spaces' `last_seen_at`, `status_set` have no column in db/001): no heartbeat, no status line.
- **The attachment door** `/files/{id}` is rewritten by the vhost already; `html/files.php` answers 401 anonymous, 404 when
  `inv_can_see_attachment()` says no (today: `inv_is_member_here()` — every record is every reader's; a later slice narrows if its view does),
  streams from `storage/attachments/` with the stored `mime_type`, `Content-Disposition: inline` for images and PDFs, `attachment` otherwise;
  `?thumb=1` streams the same file (no resizing in version 1 — DECISION: a thumbnail is the browser's `<img>` at card size).

## The receiver (`/sso`) — in order (as the kit does it; nothing changes here)
1. `verify_sso_token($token, app_key())` and `verify_sso_claims($claims)` — constant-time, both must pass, member ids must match.
2. Expiry, audience (`inventory`), status, capability, then the nonce: `INSERT INTO sso_nonces … ON CONFLICT DO NOTHING`; zero rows = replay → refuse.
3. In one transaction: `mirror_apply_member()` (the row with `capability`), `mirror_apply_roles()` from `claims.roles` (only keys `inv_roles` knows).
4. Open the session: `session_regenerate_id(true)`, `$_SESSION['member_id']`, fresh CSRF token; `member_sessions` row; `db_apply_context()`.
5. `log_activity('member.sign_on', 'member', $id, ['after' => ['capability', 'roles']])`; redirect to `/`.
6. Any failure: one page `sso/refused.php` (*"This sign-on link has expired. Open Inventory from app.<domain> again."*), `member.sign_on.refused`
   with `after.reason` (token, claims, status, capability, replay, mirror) — never shown.

## Every request (bootstrap, as the kit does it)
- Cookie `INVSID`, `SameSite=Lax`, `Secure` when HTTPS, `HttpOnly`, strict mode. A signed-in request re-checks the mirror row and the session
  list; otherwise the session is destroyed (`ended_by = 'directory'`) and the visitor sent to the launcher with `?app=inventory`.
- Action token (`X-Action-Token` + `X-Action-Relay`, or `X-Approval-Replay`): acts as that member for one request; `Set-Cookie` removed; an id
  with no mirror row is refused and logged `member.refused`; a known, active agent with no capability is admitted at first contact only when the
  kernel's run-facts call vouches for it. `SET app.member_id` before any query; `log_screen_view()` on every rendered GET.
- `/sso/logout` (POST from the kernel): `verify_sso_logout_notice` → every session of the member ended (`ended_by = 'kernel'`); 204 always.

## The shell
- Design-system skeleton (`layout-skeleton.html`), exactly. **Phone first**: Find, an order and receiving are read at 375 px (their slices); the
  catalog, sources and reports at 1280 px. The header: the menu button (375), the business name (`inv_settings.business_name`, else `app_name()`),
  the command bar, the bell with its unread count (`mcp_notifications` where `read_at IS NULL`), my avatar with the role badge; `#page-content`
  is the HTMX target (`.nxl-content`); CSRF meta `<meta name="csrf-token" id="csrf-token-meta">` + the `htmx:configRequest` listener stamping
  `X-CSRF-Token`; `htmx:afterSwap` re-init (select2, tooltips); the layout re-stamps `data-screen` / `data-entity` / `data-record-id` on
  `#page-content` and the menu highlight from `X-Screen` / `X-Entity` / `X-Record-Id` (`app/http.php` `render_screen()` sends them).
- **The sidebar** (from 992 px, and the offcanvas on a phone): the groups of the table in order, each item a link (`hx_link()`: `hx-get`,
  `hx-target="#page-content"`, `hx-push-url` set to the item's own canonical URL — never `true`), the current one highlighted, a group with no
  visible item not rendered; the Admin group only for a holder of one of its rights.
- **The command bar** (`#assistant-bar`): dictation-friendly input with Send shown only while in use; POST `/assistant/ask` → `ask_assistant()`
  (`app/api/kernel.php`: the kernel's chat endpoint as the person, agent `expert`, `utterance`, `screen`, `entity`, `record_id`,
  `conversation_id` kept in the session), the reply rendered in `shared/assistant-reply.php`, a `navigate` answer that is a local path followed
  by `HX-Location` + `HX-Push-Url`, every entity changed fired as `{entity}Changed` (`assistant_refresh_events()`), a 202 polled by
  `ask_assistant()` itself (up to 55 s), the kernel down or refusing said in the bar in words. `require_human()`: an agent never uses the bar.
  Nothing here calls a model.
- **Home** (`home_summary()` — every region's empty state names its slice): the morning note's seven headings (8), lines at risk (8), pulls
  failed or blocked (3), unmatched listings (3), purchase orders awaiting acknowledgment (6), today's deliveries and pickups (5); for Sales my
  open orders (5); for Warehouse to receive, to pick, to count (2, 5); for the admin feed usage and dispatches (7, 8). Each region a card
  `#home-{region}` with a count that opens its list; null until its slice fills the key.
- **The bell**, **My settings** (prefs: email on/off, text on/off, the kinds, the text kinds, the time zone shown read-only — it is the
  directory's), **Tokens** (`token_mint`, `token_revoke`), **Trail** (`trail`: my own rows, or a record's by `product`, `variant`, `source`,
  `order`, `purchase_order`, `member`) are real in this phase.
- Every screen: `shared/header.php` (title, crumbs, Back from `?back=` through `back_link()`, the actions), the flash region `#flash`, no modal
  anywhere; forms are pages; `hx-confirm` only on destructive controls.

## Screens
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `home` | `/` | the regions above, each a card with an empty state naming its slice; `data-screen="home"` — the placeholder; the row is slice 9's (reconciled 2026-10-05) |
| `notifications` | `/notifications?unread=` | my notices newest first (`mcp_notifications`): kind chip, title, body, when, read or not; **Mark read** per row and **Mark all read**; a row links to its record (`record_type` → the route map in `nav.php` `record_url()`) |
| `trail` | `/trail?product=&variant=&source=&order=&purchase_order=&member=&page=` | my own activity in words (`activity_sentence()`), newest first, 50 a page; with a record param, that record's rows (`mcp_activity_log` by `entity_type`/`entity_id`, or by the audit key `source_id` / `sales_order_id` / `purchase_order_id`); `member` for the admin or `reports.read` (another person's trail — the tool surface's DECISION 17) — rendered here; the row is slice 9's (reconciled 2026-10-05) |
| `my-settings` | `/settings/` | the prefs form: email_enabled, text_enabled, kinds[] (the thirteen notification kinds with their sentences — `NOTICE_KINDS`), text_kinds[], the time zone read-only with "changed in the operating system"; the role badge and the roles held |
| `tokens` | `/settings/tokens/` | my tokens as a table (`mcp_access_tokens_mine`): label, scope, last used, expires, revoked; **Mint** (label, scope mcp/api) and the minted value shown ONCE in a `warning` box on the next render (`$_SESSION['minted_token']`, cleared after); **Revoke** per live row (`hx-confirm`) |

Ids: `shell-sidebar`, `shell-tabbar`, `assistant-bar`, `assistant-input`, `assistant-send`, `assistant-reply`, `bell`, `bell-count`,
`header-role-badge`, `header-business-name`, `page-content`, `flash`, `nav-{id}` (a sidebar item), `tab-{id}` (a tab), `home-{region}`,
`notification-row-{id}`, `notification-row-{id}-read`, `notifications-mark-all`, `trail-list`, `trail-row-{id}`, `prefs-form`,
`prefs-form-field-email-enabled`, `prefs-form-field-text-enabled`, `prefs-form-field-kinds-{kind}`, `prefs-form-field-text-kinds-{kind}`,
`tokens-table`, `token-row-{id}`, `token-row-{id}-revoke`, `token-mint-form`, `token-mint-form-field-label`, `token-mint-form-field-scope`,
`token-minted-value`.

## Query functions (signatures fixed)
- `home_summary(PDO, int $memberId): array` (the keys of §9 Home, every value null until its slice; `app/features/home/queries.php`)
- `bell_count(PDO, int $memberId): int` · `business_name(PDO): string` · `my_roles(PDO, int $memberId): array` (`[{role_key, name}]` through `inv_member_roles()`; `app/features/shell/queries.php`)
- `my_prefs(PDO, int $memberId): array` (`notification_prefs` row or the table's defaults, `saved` false) · `save_prefs(PDO, int $memberId, array $fields): array` (`['before', 'after']` of the changed keys; INSERT … ON CONFLICT) · `NOTICE_KINDS` (the thirteen kinds of db/013 with one sentence each)
- `find_my_notifications(PDO, int $memberId, int $page, bool $unreadOnly): array` (`mcp_notifications`, 50 a page) · `mark_notifications_read(PDO, int $memberId, ?int $id): int` (rows marked)
- `my_tokens(PDO, int $memberId): array` (`mcp_access_tokens_mine`) · `mint_token(PDO, int $memberId, string $label, string $scope): array` (`['id', 'raw']`; `mcp_` + 48 hex, sha256 stored — as `bin/mint_mcp_token.php` does) · `revoke_token(PDO, int $memberId, int $id): void` (own row, `revoked_at = now()`)
- `find_my_activity(PDO, int $memberId, int $pageNo, array $filters): array` · `find_record_activity(PDO, string $type, int $id, int $limit = 50): array` · `find_activity_by_key(PDO, string $key, int $id, int $limit = 50): array` (`source_id` / `sales_order_id` / `purchase_order_id`) · `activity_sentence(array $row): string` (`app/features/activity/queries.php`, `present.php`)
- `attachment_store(PDO, string $recordType, int $recordId, array $file, int $by): int` (a `$_FILES` entry or `['tmp_name', 'name', 'type', 'size']`; the size against the lesser of `inv_settings.max_attachment_bytes` and `ATTACHMENT_MAX_BYTES`, the MIME allow-list by sniffing — returns-worker.md DECISION 16, reconciled 2026-10-05; sha256; `storage/attachments/<record_type>/<record_id>/<id>-<safe name>`; the row) · `attachment_delete(PDO, int $id): void` (the row and the file) · `attachment_path(array $row): string` · `inv_can_see_attachment(PDO, int $id): ?array` (the `mcp_attachments` row or null)

## Handlers (every one: `inv_handler_begin()`; the gate; `inv_guard()`; `log_activity`; `inv_done()`)
- `settings/prefs.php` (`prefs_save`): own; `prefs.save` (`after`: the fields changed — `inv_diff()`); location `/settings/`; refresh `prefsChanged`.
- `settings/notifications/read.php` (`notification_read`): own; `notification` empty marks all; `notification.read` (`after.count`); location `/notifications`; refresh `notificationChanged`.
- `settings/tokens/mint.php` (`token_mint`): own, `require_human()` (an agent never mints); `token.mint` (`after`: `label`, `scope` — never the value); the raw token in the JSON reply once (`token`) and in `$_SESSION['minted_token']` for the browser once; location `/settings/tokens/#token-row-{id}`; refresh `tokenChanged`.
- `settings/tokens/revoke.php` (`token_revoke`): own; `token.revoke`; location `/settings/tokens/`.
- `logout.php` (exists): POST + CSRF; `end_session('member')`; `member.sign_out`; 302 to the launcher.
- `assistant/ask.php`: `require_human()`; `assistant.ask` (`after`: `length`, `screen`, `record_id` ≤ 40 chars, `kernel` status, `run_id`, `status`, `actions` count, `navigate`, `cost` — never the words).
- `files.php` (GET): 401 anonymous, 404 unseen, the stream; logs nothing (a read).

## Manifest rows claimed
Screens (3): `notifications`, `my-settings`, `tokens`
Actions (4): `prefs_save`, `notification_read`, `token_mint`, `token_revoke`
No agent approvals. The "Home, me and the shell" section's other two screens, `home` and `trail`, are **slice 9's rows** (reports-admin.md): Phase 2
renders their placeholders (the empty-state regions, the trail's first rendering) and claims neither (reconciled 2026-10-05).

| Action | File | Log | Who |
|---|---|---|---|
| `prefs_save` | `/settings/prefs.php` | `prefs.save` | own |
| `notification_read` | `/settings/notifications/read.php` | `notification.read` | own |
| `token_mint` | `/settings/tokens/mint.php` | `token.mint` | own |
| `token_revoke` | `/settings/tokens/revoke.php` | `token.revoke` | own |

Every other screen of the manifest answers its placeholder (200 with the empty state after the right has been checked; 403 in the right's
words; 501 `not_built` to JSON and to a POST).

## Activity log events
`member.sign_on`, `member.sign_on.refused`, `member.sign_out`, `member.refused`, `directory.sync` (the timer), `prefs.save`,
`notification.read`, `token.mint`, `token.revoke`, `assistant.ask`, `screen.view` (every rendered GET, the placeholders included),
`worker.pass`. **No row carries a token's value, an utterance's words, or an email body.**

## Notifications this phase queues
None. It reads the bell and saves the preferences; the first producers are slice 3 (`pull_failed`) and slice 4 (`watch`); slice 8 sends.

## Status vocabulary
Role badge: Super-admin `danger`, Inventory admin `danger`, Buyer `primary`, Warehouse `info`, Sales `secondary`, Viewer `light`.
Notification kind chips: `watch` `primary`, `line_at_risk` `danger`, `pull_failed` / `pull_blocked` `warning`, `po_ack` / `po_tracking` `info`,
`po_decline` `danger`, `return` `secondary`, `morning_note` `dark`, `mention` `primary`, `order` `info`, `agent_drafted` `secondary`,
`unmatched` `warning`. Token: live `success`, revoked `dark`, expired `secondary`.

## Mobile rule (375 × 740)
One pane; the tab bar fixed at the bottom above the assistant bar; the sidebar an offcanvas; every control ≥ 44 px; `scrollWidth` =
viewport; the notifications list and the trail as stacked rows, the tokens table inside `.table-responsive`; the prefs form one column.

## Vocabulary
*shell* (the layout around `#page-content`), *placeholder* (a screen its slice has not built — 200, an empty state), *the bar* (the command
bar), *the bell* (unread notifications), *a right* (`inv_rights`; what opens a menu item), *the badge* (the highest role held), *the trail*
(my activity in words), *a token* (a person's own MCP token; shown once).

## Out of scope for this phase
Every catalog, stock, source, find, order, customer, purchasing, return, report and admin screen (slices 1–9): their menu items are
placeholders naming the slice; the home's regions (slice 9 fills them); the public doors `/o/`, `/s/` and the feed `/api/v1/availability`
(slices 5, 6, 7 — the vhost's rewrites land on 404 until then); the two MCP servers (Phase 4 — the proxies answer 502); the worker's passes
(their slices); sending a notification (slice 8).

## Proof (`tests/phase2/run.sh`: the scratch database `inv_dev2`, the application on 8601 — `php -S` with `tests/dev_router.php`, or a real
Apache serving the rendered `deploy/apache-inventory.conf` when `INV_APP=apache` (the internal vhost on 8607) —, the fake kernel on 8602, the
fake MaluDB on 8603, the fake MaluMail on 8606 (`servers.sh start`; `servers.sh start --no-malumail` leaves 8606 free for slice 3's fixture server),
headless Chromium at 375 × 740 and 1280 × 800; the registry `--check`; `sync_approvals --check`)
Target: Spaces' counts — **sso ≥ 56, gates ≥ 110, sync ≥ 33, ingest ≥ 12, kernel_compat ≥ 9, vhost ≥ 29, browser ≥ 63; about 312 in all.**
The fixture (`bin/dev_directory.json`): 1 the owner (super-admin), 40 Nora (buyer + user + warehouse), 41 Sam (user), 42 Wes (warehouse),
43 Vera (viewer), 44 Ann (external viewer), 45 the expert (an agent, user), 46 Omar (no grant).
- [ ] **sso**: the Phase 0 checks again under the shell (hand-off 302 to `/`, replay 403, audience 403, unknown 403, tampered 403, Omar 403, every
  refusal logged with its reason, the session listed, the sign-out notice 204 ending it); the home renders for each of the seven admitted members
  with their badge (Super-admin, Buyer, Sales, Warehouse, Viewer, Viewer, the agent's `Sales`); the agent on the action-token path reaches a
  JSON screen and is kept off the bar, tokens and home (`require_human()`); an unknown id on the action-token path is refused and logged `member.refused`.
- [ ] **gates**: every screen of the manifest × the seven members → 200 (built or the placeholder with its slice named), 403 in the right's words
  (Vera on `/receipts/`, `/matching/`, `/admin/settings`; Sam on `/counts/`; Wes on `/products/`? — no: Products is `inventory.read`, 200), or 302
  anonymous; the sidebar shows Vera 15 items and no Admin group, Nora every non-admin item and no Admin group, the owner everything; a POST to a
  placeholder → 501 `not_built`; JSON to a placeholder → 501.
- [ ] **sync**: the fake kernel's incremental feed revokes Vera's grant (`capability: null`) → her next request ends her session within a minute
  (`ended_by directory`) and `/` answers 302; roles changed (Sam → `buyer`) → the badge changes on the next request; a department delivered and a
  member admitted appear in the mirror; `directory.sync` logged.
- [ ] **ingest**: `mcp/activity_ingest.py` ships rows to the fake MaluDB with `"application": "inventory"` first and advances the checkpoint; a rejected row stops it.
- [ ] **kernel_compat**: the run-facts gate (`valid: false` → refused; the kernel down → refused), a signed run token with the relay on the
  action-token path acts as the expert (`source agent`, `agent_run_id`), the approval replay header, `/sso/logout` 204 on a bad notice too.
- [ ] **vhost**: the rendered `deploy/apache-inventory.conf` serves `/`, `/sso`, `/api/v1/health`, the MCP proxies (502 until Phase 4), `/o/<48hex>` and
  `/s/<48hex>` → 404 (no handler yet), `/api/v1/availability` → 404 (no handler yet), `/api/anything` → 404, `/files/1` → 401, the canonical rewrites
  (`/products/new` → the placeholder's 200), the internal vhost on 8607 answering the same.
- [ ] **browser** (375 × 740 and 1280 × 800, JavaScript off too): the sidebar at 1280 and the tab bar at 375; the offcanvas; the groups per role (Vera:
  no Admin, no Receive); the bell count after a notification row is inserted by SQL; the command bar answers the fake kernel's reply, shows "waits
  for approval" for a paused action, follows a `navigate` (`/products/`), says "The kernel is not reachable right now." when the fake kernel is
  stopped; My settings saves prefs (kinds unticked persist) and shows the time zone read-only; a token minted and shown once, gone on reload,
  then revoked; the trail shows the person's own rows in words and a record's by `?source=`; the manifest.webmanifest installable; every control
  ≥ 44 px; `scrollWidth` = viewport; no console errors; the home whole with JavaScript off.
- [ ] **registry and approvals**: `bin/build_action_registry.php --check` reads 5 screens and 4 actions built, every other screen unbuilt;
  `bin/sync_approvals.php --check` green (26 approvals).

**Decisions taken in this spec (not questions):**
- The menu table above is the one list; a slice adds no item — it removes `render_nav_stub()` from its controllers. `catalog-gaps` and
  `catalog-import` sit under Catalog gated `catalog.write`; `source-template-list` under Sources gated `sources.write`; `match-queue` gated
  `listings.match`; `watch-list` gated `watches.own` (every write role holds it; a Viewer has no watches); `proposal-list` under Returns
  (the manifest's slice 8 section) gated `reports.read|agents.settings`; `export-list` gated `reports.read|exports.all`.
- The home's regions are rendered by Phase 2 as empty states and the trail as its first rendering; slice 9 fills `home_summary()`'s keys, the cards
  and the trail's sentences — the `home` and `trail` screen rows are slice 9's (reports-admin.md); Phase 2 renders the placeholders (reconciled 2026-10-05).
- `app/attachments.php` and `html/files.php` are Phase 2's (slice 1's images need them first); no thumbnailing in version 1.
- The time zone is the directory's (`members.timezone`) and read-only on My settings — the mirror would overwrite a local value.
- `servers.sh start --no-malumail` exists so slice 3's fixture server may hold 8606 (the proof block is 8601–8607, design §14).
- A member's trail (`?member=`) opens to the admin or `reports.read` (the tool surface's DECISION 17); an agent's trail to any reader.

## Open questions
(none)

## Built and proven

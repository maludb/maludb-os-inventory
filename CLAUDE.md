# Inventory — CLAUDE.md

This repository is the **Inventory** application of the MaluDB Business OS (`github.com/maludb/maludb-os-inventory`, private;
local clone and install path `/srv/apps/inventory`; catalog key `inventory`; DNS label `inventory`). The kernel is `/var/www`
(`maludb-os-core`); read its CLAUDE.md first — the kernel owns identity, the directory, the agents and approvals; this
application owns **what the business sells and where it can get it**: a catalog with every identifier a seller uses, the
business's own stock by location with every movement, the **sources** it draws on without holding stock (other websites read
politely, suppliers' feeds, price sheets, the marketplaces as a reference, another installation of itself) with every offer
remembered as it changes, and the orders filled from the shelf or **drop-shipped** from a supplier to the customer. Modelled
on mattress retail; general to packaged goods.

## Read first
1. `docs/inventory-design.md` — the plan (Phase 0): the research (§0 — the drop-ship trade, the mattress case, the connector
   survey and the crawl policy, the vocabulary), what it is and is not (§1), the actors (§2), roles and reach — cost is the
   wall (§3), the three doors for outsiders — the customer's order link, the supplier's purchase-order link, the availability
   feed (§4), the agents and what pauses (§5), the memory model (§6 — the tables, the connector interface §6.1, the matcher
   §6.2, the shared-schema decisions §6.3), the question inventory with every tool named (§7), the kernel's part and the
   five K7 shares (§8), the screens (§9), the build order (§10), Extended (§11), what the kernel and siblings owe (§12),
   **the decisions the owner is asked for (§13)**, ports and files (§14), the owner's answers (§15), the state (§16).
2. The plugin `maludb-os-integration` (`~/maludb-os-integration`, skill `os-integration` and its references:
   `memory.md`, `mcp-and-api.md`, `sign-on-and-directory.md`, `agents.md`, `roles-and-rights.md`, `sms-and-reads.md`,
   `shared-schema.md`, `registration.md`, `php-sign-on-kit.md`, `testing-without-a-kernel.md`) — how it fits.
3. `/srv/apps/consultant_tracking` and `/srv/apps/spaces` — the nearest **built** exemplars: the kit (`app/*.php`),
   `maludb-os.json`, `docs/build-specs/time-core.md` / `channels.md` (the shape of a slice spec), `tests/phase0|phase2|
   phase3|phase4` (the shape of a proof suite), `deploy/` (templates and `ROOT_STEPS.sh`). `/srv/apps/spaces/docs/
   spaces-design.md` is the sibling plan in the same shape.
4. Prior art in the estate: the Cidery (`/srv/apps/cidery/db/` — a producer's inventory: lots, bond, production; the design
   says by name what it keeps and overturns), the General Ledger (`/srv/apps/gl/db/` — parties, sequences, secure links,
   notifications: the canonical tables this application reuses verbatim), Knowledge (`feed_keys`' bound-token shape).

## Rules that bind here
- `htmx-php-builder` skills govern the PHP (`php-patterns` before any PHP, `design-system` before any markup, `php-session-auth`
  for session hardening and CSRF, `mcp-servers` for the two read servers, `chat-actions` for the command bar, `new-screen` for
  every screen); `os-integration` governs the fit. Where the two conflict, the integration contract wins: **no password, no
  login form, no account, no model key, no Twilio key, no shared tables, no actions server of our own, no writes to the directory,
  no writes to another application** (K7 is reads only).
- **Vocabulary: "agent" means an OS AI agent**, always. A *member* is a kernel member mirrored here; a *product* is a model and a
  *variant* its sellable size or combination; an *identifier* is a code a seller uses for a variant; a *location* is where the
  business keeps stock; a *source* is where offers are read from and a *supplier* a party the business orders from; a *listing*
  is a source's product, a *listing variant* its sellable unit, an *offer* its price and availability at a moment, a *match*
  ties a listing variant to a variant; a *sales order line* is filled from stock or by a *drop-ship*; a *purchase order* is
  addressed to a supplier, for stock or for a customer.
- **The checkpoint gate**: no feature PHP until the owner approves the schema + tool surface + action manifest + connector spec +
  slice specs together (Phase 1). Schema changes after that are numbered additive migrations, each with sign-off. **Never modify
  a migration; add one.**
- **The database is the referee**: a balance is maintained only by transactions (never written directly) and refuses to go
  negative unless the location allows it; a sale allocates at confirmation and issues at shipment; a match never crosses sizes;
  a snapshot is written only when something changed (plus the daily heartbeat); a source's credential is in no view; cost is
  nulled for anyone without the right; a drop-ship line's offer (cost, lead time) is snapshotted on the line; a customer's and a
  supplier's door is one token with its own lifetime. PHP presents refusals as field errors.
- **Nothing is scraped that was not offered** (design §0.2): public endpoints and marked-up pages only; `robots.txt` honoured;
  one request a second per host; an honest user-agent naming the business; `ETag` caching; a 403, 429 or captcha marks the
  source *blocked* and the worker backs off; **never a login, a session cookie, a captcha solver or a proxy pool** — refused by
  name. All outbound HTTP goes through `app/sources/http.php`; nothing else in the application calls out but MaluMail, the
  kernel and MaluDB.
- **A connector is one class** behind `app/sources/Connector.php` (`probe`, `pull`, `search`, `lookup`, `capabilities`) returning
  the normalized listing of design §6.1 and nothing else; the worker matches, snapshots, watches and logs. A new connector is
  one class, one fixture under `tests/fixtures/sources/`, one `source_templates` row — never a change to the worker.
- **Secrets**: a source's credential is sealed with libsodium under `INV_SECRETS_KEY` (config/.env) and decrypted in exactly one
  file (`app/sources/credentials.php`); the screen shows a label and the last four characters; never logged, exported, or
  returned by a tool. The keyed marketplace connectors are Extended (D7); when added, their keys are source credentials, never env.
- **Agents propose; people commit money** (design §5): an agent drafts quotes, purchase orders, transfers and matches, runs
  pulls, sets watches; **sending or placing a purchase order pauses as `money_out`**, reaching a customer or supplier as
  `external_send`, confirming an order, recording money and authorizing a return as `other` (pause by default), deleting and
  undocumented stock changes as `deletion`, prices and sources' configuration as `other`. An agent matches a listing only by
  identifier or by a person's proposal; its own guess is a proposal.
- Every state-changing handler: `require_post()` + `verify_csrf()` (an action token stands in) + the right (`require_right()`,
  SQL `inv_has_right()`) + `log_activity()` + `emit_action_status()`; a create's `location` ends in the record id. A credential, a
  customer's address and phone, and a source's raw object are never in a log payload (ids, names, SKUs, counts, states and
  amounts are).
- The kernel's rules copied here: activity `source` is `web` for the UI, `agent` under a run token, `cron` for the worker,
  `portal` for the two doors, `feed` for the availability API; `action` is `entity.verb`; `mcp_*` views are the only thing the
  read roles see, with caller checks as uncorrelated sets tested once per statement (the kernel's db/160 lesson);
  `app.member_id` is set before any query; an unknown member id is refused, never created. **A record's history is the
  activity log** — except offers, whose history is `offer_snapshots`, and prices, whose history is `price_history`.
- Reads are **SQL functions and views** so a screen, a tool, the feed and an export never disagree: `inv_find()`,
  `inv_availability()`, `inv_atp()`, `inv_offer_history()`, `inv_reorder_candidates()`, `inv_stock_value()`, `inv_feed_answer()`.
- Email is MaluMail (the application's own optional key, written by the installer's `mail` step); texts only through the
  kernel (K6, to members); no model is ever called from PHP: "who ships this fastest", "draft a PO" are the expert's, through
  the kernel's chat endpoint; a watch naming an agent is a dispatch the worker turns into one chat turn. **The Buyer the morning note
  goes to is set in the configuration** (`INV_BUYER_EMAIL` in `config/.env` seeds the setting; the settings screen changes it — D12).
- **Every port env (`APP_INTERNAL_PORT`, `MCP_RECORDS_PORT`, `MCP_ACTIVITY_PORT`) is in `maludb-os.json` `env.required`** and
  pinned in `config/.env` before the installer's `apply` (8188 / 8837 / 8838 — design §13.16). **Never put a `config/.env` here
  before `apply`** except that three-line port pin (`deploy/ROOT_STEPS.sh` step 0); proofs read `$INV_DEV_ENV`.
- Phone first: Find, an order and receiving are designed at 375 px (a tablet on the floor, a phone in the warehouse); the
  catalog, sources and reports at 1280; no modals; full-page create/edit for records; cards for named things (products,
  sources, suppliers, customers), tables for levels, movements, listings, lines and admin lists; every name a link, every page a
  way back; the price and availability chart follows the `dataviz` rules.
- Commit after every finished step on `main`, in the sibling repos' message style (`Inventory: …`); never push unless the owner
  asks. Smokes and fixtures are named `SMOKE <run>`; proofs run on a scratch database and never touch the installed application
  and never pull a live source without a person's say (fixtures by default; the live survey is its own proof); the installer's
  `plan` (`php /var/www/bin/app_install.php plan /srv/apps/inventory`) runs at the end of every phase.

## Build order and the handoff (decided 2026-10-05 — design D16)

The division Spaces used: a **planning-class model** builds what the database enforces, the specs and the exemplar; a **worker
model (Sonnet 5.5)** replicates every other slice from a spec, stopping and escalating on any ambiguity rather than improvising.
The handoff is a clean checkpoint with everything a worker needs in this repository — never mid-slice.

**Before the handoff (planning-class model):**
1. **K27** in the kernel (`/var/www`): a migration seeding the `inventory` catalog row (Operations / `inventory` — the category
   check widened — / `feather-package` / `medium`), on the pattern of db/170 — **built as db/172, 2026-10-05**.
2. **Phase 0, second half**: **the live survey of the candidate stores** from the build server (design §0.2 — recorded in §16,
   seeded as `source_templates`); `db/001`–`0NN` — the mirror and roles (copied from Consultant Tracking, prefix `inv_`),
   settings, sequences and tax rates, the catalog, locations and the transaction ledger, suppliers and sources, listings and
   snapshots, matching, watches, customers and orders, purchasing and the supplier's events, returns, notifications, files,
   dispatches, feed keys — with the referee rules as triggers; the read functions; `inv_has_right()` and `inv_sees_cost()`; the
   `mcp_*` views; `db/proof/phase0_proof.sql` on a scratch database; the connector interface and the normalizer proven against
   fixtures for the five v1 connectors (`shopify`, `woocommerce`, `jsonld`, `feed`, `manual` — D7); the kit copied from Consultant Tracking and proven without a kernel (`tests/phase0/run.sh`);
   `maludb-os.json`; `os/{expert,buyer}.md`; the skills; `deploy/` with the vhost allow-list and `ROOT_STEPS.sh` pinning
   8188/8837/8838; the installer's `plan` clean.
3. **Phase 1**: `docs/inventory-mcp-tool-surface.md`, `docs/inventory-action-manifest.md`, `mcp/action_registry.json`,
   `docs/build-specs/connectors.md`, and the specs in `docs/build-specs/` — `sso-shell`, then slices 1–9 of design §10, each in
   the shape of Consultant Tracking's `time-core.md` (screens, files, query-function signatures, handlers, manifest entries, log
   events, notifications, vocabulary, out of scope, proof, "Open questions" EMPTY). **The owner approves Phase 1 as a whole
   before any PHP.**
4. **Phase 2** (`sso-shell`) — **BUILT and proven 2026-10-09** (Phase 1 approved the same day; 327 / 330 checks; the record in the spec's "Built and proven" and design §16), **slice 1** (the catalog — the CRUD pattern) — **BUILT and proven 2026-10-09** (310 checks; the record picker came with it; the record in `docs/build-specs/catalog.md`), **slice 2** (locations and stock) — **BUILT and proven 2026-10-09** (325 checks; `db/018` fixes `inv_transfer_receive()` for transfers of two lines or more; the record in `docs/build-specs/stock.md`), **slice 3** (sources, connectors, listings, matching — THE EXEMPLAR) — **BUILT and proven 2026-10-09** (290 checks; `db/019`: a probe or a search is not a pull; the worker's `pulls` pass live; the record in `docs/build-specs/sources.md`).
5. **Slice 3, sources, connectors, listings and matching — THE EXEMPLAR**, with **slice 4 (Find, availability and watches)**
   beside it: the novel surface (a source being read, an offer being remembered, a listing becoming ours, a salesperson asking
   "can we sell this") that every later slice composes; each with its proof suite green at 375 and 1280.

**The handoff point** = the commit after slice 4 with design §16 saying "the exemplar built; slices 5–9 open to workers".

**After the handoff (worker model — Sonnet 5.5):** slices 5 → 9 in order, one at a time, each on the exemplar's pattern from
its spec: screens + handlers + `log_activity()` + manifest entries + the tools' query functions, proven on a scratch database,
the spec's "Built and proven" and design §16 updated, one commit per slice. Then **Phase 4** (the two MCP servers over the same
query functions — `mcp/records_server.py`, `mcp/activity_server.py`, tool modules `mcp/inv_*.py`, own `mcp/venv`; `app_roles` and
the five `shares[]` admitted to the kernel's token; the registry wrapper `deploy/kernel-registry-inventory.json`; the agents'
grants and the Buyer's duty — Help Desk's `tests/phase4/run.sh` is the pattern), and **Phase 5** with the owner (ports pinned,
`apply` — root, the owner runs it —, DNS and TLS for `inventory.<domain>`, the MaluMail key, the
hires, the first sources from the templates, the first catalog, the end-to-end proof of design §10).

**A worker's rules:** read this file, design §3 (rights — cost is the wall), §5 (what pauses), §6 (the tables, the connector
interface, the matcher, the shared-schema decisions), §0.2 (the crawl policy), the slice's spec, and the exemplar's code before
writing anything; replicate, never invent; a question goes in the spec's "Open questions" and the slice stops; a new
connector is a class and a fixture, never a change to the worker or the matcher.

## Repository layout (as the siblings)
`app/` (the kit: `bootstrap.php`, `db.php`, `auth.php`, `rights.php`, `activity.php`, `http.php`, `mail.php`, `attachments.php`,
`sources/` — `Connector.php`, `http.php`, `credentials.php`, `connectors/<key>.php`, `matcher.php`; `features/<f>/{queries,
present,write,handler}.php`) · `html/` (the web root: `sso/`, `api/v1/`, `o/`, `s/`, the features' controllers, `assets/`) ·
`db/` (`001_…sql` …, `proof/`) · `mcp/` (the two servers, `action_registry.json`) · `bin/` (`build_action_registry.php`,
`sync_approvals.php`, `dev_handoff.php`, `directory_sync.php`, `worker.php`, `activity_ingest.php`) · `deploy/` (templates,
`ROOT_STEPS.sh`) · `os/` (`expert.md`, `buyer.md`) · `skills/` · `docs/` (the design, Phase 1's documents, `build-specs/`) ·
`tests/` (`phase0|phase2|phase3|phase4`, `fixtures/sources/`) · `storage/` (attachments, exports — gitignored) ·
`maludb-os.json` · `README.md`.

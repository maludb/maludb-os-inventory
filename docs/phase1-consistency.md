# Phase 1 — consistency of the documents

2026-10-05. This file records what was reconciled across the Phase 1 documents of Inventory: the action manifest (`docs/inventory-action-manifest.md`),
the registry it generates (`mcp/action_registry.json`), the tool surface (`docs/inventory-mcp-tool-surface.md`), the connector spec
(`docs/build-specs/connectors.md`), the ten slice specs (`docs/build-specs/{sso-shell,catalog,stock,sources,find,orders,purchasing,feed,
returns-worker,reports-admin}.md`), `maludb-os.json` (the Buyer's action grants only) and `bin/worker.php` (its header comment only). The design
(`docs/inventory-design.md`) and `db/` were read, never edited. Every place edited is marked "(reconciled 2026-10-05)". The lead's fourteen
decisions are applied exactly; nothing was invented.

## Final counts

| | Count |
|---|---|
| Screens in the manifest (registry entries) | **108** = 106 + the two doors (`customer-door`, `supplier-door`); 1 "built" (`html/index.php`, the Phase 0 placeholder) |
| Actions in the manifest (registry entries) | **132**; 0 built (no feature PHP exists before the checkpoint) |
| Unresolved endpoints / parse warnings from `bin/build_action_registry.php` | 0 / 0 |
| Paused for an agent (`maludb-os.json` `approvals[]`, equal to the manifest) | **26**: 2 `money_out`, 4 `external_send`, 9 `deletion`, 11 `other` — no category changed in this pass, so `approvals[]` was not rewritten |
| Claimed by the specs (`tests/phase1/check_specs.php`) | 108 screens and 132 actions, each by exactly one spec |

Claimed per spec (screens / actions): sso-shell 3 / 4 · catalog 16 / 16 · stock 22 / 25 · sources 11 / 18 · find 2 / 2 · orders 16 / 20 (with
`customer-door`) · purchasing 11 / 18 (with `supplier-door`) · feed 6 / 4 (with `connection-list`) · returns-worker 7 / 18 (with `dispatch-list`) ·
reports-admin 14 / 7 (with `home` and `trail`).

## The check and its output

`tests/phase1/check_specs.sh` runs `php bin/build_action_registry.php --check`, `php bin/sync_approvals.php --check` and
`php tests/phase1/check_specs.php`, read-only, no database, exit non-zero on any defect. The claim check reads every spec's section headed exactly
`## Manifest rows claimed`, whose two lines `Screens (N): …` and `Actions (N): …` (each on one line, names in backticks) are the claims; N must equal
the names counted; the tables and "Left to …" notes below them are for people. It proves: every claimed name is in the registry; no spec names a row
twice; every registry screen and action is claimed by exactly one spec. The ten specs' claim sections were normalised to that heading and those two
lines, their content kept (the per-action tables, the "left to" notes).

```
== bin/build_action_registry.php --check
OK: registry matches the manifest.
== bin/sync_approvals.php --check
26 approvals in sync
== tests/phase1/check_specs.php
ok   sso-shell.md: has a `## Manifest rows claimed` section
ok   sso-shell.md: Screens (3) names 3
…  (five lines per spec: the heading, each count, no name twice)
ok   screens: every one of 108 claimed by exactly one spec
ok   actions: every one of 132 claimed by exactly one spec

Claimed per spec (screens / actions):
  sso-shell          3 /   4
  catalog           16 /  16
  stock             22 /  25
  sources           11 /  18
  find               2 /   2
  orders            16 /  20
  purchasing        11 /  18
  feed               6 /   4
  returns-worker     7 /  18
  reports-admin     14 /   7
  total            108 / 132   (registry: 108 screens, 132 actions)

52 ok, 0 FAIL
PHASE 1 CHECKS: all green
```

`php bin/build_action_registry.php` after the manifest edits: `108 screens (1 built), 132 actions (0 built) → mcp/action_registry.json`, no
unresolved endpoint. The registry was regenerated once; `--check` is green.

## Reconciliations (the lead's decisions, applied)

1. **`source_search`'s right.** Found: the manifest said `inventory.read`; the tool surface, find.md's bridge and design §7 A8 (Who = Sales) said
   `orders.write`; sources.md enforced "the manifest's `inventory.read`" in `/sources/search.php` and left the disagreement to the lead; find.md's
   decisions said the Find button follows the tool surface and "slice 3's action file decides its own". Decided: **`orders.write` everywhere** —
   the manifest row (and a note under the sources table), sources.md's handler rule, handler list, claim table and proof line, find.md's decision
   bullet. **The tool's path and budgets.** Found: connectors.md §6.6 said the records tool "POSTs `/sources/search.php` in JSON mode" with a 30 s
   total; the tool surface and find.md say the HMAC bridge `html/internal/bridge.php` (slice 4). Decided: §6.6 rewritten to the bridge with the tool
   surface's budgets — 20 s per request, 25 s per source, 60 s per call, ≤ 5 sources in sequence, ≤ 20 listings each — and "never a POST to
   `/sources/search.php`"; sources.md's handler rule names the bridge's budgets beside its own. (The command bar's action file keeps its own 30 s
   total and 10 listings a screen — two doors, two budgets, both written; see the open items.)
2. **`image_add`.** Found: catalog.md decided the handler takes an existing `image` with no `file` to update alt text, primary and order
   (`update_image()`), but the manifest row had `**file**` required and no `image` or `sort_order`. Decided: the row now reads `file` (optional; hint
   "required when image is absent"), `image` (hint: with it and no file the alt text and primary and order are updated), `sort_order`; a note under
   the catalog table. The registry parses it as intended (seven params, `product` the only required one).
3. **The floor-model log event.** Found: the manifest and stock.md log `stock.floor_model` with `after.direction`; the tool surface's payload rules
   named `stock.floor_model_in|floor_model_out`. Decided: the tool surface now says `stock.floor_model` with `sku`, `location_id`, `direction` (in or
   out), `qty`; `floor_model_in|floor_model_out` remain the transaction's `txn_type` (unchanged in `stock_movements`).
4. **The removal window.** Found: connectors.md §6.3 step 5 said the window counts pulls "with status ok/partial/running"; db/016 counts only `ok`
   pulls plus the running one. Decided: step 5 rewritten to db/016 — a `partial`, `failed` or `blocked` pull never counts; a listing missed by a
   `partial` pull is removed only after two full `ok` reads without it.
5. **The worker's passes — the data-owner rule.** Found: returns-worker.md's divergence 1 claimed every pass but `pulls` and declared
   `bin/worker.php`'s and connectors.md's attributions "superseded"; find.md and orders.md had written `snapshots_heartbeat`/`watches` and
   `links_expire` as theirs; `bin/worker.php` said `links_expire` slices 5 and 6, `key_usage_prune` slice 9. Decided: `pulls` slice 3;
   `snapshots_heartbeat`, `watches` slice 4 (find.md, as written); `links_expire` slice 5 (orders.md, as written; purchasing.md already says it adds
   nothing); `outbox`, `dispatches`, `key_usage_prune` slice 8. Edited: returns-worker.md's intro, divergence 1 (now the division), divergence 2
   (the pass is slice 4's), the pass table (the three moved rows say "left to slice 4/5"), a "Left to slices 4 and 5" line, its Files list (three
   bodies, not six), the proof item (the heartbeat's and `links_expire`'s proofs are slices 4's and 5's), DECISIONs 1, 2 and 14; connectors.md §6.3
   step 10 (the division spelled out); `bin/worker.php`'s header comment (comment only).
6. **`storage/` ownership.** Found: sso-shell.md said Phase 2 adds the `ROOT_STEPS.sh` line; connectors.md §6.7 said slice 3 adds it; sources.md
   said it is Phase 2's; `deploy/ROOT_STEPS.sh` step 0b (db/016's builder) already does it. Decided: all three say "already in `ROOT_STEPS.sh`
   (step 0b); Phase 2 verifies it".
7. **Attachments — one helper, one set of names.** Found: sso-shell.md builds `app/attachments.php` (`attachment_store(): int`,
   `attachment_delete(PDO, int): void`, `attachment_path(array): string`, `inv_can_see_attachment(PDO, int): ?array`) and `html/files.php` in
   Phase 2; returns-worker.md DECISION 9 specified a different file (`app/features/files/queries.php`) with different names (`store_attachment()`
   returning the row, `delete_attachment(PDO, int, int)`, `find_attachment()`, `thumb_path()`) and "whichever slice is built first writes it";
   catalog.md already used `attachment_store()` / `attachment_delete()`; orders.md and purchasing.md name no helper. Decided: **Phase 2 builds the
   four sso-shell.md signatures** (the one contract — sso-shell.md marked so; its `attachment_store()` now states the cap and MIME rule of
   returns-worker.md DECISION 16 so the two agree); returns-worker.md DECISION 9, its attachments section, its query-function line, its decisions
   list and the product-images bullet now use the Phase 2 names and say slice 8 adds `find_attachments()`, `attachment_record()`, `thumb_path()`
   and the `/files/{id}/thumb` resizing (Phase 2 streams the file unresized) and keeps the helper unchanged. catalog.md needed no edit.
8. **`catalog_gaps()` and `fulfilment_today()`.** Found: reports-admin.md DECISION 1 fixed `catalog_gaps(PDO, ?string $gap = null, ?int $brandId =
   null): array` and `fulfilment_today(PDO, ?string $day = null, ?int $locationId = null): array` (the `{day, groups, dropships_expected}` shape) and
   said "whichever slice is built first writes them … in `app/features/reports/queries.php`"; catalog.md had `catalog_gaps(…, int $limit = 100,
   int $offset = 0)`; orders.md had `fulfilment_today(PDO, string $day, ?int $locationId)` plus `dropships_expected()`. Decided: catalog.md and
   orders.md now name DECISION 1's exact signatures (orders.md keeps `dropships_expected()` as the helper the shape uses); reports-admin.md's
   divergence 1, DECISION 1 and its two function lines say slice 1 writes `catalog_gaps()` in `app/features/catalog/queries.php` and slice 5
   writes `fulfilment_today()` in `app/features/orders/queries.php`, and slice 9 calls them.
9. **`listing_forget`'s migration.** Found: sources.md named `db/017_listing_forget.sql` ("renumbered if taken") as slice 3's own migration; the
   file built is `db/017_listing_forget_share_log.sql` (the lead's builder's, with Phase 1). Decided: the four mentions renamed and attributed; the
   function named by its signature `inv_listing_forget(p_listing_id bigint, p_by bigint)`; the guard inside `inv_match_listing_variant()` and
   `inv_propose_matches()` written as built — `listings f` (aliased `f`, because `l` in both functions is a `listings%ROWTYPE` variable).
10. **The Buyer's action grants.** Found: `maludb-os.json`'s Buyer lacked `buyer_propose` and `morning_note_send` (the manifest's actions it
    performs: `buyer.propose`, `buyer.note`); returns-worker.md said Phase 4 would add them. Decided: both added to the Buyer's `tool_grants["Actions
    MCP"]` — nothing else in the file; returns-worker.md's grants bullet says so.
11. **The shell section's rows.** Found: the manifest's "Home, me and the shell (Phase 2 and slice 9)" section had `home` and `trail` claimed by
    both sso-shell.md ("the screen row is claimed here, its content there") and reports-admin.md; `dispatch-list` and `connection-list` (the slice 9
    section) were claimed by returns-worker.md and feed.md with reports-admin.md leaving them. Decided: `home`, `trail` → slice 9 (sso-shell.md's
    claim is now the three screens and four actions, with "renders the placeholders; the rows are slice 9's" in its claim section, its decisions
    and the two screen rows); `notifications`, `my-settings`, `tokens`, `prefs_save`, `notification_read`, `token_mint`, `token_revoke` → Phase 2;
    `dispatch-list` → slice 8; `connection-list` → slice 7 (as feed.md and returns-worker.md claim). A note under the manifest's shell table.
12. **`morning_note_send`'s Who.** Found: the manifest said "agents.settings or the Buyer agent" (a sentence the registry's `who` carried
    verbatim); returns-worker.md's gate admits `agents.settings` or an agent holding `reports.read`. Decided: the manifest's Who is `agents.settings`
    (a person); returns-worker.md's gate and handler line say the agent path is its DECISION so Phase 4's gate matches; a note under the slice 8
    table.
13. **The `share.read` row as built** (from the db/017 builder). Found: the tool surface's shares section, its payload rules, its `source`
    vocabulary ("`application` a share read") and the activity server's `share_reads` tool, and feed.md's Connections page, `share_reads()` /
    `share_readers()`, its vocabulary, its "out of scope" and its proof all said source `application` with `{application, tool, from, to, rows, ms}`
    and filtered on `direction = 'in'` — `activity_log` has no `direction` column. Decided: all aligned to `inv_log_share_read(p_tool, p_consumer,
    p_count, p_request_id)` as built: `source = 'mcp'`, `actor_member_id` NULL, `entity_type = 'share'`, `after = {tool, consumer, count,
    request_id}` (the request id in the payload only); the page and the tool read `after->>'consumer'`, `after->>'tool'`,
    `(after->>'count')::int`, grouped by consumer and tool; the `share_reads` tool's params are `consumer?` and `tool?`, its rows carry no `ms`; the
    proof writes its two rows through the function. The "direction" vocabulary is gone (Inventory reads nothing of a sibling in v1).
14. **db/017's name and the `f` alias** — item 9 above (the same decision, the builder's two additions included).

## Names in one document and not another (left as they are, on purpose)

- `source_search` from the command bar (`/sources/search.php`, slice 3) has a 30 s total and 10 listings a screen (sources.md, connectors.md §6.6's
  "Fan-out is the browser's"); from the records tool it has the bridge's 25 s / 60 s / 20 listings (find.md, the tool surface). Two doors, two
  budgets, the same function and the same right.
- The transaction types `floor_model_in` / `floor_model_out` (`stock_movements`, the schema) and the one log event `stock.floor_model` with
  `direction` are different things and both stay.
- `/files/{id}/thumb`: Phase 2 streams the file unresized (sso-shell.md's DECISION — the browser's `<img>` at card size); slice 8 adds the GD
  resizing in the same door (returns-worker.md). The route is one; the behaviour grows.
- The settings' `business_address` and `raw_max_bytes` are now in `mcp_settings` (db/017); reports-admin.md DECISION 6 still records that the
  form reads `inv_settings` as the writer — true either way.

## Open items for the owner or the lead (not defects)

1. **`source_update` has no approval category.** CLAUDE.md says "sources' configuration as `other`"; the manifest pauses `source_create`,
   `source_credential_set` and `source_schedule_set` (`other`) but not `source_update` (base URL, settings, role, supplier, user-agent). The
   owner's call; a category would change `approvals[]` (27) and is one manifest cell plus `bin/sync_approvals.php`.
2. **`export_own` is `export_download`.** Design §5 lists `export_own` as free for an agent; the manifest has one `export_download` (what the
   caller's rights permit: `reports.read`, or `exports.all` for the three accounting files — reports-admin.md DECISION 5). The design is the lead's
   to align.
3. **The hire script grants by capability.** `maludb-os.json`'s agents carry `access_capability: write` and a `tool_grants` list; the kernel's
   `bin/hire_application_agent.php` grants the role and the tools as declared — the Buyer's two new action grants land only on a fresh hire or a
   re-grant by a super-admin. `buyer_propose` on the **expert's** grants is Phase 4's call (returns-worker.md).
4. **`os/buyer.md`'s "Tools (your grants)" and the `morning-note` skill** do not name `buyer_propose` or `morning_note_send` (they describe the
   note as the kernel's `message_send` plus the application's notification). The job description is outside this pass's files; one line each.
5. **The design's slice 8 line** (§10: "the outbox; link expiry; snapshot heartbeat") predates the data-owner division (link expiry slice 5,
   the heartbeat slice 4) and §5's table still says `export_own`; the design was not edited here.
6. **Approval policies are installation-wide** (the Knowledge record's item 3 holds here too): `settings.save`, `*.delete` events already have
   kernel policies; Inventory's `source.create`, `source.credential_set`, `variant.price_set`, … would pause any application's agent action
   logging the same event. A kernel matter for the owner.
7. **`morning_note_send` by an agent** rests on returns-worker.md's DECISION (an agent holding `reports.read`), not on the manifest's Who;
   Phase 4's gate for that one action must read the spec, not the registry.
8. **`share_reads` (ACT4) filters on `entity_type = 'share'`** now that a share read's `source` is `mcp` like a person's MCP call; Phase 4's
   activity server must not use `source` alone to find them.
9. **returns-worker.md's proof header** still says "≥ 200 checks" though two passes' proofs moved to slices 4 and 5; the count is the builder's to
   restate when the proof is written.

## Not checked or not changed

- `db/`, `app/`, `html/`, `deploy/`, `os/`, `skills/`, `tests/phase0` were not touched; `db/017_listing_forget_share_log.sql` was read to copy its
  facts. The design was read only. Nothing was committed or pushed.
- `maludb-os.json`'s `approvals[]` order in the working tree is `bin/sync_approvals.php`'s ksort (already so before this pass); the only change of
  this pass in that file is the Buyer's two action grants.

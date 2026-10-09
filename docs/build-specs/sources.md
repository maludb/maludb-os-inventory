# Build spec: sources, connectors, listings and matching — THE EXEMPLAR (slice 3)

Built by the planning-class model. The novel surface every later slice composes: **a source being read, an offer being remembered, a
listing becoming ours.** What exists at the end: a Buyer adds a source from a template or by hand (a Shopify store, a WooCommerce store,
a site with product markup over its sitemap, a supplier's CSV with its columns mapped, a price sheet typed in), seals its credential, probes
it, pulls it now or lets the worker pull it on its schedule under the crawl policy; every listing read is upserted one transaction at a time,
its offer snapshotted only when something changed, its variants matched by GTIN, supplier SKU, MPN + size or a marketplace id; what the
matcher cannot decide it scores into proposals the Buyer accepts, redirects or dismisses on the match queue; a listing view shows a variant's
price and availability over time; the supplier's price sheet fills itself from the feed; a source that fails backs off an hour, a day, then
pauses and tells the Buyer. Nothing is scraped that was not offered.
**This spec references `docs/build-specs/connectors.md` for the connector contract (§1–§3), the feed's mapping screen (§4), credentials
(§5), the worker glue (§6), adding a connector (§7) and the survey (§8) — it does not repeat them; where this spec and that one touch, that
one is the authority on the glue and this one on the screens, the handlers and the proof.**
Schema: `suppliers`, `supplier_items`, `sources`, `source_credentials`, `source_pulls`, `source_templates`, `listings`, `listing_variants`,
`offer_snapshots`, `match_proposals`, `watches`, `inv_sources_before()`, `inv_listing_variants_before()`, `inv_record_offer()`,
`inv_snapshot_heartbeat()`, `inv_sizes_agree()`, `inv_set_match()`, `inv_match_listing_variant()`, `inv_listing_match()`,
`inv_listing_unmatch()`, `inv_name_tokens()`, `inv_propose_matches()`, `inv_proposal_accept()`, `inv_proposal_dismiss()`,
`inv_source_pull_start()`, `inv_source_pull_finish()`, `inv_source_resume()`, `inv_upsert_listing()`, `inv_mark_removed()` (db/009);
`inv_connectors()`, `inv_availability_states()`, `inv_ships_how_kinds()`, `inv_setting_int()`, the crawl columns of `inv_settings`
(db/005); `inv_notify()` (db/013); `inv_offer_history()`, `inv_unmatched_listings()`, `inv_source_health()`, `inv_sources_due()`,
`inv_availability()`, `inv_find()`, `inv_size_name()` (db/014); the views `mcp_suppliers`, `mcp_supplier_items`, `mcp_sources`,
`mcp_source_credentials`, `mcp_source_pulls`, `mcp_source_templates`, `mcp_listings`, `mcp_listing_variants`, `mcp_offer_snapshots`,
`mcp_match_proposals`, `mcp_product_variants`, `mcp_brands`, `mcp_sales_order_lines`, `mcp_notifications`, `mcp_activity_log` (db/015).
Never modify them. **Plus the additive migration `db/017_listing_forget_share_log.sql`** (below — built by the lead's builder with Phase 1; reconciled 2026-10-05). **The database is the referee**:
a supplier source names its supplier, a schedule defaults by role and connector, a rate never exceeds the policy's, a credential belongs to
its own source and is in no view, a pull starts only when none is running and (scheduled) only when not paused or backing off, a pull's
counts are the upsert's, a snapshot is written on change (and once a day by the heartbeat), the matcher's rules 1–4 run inside the upsert
and never cross sizes, a person's match never crosses sizes, one proposal row per pair remembered as proposed / accepted / dismissed, an
agent never accepts its own, another agent's or the matcher's proposal, a failed or blocked pull climbs the ladder and the third pauses the
source, a listing unseen for two full pulls is removed and its offer becomes unknown — the match stays. The handlers call the verbs inside
`inv_guard()` and show their sentences; the worker drives; a connector only reads.

## Screens (designed at 1280 px — the Buyer's desk; usable at 375 px; no modals; full-page forms)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `source-list` | `/sources/?q=&connector=&role=&health=` | cards (`source-card-{id}`): name, the connector's badge (its label from `inv_connectors()` in PHP — `app/sources/registry.php`), role chip, the supplier (a link to `supplier-view`, slice 6), health chip (`inv_source_health()`), "last pull 12 min ago · ok · 143 listings", listings live / variants unmatched, the next due time, paused reason; filters in one row; **Add a source** and **Add from a template** (`sources.write`); every reader sees the cards (settings never on a card) |
| `source-add` / `source-edit` | `/sources/new?template=&supplier=&connector=`, `/sources/{source}/edit` | the form (below): the common fields and the connector's own sub-form; with `?template=<key>` prefilled from `mcp_source_templates` (`adopt_template()`: name, connector, role, base_url, the brand from `brand_hint` made or found, the settings keys the connector declares — connectors.md §8); a Shopify template whose `survey_result` is `blocked` shows the two doors the finding names ("read its product sitemap with the marked-up site connector, daily" → the same form with connector `jsonld` and `sitemap_url` empty, or "add a Storefront token" → the credential screen after saving) |
| `source-view` | `/sources/{source}?tab=` | the header: name, connector badge, role chip, supplier, `base_url` (a link, `rel="noopener nofollow"`), health chip + the sentence (`health_sentence()`: "ok · pulled 12 min ago", "stale — last ok 3 days ago", "failing · 2 in a row · backing off until 14:10", "paused: 3 failures in a row (last: HTTP 429)", "blocked: robots.txt refuses crawlers", "manual — pull it yourself", "never pulled"); the robots state chip; the schedule ("every 30 min", "manual") and the next due; the user-agent in use (`inv_source_user_agent()` — a `warning` box "the crawler has no honest user-agent: set the business name and contact in Settings" when it resolves to null); the buttons **Probe** (inline — the result renders in `#source-probe-result` with the state badge, the message and the facts as a definition list; a feed's `columns` become a link to the mapping screen), **Pull now** (→ "Queued — the worker runs it within a minute", the pull row linked; refused within 5 minutes of the last pull with the time to try), **Pause** (a reason input) / **Resume**, **Edit**, **Delete** (`records.delete`, `hx-confirm` naming what goes with it); the cards — **Credential** (kind, label, `…last4`, rotated at; **Set credential** / **Rotate** → `source-credential`; `sources.write` sees the card, `sources.credentials` the buttons), **Last pulls** (the last five: when, kind chip, status chip, seen / new / changed / removed, requests, bytes, error; "running since …" for a running one, "queued" when `policy.queued`, "stale — the worker may have died" after 6 h; a link to `source-pulls`), **Listings** (counts live / matched / unmatched; a link to `source-listings` and to the match queue filtered to it), **Settings** (`sources.write`: the connector's settings as a definition list — a mapping shown field → column; never a credential), **Trail** (`find_activity_by_key('source_id', id)`); `data-entity="source"` |
| `source-listings` | `/sources/{source}/listings?match=&q=&availability=&removed=&page=` | the table `listings-table` (`listing-row-{id}`): title (a link to `listing-view`), vendor, type, variants (live), matched / unmatched counts as chips, the price range, availability (the best variant's), last seen; a listing's variants expand under it (`<details>`, `listing-row-{id}-variants`: title, size, SKU, barcode, price, availability chip, match chip); removed ones under a second heading when `removed=1`; filters: match (matched / unmatched / all), q (title trigram, SKU, GTIN), availability; 50 a page |
| `source-pulls` | `/sources/{source}/pulls?status=&page=` | the table `pulls-table` (`pull-row-{id}`): started, kind chip, status chip, seen / new / changed / variants changed / removed, requests (cached), bytes, duration, by, the error; a `<details>` per row with the policy facts (`pull-row-{id}-policy`: robots, crawl delay, user-agent, rate, proxy, hosts, requests total / not ok (the listed ones with host, path, status, reason), the connector's extras, the errors); 50 a page |
| `source-credential` | `/sources/{source}/credential` | the form (connectors.md §5.3): kind (a select limited to the connector's `credential_kinds`; a connector with none says "This connector takes no credential" and offers nothing), label, the kind's fields (`inv_credential_secret_field()` — a password input never pre-filled; a key a textarea; `username`, `public_key`, `passphrase`, `client_id`, `key_version` as the kind says); **Save** (`hx-confirm` "Replace the credential "Storefront token (dealer)"?" when one exists) |
| `source-template-list` | `/sources/templates` | the templates as cards (`template-card-{id}`): name, connector badge, role chip, `base_url`, the brand hint, the survey chip (open `success`, blocked `danger` "blocked for a non-browser agent", not_platform `secondary`, unverified `light`) and `surveyed_at`, the note; **Add from this template** → `/sources/new?template=<key>`; a box at the top with the survey's finding in two sentences and "the owner re-runs `bin/source_survey.php --record` from a shell" |
| `listing-view` | `/listings/{listing}?listing_variant=&since=` | the header: title, source (a link), vendor, type, tags, `url` (a link), first seen, last seen, removed `dark`, "not ours" `dark` when forgotten; the **variants table** `listing-variants-table` (`listing-variant-row-{id}`): title, size, SKU, barcode (`warning` "not a GTIN" when `barcode_valid` false and a value exists), MPN, price, compare-at, cost (walled), availability chip, qty, lead time, how it ships, the match (the variant's SKU as a link, the kind chip, the confidence, by whom, when — or "unmatched"), the row's actions for `listings.match`: **Unmatch**, **Match…** (a picker `/variants/pick`, posts `listing_match`), **Propose** (a fresh scoring — `match_propose` without a variant); the selected variant's row highlighted (`?listing_variant=`; the first by default); **the chart** `listing-offer-chart` for the selected variant (`shared/series-chart.php`, slice 1's component, over `inv_offer_history(lv, since)`: series 1 price, series 2 compare-at when any point has one, series 3 cost when permitted and present; `bands` = the availability runs collapsed from the same snapshots (`availability_runs()`); a `since` chip row 30 · 90 · 180 · 365 days; a point's tooltip names the pull), the **snapshots table** `listing-offer-table` beneath (observed, price, compare-at, cost (walled), availability, qty, lead time, heartbeat mark, the pull as a link); the **proposals** card (`proposal-row-{id}`: the candidate SKU and product, confidence as a bar, the evidence in words — "brand matches · 3 of 4 name words · size matches · dims within 12 mm · type matches" —, proposed by (the matcher, or a name), **Accept**, **Dismiss**); **raw fields** (`listings.match` only — the view's `raw`, a definition list; images as thumbnails from their URLs, `loading="lazy"`); **sold against** (`mcp_sales_order_lines` by `listing_variant_id` — empty until slice 5); **Not ours** (`listing_forget`, `hx-confirm`); the trail (`find_record_activity('listing', id)` ∪ the variants'); `data-entity="listing"` |
| `match-queue` | `/matching/?source=&q=&min_confidence=&page=` | `inv_unmatched_listings(source)` as a table `queue-table` (`queue-row-{listing_variant}`): source, the listing's title (a link) and variant title, vendor, size, SKU / barcode / MPN, price, availability, **the best proposal** (candidate SKU and product, confidence bar, the evidence words) or "no proposal"; per row: **Accept** (`proposal_accept` of the best), **Pick another…** (the picker → `listing_match`), **Dismiss** (`proposal_dismiss` of the best; the next best surfaces), **Not ours** (`listing_forget`), **Score again** (`match_propose` without a variant); the counts: in the queue, with a proposal, without; filters: source, q, min confidence; 50 a page; `listings.match` |
| `supplier-item-list` | `/supplier-items/?supplier=&q=&page=` | by supplier (a select at the top; the first active supplier by default): the table `supplier-items-table` (`supplier-item-row-{id}`): supplier SKU, our SKU (a link) and product, size, cost (walled), lead time, MOQ, active chip, last seen, the feed that keeps it (a link to the source), **Edit** inline (Pattern C), **Remove** (`hx-confirm`); the add form at the top (variant picker, supplier SKU, cost (`sees_cost()`), lead time, MOQ); `suppliers.write` writes, every reader reads (cost walled by the view) |

## The source form (`source-form`, ids `source-form-field-{name}`; the connector's sub-form `source-form-{connector}` shown by a small script on the connector select and all rendered server-side — JavaScript off shows every sub-form with the chosen one marked)
| Field | Input | Required | Rule |
|---|---|---|---|
| connector | select of `inv_connectors()` (PHP registry — the five of v1 in order; an Extended key seeded in SQL but absent from the registry is not offered) | yes on create; read-only on edit | |
| name | text ≤ 120 | yes | unique (`23505` → "That name is already taken.") |
| role | radio supplier · reference | yes | reference by default; supplier requires a supplier (the CHECK's sentence) |
| supplier | select (`mcp_suppliers` active + none) | when role = supplier | |
| base_url | url | yes for shopify · woocommerce · jsonld; ignored for feed · manual | absolute http(s); `inv_base_url()`'s rule (a trailing slash stripped) |
| schedule_minutes | number ≥ 0, or blank | no | blank → the role's default by the trigger (shown as "default: every 30 min"); 0 = manual |
| rate_per_second | decimal 0.1–10 | no | capped at the policy's by the trigger (the form says "at most 1.00 — the policy") |
| user_agent | text ≤ 200 | no | an override must contain `(+` followed by `http` or `mailto:` — else "an honest user-agent names the business and a contact" (connectors.md §6.2) |
| active | checkbox (edit only) | no | |
| **shopify** | collections (textarea, one handle per line → `collections[]`), currency (3 letters, USD), api_version (2024-10), max_products (number) | — | the Storefront token is a credential (the view's card), never a setting |
| **woocommerce** | currency, vendor (a brand word when the store names none), max_products | — | |
| **jsonld** | sitemap_url (absolute or relative), urls (textarea, one per line → `urls[]`), url_pattern (a regex — `preg_match` must compile, else a field error), max_pages, currency | — | "no sitemap and no URLs" is the probe's finding, not the form's |
| **feed** | transport (radio HTTPS URL · SFTP): `url` (https only; `http://127.0.0.1` / `localhost` allowed in dev), or `sftp.host`, `sftp.port` (22), `sftp.user`, `sftp.path`; format (auto · csv · xlsx); delimiter (auto · , · ; · tab · \|); has_header (on); skip_rows (0); encoding (auto); currency; vendor; lead_time_days (a default); then **Read the file** → `POST /sources/preview.php` (the form's values; on an existing source its id) → the preview table (`feed-preview`: the first five rows under their headers, `row_count`, format, via) and the **mapping** — one select per the fifteen fields of `InvConnectorFeed::FIELDS` (`feed-mapping-{field}`; `supplier_sku` and `gtin` marked "one of these is required"; each select lists the file's headers plus "column number…" / "column letter…" free text); the `mapped` preview rows re-render after a save; a mapping naming an absent column is a field error on that select (the `missing` list); the SFTP tool note from `inv_feed_sftp_tool()` (connectors.md §4) | the mapping on save | the credential (basic / sftp_password / sftp_key) is set on the view after saving |
| **manual** | the listings editor `manual-editor` (`manual-row-{n}`): one row = one entry = one listing with one variant — title, brand, product type, SKU, GTIN, MPN, size (a select of the sizes + other), price, compare-at, cost, qty, availability (the seven states), lead time, how it ships, URL, currency, recorded on (today), note; **Add a row**, remove; an agent's `settings.listings[]` with nested `variants[]` is accepted as given (DECISION: the editor draws no nesting) | — | `recorded_by` = the saver's name; a price sheet PDF is an attachment on the source (slice 8's `attachment_add`, `record_type source`) |

`settings` on save = the connector's own keys only (`connector_settings_keys($connector)`: shopify `collections, currency, api_version,
max_products`; woocommerce `currency, vendor, max_products`; jsonld `sitemap_url, urls, url_pattern, max_pages, currency`; feed `url, sftp,
format, delimiter, has_header, skip_rows, encoding, mapping, currency, vendor, lead_time_days`; manual `listings`) — an agent's JSON `settings`
is filtered to the same keys and an unknown key is refused in words. **Saving a source whose `base_url`, `settings`, `user_agent` or credential
changed calls `inv_source_resume()`** (connectors.md §6.5): fixing the configuration clears the ladder.

## The handlers' rules beyond the schema
- **`source_create` from a template** (`template` given): `adopt_template()` — name, connector, role, base_url, `brand_hint` → a `brands` row found by
  name (case-insensitive) or made; the seeded settings filtered to the connector's keys with `sitemap` → `sitemap_url` and `mapping.map` →
  `mapping.map_price` renamed (connectors.md §8); every field the caller sends wins over the template's.
- **`source_delete`**: `DELETE FROM sources` — the cascade takes listings, their variants (so every match on our variants goes with them),
  offers, pulls, proposals, the credential, the `supplier_sku` identifiers of that source; `supplier_items.source_id` becomes NULL (the price sheet
  stays). The confirm names the counts ("3 listings, 18 offers, 2 pulls and the credential go with it; 5 matched variants are unmatched").
  **deletion**.
- **`source_pull`** (connectors.md §6.4): `inv_source_pull_start(:source, 'manual', :me)` (refused while running — the SQL's sentence; refused by the
  handler when a pull of kind scheduled or manual started less than 5 minutes ago: "Pulled 2 minutes ago — try again at 14:07"), then
  `UPDATE source_pulls SET policy = '{"queued": true}' WHERE id = :pull`; `record_id` = the pull; `location` `/sources/{source}#pull-row-{pull}`;
  `did` "Queued — the worker runs it within a minute". Free for an agent.
- **`source_probe`** (§6.5): inline; `inv_source_pull_start(:source, 'probe', :me)` → `inv_source_for_connector()` → `probe()` (an
  `InvMisconfigured` thrown → a `misconfigured` result with its sentence; an `InvCredentialError` → `misconfigured` "the credential cannot be
  opened — set it again") → `inv_source_pull_finish(:pull, ok|blocked|failed, :message, :policy, :requests, :bytes)`; the answer renders
  `#source-probe-result`; JSON `{state, message, facts, pull_id}` (the facts never carry a secret — the connectors' contract).
- **`source_pause`**: `UPDATE sources SET paused_at = now(), paused_reason = :reason` (the reason ≤ 200, "paused by <name>" when empty).
  **`source_resume`**: `inv_source_resume()` (the ladder cleared; a blocked robots state becomes unknown).
- **`source_schedule_set`**: `UPDATE sources SET schedule_minutes = :m, rate_per_second = COALESCE(:r, rate_per_second)`; **other**.
- **`source_credential_set`** (§5.3): the kind in the connector's `credential_kinds` (else 422 "The shopify connector takes a bearer token, not
  basic"); `inv_seal(['kind' => …, the fields])` → INSERT the new `source_credentials` row (`label`, `last4` from `inv_credential_summary()`,
  `ciphertext = convert_to(:sealed, 'UTF8')`), `UPDATE sources SET credential_id = new`, `UPDATE … SET rotated_at = now()` then DELETE the old row,
  `inv_source_resume()` — one transaction; logged `source.credential_set` with `{credential_id, kind, label, last4, rotated: bool}` and nothing
  else; the secret is in no reply (`did` says "Set the credential "Storefront token (dealer)" (…a1b2)"). **other**.
- **`listing_match`**: `inv_listing_match(:lv, :variant, :me, 'manual')`. **An agent** (an action token with a run id — `current_agent_run_id()`)
  is admitted only when (a) the pair shares an identifier — `lv.barcode_valid` and the barcode equals the variant's `barcode` or a gtin/upc/ean
  identifier; or `lv.sku` equals a `supplier_items.supplier_sku` of the source's supplier or a `supplier_sku` identifier of that source for the
  variant; or `lv.mpn` equals the variant's `mpn` or an `mpn` identifier and the sizes are equal — or (b) a `match_proposals` row for the pair is
  `proposed` with `proposed_by` a human — in which case the handler calls `inv_proposal_accept()` instead so the proposal is recorded accepted;
  otherwise 403 "An agent matches only by identifier or a person's proposal — propose it (match_propose)". Logged `listing.match`
  (`listing_variant_id`, `variant_id`, `sku`, `match_kind`, `confidence`) with `source_id`.
- **`listing_unmatch`**: `inv_listing_unmatch()`; logged `listing.unmatch`.
- **`match_propose`**: with `variant`: one INSERT into `match_proposals` (`confidence` 0–1, 0.5 by default; `evidence` JSON as given, `{}`
  by default; `proposed_by = me` — a person's or an agent's) — the pair's unique index → 422 "That pair is already proposed / accepted /
  dismissed" naming the status; without `variant`: `inv_propose_matches(:lv, NULL)` — **the matcher's scoring, proposer NULL** (DECISION: a
  person asking for a fresh scoring did not propose a pair; the matcher did — so an agent may not accept them); `did` says how many were
  written; logged `listing.propose` (`proposal_id` when one, `listing_variant_id`, `variant_id`, `sku`, `confidence`, `evidence` keys,
  `proposed_by`, `written`).
- **`proposal_accept`**: `inv_proposal_accept(:proposal, :me)` (the SQL refuses an agent on anything a person did not propose; the pair is
  matched `proposed_accepted` with the evidence; the other open proposals of the listing variant dismissed); logged `listing.accept`.
  **`proposal_dismiss`**: `inv_proposal_dismiss()`; logged `listing.dismiss`.
- **`listing_forget`** (`db/017_listing_forget_share_log.sql`'s `inv_listing_forget(p_listing_id bigint, p_by bigint)`, called `inv_listing_forget(:listing, :me)` — reconciled 2026-10-05): every variant unmatched, every open proposal dismissed, `forgotten_at`
  set; hidden from the queue; never matched or scored again; **the listing stays and its pulls keep its offers** (the manifest's words). **deletion**.
  Logged `listing.forget` (`listing_id`, `source_id`, `external_id`, `title` ≤ 200, `variants`).
- **`supplier_item_save`**: INSERT … ON CONFLICT (supplier_id, variant_id) DO UPDATE of the fields given (a person's row has `source_id` NULL
  and `last_seen_at` untouched); `cost` only for `sees_cost()`; **`supplier_item_remove`**: DELETE. Logged `supplier.item_save` /
  `supplier.item_remove` (`supplier_id`, `sku`, `supplier_sku`, `cost`, `lead_time_days`, `moq`).
- **`source_search`** (`/sources/search.php`; connectors.md §6.6): `q` 2–120, `size`, `source` (one) — default every active source whose
  connector `has_search`, not paused, not backing off, at most 5, in sequence, 30 s in all; `inv_source_search_live($pdo, $sourceId, $q, 20, $me)`
  per source; the answer `{query, size, asked: [{source_id, source, status, pull_id, listings_seen, listings_new, ms, error}], rows: [the offers
  for the matched variants through inv_availability() + the unmatched listing variants from mcp_listing_variants]}`; a browser request renders
  `sources/partials/search-result.php` (a card per source: its status and its listings as rows linking to `listing-view`); under an eval run
  (`$GLOBALS['__run_facts']['is_eval']`) nothing persists — the connector is asked, the answer rendered, no pull row, no upsert, logged
  `source.search` with `after.eval = true` (the tool surface's rule). The right is **`orders.write`** (design §7 A8: Sales — the manifest, the tool surface and the
  bridge agree; reconciled 2026-10-05). Find's fan-out screen is slice 4's; the bridge `/internal/bridge.php` is slice 4's (it runs this same
  function with the tool surface's budgets: 25 s a source, 60 s a call, ≤ 5 sources, ≤ 20 listings each).

## The worker glue — `app/sources/pulls.php` (connectors.md §6, every function and step as written there)
Slice 3 writes `app/sources/pulls.php` with `sources_run_due_pulls()`, `inv_source_for_connector()`, `inv_source_user_agent()`,
`inv_run_pull()`, `inv_pull_policy()`, `inv_propose_after_pull()`, `inv_pull_notify()`, `inv_http_cache_prune()` and
`inv_source_search_live()` — **the signatures, the ten steps of a pull (§6.3), the queued on-demand pull (§6.4), the probe (§6.5), the live
search (§6.6) and the housekeeping (§6.7) exactly as connectors.md says**; and makes **the only change to `bin/worker.php`**: the commented
hook in `worker_pass_pulls()` uncommented. The pass's report keys are §6's; `worker.pass` is logged by the worker as it is. The Buyer's
notice on a failed or blocked pull is `inv_notify(buyer, 'pull_failed' | 'pull_blocked', 'source', source_id, …, 'pull_failed:<source>:<n>', false)`
— the kind follows the status (DECISION: db/013 has both kinds; §6.3 names one). `storage/` ownership is already `deploy/ROOT_STEPS.sh` step 0b
(db/016's builder; reconciled 2026-10-05) — this slice verifies the cache directory is created by the first client and the per-host lock is shared.

## The survey's `--record` (connectors.md §8)
`bin/source_survey.php … --record`: with a database (the kit's `db()`), each surveyed host updates its template's `survey_result` (`open` when the
Shopify column is open; `blocked` when blocked and the platform recognised; `not_platform` when `no` and not recognised; `unverified` otherwise)
and `surveyed_at`; matched by host against `base_url`; prints what changed; never from the planning sandbox. Logged `source.update` on
`source_template` with `after: {key, survey_result}` (actor none, source `cron`).

## `db/017_listing_forget_share_log.sql` — additive (built by the lead's builder with Phase 1 — reconciled 2026-10-05; the same file carries `inv_log_share_read()` for the read role, `mcp_settings.business_address` and `raw_max_bytes`)
"Not ours" has no column: a listing marked removed by hand comes back on the next pull (`inv_upsert_listing()` clears `removed_at`), and the
manifest says the listing stays until its source removes it. So, additively: `ALTER TABLE listings ADD COLUMN forgotten_at timestamptz, ADD COLUMN
forgotten_by bigint REFERENCES members(id) ON DELETE SET NULL`; `CREATE INDEX listings_forgotten_idx ON listings (source_id) WHERE forgotten_at IS NOT NULL`;
`inv_listing_forget(p_listing_id bigint, p_by bigint) RETURNS integer` (SECURITY DEFINER; unmatches every variant through `inv_listing_unmatch()`,
dismisses every `proposed` proposal of its variants with `decided_by = p_by`, sets `forgotten_at = now(), forgotten_by = p_by`; refuses a listing
already forgotten "already marked not ours"; returns the variants unmatched; granted to `inventory_rw`); `CREATE OR REPLACE` of
`inv_match_listing_variant()` and `inv_propose_matches()` with one guard at the top — `IF EXISTS (SELECT 1 FROM listings f WHERE f.id = lv.listing_id
AND f.forgotten_at IS NOT NULL) THEN RETURN NULL / 0` — **aliased `f`, because `l` in both functions is a `listings%ROWTYPE` variable** (as built;
reconciled 2026-10-05) —, of `inv_listing_match()` refusing "That listing was marked not ours" (`check_violation`),
and of `inv_unmatched_listings()` with `AND l.forgotten_at IS NULL`; `CREATE OR REPLACE VIEW mcp_listings` appending `forgotten_at`,
`forgotten_by` as the last columns (a view's columns are appended, never reordered — the kernel's rule). The function bodies are copied from
db/009 and db/014 with the guard added and nothing else changed; `db/proof/phase0_proof.sql` still passes (the proof adds its checks in slice
3's own suite). Signed off with Phase 1 (this spec); the lead is told (see the report).

## Files (exactly these)
- `html/sources/index.php` (`source-list`) · `form.php` (`source-add`, `source-edit`) · `view.php` (`source-view`) · `listings.php` (`source-listings`) · `pulls.php` (`source-pulls`) · `credential.php` (`source-credential` on GET, `source_credential_set` on POST) · `templates.php` (`source-template-list`) · `save.php` · `schedule.php` · `probe.php` · `pull.php` · `search.php` · `pause.php` · `resume.php` · `delete.php` · `preview.php` (the feed's "Read the file", Pattern A)
- `html/listings/view.php` (`listing-view`) · `match.php` · `unmatch.php` · `propose.php` · `forget.php` · `proposals/accept.php` · `proposals/dismiss.php`
- `html/matching/index.php` (`match-queue`)
- `html/supplier-items/index.php` (`supplier-item-list`) · `save.php` · `remove.php`
- `app/features/sources/{queries,present,write,handler}.php` (`handler.php`: `source_from_request()`, `require_source_writer()`, `source_log()` — `source_id` on every row, `entity_type` source / source_pull / source_credential / source_template) · `app/features/listings/{queries,present,write,handler}.php` (`listing_from_request()`, `listing_variant_from_request()`, `require_matcher()`, `listing_log()` — `source_id` on every row; `agent_may_match()`) · `app/features/supplier_items/{queries,write,handler}.php`
- `app/sources/pulls.php` (the glue) · `bin/worker.php` (the hook uncommented — the only change) · `bin/source_survey.php` (`--record`)
- `db/017_listing_forget_share_log.sql` (the lead's builder's — this slice adds nothing to `db/`; reconciled 2026-10-05)
- `app/views/sources/{index,form,view,listings,pulls,credential,templates,partials/source-card,partials/health-chip,partials/probe-result,partials/pull-row,partials/policy-facts,partials/listing-row,partials/feed-preview,partials/feed-mapping,partials/manual-editor,partials/search-result,partials/template-card}.php` · `app/views/listings/{view,partials/listing-variant-row,partials/proposal-row,partials/evidence,partials/raw-fields}.php` · `app/views/matching/{index,partials/queue-row}.php` · `app/views/supplier_items/{index,partials/item-row}.php`
- `html/assets/js/sources.js` (the connector sub-form switch, the probe's inline result, the manual editor's rows, the mapping selects' free text; re-bound on `htmx:afterSwap`)
- `app/partial_update.php`: `PARTIAL_UPDATE_TARGETS` gains `'/sources/save.php' => ['sources', 'source', 'mcp_sources', 'source_id']`
- `app/features/shell/nav.php`: `back_link()` learns `/sources/{id}` "the source", `/listings/{id}` "the listing", `/matching/` "the match queue"; `record_url()` learns source, source_pull, listing, listing_variant, match_proposal, supplier_item
- `tests/phase3/slice3/` — `run.sh`, `lib.php`, `sources.php`, `credentials.php`, `probe.php`, `pulls.php`, `removal.php`, `matching.php`, `queue.php`, `listing.php`, `sheets.php`, `health.php`, `survey.php`, `json.php`, `browser.mjs`

## Query functions (signatures fixed; `app/features/sources/queries.php` unless said; the tools of Phase 4 call these — A3, A4, A6, A7, A9, A13 and the resolvers)
- `find_sources(PDO, array $filters, int $limit = 100, int $offset = 0): array` (`mcp_sources` ⨝ `inv_source_health()` by `source_id`; `q`, `connector`, `role`, `supplier`, `health`, `include_inactive`; `settings` and `user_agent` are the view's — null below `sources.write`; every row + `label`) — tool `find_sources` · `find_source(PDO, int $id): ?array` · `source_full(PDO, int $id): ?array` (`{source, credential (mcp_source_credentials row or null), health, pulls (5), counts}`) — tool `get_source` · `source_templates(PDO, bool $activeOnly = true): array` (`mcp_source_templates`) — `find_sources(templates: true)` · `find_template(PDO, string $key): ?array` · `adopt_template(PDO, array $template, array $given, int $by): array` (the fields a form or a create receives)
- `source_health(PDO, ?array $health = null, ?string $role = null): array` (`inv_source_health()` filtered) · `health_sentence(array $row): string` · `health_counts(PDO): array` (per health, the list's chips) — tool `source_health`
- `source_user_agent(PDO, array $row): ?string` (`inv_source_user_agent()` from the glue) · `connector_settings_keys(string $connector): array` · `connector_defs(): array` (`inv_connectors()` of the PHP registry: key, label, description, capabilities)
- `source_pulls(PDO, array $filters, int $limit = 50, int $offset = 0): array` (`mcp_source_pulls`; `source`, `status[]`, `kind`, `since`) — tool `source_pulls` · `find_pull(PDO, int $id): ?array` · `pull_changes(PDO, int $pullId): array` (`{changed (≤ 100 from mcp_offer_snapshots by pull_id ⨝ mcp_listing_variants), new_listings (≤ 50: mcp_listings where first_seen_at within the pull), removed_listings (≤ 50: removed_at within the pull)}`) — tool `get_pull` · `policy_facts(array $pull): array` (the `<details>` rows)
- `source_listings(PDO, array $filters, int $limit = 50, int $offset = 0): array` (`mcp_listings` + each listing's `variants[]` from `mcp_listing_variants`; `source`, `q`, `matched`, `include_removed`, `availability`, `forgotten`) — tool `source_listings` (`app/features/listings/queries.php`) · `find_listing(PDO, int $id): ?array` · `listing_full(PDO, int $id): ?array` (`{listing, variants (each + match {variant_id, sku, product, match_kind, match_confidence, matched_by, matched_by_name, matched_at}), proposals (proposed), raw (the view's), sold_lines}`) — tool `get_listing` · `find_listing_variant(PDO, int $id): ?array` · `find_listing_variants(PDO, array $filters, int $limit = 50, int $offset = 0): array` (`mcp_listing_variants`; `q` — a GTIN or SKU exact, words trigram on `listing_title || title`; `source`, `matched`, `include_removed`; + `label` "Malouf · Zinus 12" Green Tea · Queen · 312.00 · in stock") — the resolver `find_listing_variants`
- `offer_history(PDO, int $lvId, ?string $since = null, ?string $until = null, int $limit = 500): array` (`inv_offer_history()`; `until` filtered in PHP) · `offer_summary(array $series): array` (`{points, first_at, last_at, price_min, price_max, price_first, price_last, changes, last_out_of_stock_at, days_out_of_stock}`) · `availability_runs(array $series): array` (consecutive `availability` collapsed to `[{availability, from, to, days}]` + `{current, last_change_at, out_of_stock_runs, days_out_of_stock}`) · `offer_chart_series(array $series, bool $seesCost): array` (the chart's `series` and `bands`) — tools `offer_history`, `availability_timeline`
- `unmatched_listings(PDO, ?int $sourceId = null, bool $withProposalOnly = false, ?float $minConfidence = null, int $limit = 50, int $offset = 0): array` (`inv_unmatched_listings()`) · `queue_counts(PDO, ?int $sourceId): array` — tool `unmatched_listings` · `match_proposals(PDO, array $filters, int $limit = 50, int $offset = 0): array` (`mcp_match_proposals` ⨝ `mcp_listing_variants`; `listing_variant`, `variant`, `status` default proposed, `proposed_by`) — tool `match_proposals` · `evidence_words(array $evidence): string`
- `supplier_items(PDO, array $filters, int $limit = 100, int $offset = 0): array` (`mcp_supplier_items`; `supplier` or `variant`, `active_only`, `stale_days`, `q`) — tool `supplier_items` · `find_supplier_item(PDO, int $id): ?array` · `suppliers_for_pick(PDO): array` (`mcp_suppliers` active)
- `agent_may_match(PDO, int $lvId, int $variantId): ?string` (null when (a) holds, else the sentence; and `'proposal:<id>'` when (b) holds — the handler reads it) · `source_delete_counts(PDO, int $sourceId): array` (listings, offers, pulls, credential, matched)
- Writes (`write.php`): `save_source(PDO, ?int $id, array $fields, array $settings, int $by): array` (`['id', 'resumed' => bool]`) · `delete_source(PDO, int $id): void` · `set_schedule(PDO, int $id, int $minutes, ?float $rate): array` · `pause_source(PDO, int $id, string $reason): void` · `resume_source(PDO, int $id): void` · `queue_pull(PDO, int $sourceId, int $by): int` · `run_probe(PDO, int $sourceId, int $by): array` (`{state, message, facts, pull_id}`) · `set_credential(PDO, int $sourceId, string $kind, string $label, array $fields, int $by): array` (`{credential_id, last4, rotated}`) · `match_listing_variant(PDO, int $lvId, int $variantId, int $by): array` · `unmatch_listing_variant(PDO, int $lvId): array` · `propose_match(PDO, int $lvId, ?int $variantId, float $confidence, array $evidence, ?int $by): array` (`{proposal_id|null, written}`) · `accept_proposal(PDO, int $id, int $by): array` · `dismiss_proposal(PDO, int $id, int $by): void` · `forget_listing(PDO, int $id, int $by): int` · `save_supplier_item(PDO, int $supplierId, int $variantId, array $fields): int` · `remove_supplier_item(PDO, int $id): array`

## Handlers (every one: `inv_handler_begin()`; the right; `inv_guard()`; `log_activity` with `source_id`; `inv_done()`; `HX-Trigger: sourceChanged` — `listingChanged` for a listing or a match, `supplierChanged` for a price-sheet row)
- `sources/save.php` (`source_create` without `source`, `source_update` with): `sources.write`; the template adoption; the settings filter; the user-agent rule; `source.create` (`after`: name, connector, role, supplier_id, base_url, schedule_minutes, rate_per_second, `settings_keys` — never a mapping's sample rows) / `source.update` (`inv_diff()` on the fields; the settings as the keys changed); **other** on create; location `/sources/{id}`.
- `sources/schedule.php` (`source_schedule_set`): `sources.write`; `source.schedule_set` (`before/after.schedule_minutes`, `rate_per_second`); **other**.
- `sources/probe.php` (`source_probe`): `sources.write`; `source.probe` (`state`, `message` ≤ 200, `http_status`, `pull_id`); location `/sources/{id}#source-probe-result`.
- `sources/pull.php` (`source_pull`): `sources.write`; `source.pull_start` (`pull_id`, `kind: manual`, `queued: true`); `record_id` the pull.
- `sources/search.php` (`source_search`): `orders.write` (reconciled 2026-10-05); `source.search` per source asked (`pull_id`, `query` ≤ 120, `size`, `found`, `ms`, `eval`); JSON the envelope above; location `/matching/` is not sent — the reply is the answer.
- `sources/pause.php` (`source_pause`) / `resume.php` (`source_resume`): `sources.write`; `source.pause` (`reason`) / `source.resume`.
- `sources/delete.php` (`source_delete`): `records.delete`; `source.delete` (`name`, `connector`, the counts); **deletion**; location `/sources/`.
- `sources/credential.php` POST (`source_credential_set`): `sources.credentials`; `source.credential_set` (`credential_id`, `kind`, `label`, `last4`, `rotated`); **other**; location `/sources/{id}#source-credential`.
- `sources/preview.php` (Pattern A, POST, `sources.write`): `InvConnectorFeed::preview()` on the form's values (or the source's row + the values); renders `feed-preview` + `feed-mapping`; logs nothing (a read; the file is not kept).
- `listings/match.php` (`listing_match`): `listings.match`; the agent rule; `listing.match`; location `/listings/{listing}?listing_variant={lv}`. `listings/unmatch.php` (`listing_unmatch`): `listing.unmatch`. `listings/propose.php` (`match_propose`): `listing.propose`. `listings/proposals/accept.php` (`proposal_accept`): `listing.accept` (`proposal_id`, `listing_variant_id`, `variant_id`, `sku`, `confidence`, `evidence` keys, `proposed_by`). `listings/proposals/dismiss.php` (`proposal_dismiss`): `listing.dismiss`. `listings/forget.php` (`listing_forget`): `listing.forget`; **deletion**; location `/matching/` when it came from the queue (`return_to`), else `/listings/{id}`.
- `supplier-items/save.php` (`supplier_item_save`): `suppliers.write`; `supplier.item_save`; location `/supplier-items/?supplier={supplier}#supplier-item-row-{id}`; a Pattern C request answers the row. `supplier-items/remove.php` (`supplier_item_remove`): `supplier.item_remove`.

## Manifest rows claimed
Screens (11): `source-list`, `source-add`, `source-view`, `source-edit`, `source-listings`, `source-pulls`, `source-credential`, `source-template-list`, `listing-view`, `match-queue`, `supplier-item-list`
Actions (18): `source_create`, `source_update`, `source_credential_set`, `source_schedule_set`, `source_probe`, `source_pull`, `source_search`, `source_pause`, `source_resume`, `source_delete`, `listing_match`, `listing_unmatch`, `match_propose`, `proposal_accept`, `proposal_dismiss`, `listing_forget`, `supplier_item_save`, `supplier_item_remove`
Agent approvals: `source_create`, `source_credential_set`, `source_schedule_set`
(`other`); `source_delete`, `listing_forget` (`deletion`).

| Action | File | Log | Who | Approval |
|---|---|---|---|---|
| `source_create` | `/sources/save.php` | `source.create` | sources.write | other |
| `source_update` | `/sources/save.php` | `source.update` | sources.write | |
| `source_credential_set` | `/sources/credential.php` | `source.credential_set` | sources.credentials | other |
| `source_schedule_set` | `/sources/schedule.php` | `source.schedule_set` | sources.write | other |
| `source_probe` | `/sources/probe.php` | `source.probe` | sources.write | |
| `source_pull` | `/sources/pull.php` | `source.pull_start` | sources.write | |
| `source_search` | `/sources/search.php` | `source.search` | orders.write | |
| `source_pause` | `/sources/pause.php` | `source.pause` | sources.write | |
| `source_resume` | `/sources/resume.php` | `source.resume` | sources.write | |
| `source_delete` | `/sources/delete.php` | `source.delete` | records.delete | deletion |
| `listing_match` | `/listings/match.php` | `listing.match` | listings.match | |
| `listing_unmatch` | `/listings/unmatch.php` | `listing.unmatch` | listings.match | |
| `match_propose` | `/listings/propose.php` | `listing.propose` | listings.match | |
| `proposal_accept` | `/listings/proposals/accept.php` | `listing.accept` | listings.match | |
| `proposal_dismiss` | `/listings/proposals/dismiss.php` | `listing.dismiss` | listings.match | |
| `listing_forget` | `/listings/forget.php` | `listing.forget` | listings.match | deletion |
| `supplier_item_save` | `/supplier-items/save.php` | `supplier.item_save` | suppliers.write | |
| `supplier_item_remove` | `/supplier-items/remove.php` | `supplier.item_remove` | suppliers.write | |

Every row of the manifest's sources section is claimed; none is left to another slice. (The Find screen's "ask the sources now" fan-out and
the bridge are slice 4's; they call this slice's `inv_source_search_live()` and `source_search`.)

## Activity log events (every row: `source_id`)
`source.create|update|credential_set|schedule_set|pause|resume|probe|pull_start|delete|search`; the worker's `source.pull_done|pull_fail|pull_blocked`
(`entity_type source_pull`, the counts of connectors.md §6.3 step 8), `listing.new|changed|removed` (the glue logs one row per listing first seen
and removed, and `offer.change` per listing variant whose snapshot was written — the counts and the field names, never the values — DECISION:
the glue reads `mcp_offer_snapshots` by `pull_id` after the pull and logs them in one pass, ≤ 500 rows a pull; beyond that one row
`offer.change` with `count`), `listing.match|unmatch|propose|accept|dismiss|forget`, `supplier.item_save|item_remove`, `worker.pass`,
`screen.view` (`source-view`, `listing-view` with `after.source_id`). **No row carries a credential, a secret, a raw object, a URL list, a
mapping's sample rows or more than 120 characters of a query.**

## Notifications this slice queues (`inv_notify()`; the sender is slice 8)
| Step | Who is told | Kind |
|---|---|---|
| a pull fails (the first failure, the second, the pause — once per rung by the dedupe key) | the Buyer (`inv_settings.buyer_member_id`), else the source's creator | `pull_failed` |
| a pull is blocked (a 403, a 429, a bot wall, a login redirect, robots.txt refusing) | the same | `pull_blocked` |

## Status vocabulary
Health chips: ok `success`, stale `warning`, failing `warning`, blocked `danger`, paused `dark`, manual `secondary`, never_pulled `light`,
inactive `dark`. Pull status chips: running `info` (queued `secondary`), ok `success`, partial `warning`, failed `danger`, blocked `danger`.
Pull kind chips: scheduled none, manual `primary`, search `info`, probe `secondary`. Role chips: supplier `primary`, reference `secondary`.
Connector badges: shopify, woocommerce, jsonld, feed, manual — the label from the registry, `light` with the key as the title. Availability
chips (the seven states): in_stock `success`, limited `success` "limited", pre_order / back_order `warning`, out_of_stock `danger`, discontinued
`dark`, unknown `secondary`. Match kind chips: gtin `primary`, supplier_sku `primary`, mpn `info`, marketplace_id `info`, manual `success`,
proposed_accepted `success` "accepted"; unmatched `warning`. Robots state: ok `success`, blocked `danger`, unknown `secondary`. Survey chips as
on the templates screen. The chart's bands: the status map of slice 1's component. Ids: `source-list`, `source-list-filters`, `source-card-{id}`,
`source-form`, `source-form-field-{name}`, `source-form-{connector}`, `source-form-field-{connector}-{setting}`, `feed-preview`, `feed-mapping`,
`feed-mapping-{field}`, `manual-editor`, `manual-row-{n}`, `manual-row-{n}-{field}`, `source-view-header`, `source-health`, `source-probe`,
`source-probe-result`, `source-pull-now`, `source-pause`, `source-resume`, `source-credential`, `source-credential-form`,
`source-credential-form-field-{name}`, `pulls-table`, `pull-row-{id}`, `pull-row-{id}-policy`, `listings-table`, `listing-row-{id}`,
`listing-row-{id}-variants`, `template-list`, `template-card-{id}`, `listing-view-header`, `listing-variants-table`, `listing-variant-row-{id}`,
`listing-variant-row-{id}-match`, `listing-variant-row-{id}-unmatch`, `listing-variant-row-{id}-propose`, `listing-offer-chart`,
`listing-offer-since`, `listing-offer-table`, `listing-proposals`, `proposal-row-{id}`, `proposal-row-{id}-accept`, `proposal-row-{id}-dismiss`,
`listing-raw`, `listing-forget`, `queue-table`, `queue-counts`, `queue-row-{listing_variant}`, `queue-row-{lv}-accept`, `queue-row-{lv}-pick`,
`queue-row-{lv}-dismiss`, `queue-row-{lv}-forget`, `queue-row-{lv}-score`, `supplier-items-table`, `supplier-item-row-{id}`, `supplier-item-form`,
`supplier-item-form-field-{name}`, `search-result`, `search-result-{source}`.

## Mobile rule (375 × 740)
The source cards stack; the source form's sub-form one column, the mapping selects stacked under the preview table (which scrolls inside the
page); the listing view's variants table scrolls inside `.table-responsive`, the chart scales with its card and its tooltip stays inside, the
proposals as stacked rows; the match queue a stacked row per listing variant with its buttons in a row of four (≥ 44 px each); `scrollWidth` =
viewport.

## Vocabulary
*source* (where offers are read from), *connector* (the class that reads it), *template* (a known store or feed to start from), *credential*
(sealed; a label and four characters), *probe* (can it be read as configured — a `probe` pull row), *pull* (one reading — scheduled, manual,
search or probe), *queued* (a manual pull the worker runs within a minute), *policy* (the facts a pull followed), *blocked* (a 403, a 429, a bot
wall, a login redirect — never retried faster), *the ladder* (an hour, a day, then paused), *listing* (a source's product), *listing variant* (its
sellable unit), *offer* (price and availability at a moment — a snapshot), *heartbeat* (a snapshot a day regardless), *removed* (unseen for two
full pulls — the match stays), *match* (the tie to our variant: by rule 1–4, by a person, by an accepted proposal), *proposal* (a scored
candidate with evidence — a person decides), *not ours* (forgotten: unmatched, hidden, never scored again), *price sheet* (`supplier_items` —
the dealer's cost and lead time per variant, kept by the feed or typed).

## Out of scope for this slice
The Find screen, its "ask the sources now" fan-out, the bridge, watches and the heartbeat and watches passes (slice 4 — `snapshots_heartbeat`
and `watches` stay stubs here; the proof calls `inv_snapshot_heartbeat()` by SQL to prove the point is written); the Buyer agent's morning note,
`source_health`'s heading and the outbox sender (slice 8); the supplier's own screens (slice 6); the Extended connectors (connectors.md §7); a
MAP adopted from a feed; the records MCP tools over these views (Phase 4).

## Proof (`tests/phase3/slice3/run.sh`: the scratch database `inv_dev3`, the application on 8601 (`php -S`, or `INV_APP=apache`), the fake
kernel 8602, the fake MaluDB 8603, **the fixture server on 8606** — `php -S 127.0.0.1:8606 -t tests/fixtures/sources tests/fixtures/sources/router.php`
with `INV_FIX_LOG` — started by the suite after `tests/phase2/servers.sh start --no-malumail` (DECISION: no fake MaluMail in this suite — the
outbox is never sent here); members through dev hand-offs, curl with signed action and run tokens, `php bin/worker.php --passes=pulls` as the
worker, `INV_WORKER_NOW` to move the clock, headless Chromium at 375 × 740 and 1280 × 800; the registry `--check`; `sync_approvals --check`)
Target: **≥ 260 checks** (sources 35, credentials 20, probe 15, pulls 45, removal and the ladder 30, matching 35, queue 20, listing view 15,
price sheets 10, health and templates 10, survey 5, json 15, browser 30).
The world (`tests/phase3/slice3/lib.php` `sources_world()`): slice 1's `catalog_world()` — the Cloudrest Hybrid's six sizes carry **the GTINs read
from `tests/fixtures/sources/shopify/products-page1.json`**; the proof also seeds, read from the fixtures at seed time (never typed): a supplier
"SMOKE Dealer Co" (dropships) with `supplier_items` rows whose `supplier_sku` are the feed fixture's Item Numbers for two sizes (rule 2), an `mpn`
on the King equal to the jsonld fixture's King `mpn` (rule 3), an `asin` identifier equal to the WooCommerce fixture's variation id of the Queen
(rule 4); the sources — "SMOKE Shopify store" (`shopify`, reference, `base_url` the fixture server, schedule 30), "SMOKE Dealer feed" (`feed`,
supplier Dealer Co, `url` the fixture's `/feed.csv`, the mapping by header), "SMOKE Marked-up site" (`jsonld`, the fixture's sitemap, schedule
1440), "SMOKE Woo store" (`woocommerce`), "SMOKE Price sheet" (`manual`, two entries), "SMOKE Walled store" (`shopify` at the router's bot-wall
path — the one `tests/phase0/connectors.php` uses); Nora (Buyer), Sam (Sales), Vera (Viewer), the owner, the expert (agent 45 — the proof grants
it `buyer` by SQL for the agent checks, as the shipped manifest does).
- [ ] **Sources**: Nora creates the six (one from the `maloufhome` template — the brand "Malouf" made, the inert settings dropped, `endpoint` absent; the blocked-template doors shown when `survey_result` is set blocked by SQL); a supplier source without a supplier refused by the CHECK in words; a duplicate name; a rate of 5 capped to the policy's 1.00; a blank schedule → 30 for a supplier, 360 for a reference, 1440 for jsonld, 0 for manual (the trigger); a user-agent override without a contact refused in words; an unknown settings key from JSON refused; Sam's create → 403; the cards with the connector badge and `never_pulled`; a `base_url` change calls `inv_source_resume()` (a paused source comes back); pause with a reason / resume; `source.create` (**other** in the registry) / `update` / `pause` / `resume` logged with `source_id` and `settings_keys`, never a mapping's rows; the JSON reply's `record_id` and `location`.
- [ ] **Credentials**: a `bearer` set on the Shopify source → a `source_credentials` row with label and last4, `credential_id` pointing at it, the ciphertext opening to the token through `inv_open()` (the proof's own call) and in no `mcp_*` view (`mcp_source_credentials` has no such column; `records_search`-style `SELECT` over the views finds the token's text nowhere); rotated → a new row, the old gone, `rotated: true` in the log; a kind the connector does not take → 422 in words; Sam → 403 (`sources.credentials`); the screen shows "…<last4>" and never the value; a `basic` credential on the feed source lets `/feed-protected.csv` read (a probe ok after a 401 without it); the password appears in no fact, message, log row or policy (the proof greps every row).
- [ ] **Probe**: the Shopify fixture → ok ("products.json answers…"), a `probe` pull row finished ok; the walled store → blocked, the row blocked, the source climbs one rung (`backoff_until` ~1 h) and the Buyer gets `pull_blocked` once; a feed with a mapping naming an absent column → misconfigured naming it and the facts' `columns` listed on the mapping screen; a bad `base_url` → misconfigured with `InvMisconfigured`'s sentence; the probe refused while a pull is running; `source.probe` logged with `state` and `pull_id`.
- [ ] **Pulls** (`php bin/worker.php --passes=pulls`): the Shopify source due → a scheduled pull: 3 listings, 9 variants, every variant one snapshot, the six mattress sizes **matched by GTIN** (rule 1, `match_kind gtin`, confidence 1.000, `matched_by` NULL), the pull row's counts the upsert's (seen 3, new 3, changed 0, variants_changed 0), `policy.robots` a scalar `ok`, `hosts` the map, `requests` only the not-ok ones, `http_requests` and `bytes` from the client; `last_ok_at` set, the ladder clear; `source.pull_start` and `source.pull_done` logged with the counts and `listing.new` ×3; a second pass within the schedule runs nothing (`inv_sources_due()` empty); `INV_WORKER_NOW` +31 min → a second pull with **no new snapshot** (nothing changed; `ETag` → cached requests in the policy); the fixture's price edited by the proof's router switch (`INV_FIX_VARIANT=v2` — the router's second version of `products-page1.json`, added to `tests/fixtures/sources/shopify/products-page1.v2.json` by this slice: the Queen's price changed and `available` false) → the third pull writes one snapshot for the Queen (price and availability) and `variants_changed 1`, `offer.change` logged with the field names; the feed source → the dealer file read, rows grouped by `product`, the two mapped SKUs **matched by supplier SKU** (rule 2, 0.950), `supplier_items` updated with cost, lead time and `last_seen_at` (the price sheet kept by the feed), the row without identifiers skipped and counted; the jsonld source → the sitemap's product children, the `/private/` page never requested (the router's log), the King **matched by MPN + size** (rule 3), the Open Graph page a listing; the Woo source → the Queen variation **matched by marketplace id** (rule 4); the manual source → two listings from the editor's rows, `cost_price` carried; a queued "Pull now" (`source_pull`) runs first on the next pass with the same `pull_id` and its policy replaced; "Pull now" within 5 minutes refused with the time; a `search` pull (`source_search` on the Shopify fixture: suggest.json then `.js`) writes listings like a pull and never counts for removals.
- [ ] **Removal and the ladder**: the Shopify source's settings changed to `collections: [mattresses]` → two full pulls see only the mattress → the topper and the pillow `removed_at` set after the second, their variants `availability unknown`, `qty` NULL, a snapshot saying so, `listings_removed 2` on that pull, `listing.removed` ×2, the mattress's match kept; a `partial` pull (a jsonld source with `urls[]` holding a walled page after two good ones) marks nothing removed; the walled store → blocked, blocked, blocked: an hour, a day, then `paused_at` with the reason "3 failures in a row (last: …)", the Buyer told once per rung (three `pull_failed`/`pull_blocked` notifications, dedupe keys `pull_failed:<id>:1..3`), `source.pull_blocked` logged three times, the source skipped by `inv_sources_due()` while backing off (`INV_WORKER_NOW` proves each rung); resume → cleared, `robots_state unknown`; a credential that cannot be opened (the proof tampers the ciphertext) → the pull `failed` with "the credential cannot be opened — set it again" and no secret in the error.
- [ ] **Matching**: a cross-size manual match refused by `inv_listing_match()`'s sentence; a manual match kept across the next pull (`match_kind manual` survives the upsert); unmatch; an inactive variant never matched by the rules; the expert (run token + relay) matching a pair with a shared GTIN → admitted; a pair with nothing shared → 403 in the handler's words; the expert accepting the matcher's proposal → the SQL's refusal; Nora proposes a pair (a person's) → the expert's `listing_match` on it lands as `proposed_accepted` through `inv_proposal_accept()`; a dismissed pair stays dismissed after `inv_propose_after_pull()` (not re-scored); `listing.match|unmatch|propose|accept|dismiss` logged with `source_id`, `sku`, `match_kind`, `confidence`.
- [ ] **The queue**: after the pulls, `inv_unmatched_listings()` lists the Woo store's unmatched variations and the removed ones not at all; `inv_propose_after_pull()` wrote proposals for the ones scoring ≥ 0.5 (the Cloudrest topper against the catalog's topper: brand true, name tokens, size true); the best proposal's evidence in words on the row; Accept → matched `proposed_accepted`, the other proposals of that variant dismissed, gone from the queue; Dismiss → the next best surfaces; Pick another → `listing_match`; Score again → fresh proposals with proposer NULL (the matcher's); Not ours → `forgotten_at` set, every variant unmatched, hidden from the queue, not re-matched by the next pull (rule 1 would have), not scored, "already marked not ours" on a second forget, `mcp_listings.forgotten_at` visible; `min_confidence` and `source` filters; Sam → 403 (`listings.match`).
- [ ] **The listing view**: the variants table with the match column and the chips; the chart for the Queen after the three pulls: two change points and the bands in_stock → out_of_stock; `inv_snapshot_heartbeat()` called by SQL after `INV_WORKER_NOW` +1 day → a heartbeat point that draws no marker; the snapshots table with the heartbeat mark; `since` 30 / 90 / 180; cost on a feed variant shown to Nora, "—" to Sam and Vera (`inv_offer_history()` nulls it); the raw fields for Nora, absent for Vera (the view); the proposals card; the trail.
- [ ] **Price sheets**: Nora adds a row by hand (cost 189.00, lead 5, MOQ 2), changes it inline, removes one; the feed's rows show their source; Sam's `cost` → refused in words, Vera reads the table with cost "—"; `supplier.item_save|item_remove` logged.
- [ ] **Health and templates**: `inv_source_health()` answers ok / stale (`INV_WORKER_NOW` +3 days) / failing / blocked / paused / manual / never_pulled across the six; the cards' chips and sentences agree with it; the next due time; the templates screen lists the nineteen seeded with `unverified` and the finding's note; `find_sources(templates: true)` the same rows.
- [ ] **Survey**: `bin/source_survey.php 127.0.0.1:8606 --record` against a template whose `base_url` the proof points at the fixture server → `survey_result open`, `surveyed_at` set, logged `source.update` on the template; a host with no template changes nothing.
- [ ] **JSON mode**: every handler under a signed action token answers `{ok, did, record_id, location, refresh}` with its facts (a create's `record_id`, `source_pull`'s `queued: true` and the pull id, `source_probe`'s state and facts, `source_search`'s envelope with `asked[]` and `rows[]`); `_partial=1` on `source_update` keeps the untouched settings; 422 `{error: {code: invalid, fields}}` naming the field; the expert (run token + relay) creates a source (**other** on the MCP path — Phase 4), queues a pull, matches by GTIN, is refused `source_delete`; under an eval run (`is_eval` from the fake kernel's facts) `source_search` persists no pull row, no listing, no snapshot and logs `eval: true`.
- [ ] **375 × 740 and 1280 × 800**: the source form's connector switch and the feed's preview-then-mapping flow (a header chosen, a letter typed, the missing column's error on its select); the manual editor's rows; the probe's inline result; the credential form's password never pre-filled; the listing view's chart with a hover tooltip naming the pull and the bands' legend, the table beneath; the match queue's four buttons per row; the cards stacking at 375; every control ≥ 44 px, `scrollWidth` = viewport, no console errors; JavaScript off saves a source, sets a credential and accepts a proposal; the registry reads 54 screens and 63 actions built.

**Decisions taken in this spec (not questions):**
- `db/017_listing_forget_share_log.sql` (the lead's builder's, with Phase 1 — reconciled 2026-10-05) is the additive migration this slice relies on: `listings.forgotten_at` / `forgotten_by`, `inv_listing_forget()`, a forgotten guard replaced into `inv_match_listing_variant()`, `inv_propose_matches()`, `inv_listing_match()`, `inv_unmatched_listings()`, `mcp_listings` appended — "not ours" needs a column the schema lacks, and `removed_at` is cleared by the next upsert.
- A fresh scoring asked from a screen is the matcher's (`inv_propose_matches(lv, NULL)`); a named pair is the asker's proposal. An agent's `listing_match` is admitted only by a shared identifier or a person's pending proposal (then recorded through `inv_proposal_accept()`).
- The Buyer's notice carries the kind the status names (`pull_failed` / `pull_blocked`); the dedupe key is connectors.md's.
- The glue logs `listing.new|changed|removed` and `offer.change` from the pull's own snapshots in one pass after the pull, ≤ 500 rows a pull.
- `source_search`'s handler enforces `orders.write` (design A8; reconciled 2026-10-05); it persists nothing under an eval run.
- The manual editor draws one listing with one variant per row; nested `variants[]` from JSON is accepted as given.
- The source form's sub-forms are all rendered server-side and switched by script; the feed's preview is a Pattern A POST to `/sources/preview.php` that keeps no file.
- Slice 3's proof runs no fake MaluMail (8606 is the fixture server's; `servers.sh start --no-malumail`); the fixture gains `products-page1.v2.json` and the router a `INV_FIX_VARIANT` switch so a price change can be pulled; the GTINs, supplier SKUs, MPN and the marketplace id the matcher is proven on are read from the fixture files at seed time.
- The chart is slice 1's `shared/series-chart.php` with `bands` filled from `availability_runs()`; a heartbeat point draws no marker; a point's tooltip names its pull.

## Open questions
(none)

## Built and proven
**2026-10-09 — BUILT and proven by the planning model — THE EXEMPLAR** (`tests/phase3/slice3/run.sh` on the scratch database `inv_dev3` with the
fixture server on 8606: **290 checks green under php -S and under a real Apache** — world 6, sources 39, credentials 18, probe 16, pulls 45, removal and the ladder 25, matching
20, queue 25, listing view 19, price sheets 8, health and templates 11, survey 5, json 19, browser 32 at 1280 × 800, 375 × 740 and JavaScript off; the
registry — **54 screens and 63 actions built**, 21 placeholders — and the approvals in step). Slice 2 re-run green (325), slice 1 (310), Phase 2 (329, its placeholder count 21), Phase 0 (42 + 517 + 511), the Phase 1 claim
checks (52), the installer's plan clean (57 steps). Every file of "Files" is built, plus
`tests/fixtures/sources/shopify/products-page1.v2.json` and two router additions (a fixture's second version by `INV_FIX_VARIANT` or the word in
`$INV_FIX_TMP/variant`; everything under `/wall/` is the bot wall) and **`db/019_probe_not_a_pull.sql`**. The worker's `pulls` pass is live
(`bin/worker.php`'s hook uncommented — the only change there).

**Found and fixed (not questions):**
- **A probe made a new source look pulled** (`db/016` `inv_source_pull_finish()`): an `ok` finish of ANY kind set `last_ok_at`, the clock of
  `inv_sources_due()` and `inv_source_health()` — so probing a new source put its health at "ok" and kept the worker from its FIRST real pull for a
  whole schedule (a day for a marked-up site). `db/019` copies db/016's body and moves `last_ok_at` for `scheduled` and `manual` pulls only; a probe or a
  search that answers still clears the ladder and records the robots verdict.
- **The Buyer's notice key silenced every later streak**: `pull_failed:<source>:<rung>` is remembered by the outbox, so a source resumed and failing
  again was never told its first and second rungs again. The key now names the streak too — `pull_failed:<source>:<rung>:<the streak's first failed
  pull>` — once per rung of a streak, as the spec meant (DECISION; `inv_pull_notify()`).
- **A blocked or failed probe tells the Buyer too** (the spec's probe proof says so; the glue's `run_probe()` calls `inv_pull_notify()`).
- **`view()` keeps the template's path in `$template`**: a view whose data carries a key named `template` loses it — the source form's data key is
  `tpl`. A kit lesson for every later slice.
- **One `availability_chip()`**: slice 1 had written one; slice 3's vocabulary (limited `success`, discontinued `dark`) replaced its colours.
- **The kit's `db_message()`** maps the sources' CHECKs (`sources_check` → "A supplier source names its supplier — choose the supplier, or make it a
  reference source.", the rate's) and gains `db_raise_text()` (a RAISE's sentence whatever its code — the agent's refused accept answers 403 with the
  SQL's own words).
- **Run facts are fetched on an agent's first admission only** (`app/bootstrap.php`), so `source_search` asks the kernel for the run's facts itself
  when an agent calls it, to honour `is_eval` (nothing persists under an eval run).

**Decisions taken while building (not questions):**
- **The proof ages rows instead of `INV_WORKER_NOW`**: the SQL's `now()` decides what is due, removed or backing off, and PHP cannot move it —
  `age_source()` moves a source's `last_ok_at`, `backoff_until`, its pulls' times and its listings' `last_seen_at` back by the interval.
- **The fixtures differ from the spec's counts in three places, and the proof follows the fixtures**: the Shopify fixture has ten listing variants
  (6 + 3 + 1), eight matched by GTIN (the two Queen toppers carry the catalog's barcodes too); eighteen templates are seeded, not nineteen; the
  marked-up page's King carries the same GTIN as ours, so rule 3 (MPN + size) is proven with our King's barcode cleared for that one pull.
- **The matcher needs candidates**: the world adds a King topper with no barcode (the Shopify King topper scores 0.513 against it — name words, size,
  type) and titles the price sheet's rows "SMOKE Cloudrest Hybrid Twin XL — phone quote" / "SMOKE Cloudrest Foundation Queen — phone quote" (brand in
  the title, all name words, size → 0.75 and 0.63).
- **The feed mapping is a text field with the file's headers as a datalist**, not a select with a free-text escape: a header, a column number or a
  letter in one control, with no script and no JavaScript needed; a column the file lacks is the field's error both on "Read the file" and on save.
- **The credential form renders each field once across the connector's kinds** (a `basic` and an `sftp_password` share username and password); the
  script hides and disables the fields the chosen kind does not take.
- **A search's `size` narrows the answer's rows** (a listing variant of another size is left out), never the query sent to the store.
- **The match queue's row**: Accept · Dismiss · Not ours in one row, Pick another (the picker) beneath it, Score again under them when a proposal exists
  (it is the row's fourth button when none does).
- **The source form's sub-forms are fieldsets the script disables when not chosen**, so only the chosen connector's settings are posted; with
  JavaScript off all are posted and the handler reads only the chosen connector's (`settings_<connector>[…]`).
- A partial pull is not a failure: it clears the ladder (the schema's), and it never removes (the glue's).
- The templates screen is `sources.write`'s (the menu's right), as Phase 2's matrix holds.

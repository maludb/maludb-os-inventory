# Build spec: the connectors — the contract of `app/sources/` and slice 3's worker glue

Written by the planning-class model (Phase 1, 2026-10-05) from **the code as built in Phase 0** (`app/sources/`, proven by
`tests/phase0/connectors.sh` — 511 checks) and design §0.2, §0.3, §6, §6.1, §6.2, §10 (slice 3), §15 D7/D8. A worker model
relies on this document for three things: **how slice 3 glues a connector's `pull()` to the schema's worker-facing functions**,
**what a screen may ask a connector** (probe, pull now, preview, a live search), and **how a connector is added later** (the
Extended ones of D7: `ebay`, `amazon`, `walmart`, `inventory_feed`). Nothing here changes the code of Phase 0: where the spec
says *as built*, the file is the authority and this document transcribes it.

Schema (never modify; a change is a numbered additive migration with sign-off): `sources`, `source_credentials`, `source_pulls`,
`source_templates`, `listings`, `listing_variants`, `offer_snapshots`, `match_proposals`, `supplier_items`,
`inv_sources_before()`, `inv_listing_variants_before()`, `inv_record_offer()`, `inv_snapshot_heartbeat()`,
`inv_match_listing_variant()`, `inv_set_match()`, `inv_listing_match()`, `inv_listing_unmatch()`, `inv_propose_matches()`,
`inv_proposal_accept()`, `inv_proposal_dismiss()`, `inv_source_pull_start()`, `inv_source_pull_finish()`, `inv_source_resume()`,
`inv_upsert_listing()`, `inv_mark_removed()` (db/009); `inv_sources_due()` (db/014); `inv_connectors()`, `inv_availability_states()`,
`inv_ships_how_kinds()`, `inv_setting_int()`, the crawl columns of `inv_settings` (db/005); `inv_notify()`, `inv_fire_watches()`,
`agent_dispatches` (db/013); `mcp_sources`, `mcp_source_credentials`, `mcp_source_pulls`, `mcp_source_templates`,
`mcp_listings`, `mcp_listing_variants`, `mcp_offer_snapshots`, `mcp_match_proposals` (db/015).

**The division of labour, in one sentence** (design §6.1): a connector only **reads** and returns the normalized listing; the
schema **matches, snapshots and keeps the price sheet** inside `inv_upsert_listing()`; the worker **drives** (due sources, one
transaction per listing, the pull row's counts and policy facts, removals, proposals, logging, notifications); the screens
**ask** (probe, pull now, preview, live search) and **show** (health, pulls, the credential's label and last four).

---

## 1. The interface (`app/sources/Connector.php`) — as built

### 1.1 Files and the front door
| File | Holds |
|---|---|
| `app/sources/registry.php` | **the only list of connectors** — `inv_connectors(): array` (key → `['key','class','label','description','capabilities']` in screen order) and `inv_connector(string $key): InvConnector` (`InvMisconfigured` for an unknown key, the Extended ones included until added); `require_once` it and the whole module is loaded |
| `app/sources/Connector.php` | `interface InvConnector`, `InvNotSupported`, `InvMisconfigured`, `inv_probe_result()`, `inv_pull_stats()`, `inv_capabilities()`, `inv_setting()`, `inv_base_url()` |
| `app/sources/normalize.php` | the normalized shape and its validators: `inv_normalize_listing()`, `inv_normalize_variant()`, `inv_listing_valid()`, `inv_availability_state()`, `inv_gtin_normalize()`, `inv_money()`, `inv_money_minor()`, `inv_currency()`, `inv_ships_how()`, `inv_size_key()`, `inv_size_key_in()`, `inv_size_label()`, `inv_str()`, `inv_int()`, `inv_tags()`, `inv_option_values()`; the constants `INV_AVAILABILITY_STATES`, `INV_SHIPS_HOW`, `INV_SIZES`, `INV_AVAILABILITY_WORDS` |
| `app/sources/http.php` | `final class InvHttp` (the ONE outbound client), `inv_http_client()`, `inv_http_get()`, `inv_http_user_agent()`, `inv_http_json()`, `inv_http_resolve()`, `inv_http_blocked_reason()`, `inv_http_response_shape()`, `inv_http_curl_transport()`, `inv_http_default_cache_dir()` |
| `app/sources/robots.php` | `inv_robots_parse()`, `inv_robots_group()`, `inv_robots_allows()`, `inv_robots_crawl_delay()`, `inv_robots_pattern_matches()` |
| `app/sources/credentials.php` | **the only file that decrypts**: `inv_seal()`, `inv_open()`, `inv_secrets_key()`, `inv_secrets_key_generate()`, `inv_credential_summary()`, `inv_credential_secret_field()`, `INV_CREDENTIAL_KINDS`, `InvCredentialError` |
| `app/sources/connectors/{shopify,woocommerce,jsonld,feed,manual}.php` | `InvConnectorShopify`, `InvConnectorWooCommerce`, `InvConnectorJsonLd`, `InvConnectorFeed`, `InvConnectorManual` |

Slice 3 adds **one** file to the module: `app/sources/pulls.php` (the worker glue of §6 — `bin/worker.php`'s `pulls` pass hook names it
and `sources_run_due_pulls()`), and the feature files of the slice spec (`app/features/sources/{queries,present,write,handler}.php`,
`app/features/listings/…`). Nothing else under `app/sources/` changes for slice 3.

### 1.2 The `$source` array a connector receives
Every method takes the same row-shaped array. The glue builds it (`inv_source_for_connector()`, §6.2) — a connector never reads the
database and never opens a credential.

| Key | Type | Meaning |
|---|---|---|
| `connector` | string | the key (`shopify` …) — informational; the caller already chose the class |
| `base_url` | string | the store's origin (`https://store.example`); `inv_base_url()` strips the trailing slash and throws `InvMisconfigured` unless it is an absolute http(s) URL. `manual` ignores it; `feed` ignores it (its URL is in `settings`) |
| `settings` | array (or a JSON string — `inv_setting()` decodes either) | the per-connector settings of §3; `inv_setting($source, $key, $default)` treats `null` and `''` as absent |
| `credential` | array or null | **the opened credential** (`inv_open()`'s array: `kind` plus the fields of §5.1) — never the ciphertext |
| `rate_per_second` | number | `sources.rate_per_second` (the trigger already caps it at `inv_settings.crawl_rate_per_second`); default 1 |
| `user_agent` | string or null | the UA for this source — the glue resolves it (§6.2); `null` = `INV_CRAWL_USER_AGENT`, else the client's default which says it is unconfigured |
| `timeout` | int seconds | per request (default 20; the `feed` connector overrides to 120) |
| `cache_dir` | string or null | tests only — a scratch cache; production uses `storage/http-cache` |
| `transport` | callable or null | tests only — answers requests from fixtures; production uses cURL |
| `sftp_runner` | callable or null | tests only (`feed`) — receives the SFTP command plan instead of executing it |

### 1.3 The normalized listing — the ONE shape every connector returns
`inv_normalize_listing(array $in): array` fills every key, normalizes the identifiers and validates; `inv_listing_valid($listing, &$why)` is
true exactly for this shape (nine listing keys, sixteen variant keys, no extras, at least one variant). A connector always returns the
normalizer's output, never a hand-built array.

**Listing** (exactly these nine keys):

| Key | Type | Rule |
|---|---|---|
| `external_id` | string ≤ 200 | the source's id for the product; falls back to `handle`, then `sha1(url)[0:16]`; a listing with none throws `InvalidArgumentException` |
| `handle` | ?string ≤ 200 | the URL slug when the platform has one |
| `url` | ?string ≤ 1000 | the product page |
| `title` | string ≤ 300 | trimmed; `''` when the source gave none (the schema refuses a NULL title, never an empty one) |
| `vendor` | ?string ≤ 200 | the brand |
| `product_type` | ?string ≤ 200 | the seller's category word |
| `tags` | string[] | from an array or a comma string; trimmed, unique, ≤ 100 chars each |
| `raw` | array | **trimmed** — what the connector read and nothing else: `images[]` (≤ 5 URLs — images are not a §6.1 field, DECISION in the code), the option names, dates, and the connector's own flags (`storefront`, `jsonld`, `opengraph`, `feed`, `manual`, `suggest`); the schema replaces a `raw` over `inv_settings.raw_max_bytes` (8,192) with `{"_trimmed": true}` |
| `variants` | array | one or more variants; a listing given no variants gets ONE from its own fields (`price`, `sku`, `barcode`, `availability`, `qty` … on the listing level) so every listing has a sellable unit |

A `currency` key on the INPUT is a default for the variants and is removed from the output.

**Variant** (exactly these sixteen keys):

| Key | Type | Rule |
|---|---|---|
| `external_variant_id` | string ≤ 200 | the source's id; defaults to `<external_id>:<n>`; made unique within the listing by suffixing `:<n>` |
| `title` | string ≤ 300 | the variant's own, else its option values joined by ` / `, else the listing's title |
| `option_values` | `{name: value}` | strings only, empties dropped; `[{name, value}]` lists accepted |
| `size_key` | ?string | one of `INV_SIZES`' keys (`twin`, `twin_xl`, `full`, `full_xl`, `queen`, `olympic_queen`, `rv_short_queen`, `king`, `california_king`, `split_king`, `split_california_king`, `crib`): from a Size-named option, then any option value, then the variant title, then the listing title — longest synonym first, on word boundaries, one-letter codes (`K`, `Q`, `T`, `F`) only as a whole token. **Advisory**: the schema derives its own `size_key` by trigger (`inv_listing_variants_before()`) from the same settings; PHP's and SQL's agree on every seeded size and an unknown word is `NULL` in PHP and a slug in SQL (design §16) |
| `sku` | ?string ≤ 100 | trimmed |
| `barcode` | ?string | **GTIN-14** (digits only, check digit verified, left-padded) or `null` when the value is not a valid GTIN-8/12/13/14 (an 11-digit UPC that lost its leading zero is repaired); the raw text is NOT kept here — the schema's trigger keeps a raw barcode text only when PHP passed one |
| `mpn` | ?string ≤ 100 | trimmed |
| `price` | ?string `"1299.00"` | `inv_money()`: currency marks and thousands separators dropped, `"1.299,00"` understood, negatives → `null` |
| `compare_at_price` | ?string | **dropped when not above `price`** (DECISION in the code: Shopify stores fill it equal) |
| `currency` | ?string `USD` | ISO 4217, upper-cased; the variant's, else the listing's default |
| `cost_price` | ?string | the dealer's cost — `feed` and `manual` only; every other connector leaves it `null` |
| `availability` | string | one of the seven states of §0.3 — `inv_availability_state($word, $available, $qty)` in that order of authority: a seller's word (schema.org URLs reduced to their last segment; the word lists of `INV_AVAILABILITY_WORDS`; a sentence holding one), then a boolean (`true` + a known quantity of 0 → `back_order` — a store that keeps selling when out of stock, DECISION in the code), then a quantity, else `unknown` |
| `qty` | ?int ≥ 0 | the source's quantity when it gives one |
| `lead_time_days` | ?int ≥ 0 | |
| `ships_how` | ?string | `parcel`, `ltl`, `white_glove`, `pickup_only` with a few synonyms (`ground`, `freight`, `in home`, `will call` …), else `null` |
| `url` | ?string ≤ 1000 | the variant's page, else the listing's |

**The MAP is not a field** (DECISION in the code): the `feed` connector puts a mapped `map_price` column in `raw.map_price[<external_variant_id>]`.
Slice 3 shows it on the listing view; a person sets the variant's `map_price`; automatic adoption is Extended.

### 1.4 The five methods
```php
interface InvConnector {
    public function probe(array $source): array;
    public function pull(array $source, callable $emit): array;
    public function search(array $source, string $q, int $limit = 20): array;
    public function lookup(array $source, array $identifiers): array;
    public function capabilities(): array;
}
```

**`capabilities(): array`** — the eight keys, always all present (`inv_capabilities()` fills the defaults):
`has_search` (bool), `has_lookup` (bool), `gives_qty` (bool or a sentence such as `'with a Storefront token'`), `gives_cost` (bool),
`needs_credential` (bool or a sentence), `is_reference` (bool — false for all five of v1), `lookup_by` (string[] — the identifier keys
`lookup()` takes), `credential_kinds` (string[] — the `source_credentials.kind` values it accepts). A screen reads `gives_qty` and
`needs_credential` as "truthy with a note". No v1 connector is a reference by nature — `sources.role` says what the business does with it.

**`probe(array $source): array`** — "can this source be read as configured?" Returns `inv_probe_result(state, message, facts)`:
`['state' => 'ok'|'blocked'|'misconfigured', 'message' => one sentence for the screen, 'facts' => array]`. **At most a handful of
requests** (robots.txt, one page, one handshake). `blocked` = robots.txt refuses crawlers at the door, or disallows the endpoint for
our user-agent, or the endpoint answered a block (§2.6); `misconfigured` = not that platform, a missing setting, a rejected credential,
a file that could not be read, a mapped column absent from the file; `ok` with the first thing read named. Throws `InvMisconfigured` for
a bad `base_url` (the caller turns it into a `misconfigured` result with the exception's sentence — §6.4). The `facts` keys are the
connector's own (§3) and never carry a secret.

**`pull(array $source, callable $emit): array`** — read everything; call `$emit(array $listing)` **once per normalized listing, as soon
as it is ready** (the caller writes it in its own transaction and returns); never collect first. The return is `inv_pull_stats()`'s shape:

| Key | Meaning |
|---|---|
| `status` | `ok` (nothing failed), `partial` (something was read, then a block or an error), `failed` (nothing read, errors), `blocked` (nothing read, a block) — computed by `inv_pull_stats()` from the HTTP facts and the errors unless the connector says otherwise |
| `listings_seen` | how many times `$emit` was called |
| `http_requests`, `bytes`, `cached` | from the client's facts (robots.txt counts; skipped requests do not; a cached answer counts as a request with 0 bytes) |
| `errors` | sentences ≤ 200 chars, in order |
| `policy` | **the client's `facts()` verbatim** (§2.8) — `user_agent`, `rate_per_second`, `proxy` (bool), `robots` (host → `{state, crawl_delay}`), `http_requests`, `bytes`, `cached`, `blocked`, `robots_skipped`, `requests[]` |
| extra keys | per connector: `storefront`, `pages`, `pages_skipped_by_robots`, `pages_without_product`, `sitemap`, `via`, `rows`, `rows_skipped`, `format`, `file_bytes`, `entries` |

`$emit` **must not throw** into the connector (§6.3): a connector does not guard against it (manual catches only its own
`InvalidArgumentException`), so a throwing `$emit` would abort the pull.

**`search(array $source, string $q, int $limit = 20): array`** — normalized listings matching `$q`, read **live**, at most `$limit`.
Only where the platform searches (`shopify`, `woocommerce` in v1); every other connector throws `InvNotSupported` and Find answers from
the last pull. A block during a search throws `InvMisconfigured('search blocked: <reason>')` (as built) — the caller treats both
exceptions as "this source cannot be asked live now" (§6.6).

**`lookup(array $source, array $identifiers): array`** — normalized listings for a few identifiers; the keys a connector takes are its
`capabilities()['lookup_by']` (`handle`/`handles`/`url` for Shopify, `id`/`ids`/`sku` for WooCommerce, `url`/`urls` for jsonld); an
unknown key → `InvNotSupported`; an unknown thing → `[]` (never an exception). `feed` and `manual` throw `InvNotSupported`.

**Exceptions**: `InvNotSupported` (no such door), `InvMisconfigured` (cannot be read as configured), `InvCredentialError` (a seal that
does not open — raised by the glue when it opens the credential, never inside a connector), `InvalidArgumentException` (from the
normalizer — a listing with no id; a connector bug, surfaced as a pull error, not a crash), `RuntimeException` from the XLSX reader
("not a readable XLSX", "the zip extension is needed"). A transport failure is never an exception: the client answers a response with
`ok = false` and `reason = 'transport:<curl error>'`.

### 1.5 The helpers every connector uses (so a new one does too)
`inv_probe_result(string $state, string $message, array $facts = [])` · `inv_pull_stats(?InvHttp $http, int $seen, array $errors = [],
?string $status = null, array $extra = [])` (pass `null` for a connector without a network — `manual`) · `inv_capabilities(array $set)` ·
`inv_setting(array $source, string $key, mixed $default = null)` · `inv_base_url(array $source): string` · `inv_http_client(array $source,
array $opts = []): InvHttp` (the client for a source row; `$opts` override `robots`, `max_bytes`, `timeout`, `headers`) ·
`inv_http_json(array $resp): ?array`.

---

## 2. The crawl policy — as `http.php` enforces it (design §0.2)

Everything a connector fetches goes through one `InvHttp` per call (one client = one host's robots.txt read once, one facts record).
`InvHttp::__construct(array $opts)` takes `transport`, `user_agent`, `rate_per_second` (default 1; ≤ 0 → 1), `cache_dir`
(default `storage/http-cache`), `timeout` (default 20 s, min 1), `max_bytes` (default 8 MB, min 1 KB), `proxy` (default
`INV_HTTP_PROXY`), `robots` (default true), `headers` (extra headers on every request). `get($url, $opts)` and `post($url, $body, $opts)`;
per-request `$opts`: `accept`, `headers`, `content_type`, `timeout`, `max_bytes`, `robots` (false only for a supplier's file — §3.4).

### 2.1 The honest user-agent
`inv_http_user_agent($opts)`: the source's override, else `INV_CRAWL_USER_AGENT` (env, then the kit's `env()`), else
`InvHttp::DEFAULT_USER_AGENT` = `MaluDB-Inventory/0.1 (+no contact configured; set INV_CRAWL_USER_AGENT)`. Newlines are stripped. The glue
resolves the UA from the settings before the client sees it (§6.2); the default is a visible sign of a missing configuration, recorded in
every pull's policy — the source page shows it as a warning.

### 2.2 robots.txt
Fetched **once per host per client** (`robotsFor($url)`; its own request is recorded like any other, with `is_robots`) and consulted
before every request (`allows($url)`): an absent file (404, 410, an HTML page) allows everything (`state none`); a readable file is parsed
(`state ok`); **a 403, 429 or a bot wall on robots.txt itself = nothing allowed** (`state blocked`, DECISION in the code: the host has
refused crawlers at the door); a 5xx or a transport error (`state error`) allows (the fetch may still fail on its own). Group choice: the
longest `User-agent` token found inside our UA (case-insensitive), else `*`; inside a group the longest matching `Allow`/`Disallow` wins
(`Allow` on a tie), `$` anchors, `*` wildcards; `Crawl-delay` read per group; `Sitemap:` lines collected. **A disallowed path is skipped,
never fetched** (`skipped = true`, `reason = 'robots_disallow'`, counted in `robots_skipped`).

### 2.3 One request at a time per host, at most `rate_per_second`
`waitForHost()`: a lock file per host under `<cache_dir>/hosts/<host>.lock` holds the last request's time **across processes** (the worker and
a screen's probe share it); the wait is `max(1 / rate, Crawl-delay)` capped at 60 s; `flock(LOCK_EX)` serializes. The proof shows two
fetches at rate 1 take ≥ 1 s apart and a `Crawl-delay: 2` raises it to 2 s.

### 2.4 The ETag / Last-Modified cache
GET answers with an `ETag` or `Last-Modified` are stored under `<cache_dir>/<xx>/<sha1(ua + url)>.{body,meta.json}` (the body and the
kept headers `content-type`, `etag`, `last-modified`, `x-wp-total`, `x-wp-totalpages`); the next GET sends `If-None-Match` /
`If-Modified-Since`; a 304 answers from the cache as a 200 with `cached = true`, 0 bytes, counted in `cached`. POSTs are never cached.
Nothing evicts the cache — the glue prunes it (§6.7).

### 2.5 Redirects and the login rule
Redirects (301, 302, 303, 307, 308 with a `Location`) are judged hop by hop (cURL never follows on its own), at most **five**
(`too_many_redirects`); 301/302/303 turn a POST into a GET; a relative `Location` is resolved (`inv_http_resolve()`). **A redirect whose
target path matches `InvHttp::LOGIN_PATH`** — `/account/login`, `/account/signin`, `/login`, `/signin`, `/sign-in`, `/sign_in`,
`/customer(s)/…`, `/customer/account`, `/users/sign_in`, `/user/login`, `/auth/login`, `/wp-login.php`, `/my-account` — **is refused as
blocked** (`reason = 'login_redirect'`) and the login page is never requested.

### 2.6 Blocked — the list (`inv_http_blocked_reason()`)
`http_403`; `http_429` (a sandbox's own `local_rate_limited` body is named `http_429:local_rate_limited` so a survey never records it as the
site's answer); and, on an HTML body ≤ 512 KB (the first 64 KB inspected): `bot_wall:cloudflare` (`Just a moment`, `cf-browser-verification`,
`cf_chl_`, `challenge-platform`, `Checking your browser`, `cf-challenge`; also a 503 mentioning cloudflare), `bot_wall:perimeterx`
(`px-captcha`, `_pxhd`, `Press & Hold`), `bot_wall:akamai` (`errors.edgesuite.net`, `Reference #`, `<title>Access Denied`),
`bot_wall:imperva` (`Pardon Our Interruption`, `Incapsula`), `bot_wall:datadome`, `bot_wall:captcha` (a title saying access denied /
verify you are human / are you a robot / bot verification / security check, or a reCAPTCHA/hCaptcha widget beside those words). A JSON
200 is never a wall; an ordinary 404 page is not one. A blocked response has `blocked = true`, `ok = false`, the reason, and counts in
`blocked`. **A connector that sees `blocked` stops** (a pull ends `blocked` or `partial`; it never retries faster) — the back-off ladder
is the schema's (§6.3).

### 2.7 Limits, proxy, transport
Timeouts (connect ≤ 10 s, total `timeout`), a byte ceiling (`max_bytes`; over it → `reason = 'transport:max_bytes'`, no body), gzip
accepted, TLS verified, http/https only, `INV_HTTP_PROXY` when set (**one** proxy the business runs — never a pool; the facts say
`proxy: true`). The transport is injectable (`transport` callable receiving `{method, url, headers, body, timeout, max_bytes, proxy}` and
answering `{status, headers, body, error}`) — tests pass one that answers from fixtures or canned responses.

### 2.8 The response and the facts
A response: `['ok', 'status', 'headers' (lower-cased), 'body', 'bytes', 'cached', 'blocked', 'skipped', 'reason', 'url' (the final URL),
'elapsed_ms']`. `facts()`: `user_agent`, `rate_per_second`, `proxy`, `robots` (host → `{state, crawl_delay}`), `http_requests`, `bytes`,
`cached`, `blocked`, `robots_skipped`, `requests[]` (each `{host, path, status, bytes, cached, blocked, skipped, reason, ms}`). The glue
turns the facts into `source_pulls.policy` (§6.3) — the SQL expects a **scalar** `policy->>'robots'`, the client gives a map.

### 2.9 What a connector may never do (refused by name — CLAUDE.md, design §0.2, D7)
Call out except through `InvHttp` (no `file_get_contents()` on a URL, no raw cURL, no second client); fetch a path robots.txt disallows;
follow a redirect to a login page; replay a person's credentials into a third party's login form; carry a session cookie; solve or bypass a
captcha or a bot wall; use a proxy pool or rotate user-agents; retry a 403/429 inside the same pull; exceed the host's rate (the client
holds the lock — a connector never sleeps or parallelizes on its own); read the database, open a credential, log, match, snapshot or
write anything; return a shape other than `inv_normalize_listing()`'s; put a whole page, a body_html, a description or a secret in
`raw`; keep a copy of a credential. The one exception to robots: **a supplier's own file at a URL they gave us is not a crawl** —
the `feed` connector sets `robots: false` for it (DECISION in the code); rate, UA, cache and block detection still apply.

---

## 3. The five connectors of version 1 (D7) — as built

Every connector: `capabilities()` as tabled; `probe()` ≤ 3 requests; `pull()` emits as it reads; `raw` trimmed; blocks → `blocked`.

### 3.1 `shopify` — a Shopify store's public catalog (`InvConnectorShopify`)
- **Endpoints and pagination**: `GET {base}/products.json?limit=250&page=N` from page 1 until a page is empty — **a short page (< 250) is the
  last and the empty page after it is not fetched** (DECISION in the code); a hard stop at `settings.max_products` (default 25,000).
  `settings.collections[]` (handles, sanitized to `[a-z0-9-_]`) → `GET {base}/collections/<handle>/products.json?limit=250&page=N` per
  collection, a product in two collections emitted once. Lookup: `GET {base}/products/<handle>.js` (prices in **cents**, `type` for
  product_type, `inventory_quantity` when the theme exposes it). Search: `GET {base}/search/suggest.json?q=&resources[type]=product&resources[limit]=N`
  then each hit's `.js`; a store without predictive search (no `resources.results.products`) falls back to **a collection whose handle equals
  the query** (`/collections/<q>/products.json`) — the "collection filter" of §6.1 — else `InvNotSupported`.
  With a credential of kind `bearer` (a **Storefront access token** a supplier gives a dealer): `POST {base}/api/<settings.api_version, default 2024-10>/graphql.json`
  with `X-Shopify-Storefront-Access-Token`, the `STOREFRONT_QUERY` (100 products a page by cursor, 100 variants each, `quantityAvailable`),
  `gid://shopify/Product/123` → `123` so the ids match `products.json`'s (DECISION in the code).
- **Settings**: `collections[]`, `currency` (default `USD` — `products.json` and `.js` carry none, DECISION in the code; the Storefront API says its own),
  `api_version`, `max_products`.
- **Credential kinds**: `bearer` (`token`); optional (`needs_credential false`).
- **Mapping** (`products.json` / `.js` → normalized; the Storefront node in parentheses where it differs):

| Normalized | Shopify |
|---|---|
| `external_id` | `id` (Storefront: numeric part of the gid) |
| `handle`, `url` | `handle`; `{base}/products/<handle>` (Storefront: `onlineStoreUrl`) |
| `title`, `vendor`, `product_type`, `tags` | `title`, `vendor`, `product_type` (`.js`: `type`; Storefront: `productType`), `tags` (array or comma string) |
| `raw` | `images[]` (≤ 5, `//cdn` → `https:`), `options[]` (names), `published_at`, `updated_at`, `grams[]` (Storefront: `images`, `options`, `updated_at`, `storefront: true`) |
| variant `external_variant_id` | `variants[].id` (Storefront: the gid's number) |
| `title` | `variants[].title`, the product's title when `Default Title` |
| `option_values` | `option1..3` named by `options[]` (`.js`: `variants[].options[]`; Storefront: `selectedOptions`), `Default Title` dropped |
| `sku`, `barcode` | `sku`, `barcode` (the GTIN when the merchant filled it) |
| `price`, `compare_at_price` | strings as given (`.js`: cents → `inv_money_minor`; Storefront: `price.amount`, `compareAtPrice.amount`) |
| `currency` | `settings.currency` (Storefront: `price.currencyCode`) |
| `availability` | `available` (bool) → `in_stock`/`out_of_stock`; Storefront `availableForSale` + `quantityAvailable` 0 → `back_order` |
| `qty` | `inventory_quantity` when present and ≥ 0 (`.js` only, rarely); Storefront `quantityAvailable` (negative → 0) |
| `url` | `{base}/products/<handle>?variant=<id>` |
| `mpn`, `cost_price`, `lead_time_days`, `ships_how` | `null` |

- **Errors and blocks**: a 403/429/wall on any page → `blocked` (nothing emitted) or `partial` (some pages read), the error names the page;
  a page without a `products` list → an error, the loop stops (`failed`/`partial`); robots disallowing `/products.json` → the probe says
  `blocked` ("robots.txt disallows …"), the pull notes it and reads nothing; a rejected Storefront token → `misconfigured` ("The Storefront
  access token was not accepted (HTTP 401)"); a non-Shopify base → `misconfigured`. **The survey's finding** (§8): a Shopify storefront
  answers a non-browser UA's `products.json` with its own 429 — that is `blocked`, truthfully; the template's note says what to do.
- **Fixture**: `tests/fixtures/sources/shopify/` — `products-page1.json` (three products: a mattress with six sizes and barcodes, a topper with
  two options, a pillow with `Default Title`), `products-page2.json` (empty), `collection-mattresses.json`, `product-cloudrest-hybrid-mattress.js`,
  `suggest.json`, `storefront.json`; the router answers `/products.json` (ETag `"p1-v1"`), `/collections/<h>/products.json`, `/products/<h>.js`,
  `/search/suggest.json`, `/api/<v>/graphql.json` (token `shpat_fixture_token`).

### 3.2 `woocommerce` — a WooCommerce store's Store API (`InvConnectorWooCommerce`)
- **Endpoints and pagination**: `GET {base}/wp-json/wc/store/v1/products?per_page=100&page=N`; stops on an empty page, past
  `X-WP-TotalPages`, or on the 400 `rest_product_invalid_page_number` a page past the end answers; a variable product's `variations[]` are
  each fetched by `GET …/products/<variation id>` (one request per variation — the rate applies); a `variation`-typed row in a list is skipped.
  Search: `?search=<q>&per_page=N`. Lookup: `/products/<id>` for `id`/`ids`; `?sku=<sku>` for `sku`.
- **Settings**: `currency` (default: what the store says in `prices.currency_code`, else `USD`), `vendor` (a brand when the store names
  none), `max_products` (default 25,000).
- **Credential kinds**: none.
- **Mapping**:

| Normalized | Store API |
|---|---|
| `external_id`, `handle`, `url` | `id`, `slug`, `permalink` |
| `title` | `name`, HTML entities decoded |
| `vendor` | `brands[0].name`, else an attribute named brand/manufacturer/vendor, else `settings.vendor` |
| `product_type`, `tags` | `categories[0].name`; `tags[].name` + the remaining categories |
| `raw` | `images[]` (≤ 5), `attributes` (name → term names), `categories`, `type`, `on_sale` |
| variant `external_variant_id` | the variation's `id` (a simple product: the product's `id`) |
| `option_values` | the variation's `attributes[]` (`name`, `value` — a term **slug** resolved to the parent's term **name**), else parsed from `variation` ("Size: Queen, …"); a simple product: every single-term attribute |
| `sku`, `barcode` | `sku` (the parent's when the variation has none); `global_unique_id` / `gtin` / `barcode` |
| `price` | `prices.price` in minor units with `prices.currency_minor_unit` |
| `compare_at_price` | `prices.regular_price` **only when `on_sale`** |
| `currency` | `prices.currency_code` |
| `availability` | `stock_status` word when a plugin exposes it (`instock`/`outofstock`/`onbackorder`), else `is_in_stock` false → `out_of_stock` (or `back_order` when `stock_availability.text` says so), true → `limited` when `low_stock_remaining` > 0 else `in_stock`, else the text |
| `qty` | `low_stock_remaining` only (`gives_qty: 'low_stock_remaining only'`) |
| `url` | the variation's `permalink`, else the parent's |

- **Errors and blocks**: a block → `blocked`/`partial`; a non-list answer → `misconfigured` (probe) or an error ending the pull; an
  unresolved variation (its `/products/<id>` not a product) is still emitted with the parent's prices and `is_in_stock`.
- **Fixture**: `tests/fixtures/sources/woocommerce/` — `products-page1.json` (a variable mattress with three variations, a simple protector with a
  GTIN, a backordered adjustable base), `product-201/202/203.json` (the variations); the router answers the list (`X-WP-Total`, `X-WP-TotalPages`,
  ETag `"woo-v1"`, `search=`, `sku=`, the 400 past the end) and `/products/<id>`.

### 3.3 `jsonld` — any site whose product pages carry schema.org markup (`InvConnectorJsonLd`)
- **Endpoints and pagination**: the page URLs come from `settings.urls[]` when given, else from a sitemap: `settings.sitemap_url` (absolute
  or relative to `base_url`), else robots.txt's `Sitemap:` lines, then `/sitemap.xml`, `/sitemap_index.xml`, `/product-sitemap.xml` — the first
  that is a `<urlset>` or `<sitemapindex>`; **inside an index the children named `product` are read; when none is, every child is** (≤ 20
  children, DECISION in the code); a discovered flat sitemap keeps the product-looking URLs (`/product(s)/`, `/p/`, `/item/`, `/shop/`) when
  there are any, a sitemap a person named is read whole; `settings.url_pattern` (a regex) filters; at most `settings.max_pages` (default 500)
  pages, each fetched by `GET` — one page per product, the slowest connector (**daily** — the schema's `schedule_jsonld_minutes`). Lookup:
  `url`/`urls`. No search.
- **What a page yields** (`listingsFromHtml()`): every `<script type="application/ld+json">` block decoded (a lenient retry strips control
  characters and trailing commas), walked through `@graph`, lists, `mainEntity`, `itemListElement`, `item`, `about`, `mainEntityOfPage` (depth ≤ 8)
  for nodes typed `Product`, `ProductGroup`, `ProductModel`, `IndividualProduct`, `SomeProducts`, `Vehicle`, `ProductCollection`; a product
  that is a `hasVariant` child of a group already held is dropped (never twice). **Normally one listing per page; an `ItemList` page whose items
  are whole Products yields each** (DECISION in the code). With no Product node, **Open Graph** is the fallback: `og:type` product or
  `product:price:amount`, with `og:title`/`<title>`, `product:brand`, `product:retailer_item_id`/`product:sku`, `product:gtin`/`ean`/`upc`,
  `product:mfr_part_no`, `product:price:currency`, `product:original_price:amount`, `product:availability`, `og:url`, `og:image`.
- **Settings**: `sitemap_url`, `urls[]`, `url_pattern`, `max_pages`, `currency` (a default when offers name none).
- **Credential kinds**: none.
- **Mapping** (a Product / ProductGroup node → normalized):

| Normalized | schema.org |
|---|---|
| `external_id` | `productID`, else `productGroupID`, else `sku`, else a GTIN, else `@id`, else `sha1(url)[0:16]` |
| `handle` | the URL's last path segment without `.html/.php/.aspx` |
| `url` | `url` (relative resolved against the page), else the page |
| `title`, `vendor`, `product_type`, `tags` | `name`; `brand.name` or a brand string (`manufacturer` too); `category` (the last part of a `A > B > C` chain); `keywords` |
| `raw` | `images[]` (≤ 5), `offers` (count), `conditions[]`, `jsonld: true` |
| variants | **`hasVariant[]`** (or `model[]`): each child's `@id`/`sku`/GTIN as the id, `name`, `size` (a string, a `SizeSpecification`, or an `additionalProperty` named Size) and `color` as options, `sku`, `gtin*`/`upc`/`ean`/`productID` `gtin:…`, `mpn`, its first offer's `price`, `priceCurrency`, `availability`, `inventoryLevel.value` → `qty`, `url`; **else `offers[]` with distinct `sku`s → one variant per offer**; **else one variant** from the product's own fields and its first offer — **a non-new condition (Used/Refurbished) is skipped in favour of a `NewCondition` offer unless it is the only one** (DECISION in the code), with `AggregateOffer.lowPrice` → `price` and `highPrice` → `compare_at_price`, `priceSpecification.price` when `price` is absent |
| `availability` | the schema.org URL's last segment (`InStock`, `OutOfStock`, `SoldOut`, `PreOrder`, `PreSale`, `BackOrder`, `LimitedAvailability`, `InStoreOnly`, `OnlineOnly`, `Discontinued`) through `inv_availability_state()` |
| `cost_price`, `lead_time_days`, `ships_how` | `null` |

- **Errors and blocks**: a block on the sitemap or any page → `blocked` (nothing) or `partial` (the pages before it); a page robots disallows
  is skipped and counted (`pages_skipped_by_robots`); a page without a product is counted (`pages_without_product`); an HTTP error on a page is
  an error and the pull continues; no sitemap and no URLs → `misconfigured` ("… paste the product URLs") / `failed`.
- **Fixture**: `tests/fixtures/sources/jsonld/` — `sitemap.xml` (an index with a products child and a pages child), `sitemap_products_1.xml` (five URLs:
  a ProductGroup page, a Product-in-array page with an AggregateOffer, an Open Graph page, a robots-disallowed `/private/` page, a page without a product),
  `page-cloudrest-hybrid.html` (`@graph` + `ProductGroup` with four `hasVariant` Products: gtin12, mpn, `size` string, `additionalProperty`, `SizeSpecification`,
  `inventoryLevel`, four availability states), `page-topper.html`, `page-opengraph.html`, `page-no-product.html`; `private/dealer-pricing.html` holds a
  `SECRET` price the proof asserts was never fetched.

### 3.4 `feed` — a supplier's inventory file, CSV or XLSX, over HTTPS or SFTP (`InvConnectorFeed`)
- **Transport**: `settings.url` (**https only**, or `http://127.0.0.1|localhost` for proofs) fetched by one `GET` with
  `Accept: text/csv, …spreadsheetml.sheet, */*`, **robots not consulted** (DECISION in the code — a file the supplier gave us is not a crawl),
  `max_bytes` 64 MB, `timeout` 120 s, a `basic` credential as `Authorization: Basic`; a 401 → "HTTP 401: the file needs a credential". Or
  `settings.sftp {host, port (22), user, path}` with a credential of kind `sftp_password` or `sftp_key` (`inv_feed_sftp_fetch()`): the tool is
  chosen at runtime (`inv_feed_sftp_tool()`: the `ssh2` extension, else `curl` built with sftp, else the `sftp` command, else none — reported in
  `probe()`'s facts as `sftp_tool` when the file could not be read); **the password goes in a 0600 netrc file in a private temp dir, never on argv**; a key goes in a 0600
  file (`--key`), a passphrase with `--pass`; the `sftp` command takes keys only ("… give an sftp_key credential or install curl"); host and
  user are refused when they hold characters a shell would read; a transfer error never carries the password. A test passes `sftp_runner`
  and receives the plan `{tool, argv, files: {path: 0600}}`.
- **The file**: `inv_feed_rows($bytes, $settings)` → `['columns', 'rows', 'format' => csv|xlsx]`: the format from `settings.format` or sniffed
  (`PK\x03\x04` = xlsx); `skip_rows` (lines before the header), `has_header` (default true; false → `Column 1…`), `delimiter` (guessed among
  `, ; \t |` from the first line, or `tab`), `encoding` (UTF-8/16 BOMs handled, Windows-1252 assumed for invalid UTF-8); blank rows dropped;
  rows padded or cut to the header's width; ≤ 100,000 rows. XLSX: `ZipArchive` + `SimpleXML` with entities and network off (`LIBXML_NONET`),
  the first sheet by `workbook.xml.rels`, shared and inline strings, booleans as `TRUE`/`FALSE`, dates left as serials, numbers as strings.
- **The column mapping** (`settings.mapping`, field → a column **header** (case-insensitive), a **0-based index**, or a **spreadsheet letter**
  `A`–`ZZ`): the fifteen fields `InvConnectorFeed::FIELDS` = `supplier_sku`, `gtin`, `name`, `size`, `cost`, `price`, `qty`, `in_stock`,
  `lead_time_days`, `map_price`, `mpn`, `brand`, `product`, `url`, `currency`. **Required: `supplier_sku` or `gtin`** (else `misconfigured`
  / `failed` — the probe's facts carry the file's `columns` for the screen). A mapped column absent from the file → `misconfigured` naming it
  ("Mapped columns not in the file: cost → "Wholesale"") and the pull **fails rather than guessing**.
- **Rows → listings** (DECISION in the code): a row is one sellable unit; a row with neither SKU nor GTIN is skipped and counted
  (`rows_skipped`, an error sentence, status still `ok`); **without a mapped `product` column every row is its own listing with one variant;
  with it, the rows sharing a `product` value become one listing with a variant each** (`external_id` = the product value, else the SKU, else the GTIN).
- **Settings**: `url` | `sftp{}`, `format`, `delimiter`, `has_header`, `skip_rows`, `encoding`, `mapping{}`, `currency` (default `USD`),
  `vendor` (a brand when no `brand` column), `lead_time_days` (a default for rows with none).
- **Credential kinds**: `basic` (`username`, `password`), `sftp_password` (`username`, `password`), `sftp_key` (`username`, `private_key`,
  `public_key` for ssh2, `passphrase`); optional for an open HTTPS file.
- **Mapping**:

| Normalized | Column (mapping field) |
|---|---|
| `external_id`, `title` | `product` (else `supplier_sku`, else `gtin`); `product` (else `name`) |
| `vendor` | `brand`, else `settings.vendor` |
| `raw` | `row` (the first row's number), `map_price{external_variant_id: amount}`, `feed: true` |
| variant `external_variant_id`, `sku`, `barcode`, `mpn` | `supplier_sku` (else `gtin`); `supplier_sku`; `gtin`; `mpn` |
| `title`, `option_values` | `name` (else the SKU/GTIN); `{Size: <size>}` |
| `price`, `cost_price`, `currency` | `price`; `cost` (`"$349.50"` understood); `currency` column else `settings.currency` |
| `availability` | **`in_stock` column** (`Y/N`, `yes/no`, `true/false`, `1/0`, a word) with the quantity as a tie-break, else the normalizer's quantity rule (`qty` 0 → `out_of_stock`, > 0 → `in_stock`), else `unknown` |
| `qty`, `lead_time_days`, `url` | `qty`; `lead_time_days` else `settings.lead_time_days`; `url` |
| `ships_how`, `compare_at_price` | `null` |

- **Errors and blocks**: a block on the URL → `blocked`; a transport or HTTP error, no url/sftp, a non-https url, an unreadable XLSX,
  a missing SFTP tool or credential → `misconfigured` (probe) / `failed` (pull) with the sentence.
- **Fixture**: `tests/fixtures/sources/feed/supplier.csv` (ten columns — Item Number, UPC, Description, Size, Dealer Cost, MAP, Qty Available, Lead Time (days),
  Brand, Model — seven rows incl. one with a wrong check digit and one without identifiers); the router serves `/feed.csv` (ETag `"feed-v1"`),
  `/feed-protected.csv` (basic `dealer:s3cret-feed`), `/feed.xlsx` (generated by the proof's `make_xlsx()`); SFTP is proven through the runner.

### 3.5 `manual` — a source with no door (`InvConnectorManual`)
- **No network.** `settings.listings[]` holds what a person typed: `{title|name, vendor|brand, product_type, sku, gtin|barcode, mpn, size, price,
  compare_at_price, cost|cost_price, qty, availability, lead_time_days, ships_how, url, currency, recorded_on (YYYY-MM-DD), note, recorded_by, tags,
  external_id, variants[] (the same keys per size)}`; `pull()` emits each entry as a listing; `probe()` is `ok` with the count and the latest
  `recorded_on`; `search`/`lookup` throw `InvNotSupported`. **An entry's `external_id` is its `sku`, else its GTIN, else the first variant's, else
  `manual-<n>`** (DECISION in the code). A non-object entry is an error (status `partial`). `raw` = `{recorded_on, note, recorded_by, manual: true}`.
- **Mapping**: the entry's keys as named; `variants[].size` → `{Size: …}`; `lead_time_days` and `ships_how` inherited from the entry when a variant
  has none; `cost` → `cost_price`; `gives_cost` and `gives_qty` true.
- **Credential kinds**: none. **Fixture**: inline in the proof (`group('manual …')`).
- **Slice 3 note**: the manual source's screen is its form — the `settings.listings[]` editor (one entry per row, variants per size) — and "Pull now"
  re-reads it; a price sheet PDF is an attachment on the source (`attachments.record_type = 'source'`).

---

## 4. The `feed` connector's column-mapping screen — the contract

`InvConnectorFeed::preview(array $source, int $rows = 5): array` is the screen's helper (not an interface method; the form calls it on the
`feed` class directly after `inv_connector('feed')`):
`['ok' => bool, 'error' => ?string, 'blocked' => bool, 'via' => https|sftp|none, 'columns' => [header…], 'rows' => [[cell…]…] (the first N),
'format' => csv|xlsx|null, 'row_count' => int, 'mapped' => [field → value per previewed row] (when a mapping exists), 'missing' => [field → "name"]]`.

The `source-form` for a `feed` source, in order: transport (HTTPS URL **or** SFTP host/port/user/path — one or the other; the credential is set
on the source view, §5.3), format (auto/csv/xlsx), delimiter (auto/,/;/tab/|), has header, skip rows, encoding, currency, default vendor, default
lead time; then **"Read the file"** (`POST /sources/{id}/preview` or, on a new source, with the form's values) → a table of the first five rows
under their headers, and under it **one select per mapping field** (the fifteen, `supplier_sku` and `gtin` marked "one of these is required"),
each listing the file's headers plus "column number…" / "column letter…" free text; saving stores the mapping as the select's value — a header
string, a digit string, or a letter; the `mapped` preview rows re-render after save. A mapping that names an absent column is refused as a field
error on that select (the `missing` list). The SFTP tool is shown on the form as a note ("SFTP on this server: curl" / "none — install
php-ssh2 or curl with sftp"; "the sftp command takes a key, not a password") from `inv_feed_sftp_tool()`. A password credential on a server
whose only tool is `sftp` → `probe()` says so and the form's note explains it (design §16's finding: a key works today; a password needs
`php-ssh2` or an sftp-capable curl).

---

## 5. Credentials — sealed, opened in one file, shown as a label and four characters

### 5.1 Kinds and fields (`INV_CREDENTIAL_KINDS`, `inv_credential_secret_field()`)
| kind | secret field | other fields | used by |
|---|---|---|---|
| `bearer` | `token` | `label` | `shopify` (a Storefront access token) |
| `basic` | `password` | `username`, `label` | `feed` over HTTPS |
| `sftp_password` | `password` | `username`, `label` | `feed` over SFTP |
| `sftp_key` | `private_key` | `username`, `public_key`, `passphrase`, `label` | `feed` over SFTP |
| `api_key` | `api_key` | `label`, … | Extended (`walmart` consumer id + key, `inventory_feed`) |
| `oauth_client` | `client_secret` | `client_id`, `label` | Extended (`ebay`) |
| `rsa_signing` | `private_key` | `key_version`, `label` | Extended (`walmart`) |

### 5.2 Seal and open (as built)
`inv_seal(array $cred): string` → `"v1.<base64url(nonce + secretbox)>"` under the 32-byte key from `INV_SECRETS_KEY` (64 hex characters in
`config/.env`; `getenv`, then the kit's `env()`, then `$_ENV`; never cached in a global; the installer writes it — `deploy/ROOT_STEPS.sh`,
`maludb-os.json env.required`); a fresh nonce every time (two seals of one credential differ); `inv_open(string): array` refuses an unknown version,
a malformed box, a tampered byte and another key with `InvCredentialError` whose message never carries the secret or the key;
`inv_credential_summary($cred)` → `['kind', 'label', 'last4']` — the last four characters of the secret, or for a key **the first four of its
SHA-256** (never its tail). `sodium_memzero()` on the plaintext and the key after use.

**Storage** (DECISION — the column is `bytea`, the seal is text): the handler stores `convert_to(:sealed, 'UTF8')` and the opener reads
`convert_from(ciphertext, 'UTF8')`; `source_credentials.label` and `last4` are written from `inv_credential_summary()` at the same time.
**The only file that calls `inv_open()` is `app/sources/credentials.php`'s own caller in the glue, `inv_source_for_connector()`** (§6.2) — a
screen never opens one; a tool never returns one; `mcp_source_credentials` exposes `credential_id, source_id, kind, label, last4, rotated_at,
created_by, created_at` and nothing else; `mcp_sources.settings` and `user_agent` only to `sources.write`.

### 5.3 The screen (`source-view`, "Credential")
Shows the kind, the label and `…<last4>`; "Set credential" / "Rotate" (`source_credential_set`, right `sources.credentials`, category `other`):
a full-page form with the kind (limited to the connector's `credential_kinds`), a label, and the kind's fields (a password/token input is never
pre-filled; a key is a textarea); saving seals, inserts a NEW `source_credentials` row, points `sources.credential_id` at it, sets `rotated_at` on the
old row and deletes it (a credential is never read back, so there is nothing to keep), then calls `inv_source_resume()` (a source that was paused
for a rejected credential comes back — §6.5). **Logged `source.credential_set` with `{kind, label, last4}` only.** Never in a log, an export, a
tool, a probe's facts or message (the proof asserts the password is in no fact or message), a pull's policy or an error sentence.

---

## 6. The worker glue — slice 3 builds `app/sources/pulls.php`

`bin/worker.php`'s `pulls` pass (first in `WORKER_PASSES`; runs every minute under the `inventory_worker` advisory lock, as `www-data`, source
`cron`, actor none) is a stub whose hook is written in the file: `require_once APP_ROOT . '/app/sources/pulls.php'; return
sources_run_due_pulls($pdo, $limit, $now);`. Slice 3 uncomments that hook — the ONLY change to `bin/worker.php` — and writes `pulls.php` with these
functions (signatures fixed):

- `sources_run_due_pulls(PDO $pdo, int $limit, DateTimeImmutable $now): array` — the pass; returns `['sources' => n due, 'pulls' => n run, 'queued' => n on-demand run, 'ok' => n, 'partial' => n, 'failed' => n, 'blocked' => n, 'listings' => n emitted, 'errors' => n, 'cache_pruned' => n]`
- `inv_source_for_connector(PDO $pdo, int $sourceId): array` — the `$source` array of §1.2 from the row (opens the credential)
- `inv_source_user_agent(PDO $pdo, array $row): ?string` — the resolved UA (§6.2)
- `inv_run_pull(PDO $pdo, int $sourceId, string $kind, ?int $by, ?string $query = null, ?int $pullId = null): array` — one pull end to end; returns the finished `source_pulls` row + `['connector_status', 'emit_errors']`
- `inv_pull_policy(array $stats, string $baseHost): array` — the facts → `source_pulls.policy` (§6.3)
- `inv_propose_after_pull(PDO $pdo, int $sourceId, int $limit): int` — the matcher's proposals (§6.3 step 7)
- `inv_pull_notify(PDO $pdo, array $sourceBefore, array $sourceAfter, array $pull): void` — the Buyer's notice (§6.3 step 9)
- `inv_http_cache_prune(string $dir, int $days = 30): int`

### 6.1 Due sources
`SELECT * FROM inv_sources_due()` — active, `schedule_minutes > 0`, not paused, not backing off, `last_ok_at + schedule <= now()`, no pull `running`
in the last 6 hours; ordered oldest-first. The pass takes at most `$limit` sources (default 200; the worker's `--limit`). **Before them, the queued
on-demand pulls** (§6.4): `SELECT p.* FROM source_pulls p WHERE p.status = 'running' AND p.policy->>'queued' = 'true' AND p.started_at > now() -
interval '6 hours' ORDER BY p.started_at`. Each pull is its own `try`; one failing never stops the next; the pass's report counts.
**The pass's budget** (DECISION): a pull may take minutes (a jsonld site of 500 pages at one a second); the pass runs pulls in sequence and stops
starting new ones after 50 minutes of wall time (`INV_WORKER_PULLS_BUDGET_SECONDS`, default 3000), leaving the rest for the next pass — the
advisory lock already keeps two passes apart, and the per-host lock keeps the rate even so.

### 6.2 The `$source` array (`inv_source_for_connector()`)
From the `sources` row: `connector`, `base_url`, `settings` (decoded), `rate_per_second`, `timeout` (20), and
- `credential`: `null` when `credential_id` is null; else `inv_open(convert_from(ciphertext))` — an `InvCredentialError` here (a key rotated by hand, a
  tampered row) ends the pull as `failed` with the error's sentence (never the secret) and the notice says "the credential cannot be opened —
  set it again";
- `user_agent` (DECISION — the settings say "NULL = built from the business name and contact"): `sources.user_agent`, else
  `inv_settings.crawl_user_agent`, else, when `business_name` and `business_contact_email` are both set,
  `"<business_name> InventoryBot/1.0 (+mailto:<business_contact_email>; MaluDB Inventory)"` (the name's letters, digits, spaces and hyphens only),
  else `null` (the client then uses `INV_CRAWL_USER_AGENT`, else its unconfigured default — and the source page shows "the crawler has no honest
  user-agent: set the business name and contact in Settings"). A per-source override saved on the form must contain `(+` followed by a URL or
  a `mailto:` — refused otherwise ("an honest user-agent names the business and a contact");
- the page caps (DECISION — `inv_settings.crawl_max_pages` is a cap on pages per pull for every connector): when the source's settings are silent,
  `shopify` gets `settings.max_products = crawl_max_pages × 250`, `woocommerce` `settings.max_products = crawl_max_pages × 100`, `jsonld`
  `settings.max_pages = crawl_max_pages` (the shipped default 100 is low for a site read page by page — the admin raises the setting, or names
  a `url_pattern`); a source's own `max_products` / `max_pages` wins.

### 6.3 One pull, step by step (`inv_run_pull()`)
1. **Start**: `SELECT inv_source_pull_start(:source, :kind, :by, :query)` → `pull_id` (or reuse the queued row's id — §6.4). The SQL refuses an
   inactive source, a paused or backing-off one for `scheduled`, and any kind while a pull is `running` — a refusal is the pass's error line for that
   source, not an exception out of the pass. Log `source.pull_start` (`source_id`, `after: {pull_id, kind}`).
2. **Build** `$source` (§6.2) and `$c = inv_connector($row['connector'])`. A connector key the registry does not have (`InvMisconfigured` — an
   Extended key seeded in SQL's `inv_connectors()` before its class exists) → finish `failed` with the sentence.
3. **Pull** with the emitter. `$emit` is a closure over `$pdo`, `$sourceId`, `$pullId`, a `$listingsWritten` counter and an `$emitErrors` list:
   ```php
   $emit = function (array $listing) use (...): void {
       if (!inv_listing_valid($listing, $why)) { $emitErrors[] = "listing {$listing['external_id']}: $why"; return; }   // the worker's guard
       try {
           $pdo->beginTransaction();
           $pdo->prepare('SELECT inv_upsert_listing(:s, :p, :j::jsonb)')->execute(['s' => $sourceId, 'p' => $pullId, 'j' => json_encode($listing, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
           $pdo->commit();
           $listingsWritten++;
       } catch (Throwable $e) {
           if ($pdo->inTransaction()) { $pdo->rollBack(); }
           $emitErrors[] = mb_substr($listing['external_id'] . ': ' . db_message($e, 'could not be written'), 0, 200);
       }
   };
   $stats = $c->pull($source, $emit);
   ```
   **One transaction per listing, exactly as the schema's comment says**: a failure mid-pull keeps what was read; `$emit` never throws; the pull row's
   `listings_seen/new/changed`, `variants_changed` are incremented **by `inv_upsert_listing()` itself** (the glue never updates those columns);
   inside the same call the schema records the offer (`inv_record_offer()` — a snapshot only when price, compare-at, cost, availability, qty or lead
   time changed, or the variant is new), **runs rules 1–4 of the matcher (`inv_match_listing_variant()`) on every variant** (it returns at once
   for a matched one; a person's match of rule 5 is kept because the variant's `variant_id` is already set), and keeps the supplier's price sheet
   (`supplier_items` upsert when the source has a `supplier_id` and the variant carries `cost_price`). The worker calls none of these directly.
   A `Throwable` out of `pull()` itself (a connector bug, an `InvalidArgumentException` from the normalizer, a `RuntimeException` from the XLSX
   reader) is caught: `$stats = ['status' => $listingsWritten > 0 ? 'partial' : 'failed', 'errors' => [the sentence], 'http_requests' => 0, 'bytes' => 0, 'policy' => []]`.
4. **Status**: the connector's `status`, downgraded — `ok` → `partial` when `$emitErrors` is not empty and `$listingsWritten > 0`, → `failed` when
   every emitted listing failed; `blocked` stays `blocked`. The error text = the first connector error, else the first emit error, with
   "(+N more)" when there are more, ≤ 200 chars.
5. **Removals — before finishing, only after a full read** (DECISION): when the connector's own status is `ok` (it read everything it was
   configured to read) and the kind is `scheduled` or `manual`, `SELECT inv_mark_removed(:source, :pull)` — the current pull (still `running`) is
   the newest of the window the SQL counts, and `listings_removed` lands on this pull row. Never after a `partial` or `blocked` pull (the
   listings it did not reach were not "unseen"), never after a `search` or `probe` pull (the SQL excludes those kinds anyway). The SQL's window
   (db/016; reconciled 2026-10-05) is the current pull (still `running`) plus the last pulls of kind scheduled/manual whose status is **`ok`**, until
   `removed_after_pulls` (2) are counted — a `partial`, `failed` or `blocked` pull never counts, so a listing missed by a `partial` pull is removed
   only after two full `ok` reads without it; the match stays and the offer becomes `unknown` with a snapshot saying so.
6. **Finish**: `SELECT * FROM inv_source_pull_finish(:pull, :status, :error, :policy::jsonb, :http_requests, :bytes)` with `policy =
   inv_pull_policy($stats, host($base_url))`:
   ```
   {"robots": "ok"|"blocked"   (DECISION — the SQL reads a scalar and copies it into sources.robots_state (ok|blocked|unknown):
                                 "blocked" when any host's state is blocked; "ok" when the base host's state is ok or none (an absent
                                 robots.txt allows everything); the key is OMITTED when the state is error/pending or the connector had
                                 no network, so robots_state stays as it was),
    "crawl_delay": the base host's, "user_agent": …, "rate_per_second": …, "proxy": bool,
    "hosts": facts.robots (the map), "http_requests", "bytes", "cached", "blocked", "robots_skipped",
    "requests": the requests that were NOT ok (skipped, blocked, status outside 2xx, transport errors), at most 50 — the ok ones are counted,
                not listed (DECISION: a 500-page jsonld pull would otherwise store 75 KB of policy per pull), "requests_total": n,
    "connector": every extra key the connector returned (pages, sitemap, via, rows, rows_skipped, format, file_bytes, entries, storefront),
    "errors": the connector's errors + the emit errors (each ≤ 200, at most 20)}
   ```
   The SQL sets `last_ok_at` and clears the ladder on `ok`/`partial`; on `failed`/`blocked` it climbs the ladder from `inv_settings.crawl_backoff_minutes`
   (`{60, 1440}`): the first failure backs off an hour, the second a day, **the third pauses the source** with `paused_reason` — D8's "an hour, a day,
   then paused until a person looks". It returns the updated `sources` row.
7. **Proposals** (DECISION — rule 6 of §6.2, the matcher's scoring, is NOT inside the upsert): after an `ok` or `partial` pull,
   `inv_propose_after_pull()` runs `SELECT inv_propose_matches(lv.id, NULL)` for every **live, unmatched listing variant of this source that has no
   `match_proposals` row at all** (`NOT EXISTS (SELECT 1 FROM match_proposals mp WHERE mp.listing_variant_id = lv.id)` — a variant whose pairs were all
   dismissed is not re-scored: dismissals are remembered), newest first, at most `$limit` per pull; the count goes in the pass report. A person asks
   for a fresh scoring from the match queue (`match_propose` on a listing variant — the slice spec's action), which calls the same SQL.
8. **Log** one of `source.pull_done` (`ok`/`partial`), `source.pull_fail` (`failed`), `source.pull_blocked` (`blocked`) with `source_id`, `entity_type
   'source_pull'`, `entity_id = pull_id`, `after: {status, kind, listings_seen, listings_new, listings_changed, variants_changed, listings_removed,
   http_requests, bytes, cached, blocked, robots, error, proposals, consecutive_failures, paused: bool}` — counts, states and the sentence; never a
   listing, a raw object or a URL list.
9. **Notify** (`inv_pull_notify()`, DECISION): on `failed`/`blocked`, `inv_notify(buyer, 'pull_failed', 'source', source_id, "<source name>: pull
   <status>", <the error sentence and "backing off until …" or "paused — resume it from the source page">, 'pull_failed:<source_id>:<consecutive_failures>',
   false)` to `inv_settings.buyer_member_id`, else the source's `created_by`, else nobody — the dedupe key makes it once per rung of the ladder (the
   first failure, the second, the pause), not once per minute. Nothing on `ok`/`partial`.
10. **Watches** are not the pull's business: `inv_fire_watches()` is the `watches` pass (slice 4), which runs after `pulls` in the same minute and
    fires once per state change on what the pull just wrote; a watch naming an agent becomes an `agent_dispatches` row the `dispatches` pass turns into a
    chat turn (slice 8). The heartbeat snapshot (`inv_snapshot_heartbeat()`) is the `snapshots_heartbeat` pass (slice 4). **The division of the worker's passes —
    each to the slice whose data it touches (reconciled 2026-10-05):** `pulls` slice 3; `snapshots_heartbeat` and `watches` slice 4 (find.md);
    `links_expire` slice 5 (orders.md — one function for both doors); `outbox`, `dispatches` and `key_usage_prune` slice 8 (returns-worker.md).

### 6.4 An on-demand pull from a screen or a tool (`source_pull`, right `sources.write`; free for an agent — §5)
A pull may run for minutes, so **"Pull now" never runs inline** (DECISION): the handler calls `inv_source_pull_start(:source, 'manual', :me)`
(allowed while paused or backing off — the SQL's exceptions apply to `scheduled` only; refused while another pull is running, and refused by the
handler when the source's last pull of any kind but `probe`/`search` started less than 5 minutes ago — "pulled 2 minutes ago; try again at …",
the "within the source's rate" of §5), then `UPDATE source_pulls SET policy = '{"queued": true}' WHERE id = :pull`, logs `source.pull_start`
(`after: {pull_id, kind: 'manual', queued: true}`), and answers "Queued — the worker runs it within a minute" with a link to the pull. The worker's
pass picks it up first (§6.1), passing the existing `$pullId` to `inv_run_pull()` (which then skips step 1) and replacing `policy` at finish.
`emit_action_status(true, ['record_id' => $pullId, 'queued' => true])`; the location is the source view with the pull's row. The screen shows a
queued pull as "queued" and a running one as "running since …" (the 6-hour window after which the SQL stops counting it as running is also the
screen's "stale — the worker may have died" mark).

### 6.5 A probe from a screen (`source_probe`, right `sources.write`; not an approval category — reads only)
`probe()` runs **inline** (≤ 3 requests, the client's 20-second timeout; a `feed` probe reads the whole file — up to 120 s — the form warns).
DECISION: a probe is recorded as a `source_pulls` row of kind `probe` — `inv_source_pull_start(:source, 'probe', :me)` (refused only while a pull is
running: "a pull is running; probe when it finishes"), then `inv_source_pull_finish(:pull, <ok → 'ok' | blocked → 'blocked' | misconfigured → 'failed'>,
<the message>, <policy with "robots" from the facts when the probe gives them>, …)`. The ladder applies to a `failed`/`blocked` probe as to a pull —
a source that cannot be read as configured is backing off — and **the source form's save calls `inv_source_resume()` whenever `base_url`, `settings`,
`user_agent` or the credential changed** (DECISION), so fixing the configuration clears the ladder and the next probe starts clean. The screen
shows the probe's message under a state badge and its facts as a definition list (`columns` for a feed become the mapping screen's choices).
`InvMisconfigured` thrown by `probe()` (a bad base_url) is caught and shown as `misconfigured` with the exception's sentence. Logged `source.probe`
(`after: {state, message, pull_id}`).

### 6.6 The live search (`source_search` — design A8; Find's "ask the sources now", slice 4)
`inv_source_search_live(PDO $pdo, int $sourceId, string $q, int $limit = 20, ?int $by = null): array` in `app/sources/pulls.php`
(slice 3 builds it; slice 4's Find screen and the `source_search` tool call it):
- only a source whose connector `capabilities()['has_search']` is true and whose row is active and not paused and not backing off; else
  `['ok' => false, 'reason' => 'no live search' | 'paused' | 'backing off', 'listings' => the last pull's listings matching $q]` — "answers from the last pull";
- `inv_source_pull_start(:source, 'search', :by, :q)` (refused while a pull is running → `['ok' => false, 'reason' => 'a pull is running', 'listings' => from the last pull]`);
- `$c->search($source, $q, $limit)` with `$source['timeout'] = 10` (DECISION: a live answer waits at most ten seconds a request; the per-host lock
  still spaces the requests — a Shopify search is suggest.json plus one `.js` per hit, so `$limit` is 10 from a screen, 20 from the tool);
  `InvNotSupported` / `InvMisconfigured` → `ok false` with the sentence and the last pull's answer;
- **each listing found is written through the same `$emit` as a pull's** (design §6: a `search` pull writes listings like any other — so the
  match, the snapshot and the price sheet happen, and the records tools see what Find saw), then `inv_source_pull_finish(:pull, 'ok'|'partial'|'blocked', …)`
  — a `search` pull never counts for removals (the SQL's window excludes the kind);
- returns `['ok' => true, 'pull_id', 'listings' => the normalized listings (for the screen's merge), 'listing_ids' => the written ids]`; logged
  `source.search` (`after: {pull_id, q (≤ 120), found}`).
- **Fan-out is the browser's** (DECISION): the Find screen renders one placeholder card per searchable source with `hx-get="/find/source-search?source=<id>&q=…"
  hx-trigger="load"`, so the sources are asked in parallel by separate requests (each one source, ≤ 10 s a request) and each card fills on its own
  (the "spinner per source" of §9); PHP never threads. The `source_search` records tool (the tool surface's A8; reconciled 2026-10-05) reaches PHP through the HMAC
  bridge `html/internal/bridge.php` (slice 4, find.md — loopback, `X-INV-Bridge` over `ACTIONS_RELAY_KEY`, ±30 s, the caller's `orders.write`), which
  runs this same `inv_source_search_live()` per source **in sequence** with the tool surface's budgets: 20 s per request (`InvHttp`), **25 s per
  source**, **60 s per call**, at most **5 sources**, at most **20 listings** each; it answers what came back and names the sources it did not
  reach (`timeout`). Never a POST to `/sources/search.php` — that file is the command bar's action (slice 3, right `orders.write`) over the same
  function.

### 6.7 Housekeeping in the same pass
- **Cache pruning** (DECISION — nothing in `http.php` evicts): once a day (the first pass after 03:00 local, remembered in `storage/http-cache/.pruned`),
  `inv_http_cache_prune(inv_http_default_cache_dir(), 30)` deletes `*.meta.json`/`*.body` pairs whose `stored_at` is older than 30 days and lock files
  untouched for 30 days; the count goes in the report.
- **Permissions**: `storage/http-cache` is created by the first client (0770) under `www-data`; the worker unit runs as `www-data`
  (`deploy/inventory-worker.service`), the same user as Apache — so the per-host lock is shared by the worker and the screens' probes and searches.
  `deploy/ROOT_STEPS.sh` step 0b already makes `storage/` (`attachments/`, `exports/`, `http-cache/`) `www-data`-owned, mode 0770, before
  `apply` (db/016's builder; reconciled 2026-10-05 — nothing for slice 3 to add; Phase 2 verifies it); without it the worker's first client could
  not create the cache and the rate lock would fall back to "no lock" (`waitForHost()` runs the request when the lock file cannot be opened).

---

## 7. Adding a connector later — one class, one fixture, one template row, the proof extended

The Extended four (D7, §11): `ebay` (Browse API, `oauth_client` → an application token by client credentials, minted per pull, never stored;
`is_reference true`), `amazon` (PA-API 5.0 `SearchItems`/`GetItems`, `api_key` + `rsa_signing`-style signing, **reference only — never a supplier**,
pauses itself on `AssociateNotEligible` or 429), `walmart` (affiliate API, `api_key` consumer id + `rsa_signing` key, a 180-second signature per
request; reference), `inventory_feed` (another installation's `/api/v1/availability` with an `api_key`; `gives_qty`, a partner price as
`cost_price` when the key is a partner's; a supplier source). `os_sibling` (K7) is the same shape over the kernel's read endpoint.

**The checklist** (nothing else changes — not the worker, not the matcher, not the screens):
1. `app/sources/connectors/<key>.php` — `final class InvConnector<Name> implements InvConnector`; the five methods; every HTTP call through
   `inv_http_client($source)` (a signed request builds its headers and calls `$http->get()/post()` — the policy applies to an API too: rate, UA, blocks);
   `probe()` ≤ 3 requests; `pull()` emits as it reads and returns `inv_pull_stats()`; `search()`/`lookup()` or `InvNotSupported`; `capabilities()`
   with `credential_kinds` naming the kinds of §5.1 it accepts and `is_reference` true for a marketplace; the credential read from `$source['credential']`
   only; a short-lived token (eBay's two hours) minted inside the call and kept in the `InvHttp`'s `headers` option for the pull — never written anywhere;
   a provider's own throttle (Amazon's 1 req/s, 8,640/day) honoured by `rate_per_second` and a `settings.daily_cap` the class checks against the
   pull row count of the day (`mcp_source_pulls`? — no: the connector has no database; the class counts its own requests per pull and stops at the cap,
   and the source's `schedule_minutes` keeps the day's total); `// DECISION:` comments where the API's shape forced a choice.
2. `app/sources/registry.php` — one line in `$defs`, in screen order (after `manual`): key, class, label, description.
3. `tests/fixtures/sources/<key>/` — one trimmed page per endpoint, **invented data, never a third party's catalog** (a search answer, one item,
   an auth answer, the throttle's refusal); `tests/fixtures/sources/router.php` — the paths and the auth check (a wrong key → the provider's 401/403 shape).
4. `tests/phase0/connectors.php` — a `group('<key> — …')` with the same checks the five have: probe ok / blocked / misconfigured, pull emits N listings
   of valid shape with the mapping asserted field by field (external ids, sizes, GTINs, prices, currency, availability words, qty), the pagination
   (what was and was not requested, from the request log), search and lookup or their `InvNotSupported`, a block → `blocked`, the credential in no
   fact or message, `capabilities()`; and the registry group's expected key list extended.
5. `db/0NN_<key>_template.sql` — an **additive** migration inserting the `source_templates` row (`key`, `name`, `connector`, `role` — `reference` for a
   marketplace —, `base_url`, `settings` with the connector's own keys, `notes` naming the credential to add, `sort_order`); `inv_connectors()` in SQL
   already lists the ten keys of §0.2, so no check changes. A key not in that list needs the function replaced in the same migration.
6. `docs/build-specs/connectors.md` — a §3.N for it (endpoints, settings, credential kinds, the mapping table, availability words, errors, the fixture);
   `skills/inventory-basics` gains the sentence that names it; `README.md`'s connector line.
7. The proof green (`bash tests/phase0/connectors.sh`), then the slice 3 proof (the templates screen lists it; a source of that connector probes
   against the fixture server) — the installer's `plan` clean.

A connector is never added to v1's list of five by a worker model without the owner's word (D7); the interface, the client and the glue are built so
that adding one is exactly the list above.

---

## 8. The survey tool and the finding (`bin/source_survey.php`)

`php bin/source_survey.php [hosts…] [--file hosts.txt] [--json] [--rate N ≤ 2] [--ua "…"] [--timeout S]` — no database, no kernel; for each host
one `InvHttp` (robots.txt first, the cache under `/tmp/inv-survey-cache`, 2 MB a page) and four polite requests: `/products.json?limit=1` (the
Shopify verdict; the platform recognized from `x-shopid`, `x-shopify-stage`, `powered-by` or a `TOO_MANY_REQUESTS` body even when the answer is a
block — `platform:shopify` in the notes), `/wp-json/wc/store/v1/products?per_page=1` (WooCommerce; `platform:wordpress` from the `Link` header),
`/` (JSON-LD blocks and a Product on the home page; a redirect to another host noted `home→…`), the first `Sitemap:` of robots.txt or
`/sitemap.xml` (children or URLs counted). Verdicts per column: `open`, `blocked` (the reason in the notes), `robots` (disallowed), `no`, `error`
(no answer — a sandbox that rate-limits outbound HTTP shows here; the tool names its own `local_rate_limited`). Exit 0 when any host answered.

**The finding (Phase 0, 2026-10-05, design §0.2/§16)**: of fourteen candidate brand sites, thirteen run on Shopify and **every one answers
`products.json` with Shopify's own 429 to a non-browser user-agent** — the storefront throttle, not a bot wall (verdicts vary by run and IP;
Naturepedic answered products on one run and 429 on the next); **every one of the fourteen has an open product sitemap** (five or six children);
**Layla runs WooCommerce with the Store API open** (twenty products on the first page, 247 URLs in its sitemap). **The consequence**: for a Shopify
brand the realistic door is the **`jsonld` connector over its product sitemap at the daily cadence** (one page a second, robots honoured, the price
and availability in the page's markup), or a **dealer's Storefront token** through the `shopify` connector; bare `products.json` is the exception.

**The templates** (`source_templates`, seeded in db/009 from the documented shapes, `survey_result = 'unverified'`): the fourteen brands as `shopify`
reference sources, `woocommerce_store`, `jsonld_site`, `dealer_feed_csv` (supplier), `price_sheet` (manual). **"Add from a template"** (slice 3,
`source_create` from `mcp_source_templates`): copies `name`, `connector`, `role`, `base_url`, `brand_hint` (a `brands` row is made or found), and
**the settings keys the connector declares** — DECISION: the seeded settings use documentary keys the connectors do not read (`endpoint`, `limit`,
`per_page`, `daily`, `transport` are inert; `sitemap` and `mapping.map` are the connectors' `sitemap_url` and `mapping.map_price`); the adopt handler
keeps only the keys of §3 for that connector and renames those two, so a source made from a template is read exactly as its form would make it. For
a Shopify template whose `survey_result` is `blocked`, the form offers the two doors the finding names: "read its product sitemap with the marked-up
site connector (daily)" (a `jsonld` source at the same `base_url`) or "add a Storefront token". **Recording the survey** (DECISION — slice 3 adds
`--record` to `bin/source_survey.php`): with a database, each surveyed host updates its template's `survey_result` (`open` when the Shopify column is
open; `blocked` when blocked and the platform was recognized; `not_platform` when `no` and the platform was not; `unverified` otherwise) and
`surveyed_at`; the owner re-runs it from a shell before Phase 5 (design §0.2) — never from the planning sandbox, whose rate limit answers for the sites.

---

## 9. The proof — what `tests/phase0/connectors.sh` covers and how to run it

`bash tests/phase0/connectors.sh` (exit 0 = every check passed; prints `ok`/`FAIL` lines and `N ok, M FAIL`): starts `php -S 127.0.0.1:8606 -t
tests/fixtures/sources tests/fixtures/sources/router.php` with a request log at `INV_FIX_LOG` in a scratch dir (`INV_FIX_TMP`), refuses to run when
**port 8606** is taken (the proof scratch block is 8601–8607, design §14), runs `tests/phase0/connectors.php`, stops the server whatever happened.
No database, no kernel, no network beyond `127.0.0.1`. The router logs every request's method, path, query, User-Agent, conditional headers, whether
an `Authorization` header or a Storefront token was sent, and the time — so the proof asserts **what was and was not asked for**.

What it proves, group by group (511 checks): the **normalizer** — every availability word and schema.org state of §0.3, the boolean/quantity rules,
GTIN-8/12/13/14 with the check digit, hyphens and a lost leading zero, money in four notations and minor units, currency, ships-how synonyms, every
seeded size and its synonyms from options and titles (and four words that are not sizes), the listing shape with defaults and the one-variant rule,
an extra key refused; **credentials** — no key / a malformed key refused, seal and open, a fresh nonce, a tampered box, a flipped character, an unknown
version, another key, an unknown kind, the summary's `last4` and a key's fingerprint, `$_ENV` fallback; **robots** — groups, longest match, `$`
and `*`, Crawl-delay per group, an empty file; **the client** — robots.txt first and once, the honest UA sent, ETag → `If-None-Match` → 304 from the
cache, the facts, the env UA and the override, **the rate (two requests ≥ 1 s apart, serialized) and a `Crawl-delay: 2`**, a disallowed path never
reaching the server, every block reason of §2.6 incl. a PerimeterX page, a JSON 200 never a wall, **a login redirect refused and the login page never
requested**, an ordinary redirect followed, a loop stopped at five, `max_bytes`, a 403 on robots.txt = nothing allowed, the proxy and UA reaching the
transport, a transport error reported not thrown; **each connector** — probe ok/blocked/misconfigured, the pull's listings field by field against the
fixture (ids, handles, URLs, options, sizes, GTIN-14s, prices, compare-at dropped when equal, currency, every availability path, qty, raw trimmed —
no `body_html`), the pagination from the request log, the cache on a second pull, collections / variations / sitemaps / `urls[]` / `url_pattern` /
the mapping by header, index and letter / `product` grouping / the skipped row / a missing column refused / a 401 then a basic credential / the
password in no fact / an https-only URL / XLSX generated and read / SFTP's command plan with the password in a netrc file and never on argv /
manual entries with a bad one → `partial`, search and lookup or their `InvNotSupported`, `capabilities()`; **the registry** — the five keys in order,
`InvMisconfigured` for `ebay`, eight capability keys each, none a reference.

**Slice 3's own proof** (`tests/phase3/sources.sh`, on a scratch database with the fixture server on 8606) adds the glue: a scheduled pull through
`sources_run_due_pulls()` writes listings, variants, snapshots on change only, the pull row's counts and a policy with a scalar `robots`; a second
pull with nothing changed writes no snapshot; a listing gone from the fixture is removed after two full pulls and its offer becomes `unknown`; a
`partial` pull marks nothing removed; a 403 fixture climbs the ladder (an hour, a day, paused) and the Buyer is notified once per rung; a queued
on-demand pull runs on the next pass; a probe writes a `probe` row and a configuration change resumes; a live search writes a `search` pull that
never counts for removals; proposals are written for new unmatched variants and not re-scored after a dismissal; the credential's ciphertext is in
no view, log row or policy; `source.pull_start|pull_done|pull_fail|pull_blocked|probe|search|credential_set` logged with ids and counts only.

---

## 10. Decisions recorded (the code's `// DECISION:` and this document's)
In the code (Phase 0, transcribed in §1–§3): the eight capability keys always present; `size_key` as the canonical snake_case name, SQL deriving its
own; a compare-at not above the price dropped; images in `raw.images` (≤ 5), not a field; "available with qty 0" = `back_order`; a 403/429/wall on
robots.txt = nothing allowed; a feed's URL skips robots; a feed row is a sellable unit, grouped by a `product` column; MAP in `raw.map_price`;
Shopify's short page ends the pull; no currency in `products.json` → `settings.currency`/USD; Storefront gids → numeric ids; suggest.json then a
collection named like the query, else no search; jsonld reads the `product` sitemaps of an index, every child when none is named; an ItemList page
yields its products; a non-new offer skipped unless alone; a manual entry's id from its SKU/GTIN; the password in a netrc file; the sftp command keys
only; the schema's: a supplier source names its supplier; a resumed blocked source goes to `unknown`.
This document's (the glue, §5–§8): the seal stored with `convert_to/convert_from`; the one opener `inv_source_for_connector()`; a credential set
= a new row, the old deleted, `inv_source_resume()`; the UA resolved source → settings → business name + contact → env → the unconfigured default
(a per-source UA must name a contact); `crawl_max_pages` as the per-connector page cap; the pass's 50-minute budget; `$emit` never throws, one
transaction per listing, the counts the SQL's; status downgraded by emit errors; `inv_mark_removed()` before finish and only after an `ok`
connector status on a scheduled/manual pull; `policy.robots` a scalar, `hosts` the map, `requests` only the ones not ok (≤ 50); proposals after
the pull for unmatched variants with no proposal row; the Buyer notified once per rung of the ladder (dedupe key); "Pull now" queues a `running`
row with `policy.queued = true` and refuses one within 5 minutes of the last; a probe is a `probe` pull row and the ladder applies; the form's save
resumes on a configuration change; a live search is a `search` pull with a 10-second request timeout, written through `$emit`, fanned out by the
browser, 30 s for the tool; the cache pruned at 30 days; "Add from a template" keeps the connector's own settings keys and renames `sitemap` →
`sitemap_url`, `mapping.map` → `mapping.map_price`; `--record` on the survey tool fills `survey_result`; `ROOT_STEPS.sh` makes `storage/` `www-data`-owned.

## 11. Vocabulary
*connector* (a class), *source* (a row — where offers are read from), *pull* (one reading, a `source_pulls` row of kind scheduled / manual /
search / probe), *listing* (a source's product), *listing variant* (its sellable unit), *offer* (price and availability at a moment — a snapshot),
*match* (the tie to our variant), *proposal* (a scored candidate a person decides), *blocked* (a 403, a 429, a bot wall, a login redirect — never
retried faster), *skipped* (robots said no — never fetched), *cached* (a 304 answered from our copy), *policy* (the facts a pull followed),
*the ladder* (an hour, a day, paused), *removed* (unseen for two full pulls — the match stays), *the heartbeat* (a snapshot a day regardless),
*credential* (sealed; a label and four characters), *honest user-agent* (names the business and a contact).

## 12. Out of scope for the connectors and slice 3
The Extended connectors (`ebay`, `amazon`, `walmart`, `inventory_feed`, `os_sibling`, the EDI 846 flat file) — the checklist of §7 when the owner
asks; ordering by API through a connector; a browser, a headless engine or a session of any kind; retrying a block inside a pull; a proxy pool;
adopting a feed's MAP onto the variant automatically; multi-currency; Find's cards, watches and the heartbeat pass (slice 4); the Buyer's morning
note and `source_health`'s thresholds (slice 8); the records MCP tools over `mcp_sources`/`mcp_listings`/… (Phase 4).

## 13. Open questions (must be EMPTY before a worker starts)
- (none)

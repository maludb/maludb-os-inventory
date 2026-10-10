# Inventory — design (Phase 0 of the new-app workflow, fitted to the Business OS)

2026-10-05 · **sales inventory for a retailer of packaged goods who holds some stock and drop-ships the rest** — a catalog
of what the business sells, its own stock by location, the **sources** it can sell from without holding anything (other
websites, suppliers' feeds, other catalogs, another installation of this application), searched and watched for
availability and price, and the orders that are filled from the shelf or placed with a supplier for delivery to the
customer. The use case it is modelled on is **mattress retail**: a showroom with floor models and a small warehouse,
a dozen brands sold through dealer and drop-ship programs, the same model sold by the manufacturer's own site and by
marketplaces, prices that change weekly, and sizes that make one mattress six SKUs. Its own repository
`/srv/apps/inventory` (origin `github.com/maludb/maludb-os-inventory`, private), served as `inventory.<domain>`
(`inventory.subello.com` here), built with `htmx-php-builder` (how it is built) and fitted to `maludb-os-integration`
0.7.0 (how it fits), exactly as HR, Projects, Help Desk, General Ledger, Consultant Tracking, Spaces and Knowledge were.
**`/srv/apps/spaces/docs/spaces-design.md` is the nearest plan** (the same shape, the same owner's rules);
`/srv/apps/consultant_tracking` and `/srv/apps/spaces` are the nearest **built** exemplars (their kit, specs and proof suites
are what this repository copies). The Cidery (`/srv/apps/cidery`, an inventory for a producer: lots, bond, production) and
the General Ledger (parties, documents, sequences) are the prior art this design keeps or overturns by name.

> **What this document is.** The plan for the application, in the kernel's words, for the owner's go, and the brief a
> worker model builds from. **Checkpoint.** This document and the owner's answers (§13, recorded in §15) are Phase 0's
> first half; the schema in `db/`, `maludb-os.json`, `os/`, `skills/` and `deploy/` complete it. Phase 1 adds
> `docs/inventory-mcp-tool-surface.md`, `docs/inventory-action-manifest.md` and the slice specs in `docs/build-specs/`.
> **No feature PHP is written until the owner approves them together.** The decisions this document needs from the owner
> are §13; §15 records the answers when they come.

## 0. The research — the drop-ship trade, the mattress case, and where inventory lives on other websites

Researched 2026-10-05 from the platforms' own documentation (Shopify, WooCommerce, eBay, Amazon, Walmart, Rithum/SPS),
the suppliers' dealer pages (Malouf's Wholesale Resource Center and its "Endless Aisle" drop-ship program; Brooklyn
Bedding's wholesale page: drop-ship "always available and always free"), and the trade's practice. Each thing the trade
does is marked **kept** (version 1), **changed** (kept in a different form, because of the OS), **Extended** (a later
spec), or **dropped** (by decision — the reason given).

### 0.1 The business — what a drop-shipping retailer does, and what this design does with each

| What the trade does | Here |
|---|---|
| **Holds some stock, sells more than it holds.** A showroom carries floor models and a few best-sellers; everything else is ordered from the manufacturer or a distributor and shipped straight to the customer ("endless aisle"). The salesperson must know, at the moment of the sale, what is on the shelf, what the supplier can ship and when, and what it costs | **Kept — the whole point** (§1): every sellable thing has *own stock by location* and *offers from sources*; **Find** answers both in one screen (§9); a sales order line is filled from a location or from a source (§6) |
| **Dealer programs and drop-ship programs.** A manufacturer takes a dealer's orders on a portal (Malouf's WRC), by email, by EDI (850 purchase order in, 855 acknowledgment, 856 ship notice, 810 invoice out), or through a drop-ship network (Rithum — the former CommerceHub and Dsco — or SPS Commerce); it publishes inventory as an **846 inventory advice**, a CSV on FTP, or not at all | **Changed**: v1 **orders by hand** — the application prepares the purchase order and its email, a person places it on the supplier's portal and records the supplier's reference; the supplier gets a secure link to acknowledge and add tracking (§4). Inventory arrives as a **feed** (a CSV or XLSX on HTTPS or SFTP, mapped once) or by reading the supplier's public site. **EDI and the networks are Extended** (§11): the 846 flat file is the first, the 850/855/856/810 loop the second |
| **The same mattress, many names and many sellers.** A model is sold under one name at the manufacturer, a "comfort" name at a chain, a retailer-exclusive name at a club store; sizes multiply it (Twin, Twin XL, Full, Queen, King, California King, Split King); GTIN/UPC identifies the SKU when the seller publishes it, the manufacturer part number when they do not | **Kept** as the catalog's shape (§6): a **product** (the model) with **variants** (the sizes), each variant carrying any number of **identifiers** (GTIN, UPC, EAN, MPN, ASIN, eBay EPID, Walmart item id, a supplier's SKU); an external listing is **matched** to a variant by identifier first, by a person second, by the agent's proposal in between (§6.2) |
| **Prices move, and MAP binds.** Manufacturers set a minimum advertised price; sales events change retail weekly; dealer cost is on a price sheet; the marketplaces undercut | **Kept**: three prices on a variant (retail, MAP, cost) with history; every source's price kept as **snapshots** when it changes; **watches** (back in stock, price below, MAP breach) notify (§6) |
| **Delivery is the product.** Bed-in-a-box ships parcel; a traditional mattress ships LTL freight or by the retailer's own truck with white-glove set-up and old-mattress removal; lead time and the delivery method decide the sale as much as the price | **Kept**: a variant says how it ships (parcel, LTL, white glove), a source's offer carries a lead time, an order says delivery or pickup, a shipment carries the carrier and tracking (§6). **Carrier APIs (labels, rates) are Extended** |
| **Sets and bundles.** A mattress sells with a foundation or an adjustable base; a "set" has its own price | **Kept** (§13.13): a **bundle** product lists components with quantities; its availability is the minimum over them; its stock is never held as a bundle |
| **Trials and returns.** 100-night trials are the norm; a returned mattress cannot be resold as new — it becomes a floor model, a donation or a disposal; the supplier may take it back | **Kept**: a return authorization with a disposition (restock, floor model, dispose, return to supplier) and the refund recorded as status (§6) |
| **Floor models.** The showroom's units are stock that is also a display | **Kept**: a location of kind `showroom` and a balance's `floor_model` quantity — sellable at a discount, counted, never allocated to a drop-ship |
| **Serial numbers and law tags.** Every mattress carries a law tag and most carry a serial; warranties refer to them | **Extended** (serial-tracked units); v1 counts quantities and records a serial on the shipment line as text |
| **Payments and the books.** A deposit at the sale, the balance at delivery; the accountant posts sales and purchases | **Changed — the kernel's rule**: no money out and no books here; the order records **payment status and amounts** (no processor); the ledger reads sales, purchases and stock value through K7 (§8) |
| **Selling on marketplaces.** Listing on Amazon, eBay, Walmart | **Dropped from v1** (Extended): this application *buys from and compares against* the marketplaces; selling on them is a different product |
| **A storefront.** The retailer's website shows what can be sold | **Changed**: the public website is out of the OS's scope; this application publishes an **availability feed with keys** (§4) that a website, or another installation of this application, reads |

### 0.2 Where inventory lives on other websites — the connector survey

The owner's instruction: *search for inventory from other websites; those websites may or may not have an API; start
with the places with an API or easy-to-scrape inventory.* Each row is one **connector** (a PHP class behind one
interface, §6.1); the column "v1" says which ship first. "Public" means no key is needed and the site's own storefront
serves the data to any browser.

| Connector | What it reads | How | Gives | v1 |
|---|---|---|---|---|
| **`shopify`** — a Shopify store's public catalog | `GET https://<store>/products.json?limit=250&page=N` until `{"products":[]}` (the public storefront endpoint still pages by number in 2026 — the "page is deprecated" notes concern the Admin API; 250 a page, a hard stop at 25,000); one product by `/products/<handle>.js`; collections by `/collections/<handle>/products.json` | every product with `vendor`, `product_type`, `tags`, `options` (Size), and every variant's `title`, `sku`, `barcode` (the GTIN when the merchant filled it), `price`, `compare_at_price`, `available` (a boolean — the quantity is hidden unless the store shows it), `grams`, images; a store's **Storefront API** (GraphQL, a public access token the supplier gives a dealer) adds `quantityAvailable` — the same connector with an optional token. A good share of the direct-to-consumer mattress brands run on Shopify; some front the endpoint with a bot wall (a 403 or 429 is recorded as *blocked*, never retried faster) | **yes** |
| **`woocommerce`** — a WooCommerce store's public catalog | `GET /wp-json/wc/store/v1/products?per_page=100&page=N` (the Store API, public since 2022, no key); `search=`, `stock_status=`, `min_price`; a variable product's `variations[]` and each variation by `/products/<id>` | `name`, `sku`, `prices.price/regular_price/sale_price`, `is_in_stock`, `low_stock_remaining`, `attributes` (Size), images | **yes** |
| **`jsonld`** — any site that marks its product pages up | the product sitemap (`sitemap_products_*.xml`, `product-sitemap.xml`, or a list of URLs a person pastes), each page fetched politely and its `application/ld+json` read for `Product` → `Offer` / `AggregateOffer` (`price`, `priceCurrency`, `availability` as a schema.org state, `sku`, `gtin13`/`gtin12`/`mpn`, `brand`), plus Open Graph as a fallback | price and availability for most retail sites that are not Shopify or Woo (the big-box retailers mark up every product page); the slowest connector (one page per product) — a daily pull, not an hourly one | **yes** |
| **`feed`** — a supplier's inventory file | a CSV or XLSX on an HTTPS URL or an SFTP account (host, user, password or key), pulled on a schedule; a **column mapping** made once in the source's settings (supplier SKU, GTIN, name, size, cost, quantity or in-stock, lead time, MAP); the EDI 846 as a flat file is the same connector with a parser added (Extended) | the dealer's own cost and the supplier's true quantity — the only connector that knows what the retailer pays; Malouf's and most manufacturers' dealer files fit | **yes** |
| **`manual`** — a source with no door | a person records what the supplier said (a phone call, a price sheet): a listing and its offer typed in, with a date | lead times and costs for suppliers with no feed; the price sheet as an attachment | **yes** |
| **`ebay`** — eBay's Browse API | an **application token** by the client-credentials grant (a free developer account; the token lives two hours and is minted again), `GET /buy/browse/v1/item_summary/search?q=&filter=` with `X-EBAY-C-MARKETPLACE-ID`, `getItem` for one | live listings with price, condition, seller, shipping, `estimatedAvailabilities`; a **reference** (what the market asks) and, for a refurbished or used line, a supplier | **Extended** (D7 — the interface stays; the owner's keys when wanted) |
| **`amazon`** — the Product Advertising API 5.0 | an Associates account (3 qualifying sales in 180 days to get keys; **since November 2025, 10 qualifying orders in the past 30 days to keep them**), 1 request a second and 8,640 a day to start, signed requests (`SearchItems`, `GetItems` by ASIN with the `Offers` resources) | price and availability messages for ASINs — a **reference only**; never a supplier (Amazon's terms forbid retail arbitrage through the API); the connector pauses itself on `AssociateNotEligible` or 429 and says so | **Extended** (D7) |
| **`walmart`** — the Walmart affiliate API (walmart.io) | a consumer id and an RSA key pair; each request signed (`WM_SEC.AUTH_SIGNATURE`, a 180-second TTL) — `/api-proxy/service/affil/product/v2/items?ids=`, `/search?query=` | `salePrice`, `stock` ("Available"/"Not available"), `availableOnline`, UPC, brand — a **reference** | **Extended** (D7) |
| **`inventory_feed`** — another installation of THIS application | the availability feed every installation publishes (§4: `GET /api/v1/availability?gtin=…`, a key per consumer) — another store in the same family, or a friendly retailer who swaps stock | availability, lead time and a dealer price per variant from the other store, matched by GTIN | **Extended** (D7 — the feed itself ships in slice 7; the client connector is the first to add) |
| **`os_sibling`** — the Cidery, or any sibling that shares an availability tool | the kernel's K7 read of a provider's share over an approved connection | a sibling application's stock as a source | Extended (no sibling shares one yet) |
| EDI 846 through Rithum, SPS Commerce or a supplier's VAN; the 850/855/856/810 loop | the retailer-side of a drop-ship network | authoritative inventory and automated ordering | **Extended** (§11) — the `feed` connector takes the 846 flat file first |
| A storefront behind a login (a dealer portal with no feed) | a logged-in browser | — | **Dropped**: no credentials are replayed into a third party's login form, no captcha is solved, no bot wall is evaded — refused by name (§13.7); the owner asks the supplier for a feed, or a person types it (`manual`) |

**Crawling policy (every connector that reads a public site):** `robots.txt` honoured (disallowed paths skipped, `Crawl-delay`
obeyed; a `jsonld` pull reads the sitemap only where allowed); an honest `User-Agent` naming the business and a contact
address (a setting); one request at a time per host, at most one a second, cached by `ETag`/`Last-Modified`; a 403, 429 or a
captcha page marks the source **blocked** and the worker backs off (an hour, a day, then paused until a person looks);
never a login, never a session cookie from a person's browser, never a proxy pool. A source's terms of use are the
owner's to read; the application records the URL and the policy it followed in every pull.

**What the survey found (Phase 0, 2026-10-05, `bin/source_survey.php` from the build server — the planning sandbox's own
rate limit let it through on the third run; verdicts vary by run and by IP, so the owner re-runs it from a shell before Phase 5):**
of fourteen candidate brand sites, **thirteen run on Shopify and every one of them answers `products.json` with Shopify's own 429
("too many requests") to a non-browser user-agent** — the storefront throttle, not a bot wall; Naturepedic answered with products on
one run and 429 on the next. **Every one of the fourteen has an open product sitemap** (five or six children). **Layla runs
WooCommerce with the Store API open** (twenty products on the first page, JSON-LD on the home page, a sitemap of 247 URLs).
The consequence for the trade: for a Shopify brand the realistic door is the **`jsonld` connector over its product sitemap at the
daily cadence** (one page a second, robots honoured, the price and availability in the page's markup), or a **dealer's Storefront
token** through the `shopify` connector; bare `products.json` is the exception, not the rule. The shipped `source_templates` are
seeded from the documented shapes and marked unverified; the survey's JSON fills `survey_result` at Phase 5.

### 0.3 The vocabulary this design adopts (so feeds and marketplaces transcribe)

**The catalog's words are Shopify's and GS1's**: a *product* (the model — "Tempur-Pedic ProAdapt Medium") has *options*
(Size, and any other the business adds — Firmness, Height) whose combinations are *variants* (the Queen), each with a
*SKU* (the business's own code), a *barcode* (the GTIN: UPC-A 12 digits, EAN-13 13, stored as GTIN-14 for matching),
an *MPN*, a *vendor* (the brand), a *product type* (Mattress, Foundation, Adjustable base, Pillow, Protector, Sheets,
Frame), *tags*, and prices (*price*, *compare-at*). **Mattress sizes** seeded (a setting, editable): Twin, Twin XL, Full,
Full XL, Queen, Olympic Queen, RV Short Queen, King, California King, Split King, Split California King, Crib. **Mattress
attributes** seeded as product attributes (`attributes jsonb`, keys declared in settings): type (innerspring, memory foam,
hybrid, latex, airbed, futon), firmness (1–10 and the words plush / medium / firm / extra firm), height (inches), cover
and materials, certifications (CertiPUR-US, GOTS, GOLS, Oeko-Tex), trial nights, warranty years. **Availability states
are schema.org's**: `in_stock`, `out_of_stock`, `pre_order`, `back_order`, `limited`, `discontinued`, plus `unknown`
(the source said nothing) — every connector maps its own words onto these seven. **How it ships**: `parcel`, `ltl`,
`white_glove`, `pickup_only`. **A source's role**: `supplier` (the business may order from it) or `reference` (the
business compares against it and never orders).

## 1. What Inventory is, and is not

Inventory **owns what the business sells and where it can get it**: the catalog with every identifier a seller might
use, the business's own stock by location with every movement, the sources it draws on — websites read politely,
suppliers' feeds, price sheets typed in, the marketplaces as a reference, another installation of itself — with every
offer remembered as it changes, and the orders that turn a customer's "I'll take the Queen" into a shipment from the
warehouse or a drop-ship placed with the supplier. It is the inventory and order desk a small retailer would otherwise
run across a spreadsheet, five supplier portals and a dozen browser tabs, with the differences that matter here:

- **Memory first: availability is a history, not a lookup.** Every pull of every source writes what changed — price,
  availability, quantity, lead time — as a snapshot; "when did the Purple 2 Queen go out of stock at the manufacturer,
  and what did it cost at Walmart that week" is a record question with a tool, not a screenshot someone kept (§6, §7).
- **One catalog, many names.** The business's variant is the one truth; every external listing is matched to it by
  GTIN, by SKU, by MPN, by a person, or by the agent's proposal that a person confirms — and the match is remembered with
  who made it and how sure it was (§6.2). An unmatched listing is work, shown on the home screen, never silently dropped.
- **Find is the application.** One screen, one query — a product, a size, a GTIN, a brand — answers *own stock by
  location*, *every source's offer ranked by cost and lead time*, *the reference prices*, and *what the business sells it
  for* (§9); the same SQL function answers the records tool, the command bar and the availability feed.
- **Drop-ship is a fulfilment choice on a line, not a different kind of order.** A sales order's line says *from the
  warehouse*, *from the showroom floor* or *from this source at this offer*; a drop-ship line produces a purchase order
  addressed to the customer; the supplier acknowledges and adds tracking through a secure link; the customer watches
  the same order through theirs (§4, §6).
- **Agents propose; people commit money.** The expert answers and drafts; the Buyer agent reports every morning — sold
  lines whose source went out of stock, reorder points reached, prices moved, MAP breached, listings unmatched, pulls
  failing — and drafts the purchase orders; placing one with a supplier pauses as `money_out` (§5).
- **Nothing is scraped that was not offered.** Public endpoints and marked-up pages, robots honoured, one request a
  second, blocked means blocked; a site behind a login gets a feed or a person (§0.2).
- **Full MCP coverage is a requirement**, not a feature: every question a screen answers is a records tool, every
  button an action tool (`maludb-os-integration`, `mcp-and-api.md`).
- **Nothing of identity, money or books lives here.** The kernel signs people in; the ledger keeps the books and reads
  sales, purchases and stock value through K7; payments are recorded as status; suppliers and customers are parties with
  a secure link, never accounts (§4, §8).

Not in version 1: the keyed reference connectors (eBay, Amazon, Walmart) and the `inventory_feed` client (D7 — the connector
interface ships with five connectors and each later one is a class and a fixture); ordering with a supplier by API or EDI (the 850 loop); the 846 inventory advice through a network;
carrier integrations (labels, rate quotes, tracking pulled by API); serial-tracked units and law tags; selling on the
marketplaces; a storefront or checkout; a payment processor; texts to customers (the kernel texts members only — K28,
§12); multi-currency; demand forecasting; promotions and price schedules; multi-store scopes (locations are records, not
walls — §3); a sibling's stock as a source (`os_sibling`). Each is an Extended item (§11).

## 2. Who uses it (the actors, in the OS's words)

**A word about words.** "Agent" always means an OS AI agent. A **member** is a kernel member mirrored here; a **product**
is a model, a **variant** its sellable size or combination, an **identifier** a code a seller uses for a variant; a
**location** is a place the business keeps stock; a **source** is somewhere the business can read offers from, and a
**supplier** a party it can order from (a source may belong to a supplier); a **listing** is a source's product, a
**listing variant** its sellable unit, an **offer** that unit's price and availability at a moment; a **match** ties a
listing variant to a variant; a **sales order** is the customer's order with **lines**, each filled from stock or by a
**drop-ship**; a **purchase order** is addressed to a supplier, for stock or for a customer.

| Actor | Who | How they reach the application |
|---|---|---|
| **Salesperson** | On the floor or the phone: finds what can be sold and when, quotes, takes the order, chooses how each line is filled, takes the deposit, tells the customer | The launcher → `inventory.<domain>`; a tablet in the showroom (Find is designed at 375 px first); the command bar |
| **Buyer** (merchandiser) | Keeps the catalog and prices, adds and tends sources, matches listings, watches prices and MAP, raises purchase orders for stock and places drop-ships, deals with suppliers | The desk |
| **Warehouse** | Receives, puts away, counts, transfers, adjusts, picks and ships, takes returns in | The warehouse (a phone: receive and count at 375 px) |
| **Inventory admin** | Settings (sizes, attributes, crawl policy, sequences, tax rates, the feed's keys), every record, exports, the agents' settings. A super-admin holds it (the kernel gives them the admin role) | The admin area |
| **An agent** | The shipped **expert** and **Buyer agent** on install; any other agent a super-admin grants a role: reads what its role may, drafts orders, proposes matches, runs pulls; placing an order with a supplier pauses | Records MCP (run token) · Actions MCP (relayed) · the command bar · a duty (§5) |
| **A customer** | Holds an order's secure link: sees the order, its lines, payment status, delivery or pickup, tracking | `/o/<token>` — read-only (§4) |
| **A supplier** | Holds a purchase order's secure link: acknowledges, declines a line, adds an expected date and tracking | `/s/<token>` — three actions, no account (§4) |
| **A consumer of the feed** | The business's website, another installation of this application, a partner store: a key | `GET /api/v1/availability` (§4) |
| **The kernel** | Signs people in, delivers the directory, hires and runs the agents, pauses their actions for approval, answers the command bar (the chat endpoint), carries the siblings' reads (K7), texts members (K6) | — |
| **System** | The pulls on their schedules, snapshots, watches and alerts, the outbox, link expiry, the morning report's data — the one timer worker | `source = 'cron'`, every row logged |

Tenancy: the business running the application is the tenant — one installation, one database, one memory with every
other application's episodes. Several stores of one business are **locations** in one installation; a second business is
a second installation (and may be a source for the first through the feed).

## 3. Who sees what — the rules, in one place

**Roles** (published by `app_roles`, `os.app-roles/1`; exactly one `is_admin`; ordered as shown). The base role's key is
`user` on purpose (the installer's `--grant-standing-departments` gives standing departments the role keyed `member`,
`user` or `write`) — but this application is **not** granted to standing departments by default (§13.3): a retailer grants
it person by person.

| Role key | Name | Capability | Rights |
|---|---|---|---|
| `viewer` | Viewer | read | `inventory.read` — the catalog, availability (own and sources), orders and their status; **never cost, margin, or a source's settings** |
| `user` | Sales | write | Viewer's + `orders.write` (quotes and orders, lines, fulfilment choice, delivery), `payments.record` (status and amounts, no processor), `customers.write`, `orders.send` (the order's link and email to the customer), `watches.own`, `notes.write`; sees **retail and MAP**; sees cost and margin only when the setting `sales_sees_cost` is on |
| `warehouse` | Warehouse | write | Viewer's + `stock.receive`, `stock.adjust` (with a reason), `stock.transfer`, `stock.count`, `stock.ship` (pick, pack, ship, tracking), `returns.receive`; sees cost on receipts (it is on the paperwork) |
| `buyer` | Buyer | write | Sales' + Warehouse's + `catalog.write` (products, variants, identifiers, bundles, images), `prices.write` (retail, MAP, cost — logged with history), `sources.write` (sources, their settings and schedules, pulls on demand), `sources.credentials` (set and rotate — never read back), `listings.match`, `purchasing.write` (purchase orders for stock and drop-ship, send, receive against), `suppliers.write`, `returns.write` (authorize, disposition, refund recorded), `watches.all`, `reports.read` (stock value, sell-through, margin, source health); sees **cost and margin** |
| `admin` | Inventory admin (is_admin) | admin | every right + `settings.manage`, `feed.keys` (mint, rotate, revoke), `exports.all`, `agents.settings`, `records.delete` (what deletion allows — §6), `sequences.manage` |

**Reach (`inv_can_see(kind, id)` — one PL/pgSQL function, tested once per statement in every view):**

- **Scope: none.** One business, one catalog, every location visible to every role (a salesperson sells from another
  store's shelf — that is the use case). **Locations are records, not walls.** A multi-store business that needs per-store
  reach is Extended (the kernel's `location` scope; the schema keeps `locations.kernel_location_id` ready for it).
- **Cost is the wall.** `cost_price`, a purchase order's `unit_cost`, a receipt's cost, margin and stock value are visible
  to Buyer and admin, to Warehouse on receipts, to Sales when `sales_sees_cost` is on; the `mcp_*` views null the column
  for everyone else (`inv_sees_cost()`), the records tools say "cost withheld", the feed never carries it.
- **A source's credential is nobody's.** `source_credentials.ciphertext` appears in no view, no tool, no log, no export;
  the screen shows the label and the last four characters; a person with `sources.credentials` sets or rotates, never reads.
- **A customer sees their order** by its token, and nothing else — no cost, no source, no supplier name (a drop-ship line
  reads "ships from our supplier"); **a supplier sees their purchase order** by its token — the lines, the ship-to, the
  customer's first name and delivery notes when the order is a drop-ship, never the customer's phone unless the admin's
  setting `supplier_sees_phone` says so (the carrier needs it for LTL appointments — default on for `ltl` and
  `white_glove` lines, off for `parcel`).
- **An agent sees what its role may see** — the same functions, no special case; the run-facts gate decides which tools
  it is offered; `is_eval` refuses every write.
- **Departments are a dimension, never a wall**: a location may name the department that runs it; an order names the
  salesperson; nothing is hidden by department.

## 4. Doors for those who are not inside — the customer, the supplier, the feed

The kernel's rules: *application users are not OS users*; *never a password, a login form or an account of the
application's own*; for a page someone with no account must reach, **a token is the authority** (GL's secure invoice link
and Consultant Tracking's are the pattern; the table shape is theirs — §6.3).

**The customer's order page** `/o/<48 hex>` (`order_links_secure`): the order's number and date, the lines with retail
prices, delivery or pickup with the promised date, payment status and the balance due, tracking links when a shipment has
them, the business's name and contact, a "message us" `mailto:`; read-only; `noindex`; rate-limited; opening it logged
`order.customer_view` with source `portal`; the link is sent with the order confirmation email (MaluMail) and dies 180
days after the order closes; rotatable. No cost, no source, no supplier anywhere on it.

**The supplier's purchase-order page** `/s/<48 hex>` (`supplier_links_secure`): the purchase order as the supplier should
see it — the business's account number with them, the lines with their SKU and the agreed cost, the ship-to (a location,
or the customer's address for a drop-ship), delivery notes; **three actions, each a POST with the token, CSRF-protected like
the kernel's signing page**: *acknowledge* (with their order reference and an expected date — per line or whole), *decline
a line* (with a reason), *add tracking* (carrier, number, shipped date — per line); each logged `purchase_order.supplier_ack
| supplier_decline | supplier_tracking` with source `portal` and notified to the Buyer; the link is in the purchase order's
email and dies 90 days after the order closes. A supplier who never uses the link costs nothing: the Buyer records the same
three things by hand.

**The availability feed** `GET /api/v1/availability` (`feed_keys`, a key per consumer, hashed, rate-limited at 60 a minute
and 10,000 a day — settings): `?gtin=` or `?sku=` or `?q=` (name and size), answering per variant the business's **retail
price**, its own availability (`in_stock` with the quantity when the setting `feed_shows_quantity` is on, else the state
only), the **best lead time** across its stock and its supplier sources ("ships in 3 days"), how it ships, and — when the
key's consumer is marked `partner` — a **partner price** (a price list the admin names for that key) so another installation
of this application can list this store as a `supplier` source; never cost, never a source's name. Keys are minted,
labelled, rotated (24 h overlap) and revoked on the admin's Feed keys screen; usage counted per key per day (`key_usage`,
Knowledge's table, reused); every answer logged `feed.read` with the key's label and the count, never the query's text
beyond 120 characters.

**Rejected:** a supplier account in the application (the directory is the kernel's; a supplier who needs more than three
actions sends a feed or gets EDI, Extended); a customer login (the order link is enough; a customer portal is the
website's); a public catalog page (that is the website's; the feed serves it).

## 5. Agents and the application — the OS requirement, in detail

**Reading, finding, drafting and proposing are free; spending money, reaching outside, deleting and changing prices
are a person's.** For every agent:

| Action | Category | Why |
|---|---|---|
| `purchase_order_send` (to a supplier — stock or drop-ship), `purchase_order_place` (recording that it was placed on a portal) | `money_out` | A commitment to pay a supplier |
| `order_send` (the confirmation and link to a customer), `order_notify` (a delivery date, a delay), `supplier_message` (an email to a supplier other than the order itself), `feed_key_mint` | `external_send` | Content leaves the business |
| `order_confirm` (a quote becomes an order — the business promises to deliver), `payment_record`, `refund_record`, `return_authorize` | `other` | **Policy default: pause for agents** — a sale and a refund are a person's word to a customer |
| `product_delete`, `variant_delete`, `source_delete`, `listing_forget`, `order_cancel`, `purchase_order_cancel`, `customer_delete`, `stock_adjust` (a quantity changes with no document), `count_post` | `deletion` | |
| `price_set` (retail, MAP or cost on a variant), `source_create`, `source_credential_set`, `source_schedule_set`, `settings_save`, `sequence_set`, `tax_rate_save` | `other` | Configuration, money and reach |
| `product_create`, `product_update`, `variant_create`, `variant_update`, `identifier_add`, `bundle_set`, `image_add`, `note_add`, `listing_match` (**only an identifier match or one a person proposed** — an agent's own guess is a `match_propose`), `match_propose`, `source_pull` (on demand, within the source's rate), `watch_set`, `quote_create` (a sales order in `quote`), `order_line_add` / `order_line_fulfilment_set` (on a quote or an unconfirmed order), `purchase_order_draft` (never sent), `receipt_draft`, `transfer_draft`, `count_start`, `report_run`, `export_download` (what the caller's rights permit; `export_own` in the first draft) | — | An agent must do these alone — this is what makes it useful on the desk |

**Working — how an agent learns there is work.** (a) **The command bar** — a person's turn on the expert ("do we have a
Queen ProAdapt, and if not who ships it fastest", "draft a PO for everything under its reorder point", "what did the
Casper Original cost at casper.com in August"). (b) **A duty** (cron) for the Buyer agent (below). (c) **A mention in
Spaces** of either agent (Spaces' dispatch, the kernel's chat endpoint as that agent). (d) **A watch** that fires writes a
notification to the person who set it — and, when the watch names an agent, an `agent_dispatches` row the worker turns
into one chat turn ("the Zinus 12-inch Queen is back in stock at the feed — three sold lines are waiting on it: draft the
PO"). (e) When the kernel's K8 (wake an agent from an application) lands, dispatches wake the agent directly. Until K8,
the chat turn.

**Shipped agents** (`maludb-os.json` `agents[]`; **hired on install** at the owner's word — §13.12):

| Key | Job | Reads | Acts | Pauses on |
|---|---|---|---|---|
| `expert` — Inventory Expert | The command bar and the house name to mention: answers "what can we sell today in a King hybrid under $1,500", "who ships the ProAdapt Queen fastest and at what cost", "what is on order for Mrs. Alvarez and where is it", "which listings at Malouf are not matched", "how has the Casper Original's price moved since June"; **drafts** a quote from what the salesperson says (lines, sizes, the fulfilment it recommends with the reason), a purchase order for a drop-ship line, a transfer between stores; explains a source's health; never spends, sends or deletes unless the asker says so, and then pauses | every records tool, `record_history`, `actor_timeline` | `quote_create`, `order_line_add`, `order_line_fulfilment_set`, `purchase_order_draft`, `transfer_draft`, `match_propose`, `listing_match` (identifier matches), `source_pull`, `watch_set`, `note_add`, `order_confirm` (paused), `purchase_order_send` (paused), `order_send` (paused) | `order_confirm`, `purchase_order_send`, `order_send`, `price_set` |
| `buyer` — Stock Buyer | A duty every morning 06:30 (`30 6 * * *`): **the morning note** — (1) sold lines at risk: confirmed drop-ship lines whose offer went `out_of_stock` or whose source failed to pull, and stock lines with no on-hand; (2) reorder: variants at or under their reorder point with the cheapest in-stock supplier offer and a **drafted purchase order** per supplier; (3) prices: retail under MAP, cost moved more than 5 % (setting), a reference price under the business's retail by more than the setting; (4) unmatched listings with its **proposals** (name, brand, size and dimensions compared; a confidence); (5) sources: pulls failed or blocked, feeds stale beyond their schedule, lead times drifting; (6) purchase orders awaiting acknowledgment past N days, drop-ships without tracking past the expected date; (7) returns awaiting disposition — one note to the Buyer (named in the configuration — `INV_BUYER_EMAIL` in `config/.env` seeds `inv_settings.buyer_member_id`, the
settings screen changes it; the first super-admin until then — D12) and to `#inventory` in Spaces when installed; drafts never sent | `reorder_candidates`, `lines_at_risk`, `price_exceptions`, `unmatched_listings`, `source_health`, `purchase_orders_open`, `returns_open`, `find`, `availability`, `get_*` | `purchase_order_draft`, `match_propose`, `watch_set`, `note_add`, `message_send` (the kernel's, to the Buyer's assistant) | nothing (it never sends, spends, prices or deletes) |

**Any other agent** hired by the kernel reaches Inventory when a super-admin grants it a role (the kernel's grant screen);
its tool grants are its own; the shipped grants above are a floor the super-admin may widen. **A person's assistant** asked
"where is my order for the Jacksons" is the kernel's tree delegating to the expert; nothing here knows the tree.

**Skills** (`skills/`): `inventory-basics` (what a product, a variant, an identifier, a location, a source, a listing, an
offer, a match, an order line's fulfilment and a purchase order *are*; which tool answers what — the one page every agent
reads), `finding-stock` (how to answer "can we sell X": own first, then supplier sources by cost and lead time, then the
references; say the lead time and how it ships; never quote cost to a customer; say when a source is stale), `matching-
listings` (identifiers first; name + brand + size + dimensions second; never match across sizes; a proposal states its
evidence), `buying` (reorder points, the cheapest in-stock supplier, one PO per supplier, drop-ship ship-to is the
customer's, MAP is not cost), `talking-to-customers` (what an order email may say; never a supplier's name; never a
promise the offer does not support); runbooks (`kind: runbook`): `morning-note`, `draft-a-dropship`, `reorder-run`,
`price-check`, `match-queue`.

## 6. Memory model — what Inventory remembers

**Record memory** (PostgreSQL 17, `<tenant>_inventory`, db/001–0NN). Everything is keyed by **bigint** as in the siblings.
Money is `numeric(12,2)` in one currency (a setting; multi-currency Extended); quantities are integers (packaged goods —
no fractions, no units of measure: a bundle's components are counts); weights in grams and dimensions in millimetres,
shown in the business's units (a setting). A source's raw objects are kept **trimmed** (`raw jsonb`, the fields the
connector read, never the whole page), and never in a log.

| Area | Tables | Notes |
|---|---|---|
| The mirror | `members` (+ `is_agent`, `is_external`), `department_members`, `departments`, `sso_nonces`, `member_sessions`, `directory_sync_state` | the kernel's ids; `members.roles text[]` from the claims and `access[]` |
| Roles | `inv_rights`, `inv_roles`, `inv_role_rights`, `mcp_app_roles` | `inv_has_right(right)`, `inv_sees_cost()` |
| Settings | `inv_settings` (one row: business name and contact for the crawler's user-agent and the doors, currency, units (imperial/metric), `sales_sees_cost`, `supplier_sees_phone`, `feed_shows_quantity`, the sizes list, the attribute keys and their kinds, reorder defaults, the Buyer's member id, the morning note's thresholds (cost move %, reference undercut %, ack days), crawl limits (requests a second per host, back-off ladder), link lifetimes, snapshot heartbeat days), `document_sequences` (kinds `sales_order`, `purchase_order`, `goods_receipt`, `return`, `adjustment`, `transfer`, `count`), `tax_rates` | seeded; the two shared tables reused verbatim (§6.3) |
| Catalog | `brands` (name, website, `supplier_id` nullable — the brand's own dealer program), `product_types` (seeded: Mattress, Foundation, Adjustable base, Pillow, Protector, Sheets, Frame, Topper, Other), `products` (brand, product_type, name, description, `attributes jsonb`, `kind` single/bundle, `status` draft/active/discontinued, `options jsonb` (the option names in order — Size first), `reorder_point` default for its variants, `ships_how` default, created/updated/by), `product_variants` (product, `sku` unique, `option_values jsonb` (`{"Size":"Queen"}`), `size_key` (the normalized size for matching), `barcode` (GTIN-14 normalized; unique when set), `mpn`, `weight_g`, `length_mm`/`width_mm`/`height_mm` (shipping dims), `ships_how`, `retail_price`, `map_price`, `cost_price` (the business's standard cost — the feed's or the last receipt's, a person's choice), `reorder_point`, `reorder_qty`, `active`, `serialized` (reserved — Extended)), `variant_identifiers` (variant, `kind` gtin/upc/ean/mpn/asin/ebay_epid/walmart_item_id/supplier_sku/other, `value`, `source_id` nullable (a supplier's SKU belongs to that source), unique per kind+value+source), `bundle_components` (bundle variant → component variant, qty), `price_history` (variant, kind retail/map/cost, old, new, by, at, reason), `product_images` → `attachments` | `size_key` is derived by trigger from the Size option through the settings' synonyms table (`Cal King` = `California King` = `CK`); a bundle has variants like any product (a Queen set) whose components are resolved by size |
| Locations and stock | `locations` (name, kind warehouse/showroom/store/in_transit/returns/offsite, address, `department_id` nullable, `kernel_location_id` nullable (the kernel's site, when known), `is_sellable`, `allow_negative` false, active), `inventory_balances` (variant × location: `qty_on_hand`, `qty_allocated`, `qty_floor_model`, updated_at), `inventory_transactions` (the movement ledger: `group_id`, `txn_type` receipt/issue/transfer_out/transfer_in/adjustment/count_correction/sale/return/floor_model_in/floor_model_out/reversal, variant, location, `qty` (signed, never zero), `unit_cost`, `counterparty_kind` none/location/supplier/customer/disposal, `counterparty_id`, `reason_code_id`, `reference_kind` goods_receipt/transfer/adjustment/count/shipment/return/reversal/opening, `reference_id`, `reverses_id`, `idempotency_key` unique, occurred_at, posted_at, actor), `reason_codes` (seeded: damaged, found, lost, floor model, sample, donation, correction), `goods_receipts` + `goods_receipt_lines` (number, supplier, purchase order nullable, receiving location, status draft/posted/cancelled, delivery note ref; lines: PO line nullable, variant, qty, unit_cost, discrepancy kind/note, put-away location), `inventory_transfers` + lines (from, to, status draft/in_transit/received/cancelled; shipped and received by/at), `inventory_adjustments` + lines (location, reason, lines with qty delta and unit cost), `inventory_counts` + lines (location, status open/posted; counted qty vs system qty; the posting writes `count_correction` transactions) | the balance is maintained by trigger from the transactions and **never written directly**; a sale allocates (`qty_allocated`) at confirmation and issues at shipment; a floor model is on hand and flagged; a negative balance is refused unless the location allows it |
| Suppliers and sources | `suppliers` (Cidery's shape — name, kind, contact_name, email, phone, address, notes, active — **appended** `website`, `account_number`, `terms`, `dropships` bool, `lead_time_days` default, `order_method` email/portal/api/edi/phone, `order_email`, `portal_url`, `min_order`), `supplier_items` (supplier, variant, `supplier_sku`, `cost`, `lead_time_days`, `moq`, `active`, last seen — the dealer's price sheet as a table; a `feed` pull updates it), `sources` (name, `connector` (§0.2), `role` supplier/reference, `supplier_id` nullable, `base_url`, `settings jsonb` (per connector: collections, column mapping, marketplace id, search terms, sitemap URL, URL list, the price list for an `inventory_feed`), `credential_id` nullable, `schedule_minutes` (0 = manual), `rate_per_second`, `user_agent` override, `robots_state` ok/blocked/unknown + checked_at, `last_pull_id`, `last_ok_at`, `consecutive_failures`, `paused_at`, `paused_reason`, `active`), `source_credentials` (source, `kind` api_key/oauth_client/basic/sftp_password/sftp_key/rsa_signing/bearer, `label`, `last4`, `ciphertext bytea` (libsodium secretbox under `INV_SECRETS_KEY` from `config/.env`; one place decrypts — `app/sources/credentials.php`), `rotated_at`, by), `source_pulls` (source, kind scheduled/manual/search, started/finished, status ok/partial/failed/blocked, `listings_seen`, `listings_new`, `listings_changed`, `variants_changed`, `http_requests`, `bytes`, `error` (200 chars), `policy` (the robots and rate facts followed), by), `listings` (source, `external_id`, `handle`, `url`, `title`, `vendor`, `product_type`, `tags text[]`, `raw jsonb` (trimmed), `product_id` nullable (a product-level match), `first_seen_at`, `last_seen_at`, `removed_at`), `listing_variants` (listing, `external_variant_id`, `title`, `option_values jsonb`, `size_key`, `sku`, `barcode`, `mpn`, `variant_id` nullable (the match), `match_kind` gtin/sku/mpn/supplier_sku/manual/proposed_accepted, `match_confidence`, `matched_by`, `matched_at`, `price`, `compare_at_price`, `currency`, `cost_price` (feeds only), `availability` (§0.3), `qty` nullable, `lead_time_days` nullable, `ships_how` nullable, `url`, `first_seen_at`, `last_seen_at`, `removed_at`), `offer_snapshots` (listing_variant, pull, observed_at, price, compare_at_price, cost_price, availability, qty, lead_time_days — **written when any of them changed since the last snapshot, and once a day regardless** (the heartbeat, a setting), so a chart has points and a silence means "unchanged"), `match_proposals` (listing_variant, variant, confidence, `evidence jsonb` (what matched: name tokens, brand, size, dims), proposed_by (an agent or the matcher), status proposed/accepted/dismissed, decided_by/at), `source_templates` (seeded: the known stores and feeds with their connector and settings, filled from Phase 0's survey — a person picks one and adds a credential) | a pull is **one transaction per listing** (a failure mid-way keeps what was read); `last_seen_at` older than two pulls marks a listing `removed_at` (the offer becomes `unknown`, the match stays); a `search` pull (Find asked a source live) writes listings like any other |
| Availability | `watches` (member or agent, kind back_in_stock/price_below/cost_below/map_breach/lead_time_over/removed, target variant or listing_variant or product, threshold, `fired_at`, `active`), the read functions below | a watch fires once per state change, not per pull |
| Customers and orders | `customers` (GL's shape — name, legal_name, email, phone, billing_address, shipping_address, tax_id, terms_days, currency, `income_account_id` (kept, no FK — §6.3), `tax_rate_id`, `member_id`, notes, archived_at, created_by — **appended** `phone_alt`, `source` walk_in/phone/web/referral/other, `email_opt_in`), `sales_orders` (number, customer, `status` quote/confirmed/in_fulfilment/shipped/delivered/closed/cancelled, `origin` entered/phone/web/agent, `salesperson_member_id`, `location_id` (the store that sold it), ordered_on, `promised_on`, `delivery_method` pickup/delivery/parcel/ltl/white_glove, ship-to fields (name, address lines, city, region, postal, country, phone, notes), `tax_rate_id`, subtotal/discount/tax/shipping/total (recomputed by trigger), `payment_status` unpaid/deposit/paid/refunded/partial_refund, `amount_paid`, `customer_reference`, notes, `confirmed_by/at`, `closed_by/at`, `cancelled_by/at`, `cancel_reason`), `sales_order_lines` (line_no, variant, qty, `unit_price`, `discount`, `line_total`, `fulfilment_kind` stock/dropship/backorder/pickup, `location_id` (stock), `listing_variant_id` + `source_id` (drop-ship: the offer it was sold against, with the offer's price and lead time **snapshotted** on the line: `offer_cost`, `offer_lead_time_days`), `purchase_order_line_id` (once placed), `qty_allocated`, `qty_shipped`, `qty_returned`, `status` open/allocated/ordered/shipped/delivered/cancelled/returned, `serials text[]` (typed at shipment), notes), `order_payments` (order, kind deposit/balance/refund, amount, method cash/card/check/transfer/financing/other, reference, taken_by, at — **a record, never a charge**), `shipments` + `shipment_lines` (order, kind own_delivery/parcel/ltl/dropship/pickup, carrier, tracking, shipped_at, delivered_at, by; lines: order line, qty), `order_links_secure` (GL's `invoice_links_secure` shape keyed `sales_order_id`) | confirming allocates every stock line (refused when the location cannot cover it unless the line is made a backorder); a drop-ship line's purchase order is drafted at confirmation (one per supplier per order) and **placed by a person**; shipping a stock line issues the transaction; a drop-ship line is "shipped" when the supplier's tracking arrives (the link or by hand) |
| Purchasing | `purchase_orders` (number, supplier, `kind` stock/dropship, `sales_order_id` nullable, `status` draft/sent/acknowledged/partial/received/closed/closed_short/cancelled, `ship_to_kind` location/customer, `location_id` nullable, ship-to snapshot fields for a drop-ship, ordered_on, expected_on, `supplier_order_ref`, `sent_via` email/portal/api/edi/phone, `sent_at`, `sent_by`, `acknowledged_at`, `subtotal`, `shipping_cost`, `total`, notes, approved_by/at, created_by…), `purchase_order_lines` (line_no, variant, `supplier_sku`, `listing_variant_id` nullable (the offer it was ordered against), qty_ordered, `unit_cost`, expected_on, qty_received, `sales_order_line_id` nullable, `status` open/acknowledged/declined/partial/received/shipped/closed_short/cancelled, `supplier_note`, `tracking_carrier`, `tracking_number`, `shipped_at`), `supplier_links_secure` (the same shape keyed `purchase_order_id`), `purchase_order_events` (the supplier's acknowledgments, declines and tracking as rows — the portal door writes here; a person's "they called" writes here too) | a stock PO is received on a `goods_receipt`; a drop-ship PO's lines are received when the customer's line is delivered; cost on a received line becomes the variant's `cost_price` when the setting says `last_receipt` |
| Returns | `return_authorizations` + `return_lines` (order, line, qty, reason (seeded: comfort, damaged, wrong item, changed mind, warranty), `disposition` restock/floor_model/dispose/return_to_supplier/donate, pickup or drop-off and its date, status requested/approved/received/closed/denied, `refund_amount` recorded, `restocking_fee`, received_at, by) | receiving a return with `restock` writes a `return` transaction; `return_to_supplier` drafts a note on the supplier's PO (a vendor return document is Extended) |
| Notifications | `notifications` (member, kind watch/line_at_risk/pull_failed/po_ack/po_tracking/return/morning_note/mention, entity, read_at), `notification_prefs`, `notification_outbox` (email through MaluMail; K6 texts to members for a watch they chose) | GL's canonical shapes (§6.3) |
| Files | `attachments` (product images, a price sheet, a supplier's invoice, a delivery photo, a return photo) | GL's canonical; `record_type` widened |
| Agents | `agent_dispatches` (GL's shape; `kind` widened to `watch`, `ask`, `duty_proposal`), `buyer_proposals` (kind reorder/match/price/at_risk, subject, the drafted record id, status proposed/accepted/dismissed, by) | §5 |
| Feed | `feed_keys` (`mcp_access_tokens`'s shape with Knowledge's bound-token columns: label, consumer kind website/installation/partner, `price_list` nullable, rate limits, hashed, rotated/revoked), `key_usage` (Knowledge's) | §4 |
| Tokens | `mcp_access_tokens` | the contract's; a person's own, read-only |

### 6.1 The connector interface (the exemplar of slice 3)

One PHP interface, `app/sources/Connector.php`, five methods: `probe(source)` (can this source be read as configured? —
the robots check, a one-page fetch, the credential's handshake; answers ok/blocked/misconfigured with a sentence),
`pull(source, pull)` (read everything: a generator of normalized listings, each with its variants and offers, written
one transaction at a time by the worker), `search(source, query)` (read what matches a query **live**, for Find's "ask
the sources now" — only connectors with a search: `shopify` (by collection filter) and `woocommerce` (`search=`) in v1 — `ebay`,
`amazon`, `walmart` and `inventory_feed` when added; the others answer from the last pull), `lookup(source, identifiers)` (one or a
few by GTIN/ASIN/id), `capabilities()` (has_search, has_lookup, gives_qty, gives_cost, needs_credential, is_reference).
A connector returns the **normalized listing** (`external_id, handle, url, title, vendor, product_type, tags, raw` and
`variants[]: external_variant_id, title, option_values, sku, barcode, mpn, price, compare_at_price, currency, cost_price,
availability, qty, lead_time_days, ships_how, url`) and nothing else; the worker does the matching, the snapshots, the
watches and the logging — so a new connector is one class and one `source_templates` row. HTTP goes through one client
(`app/sources/http.php`) that enforces the crawl policy (§0.2), caches by `ETag`, records every request's host, status
and bytes on the pull, and refuses to follow a redirect to a login page.

### 6.2 Matching — how a listing variant becomes "ours"

In order, the first that holds: (1) **GTIN** — the listing's barcode, normalized to GTIN-14, equals a variant's barcode or
a `variant_identifiers` row of kind gtin/upc/ean; (2) **supplier SKU** — the source belongs to a supplier and the
listing's SKU equals a `supplier_items.supplier_sku` or an identifier of kind `supplier_sku` for that source; (3) **MPN
+ size** — the listing's MPN equals a variant's MPN or identifier and the `size_key`s agree; (4) **marketplace id** —
ASIN, EPID, Walmart item id recorded as identifiers; (5) **a person's match** (`listing_match` on the listing's screen or
the match queue — remembered, so the next pull keeps it); (6) **a proposal** (the matcher's own scoring — brand equal,
name tokens shared, `size_key` equal, dimensions within 2 cm, type equal — or the Buyer agent's, with evidence) that a
person accepts. A match never crosses sizes (`size_key` must agree or be unknown on one side); a listing whose source
changes its external id keeps its match by GTIN. An accepted proposal is recorded as `proposed_accepted` with the
evidence; a dismissed one is remembered so it is not proposed again. **The match queue** (§9) is the Buyer's daily work;
the morning note counts it.

### 6.3 Tables against the estate — read, reuse, new (the shared-schema rule)

The estate has one data model in many databases (`maludb-os-integration` 0.7.0, `shared-schema.md`). Every table above
was decided against the nine sibling applications' `db/*.sql` before it was written; the definition of every reused
table lives in THIS repository's migrations, so the installer creates it whether or not the sibling is installed.

| Table(s) | Decision | Source and what differs |
|---|---|---|
| `members`, `departments`, `department_members`, `sso_nonces`, `member_sessions`, `directory_sync_state` | **reuse** | the kernel contract (Consultant Tracking `db/001`); `members` **appends** `is_agent`, `is_external` (Spaces') |
| `activity_log`, `activity_ingest_state` | **reuse** | `memory.md` / CT `db/002`; **appends** `source_id`, `sales_order_id`, `purchase_order_id` (audit keys for the trails) |
| `mcp_access_tokens` | **reuse** | CT `db/003`; `feed_keys` takes the same shape with Knowledge's bound-token columns (label, consumer, limits) — the catalogue's proposed extension |
| `key_usage` | **reuse** | Knowledge `db/` (per key per day) |
| `inv_rights`, `inv_roles`, `inv_role_rights`, `mcp_app_roles` | **reuse** (shape) | CT `db/004` (`ct_` → `inv_`); the five roles of §3 |
| `inv_settings` | **reuse** (skeleton) | the `app_settings` singleton skeleton; the columns are this application's |
| `document_sequences`, `tax_rates` | **reuse** | **GL `db/006` canonical**, verbatim; `kind` list ours |
| `attachments`, `notes` | **reuse** | **GL `db/009` canonical**, verbatim; `record_type` widened |
| `customers` | **reuse** | **GL `db/009` canonical** — every column and type kept; **one deviation recorded**: `income_account_id` keeps its name and type but no `REFERENCES accounts(id)` (the chart is the ledger's; the column holds the ledger's id when a person fills it; the ledger's K7 read of our sales uses it); `tax_rate_id` keeps its FK (we reuse `tax_rates`); **appended** `phone_alt`, `source`, `email_opt_in` |
| `suppliers` | **reuse** | Cidery `db/004` (`app.suppliers`: name, kind, contact_name, email, phone, address, notes, active) — the closest supplier table in the estate; GL's `vendors` is the ledger's payable party (owned — **read** when the ledger wants to match ours, by name); **appended** the dealer-program columns of §6 |
| `supplier_items` | **new** (recorded) | Cidery's `supplier_items` keys `item_id` (a material); ours keys `variant_id` and adds `moq` — recorded as two shapes under one family, as the catalogue did for `messages` |
| `inventory_balances`, `inventory_transactions` | **new** (recorded) | Cidery's are **lot- and bond-keyed** (`lot_id NOT NULL`, `premises_id`, `tax_state`, `ttb_category` — bulk material in bond for a producer); ours are variant-keyed counts of packaged goods; the family columns are kept with the same names (`group_id`, `txn_type`, `qty`, `unit_cost`, `counterparty_kind/id`, `reason_code_id`, `reference_kind/id`, `reverses_id`, `idempotency_key`, `occurred_at`, `posted_at`) so a reader of one recognises the other — recorded as two shapes under one family; **ours becomes the canonical shape for unit-counted goods** |
| `goods_receipts`, `goods_receipt_lines`, `inventory_transfers`, `inventory_adjustments`, `inventory_counts` (+ lines), `reason_codes`, `locations` | **new** (recorded) | Cidery's documents of the same names carry premises, lots, catch weight and purchase units; ours carry none — the document family (number, status, lines, posted_by/at) and the names are kept; recorded as above |
| `purchase_orders`, `purchase_order_lines` | **new** (recorded) | Cidery's (`premises_id`, purchase units, `to_base_factor`) compared; ours add the drop-ship half (`kind`, `sales_order_id`, the ship-to snapshot, the supplier's events) — the canonical purchase order for a retailer |
| `sales_orders`, `sales_order_lines`, `shipments`, `order_payments` | **new** (recorded) | Cidery's `sales_orders` (`premises_id`, `destination_kind`, packaging configurations) compared; the shared columns keep its names (`number`, `customer_id`, `status`, `origin`, `ordered_on`, `customer_reference`, `confirmed_by/at`, `closed_by/at`, `cancelled_by/at`, `cancel_reason`); the fulfilment choice per line is new — **canonical for a retailer's order** |
| `order_links_secure`, `supplier_links_secure` | **reuse** (shape) | GL `db/010` `invoice_links_secure`, the record key named for the record (`sales_order_id`, `purchase_order_id`) |
| `notifications`, `notification_prefs`, `notification_outbox` | **reuse** | **GL `db/014` canonical**; `kind` lists ours; every recipient a member (Spaces' substitution — a customer or supplier is reached by the order's own email, not the outbox) |
| `agent_dispatches` | **reuse** | GL `db/014` = CT `db/013`; `kind` widened to `watch`, `ask`, `duty_proposal`; **appends** `watch_id`, `listing_variant_id` |
| `brands`, `product_types`, `products`, `product_variants`, `variant_identifiers`, `bundle_components`, `price_history`, `product_images` | **new** | GL's `items` (a price-list line with accounts), Reservations' `products` (a subscription plan) and the Cidery's `items`/`products` (materials and recipes) were compared: none is a sellable catalog with variants and identifiers — these become **canonical** for a product catalog; Reservations' `products` is recorded as a name collision of a different concept |
| `sources`, `source_credentials`, `source_pulls`, `source_templates`, `listings`, `listing_variants`, `offer_snapshots`, `match_proposals`, `watches` | **new** | nothing close (Knowledge's `sources`/`feeds` are documents to read, not catalogs to match — a name collision recorded: Knowledge's `sources` is "an ingested document", ours "a place offers are read from"; the catalogue gets both) — **canonical** for external catalogs and offers |
| `return_authorizations`, `return_lines`, `buyer_proposals`, `purchase_order_events` | **new** | nothing close |
| HR's employment, the ledger's chart, parties and books, Help Desk's tickets, Spaces' pages, Knowledge's bases, the kernel's directory | **read** | another application's data; never copied; the ledger reads us (§8) |

**Owed to the catalogue** (`shared-schema.md` §4): `products`/`product_variants`/`variant_identifiers` as the canonical
catalog; `sources`/`listings`/`offer_snapshots` as the canonical external-offer tables; `inventory_balances`/
`inventory_transactions` and the stock documents as the unit-counted shape beside the Cidery's lot-keyed one;
`sales_orders`/`purchase_orders` as the retailer's shapes; the `sources` and `products` name collisions.

**Views and functions**: `mcp_*` over every table above with `security_barrier`, the caller's rights tested once per
statement (`inv_sees_cost()` nulls the cost columns); the reads that matter are **SQL functions** — `inv_find(q, size,
filters)` (the one search: variants by name, brand, SKU, GTIN, MPN, size, type, price band; each with own availability
and the best supplier offer), `inv_availability(variant)` (own by location: on hand, allocated, floor, available; every
source's current offer ranked: supplier offers by cost then lead time, reference offers by price; the business's prices;
the best lead time), `inv_atp(variant, qty, when)` (can we promise it: from stock, from which source, by when),
`inv_bundle_availability(variant)`, `inv_offer_history(listing_variant, since)` (the snapshots as a series),
`inv_price_history(variant)`, `inv_reorder_candidates()`, `inv_lines_at_risk()`, `inv_price_exceptions()`,
`inv_unmatched_listings(source)`, `inv_source_health()`, `inv_stock_value(as_of, by)` (at cost; by location, brand,
type), `inv_sell_through(days, by)`, `inv_lead_time_actuals(supplier)` (promised vs actual from the PO events),
`inv_order_timeline(order)`, `inv_purchase_orders_open()`, `inv_returns_open()`, `inv_feed_answer(key, q)` — so a screen,
a tool, the feed and an export never disagree.

**Activity memory**: `activity_log` through one `log_activity()`, `entity.verb` events, shipped to the tenant's one
MaluDB as `activity` episodes tagged `inventory`. **There is no audit table**: a record's history is `mcp_activity_log
WHERE entity_type = … AND entity_id = …` (the sibling rule); **an offer's history is its snapshots; a price's history is
`price_history`**. A credential, a customer's address and phone and a source's raw object are **never in a log payload**
(ids, names, SKUs, counts, states and amounts are). Events (the manifest names them): `product.create|update|
discontinue|delete`, `variant.create|update|price_set|identifier_add|identifier_remove|bundle_set|delete`,
`location.create|update|archive`, `stock.receive|adjust|transfer_send|transfer_receive|count_start|count_post|
allocate|issue|return|reverse`, `supplier.create|update|archive`, `source.create|update|credential_set|
credential_rotate|schedule_set|pause|resume|probe|pull_start|pull_done|pull_fail|pull_blocked|search|delete`,
`listing.new|changed|removed|match|unmatch|propose|accept|dismiss|forget`, `offer.change` (one per listing variant per
pull that changed something — the counts, not the values, in the payload; the values are the snapshot), `watch.set|
fire|clear`, `customer.create|update|archive|delete`, `order.quote|confirm|line_add|line_update|line_fulfilment_set|
line_cancel|send|customer_view|payment|refund|ship|deliver|close|cancel`, `purchase_order.draft|send|place|
supplier_view|supplier_ack|supplier_decline|supplier_tracking|receive|close|cancel`, `return.request|approve|receive|
disposition|close|deny`, `feed.key_mint|key_rotate|key_revoke|read`, `agent.dispatch|reply|fail`, `buyer.propose|
accept|dismiss|note`, `report.run`, `export.download`, `share.read` (a sibling read us), `settings.save`, `token.mint|
revoke`, `member.sign_on`, `directory.sync`, `screen.view`. A token is never logged.

## 7. The question inventory — what Inventory exists to answer

Kind R = record (the records MCP), A = activity (the activity MCP). Who = the lowest role that may ask (every answer is
filtered by what the asker may see — cost withheld below Buyer). The tool name is fixed here so Phase 1 is a transcription.

**Catalog**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| C1 | What do we sell — by brand, type, size, attribute, price band, status? | R | Viewer | `find_products`, `get_product` |
| C2 | What are this product's variants, their SKUs, identifiers, prices and how they ship? | R | Viewer | `product_variants`, `get_variant` |
| C3 | Which variant has this GTIN / SKU / MPN / ASIN? | R | Viewer | `variant_by_identifier` |
| C4 | What is in this bundle, and is every component available? | R | Viewer | `bundle_components`, `bundle_availability` |
| C5 | How have this variant's retail, MAP and cost changed, and who changed them? | R | Sales (cost: Buyer) | `price_history` |
| C6 | Which variants have no GTIN, no cost, no image, or a retail under MAP? | R | Buyer | `catalog_gaps` |

**Stock**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| S1 | What is on hand, allocated, on the floor and available, by location, for this variant / product / brand? | R | Viewer | `stock_levels`, `stock_by_location` |
| S2 | What moved — receipts, issues, transfers, adjustments, counts — for this variant, this location, this week? | R | Warehouse | `stock_movements` |
| S3 | What is in transit between locations, and what is waiting to be received? | R | Warehouse | `transfers_open`, `receipts_open` |
| S4 | What did the last count find at this location, and what was corrected? | R | Warehouse | `counts`, `count_lines` |
| S5 | What is our stock worth at cost, by location / brand / type, as of a date? | R | Buyer | `stock_value` |
| S6 | What sold through in the last N days, by variant / brand / type, and what is the cover in weeks? | R | Buyer | `sell_through` |
| S7 | Which variants are at or under their reorder point, and what is the cheapest in-stock supplier offer for each? | R | Buyer | `reorder_candidates` |

**Sources, offers and availability — the heart**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| A1 | **Can we sell this — now, and if not who ships it fastest and cheapest?** (own stock by location, every supplier offer ranked, the references, our prices, the best lead time) | R | Viewer | `availability`, `find` |
| A2 | Can we promise N of it by a date, and from where? | R | Sales | `atp` |
| A3 | Which sources exist, what connector, what role, when did each last pull, is it healthy, blocked, stale or paused? | R | Viewer (settings: Buyer) | `find_sources`, `get_source`, `source_health` |
| A4 | What does source X list, with current price and availability, and which of its listings are matched to ours? | R | Viewer | `source_listings`, `get_listing` |
| A5 | What are the offers for this variant across every source, current and ranked? | R | Viewer | `offers_for_variant` |
| A6 | **How has this offer's price and availability moved** since a date (the series)? When did it last go out of stock, and for how long? | R | Viewer | `offer_history`, `availability_timeline` |
| A7 | Which listings are unmatched, and what does the matcher propose for each, with its evidence? | R | Buyer | `unmatched_listings`, `match_proposals` |
| A8 | Ask the sources that can search **live** for a query (a brand and size), and show what came back | R | Sales | `source_search` |
| A9 | What did the last pull of source X read, change, fail on, and what policy did it follow? | R | Buyer | `source_pulls`, `get_pull` |
| A10 | Which of our retail prices are under MAP, which reference prices undercut ours by more than N %, which costs moved more than N %? | R | Buyer | `price_exceptions` |
| A11 | Which sold lines are at risk — the offer gone, the source failing, the stock not there? | R | Sales | `lines_at_risk` |
| A12 | What am I watching, and what fired? | R | Viewer | `my_watches`, `watch_events` |
| A13 | What supplier SKUs and costs do we hold for this supplier (the price sheet), and when were they last seen in a feed? | R | Buyer | `supplier_items` |
| A14 | How do a supplier's promised lead times compare with the actual (from the PO events)? | R | Buyer | `lead_time_actuals` |

**Customers and orders**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| O1 | Which orders are open, by status, salesperson, store, promised date; which are late? | R | Sales | `find_orders`, `orders_late` |
| O2 | What is on order N — lines, how each is filled, where each is, payments, shipments, the timeline? | R | Sales | `get_order`, `order_timeline` |
| O3 | What has customer X bought, returned, and what is open for them? | R | Sales | `find_customers`, `get_customer`, `customer_orders` |
| O4 | What is unpaid, what deposits are held, what is due at delivery this week? | R | Sales | `payments_due` |
| O5 | What is to be picked, packed, delivered or collected today, by location? | R | Warehouse | `fulfilment_today` |
| O6 | Which shipments are out, with carrier and tracking; which drop-ships have no tracking past their expected date? | R | Sales | `shipments_open`, `dropships_untracked` |
| O7 | What did we sell by day / week / brand / type / salesperson, at retail and (Buyer) at margin? | R | Sales (margin: Buyer) | `sales_summary` |

**Purchasing and returns**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| D1 | Which purchase orders are open — drafted, sent, awaiting acknowledgment, acknowledged, partially received; which are past their expected date? | R | Buyer | `find_purchase_orders`, `purchase_orders_open` |
| D2 | What is on PO N, what did the supplier say (acknowledged, declined, tracking), and what is received? | R | Buyer | `get_purchase_order`, `purchase_order_events` |
| D3 | What is on order from supplier X, and what is their open balance of goods? | R | Buyer | `supplier_open_orders` |
| D4 | Which drop-ships are placed for a customer's order, and where is each? | R | Sales | `order_dropships` |
| D5 | Which returns are requested, approved, received, awaiting disposition; what was refunded? | R | Sales | `find_returns`, `get_return`, `returns_open` |
| D6 | What is the morning note — everything the Buyer agent found today? | R | Buyer | `morning_note`, `buyer_proposals` |

**Feed and agents**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| F1 | Which feed keys exist, for whom, and how much did each read this month? | R | Admin | `feed_keys`, `key_usage` |
| G1 | Which agents hold a role here, what did each last do, which dispatches are pending or failed? | R | Buyer | `agents_here`, `agent_dispatches` |

**Activity**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| ACT1 | Who did what to this record, in order? | A | Viewer | `record_history` |
| ACT2 | What did person or agent X do here this week? | A | Viewer | `actor_timeline` |
| ACT3 | What changed since I last looked — orders, stock, sources? | A | Viewer | `recent_activity` |
| ACT4 | When did source X last fail, how often, and what did a sibling read of ours? | A | Buyer | `source_incidents`, `share_reads` |
| ACT5 | Search the activity for a phrase | A | Viewer | `activity_search` |

Plus the contract's: `app_roles`, one guarded `records_search` on the records server, `record_history`. Every question is
a named tool; the reads are the same SQL functions the screens call. Records tools: about 62; activity tools: 7.

## 8. The kernel's part, and the application's doors

- **Sign-on and the mirror**: the contract (`sign-on-and-directory.md`), the kit copied from Consultant Tracking (Help
  Desk's lineage, as Spaces copied it).
- **Roles**: `app_roles` on the records server; the kernel's token admitted to it and to the `shares[]` alone.
- **Agents**: run tokens on the two read servers (the run-facts gate, fail closed); the Actions MCP from the registry; the
  approval hook before every action with a category; the chat endpoint for the command bar; the duty for the Buyer agent.
- **K6 texts**: to a **member** only — a watch they chose to be texted about ("Zinus 12-inch Queen back in stock at Malouf,
  $312"), a line at risk for the salesperson who sold it. **A text to a customer is K28** (§12) — until then, email.
- **K7 — Inventory is a provider.** Shares (`shares[]`), every one a records-server tool, people-free, each answering a
  versioned document:
  | Tool | For | Document | Content |
  |---|---|---|---|
  | `sales_closed` | the General Ledger (G2) | `os.inventory-sales/1` | orders closed in a period: number, date, customer (name and the ledger's id when filled), lines at retail, tax, shipping, payments recorded by method — the ledger's journal entries; also the accounting system's downloadable CSV/JSON of the same (§9) |
  | `purchases_received` | the General Ledger (G2) | `os.inventory-purchases/1` | goods receipts and delivered drop-ships in a period, at cost, by supplier — the ledger's bills |
  | `stock_valuation` | the General Ledger (G2) | `os.inventory-valuation/1` | stock at cost as of a date, by location and brand — the period close |
  | `availability_index` | any sibling (Help Desk's "is it in stock" on a ticket, Spaces' expert answering a channel) | `os.inventory-availability/1` | per variant: our retail, own availability state, best lead time — the feed's answer without quantities or cost |
  | `customer_orders` | Help Desk (HD3) | `os.inventory-orders/1` | a customer's open orders and their status by the customer's email — for a support ticket |
  Reads (`reads[]`): none in v1 (`os_sibling` as a source is Extended).
- **Public doors** (allow-listed in the vhost; everything else needs a session): `/o/<token>`, `/s/<token>` (and its
  three POSTs), `/api/v1/availability`, `/api/v1/health`, `/mcp/records`, `/mcp/activity`. Each is token-checked,
  rate-limited, and logged with `source = 'portal'` (`feed` for the feed).
- **Email**: MaluMail (the application's own optional key, written by the installer's `mail` step): the order confirmation
  with the customer's link, delivery and tracking notices, the purchase order with the supplier's link, the morning note
  to the Buyer; every send through the outbox.
- **Outbound HTTP** is the connectors' alone, through the one client with the crawl policy; nothing else in the
  application calls out but MaluMail, the kernel and MaluDB.

## 9. Screens (the nxl look; Find, an order and receiving are designed at 375 px first — a salesperson on the floor
with a tablet, a warehouse hand with a phone; the catalog, sources and reports at 1280; no modals; cards for named things
— products, sources, suppliers, customers —, tables for stock levels, movements, listings, lines and admin lists; every
name a link; every page a way back)

**The shell**: the sidebar — Home · **Find** · Catalog · Stock · Sources · Orders · Customers · Purchasing · Returns ·
Reports · Admin; the command bar (the kernel's chat endpoint) at the top of every screen; a bottom tab bar at 375 (Home ·
Find · Orders · Stock · Me).

**Home** — the morning note (the Buyer agent's seven headings, each a count that opens its list); lines at risk; pulls
failed or blocked; unmatched listings; purchase orders awaiting acknowledgment; today's deliveries and pickups; for Sales:
my open orders and their next step; for Warehouse: to receive, to pick, to count; for the admin: feed usage, dispatches.

**Find** — one box (`/find?q=&size=`): type a product, brand, SKU, GTIN or MPN; chips for size, type, firmness, price
band, "in stock only", "ships within N days"; results as **cards per variant**: our retail and MAP; **own stock** by
location (on hand − allocated, floor models marked); **supplier offers** ranked (source, cost when permitted, availability,
lead time, how it ships, as of); **reference prices** (the marketplaces, the manufacturer's site); a "ask the sources now"
button (`source_search`, the live connectors, a spinner per source, results merged); "Sell this" opens a quote with the
line and the recommended fulfilment; "Watch" sets a watch. The same function answers the command bar.

**Catalog:** Products (cards by brand/type; filters) · `product-view` (the variants table with stock, best offer and
prices; identifiers; bundle components; images; sources listing it; price history; notes; attachments) · `product-form` ·
`variant-form` (SKU, options, barcode, MPN, dims and weight, how it ships, prices, reorder point) · Identifiers ·
Bundle editor · Brands · Product types · Catalog gaps.

**Stock:** Levels (a table: variant × location, filters, export) · Locations (cards; `location-view`: levels, movements,
open transfers and counts) · Movements (the ledger, filters) · Receive (`receipt-form` against a PO or free; a line per
variant with qty and cost; a put-away location; discrepancies; **a barcode field that accepts a scanner's keystrokes**) ·
Adjust · Transfer (send, receive) · Count (start a count at a location, count by scanning or typing, post with corrections)
· Floor models.

**Sources:** Sources (cards with the connector's badge, health, last pull; "Add from a template") · `source-view`
(listings table with match state, price, availability, last seen; pulls with their policy facts; health; schedule;
settings; the credential's label and last four; Probe, Pull now, Pause) · `source-form` (connector, URL, role, supplier,
schedule, rate, user-agent, and the connector's own settings: collections, column mapping with a preview of the file's
first rows, marketplace, search terms, sitemap) · `listing-view` (the listing's variants, their current offers, **the
price and availability chart** of a variant over time (the `dataviz` rules), the match and how it was made, raw fields
read) · **Match queue** (unmatched listing variants with proposals and evidence; accept, pick another, dismiss, "not ours")
· Supplier price sheets (`supplier_items`) · Watches.

**Orders:** Orders (table by status; late marked) · `order-view` (customer, lines with their fulfilment and where each is,
payments, shipments with tracking, the customer link, the timeline, notes) · `order-form` (customer picker or new; lines
with the **availability picker per line**: stock at a location, or a source's offer with its lead time and cost when
permitted, or backorder; delivery method and ship-to; promised date; tax) · Confirm · Record payment · Ship (pick lines,
carrier, tracking; a stock line issues; a drop-ship line's tracking from the supplier) · Deliver · Cancel · Send
confirmation · Fulfilment today · Shipments.

**Customers:** cards; `customer-view` (orders, returns, notes); `customer-form`.

**Purchasing:** Purchase orders (table by status and kind) · `purchase-order-view` (lines, the supplier's events,
receipts, the supplier link, send/place, the email preview) · `purchase-order-form` (supplier, kind, ship-to, lines from
reorder candidates or a sales order's drop-ship lines, the offer each is ordered against) · Suppliers (cards;
`supplier-view`: items, open orders, lead-time actuals, sources) · `supplier-form` · Receive against (→ Stock's receipt).

**Returns:** Returns (table) · `return-view` · `return-form` (order, lines, reason, pickup/drop-off, disposition) ·
Receive return · Disposition.

**Reports:** Stock value · Sell-through and cover · Sales summary and margin · Price exceptions · Source health and
reliability · Lead-time actuals · Exports (CSV/JSON of sales closed, purchases received, stock valuation — the
accounting system's files; the catalog; the listings).

**Admin:** Settings (business, units, currency, sizes and synonyms, attributes, crawl policy and user-agent, thresholds,
links, the Buyer) · Sequences · Tax rates · Reason codes · Feed keys · Agents (which hold a role, dispatches — read-only;
hiring and grants link to the kernel) · Connections (which siblings read us) · My settings (notifications, texts) · My MCP
tokens · Trail.

## 10. Build order, gates and size

| Phase | Deliverable | Gate |
|---|---|---|
| 0 | this document and the owner's answers (§13); **the live survey of the candidate stores from the build server** (§0.2 — which answer `products.json`, the Store API, JSON-LD; recorded in §16 and seeded as `source_templates`); the schema `db/001`–`0NN` proven on a scratch database (`db/proof/phase0_proof.sql`: every actor of §2; a product with six sizes and a bundle; identifiers of every kind; a balance maintained only by transactions, a negative refused, a floor model; a receipt, a transfer in two halves, an adjustment, a count correction, a reversal; three sources with listings, a pull writing snapshots only on change plus the heartbeat, a removed listing, a blocked source backing off; the matcher's six rules in order, a cross-size refusal, a proposal accepted and one dismissed; `inv_availability` ranking suppliers by cost then lead time and references by price; `inv_atp`; a quote confirmed allocating a stock line and drafting one PO per supplier for the drop-ship lines; a supplier acknowledgment, a decline and tracking by the door; a shipment issuing stock; a return restocked; a watch firing once; `inv_stock_value`, `inv_sell_through`; cost nulled for a Viewer; the feed's answer; a credential absent from every view); the kit (Consultant Tracking's identity, roles, sign-on, MCP common files, HTTP helper, attachments and sync), proven without a kernel (`tests/phase0/run.sh`); the connector interface with **fixtures** for every v1 connector (`tests/fixtures/sources/`: a Shopify `products.json` page, a Woo Store API page, a JSON-LD product page and sitemap, a CSV feed) and the normalizer proven against them; `maludb-os.json` (every port env in `env.required`, the five `shares[]`), `os/{expert,buyer}.md`, ten skills (five runbooks), `deploy/` templates with the public doors and `ROOT_STEPS.sh` (the port pin first); the installer's `plan` reads the repository clean | the owner's go on §13 |
| 1 | `docs/inventory-mcp-tool-surface.md` (every question of §7 a tool; the resolve table; the log-payload rules; the five share documents' shapes; the feed's document), `docs/inventory-action-manifest.md` (screens and actions with their categories — §5), `mcp/action_registry.json`, the **connector spec** (`docs/build-specs/connectors.md`: the interface, each v1 connector's endpoints, pagination, field mapping, error and block handling, the fixtures), and the slice specs in `docs/build-specs/` (`sso-shell` and the nine slices; **sources and connectors (slice 3) is the exemplar**) | **approved together, before any PHP** |
| 2 | `/sso`, `/sso/logout`, the mirror and its timer, `members.roles` from the claims and the feed, `inv_has_right()`, `inv_sees_cost()`, the ingest bridge, the shell (sidebar at 1280, the tab bar at 375, the command bar through the chat endpoint), `/api/v1/health`, the vhost with the public allow-list | hand-off replay refused; an unknown member refused; a revoked grant shuts every door within a minute; cost hidden from a Viewer; 375 and 1280 |
| 3 | the slices, in order: **(1) the catalog** (brands, types, products, variants, identifiers, bundles, prices with history, images, catalog gaps — the CRUD exemplar for workers) → **(2) locations and stock** (locations, the transaction ledger and balances by trigger, receive, adjust, transfer, count, floor models, movements, levels) → **(3) sources, connectors, listings and matching — THE EXEMPLAR** (the interface and HTTP client with the crawl policy; `shopify`, `woocommerce`, `jsonld`, `feed`, `manual`; templates; credentials; the worker's scheduled pulls, snapshots and removals; the matcher's six rules; the match queue; the listing view with its chart; source health) → **(4) Find, availability and watches** (`inv_find`, `inv_availability`, `inv_atp`, the live `source_search`, the cards, watches and their notifications, K6 texts to members) → **(5) customers and sales orders** (customers; quotes and orders with the availability picker per line; confirm with allocation and the drafted drop-ship POs; payments recorded; shipments and delivery; the customer's link and the confirmation email; fulfilment today) → **(6) purchasing, drop-ship orders, receiving and the supplier's door** (POs for stock and drop-ship; send and place; the supplier link and its three actions; events; receive against; lead-time actuals; supplier price sheets) → **(7) the availability feed** (`/api/v1/availability` with keys, partner price lists, usage — the smallest slice; the keyed reference connectors and the `inventory_feed` client are Extended, D7) → **(8) returns, the Buyer agent's data, notifications and the worker's passes** (returns and dispositions; `reorder_candidates`, `lines_at_risk`, `price_exceptions`, `source_health`, the morning note's data, `buyer_propose` and `morning_note_send`; the outbox, the dispatches and key-usage pruning — the worker's passes by the data-owner rule: the heartbeat and watches are slice 4's, link expiry slice 5's, reconciled in Phase 1) → **(9) reports, home, admin and tokens** (stock value, sell-through, sales and margin, exceptions, source reliability, exports and the accounting files; the homes; settings; feed keys; MCP tokens) | each slice: screens + handlers + logging + manifest entries + tools, proven at 375 and 1280 on a scratch database, the installed application never touched |
| 4 | the two MCP servers with the run-facts gate, `app_roles` and the five `shares[]` admitted to the kernel's token, the registry wrapper (`deploy/kernel-registry-inventory.json`), the two agents declared with their grants and the duty; the kernel's Actions-MCP contract proved against its own `application_actions.register()` with a stubbed hook | the command bar answers "can we sell a Queen ProAdapt and who ships it fastest" (`find` + `availability`); an agent's `purchase_order_send` pauses; the Buyer's duty drafts and never sends |
| 5 | the owner's `app_install.php apply` (ports pinned first — §14), DNS and TLS for `inventory.<domain>`, the MaluMail key, the hires, the first sources from the templates, the first catalog (a CSV import of the business's SKUs with GTINs); the end-to-end proof | **a salesperson on a tablet finds a Queen hybrid, sees two on the warehouse shelf and three suppliers' offers with lead times, sells one from stock and one Split King as a drop-ship; the stock line allocates, the drop-ship drafts a PO that the Buyer sends; the supplier opens their link, acknowledges and adds tracking; the customer opens their link and sees both; the warehouse ships the stock line with a scan; a feed pull changes a cost and the Buyer agent's morning note reports it with a drafted reorder; a manufacturer's own site undercuts our retail and the note says so; an unmatched Malouf listing is proposed and accepted; a watch texts the salesperson when a sold-out size returns; the ledger reads `sales_closed` over an approved connection; the website reads the feed with a key** |

**Extended** (after version 1, each its own spec, §11). Size: eleven specs (sso-shell, connectors + nine slices), about
70 screens, 130 actions, 62 records tools, 7 activity tools — Spaces' size, with the connectors in place of the editor.
**Four to five days is the honest estimate**: the connectors and the matcher a day and a half (five connectors, each proven
against a fixture and a live store), orders and purchasing one and a half, the rest and Phase 5's proof with the owner the
remainder.

## 11. Extended, later, and what other applications owe

**Extended** (each its own spec, in the likely order): **the keyed reference connectors** (`ebay` Browse, `amazon` PA-API as a
reference only, `walmart` affiliate — §0.2, each a class and a fixture on the shipped interface, the owner's keys as source
credentials) and **the `inventory_feed` client** (another installation of this application as a source) · **the EDI 846 inventory
advice as a flat file** through the `feed`
connector (then through a network — Rithum, SPS Commerce — as a pulled file) · **ordering by API** (`purchase_order_send`
placing the order itself: a Shopify draft order through a dealer's Storefront token, a supplier's REST API, then the 850 →
855 → 856 → 810 loop) · **carrier integrations** (rate quotes on a quote, labels on a shipment, tracking pulled on a
schedule — EasyPost or ShipStation as one connector) · **serial-tracked units** (`stock_units`: a serial per unit, law
tags, warranty lookups, a serial on the shipment and the return) · **texts to customers** (K28) · **a sibling's stock as a
source** (`os_sibling` over K7 — the Cidery's finished goods) · **multi-store scopes** (the kernel's `location` scope with
per-site reach, the `kernel_location_id` already on `locations`) · **promotions and price schedules** (a sale event from a
date with MAP-aware floors) · **selling on the marketplaces** (listing and order import — a different product) · **demand
forecasting** (reorder points from sell-through and seasonality rather than typed) · **multi-currency** · **a vendor
return document** (RMA to the supplier with credit tracking) · **barcode scanning by camera** (the receiving field already
takes a scanner's keystrokes; the phone's camera through the BarcodeDetector API is a small step) · **K8 wakes** replacing
the chat-turn dispatch for watches.

**Later**: a storefront or checkout (the website's); a payment processor (money out is never the application's); a POS.

## 12. What the kernel and its siblings owe this application

| # | Item | Why the application cannot do it | Until then |
|---|---|---|---|
| **K27** | **Catalog entry `inventory` (kind `ours`) seeded by a migration** in `maludb-os-core`, business area **Operations**, category **`other`** (the catalog's category check lists no inventory category — the migration may widen the check with `inventory` in the same change, recorded), icon `feather-package`, criticality `medium` — so every installation shows "Inventory — from us, not installed" and the installer's `apply` finds its row | the catalog is the kernel's; Knowledge's row (db/170) came the same way | `application_catalog_save` by hand on this install |
| K28 | **A text to a non-member** through the kernel's notification number (a customer: "your mattress ships Tuesday") with the same opt-out and daily limits — the application never holds a Twilio key | K6 texts members only; the number is the kernel's | email to customers; a text is a person's phone |
| K16 | **K8 (wake an agent from an application)** — owed to Help Desk, GL, Consultant Tracking and Spaces; here for watches that name an agent | messaging is an agent's tool | the chat-turn dispatch |
| G2 | **The General Ledger reads `sales_closed`, `purchases_received` and `stock_valuation`** and posts them (a sales journal per period, bills per supplier, the inventory asset at close) — GL's `reads[]` and its import screens | the ledger's data | the accounting files (CSV/JSON) downloaded from Reports and imported by hand |
| HD3 | **Help Desk reads `customer_orders`** on a ticket whose requester's email matches a customer | Help Desk's screen | the agent answers from the records tool |
| S1 | **Spaces' expert reads `availability_index`** when asked in a channel | Spaces' grant | a mention of this application's expert |

Nothing else is owed: sign-on, the directory, the chat endpoint, K6 texts to members and K7 reads all exist. K27 is small
and comes first.

## 13. Decisions the owner is asked for (recommendation first)

1. **Name and key** — "Inventory", catalog key `inventory`, repository `maludb-os-inventory` (created private
   2026-10-05, in the org), local clone and install path `/srv/apps/inventory`, DNS label `inventory`
   (`inventory.<domain>`), database `<tenant>_inventory`, roles `inventory_rw` / `_records_ro` / `_activity_ro`, SQL
   prefix `inv_`. Confirm, or choose another name ("Stock" — key `stock`, shorter, but reads as shares on a DNS name;
   "Merchandise" — key `merchandise`; the key must be `[a-z][a-z0-9_]*`).
2. **Roles and names** — Viewer (`viewer`, read, never cost), Sales (`user`), Warehouse, Buyer, Inventory admin
   (is_admin). Confirm the words, and that **cost and margin are Buyer's and admin's** (Warehouse on receipts; Sales
   when the setting `sales_sees_cost` is on — default **off**).
3. **Not a default; granted person by person; the repository private** — a retailer installs it, a consultancy does
   not; **not** installed with `--grant-standing-departments` (a Viewer grant to Front Office is the admin's choice);
   `grant_standing_departments: false` in the manifest (K14's guard). Confirm, or make Front Office Viewers on install.
4. **Scope none; locations are records** — every role sees every location (selling from another store's shelf is the
   point); cost is the only wall; per-store reach is Extended on the kernel's `location` scope. Confirm.
5. **The catalog is Shopify's shape** — product → options → variants, each variant with SKU, barcode (GTIN-14
   normalized), MPN, dims, how it ships, and three prices; identifiers of eight kinds; **sizes seeded with synonyms**
   (§0.3), attributes declared in settings and seeded for mattresses; **bundles in v1** (a set = components, availability
   the minimum, never held as a bundle). Confirm, or drop bundles (saves half a day; a set becomes two lines).
6. **Matching** — identifiers first (GTIN, supplier SKU, MPN + size, marketplace id), then a person, then a proposal
   (the matcher's scoring or the Buyer agent's) that a person accepts; **never across sizes**; dismissals remembered; the
   match queue is the Buyer's daily work. Confirm, and whether the agent may accept **its own** proposals above a
   confidence (recommended: **no** — an agent matches only by identifier or a person's proposal).
7. **The connectors of version 1 and the crawl policy** — five without keys (`shopify`, `woocommerce`, `jsonld`,
   `feed` CSV/XLSX over HTTPS or SFTP, `manual`), three references with the owner's keys (`ebay`, `amazon`, `walmart`),
   and `inventory_feed` (another installation of this application); **the policy of §0.2** — robots honoured, one
   request a second per host, an honest user-agent, blocked means blocked, **never a login, a captcha or a proxy**
   (refused by name); Amazon is a reference only. Confirm the eight, or drop the three keyed references to Extended
   (saves a day; the interface stays).
8. **Pull cadence and snapshots** — a supplier source every **30 minutes** by default, a reference every **6 hours**, a
   `jsonld` site daily, `manual` never; a snapshot written when price, availability, quantity or lead time changed and
   **once a day regardless**; a listing unseen for two pulls is "removed" (the match kept); a blocked source backs off an
   hour, a day, then pauses for a person. Confirm.
9. **Ordering with a supplier by hand in v1** — the application drafts the purchase order (one per supplier per order
   at confirmation), a person sends it by email (MaluMail) or places it on the portal and records the reference; the
   supplier's **secure link** with three actions (acknowledge, decline a line, add tracking); API and EDI ordering
   Extended. Confirm, and that `supplier_sees_phone` defaults **on** for LTL and white-glove lines.
10. **The sales order and money** — a quote becomes an order on confirmation (allocating stock, drafting drop-ships);
    payment is **recorded** (deposit, balance, refund; method and reference) — **no processor, no invoice document**: the
    order's page is the customer's document, the secure link its door (180 days after close); the ledger reads
    `sales_closed` (G2) or imports the accounting files. Confirm, or ask for an invoice/receipt document in v1 (GL's
    invoice tables reused verbatim — a day).
11. **Prices** — retail, MAP and cost on every variant with history; cost from the feed, the last receipt, or typed (a
    setting, default **last receipt**); the morning note flags retail under MAP, cost moved > 5 %, a reference under our
    retail by > 10 % (settings). Confirm the defaults.
12. **Two shipped agents, hired on install** — the expert (the command bar) and the **Stock Buyer** (`30 6 * * *`: the
    seven-heading morning note, drafted reorders and drop-ships, proposals; to the Buyer you name — the first super-admin
    until then — and to `#inventory` in Spaces when installed). Confirm, and name the Buyer.
13. **What pauses for an agent** (§5): sending or placing a purchase order (`money_out`); the order confirmation and
    notices to a customer, a message to a supplier, a feed key (`external_send`); confirming an order, recording a
    payment or refund, authorizing a return (`other`, pause by default); deleting, cancelling, adjusting stock without a
    document, posting a count (`deletion`); prices, sources and their credentials, schedules, settings (`other`).
    Everything else an agent does alone. Confirm.
14. **Returns in v1** — a return authorization with reasons, pickup or drop-off, a disposition (restock, floor model,
    dispose, return to supplier, donate), the refund recorded; a vendor-return document Extended. Confirm.
15. **Kernel and sibling items** — K27 (the catalog seed, first — with the category widened to `inventory`), K28 (texts
    to customers, Extended), G2 (the ledger's reads — GL's side), HD3 and S1 (Extended) approved and built in their own
    repositories beside this application. Approve.
16. **Ports and the handoff** — `APP_INTERNAL_PORT=8188`, `MCP_RECORDS_PORT=8837`, `MCP_ACTIVITY_PORT=8838` pinned
    before `apply` (the block after Knowledge's 8187/8835/8836; proof scratch ports 8601–8607); and the **Spaces
    division**: a planning-class model builds K27, Phase 0's second half (the live survey included), Phase 1 for your
    approval, Phase 2, slices 1–2 and **the exemplar — slice 3 (sources, connectors, listings and matching)** with
    slice 4 (Find) beside it; a worker model (Sonnet 5.5) builds slices 5–9, Phase 4 and Phase 5 from the specs.
    Confirm, or hand the worker everything after slice 3.

## 14. Ports, names and files

- Repository `/srv/apps/inventory` (origin `github.com/maludb/maludb-os-inventory`, private); database
  `<tenant>_inventory` (`subello_inventory` here); roles `inventory_rw`, `inventory_records_ro`, `inventory_activity_ro`;
  MaluDB: the tenant's one memory, episodes tagged `inventory`.
- `inventory.<domain>` (`vhost.label = "inventory"`); `APP_INTERNAL_PORT`, `MCP_RECORDS_PORT`, `MCP_ACTIVITY_PORT` **all
  three in `env.required`** and written to `config/.env` before `apply` (§13.16); `deploy/` files are templates
  (`{{DOMAIN}}`, `{{APP_DIR}}`, `{{APP_KEY}}`, `{{APP_INTERNAL_PORT}}`, `{{MCP_RECORDS_PORT}}`, `{{MCP_ACTIVITY_PORT}}`).
- Units: `inventory-records-mcp`, `inventory-activity-mcp`, `inventory-activity-ingest` (+ timer, every minute),
  `inventory-directory-sync` (+ timer, every minute), `inventory-worker` (+ timer, every minute: due pulls by schedule
  and rate, snapshots and removals, the matcher on new listings, watches, the outbox, dispatches and their retries, link
  expiry, the heartbeat snapshots, key-usage pruning).
- `maludb-os.json`: `catalog_key inventory`, `name "Inventory"`, `business_area "Operations"`, `category inventory` (K27
  widened the check), `icon feather-package`, `criticality medium`, `vhost.label inventory`, no
  `scopes`, `sso`, `directory {reads: true, writes: false}`, `assistant {command_bar: true, agent: expert}`, `agents
  [expert, buyer]` each `hired_on_install: true`, `grant_standing_departments: false`, `approvals` (§5), endpoints (web,
  health, the customer door, the supplier door, the feed, records, activity), services (above), `shares` (the five of
  §8), `reads []`.
- Env: the contract's required keys plus `INV_SECRETS_KEY` (required — the installer generates it; source credentials
  are sealed under it), `MALUMAIL_API_KEY`, `MAIL_FROM`, `MAIL_FROM_NAME` (optional, written by the installer's `mail`
  step), `ATTACHMENT_MAX_BYTES`, `INV_PUBLIC_BASE_URL` (the doors' absolute links), `INV_CRAWL_USER_AGENT` (default
  from the settings), `INV_HTTP_PROXY` (an outbound proxy when the business has one — never a pool), `INV_BUYER_EMAIL` (the Buyer the morning
  note goes to — D12; seeds the setting at install). The marketplaces' keys, when those connectors are added, are source
  credentials — never env.
- Vhost allow-list (public): `/o/`, `/s/`, `/api/v1/availability`, `/api/v1/health`, `/mcp/records`, `/mcp/activity`.
- Kernel files: `mcp/registries/inventory.json` (made by the installer), db/172 (K27 — the catalog row, the category check widened with `inventory`).
- Proof scratch ports: 8601–8607. Fixtures: `tests/fixtures/sources/` — documented shapes, no third party's catalog
  committed beyond one trimmed page per connector taken in Phase 0's survey with the source named.

## 15. The owner's answers

Given 2026-10-05, the day the plan was written: four answered by name (7, 10, 12, 16), the rest "your recommendation".
Rules, not questions.

| # | Decision | Where it lives |
|---|---|---|
| D1 | **"Inventory", catalog key `inventory`**, repository `maludb-os-inventory`, clone and install path `/srv/apps/inventory`, DNS label `inventory`, database `<tenant>_inventory`, roles `inventory_rw` / `_records_ro` / `_activity_ro`, SQL prefix `inv_` | `maludb-os.json`; §14 |
| D2 | **Five roles**: Viewer (`viewer`, read, never cost), Sales (`user`), Warehouse, Buyer, Inventory admin (is_admin); **cost and margin are Buyer's and admin's**, Warehouse on receipts, Sales only when `sales_sees_cost` is on (default **off**) | §3; `inv_roles`; `inv_sees_cost()` |
| D3 | **Not a default; granted person by person; the repository private**; `grant_standing_departments: false` | §10; `maludb-os.json`; the org |
| D4 | **Scope none; locations are records**; every role sees every location; cost the only wall; per-store reach Extended | §3; `locations.kernel_location_id` |
| D5 | **The catalog is Shopify's shape** with identifiers of eight kinds, sizes seeded with synonyms, attributes in settings; **bundles in v1** | §6; `products`, `product_variants`, `bundle_components` |
| D6 | **Matching**: GTIN → supplier SKU → MPN + size → marketplace id → a person → an accepted proposal; never across sizes; dismissals remembered; **an agent never accepts its own proposal** | §6.2; `match_proposals` |
| D7 | **The minimum set of connectors — five, no keys**: `shopify`, `woocommerce`, `jsonld`, `feed`, `manual`; **the keyed references (`ebay`, `amazon`, `walmart`) and the `inventory_feed` client are Extended**, each a class and a fixture on the shipped interface when wanted (the owner: "a minimal number of connectors, we can add later"); the crawl policy of §0.2 — never a login, a captcha or a proxy | §0.2; §6.1; §11; slice 3 |
| D8 | **Cadence and snapshots**: supplier sources every 30 minutes, references every 6 hours, `jsonld` daily, `manual` never; a snapshot on change and once a day; removed after two unseen pulls, the match kept; back-off an hour, a day, then paused | §6; `sources.schedule_minutes`; the worker |
| D9 | **Ordering by hand in v1**: a drafted PO per supplier at confirmation, sent or placed by a person; the supplier's secure link with three actions; `supplier_sees_phone` on for `ltl` and `white_glove`; API and EDI Extended | §4; §6; slice 6 |
| D10 | **No processor, no invoice document** (agreed by name): payments recorded; the order page and its link are the customer's document; the ledger reads `sales_closed` or imports the files | §4; §8; `order_payments` |
| D11 | **Prices**: retail, MAP, cost with history; cost from the **last receipt** by default; thresholds 5 % cost move, 10 % reference undercut, retail under MAP — settings | §6; `inv_settings` |
| D12 | **Two agents hired on install** — the expert and the Stock Buyer (06:30); **the Buyer is set in the configuration**: `INV_BUYER_EMAIL` in `config/.env` seeds `inv_settings.buyer_member_id` at install, the settings screen changes it; the first super-admin until set | §5; §14; `inv_settings` |
| D13 | **What pauses**: `money_out` for sending or placing a PO; `external_send` for reaching a customer or supplier and feed keys; `other` (pause by default) for confirming an order, recording money, authorizing a return; `deletion` for deleting, cancelling, undocumented stock changes, posting a count; `other` for prices, sources, credentials, schedules, settings | §5; `approvals[]` |
| D14 | **Returns in v1** with reasons, pickup or drop-off, five dispositions, the refund recorded; a vendor-return document Extended | §6; slice 8 |
| D15 | **K27 first** (the catalog seed, the category check widened with `inventory`), **K28** (texts to non-members, Extended), **G2** (the ledger's reads — GL's side), **HD3** and **S1** (Extended), each in its own repository | §12 |
| D16 | **Ports `APP_INTERNAL_PORT=8188`, `MCP_RECORDS_PORT=8837`, `MCP_ACTIVITY_PORT=8838`** pinned before `apply`, scratch 8601–8607; **the Spaces division** (agreed by name): the planning model builds K27, Phase 0's second half with the live survey, Phase 1 for approval, Phase 2, slices 1–2, **the exemplar slice 3 (sources, connectors, listings, matching)** and slice 4 (Find); Sonnet 5.5 builds slices 5–9, Phase 4 and Phase 5 | §14; `CLAUDE.md` "Build order and the handoff" |

## 16. State

**2026-10-10 — SLICE 4, FIND, AVAILABILITY AND WATCHES — BUILT and proven** by the planning model — **THE HANDOFF POINT: the exemplar built;
slices 5–9 open to workers.** `tests/phase3/slice4/run.sh` 305 checks green under php -S and 306 under Apache, against the fixture server (Find by
name, SKU, GTIN, MPN and brand with every chip, the 50 cap, the push URL, cost on a card only for the wall, "Sell this" with the recommended
fulfilment; the availability partial in full and compact — the order form's picker — with ranking, stale, removed, the bundle and `sales_sees_cost`;
the promise line and the pick list; "Ask the sources now" fanning out in the browser, each card one live search with `offerChanged`, paused and
blocked sources in words, the host's one-second spacing; watches set, refused in words, listed, cleared and FIRED once per state change by the
worker — in-app, email, a K6 text row, an agent's dispatch — and the heartbeat pass; the bridge's loopback + HMAC + ±30 s gate, its wall, the
`mcp` / `agent` rows and an eval run that writes nothing; JSON mode; the browser at 375 and 1280 and without JavaScript); 56 screens and 65 actions
built. **Found and fixed:** the live search now logs `source.search` itself (one row per ask, whoever asks); `inv_guard()` gives a JSON caller the
`record_id` a refusal points at; the kit's `one_row()` (a boolean false read through `one_value()` is "no row"); `with_back()` puts the way back
before a fragment; a walled source answers `blocked`, not "backing off". Recorded: `lead_time_over` reads the listing's own lead time, not the
supplier's default. The record and the decisions: `docs/build-specs/find.md` "Built and proven". **Next: slices 5–9 by Sonnet 5.5 workers, one at a
time on the exemplar's pattern, then Phase 4 and Phase 5.**

**2026-10-09 — SLICE 3, SOURCES, CONNECTORS, LISTINGS AND MATCHING — THE EXEMPLAR — BUILT and proven** by the planning model:
`tests/phase3/slice3/run.sh` 290 checks green under php -S and under Apache, against the fixture server (six sources made through the handlers and one
from a template; credentials sealed, rotated and in no view, log or policy; probes ok / blocked / misconfigured with the ladder; the worker's pulls
pass live — Shopify by GTIN, the dealer feed by supplier SKU with the price sheet kept by the feed, the marked-up site by MPN + size, the Woo store by a
marketplace id, the manual sheet; snapshots on change only, the ETag cache, a price change written once; Pull now queued; a live search; two full pulls
remove, a partial removes nothing, blocked × 3 pauses with a notice per rung; the agent's match rule; the queue with Accept, Dismiss, Pick another,
Score again, Not ours; the listing view's chart with bands and a heartbeat; price sheets; health; the survey's `--record`; JSON mode and an eval run
that persists nothing; the browser at 1280 and 375 and without JavaScript); 54 screens and 63 actions built. **Found and fixed:** `db/019` — an ok
probe or search no longer moves `last_ok_at` (a probed source waited a whole schedule for its first pull); the Buyer's notice is keyed per rung of a
STREAK (the spec's key silenced every later streak); a blocked probe tells the Buyer; `view()`'s `$template` collides with a data key of that name; one
`availability_chip()`. The record and the decisions: `docs/build-specs/sources.md` "Built and proven". **Next: slice 4 (Find, availability, watches),
then the handoff to workers (slices 5–9).**

**2026-10-09 — SLICE 2, LOCATIONS AND STOCK (the ledger's screens), BUILT and proven** by the planning model: `tests/phase3/slice2/run.sh` 325 checks
green under php -S and under Apache (locations as cards with the archive rule; receipts scanned on a phone — the scan rule, GTIN / UPC / SKU, put-away,
the cost following the receipt, a PO received; adjustments with reasons and the cost wall; transfers sent and received short with the difference left on
the document; counts with the running count and the schema's "counted − on hand now"; floor models as `floor_model` adjustments; reversals once; levels
with filters and CSV; the movement ledger with its documents; JSON mode under action and run tokens; the browser at 375 and 1280 and without
JavaScript); 43 screens and 45 actions built. **Found and fixed:** `db/018` — `inv_transfer_receive()` refused every transfer of two lines or more (the
writer flag fell after the first posting); the kit's `db_message()` now speaks a ledger CHECK's sentence instead of its raw text; the sticky scan field
needed the theme's `.main-content` clip lifted. The record and the decisions: `docs/build-specs/stock.md` "Built and proven". **Next: slice 3 (sources,
connectors, listings, matching — THE EXEMPLAR) with slice 4 (Find) — then the handoff to workers.**

**2026-10-09 — SLICE 1, THE CATALOG (the CRUD exemplar for workers), BUILT and proven** by the planning model: `tests/phase3/slice1/run.sh` 310 checks
green under php -S and under Apache (brands and types, products with typed attributes and options, variants with GTIN-14 / size keys / imperial units /
prices on the create, identifiers, bundles with replace semantics, images through the gated door, prices with reasons and the inline-SVG chart, the gaps,
the CSV import in three steps, the cost wall for every role, JSON mode under action and run tokens, the browser at 375 and 1280 and without JavaScript);
21 screens and 20 actions built. The record picker (plugin 0.8.0) came with it. Kit fixes found on the way: `inv_guard()` now turns a trigger's
`check_violation` into the 422 it means; the normalizer's `inv_int()` renamed `inv_norm_int()`. The record and the decisions: `docs/build-specs/catalog.md`
"Built and proven". **Next: slice 2 (locations and stock), then slice 3 the exemplar with slice 4 — the handoff.**

**2026-10-09 — PHASE 1 APPROVED by the owner ("Push it and start Phase 2"); PHASE 2 THE SHELL BUILT and proven the same day.** `tests/phase2/run.sh`:
**327 checks green under php -S, 330 under a real Apache** (sso 63, gates 104, sync 33, ingest 13, kernel_compat 16, vhost 30/33, browser 68 at 375 × 740
and 1280 × 800 and with JavaScript off; the registry — 5 screens and 4 actions built, 38 placeholders — and the 26 approvals in step); Phase 0 re-run green
against the shell (42 + 517); the Phase 1 claim checks green (52); the installer's `plan` clean (57 steps, 9 notes). What exists: a person lands signed
in on a home whose regions name their slices and whose Unread card is real; the sidebar of `nav_groups()` (nine groups, every item gated by a right, the
Admin group for its holders), the bottom tab bar Home · Find · Orders · Stock · Me, the header with the business name, the badge of the highest role,
the bell with its count and the application switcher (K31); the command bar through the kernel's chat endpoint (a reply, a paused action, a navigate,
the kernel down — all in the bar); My settings (how I am told — the thirteen kinds of db/013 —, who I am here, the time zone read-only), Notifications
(mark one, mark all, the kind chips, a link to the record through `record_url()`), Tokens (minted and shown once in a warning box, revoked with a
confirm), My trail (own rows in words, a record's by `source` / `order` / `purchase_order` / `product` / `variant`, another person's by `member` for the
admin or `reports.read`); the gated attachment door and `app/attachments.php`; 38 placeholders each 200 after its right, 403 in the right's words, 501
to JSON and a POST. The record is `docs/build-specs/sso-shell.md` "Built and proven" — with the decisions taken (the switcher in, the record picker
slice 1's, the menu counts as the table gives them, `/products/new` a 404 until slice 1, the command bar swapping refusals) and **one kit defect fixed:
a hand-off blanked the job title, phone and time zone the feed had delivered** (`mirror_apply_member()` now keeps a stored value when the row does
not carry the key; Spaces and GL have the same lines). The repository was pushed to GitHub the same day (the five earlier commits). **Next: slice 1
(the catalog — the CRUD pattern, with the record picker), slice 2 (locations and stock), then the exemplar (slice 3) with Find (slice 4) — the handoff.**

**PHASE 0, SECOND HALF — BUILT and proven 2026-10-05**, by four builders in parallel on the planning model and every proof re-run by the
lead. **The schema** `db/001`–`015` (the kernel contract copied from Knowledge's shape with Inventory's appended audit keys; the five
roles and thirty rights; settings with the sizes and their synonyms, sequences and tax rates on GL's shapes; attachments and notes;
the catalog with GTIN-14 normalization, `size_key` by trigger and price history; locations and the transaction ledger with balances
by trigger, receipts, transfers, adjustments, counts and reversals; suppliers on the Cidery's shape, sources with sealed credentials,
pulls with the back-off ladder, listings, offer snapshots on change plus the daily heartbeat, the matcher's six rules, watches;
customers on GL's shape, orders with per-line fulfilment, confirmation allocating stock and drafting one purchase order per supplier,
shipments, payments recorded, the customer's link; purchasing with the supplier's events and link; returns with dispositions;
notifications, dispatches, feed keys with partner price lists and key usage; every read function of §6; 62 `mcp_*` views with cost
nulled by `inv_sees_cost()` and credentials never shown — 69 tables) and `db/proof/phase0_proof.sql`: **422 checks green** on a scratch
database (rights 20, settings 25, catalog 28, suppliers and sources 18, the ledger 50, pulls and snapshots 61, availability and ATP 26,
orders 34, purchasing and the supplier door 33, shipping 15, returns 14, watches 17, the Buyer's lists 24, the feed 20, views and the
read roles 36). **The kit** copied from Spaces (`app/`, `html/`, `bin/`, `tests/`, `deploy/`, `mcp/` common files; `sp_` → `inv_`; the
gates rewritten to rights with cost as the wall; a worker skeleton with its passes named) proven without a kernel by
`tests/phase0/run.sh`: **42 checks green** (the fixture of eight members, roles from `access[]`, a hand-off, replay, audience, unknown
member, no grant, tampering, the refusals logged, the sign-out notice, the worker's pass). **The connectors** (`app/sources/`: the
interface, the one HTTP client with the crawl policy — robots, one request a second per host, an honest user-agent, ETag caching, login
redirects and bot walls as `blocked` —, credentials sealed with libsodium, the normalizer with the seven availability states and the
twelve sizes, and the five of D7: `shopify` with the optional Storefront token, `woocommerce`, `jsonld` over sitemaps, `feed` CSV and
XLSX over HTTPS or SFTP with a column mapping and a preview, `manual`) proven against fixtures by `tests/phase0/connectors.sh`: **511
checks green**. `maludb-os.json` (seven endpoints, five shares, two agents hired on install, 26 approvals, `grant_standing_departments:
false`), `os/{expert,buyer}.md`, ten skills (five runbooks), `deploy/` templates and `ROOT_STEPS.sh` (the port pin, `INV_SECRETS_KEY`
generated, `INV_BUYER_EMAIL`); **the installer's `plan` reads the repository clean** (36 steps, 10 notes; the catalog row K27 found). The
live survey ran (§0.2): thirteen Shopify brands throttle `products.json` for a non-browser agent, every one has an open sitemap, Layla's
Store API is open.

**PHASE 1 — written 2026-10-05, for the checkpoint.** The tool surface (`docs/inventory-mcp-tool-surface.md`: **83 records tools** — the 68
§7 names, `app_roles`, `records_search`, the five shares, six resolvers, three support tools — and **7 activity tools**; every question of §7
named to a tool, verified by script; the resolve table of 14 entities; the log-payload rules; the five share documents and the feed's),
the action manifest (`docs/inventory-action-manifest.md`: **108 screens, 132 actions**; the 26 approval categories of §5 — 2 `money_out`,
4 `external_send`, 11 `other`, 9 `deletion` — in sync with `maludb-os.json` by `bin/sync_approvals.php --check`; the registry
`mcp/action_registry.json` built clean, read by the installer's plan, which now lists the log events it would pause), the connector
spec (`docs/build-specs/connectors.md`: the interface as built, the crawl policy as enforced, the five connectors field by field, the
feed's mapping-screen contract, credentials, the worker glue for slice 3, the checklist for a later connector, the survey), and **ten
slice specs** in `docs/build-specs/`: `sso-shell` (Phase 2), `catalog` (1, the CRUD exemplar for workers), `stock` (2), **`sources` (3,
THE EXEMPLAR)**, `find` (4), `orders` (5, with the customer's door), `purchasing` (6, with the supplier's door), `feed` (7),
`returns-worker` (8), `reports-admin` (9). **Every manifest row is claimed by exactly one spec** (`tests/phase1/check_specs.sh`, 52
checks); every "Open questions" section is empty; `docs/phase1-consistency.md` records the fourteen reconciliations (the live search is
Sales' right through the HMAC bridge; `image_add` takes an existing image; one floor-model event; the removal window counts full pulls
only; the worker's passes by the data-owner rule; attachments are Phase 2's with one set of signatures; the share-read row as built).
What Phase 1 found in the schema became two additive migrations, proven: **db/016** (the five K7 share functions, `inv_fulfilment_today`,
`inv_sales_summary`, the robots fact as a map, the removal window, the page-cap default, the template seeds' keys) and **db/017**
(`listing_forget`, a SECURITY DEFINER `inv_log_share_read()` for the read role, two settings columns on the view) — the schema proof
now **517 checks**, the kit 42, the connectors 511, the installer's plan clean with 17 migrations. **AWAITING THE OWNER'S CHECKPOINT on
Phase 1 as a whole** — then Phase 2 (`sso-shell`), slices 1–2, the exemplar (3) with Find (4), the handoff. Owner-facing items that
are not defects (`docs/phase1-consistency.md`): `source_update` is uncategorised by §5's letter though CLAUDE.md implies `other` (a 27th
approval if wanted); the hire script grants a shipped agent by capability, so the super-admin widens the two agents to Buyer after the
hire; approval policies match log events installation-wide.

**Found on the way** (recorded, not fixed here): the kernel's `application_endpoints.auth_kind` admits no `token` — the two doors are
declared `none` with the token in the path and the feed `api_key`; **Spaces' manifest declares `token` and will be refused at its
`apply`** (told to the owner); the installer takes an approval's log event from the registry, so the 26 approvals bind only once Phase 1's
`mcp/action_registry.json` exists; `bin/hire_application_agent.php` grants a shipped agent by capability (`write` → Sales), so the
super-admin widens the two agents to Buyer after the hire, and sets the Buyer's duty in Agent HR (as the siblings); an SFTP feed with a
password needs `php-ssh2` or an sftp-capable curl on the server (a key works today); the PHP and SQL `size_key` agree on every seeded
size, and an unknown word is a slug in SQL and `NULL` in PHP (one side unknown never blocks a match). Decisions the builders took are
marked `-- DECISION:` / `// DECISION:` in the files. **Next: Phase 1** — the tool surface, the action manifest, the registry, the
connector spec and the slice specs, for the owner's checkpoint.


**2026-10-05 — the sixteen decisions given (§15, D1–D16): the minimum set of connectors (D7), no invoice document (D10), the Buyer
set in the configuration (D12), the Spaces division (D16), every other recommendation taken. K27 built in the kernel as db/172 (the
`inventory` catalog row under Operations, the category check widened with `inventory`). Next: Phase 0's second half — the live
survey of the candidate stores from the build server, the schema and its proof, the five connectors' fixtures, the kit,
`maludb-os.json`, the agents, the skills, `deploy/`.**

**2026-10-05 — the plan written; awaiting the owner's sixteen decisions (§13).** The drop-ship trade and the mattress case
researched (§0.1); the places inventory lives on other websites surveyed connector by connector (§0.2) — Shopify's public
`products.json`, WooCommerce's Store API, schema.org JSON-LD, supplier feeds, eBay's Browse API, Amazon's PA-API 5.0 (its
2025 eligibility rule noted), Walmart's affiliate API, and this application's own feed as a source for another
installation; EDI and the drop-ship networks placed in Extended. The live probe of the brand sites could not run from the
planning sandbox (its outbound fetches are rate-limited) and is Phase 0's first proof. The repository
`maludb/maludb-os-inventory` created in the org (private) and cloned to `/srv/apps/inventory`; this document, `CLAUDE.md`
and `README.md` committed. **Then: the owner's answers (above).**

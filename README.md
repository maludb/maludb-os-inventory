# maludb-os-inventory — Inventory

MaluDB Business OS application: **Inventory** — sales inventory for a retailer of packaged goods who **holds some stock and
drop-ships the rest**. Modelled on mattress retail (a showroom with floor models, a small warehouse, a dozen brands sold
through dealer and drop-ship programs, the same model sold by the manufacturer's site and the marketplaces, prices that move
weekly, sizes that make one mattress six SKUs); general to anything sold by the unit.

- **A catalog** in Shopify's and GS1's words — a product, its options (Size first), its variants, each with a SKU, a GTIN, an
  MPN and any identifier a seller uses (ASIN, eBay EPID, Walmart item id, a supplier's SKU); bundles (a mattress set);
  retail, MAP and cost with history.
- **Own stock by location** — a transaction ledger (receipts, issues, transfers, adjustments, counts, returns) maintaining
  balances by trigger; floor models; allocation at the sale, issue at shipment.
- **Sources** — where the business can sell from without holding anything: other websites read politely (a Shopify store's
  public `products.json`, a WooCommerce store's Store API, any site's schema.org product markup), suppliers' inventory
  feeds (CSV or XLSX over HTTPS or SFTP, mapped once), and price sheets typed in — five connectors in version 1 on one interface, so the marketplaces as a reference (eBay's
  Browse API, Amazon's PA-API, Walmart's affiliate API) and **another installation of this application** (through the availability
  feed every installation publishes) are each one class and one fixture to add. Every pull writes what changed as a **snapshot**, so "when did it go out
  of stock and what did it cost that week" is a question with an answer. Listings are **matched** to the catalog by
  identifier, by a person, or by a proposal a person accepts — never across sizes.
- **Find** — one screen, one query: own stock by location, every supplier's offer ranked by cost and lead time, the reference
  prices, the business's own prices, the best lead time; "ask the sources now" for the connectors that search live.
- **Orders** — a quote becomes an order; each line is filled **from a location or drop-shipped from a source's offer**; a
  drop-ship drafts a purchase order addressed to the customer; the supplier acknowledges and adds tracking through a secure
  link; the customer watches the order through theirs; payments are recorded, never processed; returns with dispositions.
- **Two shipped agents** — the **expert** (the command bar: "can we sell a Queen ProAdapt and who ships it fastest") and the
  **Stock Buyer** (a morning note: sold lines at risk, reorders drafted, prices moved and MAP breached, listings unmatched with
  proposals, sources failing, purchase orders unacknowledged, returns waiting). Agents propose; **placing an order with a
  supplier pauses** for a person.
- **The crawl policy**: public endpoints and marked-up pages only, `robots.txt` honoured, one request a second per host, an
  honest user-agent, blocked means blocked — never a login, a captcha or a proxy pool.

An optional application installed beside [maludb-os-core](https://github.com/maludb/maludb-os-core) on request; the ledger
reads its sales, purchases and stock value through K7. Built with `htmx-php-builder`, fitted to `maludb-os-integration`
0.7.0, served as `inventory.<domain>`; catalog key `inventory`.

- **The plan:** `docs/inventory-design.md` (Phase 0, 2026-10-05 — **approved**; the owner's sixteen decisions are §15, D1–D16).
- **The build:** `CLAUDE.md` — the order, the rules, the handoff to the worker model.
- Status: **Phase 0 complete 2026-10-05** — the schema (db/001–015, 69 tables, 422 proof checks), the kit (42 checks), the five connectors
  with their fixtures (511 checks), `maludb-os.json`, the two agents' job descriptions, ten skills, the deploy templates; the kernel's installer plan reads the repository clean; the live survey of fourteen brand sites recorded (design §0.2, §16). **Phase 1
  written 2026-10-05** — the tool surface (83 records + 7 activity tools), the action manifest (108 screens, 132 actions, 26 approvals in
  sync), the registry, the connector spec, ten slice specs with every manifest row claimed once (52 checks), db/016–017 (the schema proof at
  517) — **approved by the owner 2026-10-09.** **Phase 2 — the shell — built and proven 2026-10-09**: sign-on into a finished-looking home, the
  menu of nine groups gated by rights, the tab bar, the bell, the command bar through the kernel, My settings, Notifications, Tokens, My trail, the
  attachment door, 38 placeholders naming their slices; `tests/phase2/run.sh` 327 checks green under php -S and 330 under Apache; the installer's plan
  clean (57 steps). **Slice 1 — the catalog — built and proven 2026-10-09** (310 checks under php -S and Apache; brands, products, variants, identifiers, bundles, images, prices with the chart, gaps, the CSV import, the record picker). **Slice 2 — locations and stock — built and proven 2026-10-09** (325 checks under php -S and Apache; locations, receipts scanned on a phone, adjustments, transfers, counts, floor models, reversals, levels and the movement ledger; `db/018` fixes the multi-line transfer receive). **Slice 3 — sources, connectors, listings and matching (THE EXEMPLAR) — built and proven 2026-10-09** (290 checks under php -S and Apache against the fixture server; the worker's pulls pass live; `db/019` keeps a probe from counting as a pull). **Slice 4 — Find, availability and watches — built and proven 2026-10-10** (305 checks under php -S, 306 under Apache; Find with its chips and cards, the availability partial and the order form's picker, the promise line, live asks of the sources from the browser, watches fired once per state change by the worker, the records server's bridge). **The handoff point: slices 5–9 go to workers.** **Slice 5 — customers and sales orders — built and proven 2026-10-10 by a worker** (308 checks under php -S, 308 under Apache; customers, the order form on slice 4's picker, confirm / send / pay / ship / deliver / close / cancel, fulfilment today, the shipments, and the customer's door `/o/<token>`; the worker's `links_expire` pass live; no migration). **Slice 6 — purchasing: suppliers, purchase orders and the supplier's door — built and proven 2026-10-10 by a worker** (343 checks under php -S and Apache; suppliers, stock and drop-ship purchase orders, send / place / acknowledge / decline / tracking / receive / close / cancel, and the supplier's door `/s/<token>`; `db/020` frees the customer's lines of a cancelled drop-ship purchase order). **Slice 7 — the availability feed: keys, price lists, connections and the public API — built and proven 2026-10-10 by a worker** (225 checks under php -S and Apache; `GET /api/v1/availability` with a key — the retail price, the state, the best lead time, how it ships, a partner's price —, 60 a minute and 10,000 a day, keys shown once, rotated with a 24-hour overlap and revoked, the price lists, the Connections page, the five shares' readers; `db/021` gives a bundle its components' state). Next: slice 8.

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
  clean (57 steps). Next: slices 1–4 by the planning model, then the handoff.

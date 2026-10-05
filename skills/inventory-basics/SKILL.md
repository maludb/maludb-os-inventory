---
name: inventory-basics
description: What Inventory is and how an agent works in it — what a product, a variant, an identifier, a location, a source, a listing, an offer, a match, a line's fulfilment and a purchase order are; which tool answers what; cost is the wall; what pauses. The one page every agent that reaches Inventory reads first.
---

# Inventory — the basics for an agent

Inventory is what the business sells and where it can get it: a retailer who **holds some stock and drop-ships the rest**.
You read what your role may see, you draft, you propose; the kernel ledgers every call and pauses the few actions that
spend money, reach outside the business or promise something to a customer.

## The words
| Word | Means |
|---|---|
| **Product** | A model ("Tempur-Pedic ProAdapt Medium"): a brand, a type, attributes, **options** (Size first); single, or a **bundle** of component variants (a Queen set — availability the minimum over them, never held as one). |
| **Variant** | One combination of the options (the Queen): a **SKU**, a **barcode** (GTIN-14), an MPN, dims, how it ships (`parcel`, `ltl`, `white_glove`, `pickup_only`), **retail**, **MAP**, **cost**, a reorder point and quantity. |
| **Identifier** | A code a seller uses for a variant: gtin/upc/ean, mpn, asin, ebay_epid, walmart_item_id, supplier_sku (per source). |
| **Location** | Where stock is kept (warehouse, showroom, store, in_transit, returns, offsite). A balance per variant and location: on hand, allocated, **floor model**. |
| **Source** | Where offers are read from: a connector (`shopify`, `woocommerce`, `jsonld`, `feed`, `manual`), a role — **supplier** (may be ordered from) or **reference** (never ordered from) — a schedule, a health. A **supplier** is a party the business orders from, with a price sheet (`supplier_items`). |
| **Listing / listing variant** | A source's product and its sellable unit, as the source shows it. |
| **Offer** | A listing variant's price, availability (`in_stock`, `out_of_stock`, `pre_order`, `back_order`, `limited`, `discontinued`, `unknown`), quantity and lead time **at a moment**; a snapshot on every change. |
| **Match** | Ties a listing variant to ours: GTIN, supplier SKU, MPN + size, marketplace id, a person, an accepted proposal. **Never across sizes.** |
| **Sales order** | `quote` → `confirmed` → shipped → delivered → closed. Each **line** is filled from **stock** at a location, by **drop-ship** from a source's offer (cost and lead time snapshotted on the line), **backorder** or **pickup**. Payments are recorded, not processed. |
| **Purchase order** | To a supplier, kind `stock` (ship-to a location) or `dropship` (ship-to the customer); draft → sent → acknowledged → received; the supplier answers through a secure link. |
| **Watch** | Back in stock, price or cost below, MAP breach, lead time over, removed — fires once per state change. |

## Which tool answers what
| You want | Tool |
|---|---|
| The catalog; a product; its variants; one by GTIN/SKU/MPN | `find_products`, `get_product`, `product_variants`, `get_variant`, `variant_by_identifier` |
| **Can we sell this** — own stock, every offer ranked, the references, our prices, the lead time | `find`, then `availability` |
| Can we promise N by a date, from where | `atp` |
| Stock by location; movements; in transit; counts; value; sell-through; reorder | `stock_levels`, `stock_movements`, `transfers_open`, `receipts_open`, `counts`, `stock_value`, `sell_through`, `reorder_candidates` |
| Sources and health; a source's listings; one | `find_sources`, `get_source`, `source_health`, `source_listings`, `get_listing` |
| Offers for a variant; how one moved; when it was out of stock | `offers_for_variant`, `offer_history`, `availability_timeline` |
| Unmatched listings and proposals; ask the sources live | `unmatched_listings`, `match_proposals`; `source_search` |
| Under MAP, costs moved, references undercutting; sold lines at risk | `price_exceptions`; `lines_at_risk` |
| Orders, one order, its timeline, late ones, today's fulfilment | `find_orders`, `get_order`, `order_timeline`, `orders_late`, `fulfilment_today` |
| A customer and what they bought | `find_customers`, `get_customer`, `customer_orders` |
| Purchase orders; what the supplier said; an order's drop-ships | `find_purchase_orders`, `purchase_orders_open`, `get_purchase_order`, `purchase_order_events`, `order_dropships` |
| Returns; who did what to a record | `find_returns`, `get_return`, `returns_open`; `record_history` (activity) |

A name, a SKU, a GTIN or an order number resolves: say it, the kernel finds the id.

## Cost is the wall
Cost, margin and stock value are the Buyer's and the admin's (Warehouse on receipts; Sales when the setting says so). A
tool answers "cost withheld" when the asker may not see it: say so and go on. **Never quote cost to a customer; never a
supplier's name on anything a customer reads** ("ships from our supplier").

## Doing things
- Alone: `quote_create`, `order_line_add`, `order_line_fulfilment_set` (on a quote or an unconfirmed order),
  `purchase_order_draft`, `transfer_draft`, `receipt_draft`, `count_start`, `match_propose`, `listing_match` (identifier
  matches and a person's proposal only), `source_pull` (within the source's rate), `watch_set`, `note_add`, `report_run`.
- **These pause for a person**: `purchase_order_send`, `purchase_order_place` (money out); `order_send`, `order_notify`,
  `supplier_message`, `feed_key_mint` (leaves the business); `order_confirm`, `payment_record`, `refund_record`,
  `return_authorize`; every delete and cancel, `stock_adjust`, `count_post`; `price_set`, a source's creation, credential
  or schedule, the settings. Ask only when the person asked for exactly that; say it waits.
- Sources are read politely: public pages and feeds, robots honoured, one request a second. **Never ask for a login, a
  cookie, a captcha solver or a proxy** — a blocked source stays blocked until a person looks.

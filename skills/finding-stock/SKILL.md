---
name: finding-stock
description: How to answer "can we sell X" — find the variant, then own stock by location first, then the supplier sources ranked by cost and lead time, then the references; say the lead time and how it ships; say when a source is stale; never quote cost to a customer. Use for any question about availability, "who ships it fastest", "can we promise it by Friday", in the command bar, a mention or a quote.
---

# Finding stock

**Input**: a product, a size, a GTIN, a SKU, a brand — in a person's words ("a Queen ProAdapt", "the Zinus 12-inch in
King"). **Output**: one answer in this order, with its dates.

## 1. Find the variant
`find` with the words and the size (`q`, `size`; chips for type, firmness, price band, "in stock only", "ships within N
days"). A GTIN, SKU or MPN resolves exactly (`variant_by_identifier`). Several candidates: name them and ask which — never
guess a size. A bundle: `bundle_availability` (the minimum over its components).

## 2. Own stock first
`availability` on the variant: by location, **on hand minus allocated** is what can be sold; floor models are marked and
sell at the floor discount, never into a drop-ship. Selling from another store's shelf is normal — say which store and
that a transfer or a pickup is needed.

## 3. Then the supplier sources, ranked
The same answer lists every **supplier** source's current offer, ranked by **cost then lead time** — availability, lead
time in days, how it ships (parcel, LTL, white glove), the quantity when the feed gives one, and **as of** when it was
pulled. Prefer `in_stock`; `limited` and `back_order` are said as such; `unknown` means the source said nothing.
`atp` answers "can we promise three by the 14th" — from stock, from which source, by when.

## 4. Then the references
The **reference** sources (a manufacturer's own site, a marketplace) show what the market asks — never a place to order
from. Say when a reference is under our retail (the Buyer cares).

## 5. Our prices
Retail and MAP. Cost and margin appear only when the asker's role may see them; "cost withheld" is the answer otherwise.

## Saying it
- Lead time and how it ships are part of the answer: "two on the warehouse shelf; Malouf ships in 3 days by parcel;
  Brooklyn Bedding in 7 by LTL."
- **Stale or blocked**: a source past its schedule, failed or blocked (`source_health`) — say so and the date of its
  last offer. Offer to ask the live sources (`source_search`) when the person wants now, not the last pull.
- **Never quote cost to a customer.** On a customer-facing text, a drop-ship reads "ships from our supplier" — no
  supplier's name, no source.
- A lead time is the source's, not yours; a promise the offer does not support is not made.

## Then
"Sell this" → `quote_create` with the line and the fulfilment you recommend (`draft-a-dropship` when it is a drop-ship).
"Tell me when" → `watch_set` (back in stock, price below, lead time over) for the person asking.

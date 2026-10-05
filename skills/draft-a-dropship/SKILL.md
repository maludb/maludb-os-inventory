---
name: draft-a-dropship
description: Runbook for selling a line the business does not hold — find the variant, pick the supplier offer by cost and lead time, put the line on a quote as a drop-ship against that offer, and draft the purchase order addressed to the customer; what pauses (confirming, sending). Use for "sell this as a drop-ship", "draft a PO for this line", or when a sold line's source went out of stock.
kind: runbook
---

# Draft a drop-ship

**Input**: a variant (or a person's words for one), a customer (existing or new), the delivery address, a quantity.
**Output**: a quote with a drop-ship line against a named offer, and a drafted purchase order per supplier — nothing
confirmed, nothing sent.

## 1. The variant and the offer
1. `find` with the words and the size; `get_variant` to be sure of the size. Never guess a size.
2. `availability` on it. **Own stock first**: when a sellable location has it, say so — a drop-ship is for what the shelf
   does not hold (or the customer's choice).
3. Among the **supplier** offers, pick by **cost, then lead time**, `in_stock` only (`limited` and `back_order` are said,
   not chosen without the person's word). Read the lead time and how it ships; `atp` when a date was promised.
4. A stale or blocked source's offer is not sold against; say so and pick the next, or ask the live sources
   (`source_search`).

## 2. The quote and the line
1. `quote_create` — the customer (`find_customers`; a new one is a person's `customer_save` from the screen when the
   details are not yours to type), the selling location, delivery method and ship-to, the promised date from the lead
   time (plus the business's handling days from the settings).
2. `order_line_add` — the variant, quantity, retail (never under MAP in writing). `order_line_fulfilment_set` —
   `dropship`, the source and the listing variant of the offer; the line **snapshots** the offer's cost and lead time.
3. Say to the person: the line, the supplier chosen and why (cost, lead time, ships how), the promised date — **cost
   only to them, never in anything the customer reads**.

## 3. The purchase order
`purchase_order_draft` — kind `dropship`, the supplier, **ship-to the customer's** address from the order (phone when the
setting allows for how it ships), one line against the same offer, the supplier's SKU, the cost, the expected date. One
draft per supplier per order. Say its number. Confirming the order drafts it too — do not double: `order_dropships`
first.

## 4. What pauses — say it, do not do it
- `order_confirm` — the business's promise to deliver (it allocates stock lines and drafts the drop-ship POs).
- `order_send` — the confirmation and link to the customer.
- `purchase_order_send` / `purchase_order_place` — **money out** to the supplier.
Each pauses for a person; ask only when the person asked for exactly that; say it waits for approval.

## 5. Afterwards
The supplier acknowledges and adds tracking through their link; a drop-ship line is shipped when the tracking arrives.
`watch_set` on the offer (`removed`, `lead_time_over`) when the person wants to know if it moves before the PO is sent.

## Never
A supplier's name on the customer's text. A lead time the offer does not give. A line against a reference source. A
second PO for a supplier that already has one on the order.

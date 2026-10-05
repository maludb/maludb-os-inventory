---
name: buying
description: The rules of buying in Inventory — reorder points and quantities, the cheapest in-stock supplier offer, one purchase order per supplier, a drop-ship's ship-to is the customer's, MAP is not cost, what a draft is and what sending it means (money out, a person's). Use before drafting or explaining a purchase order, a reorder or a drop-ship.
---

# Buying

## The words of money
- **Retail** is what the business sells at. **MAP** is the lowest price the brand allows it to advertise. **Cost** is
  what the business pays — from the supplier's feed, the last receipt (the default) or typed. **MAP is not cost**: a
  retail under MAP is a price exception, a cost over retail is a margin problem; say which.
- An **offer** on a supplier source carries the cost (feeds and price sheets) or a dealer price, an availability, a
  lead time and how it ships. A drop-ship line **snapshots** the offer's cost and lead time; later moves do not change
  the line.

## Reorder
- A variant has a **reorder point** and a **reorder quantity** (its own, else the product's, else the settings' default).
  `reorder_candidates` lists every variant at or under its point with **the cheapest in-stock supplier offer** — ranked
  by cost, then lead time; `limited` and `back_order` are not in stock.
- No in-stock supplier offer: list the variant with "no supplier in stock" and the best `back_order` lead time; a person
  decides whether to wait.

## One purchase order per supplier
- `purchase_order_draft` makes a **draft**: supplier, kind (`stock` or `dropship`), ship-to, the lines (variant, the
  supplier's SKU, quantity, the unit cost from the offer it is ordered against, the expected date from the lead time).
  Group every line of a morning's reorder by supplier — **one draft per supplier**; a draft already open for that
  supplier is listed, never doubled.
- A **stock** order ships to a location; a **drop-ship** order's ship-to is **the customer's** address from the sales
  order, one per supplier per order, drafted at confirmation; the customer's phone goes on it when the setting
  `supplier_sees_phone` says so for how it ships (on by default for LTL and white glove).
- A supplier's **minimum order** and **MOQ** per item are facts on the supplier and the price sheet (`supplier_items`);
  a draft under them is drafted anyway and the shortfall said.

## Sending is a person's
- A draft is **never sent by you**. `purchase_order_send` (the email with the supplier's link) and
  `purchase_order_place` (recorded as placed on the supplier's portal) are **money out** and pause for a person; ask only
  when the person asked for exactly that, and say it waits.
- After sending, the supplier **acknowledges**, **declines a line** or **adds tracking** through their link
  (`purchase_order_events`); a person records the same by hand when the supplier phones. Awaiting acknowledgment past the
  setting's days is a morning-note item; so is a drop-ship with no tracking past its expected date (`dropships_untracked`).
- Receiving is the warehouse's: a stock order on a goods receipt; a drop-ship when the customer's line is delivered.
  Cost on a received line becomes the variant's cost when the setting says `last_receipt`.

## Never
Send or place. Quote a supplier's cost to a customer. Put a supplier's name on a customer-facing text. Split one
supplier's lines over several drafts. Invent a lead time the offer does not give.

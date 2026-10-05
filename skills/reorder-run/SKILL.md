---
name: reorder-run
description: Runbook for a reorder pass — every variant at or under its reorder point, the cheapest in-stock supplier offer for each, one drafted purchase order per supplier at the offer's cost, what was skipped and why, the report to the Buyer. For the Stock Buyer's morning duty or any agent asked "draft a PO for everything under its reorder point".
kind: runbook
---

# Reorder run

**Input**: nothing, or a brand, a supplier or a location to limit it. **Output**: one drafted purchase order per supplier
and a short report. **Sends**: nothing.

## 1. The candidates — `reorder_candidates`
Every active variant whose **available** (on hand minus allocated, across sellable locations; floor models excluded) is at
or under its **reorder point**, with its **reorder quantity** and **the cheapest in-stock supplier offer**: supplier,
source, cost, lead time, ships how, as of. Read the "as of": an offer from a stale or blocked source is not ordered
against — say so and take the next in-stock offer, or list the variant as "no live offer".

## 2. Group by supplier
One purchase order per supplier. Check `purchase_orders_open` for a **draft already open** for that supplier: add nothing
to it and do not draft a second — list it ("PO-00118 open for Malouf, drafted Mon; the new lines wait for a person").
Check `supplier_items` for the supplier's SKU, MOQ and the supplier's minimum order; a draft under them is drafted and the
shortfall said.

## 3. Draft — `purchase_order_draft`
Kind `stock`; ship-to the receiving location (the variant's main location, else the warehouse named in the settings);
one line per variant: the supplier's SKU, `reorder_qty` (rounded up to the MOQ), the unit cost from the offer, the
expected date from its lead time; the offer it is ordered against on the line. Say the number and the total at cost.

## 4. The report
Per supplier: the draft's number, lines, total at cost, the earliest expected date. Then what was skipped: no in-stock
offer; a stale source; a draft already open; a supplier with no price sheet. Cost belongs to the Buyer and the admin —
the report goes to them, never into a customer-facing text.

## 5. Stop
`purchase_order_send` and `purchase_order_place` are **money out** and a person's; do not ask unless asked. A person
reviews the draft on its page, changes a quantity, sends.

## If something is off
- `reorder_candidates` answers nothing: say "nothing under its reorder point" — a report people like.
- A variant has no reorder point: it is not a candidate; mention it once if the person asked about that variant.
- A supplier's cost moved since the last receipt: that is `price-check`'s line, not a reason to hold the draft.

---
name: morning-note
description: Runbook for the Stock Buyer's daily duty — the seven headings in order (sold lines at risk, reorder with drafted purchase orders, prices, unmatched listings with proposals, sources, purchasing, returns), which tool answers each, what is drafted and what is never sent, and who the note goes to. Run every day 06:30 or when a person asks for the morning note.
kind: runbook
---

# The morning note

**When**: every day 06:30 (the duty), or when the Buyer asks. **Who runs it**: the Stock Buyer. **Takes**: about twelve
tool calls plus one draft per supplier. **Writes**: drafts, proposals, watches, one note. **Sends**: nothing outside.

## 1. Sold lines at risk — `lines_at_risk`
Confirmed drop-ship lines whose offer went `out_of_stock` or `removed`, or whose source failed to pull; stock lines with
no on-hand at their location. For each: the order, the customer's first name, the variant, why — and `availability` on
the variant for an alternative (another supplier in stock, another store's shelf). Propose; change no line.

## 2. Reorder — `reorder_candidates`, then `purchase_order_draft`
Variants at or under their reorder point with the cheapest in-stock supplier offer. Group by supplier; **one draft per
supplier** (`stock`, the location's ship-to, each line's `reorder_qty` at the offer's cost, the expected date from its
lead time). A supplier with a draft already open: list, do not double. No in-stock offer: "no supplier in stock". Report
each draft's number and total at cost. **Drafted, never sent.**

## 3. Prices — `price_exceptions`
Retail under MAP; cost moved more than the setting (5 %); a reference under our retail by more than the setting (10 %).
Variant, the two numbers, the source and its date. Set nothing.

## 4. Unmatched listings — `unmatched_listings`, `match_proposals`
For each unmatched listing variant with no live proposal: your proposal with evidence and confidence (`match_propose`,
the rules of `matching-listings`; never across sizes; never accept). Count what is left for a person.

## 5. Sources — `source_health`
Pulls failed or blocked, feeds stale past their schedule, lead times drifting. A blocked source stays blocked; say when
it was blocked and that a person must look. Never ask for a login, a cookie, a captcha or a proxy.

## 6. Purchasing — `purchase_orders_open`, `dropships_untracked`
Purchase orders awaiting acknowledgment past the setting's days; drop-ships without tracking past their expected date.
Supplier, number, days. A person chases (`supplier_message` is theirs).

## 7. Returns — `returns_open`
Returns awaiting disposition: number, customer's first name, variant, days waiting.

## The note
One note, the seven headings each with its count, then the five things that matter most, then the drafts and proposals
by number:

```
Morning note — Mon 6 Oct
At risk 2 · Reorder 3 (2 POs drafted) · Prices 4 · Unmatched 7 (5 proposed) · Sources 1 blocked · POs 2 unacknowledged · Returns 1
Needs a hand today:
- SO-00231 line 2 (Alvarez): Zinus 12" Queen out of stock at Malouf since Sat — Lucid has it in stock, ships in 4 days
- PO-00118 drafted: Malouf, 3 lines, $1,284 at cost — send?
- Purple 2 Queen: casper.com reference $1,099 under our retail $1,299 by 15 %
- brooklynbedding.com blocked (429) since Sun 02:10 — a person must look
- Proposed: Malouf "Weekender Hybrid 10 Queen" → Weekender Hybrid 10 / Queen (GTIN absent; brand, name, size, dims agree)
```
No customer's address or phone; cost only because the Buyer may see it; never a supplier's name beside a customer's.

Deliver: the kernel's `message_send` to the Buyer's assistant (the Buyer is the person the settings name — seeded from
`INV_BUYER_EMAIL`, the first super-admin until set); the application emails the same note as the `morning_note`
notification; `message_post` in `#inventory` when Spaces is installed and you were granted it. `watch_set` where a thing
should wake you (a sold-out size at a feed). `note_add` on a record only when the fact belongs there.

## Stop
Do not send, place, price, match, delete or adjust. Nothing to report: the short note anyway — "nothing at risk, nothing
under its reorder point, every source healthy." A tool that errors is one line in the note ("`source_health` did not
answer"); go on.

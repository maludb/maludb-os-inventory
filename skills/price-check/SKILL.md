---
name: price-check
description: Runbook for a price pass — retail under MAP, cost moved beyond the setting, a reference price undercutting our retail beyond the setting, with the source and date of each; how to read an offer's history; what you say and never set. For the Stock Buyer's morning duty or any agent asked "what moved", "are we under MAP", "what does casper.com charge".
kind: runbook
---

# Price check

**Input**: nothing (the whole catalog), or a product, a brand, a source. **Output**: the exceptions, each with its two
numbers, its source and its date. **Sets**: nothing — `price_set` pauses for a person and you do not ask.

## 1. The exceptions — `price_exceptions`
Three kinds, each against a setting:
- **Retail under MAP** — our retail below the brand's minimum advertised price. The business's own numbers; no source.
- **Cost moved** — a supplier offer's cost (a feed, a price sheet) or our standard cost changed by more than the
  setting (5 %) since the last snapshot or receipt. Say old, new, the source, as of.
- **A reference undercuts** — a reference source (the manufacturer's site, a marketplace) lists the same variant under
  our retail by more than the setting (10 %). Say the reference price, ours, the source, as of; a reference is never a
  place to order from.

## 2. The history — when asked "since when" or "how"
- `offer_history` on the listing variant (a series of snapshots: price, availability, qty, lead time — a point whenever
  something changed, plus the daily heartbeat; silence means unchanged). `availability_timeline` for when it went out
  of stock and for how long.
- `price_history` on our variant: retail, MAP and cost changes with who and why.
- `offers_for_variant` for the current offers across every source, ranked.

## 3. Say it
One line per exception: the variant (name and size), the kind, the two numbers with the currency, the source and its
date, and — for a cost move — the effect on margin **only to someone who may see cost**. Group by kind; worst first (the
largest percentage). A source that is stale or blocked is said as such; its last price is a fact with a date, not today's.

## 4. Then
- A person decides: `price_set` (retail, MAP or cost) pauses for them; a floor-model discount is theirs.
- `watch_set` (`price_below`, `cost_below`, `map_breach`) when the person wants to be told next time.
- In the morning note, this is heading 3; the note states the counts and the five largest.

## Never
Set a price. Call a MAP breach a cost problem or the reverse. Quote a cost or a reference to a customer. Read a price off
a page yourself — the snapshots are the record; a live look is `source_search` and is said as "live, just now".

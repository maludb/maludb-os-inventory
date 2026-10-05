---
name: matching-listings
description: How a source's listing becomes ours — identifiers first (GTIN, supplier SKU, MPN with the same size, a marketplace id), then a person, then a proposal with its evidence that a person accepts; never across sizes; an agent matches only by identifier or a person's proposal and never accepts its own guess. Use when reading unmatched_listings or match_proposals, when asked "is this ours", or before listing_match.
---

# Matching listings

A **listing variant** (a source's sellable unit) is matched to **our variant** so its offers count in Find, in the morning
note and on an order line. The match is remembered with who made it and how sure it was; a dismissed proposal is
remembered so it is not proposed again. The match queue is the Buyer's daily work; you prepare it.

## The order — the first that holds
1. **GTIN** — the listing's barcode, normalized to GTIN-14, equals a variant's barcode or an identifier of kind
   gtin/upc/ean.
2. **Supplier SKU** — the source belongs to a supplier and the listing's SKU equals a `supplier_items.supplier_sku` or an
   identifier of kind `supplier_sku` for that source.
3. **MPN + size** — the listing's MPN equals a variant's MPN or identifier **and the `size_key`s agree**.
4. **Marketplace id** — an ASIN, an eBay EPID, a Walmart item id recorded as an identifier.
5. **A person's match** — made on the listing's screen or in the match queue.
6. **A proposal** a person accepts — the matcher's scoring or yours.

Rules 1–4 are **identifier matches**: you may make them yourself with `listing_match`, and the evidence is the identifier.
Rule 5 is a person's. Rule 6 is where you **propose** — never accept.

## A proposal states its evidence
`match_propose` with the listing variant, our variant, a confidence, and the evidence as facts a person can check:
- **brand** equal (the listing's vendor against our brand);
- **name tokens** shared (the model's words — "ProAdapt", "Medium" — not "mattress", "queen");
- **`size_key`** equal (Queen = Queen; "Cal King" = "California King" = "CK" through the synonyms);
- **dimensions** within 2 cm (length and width);
- **type** equal (Mattress to Mattress).
Confidence is honest: four of five is strong, two is a hint. A hint is still worth proposing when the queue is empty of
better; say that it is a hint.

## Never
- **Never across sizes.** A Queen listing never matches a King, whatever the name says; the referee refuses it anyway.
  When the listing's size is unknown, say so — a person decides.
- Never accept your own proposal, whatever the confidence (D6). Never match on name alone with `listing_match`.
- Never "forget" a listing (`listing_forget` pauses for a person) — "not ours" is the person's word in the queue.
- Never read a source's raw object into a note or a message; the trimmed fields a tool shows are enough.

## Reading the queue
`unmatched_listings` (per source or all) lists the listing variants with no match; `match_proposals` shows what is already
proposed, by whom, with what evidence, and what was dismissed. Work a source at a time; identifier matches first, then
proposals; count what is left for the morning note. The runbook `match-queue` is the pass in order.

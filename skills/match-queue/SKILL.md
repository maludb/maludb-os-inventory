---
name: match-queue
description: Runbook for working the match queue — a source's unmatched listing variants in order, identifier matches made, proposals made with evidence, the rest counted for a person; never across sizes; never accept a proposal. For the Stock Buyer's morning duty or any agent asked "which listings at X are not matched" or "match what you can".
kind: runbook
---

# The match queue

**Input**: a source (or every source). **Output**: identifier matches made, proposals made with evidence, a count of
what is left, in a short report. **Accepts**: nothing — a person accepts, in the queue.

## 1. Read the queue — `unmatched_listings`
Per source (work one at a time): each unmatched listing variant with its title, vendor, option values, `size_key`, SKU,
barcode, MPN, dims when the source gave them, current offer, first and last seen. `match_proposals` beside it: what is
already proposed, by whom, with what evidence, and what a person **dismissed** — a dismissed pair is not proposed again.

## 2. Identifier matches — `listing_match`
In this order, the first that holds, and only these:
1. **GTIN** — the listing's barcode as GTIN-14 equals a variant's barcode or a gtin/upc/ean identifier
   (`variant_by_identifier`).
2. **Supplier SKU** — the source belongs to a supplier and the SKU equals that supplier's `supplier_items` SKU or a
   `supplier_sku` identifier for the source.
3. **MPN + size** — the MPN equals a variant's MPN or identifier **and the size keys agree**.
4. **Marketplace id** — an ASIN, an EPID, a Walmart item id recorded as an identifier.
Say the rule and the identifier for each match made. A size the referee refuses (`size_key` disagrees) is not a match —
list it under "needs a person".

## 3. Proposals — `match_propose`
For what is left, score against our catalog (`find` by the listing's vendor and name tokens, then `product_variants`):
brand equal · name tokens shared · `size_key` equal · dimensions within 2 cm · type equal. Propose when at least brand
and size agree and something else does; state the evidence as facts and a confidence. Never across sizes; a listing
whose size is unknown is proposed with "size unknown — confirm" and a low confidence.

## 4. Not ours, and the rest
A listing that is plainly not something the business sells (another brand's pillow at a reference source) is **listed**,
not forgotten — `listing_forget` pauses for a person, and "not ours" is their word in the queue. What you could neither
match nor propose is counted.

## 5. The report
```
Match queue — Malouf (feed): 23 unmatched → 9 matched by supplier SKU, 2 by GTIN; 7 proposed (4 strong, 3 hints);
2 size unknown; 3 left for a person.
```
In the morning note this is heading 4. A person opens the queue, accepts, picks another, dismisses or says "not ours".

## Never
Match on a name alone with `listing_match`. Accept your own or the matcher's proposal (D6). Propose a pair a person
dismissed. Cross a size. Copy a source's raw object into a note.

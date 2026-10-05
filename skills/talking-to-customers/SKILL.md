---
name: talking-to-customers
description: What an order email, a delivery notice or a quote to a customer may say — the order, its lines at retail, the promised date, payment status, the secure link; never a supplier's name, never a cost, never a source, never a promise the offer does not support; and that sending it pauses for a person. Use whenever a text a customer will read is drafted.
---

# Talking to customers

A customer sees **their order and nothing else**: its number and date, the lines at retail, delivery or pickup with the
promised date, payment status and the balance due, tracking when a shipment has it, the business's name and contact, and
their secure link (`/o/<token>`). Everything you write for a customer is held to the same.

## What a text may say
- **The confirmation** (sent by `order_send`, with the link): "Your order SO-00231 of 5 October: Tempur-Pedic ProAdapt
  Medium, Queen, $2,199; delivery to 14 Elm St on or about 12 October by our truck; deposit $500 received, $1,699 due at
  delivery. Follow it here: <link>." One ask at most (a deposit, an appointment).
- **A notice** (`order_notify`): a delivery date, a delay with the new date, a pickup ready, tracking for a parcel. Say
  the fact and the next step; apologise once for a delay, briefly.
- **A quote**: the lines at retail, how each ships and when, the total, how long the quote stands.

## What a text never says
- **A supplier's name, a source, a cost or a margin.** A drop-ship line reads **"ships from our supplier"**; a lead
  time is given as a date or "in 5–7 days", never as a supplier's promise with its name.
- A price under MAP in writing (the business advertises at MAP or above; a person decides a floor-model discount).
- Another customer's anything. A person's phone or address other than the ship-to they gave.
- **A promise the offer does not support**: the date comes from the lead time of the offer the line was sold against,
  or from the shelf; "in stock" is said only when `availability` says so today.
- A payment it did not record: payment status is what `get_order` shows (unpaid, deposit, paid, refunded).

## Sending pauses
`order_send` and `order_notify` **leave the business** — they pause for a person's approval. Draft the text, say it waits,
and never send it another way. A customer is never texted by you (K28 is not built); email is the door.

## Tone
Plain, warm, short. The order by its number, the product by its name and size, money with its currency, the date in
words. Reply-to is the business's contact; "message us" is the link's `mailto:`.

---
name: Product signals (saved by, found in, related)
area: Catalogue / Discovery
status: Active
date_added: 2026-09-26
---

# Product signals: the product page as a meeting point

Roadmap step 3 ([../strategy.md](../strategy.md), section 4). A product page used to show the product
and its shops. It now also shows the people who want it and the Coves it is in, so it is a place in
the site rather than a click-out to a shop:

> **€55,00** · €55,00 – €59,99 · bij 4 winkels · **Bekijk de winkels**
> Saved by 12 people · Found in 3 Coves
> Related: *[a Cove]* · More from JBL · Gifts under €100

`App\Services\Catalogue\ProductSignals::for()` answers it, cached per product and market for an
hour (the counts move slowly, and this is the most-crawled template). `ProductController` sends it
as `signals`; `Pages/Product.tsx` draws it under the save and share buttons.

## The price range and the shops

The big number stays the lowest price: it answers "what does it cost". Under it, the range from the
cheapest to the dearest offer when there is more than one shop and the prices differ, the number of
shops, and **View shops**, which jumps to the offer table (`#offers`). `maxPrice` had been sent to
the page all along and never shown.

## Saved by N people

People, not lists: one person with three lists holding the product is one. An anonymous visitor's
list counts, because it is a real person's list (`owner_user_id` or `owner_anon_id`). A suggestion
nobody has accepted yet does not count.

- **Nothing shows below 5** (`giftcoves.product_signals.saved_threshold`). "Saved by 1 person" on a
  product whose one fan everybody knows is a way of pointing at that person's list.
- **Claims are never read** (invariant 4). Being bought is not being wanted, and a count that
  dropped when something was claimed would tell somebody it had been bought.

## Found in N Coves

Published Coves in this market whose picks include the product, newest first; the three newest are
named as links under Related. A draft does not count. Editorial Coves only for now: public lists
join the count when they exist (roadmap step 6).

## Related

Each only when it exists: the Coves holding the product, the brand's page ("More from JBL", only
where the brand has a page), and a price band: the smallest of €25, €50, €100 and €200 the product
fits under, linking to search filtered to that maximum. Above €200 there is no band; "under €500" is
not a budget anybody searches by. "People who want this also want…" arrives with intent search
(roadmap step 4, engine G) and gets its own band.

## On the search page (2026-09-26)

`ProductSignals::forResults()` gives the search results the same two counts, people and Coves, for a
whole page at once in two queries, shown as one small line under each card. It uses
`list_signals.min_owners` as its threshold (`searchThreshold()`), which the owner named for search,
not `saved_threshold`; while `GIFT_MIN_OWNERS=1` the two differ. See
[search.md](search.md#filters-behind-a-button-coves-above-and-what-others-keep-2026-09-26).

## Tests

`ProductSignalsTest`: people not lists, the threshold, anonymous lists counting, unaccepted
suggestions not counting, a claim changing nothing, drafts and other markets' Coves not counting,
the price bands.

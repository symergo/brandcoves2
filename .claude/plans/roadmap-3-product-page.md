# Roadmap step 3: the product page as a node

Strategy: [docs/strategy.md](../../docs/strategy.md), section 4. Written 2026-09-26.

## Goal

> **LEGO Technic Ferrari** · €89.99–€104.95 · available from 6 shops [View shops]
> [Add to my Cove] · Saved by 342 people · Found in 18 Coves
> Related: Gifts for car lovers · Gifts under €100 · LEGO Coves

The page where a product, its shops, the people who want it and the Coves it is in meet.

## Where we start

`ProductController` + `Pages/Product.tsx`: image, brand link (`BrandLinker`), title, lowest price
with a discount badge from `previousPrice`, "Compare N shops", save / alert / share buttons,
barcode + `AmazonSearchCta`, all offers, the shop's description, `RecentlyViewed`. `maxPrice` is
sent and not shown. No counts, no Coves, nothing related.

Data available: `wishlist_items.group_id` (indexed), `wishlists.owner_user_id` / `owner_anon_id`,
`daily_picks.group_id` → `daily_pick_sets` (published scope, `CoveKind::path()` for links),
`brand_stats`, `product_groups.gift_tags`.

## Design

### 1. Price range and shops

"€89.99–€104.95 · available from 6 shops" when `min_price !== max_price` and more than one shop;
the lowest price alone otherwise. [View shops] scrolls to the offers. Amazon rules unchanged: the
range comes from stored offers only, and Amazon is never among them.

### 2. Saved by N people

`App\Services\Catalogue\ProductSignals::for(ProductGroup)`, cached per group for an hour:

- `savedBy`: distinct owners (user or anonymous identity) of **accepted** items on that group
  (suggestions not yet accepted do not count). Claims are never read (invariant 4).
- Shown only from 5 (config, with the reason): below that a count can point at one known person's
  list ("saved by 1 person", and you know who keeps a list of LEGO).
- A later "saved in the last 30 days" is step 7's engagement aggregate; this step reads live.

### 3. Found in N Coves

Published editorial Coves whose picks include the group (`daily_picks` → `daily_pick_sets`
published, this market). Linked: the three most recent as chips, "and N more". Public lists join
the count in step 6.

### 4. Related

Up to three chips, in this order, each only when it exists:
1. Coves that hold it (from 3).
2. Its brand's page (`brand_stats`, via `BrandLinker`).
3. A price band ("Gifts under €100"), pointing at a filtered search until step 4's landing pages
   exist, then at those.

"People who want this also want…" (products linked through people's own lists) arrives with step 4
(engine G) and gets its own band on this page.

### 5. Structured data

`Product` JSON-LD already exists; add `AggregateOffer` (lowPrice, highPrice, offerCount) where it is
missing. No review or rating markup: we have none, and inventing it is against Google's rules.

## Decide before building

1. The threshold for "saved by" (5 suggested) and whether anonymous owners count (they are real
   people with a list; recommended yes).
2. Wording in four languages ("Saved by 342 people" / "342 mensen bewaarden dit" …) and whether the
   heart icon stays (icons are drawn by `ToolIcon`, not emoji).

## Files

- New: `app/Services/Catalogue/ProductSignals.php`, `Components/ProductSignals.tsx`.
- Change: `ProductController` (range, signals, Coves, related), `Pages/Product.tsx`,
  `lang/*/site.php` (`product.*`), `config/giftcoves.php` (threshold).
- Docs: new `docs/features/product-signals.md`; update the product page's doc and `INDEX.md`.

## Verification

- Feature: `ProductSignalsTest` (below the threshold nothing shows; one person with three lists is
  one; an unaccepted suggestion does not count; a claimed item counts as a save and reveals
  nothing about the claim; an unpublished Cove does not count; another market's Cove does not
  count), a page test for the range and the chips.
- By hand: a product in several Coves and lists on dev data; check the page on mobile.

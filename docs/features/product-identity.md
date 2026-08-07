---
name: Product identity & offer grouping
area: Catalogue
status: Designed — schema built, resolver is Phase 1
date_added: 2026-08-07
---

# Product identity & offer grouping

This is the mechanism the whole product rests on. Without it there is no offer comparison, no
"cheapest across shops", and no way to show one product page instead of eleven near-duplicates.

## Offers vs products

> `products` rows are **offers**: one merchant, one product, one market.
> `product_groups` rows are **physical products**.

Everything a visitor sees — search results, product pages, gift picks, guide items — operates on
**groups**. Offers hang off them.

Naming a table `products` and having it hold offers is a wart, kept because every feed calls its rows
products and renaming invites a different confusion. The distinction is enforced by comment, type
signature and this document.

## Identity is scoped to the market

`product_groups` is unique on `(market, identity_key)` — deliberately, and this is the rule most
likely to be "simplified" by someone later.

The same product ingested for two markets has **different tax, shipping and availability**. Those
offers are not interchangeable. Merging across markets lets a foreign price masquerade as the
cheapest one, which is both wrong and, for a price-comparison site, the worst possible kind of wrong.

## Two identity paths

### EAN/GTIN — authoritative

- Validated with the **GS1 modulo-10 check digit**.
- **UPC-A (12 digits)** and **ITF-14 (14 digits)** normalised to GTIN-13.
- Placeholders rejected: `0`, `N/A`, all-zeros, repeated digits. Feeds are full of these.

A wrong EAN merges unrelated products into one offer set. That is strictly worse than not merging at
all, so validation is strict and anything doubtful is left alone.

### Brand + normalised title — the fallback

A large share of feed rows carry **no EAN**. Without a fallback those products could never be
compared across merchants, which would gut the feature for most of the catalogue.

Guards against bad merges:

- Rows with **no brand** are left ungrouped.
- Rows with a title under `identity.min_title_length` (10) characters are left ungrouped — short
  titles collide trivially.
- Title normalisation is aggressive and lossy: strip accents, parenthesised and bracketed asides,
  punctuation, then collapse whitespace. Precision over recall.

## Group aggregates

`best_offer_id`, `min_price`, `max_price`, `offer_count`, `merchant_count` and `in_stock` are
denormalised onto the group so a results page is **one query**, not one query plus N.

Recomputed **set-based**, one statement per group, after each ingestion chunk. Ties broken on lowest
id so repeated runs are stable and never churn `best_offer_id` — a group whose "best offer" flickers
between two equally-priced merchants produces pointless cache invalidation and a jumpy UI.

## Prices are integer cents

Floats accumulate error across exactly the min and median aggregates that drive "cheapest offer" and
discount badges. Both have to be exactly right, and a check constraint forbids negatives — a negative
price would sort straight to the top of every cheapest-offer query.

## Discounts measured against our own median

`ProductGroup::discountPercent()` compares `min_price` against the **30-day median from
`price_history`**, not against a merchant-supplied "was" price. Some merchants inflate the reference
price so everything looks discounted; `merchants.trusts_reference_price` flags those, and the Daily
Picks discount lane excludes them.

The percentage is **floored, never rounded** — a badge must not overstate a saving.

## Files

- `database/migrations/2026_08_07_000200_create_catalogue_tables.php`
- `app/Models/ProductGroup.php`, `app/Models/Product.php`
- `app/Services/Identity/` (Phase 1)
- `config/brandcoves.php` (`identity.*`)

## Verification (Phase 1)

Unit tests must cover: valid GS1 check digits, invalid ones, UPC-A → GTIN-13, ITF-14 → GTIN-13, and
every placeholder form. Then an integration assertion that a product stocked by two merchants with a
shared EAN produces exactly one group with `merchant_count = 2`.

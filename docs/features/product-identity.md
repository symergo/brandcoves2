---
name: Product identity & offer grouping
area: Catalogue
status: Active
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

`best_offer_id`, `min_price`, `max_price`, `previous_price`, `offer_count`, `merchant_count` and
`in_stock` are denormalised onto the group so a results page is **one query**, not one query plus N.

Recomputed set-based, in one statement over the whole market, by `GroupProducts` after ingestion has
landed (05:00 and 17:00) and never per chunk: a cheapest offer computed from a half-loaded catalogue
is wrong. Ties broken on lowest id so repeated runs are stable and never churn `best_offer_id` — a
group whose "best offer" flickers between two equally-priced merchants produces pointless cache
invalidation and a jumpy UI.

## Prices are integer cents

Integer cents, per invariant 7; the parsing rule is in [ingestion.md](ingestion.md#prices).

## Discounts measured against the offer's previous price

`ProductGroup::discountPercent()` compares `min_price` against the **previous price of the offer the group links to** (a 30-day median from a price history until 2026-09-12), not against a merchant-supplied "was" price. Some merchants inflate the reference price so everything looks discounted, which is why the badge
never uses it. `merchants.trusts_reference_price` is editable in the admin, but no code reads it
today.

The percentage is **floored, never rounded** — a badge must not overstate a saving.

## Files

- `database/migrations/2026_08_07_000200_create_catalogue_tables.php`
- `app/Models/ProductGroup.php`, `app/Models/Product.php`
- `app/Services/Identity/`
- `config/giftcoves.php` (`identity.*`)

## Verification

Covered by `tests/Unit/GtinTest.php` and `tests/Feature/IngestionTest.php`.

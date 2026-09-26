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

## Merges and splits

Added 2026-09-26 (roadmap step 5). The two exact paths above leave some products apart that are
one ("LEGO Technic Ferrari 488" and "LEGO Ferrari 488 #42125"), and very occasionally put an offer
in a product it does not belong to. A person can now correct both, in the admin (Catalogue >
Products, and the queue at Catalogue > Match review, see [match-review.md](match-review.md)).

### Why a merge lives in identity, not in `group_id`

`ProductGrouper` re-derives every offer's product from its identity key twice a day, and
`IncomingGrouper` does the same for every offer a live search or a page import brings in. A merge
that only repointed `products.group_id` was undone within twelve hours. So a merge is written where
the grouper looks:

- **`identity_aliases`** (`market, from_key → to_key`): a merge. Offers whose key is `from_key` are
  grouped as if it were `to_key`. Followed one hop only; `GroupMerger` rewrites every alias that
  pointed at a product it merges away, so a chain never forms.
- **`identity_overrides`** (`product_id → forced_key`): a split. The offer gets a key of its own,
  `split:{id of the first offer split off}`, shared by every offer split off together so a split
  makes one new product.

The grouper uses the **effective key**: the override if there is one, else the offer's own key;
then, if an alias starts from that key, the alias's target. So an override beats the alias on the
offer's old key (a split survives the product it left being merged away), and a product made by a
split can itself be merged later (the alias on `split:N` is followed).

Two statements rather than one in each step of `ProductGrouper`, for speed: the plain statement is
the one that always ran, skipping the few offers an alias or override covers (two anti-joins against
tables of a few hundred rows), and a second statement handles those few, found from the two small
tables outward. Without the skip the plain statement would move a merged offer back every run and
the second would move it again.

A split product's `identity_kind` is `title`, whatever the offers' own kind: the kind says whether
the key *is* a barcode, and the product page prints an EAN-kind key as the barcode.

### What a merge does (`App\Services\Identity\GroupMerger`)

One transaction: the alias; every foreign key to the loser moved to the winner; the two JSON id
arrays (`cove_plans.pinned_group_ids`, `search_alerts.seen_group_ids`); `merged_into_id` on the loser;
the aggregates of both recomputed for just those two (`ProductGrouper::recomputeGroups`), so the
winner shows its new offers on the next page load.

Four tables have a unique constraint that collides when a row points at both: `wishlist_items
(wishlist_id, group_id)`, `cove_plan_items (plan_id, group_id)`, `price_alerts` and `restock_alerts`
`(group_id, user_id)`, and `community_answer_picks (answer_id, group_id)`. The winner's row is kept
and the loser's deleted; on lists and Cove plans the deleted row's **note is appended** to the kept
one, and on a list a **claim moves across** when the kept item has none, so a gift already bought is
not offered again. `product_links` rows of the loser are deleted, not moved: the nightly
CountListSignals rebuilds them from the lists, which have just moved. A published Cove holding both
keeps its loser card rather than showing one product twice: deleting a pick would change a page an
editor approved.

The loser is **kept**, not deleted, and `product_groups.merged_into_id` points at the winner:

- `/p/{loser}` answers **301** to the winner (`ProductController`);
- a `[[product:loser]]` token in prose written before the merge links to the winner, silently
  (`CoveMarkup::mergedIn`, via `App\Services\Identity\MergedProducts`), and its card still pairs
  with its paragraph (`ProseCards`);
- lookups by barcode (scan, catalogue API, Cove item by EAN, pasted links, barcode items) follow
  `ProductGroup::followMerge()`.

An editor's hand-written title and gift tags on the loser are kept on the winner when the winner has
none. Merging across markets is refused (invariant 2), as is merging into or out of a product that
was itself merged.

`GroupSplitter` creates the split product and moves the offers in the request rather than running
the grouper for the market (minutes on production), and records the two products as a rejected pair
so the match rules never propose putting them back together.

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
- `database/migrations/2026_09_27_000100_products_that_arrived_separately_can_be_matched.php`
- `app/Models/ProductGroup.php`, `app/Models/Product.php`, `app/Models/IdentityAlias.php`,
  `app/Models/IdentityOverride.php`
- `app/Services/Identity/` (`GroupMerger`, `GroupSplitter`, `MergedProducts` beside the resolvers)
- `app/Services/Ingestion/ProductGrouper.php`, `IncomingGrouper.php`
- `app/Filament/Resources/ProductGroups/`
- `config/giftcoves.php` (`identity.*`)

## Verification

Covered by `tests/Unit/GtinTest.php`, `tests/Feature/IngestionTest.php`,
`tests/Feature/GroupMergerTest.php` (each colliding table, JSON arrays, notes and claims kept),
`tests/Feature/MergesSurviveRegroupingTest.php` (a merge survives the grouper and live search; a split
survives an alias) and `tests/Feature/MergedProductLinksTest.php` (301, prose tokens).

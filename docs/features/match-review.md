---
name: Match review (products that arrived separately)
area: Catalogue / Admin
status: Active — rules propose, a person decides every pair; no auto-merge yet
date_added: 2026-09-26
---

# Match review: products that arrived separately

Roadmap step 5, engine C in [../strategy.md](../strategy.md). "LEGO Technic Ferrari 488" and "LEGO
Ferrari 488 #42125" should be one product with two offers. The exact identity rules in
[product-identity.md](product-identity.md) keep them apart unless a barcode joins them, on purpose:
a wrong merge is worse than a missed one. This adds the missing step in between: rules that
**propose** pairs, and a queue where a person **decides** each one.

Admin only. Visitors see the result (one product page instead of two), never the queue, so there is
no line on the public help page.

## Decisions taken while the owner has not ruled

The plan left two questions open; these are the answers used until the owner sets others.

1. **Nothing merges on its own.** Every pair goes to the review queue, whatever the rule. The page
   shows each rule's precision (merged out of decided) so an automatic-merge bar per rule can be set
   later from numbers, not a feeling. No switch for it exists yet.
2. **A visitor's pasted product is out of scope.** A pasted page is not in `products` (see
   [pasted-links.md](pasted-links.md)), so no rule sees it. Whether it may be merged into a feed
   product by a rule, or only by a person, is still the owner's question.

## The rules (`App\Services\Identity\MatchFinder`)

Run in order of how often they are right, so the most precise rule claims a pair first; the queue
(`match_candidates`) is unique on the pair, stored with `group_a < group_b`.

1. **Barcode**: offers in two products carry the same barcode (leading zeros ignored). Happens when a
   barcode failed validation at ingest and the offer was grouped by title. Offers split by hand are
   left out.
2. **Model number**: same brand, same model number. From the title
   (`App\Services\Identity\ModelNumber::extract`: `42125`, `WH-1000XM5`, `SM-S921B`; not years,
   sizes, capacities, speeds, resolutions or standards like `USB3`) or from a feed's own part number
   (`products.mpn`, kept from Awin since this step). A model number shared by more than six products
   of one brand (`identity.matching.model_bucket_max`) names a series, not a product, and is skipped.
3. **Similar title**: same brand, trigram similarity of the titles at least 0.6
   (`identity.matching.title_similarity`), at most three partners per product, found through the
   trigram index search already has on `product_groups.title`.

Guards on every rule: same market (invariant 2); both products live (not merged, with offers);
**never two barcode products**, because two valid barcodes are two products (very often two colours
of one model), and the barcode is the identity this site trusts outright. Rules 2 and 3 also need
the **numbers in the titles to agree**: every number in one title appears in the other
(`ModelNumber::numbersAgree`). "LEGO Ferrari 488" and "LEGO Ferrari 488 #42125" pass; "Galaxy A27
128GB" and "Galaxy A17 128GB" do not.

A pair somebody rejected is never proposed again: the insert ignores a pair that already has a row.
When two products are merged, pending pairs with the loser are dropped (the next run proposes them
against the winner if they still hold) and rejections are carried over to the winner as `manual`,
which never counts in a rule's precision.

## When it runs

`FindMatchCandidates` per market in the catalogue run, twice a day, right after the market's grouping
and brand statistics (since 2026-09-28; at 05:40 and 17:40 before, forty minutes after grouping
started), because it reads the groups grouping writes. One run per market at a time, checked when it
runs (`RunsOneAtATime`) rather than `ShouldBeUnique`, which would cut the chain. The nightly pass compares everything for rules 1 and 2, and for
rule 3 starts only from products first seen in the last three days (`identity.matching.recent_days`),
against every product. The first run on an environment is by hand:

```bash
php artisan bc:find-matches --full --sync          # every market, every product
php artisan bc:find-matches --market=be-nl --sync  # one market, incremental
```

## The queue (`/admin/match-review`)

Modelled on the Cove curation screen: two products side by side (image, title, brand, how each is
grouped, offers and prices), the rule and what it matched on, and four buttons with keys, because
this is a queue someone works through: **M** the same (merge), **N** not the same, **S** skip (back
next time), **K** keep the other one. The page suggests which side survives: a barcode product over a
title one, then the one with more offers, then the older one. Barcode pairs come first, then model,
then title, each by title similarity, so the likeliest decisions are first and the precision numbers
fill quickly.

Merging here is the same `GroupMerger` as the product page's **Merge into...**; see
[product-identity.md](product-identity.md#merges-and-splits) for what it moves and keeps.

## Measured on a copy of the catalogue (2026-09-26)

The development database (148,000 products in three markets). A full pass took 3.5 minutes.

| Market | Barcode | Model number | Similar title |
|---|---|---|---|
| be-nl | 0 | 626 | 2,038 |
| be-fr | 0 | 621 | 1,834 |
| nl-nl | 0 | 183 | 1,447 |

Read by eye, 25 pairs per rule in be-nl:

- **Model number** is right a little over half the time (14 of 25). The misses are mostly a bundle
  or multi-pack beside its single item ("TP-Link EAP610 4-pack", "HP M110w + 1 extra toner", two
  LEGO sets sold together) and colours of one model that share a number but not a barcode. Before
  the unit fix, memory cards of one speed class (`80MB/s`) were proposed across every capacity;
  `ModelNumberTest` now pins that.
- **Similar title** is right rarely, perhaps one pair in ten, even with the numbers guard (which
  roughly halved it; before, nearly every pair was two neighbouring models). Neighbouring
  products of one line with no number in the name ("iPad Air 13" 256GB" and "iPad Pro 13" 256GB")
  still pass. It is the rule most likely to need a higher threshold, and the precision table is how
  to tell.
- **Barcode** found nothing locally, which is expected: the feeds here send valid barcodes or none.

The plan's next check, before any rule merges on its own, is 50 decisions per rule on a scrubbed
copy of production.

## Files

- `app/Services/Identity/MatchFinder.php`, `ModelNumber.php`, `GroupMerger.php`, `GroupSplitter.php`
- `app/Jobs/FindMatchCandidates.php`, `app/Console/Commands/FindMatchesCommand.php`,
  `routes/console.php`
- `app/Filament/Pages/MatchReview.php`, `resources/views/filament/pages/match-review.blade.php`
- `app/Filament/Resources/ProductGroups/` and its view
- `app/Models/MatchCandidate.php`, `app/Enums/MatchRule.php`, `app/Enums/MatchStatus.php`
- `config/giftcoves.php` (`identity.matching.*`)

## Verification

`tests/Unit/ModelNumberTest.php` (LEGO titles, phone and TV models, and the sizes, years and speeds
that must not match; when two titles' numbers agree), `tests/Feature/MatchFinderTest.php` (each rule,
the barcode-pair and brand guards, rejected pairs stay rejected, precision) and
`tests/Feature/MatchReviewScreenTest.php` (both screens render; merge, swap, reject, skip, and the
product page's merge and split).

## "The same" for a whole rule (2026-09-26)

The owner asked for a button after each rule in the precision table that applies "The same" to every
waiting pair of that rule. `MatchReview::mergeRule()` counts the waiting pairs (in the market the
screen is narrowed to, if any) and dispatches `MergeRuleCandidates`, which merges them 200 per run and
queues the next run until the rule's queue is empty (invariant 8). Each pair is re-read before its
merge, because an earlier merge may already have settled it; a pair the merger refuses stays waiting
for a person. Which product survives is `MatchKeeper::pick()`, the same choice the one-at-a-time
review makes (barcode product, then more offers, then older), so the two cannot disagree.

The button asks first, naming the count and the rule's precision so far. That is the whole safety
net, and it matters: when measured, the model-number rule was right about half the time and the
similar-title rule about one time in ten, so pressing it on "Similar titles" folds unrelated
products together. A merge can be undone with Split, but one product at a time; there is no bulk
undo. Tests: `MatchReviewScreenTest` (a rule's pairs merge and another rule's do not; the market
filter holds).

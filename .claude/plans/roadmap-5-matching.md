# Roadmap step 5: matching products that arrived separately

Strategy: [docs/strategy.md](../../docs/strategy.md), engine C. Written 2026-09-26.

## Goal

"LEGO Technic Ferrari 488" and "LEGO Ferrari 488 #42125" end up as one product with two offers,
without ever merging two different things by accident. Step 1 makes this urgent: every pasted page
is a new spelling of something we may already have.

## Where we start

- Identity is exact: a valid GTIN-13, else `brand|normalised title`
  (`app/Services/Identity/IdentityResolver.php`). No model numbers (the Awin connector drops `mpn`
  on purpose, `AwinConnector.php:286`), no similarity between products. `pg_trgm` indexes exist on
  titles but serve only search.
- `ProductGrouper::linkOffersToGroups` re-derives `products.group_id` from `identity_key` on every
  run (twice a day) and `IncomingGrouper::attachFrom` does the same per request. **A hand-made
  merge that only repoints `group_id` is undone within 12 hours.** Any merge has to live in
  identity, not in the foreign key.
- Ten tables point at `product_groups.id`, four with unique constraints that collide on a merge
  (`wishlist_items (wishlist_id, group_id)`, `price_alerts`, `restock_alerts`,
  `cove_plan_items (plan_id, group_id)`). Also: ids inside JSON arrays
  (`cove_plans.pinned_group_ids`, `search_alerts.seen_group_ids`), `[[product:N]]` tokens in Cove
  prose, and public URLs `/p/{group}/{slug}` which 404 on a missing id.
- No ProductGroup resource in Filament; the Offers table has no actions.
- The product-identity doc's rule stands: a wrong merge is worse than no merge.

## Design

### 1. Merges and splits live in identity

- `identity_aliases (market, from_key, to_key, reason, created_by, created_at)`, unique
  `(market, from_key)`. The grouper uses the effective key
  `COALESCE(alias.to_key, products.identity_key)` in both `createMissingGroups` and
  `linkOffersToGroups`; `IncomingGrouper` the same. Aliases are followed one hop only; writing an
  alias rewrites any alias that pointed at the loser, so chains never form.
- `identity_overrides (product_id unique, forced_key, reason, created_by)`: a split. The offer gets
  `split:{product_id}` (or an editor-chosen key) as its identity, which beats both its own key and
  any alias.

### 2. The merge itself: `App\Services\Identity\GroupMerger`

One transaction:
1. Write the alias (loser key → winner key).
2. Repoint every foreign key from the loser to the winner. Where a unique constraint would
   collide, keep the winner's row and delete the loser's (a list holding both keeps one item;
   the note of the deleted one is appended to the kept one so nothing a person wrote is lost).
3. Rewrite the JSON id arrays.
4. `product_groups.merged_into_id` on the loser (new nullable column, FK to groups). The loser is
   kept, not deleted, so old URLs and prose tokens still resolve.
5. Recompute aggregates for the winner.

`ProductController` follows `merged_into_id` with a 301. The prose token renderer follows it
silently. A split is the reverse: override the chosen offers, run the grouper for the market.

### 3. Finding candidates: `FindMatchCandidates` job

Runs after `GroupProducts`. Writes `match_candidates (market, group_a, group_b, rule, score,
status CHECK in (pending, merged, rejected), decided_by, decided_at)`, unique on the ordered pair,
so a rejected pair is never proposed again. Rules, in precision order, all within one market and
one brand key:

1. **Barcode conflicts**: two groups whose offers share a GTIN (possible when one offer's GTIN was
   missing at grouping time).
2. **Model number**: `App\Services\Identity\ModelNumber::extract(title)` — a pure class that finds
   tokens like `42125`, `WH-1000XM5`, `SM-S921B`: mixed letters and digits or 5+ digits, not a
   year, not a size (`500ml`, `42mm`), not a quantity. Same brand + same model number = candidate.
   Awin's `mpn` becomes a stored column on `products` so feeds that send one skip the guessing.
3. **Similar titles**: `similarity(normalised title a, b) >= 0.6` inside one brand, using the
   existing trigram indexes, capped to the top candidates per group.

Nothing merges automatically at first. After a few hundred decisions, the admin page shows each
rule's precision (merged ÷ decided); a rule above an agreed bar (say 98 %) can be switched to
auto-merge by config. That turns "precision over recall" into a number instead of a feeling.

### 4. Admin

- `app/Filament/Resources/ProductGroups/ProductGroupResource.php`: list, view (its offers with
  source and identity), actions **Merge into…** (search box) and **Split offers…** (checkboxes).
- A **Match review** page modelled on `CovePlans/Pages/CuratePlan.php` (Livewire methods +
  header actions): two products side by side, image, offers, prices; buttons Merge / Not the
  same / Skip; keyboard shortcuts, because this is a queue someone works through.

## Decide before building

1. The auto-merge bar per rule, and who reviews the queue at first.
2. Whether a visitor's pasted product (step 1) may be merged into a feed product by a rule, or only
   by a person.

## Files

- Migrations: `identity_aliases`, `identity_overrides`, `match_candidates`,
  `product_groups.merged_into_id`, `products.mpn`.
- New: `app/Services/Identity/{GroupMerger,ModelNumber}.php`, `app/Jobs/FindMatchCandidates.php`,
  the Filament resource and review page with its Blade view.
- Change: `ProductGrouper`, `IncomingGrouper`, `AwinConnector` (keep `mpn`), `ProductController`
  (301), the prose token renderer, `routes/console.php` (schedule after grouping).
- Docs: update `product-identity.md` (aliases, overrides, why merges live in identity), new
  `match-review.md`, `INDEX.md`.

## Verification

- Unit: `ModelNumberTest` (the LEGO titles, phone models, sizes and years that must not match),
  `GroupMergerTest` (each colliding table, JSON arrays, note preserved), grouper tests proving a
  merge survives a re-run and a split survives an alias.
- Feature: `/p/{loser}` answers 301 to the winner; a Cove with `[[product:loser]]` renders.
- Run `FindMatchCandidates` on a local copy of production data (scrubbed) and review 50 candidates
  per rule by hand before building the auto-merge switch. This touches shared services and
  migrations, so the full suite runs before the commit.

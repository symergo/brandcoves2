---
name: Entity Coves — shops and brands
area: Content / Discovery
status: Active
date_added: 2026-09-05
---

# Entity Coves

**Prose about a shop or a brand, above live rails of that entity's products.**

A Shop Cove and a Brand Cove are one page shape, and they were broken in opposite
directions.

| | Products | Bespoke prose |
|---|---|---|
| `/shops/{slug}` | none at all | yes — a real Cove |
| `/brand/{slug}` | the whole grid | **no** — `copy_templates` slots, the same for every brand |

So Brand is brought onto Shop's model rather than the other way round: `CoveKind::Brand` is planned,
curated (trivially — see below), written, approved and built like every other kind.

## It renders on the page that already exists

`/{market}/brand/{slug}`, above the grid. [brand-pages.md](brand-pages.md) exists to argue for **one
canonical indexable URL per brand per market** — every brand mention on the site, on cards, in
facets, in generated Cove prose, points there — so a second address would split exactly the link
equity that page was built to consolidate.

`CoveKind::isEntity()` is the new predicate. Like `isArticle()` and `expectsShortlist()` before it,
it exists because the questions are genuinely different: `isArticle()` asks about the `/guides` URL
space, and both entity kinds answer **false**.

## An entity Cove carries no shortlist at all

This is the decision everything else follows from. **The prose is about sub-brands and product
categories, never about individual products.**

A page's products and a page's prose move at different speeds. A frozen "biggest discounts" list is
wrong within days; a live one cannot be named in prose written last month, because nothing knew
which products it would hold. Writing about *ranges* resolves both: ranges do not move, and the
products come from live rails underneath.

So: floor 0, `expectsShortlist()` false, nothing to freeze, nothing to go stale, and an entity Cove
can never land in the `Thin` state.

**It is expected to carry search links.** That is the point of the page rather than a decoration on
it: `/brand/sony` is an indexable destination, and the categories it names are real crawlable market
URLs. `Defaults::BRAND_SYSTEM` asks for them directly.

## The slug is not ours to choose

The one validation rule no other kind has. A Shop Cove's slug is derived from `merchants.domain`
(`bol-com`, `coolblue-be`) and a Brand Cove's is the `brand_stats` slug — and both are what make the
same entity pairable across markets for hreflang.

A hand-typed or API-supplied slug bypasses that derivation, and the result is a page about a shop
absent from the directory it sits above, or a brand page above a grid of nothing — with nothing to
report it, because the plan is perfectly well-formed. `POST /coves` refuses both.

## The rails

`App\Services\Cove\EntityRails`. Live at render, cached for fifteen minutes, scoped to the entity —
`products.merchant_id` for a shop, the brand's slug **and its aliases** for a brand, because feeds
disagree about punctuation and "Audio-Technica" and "Audio Technica" are one brand.

| Rail | Ordered by | What it claims |
|---|---|---|
| Discounts | the drop against the 30-day median | first-party, and a reader can check it |
| Popular | `PopularRank` | a retailer's chart |
| Wishlisted | distinct wishlists holding it | what **our** visitors want |

There is no `discount_percent` column: a discount is measured against the **30-day median** rather
than a merchant-supplied "was" price, which is frequently fiction. The rail repeats that rule in SQL
rather than approximating it, floor included — a saving that floors to zero is not a saving, and a
rail showing one would claim nothing while looking exactly like a rail claiming something.

### The rank may be the label here

This **narrows a rule** stated in [popularity-charts.md](popularity-charts.md):

> reprinting it as our content is republishing somebody else's ranking. The rank shapes the shelf,
> it is never the label.

That was written when a chart only ordered an internal shelf. On an entity page the ordering *is*
the rail, and labelling it is the point. The exception is recorded in that file too rather than left
to contradict this one — a codebase carrying a stated rule its own code breaks is worse than either
position, because the next person to read the line will "fix" the rail back.

The exposed case is a shop page: a `/shops/bol-com` popularity rail ordered by bol's own chart is
close to their bestseller list republished on a page about them. Named here rather than left to be
rediscovered.

### The wishlist rail is the honest one, and it is new data

It is first-party: it says what *our* visitors want rather than what somebody else sold. Four rules,
and the threshold is the one that matters.

- **Distinct wishlists are counted, never people**, and whose is never exposed.
- **Three lists minimum** (`EntityRails::WISHLIST_FLOOR`). With one, a shared list and a brand page
  together identify an individual's list. The threshold *is* the anonymity — below it the rail stops
  being an aggregate and becomes a way of asking whether one particular person wants one particular
  thing. Getting this wrong is a privacy bug rather than a layout one, which is why it is the test
  written first.
- **Computed live and cached, never stored in a snapshot table.** A list its owner deletes, or one
  reaped by `bc:prune-personal-data`, then leaves the rail at the next cache expiry rather than
  persisting in an aggregate nobody thinks to prune.
- **Invariant 4 is untouched.** Nothing reads `claimed_by_hash` or says anything about whether an
  item was bought. It reports *membership*, not claim state, and the two are not the same question.

Counting **lists** rather than rows is deliberate: one person with four lists is not four people
wanting a thing, and counting rows would let a single enthusiastic list clear the floor on its own.

Private lists **do** count toward the threshold-gated total. The output carries no identity, and
excluding them would weaken the signal without making it safer. That is a judgement call rather than
an obvious one — check it against the published privacy notice, because aggregate use of wishlist
data may need a line in it. See [legal-pages.md](legal-pages.md).

## The allowlist is the entity's own shelf

The piece that makes the search links real rather than aspirational.

An entity Cove's allowlist carries **no products** — the prose is about ranges, and the products
under it are a live rail. So without something else it would carry nothing at all, and every
`[[search:…]]` would render as three plain words. `EntityRails::vocabularyForBrand()` and
`vocabularyForShop()` supply the categories that entity actually sells in, drawn from the **same
scope as the rails** — so what the prose may link to and what the page shows are the same subjects,
and a writer cannot link a category this brand does not stock.

A token naming anything outside it renders as plain text, which is the safety property: a
hallucinated link is an unlinked phrase rather than a 404 in the middle of an article.

## Two prose regions on a brand page, with different jobs

The Cove above the grid is bespoke editorial. The templated numeric copy below the grid stays and
still serves every brand with no Cove — which is the great majority of them. They are not
duplicates: one is written about ranges, the other is built from numbers the catalogue can back up.

## Files

- `app/Enums/CoveKind.php` — `Brand`, `isEntity()`
- `app/Services/Cove/EntityRails.php`
- `app/Services/Ai/Prompts/Defaults.php` — `BRAND_SYSTEM`, `BRAND_PROMPT`
- `app/Services/Shops/ShopDirectory.php` — the shop slug rule and membership
- `app/Http/Controllers/BrandController.php` — `cove()`, and the rails prop
- `app/Http/Controllers/GuideController.php` — `shopRails()`, `shopVocabulary()`
- `resources/js/Components/EntityRails.tsx`
- `database/migrations/2026_09_05_001000_a_brand_is_a_cove_too.php`
- `tests/Feature/EntityRailsTest.php`

## Open

- **`PlanDrafter` refuses `brand`**, like advice and shop: nothing in the catalogue proposes which
  brand is worth writing about. The candidate list is not a mystery, though — it is the brands that
  already exist in a market and have no Cove yet, and a drafter arm for that is worth considering.
- **The popular rail includes the described shop's own chart.** Ordering `/shops/bol-com` by bol's
  ranks is the most exposed reading of the narrowed rule. `PopularRank.source` makes excluding it a
  one-clause change if attribution ever matters.

## See also

- [shop-coves.md](shop-coves.md) — the kind this one was modelled on
- [brand-pages.md](brand-pages.md) — the page a Brand Cove renders above
- [popularity-charts.md](popularity-charts.md) — the rule the popular rail narrows
- [cove-writer.md](cove-writer.md) — who writes it, and the prompt it is written from

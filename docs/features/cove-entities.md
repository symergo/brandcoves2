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

`/{market}/brand/{slug}` — the address that already exists, never a second one beside it.
[brand-pages.md](brand-pages.md) exists to argue for **one canonical indexable URL per brand per
market** — every brand mention on the site, on cards, in
facets, in generated Cove prose, points there — so a second address would split exactly the link
equity that page was built to consolidate. What changes on that URL is the *layout*, not the
address: see "The filtered search is the fallback" below.

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

## The filtered search is the fallback, and a written page is an article

**Where somebody has written about a brand or a shop, the writing is the page. Where nobody has,
the page is a search filtered to that entity.** Stated as a rule on 2026-09-06; it replaced a
first attempt the same day that merely suppressed the templated copy under a Cove.

| | Written | Unwritten |
|---|---|---|
| `/brand/{slug}` | the piece, products in a right sidebar | facets and a grid, no prose at all |
| `/shops/{slug}` | the same page shape | 404 — the directory row links to the filtered search |

The two render one component, `resources/js/Pages/Entity/Cove.tsx`, which is what makes them one
page shape rather than two that resemble each other.

### The grid stays reachable, and that is the constraint

Removing the grid from a written brand page would be indefensible on a shopping site if there were
no way back to the products. There are two, and they lead to the same place:

- the sidebar ends in **"see all N products"**, pointing at the search filtered to that entity;
- any narrowing word in the prose resolves to the same filtered search.

And `BrandController` only renders the article on the brand's **landing** URL. `isThin()` already
meant "this URL is not the landing page" — a filter, a sort, a page 2, a sub-search — and on any of
those the reader asked for results rather than for an article, so they get the grid. Without that
rule, writing about a brand would silently delete that brand's facets.

The search page filters on the brand **name**, so the link is built from `brandSpellings()`, not
from the slug: feeds disagree about punctuation, and a link built from `audio-technica` would land a
reader on an empty search from a page about a brand with hundreds of products.

### Where each rail goes

Decided by what each one claims rather than by how it looks.

| Rail | Where | Why |
|---|---|---|
| Biggest discounts | sidebar | a shelf beside the writing; eight small rows read fine in a column |
| Most popular | sidebar | the same, and it is a retailer's chart rather than our claim |
| On wish lists | **under the writing, full width** | the only first-party claim on the page — what *our* visitors want. Putting it in a column beside two rails borrowed from merchants states it more quietly than it deserves |

### The six generated sections are gone

`brand.below_grid` shipped six headed sections per language — *Over Samsung*, *Wat kosten
Samsung-producten?*, and four more — every clause assembled from the numbers in the grid above them,
identical in shape on every brand page. They were checkable, which is why they were publishable at
all; they were never worth reading, which is the test that decides whether a page should carry them.

`2026_09_06_000200_a_brand_page_stops_explaining_itself` deletes them. **The region survives and
now ships empty**, like `above_grid` beside it, because removing the *place* would take away
somebody's ability to write a real sentence there without a deploy. The words are still in
`database/migrations/data/page-blocks-2026-09.php` if they are ever wanted back.

## Both entity pages are templated

Six regions, editable at *Admin → Page templates* with no deploy — three on each page, and the same
three on both:

| Region | Renders |
|---|---|
| `above_prose` | between the heading and the first paragraph |
| `below_prose` | after the last paragraph, above the wish-listed rail |
| `sidebar` | between the product lists and the "see all" link |

`sidebar` is the narrowest column on the page and its blurb says so: two lines read well there and a
paragraph does not. It is where a note about the *products* belongs — that a discount is measured
against our own 30-day median rather than a crossed-out price, or where a popularity ranking came
from — which is a claim worth making beside the numbers it qualifies rather than three hundred
pixels away under the article.

Two page keys rather than one, although the layout is identical: the words differ even where the
shape does not. A band above a shop piece talks about buying from somebody; above a brand piece it
talks about what somebody makes. One key would force a sentence true of both, and the sentence true
of both is the sentence worth nothing.

**What is templated is the chrome, not the piece.** The Cove's own prose is written per entity, in
the planner or over the editorial API, and that is the reason the page exists. These regions are the
parts that are the same on every entity page in a market — a note on how the shortlist beside the
writing is chosen, a standing line about affiliate links — so they can change without editing forty
Coves. A place is a deploy; text is not.

`EntityCoveContext` supplies the facts, and its `$items` are **the sidebar**, not a page of results:
this page has none. The house rule holds and matters more here than anywhere — a claim is about what
the reader can see. `:count` stays the one exception, and it is what the "see all" link needs.

`:entity` is a new placeholder, deliberately one name for both kinds: a block naming `:brand` on a
shop page would be a sentence about the wrong kind of thing.

## Files

- `app/Enums/CoveKind.php` — `Brand`, `isEntity()`
- `app/Services/Cove/EntityRails.php`
- `app/Services/Ai/Prompts/Defaults.php` — `BRAND_SYSTEM`, `BRAND_PROMPT`
- `app/Services/Shops/ShopDirectory.php` — the shop slug rule and membership
- `app/Http/Controllers/BrandController.php` — `cove()`, `covePage()`, and the landing-page rule
- `app/Http/Controllers/ShopsController.php` — `coveSlugs()`, and where a directory row points
- `app/Http/Controllers/GuideController.php` — `entityPage()`, `shopRails()`, `shopVocabulary()`
- `app/Services/Shops/ShopDirectory.php` — the slug rule, membership, and `productCount()`
- `app/Services/Pages/Regions/EntityCoveRegions.php` — the six editable regions
- `app/Services/Pages/Context/EntityCoveContext.php` — the facts they may state
- `resources/js/Pages/Entity/Cove.tsx` — the page both kinds render
- `resources/js/Components/EntityRails.tsx` — the shelf, and `RailCard`'s row layout
- `database/migrations/2026_09_05_001000_a_brand_is_a_cove_too.php`
- `database/migrations/2026_09_06_000200_a_brand_page_stops_explaining_itself.php`
- `tests/Feature/EntityRailsTest.php`, `tests/Feature/BrandPageTest.php`
- `tests/Feature/EntityCoveTemplateAdminTest.php` — the pages reach the admin screen

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

---
name: Page templates
area: Content / SEO
status: Active
date_added: 2026-09-01
---

# Page templates

**A page has regions. A region is an ordered list of blocks an editor writes. There is nothing
underneath.**

Replaces the copy bank (`CopySlots` / `CopyBank` / `PageNarrative` / `copy_templates`), which is gone.

## What was wrong with the thing this replaced

The old system let an editor rewrite the words in a position and add rotating alternatives to it. The
*positions* were code: `CopySlots` declared about thirty-five of them, and their order, their guards,
and the fact that there were exactly three sections below a results grid and no place for prose
anywhere else on the page were all a deploy. "We should also explain returns here" was a developer's
afternoon.

It also had a floor. A slot with nothing in it rendered the sentence from `lang/{language}/site.php`,
which made the bank safe to hand over — and made it impossible to *remove* a paragraph. Switching
every variant off rendered the shipped version instead.

## The model

| | |
|---|---|
| **Region** | A place on a page. Code, because only code knows where in the markup a paragraph can go and which facts that spot can supply. `App\Services\Pages\Regions\RegionRegistry`. |
| **Block** | One position in one region **in one language**. `heading` or `paragraph`; carries conditions and an enabled flag. `page_blocks`. |
| **Variant** | One way of saying what a block says, with a weight. `page_block_variants`. |
| **Placeholder function** | A registered class answering a `:name`. Some return a word, some a linked list, one a whole widget. `App\Services\Pages\Placeholders\PlaceholderRegistry`. |

A heading opens a section; the paragraphs after it belong to it. That is the whole vocabulary —
sections are an *arrangement* rather than a nesting, which is why reordering is one integer and two
buttons instead of a tree.

### Language sits on the block, not on the variant

The alternative was one shared list of positions with four bodies hanging off each, and it forces a
property nobody asked for: translation parity of *structure*. Dutch and French prose do not decompose
into the same number of paragraphs. Worse, with no fallback a block whose French body is missing
renders nothing, so a shared list develops holes no screen shows you — twelve positions, five empty
textareas, and a French page quietly missing a third of its copy.

With language on the block the admin says **"0 blocks"**, and `PageRegionsTest` says it louder. The
cost is writing a region four times, which the **Copy from another language** action absorbs.

## The two guards

A sentence mentioning a number is making a claim about it. ":reduced products are below their median"
on a page where nothing is reduced does not read as a gap — it reads as "0 products", which is false.
So:

1. **Automatic.** A *phrasing* is skipped when any placeholder it names resolves to nothing. Each
   function declares its own `Absence` rule: `BlankOrZero` (the default, right for every count and
   price), `Blank` (where zero is legitimate), `Never` (where the region's own guard already promises
   the value). An empty link list obeys the same rule as a missing number, with no special case.
2. **Named conditions.** A *block* is skipped when a ticked condition is false, ANDed. These are the
   guards that used to be hardcoded: `$facts['comparable'] > 0 ? $this->line('compare_2') : null`
   became `['multi_shop']` on that block. An **unknown** condition key — left behind by a rename —
   fails closed, because a block whose gate has vanished must stop rendering rather than start
   rendering unconditionally on every page in the market.

### Filter first, then draw

Not the obvious order, and the difference is visible. Draw first and check second, and a block with
two phrasings — one naming `:percent`, one not — vanishes on a discount-free page roughly half the
time, depending on which one the hash picked. Filtering first means the block renders whenever *any*
phrasing can, and the weighting applies within the set that survived.

It also hands an editor "write a fallback phrasing that needs no number" for free, which is the most
useful thing this system offers them.

## Placeholder functions

The extension point. A placeholder is a class, not a key in an array the caller assembled — which is
what lets one of them run a trigram query, another build links through a service, and a third be
added next year without touching a block, the schema or the admin.

| Token | Level | Returns | Notes |
|---|---|---|---|
| `:term` `:brand` `:count` `:shown` `:shops` `:comparable` `:reduced` `:percent` `:low` `:high` `:brands` `:shop` `:category` `:categories` | inline | text | Read off the products **on this page**, never the whole result set — a reader can check a claim about twenty-four visible products and cannot check one about four hundred they will never see. `:count` is the one exception and says so. |
| `:brand_links` | inline | links | The brands in these results, each to its own page. Only brands that have one: a brand needs three products before it earns a page, and a link to a 404 from a sentence on every search page is the worst possible place for one. |
| `:term_links` | inline | links | The vocabulary of the results, each to a narrower search. Also rendered as chips above the grid, so putting it in `above_grid` shows the same words twice. |
| `:related_searches` | **block** | chips | "Verwante zoekopdrachten". A paragraph containing nothing else. |
| `:term_page_link` `:brand_page_link` | inline | links | The subject of the page, linked to the canonical page for it — and plain text on the page it would link to, because a self-link is noise for a reader and a wasted signal for a crawler. |
| `:search_link` `:brands_link` `:coves_link` `:guides_link` `:shops_link` `:gift_finder_link` `:search_help_link` | inline | links | Another part of the site, market-prefixed and labelled from the navigation's own strings. A hand-typed `/be-nl/coves` is right in one market and a 404 in four — and it is a 404 nobody notices. |

### A widget is not a third block kind

`:related_searches` draws a row of pills, which cannot legally nest inside a `<p>`. So a block-level
function is used as **a paragraph containing nothing else**, and the two-kind rule survives intact.
The admin validates it and `Parts.tsx` enforces it again at render.

### Editors never write markup

`:brand_links` emits anchors, and the tempting way to allow that is to let an editor type HTML. This
codebase has refused that once already, in as many words — *"what you never do with model output is
hand it to something that interprets markup"* — and the reasoning does not weaken because the author
is a colleague. An admin form that renders arbitrary markup is one stored `<script>` from being the
worst hole in the site, reached through the one screen we tell people is safe to hand over.

So a paragraph resolves to a **list of parts**, never a string:

```
Part = {t:'text',  v: string}
     | {t:'links', items: [{label, url}]}   // inline
     | {t:'chips', items: [{label, url}]}   // a block of its own
```

`resources/js/Components/Parts.tsx` is the only place a part becomes an element. A function returning
an existing shape is PHP only; one needing a *fifth* shape is PHP plus one branch there.

### Adding one later

1. A class implementing `PlaceholderFunction` — or, for a scalar, one more `new Fact(...)` in
   `Fact::all()`.
2. One line in `PlaceholderRegistry::FUNCTIONS` (or the family's `all()`).
3. Its name in the `placeholders` list of every region that offers it.
4. If it needs a fact nobody precomputes, one line in that page's `PageContext`.

No migration, no admin change — the editor's palette is rendered from the registry — and every block
already written can use it the day it ships.

**`dependsOn()` is what keeps step 3 honest.** A region offering `:brand_page_link` on a page whose
context has no brand does not render `:brand_page_link` to a reader: it hides every block that
mentions it, on every page, for ever, and nothing throws or logs.
`PageRegionsTest::every_offered_placeholder_has_the_facts_it_needs` turns that into a red build.

## Regions today

| Region | Where | Seeded | Required |
|---|---|---|---|
| `search.above_grid` | between the search box and the first card | no | no |
| `search.below_grid` | after the products, up to three columns | yes | yes |
| `search.empty_state` | under the "nothing found" line | yes | yes |
| `brand.above_grid` | between the brand heading and the first card | no | no |
| `brand.below_grid` | full width, after both columns | yes | yes |
| `brand.empty_state` | under the "nothing here" line | yes | yes |

### Which URLs may carry copy

`above_grid` and `below_grid` are suppressed on any page a crawler is told to ignore — page two, a
filtered URL, a brand sub-search, a re-sorted list. That is not an editorial rule: repeating several
hundred words across dozens of near-identical `noindex` URLs is the doorway-page pattern at scale, and
it is the reason `SearchController::isThin()` and `BrandController::isThin()` exist.

`empty_state` takes the **opposite** guard. It renders because the page is empty, and on `noindex`
variants too. The doorway argument is about what a crawler is shown repeatedly; that region is for the
reader, and a dead end is exactly where a way out belongs. This is why guards belong to regions rather
than to pages.

### `brand.above_grid`, against its own history

A brand page opened with ten slots of templated statistics — the `brand_intro` surface — until they
were deleted on 2026-08-10 for being arithmetic about the grid, written in sentences, identically on a
thousand pages. See [brand-pages.md](brand-pages.md).

Three things have changed and one has not. The mechanism cannot produce that any more: every word is
typed by a person and nothing assembles a sentence out of a number. It **ships empty**, so the
deletion is not being reversed — a place is being made available. And the availability rule means a
sentence naming a number vanishes where the number is absent, which was one of the named old failure
modes.

What has not changed is the content judgement — three hundred words between a shopper and the first
card is still a worse page — so the region's `blurb` carries that warning in the admin, where somebody
about to ignore it will read it.

## Adding a region to a new page

1. A class in `App\Services\Pages\Regions` with its regions, placeholders and conditions.
2. One line in `RegionRegistry::PAGES` — an explicit list, never directory auto-discovery: a rename
   should fail at boot, not silently retire a region and orphan its blocks.
3. A `PageContext` for that page producing exactly those facts and answering exactly those conditions.
4. The controller builds the context, calls `PageCopy::forRegion()`, and passes the result as a prop.
5. The page component renders it through `PageBlocks` (flow) or `BlockSections` + `PageNarrative`
   (columns).
6. `npm run build` and **commit the SSR bundle** — `bootstrap/ssr/ssr.js` is tracked.

Then adding a *place* is a deploy and adding *text* is not, which is the whole arrangement.

`PageRegionsTest::every_declared_region_is_rendered_by_a_page` exists because the opposite already
happened once: `brand_intro` stayed in the registry after nothing rendered it, so admin still listed
it, and an editor could rewrite copy, be told it saved, and see no change anywhere.

## Rotation

Ported unchanged from the copy bank, because it was right.

- **Across pages**: the seed includes the page's own identity — the term, the brand slug — so two
  pages drawing from the same three phrasings reliably get different ones.
- **Over time**: the period folds into the seed, so the corpus reshuffles on a cadence with nobody
  touching it. `COPY_ROTATION` = `weekly` (ISO week, the default) | `daily` | `monthly` | `static`.

**The env var keeps its name.** Renaming it is a one-line change that silently reverts both Coolify
apps to the default the moment they deploy, because the variable they hold no longer matches anything.

Not randomised per request, which is the obvious reading of "rotate" and the one thing that would
hurt: a page whose wording changes on every load cannot be cached, flickers for anyone hitting back,
and shows a crawler a different document on every fetch.

## Caching

`bc:page-blocks:{language}`, one key per language, TTL **3600** — only an admin save writes there and
the save flushes it. Memoised on the instance as well, because a render asks for three regions.

`PageCopy` is bound `scoped()`, so **`flush()` calls `app()->forgetInstance()` as well as
`Cache::forget()`**. The old `CopyBank::flush()` did not, and the symptom was an editor saving,
reloading and concluding the admin did not work: the Livewire round-trip that had just saved
re-rendered from the instance holding the copy it read a moment earlier.

The flush is owned by **the models**, not the screen — `PageBlock::booted()` and
`PageBlockVariant::booted()` — so a seeder, a tinker session and `bc:import-content` all trigger it.
That last one is why: the old bank was flushed by the admin page, and the importer is not one.

`PageCopy::read()` wraps the whole `Cache::remember` **call** in a try/catch, not just the query.
`package:discover` boots the app during the Docker build with no Postgres and no Redis, and the throw
arrives from the cache lookup several frames before anything touches the table.

## The FAQ, retired

Search and brand pages no longer emit `FAQPage` JSON-LD. Google narrowed FAQ rich results to a handful
of authoritative government and health domains in 2023, so emitting the same six templated questions
across thousands of near-identical URLs had stopped paying for itself.

**The prose survives.** The questions are seeded as ordinary headings with their answers as the
paragraphs under them, so a reader loses nothing — only the markup went. `StructuredData::faq()` stays
and `GuideController` keeps using it, fed by a per-Cove FAQ an editor writes by hand, which is where
questions are genuinely per page.

The door back is one boolean wide: because a question is already a heading with an answer under it, a
region could carry an `emitsFaq` flag and generate the `<dl>` and the JSON-LD from one source. The flag
is not built; the shape that would need it is.

## Seeding, and the migration path

`2026_09_01_000200_todays_page_copy_becomes_blocks` fills the new tables from two sources, in order of
who did the work:

1. **`copy_templates` rows**, where the environment has them — every variant with its weight and its
   enabled flag. Staging ran `bc:seed-copy` on 2026-08-09 and somebody may have rewritten a sentence
   since; an editor's afternoon is the one thing in that table that cannot be regenerated.
2. **`database/migrations/data/page-blocks-2026-09.php`** for every slot with no rows.

**The strings are in a data file, not read from `lang/`.** A migration must produce the same result on
a database created next year, and by then the `narrative.*` keys are gone — deleted in the same
release, which is the point of the exercise. `__('site.narrative.compare_1', [], 'nl')` works today and
returns the literal key after that, seeding a fresh environment with dotted paths where its copy should
be.

One case needs care and gets it: a slot whose every variant was retired or switched off used to render
the shipped sentence through the fallback. Carrying those rows across untouched would silently blank
the block, so the shipped line goes in as the drawable one and the editor's rows ride along beside it.

### What leaves, and when

Deleted in this release: `CopySlots`, `CopyBank`, `PageNarrative`, `CopyTemplate`, `SeedCopyCommand`
(`bc:seed-copy`), `EditPageCopy`, `CopyTemplateResource`, and the whole `narrative` and
`brand_narrative` blocks in all four language files.

**`copy_templates` itself stays for one release**, unread. Expand/contract: between here and the drop,
`main` reverting to the previous build gets its lang files back with the code and finds the table
intact and populated, and renders exactly as before. Dropping it now would leave that rollback meeting
a schema it cannot read.

> `2026_08_24_000100_drop_the_seeded_copy_that_only_shadows_the_language_file` was emptied rather than
> deleted. It has already run everywhere, so its work cannot be undone by emptying it — but it called
> `CopySlots::all()`, and a fresh database replays the whole history. That is not hypothetical: it is
> `RefreshDatabase`, on every test run.

## Content promotion

`ContentEnvelope` version **3**. `copy` retires and `blocks` replaces it, with variants nested inside
each block — a variant has no identity independent of its block, and `(page, region, language,
position)` looks like a natural key and is not, because position is exactly what an edit changes.

Import **replaces per `(page, region, language)`** rather than merging. An import means "make this
environment match the envelope"; a merge leaves behind blocks the author deleted on the far side, and
merged blocks would interleave two orderings into one nonsense.

A v2 envelope's `copy` rows are **dropped and named** in the report, never converted: every environment
is seeded identically by the release migration, so importing them would recreate the same sentences
under a different identity and print the region twice.

**The version check changed direction, deliberately.** It was `$version !== self::VERSION`, which is
backwards for what it protects: the question is whether *this build* can read *that file*, and it is a
file from a **newer** build that it cannot. The proof it was wrong is in the class — `importLegacyGuides()`
exists to fold a v1 envelope's guides into editions and had been unreachable dead code since the day it
was written, rejected three frames earlier by the equality check.

`bc:scrub` needs no change: page blocks hold no personal data.

The editorial API stays Coves-and-guides only. Its abilities exist so an agent can draft one article; a
page template is not one article, and a bad write changes every search and brand page in a market at
once. The blast radius does not match the token model.

## Tests

| File | Holds |
|---|---|
| `PageRegionsTest` | **The guardrail**: every required region has drawable blocks in all four languages. Plus the agreement tests — offered placeholders exist, resolve, and have the facts they need; conditions are answered; every stored block names a live region; every declared region is rendered by a page. |
| `PageTemplateTest` | The resolver: no fallback, the two guards, filter-then-draw, the rotation's determinism and its movement between periods, sections, and the `forgetInstance` regression. |
| `PlaceholderFunctionTest` | Data-not-markup, lazy resolution, the absence rules, the link functions, and that a newly registered function works in a block written before it existed. |
| `PageCopyRenderTest` | End to end: enough prose, every placeholder filled, nothing on a thin page, the empty state's inverse guard, no `FAQPage`, and the copy rule. |
| `PageTemplateAdminTest` | The screen: the three selects reload the list, a save renumbers positions from 1, the two validation rules bite, the palette lists what the region offers, and Copy-from-another-language reproduces a region. |
| `ContentPromotionTest` | Blocks round-trip; nothing personal leaks. |

## Two things worth knowing when working on this

**`->statePath('')` does not reset a field to the root.** It contributes nothing to the path, so a
field inside a `Section(...)->statePath('')` inherits the *form's* state path and renders as
`wire:model="data.region"`, not `wire:model="region"`.

This screen shipped with the three selects arranged that way and bound to public properties, with
Livewire `updatedRegion()` hooks meant to reload the list. The properties were never touched, the
hooks never fired, and picking a region in the browser changed the label and nothing else. They live
in the form state now, at `data.pageKey` and friends, and reload through Filament's
`afterStateUpdated`.

**It passed its tests the whole time**, because those said `set('region', …)` — which writes the
property directly, the one path the browser never takes. A test driving a path the product does not
have is worse than no test: it reports a feature works while somebody is looking at it not working.
`PageTemplateAdminTest` now drives `data.region`, and asserts the `wire:model` paths against the
rendered markup, because the fault was invisible from the component's API — the property existed, the
hook existed, and neither was connected to the control on screen.

The property is `$pageKey` rather than `$page` for an unrelated reason worth keeping: this is a
Livewire component and `$page` is spoken for in that neighbourhood by `WithPagination`. A property
colliding with a trait's does not error, it quietly stops behaving.

**SSR is off in local development, and always was.** `config/inertia.php` leaves `ssr.hot_url` unset,
so Inertia skips the SSR gateway whenever Vite is hot — which it is under `composer dev`, because
`public/hot` exists. An empty `<div id="app">` locally is expected and is not a regression. To check
server-rendered markup, POST a page object to `http://127.0.0.1:13714/render` directly, and remember
to rebuild the bundle (`npm run build:ssr`) and restart the node process first: the SSR server holds
the bundle it started with.

## Known gaps

- **No flat table.** The old system had `CopyTemplateResource` for searching across languages, spotting
  orphans and bulk delete. Worth adding back as a `PageBlockResource`.
- **Reordering is drag-and-drop**, not the up/down buttons `CuratePlan` uses. Defensible because
  nothing is written until Save and a mis-drop is undone by reloading — but it is a divergence.
- **`copy_templates` is still there**, unread, until the follow-up migration drops it.

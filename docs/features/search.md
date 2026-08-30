---
name: Search & offer comparison
area: Search
status: Partial — schema, functions and indexes built and verified; the service and UI are Phase 2
date_added: 2026-08-07
---

# Search & offer comparison

Postgres does the searching. No Elasticsearch, no Meilisearch — one fewer service to run, back up
and keep in sync, and the catalogue is well within what Postgres handles comfortably.

## Two mechanisms, because they fail differently

### 1. Full-text — ranked term matching

`products.search_vector` is a **stored generated column**, so the vector is computed once at write
time rather than on every search.

```
setweight(title,       'A')   -- a term in the title outranks
setweight(brand,       'B')   -- the same term in the brand, which outranks
setweight(category,    'C')   -- the same term in a merchant's category string, which outranks
setweight(description, 'D')   -- the same term mentioned somewhere in the copy
```

Two things this had to get right:

- **Stemming is language-specific.** A Dutch stemmer will not reduce `chaussures` to `chaussure`.
  `bc_text_config(market)` maps the market to `dutch` / `french` / `spanish` / `english`.
- **`unaccent()` is declared STABLE, not IMMUTABLE**, so Postgres refuses it in a generated column or
  an index. Pinning the dictionary explicitly (`bc_unaccent()`) makes the call genuinely immutable.
  Without this, `creme` cannot match `crème` — not optional in a catalogue spanning three languages.

Offers are indexed rather than groups: offers carry per-merchant titles and categories, so indexing
them gives better recall than the group's single denormalised title.

> **Careful:** `bc_search_vector()` is baked into a stored generated column. Changing that function
> does **not** rewrite existing rows. That needs an explicit backfill migration.

#### Descriptions, at weight D

Added 2026-08-10 by `2026_08_10_000500_add_description_to_the_search_vector`. `products.description`
had been written on every ingest since the catalogue existed and read by nothing — so every fact a
merchant stated about a product but left out of the title was unfindable. In the test fixture alone,
"ruisonderdrukking" appears in two descriptions and no title.

**D is the right weight, and the ranking does not actually read it.** Postgres' multipliers are
A=1.0, B=0.4, C=0.2, D=0.1, so a description mention is worth a tenth of a title mention — the
correct ratio. But `SearchService::orderByRelevance()` sorts on `word_similarity()` against the
*group's* title, not on `ts_rank`, because the vector lives on offers and ranking through it would
mean a correlated subquery per row. That works in our favour: a product matched only through its
description scores near zero on title similarity and lands at the back by construction. The weights
and the sort agree here by luck rather than design, which is why
`a_title_match_outranks_a_description_match` exists — it is what will catch the ordering breaking if
the sort ever moves to `ts_rank`.

**What got worse, deliberately.** Recall went up, so common words in descriptions ("draadloos",
"garantie") now match products they did not before. Result counts rise and the tail of a long result
set is weaker. That is the trade, not a side effect.

**The description is truncated to 2000 characters** in SQL, matching the cap
`BolConnector::description()` already applies at its own boundary. Without it one verbose Awin
advertiser contributes ten times more index than bol does for the same product. Applied in the
function rather than per connector so it also covers rows already in the table.

**Why the migration drops and re-adds the column.** Postgres 16 cannot alter a generation expression
— `ALTER COLUMN … SET EXPRESSION` arrived in 17 — so the column has to go and come back. That is not
a workaround with a cost attached; it *is* the backfill, and it is the answer to the warning above.
Re-adding recomputes the vector for every existing row, which `CREATE OR REPLACE FUNCTION` alone
never would. The price is an ACCESS EXCLUSIVE lock for a table rewrite plus a GIN rebuild — seconds
at tens of thousands of offers per market. Past a few million this wants revisiting as a second
column plus `CREATE INDEX CONCURRENTLY` and a switch.

### 2. Trigram — typo tolerance

**Query with the `<%` (word_similarity) operator, not `%`.**

`%` uses `similarity()`, which compares **whole strings**. Measured against a real product title:

| | score |
|---|---|
| `similarity('blutooth koptelefon', 'Draadloze Bluetooth Koptelefoon met ruisonderdrukking')` | **0.298** |
| `word_similarity(...)` same pair | **0.696** |

Postgres' default `similarity_threshold` is 0.3, so the whole-string comparison lands *just* under it
and a perfectly reasonable typo returns nothing. `word_similarity()` asks the question actually meant
— does this query match some run of words *inside* the title — and the same `gin_trgm_ops` index
serves both operators, so this is a query-side fix, not an indexing one.

`config('giftcoves.search.trigram_threshold')` is **0.45**: below Postgres' 0.6 word-similarity
default so single misspelled words still match, but not so low that unrelated products leak in.
Starting point only — it must be re-tuned against a real catalogue in Phase 2, because with two rows
in the table false-positive testing is meaningless.

**The threshold is a session setting, not a WHERE clause.** `AppServiceProvider` issues `SET
pg_trgm.word_similarity_threshold` on every Postgres connection, because `<%` compares against that
GUC and nothing the query can say. See the section below for why that matters more than it looks.

## Why the text predicate is shaped the way it is

**Rewritten 2026-08-16.** Search on staging measured **13–21s per query**; it is now well under a
second. Nothing about *which* results come back changed — the fix was entirely in how the same
question is asked. The four causes compounded, and no one of them was sufficient on its own.

Measured on staging before the change, and the shape of the evidence is worth keeping:

| Request | Before |
|---|---|
| `/be-nl/search?q=lego` | 13.4s |
| `/be-nl/search?q=zzzqqxnothing` (no results at all) | 10.8s |
| `/be-nl/search?q=1234567890128` (a GTIN — skips the text predicate) | **0.46s** |
| `/be-nl/search` (no term) | 0.82s |

The GTIN row is the one that localises the problem: it still calls the live connectors and still
renders the whole Inertia page, and it is fast. A zero-result query costing 10.8s says the same thing
from the other side — the time was spent *finding nothing*.

### 1. The tsquery config came from the row

`websearch_to_tsquery(bc_text_config(products.market), ?)` derives the dictionary from the scanned
row's own column. An index scan computes its search key **once** and then descends the index, so the
query-side operand has to be constant for the scan. This one is not: you would need the row in hand to
build the key you were going to use to find the row. Postgres therefore demotes it from `Index Cond`
to `Filter` and parses a fresh tsquery **per row**.

```
websearch_to_tsquery(bc_text_config(p.market), 'lego')   →  Filter, 23086 rows removed    820ms
websearch_to_tsquery(bc_text_config($1), 'lego')         →  Bitmap Index Scan               9ms
```

`bc_text_config` being `IMMUTABLE` does not help — immutability governs constant-folding, not whether
an expression references the scanned row. Binding the market as a parameter is enough, and it keeps
the language map in SQL: a PHP copy of it is a second source of truth that can disagree with the
generated column.

### 2. The threshold was written as a function call

`word_similarity(?, title) >= 0.45` is an ordinary function call in a comparison. No index answers it,
so it forces a sequential scan on its own. It existed only because `<%` reads its cutoff from a
session GUC defaulting to 0.6 while we want 0.45 — so the threshold moved to the session and the
clause was deleted. `<%` alone is now both indexable and correct.

### 3. One unindexable branch in an `OR` poisons all of them

`A OR B OR C` needs an index path for **every** branch before Postgres can union the bitmaps. One
branch without one means every row gets visited regardless — at which point the indexes the other
branches could have used buy nothing. The isolated trigram clause was a 30ms Bitmap Index Scan; the
identical clause inside the `OR` was part of a `Seq Scan … Filter`. The index was not disabled by the
`OR`, it was made pointless by its neighbours.

Fixing 1 and 2 is **not sufficient**, and this is the trap: a correlated `EXISTS` can never be a
bitmap branch, so the `OR` would still have forced the scan. The predicate is therefore a `UNION` of
two *uncorrelated* id selects — one per index — which is what lets each side use its own and lets the
result be hashed once instead of probed per row.

`applyTextMatch()` keeps its original signature so `facets()` can still apply it to a different base
query; the branches live in a subquery precisely so this stays a predicate rather than becoming a
pipeline every caller has to thread.

### 4. It all ran five times per page

`->paginate()` is two queries (a `count(*)` and the page), and `facets()` rebuilt the same predicate
three more times — brands, merchants, price. 13.4s ÷ 5 ≈ 2.7s each, and `view=store` adds a sixth via
`storeLanes()`, which measured +2.9s. That arithmetic closing is what confirmed the model.

Facets are now cached on `SearchQuery::facetCacheKey()` — market, term and in-stock only, because
those are the only inputs that reach them. They deliberately ignore the active filters so the sidebar
cannot erase its own options, which is exactly what makes every brand, price, sort and page variant of
one term share an entry. The cost is a sidebar that can trail the grid by `facet_cache_ttl`, since a
search folds live offers in and moves `merchant_count`.

### What this cost elsewhere

Lowering the session threshold to 0.45 widens **every** `<%` in the codebase, not just search's.
`SpectrumRetriever::anchor()` and `PageNarrative::related()` were written against Postgres' 0.6 and
now re-check against `trigram_threshold_strict` explicitly. Both answer "what is near this?", where a
loose match is a wrong neighbour rather than a forgiving typo — and the narrative's chips are rendered
as related searches on an indexable page, so a bad one is a link promising something the target does
not answer. The `<%` still drives the index; the re-check only narrows what survives.

The `word_similarity()` calls in `ORDER BY` — `orderByRelevance()`, `GuideBuilder`, `SlotsRetriever`,
`KeywordRetriever` — are unaffected. The GUC sets the operator's cutoff, never the function's return
value, so all ranking is untouched.

`SearchTest::a_typo_below_the_postgres_default_threshold_still_finds_the_product` is the regression
guard. It asserts the GUC arrived *and* searches `kopltelefon`, which scores 0.500 against the Sony
title: it matches at 0.45, misses at 0.6, and is not a word any dictionary stems, so full text cannot
quietly cover for the trigram branch and pass it for the wrong reason. The older typo test scores
0.818 and would pass either way.

## Offer comparison (Phase 2)

The plan, not yet built:

- Merge the stored index (Awin, ingested hourly) with a live bol query, Redis-cached 15 min.
- Live results are grouped into the stored graph **on the fly** — an incoming bol offer with a
  matching EAN joins an existing group and immediately becomes comparable.
- Results render as **group cards**: *"from €X · 3 offers across 2 stores"*.
- `store_lane_cap` = 8 per merchant in the "by store" view, so one recently-ingested merchant with a
  huge feed cannot monopolise every slot.

### The shop filter means two different things, and the by-store view needs both

**Fixed 2026-08-30.** Selecting a shop left the other shops' lanes on screen.

`storedQuery()` applies the merchant filter as an `EXISTS` over offers, which selects **groups**: a
product qualifies when *any* of its offers is from a selected shop. That is deliberate and stays —
the shopper is asking "who has this at Coolblue", not "which products are Coolblue-exclusive", so
the Krefel price stays on the card as the comparison the site exists to make.

`storeLanes()` then took those group ids and split **every** offer on them by merchant. The
qualifying groups drag all of their other offers along, and each of those became a lane, so a shopper
who deselected Krefel still got a Krefel column. The filter has to be applied a second time inside
the lane query, where it means "which shops get a column" rather than "which products qualify".

It is the same predicate reading differently by view: in the grid an unselected shop's offer is a
number on a card the visitor asked for; in lanes it is a whole shop they said no to.

The `PARTITION BY` cap runs after the narrowing, so a filtered view still shows up to
`store_lane_cap` per remaining shop rather than a share of it.

### The lane rows show the discount

**Fixed 2026-08-30.** `discountPercent` was on the payload — lanes and the grid are both built by
`SearchController::card()` — but the lane row is a bespoke compact `<li>`, not a `ProductCard`, and
simply never rendered it. The same result looked full-price in one view and reduced in the other.

It renders as a bare `−20%` trailing the price rather than the grid's badge over the image: the lane
is 224px wide and its thumbnail 56px, which leaves room for a suffix and none for a badge or a second
line. The visible text drops the translated ":percent% off" wording because it does not fit, so the
full phrase is carried on `aria-label` — otherwise a screen reader reads a price followed by an
unexplained negative number. The sign is U+2212, matching the Daily Cove and Discover, not a hyphen.

Making it visible surfaced a second thing: **a saving under one percent floored to `0` and rendered
as `−0%`**, a badge that claims nothing while looking exactly like one that claims something.
`ProductGroup::discountPercent()` now returns null there — the floor is still right, it is the zero
that was not a discount. Discover already carried a client-side `> 0` guard for this, which was the
symptom being patched where it showed rather than where it came from. `EditionPresenter` keeps a
guard of its own because it reads the `daily_picks.discount_percent` column the builder wrote, so it
still meets zeros stored before the fix.

### The lane headers carry the shop's mark

`storeLanes()` returns a list of `{merchant, groups}` rather than a name-keyed map, so the column
header can show a logo — and because two merchants from different sources are allowed to share a
display name, which a map would silently merge into one lane.

`Merchant::faviconUrl()` supplies it: `logo_url` when one was stored, otherwise a favicon guessed
from the merchant's own domain, and **never** the affiliate network's icon — Awin's mark on every
column identifies nothing, on the one view whose job is telling shops apart. Because the guess is
only a guess, the client hides the image `onError` rather than checking first; the name alone reads
as a shop, an empty box reads as a broken one. Measured on dev, two of five shops resolve a mark.

The header also **lost its product count**. The lane is capped at `store_lane_cap`, so a shop with
four hundred matches and one with exactly eight both said "8" — a number that described the cap
rather than the shop.

### The rail folds, and gets out of the way entirely in the by-store view

Brand and shop are up to 15 options each, so the two lists are thirty rows above the switches. Each
`Facet` is now collapsible, open by default — a filter nobody can see is a filter nobody uses — with
the count of its active options on the header, so a folded list cannot quietly hold something that is
changing the results. The open/closed state is not persisted: a remembered collapse would greet the
next search with a rail folded shut for reasons that no longer apply.

### The by-store view has no rail at all: the shops are the control

Every other view is a rail plus a grid, and the grid reflows — take 16rem off it and the cards get
narrower. Lanes do not reflow: they are fixed-width columns that scroll sideways, so the rail costs a
whole shop's column on precisely the view built to hold shops side by side. Measured at a 1440px
viewport, the results area goes from 832px beside the rail to 1128px without it.

Widening it exposed an older bug in the strip. A grid item's default `min-width: auto` is its
content's intrinsic width, and the strip's content is every column laid end to end — so the track
grew to hold all of them, the strip never became narrower than its contents, and its `overflow-x-auto`
had nothing to scroll. **The body scrolled sideways instead**, `document.body.scrollWidth` 1204 on a
390px viewport. `min-w-0` on the results section is the fix.

So this view drops the rail and gets **`ShopChips`** instead: a row of pills above the lane strip, one
per shop, each carrying the same mark as the column it governs. The chip and the column are the same
object a few pixels apart, so "drop this shop" is one click on the thing you want rid of rather than
a hunt through a checkbox list that looks nothing like it.

**Three chip states, not two.** "Shown because nothing is filtered" and "shown because you picked
it" are both true of a column but they are not the same claim, and drawing them alike made the
resting page a row of seven solid pills — a lot of ink to say *no filter is applied*, and it left
`All shops` no way to look like the state it is. So only a deliberate choice is solid: at rest the
shops sit quiet and readable and `All shops` is the one filled chip.

**Why a chip still counts as active when nothing is selected.** The underlying filter is a multi-select that
means *nothing* when empty, and an empty filter shows every shop. Drawn literally that is a row of
hollow chips directly above the evidence that all of them are on. So the chips render what is true of
the page, not what is in the query string. That makes the first click ambiguous and it resolves the
way the row reads: clicking a shop while everything is shown means **only this one**, not "all except
this one" — which would have to write every other shop into the URL and would silently exclude any
shop that appears later. Deselecting the last one returns to all, so no sequence of clicks can empty
the strip through this control alone. `All shops` is a chip rather than a "clear" link because it is
the same kind of thing as its neighbours: one of the row's mutually reachable states.

Everything that is *not* the shop axis — brand and the two switches — sits behind a **popover** at the
right of the same row, because none of it belongs to this view in particular. A popover and not a
block: opened as a block it pushed the whole lane strip down the page, so reading a filter cost you
sight of the thing you were filtering. Measured, the lanes now move 0px when it opens.
`FilterPanel` is shared with the rail so both render one definition, and `showShops` is false here —
a shop facet in the popover would be a second control for the state the chips already own.

### Shop names lose the country suffix

Feeds name an advertiser per country, so the catalogue holds `Coolblue BE`, `DreamLand BE`,
`Action BE-NL`. That suffix is the network's bookkeeping — which advertiser account the offers came
from — not part of the shop's name, and it tells the visitor something they already know: they chose
the market in the switcher and every price on the page is in its currency. Repeated down a row of
chips and again across every lane header it is noise, and it costs real width in a 224px column where
the name is already truncating.

`Merchant::displayName()` trims it, with `Merchant::withoutCountrySuffix()` as a static form for the
surfaces that read a name off a join (`$offer->merchantName` on the brand and wishlist pages) rather
than a hydrated model — otherwise the same shop is "Coolblue" in one list and "Coolblue BE" in the
next. Only a **trailing standalone two-letter code** goes, optionally bracketed and optionally a pair
joined by a hyphen; anything longer is a word and stays, so `bol.com` and `Bakker Hillegom` are
untouched, and a merchant whose entire name is a code keeps it rather than rendering as a blank
header.

Applied to the chips, the lane headers, the rail's shop facet, the product page's offer rows, the
brand and wishlist live offers, and the shops index. **Not** applied to `merchants.name` itself —
that keeps the feed's spelling, because it identifies the advertiser account when a feed is being
debugged — nor to `Api\CatalogueController` or `StructuredData`, which are data contracts rather than
menus.

### A related-term pill narrows the search without changing the view

The term pills under the search box were linked as `?q=` and nothing else, so clicking one from the
by-store view answered the narrower question back in the grid, and a chosen sort went the same way.
`SearchQuery::withTerm()` already states the rule — *filters, sort and view survive, because the
visitor chose those* — and `toArray()` already knows which of them are worth putting in a URL, so the
link is built from that pair rather than a second hand-rolled parameter list that would drift from
them. There are no filters to carry in practice: `terms()` returns nothing once any are set.

### Brand does not fold

`Facet` takes a `collapsible` prop, and brand passes `false`. It is the list a shopper actually came
to the rail for, and a control one click away from invisible is a worse default for it than a long
list is. The fold earns its place on shop, where the question is often already answered.

### The lanes are cards

Stacked as bare bordered rows under a hairline heading, nothing said where one shop ended and the
next began except the gap between them — and on a strip that scrolls sideways, the reader loses which
column they are in. Each lane is now one surface: rounded, bordered, with the shop's name and mark
banded across the top on `bg-cream`, and the rows inside divided by a hairline rather than boxed
individually (inside a card that already has an edge, a border per row is three nested outlines in
224px). The price took the weight and the title gave it up, because in a column of one shop's stock
the price is the thing being compared. No new colours — all existing palette tokens.
- Every query is logged to `search_log` — that table is the input to [buying-guides.md](buying-guides.md).

### What the live half is actually asked

**Changed 2026-08-11.** The live sources used to fire on `$query->term`, which meant they never fired
on a brand page — that page filters and does not search. They now fire on `$query->liveTerm()`, which
defaults to the term and which a brand page sets to the brand's name, because none of these APIs
takes a brand filter. See [brand-pages.md](brand-pages.md) for the whole mechanism, including
`BrandAttribution`, which fills in the brand bol never sends and without which those offers would be
stored and then hidden.

Two things changed for the search page as a side effect:

- **The fold is throttled** to once per (market, live term) per `live_cache_ttl`, keyed on
  `SearchQuery::liveCacheKey()`. The connector was already answering from its own cache; the upsert
  and the three grouping statements ran every time regardless. The offers stay folded long after the
  marker expires, so a throttled request renders the same page.
- **A source that may not be mirrored is no longer written.** `Source::allowsCatalogueStorage()`
  splits the live connectors, and Amazon's offers come back on `SearchResult::$liveOffers` for the
  page to render live. The rule was documented as enforced and was not enforced anywhere — nothing
  was deciding what to hand `OfferUpserter`.

## Above the grid: the vocabulary, not the statistics

**Changed 2026-08-10.** A results page used to open with four paragraphs read off the results — how
many products, across how many shop listings, the price range, how many were below their 30-day
median, how many were sold by more than one shop. Every clause was checkable and the whole block was
a description of the grid printed directly above the grid. On a phone it was most of a screen between
the shopper and the first card.

What is there now is one row of links: the words that recur across the titles on the page, each
adding itself to the query — `?q=koptelefoon` plus `over-ear` becomes `?q=koptelefoon over-ear`.

- **Extracted, never generated.** `App\Services\Seo\ResultTerms` counts words in the titles the page
  actually holds. Asking a model for "related keywords" invents plausible words the page does not
  contain — keyword stuffing *and* a lie about the contents. The reasoning, and every exclusion rule,
  is in [brand-pages.md](brand-pages.md#resultterms-extraction-not-generation).
- **Added to the query, not swapped in for it.** *Changed 2026-08-11.* The link used to carry the
  bare word, so that `over-ear` reached the whole category. That was wrong about what the click
  means: somebody reading "over-ear" under a page of headphones is refining, not restarting, and a
  fresh search discards the word they typed. Because the next page's suggestions are read off the
  titles that survived, every one of them is a word the result set can still answer — the path
  narrows and cannot dead-end. Widening is what the search box is for.
- **Empty on any `noindex` variant** — filtered, sorted, paginated. One block of internal links
  repeated across dozens of near-identical URLs is the doorway-page pattern.

The brand page carries the identical row, narrowing the brand page itself rather than leaving for a
search, with the brand name passed as the query so it cannot list itself. It pays for the resulting
URL space in two ways a search page does not have to — see
[brand-pages.md](brand-pages.md#what-the-accumulation-costs-and-where-it-is-paid).

The long copy *below* the grid is untouched and still carries the page's facts. Below, not above:
several hundred words between a shopper and the first product is a worse page for them, and Google
has said for years that it is a worse page for it too.

## The order of the filter rail

*Changed 2026-08-16.* Brand first, then shop, then the two switches — discounted, and in-stock last.

The rail used to open on three switches, with the brand and shop lists below them. On a phone the
rail is a collapsible panel, so those switches were the whole of it until you scrolled: the two
controls a shopper actually reaches for were the two they could not see. The switches only trim a set
the facets have already chosen, so they belong after it.

**"Available from several shops" is gone from the rail.** It asked the shopper to think in the
schema's terms — `merchant_count > 1` is an artefact of how many feeds happen to carry a product, not
a property of the product — and the answer is already on every card that has it. `comparable=1` still
works as a query parameter and `SearchService` still honours it (`SearchTest` covers it), so existing
links and the guide builder are unaffected; there is simply no longer a control that sets it.

## A query in flight says so

Every visit that replaces the result set — Enter, the Search button, a filter, the sort, a page —
raises a 2px beam that sweeps back and forth along the bottom edge of the search input, inside the
border, until the visit lands.

Why it exists at all, given Inertia already draws a progress bar: that one is pinned to the top of
the *window*, and a filter clicked from halfway down a long results page happens off-screen. The page
meanwhile still shows the previous results, so there is nothing at the point of interaction to say
the control did anything — and a control that looks like it did nothing gets clicked again.

Decisions worth keeping:

- **A scanner sweep, not a progress bar.** The beam is `animation-direction: alternate` with an
  `ease-in-out` timing function, so it decelerates into each turn and comes back the way it went. A
  loop that vanishes off one edge and reappears at the other reads as a conveyor belt; this reads as
  a head passing over the field, which is the same idiom as the barcode scanner sitting next to it.
- **Faded at both ends, not a hard-edged block.** A solid rectangle sliding back and forth looks like
  an object being dragged. A `from-transparent via-accent to-transparent` gradient has no edges, and
  the identical motion then reads as light.
- **The travel distance and the beam width are one decision.** `w-1/4` in the markup and
  `translateX(300%)` in the `scan` keyframes multiply out to exactly the track width. Change one
  without the other and the beam either overshoots the field or never reaches the corner.
- **Indeterminate, never a percentage.** Nothing reports how far along a query is. A synthetic bar
  that crawls to 90% and waits is a worse lie than no number, and a sweep makes no claim about
  progress at all — only about work.
- **Every visit, one flag.** `go()` owns the `onStart`/`onFinish` callbacks, so filters, sort and
  pagination all get the beam and no call site can forget it. `preserveState: true` is what makes it
  work — the component survives the visit, so the same instance that raised the flag lowers it.
- **Absolutely positioned.** Reserving 2px of layout would shift the whole results grid on every
  interaction — more disruption than the thing being reported.
- **Dimmed, not disabled.** A disabled submit button drops keyboard focus mid-search. The extra click
  it would have swallowed is harmless: Inertia cancels the in-flight visit and starts the new one.
- **`prefers-reduced-motion` gets a still bar, not nothing.** The message is "working"; motion carries
  it for everyone else, and here the mere presence has to. The override is unlayered CSS so it beats
  the Tailwind utility, which lives in a cascade layer.
- The beam is `aria-hidden`; a `role="status"` region carries `search.searching` in words. It is
  rendered empty rather than unmounted — a live region must exist *before* its content changes for a
  screen reader to announce it.

## Files

- `database/migrations/2026_08_07_000700_add_search_indexes.php`
- `config/giftcoves.php` (`search.*`)
- `resources/js/Pages/Search.tsx` (the box, its scanner beam, the term links)
- `app/Services/Seo/ResultTerms.php` (what the term links are built from)
- `resources/css/app.css` (`--animate-scan`)

## Verification

```sql
-- language-aware stemming
SELECT title FROM products
WHERE search_vector @@ to_tsquery(bc_text_config(market), 'koptelefoon');

-- accent folding: finds "réduction"
SELECT title FROM products
WHERE search_vector @@ to_tsquery(bc_text_config(market), 'reduction');

-- typo tolerance: use <%, not %
SELECT title, word_similarity('koptelefon', title) FROM products
WHERE 'koptelefon' <% title;
```

All three verified passing 2026-08-07 against a two-row fixture.

## The vocabulary row above the results

`ResultTerms` counts the words genuinely present in a page of titles and offers the most
characteristic ones as chips that *narrow* the search rather than replacing it. Reworked
2026-08-16, for two defects that both made the row read as machine output.

**Common words survived, in the wrong language.** The stopword list was chosen by the *market* —
`STOPWORDS[$market->language()]` — while product titles are whatever the feed wrote. A Belgian feed
is full of "Wireless Bluetooth Headphones with Noise Cancelling", so on `be-nl` the English function
words were never filtered and "and", "with" and "for" were among the most frequent words on the
page. Every language's list now applies at once; they are disjoint enough that unioning them costs
nothing real.

**Every chip was a single word.** "noise" and "cancelling" as two chips is one idea chopped in half,
and clicking either narrows the page by half a concept. Adjacent pairs are now counted alongside
single words, and a phrase **absorbs** its own words once it accounts for 60% of their occurrences.
Three rules keep that from inventing language:

- A stopword, a number or a short token **breaks the run**, so "Headphones with Case" never yields
  "headphones case" — a phrase the page does not contain is exactly the invented vocabulary this
  class exists to avoid.
- **Phrases may not overlap.** "draadloze koptelefoon" and "koptelefoon model" share a word and are
  one idea chained; the strongest wins and the rest is dropped.
- **A brand is a stopword.** A title is overwhelmingly "Brand Attribute Noun", so without this the
  strongest phrase on a page is routinely the brand plus whatever follows it — "Aurex draadloze",
  which nobody would type. Brands already have their own filter and their own pages.

One consequence worth knowing when reading the tests: the query's own words break a run too, so
searching "koptelefoon" yields "draadloze" as a word while the *brand* page for the same products
yields "draadloze koptelefoon" as a phrase. Both are right — the difference is which word is already
on the page you are standing on.

`extract()` no longer takes a `Market`. It took one only to pick the stopword list, and picking one
was the bug.

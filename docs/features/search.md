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

`config('brandcoves.search.trigram_threshold')` is **0.45**: below Postgres' 0.6 word-similarity
default so single misspelled words still match, but not so low that unrelated products leak in.
Starting point only — it must be re-tuned against a real catalogue in Phase 2, because with two rows
in the table false-positive testing is meaningless.

## Offer comparison (Phase 2)

The plan, not yet built:

- Merge the stored index (Awin, ingested hourly) with a live bol query, Redis-cached 15 min.
- Live results are grouped into the stored graph **on the fly** — an incoming bol offer with a
  matching EAN joins an existing group and immediately becomes comparable.
- Results render as **group cards**: *"from €X · 3 offers across 2 stores"*.
- `store_lane_cap` = 8 per merchant in the "by store" view, so one recently-ingested merchant with a
  huge feed cannot monopolise every slot.
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
- `config/brandcoves.php` (`search.*`)
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

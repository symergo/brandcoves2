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

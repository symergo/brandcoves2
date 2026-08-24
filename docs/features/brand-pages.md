---
name: Brand pages and on-page editorial
area: SEO / Discovery
status: Built
date_added: 2026-08-08
---

# Brand pages and on-page editorial

**Every brand with three or more products gets one canonical, indexable page: a search with the
brand preselected, with the brand's own vocabulary above the grid as links and prose below it built
entirely from numbers the catalogue can back up.**

## The problem this solves

A comparison site has two kinds of page and only one of them ranks.

- **Product pages** rank. They are specific, they have structured data, and there is one per thing.
- **Search results** do not. `?q=koptelefoon&brand[]=Sony` is almost pure markup — titles, prices, a
  filter rail — with nothing on it for a crawler to understand the page as being *about*. It is also
  `noindex` on purpose, because a facet UI generates a combinatorial explosion of near-identical URLs
  and a crawler left loose in one spends its entire budget there.

The consequence is that every brand mention on the site — on cards, in facets, in generated Cove
prose — pointed at a URL we had explicitly told search engines to ignore. Thousands of internal links
into a hole.

A brand page is the missing destination: **one URL per brand per market**, indexable, that the filter
and sort variants canonicalise back to.

## It is a search page

`/{market}/brand/sony` runs the same `SearchService` with the brand preselected and renders the same
cards. Deliberately not a parallel implementation — the filter rail, the sort, the pagination and the
offer comparison all already exist and all already work, and a second copy would drift within a
month.

What it adds is what a filtered search cannot have: a canonical URL, and links out to editorial.

Above the grid there is one row of the brand's own vocabulary, as links — see
[The statistics came off the top of the page](#the-statistics-came-off-the-top-of-the-page). Below it
there are articles that mention the brand. The generated paragraphs that used to fill that space went
on 2026-08-16 — see [The long copy below the grid is gone](#the-long-copy-below-the-grid-is-gone).

The brand facet is absent from its filter rail, because filtering a Sony page by brand is a control
with one option.

The rail carries the search page's ordering, changed with it on 2026-08-16: the shop facet first,
then the discounted and in-stock switches, then the related-brand links. The "available from several
shops" checkbox is gone from both rails — see
[search.md](search.md#the-order-of-the-filter-rail).

## The live sources are asked too

**Added 2026-08-11.** A brand page showed the stored Awin index and nothing else, because the live
half of `SearchService` fires on a search **term** and a brand page has none. bol carries a great
deal that no Awin advertiser does, and it was invisible on the one page dedicated to the brand.

The fix is one line of intent and several of care: **a brand page is a keyword search upstream and a
filter downstream.**

### Why a keyword search and not a brand filter

Neither bol's catalogue API nor Amazon's PA-API takes a brand parameter on the endpoints we use.
Both take a search term. So the only question that can be asked about Kärcher is the word "Kärcher",
and what comes back is approximate in a way the SQL half never is.

`SearchQuery::$liveTerm` carries it. Null means "the same as the term", which is the ordinary search
page; a string is what a brand page sets; the empty string is *ask nobody*, which is distinct from
null because null would start querying again the moment a visitor typed something.

A term typed into the box on a brand page is prepended rather than dropped — `Kärcher
hogedrukreiniger` is a better question for bol than either word, and it is the question the visitor
actually asked.

### Only on page one

Page two of a brand's catalogue is `noindex` and is read by someone who has already scrolled past
everything a live source could add. Across thousands of brand pages, asking again is a request per
page per crawl for nothing.

### The fold runs once per cache window

Writing is the expensive half: an upsert plus three grouping statements, on the pages that are the
crawl target for the entire site. `SearchQuery::liveCacheKey()` — which existed and had no caller
until now — is the marker: **one fold per (market, live term) per `live_cache_ttl`.**

The offers stay folded in the database long after the marker expires, so a throttled request renders
exactly the page a folded one would. This also covers the search page, where the connector was
already serving a cached payload while the write ran every time.

### `BrandAttribution`: the part that makes it work at all

bol's catalogue API returns **no brand field**. `BolConnector::normalise()` sets it null on purpose —
a wrong brand is worse than none, because grouping and the brand facet both key on it.

Left null, a bol offer is fetched, stored, grouped, and then hidden by the brand page's own
`whereIn('brand', ...)`. The request would be paid for and thrown away. So the brand is worked out
from two kinds of evidence, in order:

1. **Another source already named it.** The join is on `identity_key` where `identity_kind` is `ean`
   — the normalised, checksum-validated GTIN — **not** on the raw `products.ean` column, which holds
   whatever the feed wrote: a UPC-A, a GTIN-8, the same number with hyphens. Two shops listing one
   product agree on the second and routinely disagree on the first. If a feed says the thing behind
   4548736112513 is a Sony, then bol's unbranded listing of it is a Sony. That is a lookup, not an
   inference, and it works on the ordinary search page too, where there is no brand to compare
   against.

   The lookup spans **every market**, deliberately. Market scoping exists because tax, shipping and
   stock differ per market — a brand name is none of those, it is a property of the physical object.
   The markets are enumerated rather than the column dropped, because `products_identity_idx` leads
   on `market` and an unqualified query would sequentially scan the catalogue on a request path.

2. **The title leads with the brand we asked for.** Only on a brand page, only for what step 1 could
   not settle, and only at the **start** of the title. bol titles are written "Brand Model —
   description"; the accessories that pollute a brand search are written the other way round, and
   *"Hoesje voor Sony WH-1000XM5"* is a case made by somebody else. A contains-match would file it
   under Sony. Anchoring at the start costs a few genuine matches whose title leads with the category
   and refuses the entire class of third-party accessory a brand page must not claim.

Folding is done on `Str::ascii()`-normalised text for the same reason `brand_stats.slug` is:
"Kärcher" and "Karcher" are one brand and Postgres cannot fold them the way PHP does. Two characters
is too short to anchor on, so a brand below three characters simply gets no live offers — the safe
failure.

An offer that already carries a brand is never touched by either step. The spelling stamped is
`aliases[0]`, which `BrandStats` writes as the most-stocked variant, so it is the same string the
page's own heading uses.

### Amazon: shown, never stored

Amazon joins the moment its connector is registered, with no change to `BrandController` — the
registry decides which sources exist. The one thing that differs is handled in `SearchService`:
`Source::allowsCatalogueStorage()` splits the live sources into the ones that may be mirrored and the
ones that may not, and Amazon's offers are handed back on `SearchResult::$liveOffers` to be rendered
from the request's own fetch.

This is the call site [amazon-compliance.md](amazon-compliance.md) names for search, and it was not
enforced anywhere before: `OfferUpserter` writes what it is given, and nothing was deciding what to
give it.

They render in their own section below the grid rather than mixed into it, and the separation is the
honest one. Every card in the grid is a physical product with every shop's price beneath it, because
those offers are stored and grouped. These are grouped with nothing — no offer count, no shop count,
no discount against a 30-day median, because all four are things the catalogue computes for rows it
holds. Rendering them through `ProductCard` would mean inventing them.

The price note and the direct anchor are read off `Source::requiresPriceTimestamp()` and
`requiresDirectLink()` rather than hard-coded, so a second such source inherits its own answers. The
throttle does **not** apply to them: there is nothing durable to show, and freshness at render is the
condition of being allowed to display them at all.

### `?brand[]=` cannot override the path

`SearchQuery::withBrand()` **replaces** the brand filter rather than adding to it. Allowing both would
let `/brand/sony?brand[]=Philips` render a page whose copy talks about Sony and whose results are
Philips — wrong in the specific way that is hard to notice and impossible to defend.

## The statistics came off the top of the page

**Updated 2026-08-10.** A brand page used to open with four templated paragraphs: the product count,
the categories, how many shops carry the brand, the price range, how many items sat below their
30-day median. The search page opened with the same block, phrased for a query instead of a brand.

Both are gone from above the grid. Not because any of it was untrue — the whole point of
[the copy rule](#the-copy-rule) is that none of it could be — but because a block of numbers
describing the grid immediately beneath it is a paragraph nobody needs. Someone who typed a brand
name came to see that brand's products, and on a phone the statistics were most of a screen between
them and the first card.

**The facts did not disappear.** They live in the long copy below the grid, which is where a reader
who wants them goes looking, and which is what a crawler reads the page as a document from. The copy
rule is unchanged and still governs every sentence there.

### What replaced it: the vocabulary, as links

`ResultTerms` extracts the words that recur across the titles on the page — `noise cancelling`,
`over-ear`, `cordless` — and each one renders as a link to `/{market}/search?q=<word>`.

It survived the cut because it was the one part that was *not* a restatement of the grid. A count of
products describes what the reader can already see; the vocabulary says what **kind** of thing the
page holds, which the titles only imply. And as links the words do a second job prose could not: each
is a live query into the same index, so the long-tail vocabulary of a category becomes navigation
instead of a comma-separated sentence.

The brand's own name is passed to `ResultTerms` as the query, which makes it a stopword for that
page, so a Kärcher page cannot list "kärcher" as one of its own related terms.

#### They narrow this page, and they accumulate

**Changed 2026-08-11.** The link used to carry the **bare word** — `/search?q=pressure washer`, not
the word plus the brand — on the reasoning that the bare word reaches every brand that makes one,
which is the comparison the site is for.

That was wrong about where the reader is standing. Somebody on a Kärcher page looking at the word
"hogedrukreiniger" is not asking to be shown Bosch; they are asking **which Kärchers are the pressure
washers**. The old link answered a question nobody on that page had, and threw away the brand they
had already chosen. A word under a brand heading reads as a filter, so it now behaves like one:
`/brand/karcher?q=hogedrukreiniger`.

And each click **adds** its word rather than swapping the last one out — `?q=hogedrukreiniger`, then
`?q=hogedrukreiniger accu`. The next page's suggestions come off the titles that survived the
previous click, so every one of them is a word the current result set can still answer: the path
narrows and cannot dead-end in zero results. Widening is what the search box, the related-search
chips under the narrative, and every card's own links are for.

The same change applies to the search page, where the base is the query instead of the brand:
`?q=koptelefoon` plus `over-ear` becomes `?q=koptelefoon over-ear`.

Because there is no search box on a brand page, the active words are shown back as removable chips
above the grid. Without that the only way out of a sub-search is the browser's back button, which is
not a control a page gets to rely on.

#### What the accumulation costs, and where it is paid

A combinatorial URL space, over the pages that are already the site's crawl target. Two guards:

- **Every `?q=` variant of a brand page is `noindex, follow`** and canonicalises to the bare page.
  This is the same trap `/search?brand[]=` is noindex for. It also carries no narrative, for the same
  reason a paginated variant does not.
- **Only the canonical brand page queries the live sources.** A crawler walking the chips would
  otherwise fire one bol search per URL, and bol's rate limiter is a shared bucket — background
  crawling would be starving live visitors of it. Little is lost: the bare page's pull has already
  folded bol's offers for the brand into the index, and a sub-search filters that index.

Term links are still empty beyond page one. Repeating one block of internal links across every page
of a brand's catalogue is the doorway-page pattern with fewer words.

### `BrandCopy` is gone, and so is its copy-bank surface

**Completed 2026-08-11.** The service, its `brand_intro` surface, the *Brand page — opening
paragraphs* editor and the shipped `lead_1..4` variants are all removed. Nothing rendered them after
the term links replaced the statistics, and the half-retired state was worse than either end of it.

**Why it could not be left standing.** `CopySlots` still declared the surface, so `/admin` still
listed it and its re-seed button imported the rows straight back — onto production, where they had
never existed. An editor could then rewrite variants and see no change on any page: work silently
discarded by a form that reported success.

**The stored rows are gone.** `2026_08_10_000600_remove_the_retired_brand_intro_copy` deletes every
`copy_templates` row on the surface. Read from the environments before writing it: staging held 56 of
them — 14 per language, all from one `bc:seed-copy` run on 2026-08-09, none edited since — and
production's `copy_templates` was empty, so it is a no-op there. No editor's work was discarded, which
is the one thing in that table that cannot be regenerated.

The `CHECK` constraint on `copy_templates.surface` still permits `brand_intro`, deliberately. It is
the only part of this a rollback could not survive: an older `bc:seed-copy` writes those rows, and it
would fail against a constraint that had stopped accepting them.

**What moved in the tests.** `CopyBankTest` used `brand_intro.lead` as its worked example in 26
places; those now use `brand.about_3`, which is the equivalent slot on the surviving surface — always
rendered, common placeholders only, so the placeholder-validation cases still say what they said. One
assertion genuinely changed rather than moving: seeding used to import **four** rows for that slot
because `lead` shipped four alternatives, and nothing ships alternatives any more, so it imports one.

The four `BrandPageTest` cases that pinned the copy rule now read the rendered `narrative` prop. That
is the third home for them — page prop, then `BrandCopy`, now the page prop again — and it is the
right one: the rule is about what a reader is shown, and asserting it against a service proves
nothing once no page calls that service.

## The copy rule

Still the rule for every sentence the site generates. It now governs the long copy below the grid
rather than an intro above it.

**Every sentence is a fact the page can back up.**

"Looking for Sony? Coolblue currently has 14 Sony products reduced, the largest by 31%" is emitted
only when `top_merchant_id` is Coolblue, `discounted_count` is 14 and `best_discount_percent` is 31.
Nothing is written unless the number behind it exists.

That constraint is not only an ethical one. It is what separates this from the generated brand pages
every affiliate site has had since 2009 — *"Looking for Sony? We have a wide range of Sony products at
great prices!"* — which rank for a fortnight and then not at all, and which drag a whole domain down
when a helpful-content update decides the site is mostly filler. A page stating real, checkable,
changing numbers about a live catalogue is not filler, even when the sentence shapes are templates.

Discounts are measured against **our own 30-day median**, never a merchant's crossed-out figure. The
strikethrough is marketing; the median is evidence. Same arithmetic as
`ProductGroup::discountPercent()` — floor, never round — because a badge and a sentence that disagree
about whether something is reduced is worse than neither.

`BrandPageTest` pins this: a brand whose median equals its minimum must produce copy containing no
percentage at all.

### Why templates and not AI

These pages number in the thousands and their numbers change nightly. Generating them with a model
would mean either regenerating thousands of pages a night — unaffordable, and forbidden from a request
path by the [AI invariant](ai-invariant.md) — or letting the prose drift out of sync with the prices
it quotes, which is the worse failure.

So the split is: **facts are templated, creativity is linked to.** The AI-written Coves that mention a
brand appear on its page, and that is where the personality lives.

### Why the opening line rotates

Four `lead_*` variants per language, chosen by `hash(brand) % 4`. Thousands of pages opening with one
identical sentence is a pattern a crawler sees in a single sample. The variant is a hash rather than a
random draw so the page does not reword itself between two crawls, which reads as instability rather
than variety.

## The copy is about the brand, not about the pricing

Every sentence on a brand page used to be about price: ranges, 30-day medians, how many shops we
track, why comparing matters. All true, all backed by a number — and none of it an answer to the
question the reader asked by typing a brand name into a URL. Someone landing on `/brand/karcher`
wants to know what Kärcher is; they were given three paragraphs on how we measure discounts.

The order now follows the question. **What they make**, then **where it is sold**, then **what it
costs**. Price did not go anywhere and is still every bit as checkable; it stopped being the subject.

### The fact that made it possible

`top_category` answers "mostly what?" and nothing else, so the only descriptive sentence the page
could write was one word long. `brand_stats.categories` stores the spread instead — up to four
categories with their counts, most first — and three of them describe a brand in a way no number
does: *pressure washers, vacuums and garden tools* is a description; *€39 to €1,299* is not.

Ordered by count and then by name, so a tie does not reshuffle the sentence between two nightly runs
and read as a page that keeps changing its mind.

### What it still refuses to say

The rule is unchanged and is the whole reason these pages are worth having: **every clause is a fact
the catalogue can back up.** So the copy says what a brand sells *in this market*, and says so
explicitly — a name can be a household one in a category it barely sells in a given country, and
claiming the worldwide catalogue from evidence of the local one would be exactly the invented
sentence this class exists to avoid.

A brand in a single category gets a different line from one with a range, because a list joiner given
one item renders a bare word and reads as a truncated sentence.

Still no AI. These pages number in the thousands and their facts change nightly; see the reasoning in
`BrandCopy`'s docblock, which the new sections do not alter.

## `brand_stats` is the whole page

One row per (market, brand), refreshed nightly by `RefreshBrandStats` after grouping — grouping is what
produces the cheapest price and the median the copy quotes. The page itself aggregates nothing: a URL
that exists to be crawled thousands of times must not put a `GROUP BY` on its critical path.

### The stored slug

`brand_stats.slug` is written by the same `Str::slug()` that builds the links. Two reasons it is not
computed:

1. **In SQL it would be wrong.** `Str::slug()` folds "Kärcher" to "karcher"; `lower(replace(...))` does
   not. The link and the lookup would disagree and every Kärcher link would 404.
2. **In the browser it would be wrong differently.** Slugifying client-side also links confidently to
   brands that have no page.

Unique on `(market, slug)`, not globally — "Bosch" is a different row per market because its
catalogue, prices and merchants differ per market, exactly as product identity does.

### One row per slug, not per feed spelling

The first refresh against the real catalogue failed on
`(be-nl, audio-technica) already exists`. An Awin feed calls it "Audio-Technica", bol calls it
"Audio Technica", and `Str::slug()` correctly folds both to the same thing. The unique index turned a
silent wrong answer into a loud failure on the first run, which is what it was for.

Two fixes were rejected before the third:

- **Disambiguate the slugs** (`audio-technica-2`) — two pages that are each half a brand, one of which
  nobody will ever link to, and a URL that depends on which spelling had more products this week.
- **Normalise the feed at ingestion** — the feed value is what the merchant said, it is what the
  search index is built from, and rewriting it throws away the ability to tell which merchant spells
  it which way. Ingestion should record; presentation should decide.

So the **slug is the identity** and the spellings are aliases of it. One row per `(market, slug)`,
holding the most-stocked spelling as the display name and the rest in `aliases`, and the brand page
filters on all of them. `/brand/audio-technica` shows every Audio-Technica product however the shop
that listed it chose to punctuate.

That is strictly more correct than the naive version rather than a workaround for it: a reader
searching for a brand does not care about a hyphen, and a comparison site that shows them half the
offers because two feeds disagree about punctuation has failed at its one job.

The display name is chosen by product count with alphabetical tie-breaking, so it is stable across
runs — an unstable one would rewrite every brand page's heading at random. `merchant_count` takes the
`max()` across spellings rather than the sum: it means "how many shops carry the brand's most-carried
product", and adding two spellings' figures would claim a breadth that does not exist.

`BrandLinker` matches on the slugified name rather than the literal one, so a card carrying either
spelling links to the same page.

### Three products, minimum

`BrandStat::pageworthy()`. A page of copy about a brand with one product on it is filler, and
publishing thousands of them is the doorway-page pattern that discounts a whole domain. Below the
threshold the page 404s honestly.

A brand that leaves the catalogue **keeps its row with counts at zero** rather than being deleted, so
anyone asking why a URL stopped working can find out — and a brand usually comes back with the next
feed.

## Linking, and how a 404 is avoided

`BrandLinker` resolves brand name → URL in one batched query, returning nothing for brands with no
page. Used by:

| Surface | Behaviour |
|---|---|
| Product card | Brand links to its page; plain text otherwise |
| Product page | Same, on the brand line above the title. *Added 2026-08-16* — it was the one surface that rendered a brand and never linked it, which is the page a shopper is most often standing on when they want more of the brand |
| Search facet | Checkbox filters this page, an arrow goes to the brand page |
| Cove prose (`[[brand:X]]`) | Brand page where one exists, filtered search otherwise |
| Brand page itself | Sibling brands in the same category |
| Sitemap, footer | Brand index at `/{market}/brands` |

A brand without a page still *appears* — dropping it would make the sentence untrue, and linking it
anyway would be a 404 in the first paragraph.

`BrandLinker` is `scoped()`, not `singleton()`: the cache is "which brands have a page", which changes
when the nightly refresh runs, and under Octane a singleton would serve yesterday's answer until the
process restarted.

### The card had to be restructured

`ProductCard` was a single wrapping `<Link>`, which is the simplest thing that works right up until
part of the card points somewhere else. Nesting an anchor inside an anchor is invalid HTML and
browsers resolve it by discarding one, unpredictably. It now uses the stretched-link pattern: the
product link carries an `absolute inset-0` overlay, the brand link sits above it on the z-axis, and
both are real crawlable anchors with neither inside the other.

## `ResultTerms`: extraction, not generation

The one thing that still sits above the grid, on both surfaces.

Asking a model for "related keywords" produces plausible words the page does not contain, which is
keyword stuffing with extra steps *and* a lie about the page's contents. Counting the words genuinely
there cannot do either, and costs one pass over 24 titles.

Excluded, each for its own reason:

- **The query's own words**, or the brand on a brand page. Echoing "bluetooth" at someone who searched
  for "bluetooth" is filler, and on a brand page it is a link back to the page you are on.
- **Per-language stopwords.** Without them the list is "de, met, voor".
- **Anything under three characters, and pure numbers.** Model numbers and capacities are not
  vocabulary; they are what makes a list look machine-made.
- **Anything appearing in only one title.** A word in one of 24 listings does not characterise the
  page — it is how a page of headphones ends up described with the word "keukenmachine".

Each title contributes a word at most once, or twelve near-identical listings for one product make
that product's model name the page's defining vocabulary.

## The long copy below the grid is gone

**Removed 2026-08-16.** What a brand page carries below its products now is a list of articles that
mention the brand, and nothing else.

`PageNarrative::forBrand()` used to add ~350–450 words there: three sections — about the brand, where
it is sold, how to choose one — plus an FAQ and a strip of related searches. Every line of it was a
fact the catalogue could back up or a true explanation of how the site works, and it was still the
wrong thing to publish a thousand times.

The reason it went is not that it was false. It is that it was **arithmetic about the grid directly
above it, written in sentences**, and the same arithmetic on every brand page with the nouns swapped.
A reader who scrolls past the products has already seen the price range; a crawler comparing two
brand pages sees one template. The word count was real and the document it made was not.

The `FAQPage` JSON-LD went with it, necessarily: structured data whose answer is not on the page is a
misrepresentation, so an FAQ block cannot outlive the paragraphs it describes.

What sits there instead is the section below — articles, which are the one thing on a brand page that
was written once, about a real question, and links *out* of the page rather than restating it.

`PageNarrative::forBrand()`, the `brand_narrative` copy-bank surface and `PageNarrativeTest`'s brand
half are all still in the tree. Nothing renders them. They are a deliberate loose end rather than an
oversight: deleting them also means retiring an editable copy surface that `copy_templates` may hold
seeded rows for on staging and production, which is a data question and not a code one.

### Articles that mention the brand

`BrandController::coves()` — three ways an article counts, and the third was added the day the prose
was removed, because the first two left the section empty on most pages:

1. Its prose contains a `[[brand:X]]` token. The writer named the brand deliberately.
2. It features one of the brand's products. Structural, and true even where the prose never spells
   the brand out — but only buying guides have a shortlist at all.
3. Its title, intro or body says the name in plain text. This is the one that fires in practice: an
   advice article has nothing to match structurally, and prose about "de Sony over-ears" carries no
   token.

The plain-text match is a **word-boundary regex (`~*` with Postgres' `\y`), never a LIKE**. `%sony%`
matches "Sonya" and "masonry", and a brand page linking to an article about masonry is worse than one
linking to nothing: it is a promise the click does not keep.

Two details that are easy to get wrong and fail differently:

- **The name is regex-escaped.** Brands are punctuated — "Dr. Oetker (NL)" — and an unescaped `(` is
  not a subtle bug but a syntax error Postgres raises at query time, i.e. a 500 on that brand's page.
- **The boundaries are conditional.** `\y` matches *between* a word character and a non-word one, so
  `\yDr\. Oetker \(NL\)\y` matches nothing at all: the pattern already ends on `)`. Anchoring both
  ends unconditionally silently empties the section for every brand whose name is punctuated at
  either end — silently, because an empty section looks like "no articles yet". Each boundary is
  added only on the side where the name starts or ends on a word character.

Spellings under three characters are excluded from the plain-text match alone; the token and product
matches carry no such limit because both are exact. A boundary match on two letters is not evidence
of anything — an article containing the word "OK" is not about the brand OK.

Unindexed, deliberately. A market holds hundreds of published articles, not millions, so the
sequential scan costs less than an index maintained on every publish.

### Related searches — on the search page only, since 2026-08-16

From `search_log`, matched with the `<%` word-similarity operator — never `%`, whose whole-string
`similarity()` scores a realistic neighbour under the 0.3 default and finds nothing. Real searches
with real results, which is the demand signal no competitor has, and the outbound links that stop a
results page being a leaf a crawler reaches and then stops at.

These chips lived inside the narrative, so a brand page lost them along with it. The brand page is
not a leaf without them: the term links above the grid, the related-brand list in the rail and the
article links below it are all outbound, and the last of those is new.

### Editable, and rotating — the search page's narrative now

The copy is not in the language files any more — or rather, it is, but only as the fallback.
`copy_templates` holds **variants** of each **slot**, editable at `/admin` under *Page copy*, and
`CopyBank` draws one per page.

A slot is a position in the page's argument ("the second sentence about comparing"), and the code
only ever asks for the slot. Add a fifth opening line for brand pages and a fifth of them start using
it, immediately, with no deploy.

**Two axes of rotation.** *Across pages*, always: the page's own identity — the brand slug, the search
term — is in the seed, so two pages drawing from the same three variants reliably get different ones.
*Over time*, on a cadence: `COPY_ROTATION` is `weekly` by default, with `daily`, `monthly` and
`static` available.

**Not per request**, and that is the one decision worth defending. It is the obvious reading of
"rotate constantly" and the only version that would hurt: a page whose wording changes on every load
cannot be cached at the edge, flickers for anyone who hits back or opens two tabs, and shows a
crawler a different document on every fetch — which reads as an *unstable* page rather than a fresh
one. A search engine's judgement of "this content changes" is about substance, not about which of
three synonyms for "compare" is in paragraph two. So the draw is deterministic given (slot, page,
period) and the period is what moves.

The slot is in the seed as well as the page, or every slot on a page would draw the same index and a
site with six variants each would have six documents rather than many.

**The fallback is load-bearing.** A slot with no enabled variant renders from the language file. That
means `copy_templates` can be empty, half-filled or wrong and every page still shows the copy the
site shipped with — which is what makes the table safe to hand to an editor. The worst they can do is
make a page ordinary again. `bc:seed-copy` imports the shipped lines as the first variant of each
slot, so the admin opens populated rather than blank; it never touches a slot that already has a row.

**Placeholders are validated per slot.** `CopySlots` declares which each may contain and the form
refuses the rest. A typo'd `:cont` renders literally, and a placeholder the slot cannot supply is
worse: `:percent` in a sentence that renders even when nothing is discounted asserts a 0% saving.
Two tests hold the shipped copy to the same rule.

This also retired `BrandCopy::LEAD_VARIANTS` — the four hard-coded openings picked by
`hash(brand) % 4` are now four rows anyone can edit, reweight, or add a fifth to.

### The trap: seeded copy shadows a rewritten language file

The guarantee above — *never overwrite an editor's work* — has a consequence that stays invisible
until it bites. **Once a slot has a row, rewriting its language file changes nothing on any
environment where `bc:seed-copy` has run.** Local development, where the table is usually empty,
shows the new words immediately; staging and production keep serving the old ones out of the
database.

Caught exactly that way. The brand copy was rewritten to describe the brand rather than the pricing,
the tests passed, staging deployed — and staging carried on with the old sentences. The three *new*
slots appeared straight away, because a slot with no row falls back to the file; the *changed* ones
did not. A page half in the new voice and half in the old is a worse symptom than none of it landing,
because it looks like the deploy worked.

`bc:seed-copy --replace` deletes the chosen slots' rows and re-imports them. Destructive by
definition, so: opt-in, narrowed with `--surface`, `--dry-run` reports what it would remove, and
outside a dry run it names the number of rows and asks. `--force` skips the question for a deploy
shell with no tty.

```bash
php artisan bc:seed-copy --surface=brand_intro --replace --dry-run   # look first
php artisan bc:seed-copy --surface=brand_intro --replace             # then do it
```

**Rewriting shipped copy is therefore a two-part change**: the language files, and a `--replace` run
wherever the bank has been seeded. A row for a slot that no longer exists is left behind and is
harmless — the admin lists slots from `CopySlots`, so an orphan is not rendered and not shown.

### Where it does not appear

Null on any page that is `noindex` anyway: page 2+, a filtered search, a sorted brand page. Repeating
four hundred words across dozens of near-identical URLs is the doorway-page pattern at scale, and
those pages were never going to rank.

## Seasonal Coves

`TopicMiner` reads 30 days of our own searches, which is the right primary signal — real demand no
competitor can see. It has one structural blind spot: **it cannot know about a season before the
season arrives.** Barbecue searches peak in June, so a log-only queue commissions the barbecue Cove in
July and it first earns real traffic the following May. Halloween is worse — three weeks of demand
means the log knows only after it is over.

`config/cove_seasons.php` lists ~23 topics with windows that open well before their season: spring
cleaning and spring running from mid-February, barbecue from mid-March, poolside and sun protection
from April, back-to-school from mid-June, Halloween from 1 August, wintersport from mid-September,
Easter, Mother's Day, Father's Day, Valentine's.

`TopicMiner::ripest()` returns an in-season seasonal topic **outright**, whatever an evergreen topic
scores. Not a hedge — a timing argument: a Halloween Cove written on 20 October is nearly worthless
and the same Cove written on 1 August is an asset for a decade. Within the seasonal set the ordering is
by **how soon the window closes**, not by size, for the same reason.

Two things it deliberately does not do:

- **It never fabricates a search volume.** A seasonal topic's `search_volume` is whatever the log
  actually says, usually zero on a young site, and the seasonal branch does not test it. Writing a
  plausible number there would corrupt the one honest demand signal the system has, and admin's
  "180 searches, 0 products" report is useful exactly as long as every figure in it was measured.
- **It never overturns an editor's decision.** Re-seeding is nightly; a rejected topic that reset
  itself would return every single night.

A seasonal topic colliding with a mined one is the *best* outcome — it means real demand exists for a
season we already knew was coming — so the member queries are merged rather than replaced.

### Not the same thing as an observance

[Daily Coves](daily-cove.md) are dated and gone tomorrow; Coves are evergreen pages that happen to be
*commissioned* seasonally. The window controls when a Cove is written, never what it claims — a Cove
must not say "today", because it will be read in February.

## Structured data

`StructuredData::brand()` emits `Brand` with a name and a URL and nothing else. The temptation is
`logo`, `sameAs` and `aggregateRating`: we have no logo we are licensed to serve, no verified Wikidata
mapping, and no ratings at all. Structured data asserting something unverifiable is worse than none,
because it is the half of the page a search engine reads literally.

## Crawl policy

| URL | Robots | Canonical |
|---|---|---|
| `/{market}/brand/sony` | indexable | itself |
| `/{market}/brand/sony?sort=price_asc` | `noindex, follow` | the bare page |
| `/{market}/brand/sony?page=3` | `noindex, follow` | the bare page |
| `/{market}/brand/sony?q=over-ear` | `noindex, follow` | the bare page |
| `/{market}/search?brand[]=Sony` | `noindex, follow` | the bare term |
| `/{market}/brands` | indexable | itself |

Brand pages are listed in the sitemap **only on page 1**. Product pages run to tens of thousands and
brands to a few hundred; repeating the brand block in every chunk would list each one dozens of times,
which a crawler reads as a sitemap it cannot trust.

## A trap worth remembering

`BrandController::show()` declares `string $marketSegment` and never uses it. Laravel splices resolved
class dependencies in at their parameter position and then passes the remaining route parameters
positionally, in the order the URI declares them. `{market}` comes first, so a signature that omits it
hands `"be-nl"` to `$slug` — and every brand page 404s silently, because the lookup simply finds
nothing. `GuideController::show()` declares it for the same reason.

## Operations

```bash
php artisan bc:refresh-discovery --market=be-nl   # includes brand stats
```

Scheduled twice daily at 05:30 and 17:30, after grouping and serendipity.

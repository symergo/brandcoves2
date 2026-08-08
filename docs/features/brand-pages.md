---
name: Brand pages and on-page editorial
area: SEO / Discovery
status: Built
date_added: 2026-08-08
---

# Brand pages and on-page editorial

**Every brand with three or more products gets one canonical, indexable page: a search with the
brand preselected, with prose above it built entirely from numbers the catalogue can back up.**

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

What it adds is what a filtered search cannot have: a canonical URL, prose, and links out to Coves.

The brand facet is absent from its filter rail, because filtering a Sony page by brand is a control
with one option.

### `?brand[]=` cannot override the path

`SearchQuery::withBrand()` **replaces** the brand filter rather than adding to it. Allowing both would
let `/brand/sony?brand[]=Philips` render a page whose copy talks about Sony and whose results are
Philips — wrong in the specific way that is hard to notice and impossible to defend.

## The copy rule

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
| Search facet | Checkbox filters this page, an arrow goes to the brand page |
| Search intro prose | Brands render as links, unlinked when they have no page |
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

## Search-page editorial

The same principle applied to search results. Above the grid, only on the bare indexable page — a
filtered or paginated variant is `noindex` anyway, and repeating the text across them is the
doorway-page pattern.

Every clause is read off the results themselves:

- how many products, and how many shop listings between them
- the price range for the term
- how many are below their 30-day median, and the largest saving
- how many are sold by more than one shop — the site's whole premise, stated where it is true
- **the words that come up across the listings**

That last one is `ResultTerms`, and it is extraction, not generation. Asking a model for "related
keywords" produces plausible words the page does not contain, which is keyword stuffing with extra
steps *and* a lie about the page's contents. Counting the words genuinely there cannot do that and
costs one pass over 24 titles. Excluded: the query's own words (echoing "bluetooth" at someone who
searched for "bluetooth" is filler), per-language stopwords, anything under three characters, pure
numbers, and anything appearing in only one title.

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

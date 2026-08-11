---
name: Popularity charts
area: Catalogue / Discovery
status: Active — bol; Amazon lands on the same seam
date_added: 2026-08-10
---

# Popularity charts

**A retailer's bestseller list, used as an internal demand signal. Never republished as content.**

The site had no way of knowing what people actually buy. Every "popularity" signal in the codebase
was a proxy for one:

- `TopicMiner` reads 30 days of our own searches, on the premise that a site's own log is "the only
  demand signal that is both real and unavailable to competitors". True — and empty on a new market,
  which is exactly when guides would do the most good.
- `FreshRetriever` measures `first_seen_at` and how many shops picked something up lately. That is
  supply moving, not demand.
- `SuggestionEngine::surprise()` uses merchant count as an inverse proxy for "you have seen this
  already".

bol publishes the real quantity, and we were already authenticated against it.

## What it feeds, and what it does not

Two consumers, both internal:

1. **Product suggestions** — the `popular` retriever in the discovery pipeline, candidate coverage in
   the gift engine, and the serendipity quality gate.
2. **Market trend identification** — the admin trends page and chart-derived guide topics.

**There is no public bestsellers page and no visible rank.** Partly scope, mostly this: a chart is
the retailer's measurement of their own customers. Using it to decide what we show is ordinary
editorial judgement; reprinting it as our content is republishing somebody else's ranking. The rank
shapes the shelf, it is never the label.

## The endpoint

`GET https://api.bol.com/marketing/catalog/v1/products/lists/popular`

A browse endpoint — no search term, which is what makes the result demand rather than relevance.
Parameters: `country-code` and `Accept-Language` (both required), `category-id`, `page`, `page-size`,
`include-offer`, `include-image`, `include-relevant-categories`.

**The response envelope is undocumented.** `/products/search` uses `results`, so that is tried first
with `products` as a fallback — and when neither is present the connector logs a warning rather than
returning an empty array. This is the failure mode the search connector already carries a comment
about: an empty chart and a wrong parser are indistinguishable from the outside, so a renamed key
would silently report "no bestsellers today", forever, past a green test suite.

## Category discovery is a crawl, because bol publishes no category list

There is no endpoint that lists category ids. The only way to learn one is to pull a chart and read
`include-relevant-categories` off the response — so a chart is simultaneously this run's data and the
next run's frontier. `chart_categories` is that frontier.

The crawl is therefore breadth-first and **bounded per run** (`max_categories`, default 40), ordered
never-pulled-first then stalest-first. Coverage widens over days instead of arriving in one night
against a rate-limited API. What a run defers is logged — a silent cap reads as "we covered
everything", which is precisely the wrong impression to leave about a crawl that deliberately does
not.

## Storage: two tables, and one of them is deliberately thin

`popular_ranks` holds an **external id, a position and a date**. No title, price or image.

That is the Amazon seam. Invariant 6 forbids mirroring Amazon's catalogue but permits storing the
*decision* — which product was chosen and why — so a decision-only rank table is correct for a source
that may be mirrored and one that may not, with no second schema and no special case at the call
site. `ChartPuller` gates the catalogue write on `Source::allowsCatalogueStorage()`; the ranks are
written either way.

Three details worth keeping:

- **`category_external_id` is `'*'` for the market-wide chart, not `NULL`.** Postgres treats NULLs as
  distinct, so a nullable column cannot carry the daily unique key — every pull of the overall chart
  would insert a duplicate instead of upserting, and the table would grow a fresh copy of the chart
  every day.
- **Rank counts every row the source returned, including ones we cannot store.** Renumbering after
  dropping an invalid row would promote the next product into a position it never held. Since
  movement is a difference of two ranks, one dropped row would fabricate a climb for everything below
  it.
- **`group_id` is resolved on a later pass, not at write time.** Grouping runs on its own schedule, so
  a product upserted this morning has no group yet. `ChartPuller::linkRanks()` runs at the *start* of
  each job and is self-healing: anything still unlinked inside the window gets another attempt
  tomorrow.

## Movement is the signal, not position

This is why history is stored at all rather than a single snapshot being overwritten.

A permanent number one is popular and is not *news*. A product that went from #40 to #6 in a week is
what "what's current" actually means. So `PopularRetriever` splits the chart across two of the
ranker's existing signals:

| Signal | From |
|---|---|
| `relevance` | rank, log-decayed: `1/(1+ln(rank))` — #1 ≈ 1.0, #10 ≈ 0.30, #100 ≈ 0.18 |
| `novelty` | movement against the nearest snapshot ≥ 7 days old. New entry 1.0, climbing proportional to positions gained, flat or falling 0.2 |
| `quality` | 0.9, raised to 1.0 at `merchant_count > 1` |
| `unexpectedness` | `surprise_score`, as the other retrievers do |

With `trends` at α = 0.3 and γ = 0.7, the climber wins. That is the whole point.

Two calibration decisions:

- **Log decay, not linear.** The gap between #1 and #10 is real; the gap between #90 and #100 is
  rounding. A linear scale gets both backwards.
- **A week, not a day.** Charts jitter — a competitor's stock-out moves three places overnight. And
  the comparison reaches for the *nearest snapshot at least seven days old* rather than "exactly seven
  days ago": a skipped run would otherwise find nothing there and award every product maximum novelty
  for a gap in our own data.

A product's **best** position across charts is the one that counts. Charting at #2 in headphones and
#90 overall is a strong seller; averaging would punish it for appearing in a large category as well as
a small one.

`isAvailable()` returns false when the newest snapshot is over a fortnight old, so `trends`
renormalises onto `fresh` instead of presenting month-old demand as current.

## The part that had to be fenced off

Two engines here are built to **resist** popularity, deliberately:

- `SuggestionEngine::surprise()` — "a neutral constant would let the most-stocked bestseller win every
  tie, which is exactly the failure this whole feature exists to avoid."
- `SerendipityEngine::exclusivity()` — merchant count is inverted on purpose: "when you are being
  shown something new, one shop having it is the reason it is new to you."

A demand term added naively to either would undo that quietly, while looking like an improvement. So
demand is spent in three places and no others:

**1. Candidate coverage in the gift engine.** The pool is capped at 300 and ordered by
`merchant_count`. A bol chart entry is sold by bol alone, so it sorts last and falls off the end —
the things people demonstrably buy would be *systematically absent* from gift suggestions, with
nothing in the output to show it. `withDemandCoverage()` reserves a sixth of the pool for chart-backed
groups, reusing the same query builder so the brief's `avoid` and budget filters bind identically. It
can add candidates; it can never reorder them.

**2. Ranking, for `for_myself` only.** `demand` is a scored term weighted through `SuggestionProfile`:
**0 for `for_someone`**, 5 for `for_myself`. Nobody wants a surprising kettle on their own wishlist —
they want the one that turns out to be good, and "a lot of people bought it" is the cheapest evidence
of that going. Buying for another person is the opposite question, and that zero is a product
decision rather than a tuning one.

`SuggestionEngineDemandTest::chart_data_does_not_move_a_gift_suggestion` asserts the `for_someone`
output is byte-identical with and without chart data. That test is the fence.

**3. The serendipity quality gate — as proof, never as rarity.** `SerendipityEngine` opens by naming
its own failure mode: "surprising" and "nobody stocks it because it is rubbish" are numerically
identical if you only measure rarity. A chart is the one piece of outside evidence that separates
them. It multiplies `worthSeeing()` by up to 1.25 and is then clamped at 1.0 — so it can only ever
undo a penalty, never lift a product past an unencumbered one. The case it exists for is the €6
curiosity discounted to 0.5 for being cheap: if people are buying it, it was cheap rather than junk.
It never touches a rarity weight, and it cannot rescue anything from a hard gate.

Fed through `CatalogueStats` rather than a direct query, so `SerendipityEngine` keeps its stated
contract — catalogue statistics in, a number out, no network and no clock.

**Absence is not a penalty anywhere.** Most of a catalogue has never charted; reading that as
"probably junk" would gate out the entire long tail, which is the only place a genuine find lives.

## Guide topics from a chart

A `chart_categories` row whose current chart holds at least `MIN_PRODUCTS` presentable groups becomes
a topic candidate, keyed through the same head-noun reduction a search query gets — so "Koptelefoons"
and a search for "beste koptelefoon" land on one topic rather than two near-identical guides.

`chart_entries` is stored as a plain count next to `search_volume`, so an editor reads "3 searches, 38
charting products" and can judge it. `origin = 'chart'` is claimed only when the chart is the *sole*
evidence — a topic people search for here is a search topic however many products chart, and
mislabelling it would hide that we have real first-party demand.

Weighted at 20 against first-party demand's 40, deliberately. A bestseller chart is one retailer's
customers in one country, shopping in a shape that retailer's merchandising decided. It fills the
queue when the log cannot; it never outranks a topic the audience asked for directly. Nothing
auto-publishes — these land in the same reviewable queue, which is what keeps this a topic queue
rather than a content farm.

## Rate limiting

The chart bucket runs at **2.0/s, not the connector's 8.0**.

`RateLimiter` buckets are per endpoint and share no budget, so every additional bucket raises what
this connector can emit in one second. `bol:search` and `bol:product` are 8/2 each already, against a
documented 10/s — the pair is arguably over-subscribed on its own and worth revisiting. This runs in
a worker while visitors are searching, so background work loses that race by construction rather than
by luck.

A 429 cools down the chart bucket alone. The crawl notices, stops, and **keeps its cursor** — a run
recorded as finished would throw the cursor away and re-spend the very requests that caused the
pause, so an interrupted run is marked Pending rather than Completed.

## Operations

```bash
php artisan bc:pull-charts --market=be-nl --discover   # prove the endpoint; writes nothing
php artisan bc:pull-charts --market=be-nl              # pull for one market
php artisan bc:pull-charts --fresh                     # clear cursors and pull marks
```

There is no separate `--dry-run`: `--discover` already writes nothing, and a second flag meaning the
same thing is a flag somebody will pick the wrong one of. A full crawl that wrote nothing would spend
the whole rate-limited budget to produce no data, which is not a mode worth having.

`--discover` is the first thing to run against a new environment or after a connector change: one
request per market proves the credentials, the endpoint and — the part that fails silently otherwise
— the response envelope. When it returns nothing at all it says so explicitly and points at
`bc:check-bol`, because "empty chart" and "broken parser" look identical otherwise.

Scheduled daily at **03:40**, deliberately ahead of feed ingestion (04:10) and grouping (05:00), so
the chart's products are grouped in the same overnight cycle rather than waiting a day to become
suggestable. Once a day and no more: a bestseller chart does not turn over hourly, and a second pull
would only overwrite the same snapshot.

Rank history is retained for **400 days** — a year plus a margin, so "was this climbing last August
too?" is answerable. Seasonality is most of what a chart has to say in a gifting catalogue. Pruned by
`bc:prune-price-history`, which owns both daily time series.

**No new environment variables.** It reuses `BOL_CLIENT_ID` / `BOL_CLIENT_SECRET`, so there is nothing
to add in Coolify and nothing for `bc:check-config`.

`/admin` → Operations → **Market trends** shows risers, arrivals, fallers and the categories with the
most churn, per market. Read-only: a button that quietly spends a rate-limited budget is a button
somebody will hold down.

## Adding Amazon

`PopularityConnector` is a third capability alongside `FeedConnector` and `LiveConnector`, because the
three are independent — Awin is a feed with no chart, bol does live search *and* charts, Amazon will
chart under storage rules that forbid mirroring. Adding it is:

1. Implement `PopularityConnector` on the Amazon connector.
2. `registerPopularity()` in `AppServiceProvider`.
3. A `popular` block under `connectors.amazon` in `config/brandcoves.php`.

Nothing in `ChartPuller`, `PullPopularCharts`, `PopularRetriever` or `MarketTrends` changes.
`allowsCatalogueStorage()` already routes Amazon down the decision-only path, and
`PopularChartPipelineTest::a_source_that_may_not_be_mirrored_still_gets_its_ranks_recorded` asserts
it.

## One thing worth checking

`BolConnector::fetchById()` may be hitting a 404 on every call, and this feature does not depend on
it. bol documents the single-product path as `/products/{ean}`, its key-concepts page treats
`bolProductId` and `ean` as distinct, and there is a `/products/{bolProductId}/to-ean` converter —
which exists only because they are not interchangeable. `normalise()` sets `externalId` from
`bolProductId`, and `fetchById()` puts that value in the `{ean}` slot. If that is wrong, every bol
wishlist and daily-pick refresh returns null *invisibly*, because the method is documented to degrade
to null. One `bc:check-bol` run against a known bolProductId settles it.

## Files

- `app/Services/Connectors/PopularityConnector.php`, `PopularChart.php`, `ChartEntry.php`,
  `ChartCategory.php`
- `app/Services/Connectors/Bol/BolConnector.php` — `popular()`, its own limiter bucket
- `app/Services/Charts/` — `ChartPuller`, `ChartPullResult`, `ChartDemand`
- `app/Jobs/PullPopularCharts.php`, `app/Console/Commands/PullChartsCommand.php`
- `app/Models/PopularRank.php`, `ChartCategory.php`
- `app/Services/Discover/Retrievers/PopularRetriever.php`
- `app/Services/Discovery/MarketTrends.php`, `TrendMove.php`
- `app/Filament/Pages/MarketTrends.php`
- `database/migrations/2026_08_10_000300_create_popularity_chart_tables.php`
- `database/migrations/2026_08_10_000400_let_a_guide_topic_come_from_a_chart.php`
- `tests/Feature/BolPopularChartTest.php`, `PopularChartPipelineTest.php`,
  `PopularRetrieverTest.php`, `MarketTrendsTest.php`, `SuggestionEngineDemandTest.php`

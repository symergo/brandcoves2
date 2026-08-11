---
name: Gift Whisperer
area: Gifting
status: Active
date_added: 2026-08-08
---

# Gift Whisperer

Describe someone, get four suggestions, each with the reason it was chosen.

Gifting is **anti-search**: a shopper knows the product and needs the price; a gift-giver knows the
person and has no idea what the product is. Every part of this feature exists because the search box
is the wrong tool for that problem.

## The pipeline

```
AngleMap ──▶ retrieve ──▶ filter ──▶ score ──▶ MMR ──▶ explain
 (queries)    (one SQL)   (hard)     (5 signals)  (diversify)
```

Four suggestions out of tens of thousands of rows, in under 100 ms, on a request that can never cost
an AI call. Everything expensive happened earlier: giftability was classified after the last ingest,
the angle map was widened overnight.

## 1. Giftability — [see the classifier](#the-classifier)

A merchant feed is mostly *not* gifts. Vacuum bags, printer toner, extended warranties and phone
cases for one specific handset vastly outnumber the things a person would be pleased to unwrap. One
of those in a gift result destroys trust in every other result on the page — so the classifier is
tuned strict: a wrongly excluded gift costs one candidate out of tens of thousands, a wrongly
included non-gift costs the feature.

### The classifier

Pure — text and a price in, a verdict out. No database, no network. Two decisions shape all of it:

**Match substrings, not words.** Dutch and German write compounds closed: `stofzuigerzak`,
`inktcartridge`, `waterfilterpatroon`. A `\bcartridge\b` regex matches none of them, so a
word-boundary matcher waves every Dutch consumable straight through — and Dutch is two of our five
markets.

**List the compound, never the bare stem.** The obvious follow-up mistake is adding `filter` to the
list, which then also kills `polarisatiefilter` and `ND-filter` — real presents for someone who takes
photographs. So the list holds `waterfilter`, `stofzuigerfilter`, `filterpatroon`. Camera filters
survive because nothing in the list is a substring of them, not because of a special case bolted on
afterwards.

**A term must never sit in the list beside its own prefix.** Found the hard way: `navulling` and
`navul` both matched a coffee hamper, and the shorter one — reached second, carrying no rescue of its
own — silently overturned the longer one's rescue. Keep the prefix, hang the rescue on it.

Accent folding is done by an explicit table rather than `iconv('ASCII//TRANSLIT')`, which produces
different output on glibc, musl and Windows. Tests run on a Windows laptop; production is Alpine. A
classifier that disagrees with its own test suite depending on the host is worse than none.

The golden file in `tests/Unit/GiftabilityClassifierTest.php` **is** the specification — 35 cases,
each a real shape from the Awin feeds.

## 2. The angle map

Interest (+ optional vibe) → the search queries that retrieve candidates. Two layers:

1. **Curated seed**, compiled into `AngleMap`. Hand-written, and good enough alone — the feature has
   to work on a fresh database, in a test, and with `AI_ENABLED=false`. A gift finder that returns
   nothing until a nightly job has run is broken on launch day.
2. **Widened rows** from `gift_angles`, written nightly by `WidenGiftAngles`.

Widened rows come *first* in the query order. Putting them behind the seed would mean the nightly job
never visibly changes anything until the seed runs out.

The seed uses concrete product nouns, not themes: `statief` and `cameratas` retrieve products,
`cadeau voor fotograaf` retrieves listicles and junk.

Free-text interests are passed through verbatim. Someone who typed "wielrennen" has told us exactly
what to search for, and second-guessing them is worse than trusting them.

### Widening is the AI invariant in miniature

The model runs in a scheduled job, under a daily cap, and writes rows the request path only reads.
Batched one call per market covering the five stalest interests — 5 markets × 20 interests × 4 vibe
states is 400 combinations against a cap of 20 calls a day, and the model writes better queries when
it can see several interests at once. Staleness is read off `updated_at`, so the timestamp *is* the
cursor and it survives a redeploy for free.

With AI off, nothing happens — deliberately. Faking widening from the catalogue would push results
toward what is already well stocked, which is the opposite of the point.

## 3. Retrieval

One query. The angle queries are folded into a single `websearch_to_tsquery` joined with `OR`, not a
subquery per term: twenty `EXISTS` clauses against a table this size is the difference between 40 ms
and four seconds.

**"Avoid" is a hard filter, never a penalty.** Someone who wrote "no alcohol" or "she's allergic to
wool" is not expressing a preference to be weighed against price. A single violation makes the whole
page untrustworthy. `ILIKE` rather than FTS, because the exclusion has to catch the word wherever it
sits — including inside a Dutch compound.

An interest with no matches falls back to a budget-and-vibe browse rather than an empty page. The
person told us who they are shopping for; "we found nothing" throws that away.

## 4. Scoring

| Signal | Weight | Note |
|---|---:|---|
| `interest_fit` | 40 | Weighted by *where* the matching query sat, not just how many matched. The first interest someone thinks of is the one that matters. A second match adds at most 0.2, so a product that name-drops five keywords cannot win on padding. |
| `budget_fit` | 20 | Peaks at 85% of the ceiling, falls away on both sides. A €12 gift against a €100 budget reads as thoughtless, not thrifty. |
| `surprise` | 20 | From [the Serendipity Engine](serendipity.md). |
| `vibe` | 10 | A nudge, never a filter — someone who said "playful" still wants the good headphones if headphones are the right answer. |
| `values` | 10 | Sustainable / local / handmade. |
| `demand` | **0** | Bestseller-chart strength. Zero here is the decision — see below. |

**`demand` is weighted zero for `for_someone` on purpose.** We hold a real demand signal now (see
[popularity-charts.md](popularity-charts.md)), and this is the one place it must not be spent.
`surprise` exists to stop the best-stocked product winning every tie; paying for popularity alongside
it cancels that out and turns the Whisperer into a chart — while looking like an improvement. The
`for_myself` profile weights it 5, because that is the opposite question: nobody wants a surprising
kettle on their own wishlist, they want the one that turns out to be good. This split is exactly what
`SuggestionProfile` exists to hold, and
`SuggestionEngineDemandTest::chart_data_does_not_move_a_gift_suggestion` asserts the gift output is
byte-identical with and without chart data.

Chart products still *reach* the scorer. The candidate pool is capped at 300 and ordered by
`merchant_count`, and a bestseller pulled from one retailer's chart is sold by that retailer alone —
so it sorted last and fell off the end, meaning the things people demonstrably buy were
systematically absent with nothing in the output to show it. A sixth of the pool is now reserved for
chart-backed groups, through the same query builder so `avoid` and the budget bind identically. It
can add candidates; it cannot reorder them.

**An unanswered question scores 0.5, not 0.** "Does not apply" is not "scores badly" — scoring a
skipped question as zero would silently shrink the total for everyone who skipped it, and the wizard
is built so every step after the first can be skipped.

## 5. Diversification — the stage that matters most

Without MMR the top four are near-duplicates, because whatever scores well scores well *for the same
reasons*. Four Bluetooth speakers at four price points is a worse answer than a speaker, a cookbook,
a plant pot and a board game, **even though each speaker individually beats each alternative**.

Greedy MMR at λ = 0.65: `λ·score − (1−λ)·maxSimilarityToAlreadyPicked`. Similarity is category (0.6)
+ brand (0.2) + Jaccard title overlap (0.4), capped at 1. Category dominates because it is what a
person notices — two headphones from different brands still read as "you showed me headphones twice".

This is tested directly, with a fixture of six speakers that all outscore three genuinely different
presents. A diversifier that quietly stops working looks exactly like one that works, right up until
every result page shows four of the same thing.

## 6. Explaining

One reason per card, not a breakdown. Three reasons read as a machine justifying itself, and the
strongest signal is almost always the true one. The full `breakdown` is kept on `GiftPick` — "why did
it pick this" is the first question everyone asks, the shopper now and whoever tunes the weights in
six months. A recommender you cannot interrogate is one you cannot fix.

## Privacy

The wizard's answers live in component state and travel by POST. A brief describes a real person —
their tastes, what to avoid, what you are willing to spend on them — and that does not belong in a
URL that lands in a referrer header or a shared browser history. The wizard page itself is a GET so
it can be indexed; only the results are POSTed.

Swapping carries every group already on screen plus everything swapped away, so "something else"
never loops back to what was just rejected — the fastest way to lose trust in a recommender.

## Files

- `app/Services/Gift/GiftabilityClassifier.php`, `Giftability.php`
- `app/Services/Gift/AngleMap.php`, `GiftEngine.php`, `GiftBrief.php`, `GiftPick.php`
- `app/Jobs/ClassifyGiftability.php`, `WidenGiftAngles.php`
- `app/Http/Controllers/GiftController.php`
- `resources/js/Pages/Gift/Wizard.tsx`
- `tests/Unit/GiftabilityClassifierTest.php`, `tests/Feature/GiftEngineTest.php`

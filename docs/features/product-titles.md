---
name: Product titles
area: Catalogue / SEO
status: Active
date_added: 2026-09-05
---

# Product titles

The string on 302,133 pages that nobody here wrote.

`product_groups.title` is copied by `ProductGrouper::recomputeAggregates()` from whichever
offer is cheapest and in stock. So the most important string on the most-crawled template on
the site is chosen by whichever merchant undercut the others this morning, and it arrives
carrying that merchant's feed conventions wholesale. It is the `<title>`, the `<h1>` and the
schema.org `name`.

Measured on production, 2026-09-05:

| | be-nl | be-fr | nl-nl | en | total |
|---|---:|---:|---:|---:|---:|
| Title is ALL CAPS | 8,950 | 9,120 | 456 | 67 | **18,593** |
| Brand known, absent from title | 19,482 | 17,192 | 1,510 | 311 | **38,495** |
| Title in the wrong language | 314 | 4,753 | 41 | — | **~5,100** |
| Median length | 47 | 47 | 74 | **121** | |
| Over 60 characters | 37.6% | 35.4% | 59.6% | **85.4%** | |

In the English market the median product title is 121 characters and 85.4% are cut off in a
listing. Google answers a shouting title by rewriting it rather than showing it, so those
18,593 pages had handed the decision over. The 38,495 missing a brand are the worst of the
three: the brand is the highest-intent word a product query contains, and it was sitting in
the column next door, unused.

## Two fixes, at two different layers

Neither is sufficient alone and they do not overlap.

### 1. Choose a better title — `ProductGrouper`

One CTE used to supply both *which offer to link to* and *which title to quote*, ordered on
stock then price. Those are different questions and they now have different answers:

- **`best`** — cheapest in stock. Supplies `best_offer_id` only. Nothing cosmetic may
  influence it: this is the offer a shopper is sent to, and re-ranking it on title quality
  would send them to a dearer seller.
- **`display`** — supplies `title`, `brand`, `image_url`, `category`. Ordered on the defects
  first, then falling through to the same stock-and-price ordering, so among equally
  well-formed titles the cheapest seller still supplies it.

```sql
ORDER BY
    p.group_id,
    (p.title = upper(p.title) AND p.title ~ '[A-Z]{4}') ASC,
    (p.brand IS NOT NULL AND position(lower(p.brand) in lower(p.title)) = 0) ASC,
    (p.availability = 'in_stock') DESC,
    p.price ASC NULLS LAST,
    p.id ASC
```

Booleans sort false-first in Postgres, so each test is phrased as *the defect* and ordered
`ASC` — the offers without it come first. The four-capital run in the first test is what stops
a title that is only "LG" or "JBL" being read as shouting.

This fixes the rows where *some other merchant* had a decent title. It does nothing for a group
where every merchant shouts.

### 2. Clean what survives — `App\Services\Catalogue\ProductTitle`

Presentation only. **Nothing here writes to the database**, because the stored title is also
the input to search indexing, to `ProductDescription` matching and to the slug — all of which
want the merchant's own words.

Two methods, and the difference between them is the point:

- **`heading()`** — de-shouted, carrying its brand, **untrimmed**. An `<h1>` has the width of
  the page; only a listing pays for length. This is what the page renders, what
  `StructuredData::product()` emits as `name` (markup must describe what the page visibly
  says), and what the breadcrumb carries.
- **`listing()`** — the heading cut to fit, with `— at :count shops` when there is more than
  one. This is the `<title>` and the `og:title`.

#### De-shouting

Only a title that is uppercase-only *and* carries a run of four or more capitals. Within it,
a token is left alone if it contains a digit (`512GB`, `WH-1000XM5`, `18V`), is on a small
acronym list (`USB-C`, `LED`, `OLED` — "Usb" and "Oled" read as typos where "Bluetooth" does
not), or **matches a token of the brand**.

That last one was found by a test rather than by reading the code: "JBL TUNER 3 BLUETOOTH
BLACK" came back as "**Jbl** Tuner 3 Bluetooth Black", which is a misspelling of somebody's
trademark on 8,950 pages in one market. The `brand_stats` row is the authority on how a brand
is written, so its casing is carried through verbatim.

Everything else becomes Title Case. An ALL-CAPS source carries no information about which words
were meant to be small, so "Kabel Met LED Indicator" keeps its capital M — a list of function
words in four languages would be guessing, and guessing wrong is worse than being uniform.

#### The brand prefix

Compared as **slugs**, never as substrings. The feeds disagree about punctuation —
"Audio-Technica" and "Audio Technica" are one brand — and a `str_contains` on the raw strings
prefixes the brand onto a title that already says it. Same folding `brand_stats` uses, in PHP
for the same reason: Postgres cannot reproduce `Str::slug()`, which transliterates where
`lower(replace(...))` does not.

#### The cut

`listing()` renders the count template once with an empty title to measure exactly what the
template and that particular count cost in the current language, then cuts the heading to what
is left of 48. Measuring beats a constant here for the same reason it does everywhere else in
[page-titles.md](page-titles.md): `" — at 5 shops"` and `" — chez 12 boutiques"` differ by
eight characters.

The cut lands on a separator — comma, dash, pipe, space — past the halfway mark, so it usually
falls where the merchant had already ended a clause. No ellipsis: the count that follows is
already the visible signal that the title is not the whole string.

## Shop count in the title, price in the description

Both numbers are ours to claim and only one is safe in a `<title>`.

A cached snippet quoting a price we no longer offer is a trust problem, and the price is the
number most likely to have moved since the last crawl. A merchant count barely moves. So the
volatile figure goes in the description — where `seo_compare` already carried it, and where a
stale number costs less — and the stable one goes in the title. The JSON-LD `AggregateOffer`
remains the honest, machine-readable copy of both.

## Language is not one of the tests

~4.5% of `be-fr` titles contain Dutch words (4,753 groups), and 314 be-nl titles contain
French. Neither layer addresses it.

Telling one language from another in SQL needs a per-market word list that would be wrong at
the edges in a way the two mechanical tests are not — "sans" is French and also a font, "mini"
is every language — and a wrong tie-breaker silently promotes a worse title. `ProductTitle`
cannot do it either: by the time it runs there is only one title left to judge.

The honest fix is at ingestion, where the offer still knows which feed it came from and the
feed knows its own language. Left undone rather than approximated.

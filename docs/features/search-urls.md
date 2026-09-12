---
name: Search URLs
area: Search / SEO
status: Active — `/be-nl/zoek/term`, the market's word in the path
date_added: 2026-09-12
---

# Search URLs

**A search has a readable address.** `/be-nl/zoek/draadloze-koptelefoon`, `/be-fr/recherche/casque`,
`/en/search/headphones`, `/es/buscar/auriculares`. Asked for by the owner on 2026-09-12, with the
bstore precedent in mind (`bstore.be/amazon/philips/`). `App\Support\SearchUrl` is the one place the
rule lives on the server, `resources/js/searchUrl.ts` its twin in the browser.

## Why a path, and why the market's word

A path segment is read by two readers: a person deciding whether to click a result, and a search
engine deciding what the page is about. `?q=philips` says nothing to either; `/zoek/philips` says
"search, Philips" to both, in the language of the market it sits in. The segment is localised per
language, the way the Daily Cove's was for two hours before it was collapsed to `tips`
([daily-cove.md](daily-cove.md)); the difference here is that there is no word for "search" that reads
in all four languages, and "zoek" is the word the Dutch reader types.

Segments, per language rather than per market: `nl` zoek, `fr` recherche, `en` search, `es` buscar.

## The old form is kept, not redirected

`/search?q=term` still answers 200. Its canonical names the path form, every server-side producer
of a search link mints the path form (term pills, popular searches, `[[search:]]` tokens in coves,
the Cove rail's category links, alert notifications, the scanner's hand-off), and the two search forms
navigate to it from the browser. That is what consolidates ranking onto one URL.

A 301 would have been marginally cleaner and was rejected on 2026-09-12: sixty requests across the
test suite exercise `?q=` and expect a page, and every bookmark and every external link would have
paid a hop for the difference between "canonical" and "redirected". If the analytics later show the
`?q=` form still being crawled at volume, a redirect can be added in the controller in three lines.

## Only a term that survives the round trip gets a path

The slug turns spaces into hyphens and lower-cases; nothing else. So it can be turned back without
guessing, and the route constraint (`[a-z0-9]+(?:-[a-z0-9]+)*`) admits nothing the slug would not have
produced. A term with anything beyond ASCII letters, digits and single spaces — `iphone 6.1`,
`café`, `wh-1000xm5`, `50%` — stays on `?q=`, exact, with `?q=` as its canonical. Flattening `6.1`
to `61` would have silently searched for something else, and the page would still have said it was
the canonical one.

Case is folded because the search is case-insensitive: `/zoek/Philips` and `/zoek/philips` would be
one page under two names, so the constraint refuses the capital and the helper never emits one.

## Any market's segment serves on any market

`/be-fr/zoek/casque` answers, with `/be-fr/recherche/casque` as its canonical. Cheaper than a
redirect table, and it makes a mistyped or hand-built link land rather than 404. `Alternates::for()`
builds hreflang twins under each market's own word, as it does for the Daily Cove, so a crawler is
never told the French twin lives at `/be-fr/zoek/...`.

## What did not change

- The bare landing, `/{market}/search`, keeps its address: the navigation, the sitemap, the not-found
  page and the help pages all point at it, and it is the form, not a result.
- The indexing rule ([seo.md](seo.md)) is the same on the path form as on `?q=`: since the same
  day, every variant is indexable, filtered and sorted ones canonicalise to the bare term, and page 2
  onwards is its own canonical.
- The crawler layers around `search_log` ([crawlers-and-the-search-log.md](crawlers-and-the-search-log.md)):
  the path route runs the same controller under the same throttle.
- Brand pages narrow with `?q=` on their own path (`/brand/sony?q=...`) and are not searches; untouched.

## Files

- `app/Support/SearchUrl.php` — the rule, and the only place the segments are written
- `resources/js/searchUrl.ts` — the same rule for the browser
- `routes/web.php` — `search.term`
- `app/Http/Controllers/SearchController.php` — reads the path term; canonical
- `app/Services/Seo/Alternates.php` — hreflang per market word
- Producers switched: `SearchTermStats`, `SearchAlert::searchPath()`, `CoveRail`, `CoveMarkup`,
  `ScanController`, `Pages/Search.tsx`, `Pages/Home.tsx`
- `tests/Feature/SearchUrlTest.php`

## See also

- [seo.md](seo.md) — the crawl-budget table these URLs sit in
- [search.md](search.md) — what the page does once it has the term

---
name: SEO
area: SEO / Frontend
status: Active
date_added: 2026-08-07
---

# SEO

Search is the growth model, so this is load-bearing rather than decoration.

## Server-side rendering

Inertia SSR runs as its own Node container (`ssr`, port 13714). Without it a
crawler received `<div id="app"></div>` and a JSON blob. Google will often
execute the JS and index it eventually, but "eventually, if the render budget
allows" is a poor foundation — and every other crawler (Bing, social card
scrapers, LLM crawlers) is far less forgiving.

**Deliberately not a dependency of `app`.** SSR is an enhancement: if the
container dies, Laravel falls back to client rendering and the site stays up,
losing only the pre-rendered HTML.

> **That graceful fallback hid a total outage of it for months.** Inertia v3's
> `ensure_bundle_exists` defaults to true and checks for the SSR bundle on the
> *local* filesystem before dispatching — which the `app` container, by the
> design directly above, does not have. So it never dispatched, and every page
> on production and staging shipped as `<div id="app"></div>`: no `<title>`, no
> `<h1>`, no body copy, for every crawler. No log line, no exception, and
> nothing wrong in a browser because the client hydrates. Found and fixed
> 2026-09-05 in `config/inertia.php`; the whole account is in
> [page-titles.md](page-titles.md#ssr-was-never-dispatched-to). If SSR ever
> appears to be off again, check that value before anything else.

Two things that cost time getting this working, both worth knowing:

- **`ssr: { noExternal: true }` in `vite.config.js`.** Vite externalises
  dependencies in an SSR build by default, which assumes a `node_modules` sits
  beside the bundle. The SSR image has none, so it crash-looped on
  `Cannot find package '@inertiajs/react'`. Bundling gives a self-contained
  2.7 MB file instead of shipping ~200 MB of packages.
- **The bundle is run with `node bootstrap/ssr/ssr.js`, not
  `php artisan inertia:start-ssr`.** The artisan command is a thin wrapper that
  adds a PHP process and a failure mode for no benefit, and it does not work
  reliably on Windows for local development.

> **Verifying SSR by hand:** the rendered markup comes *after* the
> `<script data-page>` block in the response. Splitting the HTML on that script
> and inspecting what precedes it will show an apparently empty page even when
> SSR is working perfectly. Grep the whole response.

## Structured data

The highest-leverage piece here. A `Product` with an `AggregateOffer` is what
makes a search listing show *"€329.99 to €349.00 from 2 sellers"* — both the
thing we uniquely know and the thing that earns the click.

Rules that keep it honest, because fabricated markup is a manual-action risk:

- `gtin13` is emitted **only** for an EAN-grouped product. The brand+title
  fallback key is an internal string, and claiming it as a barcode would be a lie.
- `AggregateOffer` is omitted entirely when nothing is buyable, rather than
  advertising an offer count of zero.
- Prices are formatted as decimal strings, not floats: `329.99` serialised from
  a float lands as `329.99000000000001` often enough to matter.

Also emitted: `BreadcrumbList` on product pages, `WebSite` with a `SearchAction`
so a listing can offer a search box.

**`FAQPage` is emitted by Coves and guides only — not by search or brand pages,
since 2026-09-01.** Those two carried the same six templated questions across
thousands of near-identical URLs, and Google narrowed FAQ rich results to a
handful of authoritative government and health domains in 2023, so the markup had
stopped paying for itself. The questions are still *on* those pages, as ordinary
headings with their answers under them — only the JSON-LD went. A Cove keeps its
own, where the questions are genuinely written per page. See
[page-templates.md](page-templates.md).

## Every public page is indexable (2026-09-12)

The owner's rule, stated on 2026-09-12: **every page is indexable, every internal
link may be followed, and every link that leaves the site is `nofollow`.** It
replaces the crawl-budget table that stood here, kept below for the record.

What that means in code:

| Page | robots | canonical |
|---|---|---|
| Term search (`/zoek/term`; `?q=term` when the term cannot be a path) | none (index) | itself |
| Filtered / sorted search or brand variant | none | the bare term or brand page |
| Search page 2 onwards | none | itself, with `page=` |
| Brand page 2 onwards | none | the bare brand page |
| Empty result, empty popular-searches page | none | itself |
| Product, with or without a buyable offer | none | itself |
| Guide with an empty shortlist | none | itself |
| Previews, shared lists, Secret Santa, quiz, taste profile, magic link, an unanswered or held board question, 404 | **noindex** | — |

The last row is the exception the rule allows for: those pages are private or
transient, not thin. `robots.txt` keeps `/*/go/` (an outbound affiliate hop is not a page, and
crawling it burns budget on redirects while looking like link-selling) and the capability URLs, and
nothing else; the `?sort=`, `brand[`,
`merchant[` and `page=` disallows are gone, because a link a crawler may not
follow is not an indexable link.

Consolidation is now the canonical's job alone. A filtered or sorted variant
names the bare page, so whatever signal it collects lands there; a later page of
results is its own canonical, which is what a search engine asks of a paginated
series. The copy block still skips thin variants (`isThin()`) for the
performance reason measured below, not for indexing.

External links: every anchor that leaves the site carries `nofollow` (with
`sponsored` where money is involved): the shop buttons, the Amazon hand-off,
list items linking out, and the share sheet's messaging endpoints.

### The crawl-budget rule this replaced

Kept because the measurements in it are still true and the trade-off may come
back. The concern on search pages was waste: every filter combination is a
distinct URL and a facet UI generates a combinatorial explosion of them, and a
crawler that indexes them all spends its budget on near-identical pages before
reaching the products and guides worth ranking. From 2026-08 to 2026-09-12 the
rule was therefore: bare term search and product with offers `index, follow`;
filtered, sorted, paginated, empty, and product without offers `noindex, follow`;
plus `robots.txt` disallows on the filter parameters. If the crawl stats show the
filtered variants eating the budget again, that table is the thing to restore.

### The chips under the grid, and what happened to them

The *page* directive stays `follow` throughout. Two rows of pills under the
results made that insufficient on their own, because both pointed at generated
`/search?q=…` URLs and both fed the table they were drawn from: `SearchLog::record()`
writes every term that gets crawled.

Measured on production 2026-09-04: the canonical search page took 6.8–8.0s and a
brand page up to 5.0s, against 0.5s on staging running the identical commit, and
0.2–0.6s for every other page type on the same host. The isolating measurement is
`?q=watch` at 7.9s against `?q=watch&min=1` at 0.6s — a €0.01 price floor that
excludes nothing, the same 3042 results — because any filter trips `isThin()` and
skips the copy block whole. Dev never showed it: `search_log` there held 48 rows.

**The term chips are no longer links.** They narrowed *cumulatively* — `watch`,
then `watch Smartwatch`, then `watch Smartwatch 44mm` — so as anchors they were a
combinatorial supply of URLs, each crawl minting a brand-new term. Worse, each was
`index, follow` by the table above: no filter, page 1, default sort. They are
`<button>`s calling `router.get()` now, so they navigate for a visitor and do not
exist for a crawler. The narrowing behaviour is unchanged, and the URL is still built on the
server — `SearchController::terms()`, and `BrandController::terms()` on a brand page — never in
the browser.

**The related-search chips are gone entirely**, removed 2026-09-05. They were
cached for an hour first, and that was not enough: production still took
9.7–11.1s on a cold term, because the cache only spares the *second* visitor
within the hour and every crawler meeting a new term pays in full. Caching moved
the cost rather than removing it. The trigram scan behind them, the placeholder,
the blocks that placed it and the now-unread `search_log_query_trgm_idx` all went
with it.

What that cost is real: a results page's only outbound links that were not about
itself. [Popular searches](popular-searches.md) is the replacement — one cheap,
cached, indexable hub rather than a scan on every page — and `:term_links` and
`:brand_links` still link outward from the same regions, computed from the
products already on the page. Product, brand and guide links are untouched.

## Metadata is server-rendered, always

`<title>`, `<meta>` and JSON-LD are set from PHP (`PageMeta`) and rendered by
Blade. Tags written by client JavaScript are invisible to every social card
scraper and to any crawler that does not execute scripts.

> **`PageMeta` is request-scoped, and this matters.** An earlier version held
> state statically, so JSON-LD accumulated across requests and a page carried
> the structured data of everything rendered before it. Invisible under PHP-FPM
> (one process per request); under FrankenPHP's persistent workers it means one
> visitor's product page can advertise another product's price. Now bound with
> `scoped()` *and* explicitly reset by `SetMarket` on every request, because
> container scoping alone only clears where something calls
> `forgetScopedInstances()`.

## A listing title is not a heading

Most pages use one language key for three jobs: the `<h1>`, the browser tab, and
the search listing. Those readers are not the same person. An `<h1>` sits above
the page it names and can say "Brands"; a search result has to tell someone who
has never heard of this site what they would be clicking.

> The strings themselves — the search, brand, product and persona templates, the
> 48/155 budgets and the tests that now enforce them — moved to
> [page-titles.md](page-titles.md) on 2026-09-05, when all four were rewritten.
> What stays here is the rule about why they exist at all.

So indexable pages carry `seo_title` and `seo_description` next to `title`.
`title` stays short and keeps the H1 and the nav label; `seo_*` is what
`PageMeta` and the Inertia `<Head>` use, so the `<title>` and `og:title` are the
same string rather than two that drift apart.

Two exceptions, both deliberate:

- **The home page has no `seo_title`.** Its `title` is not an H1 anywhere — the
  hero uses `headline_1`/`headline_2` — so one key serves all three jobs.
- **It also carries the brand name itself.** The title template in
  [`app.tsx`](../../resources/js/app.tsx) appends `· GiftCoves` to every title
  *except* one that already contains it, so the home listing reads
  "GiftCoves verlanglijstjes: …" rather than printing the name twice.
  `ssr.tsx` repeats the rule verbatim: a title that differs between the
  server-rendered HTML and the hydrated client is a visible flicker.

> **Three pages had no `PageMeta` call at all** — the home page, the Gift Cove
> and the Discover Cove — so they shipped with no meta description and an empty
> `og:title`. Nothing looks wrong in a browser: the page renders finished, and
> only the search listing and the social card are blank. The home page, the one
> most likely to be linked from outside, was the worst of the three.
> `SeoTest::every_indexable_static_page_carries_a_title_and_a_description`
> now walks every static indexable page so the gap cannot reopen quietly.

Descriptions are written under 155 characters, because `PageMeta` truncates
there on a word boundary. Titles are written under ~60 including the appended
brand; over that, the brand is what Google drops.

**All four language files move together.** `fallback_locale` is `en`, so a key
added to `lang/en` and forgotten in `lang/nl` does not raise an error — it
silently serves English copy into a Dutch market.

## hreflang and canonicals

Alternates go in the head *and* in the sitemap — Google treats those as
independent signals and picks the sitemap version up faster on a new URL.

**A page only claims the twins it actually has.** A market-independent page —
home, search, discover, the lists — emits all five markets plus `x-default` by
swapping the market segment, which is exactly right for it. A page keyed on a
database row looks its sibling up and emits *nothing* when there is not one:
products join on `identity_key`, guides and Shop Coves and personas on their
slug, Daily Coves on their date.

The asymmetry matters because Google reads hreflang as a mutual declaration. One
alternate pointing at a 404 does not get quietly ignored — the whole cluster is
discarded, so the pages that *do* have real translations lose the annotation
along with it. A missing alternate costs nothing; a wrong one costs the set.

`Alternates::for()` dispatches on the first path segment, and **a keyed page
whose segment is missing from that `match` falls through to the blind swap**.
That is how personas shipped claiming five twins each: `gift-ideas` was not in
the list. It is the failure mode to check for when adding a new keyed page type.

It happened a second time, from the other direction: the Daily Cove segment was
renamed from `daily` to `tips` and the `match` arm kept the old word, so every
edition fell through to the swap and claimed four twins that 404, in the head
and in the sitemap both (found 2026-09-06). The arm now matches
`Market::coveSegments()`, so a rename cannot repeat it, and `SeoTest` pins that
an edition names only the markets that published that day, under each market's
own slug.

**The canonical is the kind's path, not `guides/` for everything.** `GuideController`
renders Shop Coves at `/shops/{slug}` through the same method as guides, and its
canonical, breadcrumb and social-card URLs were literals under `/guides/` — an
address the guide route scopes to articles and 404s. A canonical pointing at a
404 tells the crawler to drop the page, so every Shop Cove was quietly
de-indexing itself. The URL now comes from `CoveKind::path()`, and the OG route
accepts both kinds.

**Private pages say `noindex`, and robots.txt stops the fetch.** A shared list,
a quiz, a recipient's self-describe page and a Secret Santa group are reached by
a token that *is* the access. None of them set `PageMeta`, and the shell
defaults a page with no robots value to `index, follow` — so a share link
posted anywhere public would have listed a family's gift list under a real
name. Each sets `noindex, nofollow` now, and `/l/`, `/for/`, `/q/` and `/santa/`
are disallowed in `robots.txt` as well, because a `noindex`
only works on a page that gets fetched.

**The environment wins over the page.** With `ROBOTS_ALLOW` off, the shell used
to fall back to the page's own robots value, so a controller that asked for
`index, follow` explicitly (the surprise page) was honoured on staging. It now
emits `noindex, nofollow` unconditionally there. The consequence for tests:
anything asserting a page's *own* robots value must switch `robots_allow` on
first, or it is asserting the staging stamp.

**A preview is `noindex` — and the suite could not see that it was not.** Both
the guide and the daily controller computed the preview flag; one never passed
it to its SEO method and the other never read it. With indexing off in tests,
every page carried `noindex` anyway, so `PreviewTest` passed while production
would have indexed an unpublished draft at the finished piece's address. The
preview tests now switch indexing on.

## Sitemaps

An index plus one file per market.

**Product pages are not listed, since 2026-09-18.** The owner's decision. What
is submitted is the editorial surface: the home page, the Coves, the guides, the
personas, the brand pages, the answered questions, the help and legal pages.

A product page is **still indexable and still crawlable** — nothing about this
makes it `noindex`, and the links to it from search, brand pages and Coves are
followed. It is only no longer submitted. The catalogue was 96 of every 100 URLs
in the file and the least stable part of it: offers come and go daily, and a
submitted URL that reads "currently unavailable" a week later is what teaches a
crawler the file is not worth re-reading.

The 5,000-URL chunking went with it. It existed because the catalogue passed a
single file's 50,000-URL limit in one market alone; a market's editorial URLs are
a few thousand, so one file holds them. `2.xml` still answers, empty, while
crawlers forget it.

**Everything is in the first file, and the gate that put it there is still in the
code.** It was written when there were product chunks to keep it out of: the
brand block was gated from the start; the statics, the discovery modes, the
guides, the Shop Coves, the personas and four hundred dailies were not, so a
market with eight product chunks listed its editorial URLs eight times and
rebuilt them, alternates included, eight times over (gated together 2026-09-06).
With the chunks gone the gate is what makes any later file empty rather than a
duplicate.

**The board's answered questions, the popular-searches hub and the list help
are listed.** All three were linked from the header or footer and in no sitemap.
Questions are the one URL space here that grows from what visitors write, and
the index shows only the newest twenty — so without the sitemap the rest had no
discovery path at all. Answered questions only, because `AskController`
noindexes one nobody has answered.

*(2026-09-06 to 2026-09-12)* The `robots.txt` facet rules read `brand=` and `merchant=` and matched
nothing, because both parameters are arrays (`brand%5B0%5D=`). They were fixed to match the bracket,
then removed with the rest of the facet disallows on 2026-09-12.

*(Until 2026-09-12)* A product with no buyable offer was `noindex, follow`; the check had been "no
offer rows", which an out-of-stock row satisfies. It is indexable now, like every product — and
since 2026-09-18 no product is in the sitemap at all, so the rule that listed only in-stock, priced
products with an image has no URLs left to filter.

## Guardrails

`SeoTest` pins the behaviour, including four tests that exist because the
corresponding bug already happened once:

- Metadata never leaks between requests.
- Meta descriptions are real copy, never an unresolved translation key. Laravel
  returns the key unchanged when it cannot resolve one, so `site.search.seo_term`
  written as `search.seo_term` shipped a literal `search.seo_term` into
  production's meta description.
- A title-grouped product never claims a `gtin13`.
- Inertia is not told to look for an SSR bundle the `app` container never has.

The indexable-page sweep is derived from the route table, not listed — see
[page-titles.md](page-titles.md#the-test-that-was-supposed-to-catch-this).

`LocalisationTest` gates the two length budgets, beside the parity check that
already walks the same four language files.

## Not done yet

- **`Article` markup** on Coves and guides — they emit `ItemList` and, where written, `FAQPage`, but
  no `Article`.
- **Core Web Vitals have not been measured.**
- **Cross-language product titles.** ~4.5% of `be-fr` titles are Dutch. The
  honest fix is at ingestion, where the offer still knows its feed — see
  [product-titles.md](product-titles.md#language-is-not-one-of-the-tests).
- **`en` has no multi-merchant groups**, 0 of 16,531, so the comparison
  proposition and its `AggregateOffer` never appear in the English market.

## Alternates are batched wherever a list is rendered (2026-09-06)

The product page hands its own alternates to the shell through `PageMeta::setAlternates()`, so
the shell does not re-fetch the group the controller just loaded. The sitemap resolves every
non-product URL through `Alternates::forPaths()`, which sorts paths by kind and answers each
kind in one query — the same batching the product block had, now for guides, Shop Coves,
personas and dailies. Per-URL resolution on a cold cache was a query or two for each of five
hundred editorial URLs, per chunk.

Shared lists get a card of their own — see [social-cards.md](social-cards.md).

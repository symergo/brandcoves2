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

## Crawl budget

The real concern on search pages is not ranking, it is waste. Every filter
combination is a distinct URL and a facet UI generates a combinatorial explosion
of them. Left indexable, a crawler spends its entire budget on near-identical
filtered pages and never reaches the products and guides worth ranking.

| Page | robots |
|---|---|
| Bare search landing (`?q=term`) | index, follow |
| Filtered / sorted / paginated | **noindex, follow** |
| Empty result | **noindex, follow** |
| Product with offers | index, follow |
| Product with no offers | **noindex, follow** |

`follow` throughout, never `nofollow` — products are still discovered through
those links. Filtered searches canonicalise to the bare term so any ranking
signal consolidates onto one URL.

`robots.txt` blocks `/*/go/`: crawling an outbound affiliate hop burns budget on
redirects and looks like link-selling to a search engine.

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

Every page emits alternates for all five markets plus `x-default`, in the head
*and* in the sitemap — Google treats those as independent signals and picks the
sitemap version up faster on a new URL.

## Sitemaps

An index plus per-market files, 20,000 URLs each (the format caps at 50,000 and
the catalogue will pass that in one market alone). Only products worth landing
on are listed — in stock, priced, with an image. Submitting URLs that render as
"currently unavailable" wastes crawl budget and teaches the crawler that the
sitemap is unreliable. Multi-shop products get a higher priority, because a page
that actually compares offers is the better landing page.

## Guardrails

`SeoTest` pins the behaviour, including three tests that exist because the
corresponding bug already happened once:

- Metadata never leaks between requests.
- Meta descriptions are real copy, never an unresolved translation key. Laravel
  returns the key unchanged when it cannot resolve one, so `site.search.seo_term`
  written as `search.seo_term` shipped a literal `search.seo_term` into
  production's meta description.
- A title-grouped product never claims a `gtin13`.

## Not done yet

- **OG images** are the product photo. Generated cards showing price and seller
  count would convert better on social.
- **Guide and pick pages** (Phases 5–6) will need `Article` / `ItemList` markup.
- **Core Web Vitals** have not been measured; the Lighthouse pass is Phase 7.

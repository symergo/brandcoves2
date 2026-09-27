---
name: Speed
area: Core / Frontend / Operations
status: Active
date_added: 2026-09-27
---

# Speed

What was done to make the site answer faster, and why each piece is shaped the way it is. It
follows the speed audit of 2026-09-27 (live page timings, the production database, the server, the
background work and the code of every page). The audit's finding: the site was slow for avoidable
reasons, not for lack of hardware. The server was 85% idle, and `product_groups` had been read end
to end 221,810 times.

Each helper working on the audit's follow-up adds its own section here.

## Indexes and slow queries

Every number below was measured on production with `EXPLAIN ANALYZE` on 2026-09-27, before the
change. The indexes are in `database/migrations/2026_09_28_001200_indexes_for_the_slow_queries.php`.

### The new indexes

| Index | Serves | Before |
|---|---|---|
| `product_groups (market, brand)` | the brand filter (brand pages, search's brand facet) | 255 ms, a read of the whole table |
| `product_groups (market, lower(brand))` | `NextSteps::sameBrand()`, `SearchLanding::soldHere()` | 173 ms |
| `product_groups (market, category, merchant_count DESC, first_seen_at DESC) WHERE worth_showing AND in_stock` | `CoveRail`'s "more from these categories" | a sort of every match |
| `products (merchant_id, market, status)` | a shop's offers in one market | the `merchant_id` index plus a filter |
| `products (merchant_deep_link text_pattern_ops)`, partial | `LinkRouter::feedMerchant()`, a pasted shop link | a filter over the shop's offers |
| `wishlist_collaborators (user_id)` | lists a person helps with | the unique index leads on `wishlist_id` |
| `wishlists (recipient_id)`, partial | the lists about a saved person | none |
| `restock_alerts (user_id)`, partial | a person's own alerts | the unique index leads on `group_id` |
| `cove_plans (edition_id)`, partial | from a published Cove back to its plan | none |
| `popular_ranks (market, captured_on)` | the latest charts of a market | the chart index leads on `source` |

Things worth knowing about them:

- **Built `CONCURRENTLY`**, so nothing is locked while they build; that cannot happen inside a
  transaction, hence `$withinTransaction = false`. A concurrent build that fails leaves an
  *invalid* index under its name, which `IF NOT EXISTS` would skip forever, so the migration drops
  such a leftover first. A re-run is always safe.
- **The rail index matches the query exactly**: the same two equality columns, then the same two
  sort columns in the same direction, and a condition (`worth_showing = true AND in_stock = true`)
  that the query repeats. Postgres then reads the first six entries and stops. Change the sort in
  `CoveRail` and the index stops helping.
- **`lower(brand)` must be spelled exactly that way** in a query for the expression index to apply.
  Both callers do (`whereIn(DB::raw('lower(brand)'), ...)`).
- **The deep-link index uses `text_pattern_ops`**, because a plain btree cannot answer `LIKE
  'https://shop/path%'` outside the C collation. It only helps because every pattern in
  `feedMerchant()` is anchored at the start; a leading `%` would make it useless.
- **The deep-link index skips links of 2000 bytes or more**, and `feedMerchant()` repeats that
  condition so the planner may use it. A btree entry has a hard ceiling of about 2.7 kB: without the
  limit one freak URL in a feed would fail the index build (and so the deploy), and afterwards fail
  every ingest that tried to store it. The longest deep link locally was 464 bytes; nobody pastes a
  2 kB product URL.
- **Expand only.** `products_merchant_id_index` is now covered by the new three-column index and can
  be dropped later, together with the never-used indexes the audit found.

### Queries rewritten

**Alternates across markets (804 ms).** `Alternates::forProducts()` and the per-product lookup
asked `WHERE identity_key IN (...)` with no condition on `market`. The only index on `identity_key`
is the unique `(market, identity_key)`, and a btree cannot be entered on its second column alone, so
Postgres read the whole table. Both now add `WHERE market IN (<every market>)`, which filters
nothing out and lets the index answer each pair. Locally 31 ms became 1.7 ms. Covered by
`ProductAlternatesTest`.

**The gift engine's pool (267-504 ms).** `SuggestionEngine::pool()` retrieved candidates with
`EXISTS (an offer matches the text) OR jsonb_exists_any(gift_tags, ...) OR
jsonb_exists_any(crowd_tags, ...)`. Two things kept it off every index:

1. `jsonb_exists_any()` is the function behind the `?|` operator, and a GIN index serves operators,
   not functions. The two tag indexes had been scanned zero times on production, ever.
2. An OR is only indexable when every branch is, and a correlated EXISTS never is, so the whole
   predicate read every giftable group and probed its offers.

It now collects candidate ids first, as a UNION of three independent SELECTs (offers by full text,
groups by editor tags, groups by crowd tags), each on its own index, and filters and sorts that set
with the same conditions as before. It is the same rewrite `SearchService::applyTextMatch()` went
through in August. The operator is written `??|` in PHP because a bare `?` is a PDO placeholder;
PDO hands Postgres a single `?`. Locally the pool went from 223 ms to 56 ms and returned the same
653 groups.

**Search's tag filters.** `SearchService::storedQuery()` used `jsonb_exists_any()` for the "who,
what they love, what for" filters too. It now uses `??|`, so both branches of its OR (editor tags,
crowd tags) can use their index and be combined in a bitmap.

### Before deploying

- The migration builds ten indexes on production tables, `products` being the largest. Concurrent
  builds are slower than plain ones but lock nothing. Run it outside the night job windows.
- Worth one read-only query on production first:
  `SELECT count(*) FROM products WHERE octet_length(merchant_deep_link) >= 2000` says how many
  offers the pasted-link lookup no longer matches (expected: none).

## Payload and browser

### The site copy is sent once per language

Every Inertia page shares the site's translations (`Lang::get('site')`, the
whole of `lang/{language}/site.php`). That used to be "a few kilobytes". By
2026-09-27 it was the page:

| Measured on `/be-nl/help`, local | Before | After |
|---|---|---|
| Page data in the first document | 131 KB | 131 KB (unchanged) |
| of which translations | 129 KB (98%) | 129 KB |
| An Inertia navigation to the same page | 131 KB | 1.6 KB |

(`/be-fr/help`: 143 KB, of which 132 KB translations; a navigation 1.6 KB.)

It is now an Inertia **once-prop** (`Inertia::once()`, in
`HandleInertiaRequests::share()`). How that works, from the vendor code
(inertia-laravel 3.3, @inertiajs/core 3.6):

- Every response lists its once-props under `onceProps`, by key.
- On each Inertia visit the browser sends the keys it already holds in the
  `X-Inertia-Except-Once-Props` header, and the server leaves those props out.
- The browser then copies its own copy into the new page's props, so
  `useTranslations()` reads `props.translations` exactly as before.
- A full page load carries no such header, so the first document, and the
  server-side render of it, always has the strings.

**The key is the safety.** It is `translations:{language}:{mtime of site.php}`.
A visit that lands in another language (a link from `/nl-nl` to `/be-fr`)
names a key the browser does not hold, so the French strings come; a deploy
that changes the copy changes the mtime, so the first visit after it gets the
new strings. Keyed on the prop name alone, a language change mid-session would
have kept the old words. The switcher itself submits a form (a full page
load), so it never depended on this.

`translationVersion()` reads the mtime with one `filemtime()`, memoised for the
PHP process. Without worker mode that is one request; the memo saves only the
second read in that request. A stat costs microseconds, less than a cache round
trip, so it is not cached longer on purpose.

Tested in `TranslationsSentOnceTest`: the first document has it, a visit with
the key does not, another language and an older key both get it.

### The page's own JavaScript is preloaded

Pages are split per component (`app.tsx` imports them lazily). The browser used
to learn which chunk it needed only after `app.js` had downloaded and run: two
round trips in a row before the page could hydrate. `app.blade.php` now names
the page to `@vite` as well, as the Laravel React starter kit does, so Vite
emits a `modulepreload` for the chunk and its imports, and
`AddLinkHeadersForPreloadedAssets` repeats them as `Link` headers.

An entry Vite cannot find in its manifest throws, so the page entry is added
only when `resources/js/Pages/{component}.tsx` exists. Every component rendered
on 2026-09-27 has its file; the check is for the next rename.

A `preconnect` to `media.s-bol.com`, where most product images come from, opens
that connection while the HTML is still arriving.

### Server-side rendering

- **One renderer per core.** `ssr.tsx` runs Inertia's `cluster: true`, which
  forks `availableParallelism()` workers: 6 on the VPS. One Node process renders
  one page at a time, so a crawler burst queued there while PHP waited. Each
  worker is about 60 MB (the single process measured 60 MB in production), and
  staging runs its own, so about 0.8 GB together against 7 GB free.
- **Two seconds, not thirty.** Inertia posts to the renderer with a bare
  `Http::post()`, so a hung renderer held each request for Laravel's 30 s
  before falling back to rendering in the browser. `App\Support\SsrGateway`
  (bound in `AppServiceProvider` over Inertia's `HttpGateway`) adds a 2 s limit
  and 1 s to connect (`config('inertia.ssr.timeout')`, `INERTIA_SSR_TIMEOUT`).
  A real render takes tens of milliseconds. Measured locally: an unreachable
  renderer now falls back in 1.05 s. Its `dispatch()` is a copy of Inertia's
  with the timeout added, because the request is built inside that method;
  compare it with the vendor file when Inertia is upgraded.
- **Not for signed-in visitors.** The render exists for crawlers and for a
  first paint on public pages. Nobody who signs in is a crawler, and what they
  mostly open (lists, people, notifications) is private and never indexed.
  Leaving them out takes that load off the renderer and a class of hydration
  mismatches off personal pages. The cost is a blank moment on a full page load
  until the JavaScript draws the page, which the preloaded page chunk keeps
  short. It applies to every page for a signed-in visitor, not only private
  ones: telling them apart per route would be a list to maintain for little
  gain. Tested in `SsrGatewayTest`.

### Server and browser agree on dates

Anything rendered twice (once in Node, in UTC; once in the browser, usually in
Brussels) must come out the same, or React reports a hydration mismatch and
redraws. The list board's message dates and the footer year now format with
`timeZone: 'Europe/Brussels'`. A message written just after midnight used to
read one day on the server and the next in the browser.

### Smaller

- `/coves` linked its Daily band to `/{market}/daily`, which only 301s to
  `/{market}/tips` now; it links there directly (`Market::coveSegment()`).

### Considered and not done

- **The first image on the home page, eager and high priority.** The home page
  has no product image near the top: the first one is in the Daily band, the
  sixth section, well below the fold on a phone. Marking an off-screen image
  `fetchpriority="high"` takes bandwidth from what is on screen, so it stays
  lazy. Revisit if a product image moves into the hero.

## Shop page and admin

The shop page (`/shops/{slug}`) took 4.4 s warm on production for bol.com, and a handful of admin
screens did work per keystroke or per click that belongs elsewhere.

### The shop page

Measured locally against the development copy of production data, `/be-nl/shops/bol-com`, one
process, cache in memory:

| | first view | later views |
|---|---|---|
| before | 3,540 ms, 22 queries | about 800 ms, 14 queries, 660 ms of it SQL |
| after | 850 ms, 18 queries (the one-day fallback working the link list out) | about 95 ms, 8 queries, 24 ms of it SQL |

A Cove built after this change stores its link list, so its first view skips the ~310 ms link-list
query as well. Three changes:

- **The link list is stored when the Cove is built.** The categories a shop or brand Cove's
  `[[search:…]]` tokens may link to were worked out per view by grouping every active offer of the
  shop by category, twice per view. `EditionBuilder` now stores them in
  `daily_pick_sets.link_categories`; a Cove built before that falls back to working them out once a
  day. Why this is also the more honest answer: [cove-entities.md](cove-entities.md), "The link
  list is stored at build".
- **One cached list of shops per market.** `ShopDirectory::in()` (every enabled shop with active
  offers here, or a live source serving here) was an `EXISTS` over `products` per merchant, run up
  to four times on one shop page. It is cached for an hour per market (`bc:shops:{market}`), and
  so is a shop's product count. `/shops` and the shop band on `/coves` had their own copies of the
  query and now use this one; `requireDomain: false` keeps their rule that a shop without a domain
  is still listed. An hour late is the worst a newly onboarded shop can be, on a directory that is
  not where a shop is announced.
- **Asked once per view.** `GuideController::render()` resolves the shop, its link list and its
  product count once and hands them down, rather than each part of the page looking them up again.

Tests: `EntityRailsTest` (the stored list is what renders, and the grouping query does not run; the
fallback runs it once; the directory and the count run once and not at all on the next view).

### Admin

| Screen | Was | Now |
|---|---|---|
| Products, search box | `lower(title) like '%x%'`, which no index serves: every keystroke read the whole offers table (668 ms on production; 173 ms locally on 175k offers) | `title ilike '%x%'`, served by `products_title_trgm_idx` (27 ms locally). Typed `%` and `_` are escaped. The shop name is no longer searched: that joined every offer to its merchant per keystroke, and the shop filter answers the same question |
| Market supply badge, in the sidebar of every admin page | built every row, including a `count(distinct group_id)` over every offer (339 ms), cached only a minute | reads the small `feeds` table and the connector config only. Whether a market is dark depends on its sources, never on how many products it holds; the catalogue counts are cached separately and only the page itself asks for them |
| Market trends | the rank-history join ran four times per render (risers, new entries, fallers, active categories are four filters over one result), and the market tabs came from a `DISTINCT market` over the whole rank history | `MarketTrends::moves()` is remembered for the life of the object and the page holds one per request; the tabs ask one `EXISTS` per market, each stopping at its first row |
| Guide topics, "Refresh queue" | mined the search log and seeded the seasonal topics for all five markets inside the web request | queues one `RefreshTopicQueue` job per market and says so; the list is the report once they have run |

The memo on `MarketTrends` lives on the object, and the class is never bound as a singleton, so a
long-running queue worker cannot keep serving an old answer.

Tests: `AdminQueryCostTest` (search is an `ilike`, finds a typed `%`, no longer matches a shop
name; the refresh queues one job per market), `MarketSupplyTest::the_sidebar_badge_never_counts_the_catalogue`,
`MarketTrendsTest::the_admin_page_reads_the_moves_once_per_render`.

### Community Cove cards

Every listing of Community Coves (the band on `/coves`, the community index, Find a gift, the search)
loaded every column of every item and of its product, for a count and one picture per card. The
items still load, because which items a stranger may see is decided in PHP (a hand-written title
with a link in it is dropped by the same screen that guards the page, and that screen has no SQL
twin), but only the six item columns and the product's picture. The card is identical;
`CommunityCoveTest::a_listing_card_loads_only_what_it_shows_and_reads_the_same` compares it against
one built from the whole rows.

## Server and pipeline

The container, the web server and the request path in front of the controllers.

### Config and routes cached when the container starts

Laravel can compile its config into one file (`config:cache`) and its ~300 routes into another
(`route:cache`). Without them every request, and every artisan process the scheduler starts each
minute, reads every config file and registers every route before doing any work.

`config:cache` cannot run in the Dockerfile: it freezes the environment it runs in, and at build
time that is the builder's, without Coolify's runtime variables. One image serves staging and
production, and `SOURCE_COMMIT` only exists in the running container. So `docker/entrypoint.sh`
builds both caches when each container starts (app, queue, scheduler and migrate all run from the
image), then hands over to the base image's own entrypoint.

- **A failed cache does not stop the container.** The script clears it and the container serves
  uncached, as before, with a line on stderr. Coolify stops the old containers before the new ones
  are healthy, so a container that refuses to start is an outage, and an uncached site is only
  slower.
- **`env()` only in `config/`.** Once config is cached, Laravel stops loading `.env`, and an `env()`
  call elsewhere is the wrong place to ask. `COOLIFY_BRANCH` (read by `/health` and the admin
  Migration page) became `config('giftcoves.branch')`. `bc:make-admin` still reads
  `BC_ADMIN_PASSWORD` with `env()` on purpose: it is a shell variable set for that one command, and
  a real process variable stays visible to `env()` under a cached config.
- **Locally nothing is cached.** If you ever cache by hand, run `php artisan config:clear` and
  `route:clear` afterwards, or a changed `.env` or route file will seem to do nothing.

### A Caddyfile instead of `php-server -v`

The app container ran `frankenphp php-server --listen :80 --root /app/public -v`. It now runs
`frankenphp run --config /app/docker/Caddyfile`, which does the same (plain HTTP on :80, root
`/app/public`, zstd/br/gzip, `php_server`) plus two things php-server cannot:

- **Hashed bundles cached for a year.** `/build/assets/*` gets `Cache-Control: public,
  max-age=31536000, immutable`: Vite puts a content hash in each file name, so a changed file is a
  new URL. Only for a file that exists (the `file` matcher): otherwise a request for an old bundle
  after a deploy would fall through to Laravel's 404, and that 404 would be cached for a year.
  `/icons/*` and `/favicon.ico` keep their names across deploys, so they get a day. Before this,
  none of them had any `Cache-Control`, and every visit revalidated every bundle.
- **An access log instead of debug logging.** `-v` was Caddy's debug level: about 39,000 lines in
  ten hours, and not the one line per request that says how long it took. The Caddyfile logs one
  JSON line per request to stderr, with `duration` (seconds), `status`, `size` and `request.uri`.
  `/health` is not logged (every 30 s, it would be most of the file). Caddy already redacts cookies
  and `Authorization`, and `X-Forwarded-For` / `X-Real-Ip` are deleted because they carry the
  visitor's IP address. Read it with `docker logs <app container> 2>&1 | grep handled`.

The Caddy admin API is off (`admin off`): nothing reloads config at runtime, and the compose file
replaces the only healthcheck that used it. The file's header says how to validate a change with
the image, without building it.

### The healthcheck probes every 30 s, every 2 s while starting

The app healthcheck ran `/health` (a database and a Redis check) every 5 s, about 17,000 times a day
per environment. The 5 s was there for deploy speed: Traefik routes to a new container only after
its first passing probe. `start_interval: 2s` now keeps that speed during `start_period` (40 s), and
`interval: 30s` applies once the container is up. Retries went from 10 to 3, so a container that
stops answering is marked unhealthy after about 90 s (it was 50 s). The probe also has its own 4 s
socket timeout: `file_get_contents` otherwise waits PHP's default of 60 s.

`start_interval` needs Docker Engine 25 or later. Compose refuses the file on an older engine rather
than ignoring the key, so the first staging deploy proves it; staging and production share the host.

### Machine-read routes are stateless

`/health`, `/media/items/*`, `robots.txt`, the sitemaps and `/{market}/og/*` went through the full
`web` middleware group. Each fetch started a session (a Redis write; crawlers keep no cookie, so
every fetch started a new one) and answered with two `Set-Cookie` headers, the session and
`XSRF-TOKEN`. A response with `Set-Cookie` is one no shared cache keeps, so the one kind of URL that
is the same for everybody could not be cached.

They now skip `StartSession`, `ShareErrorsFromSession`, the CSRF check (`PreventRequestForgery`,
Laravel 13's name for it), `AddQueuedCookiesToResponse`, `TrackAnonymousIdentity` and
`HandleInertiaRequests`. The list is `App\Http\StatelessRoutes::SKIPPED`, applied with
`withoutMiddleware()` in `routes/web.php`. `SetMarket` stays, because a social card draws in its
market's language.

Our 404 page is an Inertia page whose shared props read flash messages from the session, so it
cannot render on these routes. `bootstrap/app.php` hands a request without a session the
framework's plain 404, which is also the right answer for a missing PNG or sitemap chunk.

`TrackAnonymousIdentity` (the `bc_visitor` cookie) also skips `media/*` now, and recognises three
more link-preview agents: Slackbot, its `LinkExpanding` fetcher and `Google-InspectionTool`.

Tested in `StatelessRoutesTest`: no cookie and no identity row on each route, a plain 404 for a
missing card or picture, and a control page that does set cookies, so "no cookies" means something.

### Social cards answer 304 without drawing

The card's ETag was md5 of the PNG, so a platform revalidating its copy made us draw a product card
(58 ms; product cards are never cached) or read another card from Redis, only to send the same
bytes back. The ETag now comes from the card's version, the same commit + record + drawn-text hash
the cache key uses, which is known after one row lookup. A matching `If-None-Match` gets an empty
304. Tested in `OgImageCacheTest`. See [social-cards.md](social-cards.md).

### The serendipity job is off the schedule

`score-serendipity` ran at 05:25 and 17:25 and was failing on a timeout twice a day, while the owner
has the surprise score switched off as a trial (2026-09-27, decision due around 2026-10-11). The
schedule entry is replaced by a comment with the code to restore it; see
[serendipity.md](serendipity.md).

### Check on staging after the push

- `/health` answers with the pushed `commit` and `branch: main`. Both come from the cached config,
  so this proves `config:cache` ran with Coolify's variables and not the builder's.
- `docker logs` on the app container: no `entrypoint: ... failed` line, and one JSON access line per
  request with a `duration`.
- A hashed bundle (`curl -sI --max-time 5 https://staging.giftcoves.com/build/assets/<file>.js`)
  carries `Cache-Control: public, max-age=31536000, immutable`.
- `curl -sI --max-time 5 https://staging.giftcoves.com/robots.txt` carries no `Set-Cookie`.
- A client-side interaction still works. The `VITE_*` build variables are untouched by this, but
  it is the failure that looks like nothing.

## Cove pages

The pages that show a Cove (a Daily, a gift persona, a guide, a brand page with a written Cove, a
shop page) and the pages that list them (`/coves`, Discover, `/guides`, `/gift-ideas`, `/brands`),
plus the contribute board and the legal pages. Two kinds of change, following the owner's decision
of 2026-09-27: work out at build what only changes at build, and keep shared lists in the cache for
a few minutes. No "cache until something changes" scheme; the one exact forget is at publish.

### A Cove's prose is rendered at build and stored

Every view of a Cove page turned its text into HTML again: it built the link allowlist (the 300
largest brands with a page and the 200 newest articles of the market, two queries), looked up brand
page addresses (a third), and ran the token and paragraph passes over the article, its FAQ and each
product's copy. None of it depends on the visitor or on stock.

`App\Services\Cove\CoveProse` now does it when `EditionBuilder` builds, rebuilds, refreshes
(`refreshCopy`) or redoes a Cove, and stores the result in `daily_pick_sets.rendered_prose`
(migration `2026_09_28_001300_a_cove_keeps_its_rendered_prose`, one nullable column, expand-only).
The pages read it through `CoveProse::for()`. What is stored, per page:

| Page | Stored |
|---|---|
| Daily, persona | the editorial as blocks (paragraph html + the products it names) |
| Guide, seasonal, advice | intro and body blocks, each product's copy by pick id, the FAQ, and the plain text for the meta description and the FAQPage JSON-LD |
| Shop Cove, Brand Cove | the intro html, the body paragraphs, the plain blurb |

Everything that depends on stock or price (the finds, the in-stock filter, prices, the rails) and
everything with the host in it (canonical URLs, the JSON-LD's absolute URLs) stays live.

Things worth knowing:

- **`json`, not `jsonb`.** `jsonb` stores an object with its keys re-sorted, so a paragraph would
  come back with its keys in another order and the page props would differ from a live render.
  `json` keeps the text as written; nothing queries inside the column.
- **A fingerprint decides whether the stored value is used.** It hashes everything the render
  reads from the Cove itself: kind, market, slug, blurb, editorial, body, FAQ, source queries, the
  stored link list and each pick's id, product and copy. Several writers change those with plain
  queries (`bc:tidy-prose`, a product merge re-pointing picks, the content import), so a model
  event would miss them; a fingerprint cannot. When it does not match, or nothing is stored, the
  page renders live and caches that for a day under a key with the Cove's id, `updated_at` and
  the fingerprint, so an edit is never served stale.
- **What freezes.** A stored link reflects the site at build: a brand page that later disappears
  still gets its link, a guide unpublished since still gets its link, a retitled product keeps
  its old words in an unlabelled token. Accepted, because every such link goes to our own brand,
  guide, product or search page, never to a shop.
- **What does not freeze.** A `[[guide:...]]` to an article not published yet, or a
  `[[brand:...]]` for a brand without a page yet, renders as plain text today; stored, it would
  stay plain text for good. So a render with such a token is not stored, and the page renders it
  live with the one-day cache: the link appears within a day of its target. (`ProseCards` now
  collects the tokens it could not link for this.)
- **Bump `CoveProse::VERSION`** when `CoveMarkup` or `ProseCards` change what they output, or stored
  Coves keep the old markup until they are rebuilt.
- **Storing never fails a build.** It runs after the build's transaction commits and logs a
  warning on error; the page can always render the prose itself.
- **Storing does not touch `updated_at`.** The sitemap reads it as the page's last change.
- **Not exported.** `bc:export-content` drops `rendered_prose`: it holds this environment's
  product ids in its links, and the far side renders its own.
- **Older Coves**: `php artisan bc:store-cove-prose --write` renders and stores every published
  Cove whose prose is missing or out of date (dry run without `--write`). Run it once after the
  deploy that adds the column; until then those Coves use the one-day fallback.

Tested in `CoveProseTest`: for each of the five pages, the page rendered from the stored prose is
byte for byte the page rendered live (props and JSON-LD); the page reads the column; a plain-query
edit shows at once; a link to an unpublished article is not frozen; the backfill leaves
`updated_at` alone.

Query counts on a warm page, locally (every other cache warm; "live" is the prose rendered again,
as every view did before):

| Page | live | stored |
|---|---|---|
| Daily | 11 | 9 |
| Persona | 11 | 9 |
| Guide | 12 | 8 |
| Brand page with a Cove | 11 | 6 |
| Shop Cove | 7 | 5 |

The two queries saved on every page are the allowlist's; the rest is brand page lookups and
merged-product lookups in the prose. On production the allowlist queries sort `brand_stats` and the
published articles of a market, so the saving per view is larger than the count suggests.

Also: `EditionPresenter::guide()` loaded the Daily's footer guide and counted its picks lazily, two
queries per view; `DailyCoveController` and `GiftIdeasController` now eager-load it with the count.

### Short caches for shared lists

Per market, plain arrays only: `config/cache.php` refuses to unserialise objects in Redis
(`serializable_classes`), so a cached model comes back as `__PHP_Incomplete_Class`. Nothing
per visitor is in any of them (saved state and sign-in come from the shared props, per request).

| What | Key | TTL | Why that long |
|---|---|---|---|
| `CoveRail`: a category's most-compared products | `bc:cove-rail:category:{market}:{md5}` | 30 min | moves when the catalogue is regrouped, twice a day. 23 rows are kept so the Cove's own picks (up to 20) can be dropped in PHP and the band still fills: one list serves every Cove sharing the category |
| `CoveRail`: newest Coves of a band | `bc:cove-rail:coves:{market}:{band}` | 30 min | one more than shown, the Cove being read dropped in PHP |
| `CoveRail`: a season's parts | `bc:cove-rail:series:{cove id}` | 30 min | a new part is published a few times a year; wrapped in an array because `remember()` treats a cached null as a miss, and null is the answer for most Coves |
| `/coves`, every section | `bc:coves:{market}` | 10 min | the community band changes without a build; ten minutes is a short wait on an overview |
| Discover: today, earlier days, personas, guides | `bc:discover:{market}` | 10 min | today's finds are filtered on stock inside it; a sold-out find can stay ten minutes on a hub card |
| Discover: the surprise pool (ids) | `bc:discover:{market}:surprise-pool` | 10 min | the draw from it stays per request, so the band still differs per visit |
| Brand page: Coves mentioning the brand | `bc:brand:coves:{market}:{slug}` | 1 h | a regex over every published article per view; changes when an article is published |
| Brand page: related brands; `/brands` | `bc:brand:related:{market}:{slug}`, `bc:brands:{market}` | 30 min | `brand_stats` is rebuilt nightly |
| Contribute: the ideas and vote counts | `bc:feature-board` | 5 min | forgotten on a vote and on any idea save; which ideas *you* voted for is read per request |
| Legal pages, rendered | `bc:legal:{page}:{lang}:{mtime}:{company hash}` | 1 day | a changed file or imprint is a new key, so it shows at once |

Without a cache, two pages lost work outright: `/guides` selects only the seven columns its cards
use (it loaded every column, body and stored prose included, for sixty cards), and the
`/gift-ideas` shelf counts each persona's in-stock finds in SQL (`withCount`) instead of loading
every pick and its product for one number per card.

### A new Cove shows at release

`App\Services\Cove\CoveCaches` names the Cove-list keys (`/coves`, Discover, the rail bands) and
forgets them for a market in `forgetMarket()`, which `BuildDailyEdition`, `BuildCove` (what
`PublishDueCoves` dispatches) and `RedoCove` call after a build.

That alone would not cover the Daily: it is built at 06:00 and only counts as published from its
drop time (`giftcoves.picks.drop_time`, 09:00). A list cached at 08:55 would carry yesterday's
edition until 09:25. So every Cove-list TTL goes through `CoveCaches::ttl()`, which never runs past
the next drop time. No job has to run at 09:00.

Not forgotten on purpose: the home page's shelf of other Coves (`home.coves:{market}`). It leaves
the Dailies out and is a random draw held for an hour so that a reload shows the same shelf; the
home page's Today band is read uncached.

Tested in `CoveCachesTest` (a Cove built through `BuildCove` is on `/coves` and Discover at once;
another market's keys are left alone; a TTL at 08:55 ends at 09:00).

Measured locally on the first view (caches empty) and the second, same data as `CoveProseTest`:

| Page | first view | second view |
|---|---|---|
| `/coves` | 8 queries, 279 ms | 1 query, 27 ms |
| Discover | 11 queries, 100 ms | 3 queries, 39 ms |
| Brand page with a Cove | 12 queries, 107 ms | 6 queries, 29 ms |
| Guide | 9 queries, 54 ms | 5 queries, 37 ms |
| Persona | 21 queries, 114 ms | 6 queries, 33 ms |
| Contribute | 2 queries | 1 query |
| Privacy | 138 ms | 18 ms |

## Lists, people and gifts

Repeated queries on the pages a signed-in person uses most: My Coves (the overview of their lists),
a list, a shared list, a person's page and Find a gift. Nothing here changes what anybody sees or may
do; the pages ask the database fewer times for the same answer. Measured with
`ListQueryCountTest`, on a fixture with lists of every kind and every way a list reaches somebody:

| Page | Before | After |
|---|---|---|
| My Coves, 6 lists | 32 queries | 15 |
| My Coves, 18 lists | 52 | 15 |
| A list, 3 items, few lists to copy to | 22 | 19 |
| A list, 12 items, more lists to copy to | 31 | 19 |
| A shared list, a reader opening it again | 15 | 10 |

The test holds the "after" numbers equal between the small and the large fixture: a query per row is
what these pages had, and the equality is what catches it coming back.

### Which lists may I open (`ListAccess::scope()`)

The one question every list page asks, and a security boundary. It was one statement:
`owner = ? OR EXISTS (a collaborator row) OR (not private AND (EXISTS (an open) OR EXISTS (a
share)))`. Postgres cannot use an index for an OR of correlated EXISTS subqueries, so it read every
row of `wishlists` and probed three tables for each.

Now it is two steps. `ListAccess::reachableIds()` gets the lists a person was let into with one
`UNION ALL` of three `user_id` index lookups (`wishlist_collaborators`, `list_opens`,
`wishlist_shares`), split into *direct* (a collaborator row, which holds whatever the visibility) and
*bookmarked* (a followed link or a share, which hold only while the list is not private). The outer
query is `owner = ? OR id IN (direct) OR (not private AND id IN (bookmarked))`: an owner index and
the primary key. Not memoised, because a request that records an open and then asks again must see
it.

`ListAccess::allows()` answers the same question for one list already loaded. The list page and the
adding mode (on every page, through the shared props) load their one list by key and ask it, rather
than resolving everything the person was let into to find one row.

`ListAccessScopeTest` keeps the old one-statement query as a reference and holds the new scope,
`allows()` and the list page's 200/404 to it for every route: owner, collaborator (viewer and editor,
private list included), a followed link, a share, a list set back to private after it was opened or
shared, strangers, other people's opens and collaborations, and an anonymous visitor (plain
ownership; an open recorded against a cookie is never access).

### My Coves

- **Two queries, not six.** Each section (mine and theirs, per kind) was its own query, repeating the
  eager loads. It is one query for my lists and one for the lists others let me into, cut into the
  sections in PHP in the same order. Still two and never one: the suggestion count may only be
  attached to rows I own (see `rows()`).
- **`hasCoGivers()`** asked `collaborators()->exists()` per card (twice, through
  `allowsClaiming()`). The query now carries `withExists('collaborators')` and the model reads it.
  Deliberately not the loaded `collaborators` relation: on somebody else's list My Coves loads only
  my own row of it, which answers a different question.
- **`canEdit()`** asked for my collaborator row per card. It reads it from the rows already loaded
  when there are any (My Coves: my own row; the list page: all of them), picking mine by user id.
  Only those two places load the relation, and both hold my row if I have one.
- **Saved Coves** loaded each saved Cove's whole shortlist and each saved Community Cove's every
  item with its product, to show one picture. Now one pick with a picture per Cove and one item per
  list, chosen by the rule `CommunityCoves::card()` uses: the newest item with a product picture
  that does not render live (Amazon).

### A list and a shared list

- The list page loads the owner and the recipient's person with the list, counts its loaded items
  instead of asking again, and counts quiz plays with `withCount`. The copy menu
  (`ListOptions::copyTargets()`) loads my own collaborator row on each list, so its per-row
  `canEdit()` asks nothing.
- The shared page counts claims ("3 of 11 spoken for") and "have I claimed something" from the
  items it has already loaded. The counting stays in `ClaimView::progress()`, now given the items,
  so ClaimView remains the one place claim state is applied (invariant 4). Votes are loaded only
  where voting is on.
- **Opening a shared list writes at most once an hour.** Every open upserted `list_opens` and the two
  `friendships` rows, and after the first time the upserts only moved a timestamp that nothing reads
  to within an hour. `ListOpen::recordFromRead()` and `Friends::linkFromSharedList()` skip a repeat
  inside the hour on a cache marker (a plain `true`, never an object). The first open always writes,
  and that is the one that grants the bookmark. `Friends::unlink()` clears the marker, so somebody
  who removed a friend and opens their link again is reconnected at once, as before.
- **The save picker** in the shared props (`ListOptions::forPicker()`, on every signed-in page)
  selects the six columns it draws plus the recipient's name, not whole rows with descriptions and
  encrypted addresses. It is a closure there, so an Inertia partial reload that does not ask for
  `lists` never runs it at all.

### A person's page and Find a gift

- **The next steps are kept an hour.** `NextSteps::forRecipient()` (three candidate queries, one a
  24-word ILIKE, and the scoring) ran on every view of a person's page and every board for them. The
  ranked ids are cached per person, market and limit, under a hash of everything that goes in: the
  past gifts looked at (title, brand, category, product, year), the budget, the year and every
  excluded product (their history, their lists, the board on screen). Change any of those and it is
  a different key. The products themselves are loaded fresh, through the same "can be shown" filter,
  so one that went out of stock inside the hour drops out. Plain arrays only: the Redis store
  refuses to rebuild objects (see `config/cache.php`).
- **The gift history is read once per request** in `GiftController` and handed to the exclusions and
  the next steps; it was read two or three times.
- **"Four more" is one engine run.** It needs the board on screen (to remember it) and the next one,
  and ran the whole engine twice for them. `SuggestionEngine::suggestTwo()` picks both from one
  scored pool. The one difference from two runs: a second retrieval, with four more products
  excluded, could reach up to four candidates past the 300th (newest first). Those rarely place, and
  the price was a full second run.
- **The engine's candidates skip `display_vector`**, a search column no PHP reads. Everything else is
  still read: `EditionBuilder` keeps `surprise_breakdown` with a persona's picks. A column added to
  `product_groups` later is absent from suggestions until it is named in
  `SuggestionEngine::POOL_COLUMNS`.
- The person page fetched every friendship to pick out one; it fetches the one.

### `/for/{token}` makes nothing on a GET

A person's own link (`/for/{token}`, where they say what they like and keep their own list) made
"my list for them" for whoever opened it, on the GET. A link preview, a prefetch or a crawler holding
a cookie left a list behind, and a GET that writes is one no page cache can ever keep. The GET only
reads now. A signed-in visitor with no list yet gets `startsList`, and the page POSTs
`/for/{token}/list` as it opens, which makes it and comes back with the add panel. A visitor with
only a cookie cannot add, so they get their list after signing in. See
[gifting-lenses.md](gifting-lenses.md).

`/for/{token}/suggest` runs the suggestion engine for anybody holding the link, so it has its own
limit of 30 a minute on top of the group's 60. It has its own counter (`throttle:30,1,for-suggest`):
a bare `throttle` shares one counter per visitor with every throttled route, and two on one route
would count each request twice.

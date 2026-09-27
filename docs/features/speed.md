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

# CLAUDE.md — GiftCoves 2

Multi-market product search, offer comparison, gift discovery and buying guides.
A clean-room rebuild of the v1 WordPress site at `../brandcoves` (referenced for *scope and product
thinking* only — no code is ported).

## Feature documentation rule

**Every time you add, modify or remove a feature, update `docs/features/`.** One `.md` per feature,
indexed in [docs/features/INDEX.md](docs/features/INDEX.md). Record *why* a non-obvious decision was
made, not just what the code does — the reasoning is the part that cannot be recovered from a diff.

---

## Stack

| Layer | Choice | Notes |
|---|---|---|
| Framework | Laravel 13, PHP 8.4 | |
| Database | PostgreSQL 16 | `pg_trgm` + `unaccent` required |
| Cache / queue | Redis 7 | Horizon in production |
| Admin | Filament 5 | `/admin`, gated on `users.is_admin` |
| Frontend | Inertia 3 + React 19 + Tailwind 4 | Blade for the document shell only |
| Server | FrankenPHP | single process, no nginx/fpm split |
| Deploy | Coolify on `51.75.78.173` | `staging` and `main` branches → two apps |

## Commands

```bash
docker compose up -d          # postgres :5432, redis :6379, mailpit :8025
composer dev                  # serve + queue + vite + ssr, all at once
php artisan migrate --force
composer lint                 # Pint
composer test                 # parallel, 8 processes — 39s, against real Postgres
composer test:serial          # one process — 150s. For when parallelism is the suspect
git config core.hooksPath .githooks   # once per clone: run the suite on push
```

> **Run the tests the change touches; run the full suite before production.** During a working
> session, reach for the narrowest filter that exercises what you just edited —
> `php artisan test --filter=SomeTest` or a single file — and say which one ran, so "tests pass" is
> never read as "the suite passes". `composer test` is for one moment: **before a push to `main` or
> a fast-forward of it**, plus whenever it is explicitly asked for. The pre-push hook runs the suite
> on every push including staging, so the deliberate run buys you a green result *before* you commit
> to shipping, rather than at the moment the deploy is already in flight.

> **The suite runs on push, not on save — locally *and* on GitHub.**
> [`.github/workflows/tests.yml`](../.github/workflows/tests.yml) runs it against a real Postgres 16
> service on every push and PR; that one cannot be skipped, forgotten or bypassed with `--no-verify`.
> `.githooks/pre-push` runs the same suite before the push leaves the machine, which is faster
> feedback but only after `core.hooksPath` is set — per-clone local config the repo cannot do for
> you. Treat the hook as the early warning and CI as the gate. See
> [docs/testing.md](../docs/testing.md) for why 8 processes locally and 4 in CI, and why `APP_DEBUG`
> is pinned false in the test run.

> **PHP is Herd's, not WinGet's, and the dev stack runs supervised.** Smart App Control blocks the
> WinGet build's unsigned `php8ts.dll`, so `php.exe` exits with `0xC0E90002` printing *nothing* and
> `composer dev` dies with exit code 2 and no output. Herd's PHP is non-thread-safe, has no
> `php8ts.dll`, and clears SAC on reputation. The stack itself runs under the `GiftCoves Dev Server`
> scheduled task (at logon) via `scripts/dev-server.ps1`, which starts Docker, waits for postgres,
> reaps port-squatting orphans and restarts the stack when it dies. Stop it with
> `.\scripts\dev-stop.ps1`. See [docs/local-dev.md](../docs/local-dev.md).

> **`composer dev` does not run Pail, and must not.** Pail needs `pcntl`, which does not exist on
> Windows — and `concurrently` runs with `--kill-others`, so Pail's immediate crash took `artisan
> serve` down with it and left `localhost:8000` refusing connections seconds after start-up. The
> failure reads as "the dev server never came up", because by the time you look, it hasn't. For logs,
> read `storage/logs/laravel.log` directly (`Get-Content storage\logs\laravel.log -Wait -Tail 50`).

Operational commands, all idempotent and safe to re-run:

```bash
php artisan bc:refresh-discovery      # giftability → serendipity → brand stats → today's edition
php artisan bc:plan-coves             # draft the editorial calendar 120 days ahead, each day
                                      # pre-filled with the products the builder would pick, for
                                      # a person to curate. Needs bc:refresh-discovery to have run
                                      # first, or there are no scored candidates to suggest
php artisan bc:make-admin             # create an admin; refuses a password in argv (visible in ps)
php artisan bc:check-bol              # prove the bol credentials; prints lengths, never values
php artisan bc:check-ebay             # the same for eBay, plus the marketplace mapping — which
                                      # this repo guesses per market, and gets no error for
php artisan bc:check-tradedoubler     # the same for Tradedoubler. --raw prints the real payload
                                      # shape, which that connector was written without
php artisan bc:check-config           # did this environment's config arrive? lengths, never values
php artisan bc:export-content         # editorial (feeds, coves, guides, copy) as a portable envelope
php artisan bc:import-content --in=-  # apply one here. Dry run unless --write
php artisan bc:api-token              # mint/list/revoke an editorial API key; plaintext shown once
php artisan bc:prune-personal-data   # enforce the published GDPR retention windows
php artisan bc:awin-feeds             # discover Awin advertiser feeds, register them per market
php artisan bc:ingest                 # run feed ingestion now
php artisan bc:withdraw-source --market=en --source=bol   # suppress the offers a source
                                      # left behind after it stopped serving a market.
                                      # Turning a connector off does NOT hide what it
                                      # already stored. Dry run unless --write; --restore
                                      # is the undo; refuses while the source still serves
php artisan bc:pull-charts            # pull bestseller charts — the demand signal, never a page
php artisan bc:pull-charts --market=be-nl --discover   # prove the endpoint and the response
                                      # envelope in one request. Writes nothing
php artisan bc:refresh-guide-copy     # re-write guides that have no editorial, then stale ones
php artisan bc:tidy-prose             # bring published prose into house style: em dashes out,
                                      # stray ** off the fields that cannot render it. New writing
                                      # is already correct (App\Services\Editorial\HouseStyle runs
                                      # at the write); this is for the archive. Idempotent, dry run
                                      # unless --write
php artisan bc:seed-advice-coves      # publish the shipped advice articles from
                                      # resources/content/advice-coves.php. Idempotent, and it
                                      # never overwrites a Cove a person edited — --replace does,
                                      # and asks first. The deploy migration runs this too, so
                                      # this is for after you have edited the file
php artisan bc:scrub --force          # MANDATORY after restoring a production dump
```

A fresh deploy has empty discovery surfaces until the next scheduled window, so
`bc:refresh-discovery` is the first thing to run against a new environment — and `bc:pull-charts`
second, because the demand signal it collects has no other source and the guide-topic queue is empty
without it on a market with no search traffic yet.

> **`--env=testing` needs `.env.testing` to exist, and it is gitignored.** Laravel falls back to
> `.env` when the named environment file is missing, and it does so silently — so on a fresh clone
> `php artisan migrate:fresh --env=testing` reads `.env`, resolves to the **development** database and
> drops it. That happened on 2026-08-10. `.env.testing` is checked in as `.env.example`'s sibling in
> spirit only: recreate it locally, pinned to `DB_DATABASE=brandcoves_test`. The suite itself never
> needs it — `phpunit.xml` sets its own env vars, and `RefreshDatabase` migrates the test database on
> its own. There is no reason to run `migrate:fresh` by hand between test runs at all.

PHP, Composer and Node run on the **Windows host** — bind-mounting a PHP project into a Linux
container on Windows makes every request stat hundreds of autoloaded files across the filesystem
boundary. Only the infrastructure is containerised locally.

---

## Non-negotiable invariants

These are the rules the product depends on. Breaking one is a bug even when tests pass.

1. **AI is only ever called from a queued job.** Never from a request handler, never from a Blade
   view or an Inertia controller. A visitor request must not be able to cause AI spend. Every AI
   caller registers a `feature_key` in `config/giftcoves.php` and is capped per day via `AiUsage`.
   With `AI_ENABLED=false` the whole site still works.

2. **Product identity is scoped to the market.** `product_groups` is unique on `(market,
   identity_key)`. The same product in two markets has different tax, shipping and availability, so
   those offers are not interchangeable — merging them lets a foreign price masquerade as "cheapest".

3. **`products` rows are OFFERS, not products.** One row = one merchant selling one thing in one
   market. `product_groups` rows are physical products. Search, product pages, gift picks and guides
   all operate on **groups**. This split is what makes offer comparison possible.

4. **Claim state reaches the list owner only if they asked for it.** A wish list exists so the
   recipient does not know what has been bought, so it is hidden by **default** and nothing may
   infer otherwise — not sharing, not inviting somebody, not an occasion. Only an explicit
   `wishlists.owner_sees_claims` turns it on (null = never asked; the kind decides). A list *about
   somebody else* defaults the other way, because there the owner is a co-giver and the recipient
   never opens the page. `wishlist_items.claimed_by_hash` is `$hidden` on the model, and
   `Wishlist::shouldHideClaimsFrom()` is the single place the question is answered —
   `App\Services\Wishlist\ClaimView` the single place it is applied. Reworded 2026-08-29; it read
   "never reaches the list owner", which was the rule before the owner could choose.

5. **Affiliate URLs are hostile input.** They come from third-party feeds. Scheme-check (`https:`
   only, via `Product::hasSafeAffiliateUrl()`) before any redirect. HTML escaping alone happily
   preserves `javascript:`.

6. **Amazon may not be mirrored.** Store the *decision* (ASIN + scoring metadata), re-fetch title,
   price, image and availability live at render. A failed fetch hides the item rather than showing
   stale data. `Source::allowsCatalogueStorage()` encodes this.

7. **Prices are integer cents.** Floats accumulate error across the min/median aggregates that drive
   "cheapest offer" and discount badges, both of which must be exactly right.

8. **Long work is chunked and resumable.** A feed runs to hundreds of MB. Jobs record their cursor in
   `ingestion_jobs` and resume; a redeploy mid-run must not lose the work.

---

## Conventions

- **`market`, never `locale`.** Laravel already has an app locale for framework strings. `be-nl` and
  `nl-nl` are the same *language* and different *markets*. `App\Enums\Market` is the single source of
  truth; `SetMarket` middleware resolves it from the `/{market}/` route prefix.
- **A chosen market beats a guessed one, and only the switcher chooses.** When a URL carries no
  market, `MarketPreference::resolve()` decides: the `bc_market` cookie first, `Accept-Language`
  second. Write that cookie **only** from an explicit switcher POST — never from `SetMarket`, or
  opening a friend's shared `/nl-nl/...` link silently repoints the visitor's home market. See
  [docs/features/market-routing.md](docs/features/market-routing.md).
- **Enum-ish columns are `string` + a CHECK constraint**, not native Postgres enums. Altering a PG
  enum cannot run inside a transaction, which makes every future value addition a deploy hazard.
  Cast to a PHP enum on the model.
- **Business logic lives in `app/Services/`**, not in controllers or jobs. Jobs orchestrate; services
  decide. Scoring and classification rules go in pure, unit-testable classes — that is where the
  subtle bugs live.
- **Filament's stylesheet ships no Tailwind utilities.** `public/css/filament/filament/app.css` is
  prebuilt and contains only Filament's own `fi-*` classes, so a `class="flex gap-3 rounded-lg"` in a
  custom panel page renders and lays out *nothing* — a failure that looks exactly like a page nobody
  styled. `resources/css/filament/admin/theme.css` is loaded alongside it and supplies the utilities,
  scanned from `app/Filament` and `resources/views/filament`. It imports Tailwind's theme as
  `theme(reference)` and no preflight, so it can neither override Filament's palette nor reset the
  panel. Adding, not replacing: `viteTheme()` would swap Filament's stylesheet out and needs
  `vendor/filament` at CSS-build time, which the Dockerfile's frontend stage does not have.
- Strict types everywhere: `declare(strict_types=1);`.
- Comment the *why*, especially for a threshold, a weight, or a workaround. A number with no
  justification will be "cleaned up" by someone later.

## Search notes

Two mechanisms, because they fail differently:

- `products.search_vector` is a **stored generated column** built by `bc_search_vector()`, weighted
  title A / brand B / category C / description D, stemmed per market via `bc_text_config()`. Changing
  that function does **not** rewrite existing rows — that needs an explicit backfill migration. In
  PG16 the only way to change a generated column's expression is to drop and re-add it, and that
  re-add *is* the backfill; see `2026_08_10_000500_add_description_to_the_search_vector`.
- **A brand's identity is its slug, not its name.** Feeds disagree about punctuation —
  "Audio-Technica" and "Audio Technica" are one brand — so `brand_stats` holds one row per
  `(market, slug)` with the spellings in `aliases`, and a brand page filters on all of them. The
  folding is done in PHP with `Str::slug()`, never in SQL: Postgres cannot reproduce it, because it
  transliterates ("Kärcher" → "karcher") where `lower(replace(...))` does not.
- Trigram fuzzy matching: **query with the `<%` (word_similarity) operator, not `%`.** `%` uses
  `similarity()`, which compares whole strings — measured, `"blutooth koptelefon"` against
  `"Draadloze Bluetooth Koptelefoon met ruisonderdrukking"` scores **0.298**, just under the 0.3
  default, so the typo finds nothing. `word_similarity()` scores **0.696**. Same index serves both.

## Deployment

**Two branches, two apps. Staging auto-deploys; production does NOT.** Read from the Coolify API
and both `/health` endpoints on 2026-08-31:

| App | Tracks | Auto-deploy | Domains |
|---|---|---|---|
| `GiftCoves-staging` | `staging` | **on** | `staging.giftcoves.com`, `staging.brandcoves.com` |
| `GiftCoves-prod` | `main` | **OFF** since 2026-08-31 | `giftcoves.com`, `www.giftcoves.com`, `brandcoves.com` |

`git push origin staging` → staging, within the minute. **`git push origin main` deploys
NOTHING.** Production is now a deliberate trigger, and this is the single most important change to
absorb: advancing `main` is no longer the release, so a fix sitting on `main` is not a fix that is
live. Verified by behaviour on 2026-08-31 — `main` was fast-forwarded four commits and production
stayed on its previous build.

**Deploying production is one authenticated request:**

```bash
TOK=$(grep -oP '(?<=^KEY=).*' .claude/coolify_api.api | tr -d '\r\n')

# production
curl -H "Authorization: Bearer $TOK" \
  "http://51.75.78.173:8000/api/v1/deploy?uuid=gr0kqzz1er3s79u17vdph27t"

# staging, for comparison
curl -H "Authorization: Bearer $TOK" \
  "http://51.75.78.173:8000/api/v1/deploy?uuid=vhfcyk39ug5exk0fyvdj8qo3"
```

Returns `200` with a `deployment_uuid`; Coolify records it as `is_api: true` / `is_webhook: false`,
so a deliberate release is distinguishable from an automatic one in the audit trail. The token lives
in `.claude/coolify_api.api` (gitignored) as `KEY=<token>`, and the API base is
`http://51.75.78.173:8000` — plain HTTP, port 8000, not the Coolify UI hostname.

> **The endpoint refuses the stored webhook, not the request.** This file used to say
> `/api/v1/deploy` answers 401, which is true only of `DeployTrigger`, which sends no
> `Authorization` header by design. With a Bearer token it works, and that is what made a
> manually-gated production practical at all.

> **Auto-deploy cannot be read back, so trust behaviour over configuration.** The API exposes no
> auto-deploy field on `GET` for an application, and `PATCH` rejects it — it is a UI-only setting.
> The only proof it is off is moving `main` and watching production not rebuild.

> **DANGER: `applications/{uuid}/stop`, `/start` and `/restart` execute on a plain `GET`.** They are
> not read-only probes. Requesting `/stop` to find out whether the route exists **stops the
> application** — that took `giftcoves.com` down for about five minutes on 2026-08-31. Never probe an
> action endpoint against production; use `GiftCoves-staging` if a route has to be discovered at all.
> Note also that `/start` queues a full **rebuild**, not a container start, so it is not a cheap way
> to apply changed runtime environment variables.

> **Never push without being asked, and never push a half-committed tree.** `staging` deploys
> outright to the staging hosts, so that push is still a deploy. `main` no longer deploys on its
> own — but pushing it is still the act that decides what production *will* serve on the next
> trigger, and the trigger is one request away. Treat both as release decisions. Neither
> happens on Claude's initiative. Commit the work first, then stop and report the branch is ready; the
> decision to publish is the user's, every time, and approval for one push does not carry to the
> next. Before any push, check the change is committed **whole** — the migration with
> the model, the controller with the page it renders, the config key with the code that reads it.
> Not "`git status` is clean": this tree normally carries dozens of unrelated modified and untracked
> files, so a clean-tree gate would never pass. Git will happily ship commit A while B and C sit on
> the laptop, and the failure mode is not a missing deploy but an incoherent one.

> **`giftcoves.com` is attached and serving.** Verified 2026-08-29: it holds a valid Let's Encrypt
> certificate and answers `/health` with 200 in ~0.2s, as do `www.giftcoves.com` and
> `brandcoves.com`. The earlier note here — DNS pointed but no Traefik route and no
> certificate — is obsolete.
>
> **What is still outstanding is `canonical_host` on production.** All three hosts serve directly;
> `brandcoves.com` does **not** 301 onto `giftcoves.com`, it just answers. So the same site is live
> on three domains at once, which is a duplicate-content problem now that `ROBOTS_ALLOW=true` there.
>
> **Staging does not redirect either, and the reason was never Coolify.** This file used to say it
> did. `CANONICAL_HOST` and `LEGACY_HOSTS` were absent from `docker-compose.coolify.yml` until
> 2026-08-31, so whatever either app held for them never reached PHP — verified by request:
> `brandcoves.com`, `www.giftcoves.com` and `staging.brandcoves.com` all answered 200 with no
> redirect. The AWIN_VDB failure exactly, in a second place. Both now pass through, and
> `ConfigContractTest` grew a rule for settings that default to `''`, which is the blind spot that
> hid it: an empty default is indistinguishable from a value that never arrived.
>
> **The `APP_NAME` warning that used to be here is spent.** Production already issues a
> `giftcoves-session` cookie, so `APP_NAME` is already `GiftCoves` — there is nothing left to
> change and nobody to log out. (The mechanism, if it ever matters again: `config/session.php`
> derives the cookie from `Str::slug(APP_NAME)`, and `SESSION_COOKIE` is not set, so renaming the
> app renames the cookie.) All four Google redirect URIs are registered too, `giftcoves.com`
> included, so moving `APP_URL` no longer breaks sign-in.
>
> What is left is one deploy: set `APP_URL=https://giftcoves.com`, `CANONICAL_HOST=giftcoves.com`
> and `LEGACY_HOSTS=brandcoves.com,www.brandcoves.com,www.giftcoves.com` together on
> `GiftCoves-prod`. `APP_URL` still points at `brandcoves.com`, which is why the sitemap served from
> `giftcoves.com` emits `brandcoves.com` URLs. Keep `giftcoves.com` out of `LEGACY_HOSTS` — the
> canonical host redirecting to itself is a loop. See
> [docs/features/rebrand.md](docs/features/rebrand.md).
>
> **The Coolify applications were renamed to `GiftCoves-*`**, which this file previously said would
> not happen, because renaming invalidates every issued deploy webhook. That Coolify behaviour is
> real and was simply overridden; staging has deployed since (built `2026-08-29`), so its webhook
> survived or was re-issued. Production's is unproven — it has not built since `2026-08-16`.

> **`main` and `staging` are level again**, both at `2f8aa2d`, fast-forwarded on 2026-08-30 after
> thirteen days apart. That release is large: it folds `guides` into the editorial table, renames
> every Daily Cove's URL, and lands the curation screen and gift personas that had been sitting
> uncommitted. Check `/health` on both hosts before assuming a fix is live — the migration name is
> the reliable field, not the build stamp.

> **Not adopted, but no longer blocked.** The one-branch model in
> [docs/deployment.md](docs/deployment.md) — both apps on `main`, production behind a manual
> trigger — needs two Coolify changes, and the gate has to come first. The gate is currently OFF, so
> the remaining step, repointing `GiftCoves-staging` from `staging` to `main`, **must not be
> taken**: both apps would then track `main` with auto-deploy on, and every commit would reach real
> visitors with no staging pass at all. That ordering has not changed.
>
> What changed is the reason it was parked. This file used to say adopting it needs "a production
> deploy path that works without the Coolify UI" and that none existed, because `DeployTrigger`
> sends no `Authorization` header and the stored endpoint answers 401. **That is true of the
> webhook and false of the endpoint.** With a Bearer token, `GET /api/v1/deploy?uuid=<app-uuid>`
> against `http://51.75.78.173:8000` returns 200 and queues a deploy — proven against
> `GiftCoves-staging` on 2026-08-31, and Coolify records it as `is_api: true` / `is_webhook: false`,
> so a deliberate release is distinguishable from an automatic one in the audit trail.
>
> So the blocker is gone and the decision is now a judgement rather than a wait. Two costs to weigh
> before taking it: the token can redeploy, read every environment variable and reassign domains on
> **both** applications, so routine use raises the stakes on where it lives; and production stops
> being `git push origin main` and becomes an authenticated request or the UI. That friction is the
> point of the model, and it is still a real cost.

- **`VITE_*` is baked into the client bundle at build time.** In Coolify these must be ticked
  **Build Variable**. Left as runtime vars they are `undefined` in the browser: server-rendered pages
  look fine while every client-side interaction silently breaks.
- `migrate` runs as a one-shot service before `app`/`queue`/`scheduler` start.
- `queue` and `scheduler` run **exactly one replica** — two Horizons double-process every job.
- Migrations are forward-only. Anything not backwards-compatible uses expand/contract, so a rollback
  never meets a schema it cannot read.
- Production data flows **one way**: `pg_dump` prod → laptop, then **`php artisan bc:scrub` is
  mandatory** — `users`, `recipients` and `wishlists` hold real emails and personal gift notes, and
  this repo sits in a Synology-synced folder.

## Never commit

`.env`, `*.dump`, `*.sql`, `*.pem`, `*.ppk`, `PAAPICredentials.csv`, `Amazon-tags.txt`.
Rotating `CLAIM_HASH_SECRET` orphans every existing wishlist claim — treat it as permanent.

# CLAUDE.md — GiftCoves 2

Multi-market product search, offer comparison, gift discovery and buying guides.
A clean-room rebuild of the v1 WordPress site at `../brandcoves` (referenced for *scope and product
thinking* only — no code is ported).

## Feature documentation rule

**Every time you add, modify or remove a feature, update `docs/features/`.** One `.md` per feature,
indexed in [docs/features/INDEX.md](../docs/features/INDEX.md). Record *why* a non-obvious decision
was made, not just what the code does — the reasoning is the part that cannot be recovered from a
diff.

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
| Deploy | Coolify on `51.75.78.173` | one branch, `main` → two apps |

## Where things are

[docs/map.md](../docs/map.md) answers "where does this change go": the request path, the route
surface, one line per service directory, which test covers what. Read it before `ls`-ing and
grepping for the usual entry points.

## Shell facts — read before the first command

Two shells with different rules. Getting these wrong has cost more failed tool calls than any bug in
this codebase.

| Need | Bash tool | PowerShell tool |
|---|---|---|
| PHP | `php` | `php` |
| Composer | `composer` | `composer` |
| One test class | `php artisan test --filter=X` | `php artisan test --% --filter=X` |
| Several at once | one call per class | `php artisan test --% --filter="A\|B"` |

- **`php` and `composer` work as bare names in Bash only because of two one-line shims in `~/bin`**
  (`exec php.bat "$@"` and `exec composer.bat "$@"`): Git Bash will not run Herd's `.bat` files by a
  bare name. If `php` ever reports `command not found`, recreate `~/bin/php`. Do not switch to the
  absolute Herd path (`C:/Users/bvand/.config/herd/bin/php84/php.exe`): it works, which is why it
  keeps getting adopted, and it is a third spelling of one fact.
- **A `|` in `--filter` breaks in both shells.** In PowerShell because PowerShell parses it; in Bash
  because the shim hands the arguments to `php.bat` and cmd.exe parses it. Either way the run dies
  with `'B' is not recognized…`, which reads like a missing test rather than a quoting bug. Use the
  PowerShell tool with `--%` before the arguments, or one filter per call from Bash.
- **Local Postgres is `-U brandcoves`, never `-U postgres`** — that role does not exist, and the
  error reads like a broken container. Databases: `brandcoves` (development) and `brandcoves_test`
  (the suite; the parallel runner clones `_test_N` from it). Credentials are all `brandcoves`, in
  `.env`. Use `docker compose exec` rather than a guessed container name, and drop any scratch
  database you create (`dropdb -U brandcoves <name>`).

  ```bash
  docker compose exec -T postgres psql -U brandcoves -d brandcoves -c '<sql>'
  ```
- **Never run an `--env=testing` command by hand.** `.env.testing` is gitignored, and without it
  Laravel silently reads `.env` — so `migrate:fresh --env=testing` drops the *development* database.
  The suite never needs it: `phpunit.xml` sets its own environment. See
  [docs/testing.md](../docs/testing.md).

The command blocks below are written in canonical form (`php artisan …`, `composer …`); the table
above says how to spell that in the shell you are holding.

## Commands

```bash
docker compose up -d          # postgres :5432, redis :6379, mailpit :8025
composer dev                  # serve + queue + vite + ssr, all at once
php artisan migrate --force
composer lint                 # Pint
composer test                 # the full suite: parallel, 8 processes, against real Postgres
composer test:serial          # one process, for when parallelism is the suspect
```

> **Run the tests the change touches, and say which ran**, so "tests pass" is never read as "the
> suite passes": the narrowest `--filter`, or the file. Run `composer test` only when asked, or when
> a change touches migrations or shared services that no narrow filter covers. Push with
> `git push --no-verify`: [CI](../.github/workflows/tests.yml) runs the full suite against Postgres
> 16 on every push and pull request, and nothing can skip it. The local pre-push hook is optional
> (`git config core.hooksPath .githooks`). Why, and how CI differs: [docs/testing.md](../docs/testing.md).

> **Local dev runs on Windows, supervised.** PHP is Herd's, not WinGet's: Smart App Control blocks
> WinGet's unsigned `php8ts.dll`, and `php.exe` then exits printing nothing. The stack runs as the
> `GiftCoves Dev Server` scheduled task (`scripts/dev-server.ps1`); stop it with
> `.\scripts\dev-stop.ps1`. `composer dev` must not run Pail — it needs `pcntl`, which Windows
> lacks, and its crash takes the whole stack down. Logs: `storage/logs/laravel.log`. PHP, Composer
> and Node run on the host; Docker holds only Postgres, Redis and Mailpit. See
> [docs/local-dev.md](../docs/local-dev.md).

Operational commands. The ones that change content are dry runs unless `--write`;
`bc:prune-personal-data` is the exception and deletes unless `--dry-run`.

```bash
php artisan bc:refresh-discovery      # giftability → serendipity → brand stats → today's edition
php artisan bc:plan-coves             # draft the editorial calendar 120 days ahead: themed days
                                      # pre-filled with products, and seasons laid out as dated
                                      # parts, for a person to curate. A season that already ran
                                      # is renewed onto its next window at the same URLs. Run
                                      # bc:refresh-discovery first. --no-seasons: Dailies only.
                                      # The year is at /admin, Content > Cove calendar
php artisan bc:make-admin you@example.com   # create or promote an admin (--demote undoes). The
                                      # password comes from BC_ADMIN_PASSWORD or a hidden prompt;
                                      # --password= works but shows in ps and shell history
php artisan bc:check-bol              # prove the bol credentials; prints lengths, never values
php artisan bc:check-ebay             # the same for eBay, plus the marketplace mapping — which
                                      # this repo guesses per market, and gets no error for
php artisan bc:check-tradedoubler     # the same for Tradedoubler. --raw prints the real payload
                                      # shape, which that connector was written without
php artisan bc:check-config           # did this environment's config arrive? lengths, never values
php artisan bc:export-content         # editorial (feeds, page blocks, coves, topics, cove plans)
                                      # as a portable envelope
php artisan bc:import-content --in=-  # apply one here. Dry run unless --write
php artisan bc:api-token              # mint/list/revoke an editorial API key; plaintext shown once
php artisan bc:prune-personal-data    # enforce the published GDPR retention windows. Deletes
                                      # unless --dry-run
php artisan bc:awin-feeds             # discover Awin advertiser feeds, register them per market
php artisan bc:ingest                 # run feed ingestion now
php artisan bc:withdraw-source --market=en --source=bol   # suppress the offers a source left
                                      # behind after it stopped serving a market. Turning a
                                      # connector off does NOT hide what it already stored.
                                      # --restore is the undo; refuses while the source still serves
php artisan bc:pull-charts            # pull bestseller charts — the demand signal, never a page
php artisan bc:pull-charts --market=be-nl --discover   # prove the endpoint and the response
                                      # envelope in one request. Writes nothing
php artisan bc:refresh-guide-copy     # re-write guides that have no editorial, then stale ones
php artisan bc:tidy-prose             # bring the archive into house style (em dashes out, stray
                                      # ** off fields that cannot render it). New writing is
                                      # already correct: App\Services\Editorial\HouseStyle runs at
                                      # the write
php artisan bc:seed-advice-coves      # publish resources/content/advice-coves.php. Never
                                      # overwrites a Cove a person edited (--replace does, and asks
                                      # first); --dry-run previews. Run it after editing the file
php artisan bc:seed-shop-coves        # the same for resources/content/shop-coves.php
php artisan bc:seed-help-demo         # local only: a throwaway account with a list, for the
                                      # /lists-help screenshots. Then: node scripts/help-screenshots.mjs
php artisan bc:scrub --force          # MANDATORY after restoring a production dump
```

A fresh deploy has empty discovery surfaces until the next scheduled window, so
`bc:refresh-discovery` is the first thing to run against a new environment — and `bc:pull-charts`
second, because the demand signal it collects has no other source and the guide-topic queue is empty
without it on a market with no search traffic yet.

---

## Non-negotiable invariants

These are the rules the product depends on. Breaking one is a bug even when tests pass.

1. **AI never runs inside a web request.** It runs in queued jobs, the scheduler or an artisan
   command, and `AiClient` refuses anywhere else. A visitor request must not be able to cause AI
   spend. Every caller needs a key in `config('giftcoves.ai.caps')` — an unregistered key throws —
   and each key is capped per day through `AiUsage`. With `AI_ENABLED=false` the whole site still
   works. See [docs/features/ai-invariant.md](../docs/features/ai-invariant.md).

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
   `App\Services\Wishlist\ClaimView` the single place it is applied.

5. **Affiliate URLs are hostile input.** They come from third-party feeds. Scheme-check (`https:`
   only, via `Product::hasSafeAffiliateUrl()`) before any redirect. HTML escaping alone happily
   preserves `javascript:`.

6. **Amazon never enters the catalogue.** `Source::allowsCatalogueStorage()` is false for Amazon, so
   nothing from Amazon reaches `products`, search, offer comparison, a wishlist, a chart or an email.
   What we keep sits apart, in `amazon_products`: the decision (ASIN, scores, classification) and,
   since 2026-09-14, what an imported page said about the product (description, image URL, barcode).
   **Never a price or availability** — the owner's decision rather than a flag, since
   `allowsPriceStorage()` is true for every source: there is no column, and the import refuses one.
   No Amazon connector exists; showing an Amazon product would need a live fetch at render that
   hides the item when it fails. Read [docs/features/amazon-compliance.md](../docs/features/amazon-compliance.md)
   before widening any of this.

7. **Prices are integer cents.** Floats accumulate error across the min and previous-price
   aggregates that drive "cheapest offer" and discount badges, both of which must be exactly right.

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
  [docs/features/market-routing.md](../docs/features/market-routing.md).
- **Enum-ish columns are `string` + a CHECK constraint**, not native Postgres enums. Altering a PG
  enum cannot run inside a transaction, which makes every future value addition a deploy hazard.
  Cast to a PHP enum on the model.
- **Business logic lives in `app/Services/`**, not in controllers or jobs. Jobs orchestrate; services
  decide. Scoring and classification rules go in pure, unit-testable classes — that is where the
  subtle bugs live.
- **Filament's stylesheet ships no Tailwind utilities**, so `class="flex gap-3"` in a custom panel
  page lays out nothing — which looks exactly like a page nobody styled.
  `resources/css/filament/admin/theme.css` adds them alongside Filament's own CSS without replacing
  it; read its header before styling an admin page. Why: docs/features/cove-curation.md.
- Strict types everywhere: `declare(strict_types=1);`.
- **Write plainly, in chat and in docs.** Prefer the ordinary word to the in-house one, and when a
  term genuinely earns its place — *fold*, *expand/contract*, *cove* — say what it means the first
  time it appears in a document or a conversation rather than assuming it landed. A sentence nobody
  has to decode is worth more than a precise one nobody reads. This applies to commit messages and
  code comments too: the reader a year from now has no more context than the reader today.
- Comment the *why*, especially for a threshold, a weight, or a workaround. A number with no
  justification will be "cleaned up" by someone later.

## Search notes

Two mechanisms, because they fail differently. The measurements behind each are in
[docs/features/search.md](../docs/features/search.md) and
[brand-pages.md](../docs/features/brand-pages.md).

- `products.search_vector` is a **stored generated column** built by `bc_search_vector()` (title A,
  brand B, category C, description D, stemmed per market by `bc_text_config()`). Changing the
  function does **not** rewrite existing rows: in PG16 the column has to be dropped and re-added, and
  that re-add *is* the backfill (see `2026_08_10_000500_add_description_to_the_search_vector`).
- **A brand's identity is its slug, not its name.** `brand_stats` holds one row per
  `(market, slug)` with the feeds' spellings in `aliases` ("Audio-Technica", "Audio Technica"). Fold
  in PHP with `Str::slug()`, never in SQL: Postgres cannot transliterate ("Kärcher" → "karcher").
- Trigram fuzzy matching uses **`<%` (word_similarity), not `%`.** `%` compares whole strings, so a
  typo against a long title scores under the threshold and finds nothing. The same index serves both.

## Deployment

**One branch, two apps. Staging auto-deploys; production does NOT.**

| App | Tracks | Auto-deploy | Domains |
|---|---|---|---|
| `GiftCoves-staging` | `main` | **on** | `staging.giftcoves.com`, `staging.brandcoves.com` |
| `GiftCoves-prod` | `main` | **OFF** since 2026-08-31 | `giftcoves.com`, `www.giftcoves.com`, `brandcoves.com` |

`git push origin main` → **staging**, within the minute. It deploys **nothing to production**:
advancing `main` is not the release, so a fix sitting on `main` is not a fix that is live.
Production is one authenticated request:

```bash
TOK=$(grep -oP '(?<=^KEY=).*' .claude/coolify_api.api | tr -d '\r\n')

# production
curl --max-time 30 -H "Authorization: Bearer $TOK" \
  "http://51.75.78.173:8000/api/v1/deploy?uuid=gr0kqzz1er3s79u17vdph27t"

# staging, for comparison
curl --max-time 30 -H "Authorization: Bearer $TOK" \
  "http://51.75.78.173:8000/api/v1/deploy?uuid=vhfcyk39ug5exk0fyvdj8qo3"
```

It returns `200` with a `deployment_uuid`. The token is `.claude/coolify_api.api` (gitignored,
`KEY=<token>`); the API is plain HTTP on port 8000, not the Coolify UI hostname. Auto-deploy is a
UI-only setting the API cannot read back, so trust behaviour: the proof it is off is moving `main`
and watching production not rebuild.

> **DANGER: `applications/{uuid}/stop`, `/start` and `/restart` act on a plain `GET`.** Requesting
> `/stop` to see whether the route exists stops the application; it took `giftcoves.com` down on
> 2026-08-31. Never probe an action endpoint against production. `/start` queues a full rebuild.

> **Never push or trigger production unless asked, and never push half a change.** Pushing `main`
> deploys staging, so the push is a deploy, and the production trigger is one request away. Both are
> the user's decisions, each time; approval for one push does not carry to the next. Commit the work,
> then stop and say it is ready. Before a push, check the change is committed **whole** — the
> migration with the model, the controller with the page, the config key with the code that reads
> it. Do not gate on a clean `git status`, which this tree rarely has. When asked to push, use
> `git push --no-verify origin main`.

> **After any deploy, read `/health` on the host you changed.** `commit` says which code is serving
> and `started` whether the container restarted; `built` is cacheable, so a stale value there is not
> a failed deploy.

- **The canonical host is `giftcoves.com`, and that is done:** `www.giftcoves.com` and
  `brandcoves.com` 301 to it. If it regresses, `APP_URL=https://giftcoves.com`,
  `CANONICAL_HOST=giftcoves.com` and `LEGACY_HOSTS=brandcoves.com,www.brandcoves.com,www.giftcoves.com`
  go on `GiftCoves-prod` together — and `giftcoves.com` stays out of `LEGACY_HOSTS`, or it redirects
  to itself. See [docs/features/rebrand.md](../docs/features/rebrand.md).
- **Every `curl` carries a timeout, and every container healthcheck carries `--max-time 5`** with an
  interval of about 30s. A curl without a ceiling hangs instead of failing, and hung healthchecks
  once piled up until the VPS stopped answering; see docs/deployment.md.
- **`VITE_*` is baked into the client bundle at build time.** In Coolify these must be ticked
  **Build Variable**. Left as runtime vars they are `undefined` in the browser: server-rendered pages
  look fine while every client-side interaction silently breaks.
- `migrate` runs as a one-shot service before `app`/`queue`/`scheduler` start.
- `queue` and `scheduler` run **exactly one replica** — two Horizons double-process every job.
- Migrations are forward-only. Anything not backwards-compatible uses expand/contract (add, move the
  code over, drop in a later release), so a rollback never meets a schema it cannot read.
- Production data flows **one way**: `pg_dump` prod → laptop, then **`php artisan bc:scrub` is
  mandatory** — `users`, `recipients` and `wishlists` hold real emails and personal gift notes, and
  this repo sits in a Synology-synced folder.

The history behind all of this — how the one-branch model was adopted, why a Bearer token works
where a stored webhook did not, the nightly Docker prune, the gotchas from standing staging up — is in
[docs/deployment.md](../docs/deployment.md), read once when something surprises you.

## Never commit

`.env`, `*.dump`, `*.sql`, `*.pem`, `*.ppk`, `PAAPICredentials.csv`, `Amazon-tags.txt`.
Rotating `CLAIM_HASH_SECRET` orphans every existing wishlist claim — treat it as permanent.

## Publishing content (coves, personas, dailies, advice)

**Coves are written in the session and published over the production editorial API. The AI writer
is never run on production.** An authored plan builds without touching the model
(`EditionBuilder::build()`, `buildPersona()` and `buildArticle()` use an authored
`editorial`/`body` as written), so "write it here, send it authored" costs nothing in AI spend and
nothing in surprise.

Read these before writing anything:

- `.claude/skills/giftcoves-seed-coves/SKILL.md` — the flow, addressing per kind, link tokens, voice
- `.claude/skills/giftcoves-seed-coves/reference/api.md` — the endpoint contract, `POST /coves` body
- `docs/features/editorial-api.md` — the long form. Its "Learned the hard way" section holds the
  traps: the brief is the link allowlist, approved plans refuse item changes, item ids live in the
  brief
- `docs/features/advice-coves.md` and the header of `resources/content/advice-coves.php` — what an
  advice article may and may not say (no prices, no superlatives, dated legal claims)
- `docs/features/cove-entities.md` — brand and shop coves: ranges, never products
- `app/Services/Ai/Prompts/Defaults.php` — the house voice per kind (`ADVICE_SYSTEM`, `BRAND_SYSTEM`)

The key is `.claude/giftcoves_api.api` (`KEY=…`, production, gitignored), base
`https://giftcoves.com/api/editorial`. Reads 120/min, writes 20/min: pace a batch at ~3 s a write.

**`POST /coves` resets every field it is not sent** (editorial, blurb, pickMode, writer), though it
keeps the shortlist when no items are sent. Send the plan whole, or change one field with
`PATCH /coves/{id}`.

House rules from the owner: **eight products minimum** on a daily or a persona, and every product
paragraph ends with a search-for-more link on its category (`Meer [[search:Cat|noun]].`).

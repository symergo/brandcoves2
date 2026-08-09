# CLAUDE.md — Brandcoves 2

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
composer dev                  # serve + queue + logs + vite, all at once
php artisan migrate --force
composer lint                 # Pint
composer test
```

Operational commands, all idempotent and safe to re-run:

```bash
php artisan bc:refresh-discovery      # giftability → serendipity → brand stats → today's edition
php artisan bc:plan-coves             # draft the editorial calendar 120 days ahead
php artisan bc:make-admin             # create an admin; refuses a password in argv (visible in ps)
php artisan bc:check-bol              # prove the bol credentials; prints lengths, never values
php artisan bc:seed-copy              # import shipped page copy into the editable copy bank
php artisan bc:prune-personal-data   # enforce the published GDPR retention windows
php artisan bc:awin-feeds             # discover Awin advertiser feeds, register them per market
php artisan bc:ingest                 # run feed ingestion now
php artisan bc:scrub --force          # MANDATORY after restoring a production dump
```

A fresh deploy has empty discovery surfaces until the next scheduled window, so
`bc:refresh-discovery` is the first thing to run against a new environment.

PHP, Composer and Node run on the **Windows host** — bind-mounting a PHP project into a Linux
container on Windows makes every request stat hundreds of autoloaded files across the filesystem
boundary. Only the infrastructure is containerised locally.

---

## Non-negotiable invariants

These are the rules the product depends on. Breaking one is a bug even when tests pass.

1. **AI is only ever called from a queued job.** Never from a request handler, never from a Blade
   view or an Inertia controller. A visitor request must not be able to cause AI spend. Every AI
   caller registers a `feature_key` in `config/brandcoves.php` and is capped per day via `AiUsage`.
   With `AI_ENABLED=false` the whole site still works.

2. **Product identity is scoped to the market.** `product_groups` is unique on `(market,
   identity_key)`. The same product in two markets has different tax, shipping and availability, so
   those offers are not interchangeable — merging them lets a foreign price masquerade as "cheapest".

3. **`products` rows are OFFERS, not products.** One row = one merchant selling one thing in one
   market. `product_groups` rows are physical products. Search, product pages, gift picks and guides
   all operate on **groups**. This split is what makes offer comparison possible.

4. **Claim state never reaches the list owner.** A gift list exists so the recipient does not know
   what has been bought. `wishlist_items.claimed_by_hash` is `$hidden` on the model and must stay out
   of any payload the owner can see.

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
- **Enum-ish columns are `string` + a CHECK constraint**, not native Postgres enums. Altering a PG
  enum cannot run inside a transaction, which makes every future value addition a deploy hazard.
  Cast to a PHP enum on the model.
- **Business logic lives in `app/Services/`**, not in controllers or jobs. Jobs orchestrate; services
  decide. Scoring and classification rules go in pure, unit-testable classes — that is where the
  subtle bugs live.
- Strict types everywhere: `declare(strict_types=1);`.
- Comment the *why*, especially for a threshold, a weight, or a workaround. A number with no
  justification will be "cleaned up" by someone later.

## Search notes

Two mechanisms, because they fail differently:

- `products.search_vector` is a **stored generated column** built by `bc_search_vector()`, weighted
  title A / brand B / category C, stemmed per market via `bc_text_config()`. Changing that function
  does **not** rewrite existing rows — that needs an explicit backfill migration.
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

`git push origin staging` → `staging.brandcoves.com`. Verify, then fast-forward `main`.

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

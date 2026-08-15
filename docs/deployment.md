# Deployment

Coolify on `51.75.78.173`, deploying from a private GitHub repo. Same box that currently runs v1.

```
laptop ──git push──▶ GitHub ──webhook──▶ Coolify ──▶ build · migrate · redeploy
   ▲                                                         │
   └──────── pg_dump over SSH (production → laptop only) ─────┘
```

## Two applications, one repo

Read from the Coolify database on 2026-08-10, not from memory:

| Coolify app | Branch | Auto deploy | Domain | Notes |
|---|---|---|---|---|
| `brandcoves2-staging` | `staging` | **on** | `staging.brandcoves.com` | `ROBOTS_ALLOW=false`, own database, low AI caps |
| `brandcoves2-prod` | `main` | **on** | `brandcoves.com` | `ROBOTS_ALLOW=true` |

> **The domains above are pre-rename and still current.** The codebase became GiftCoves on
> 2026-08-15; Coolify has not been touched. Until the steps in
> [rebrand.md](features/rebrand.md#the-coolify-side) are carried out, these are the live values —
> and the two application names stay `brandcoves2-*` regardless, because renaming a Coolify
> application changes nothing a visitor can see and would invalidate every deploy webhook already
> issued against it.

Both: **Build Pack = Docker Compose**, **Compose Location = `/docker-compose.coolify.yml`**, domain
assigned to the **`app`** service, Scheduled Backups on **`postgres`**.

## How it deploys today

```bash
git push origin staging       # staging builds automatically
# verify staging, then:
git push origin main          # production builds automatically, at once
```

**There is no human gate on production.** Both apps have auto-deploy enabled, so the fast-forward to
`main` *is* the deploy — nobody confirms anything, and nothing waits. Anyone advancing `main`
believing a person still has to press a button in Coolify will ship to real traffic by accident. That
is not hypothetical: `main` moved to `2140f25` at 07:24 on 2026-08-10 and production rebuilt within
the minute.

## Planned: one branch, two apps — NOT yet in effect

The intended model is that both applications track **`main`**, staging deploying every push and
production only when someone triggers it.

It would replace the `staging` → `main` fast-forward, which is bookkeeping that encodes what a deploy
already records — and which drifts. `main` sat **seven commits** behind `staging` at one point,
including four bug fixes, while production served real traffic. Worse, the drift was invisible:
nothing about production looked wrong, it was simply old, and the narrower advertiser allowlist in
those unshipped commits was quietly costing catalogue.

Under it, branch drift cannot happen because there is only one branch. **What is on production would
be a deploy decision, not a branch state somebody has to remember to advance.**

**Two changes in Coolify make it real, and the order matters:**

1. Turn **off** auto-deploy on `brandcoves2-prod`. This is the gate the model assumes and the system
   does not currently have.
2. Repoint `brandcoves2-staging` from `staging` to `main`.

Doing (2) first, or alone, points both apps at one branch while production still auto-deploys — every
commit would reach real visitors with no staging pass at all, which is strictly worse than the
two-branch model it replaces. Until both are done, follow *How it deploys today* above.

Keep both environments. Since v1 was deleted there is no fallback, so staging is the only place a bad
migration surfaces before real visitors meet it — and the whole stack idles at ~390 MiB.

## Services

| Service | Role |
|---|---|
| `migrate` | one-shot, runs `migrate --force --isolated`, exits. Everything else waits on it |
| `app` | FrankenPHP on :8080, Traefik routes the domain here |
| `queue` | `php artisan horizon` — **exactly one replica** |
| `scheduler` | `php artisan schedule:work` — **exactly one replica** |
| `postgres`, `redis` | state; both volumes backed up |

Two Horizons would double-process every job, including feed ingestion. `stop_grace_period: 60s` lets
the in-flight job finish rather than abandoning a half-ingested chunk.

## Gotchas hit standing staging up (2026-08-07)

Five real ones, all fixed. Recorded because every one of them would recur on a
fresh environment.

| Symptom | Cause | Fix |
|---|---|---|
| Build fails `composer: not found` | Runtime stage copies `vendor/` from the composer stage but frankenphp ships no composer binary, and `dump-autoload` must run *after* the app source is present | `COPY --from=composer:2 /usr/bin/composer`, removed again in the same layer |
| Every request 502s while the container reports **healthy** | frankenphp exposes 80, 443 and 2019; adding 8080 gave Traefik four candidates and no `loadbalancer.server.port` label, so it routed to 80 where nothing listened. The healthcheck hit 8080 directly, so the container looked fine | Serve on **80** — the port Traefik already assumes. v1's WordPress works because it exposes exactly one port |
| Redirects and asset URLs come out `http://` | Traefik terminates TLS and forwards plain HTTP; Laravel saw an insecure request | `$middleware->trustProxies(at: '*')` in `bootstrap/app.php`. Safe here: the container publishes no ports and is reachable only through Traefik |
| `queue` and `scheduler` heading for permanently unhealthy | Both inherited the base image's healthcheck (`curl localhost:2019/metrics`, Caddy's admin API). Neither runs a web server | `horizon:status` for queue; healthcheck disabled for scheduler — a check that can never pass is worse than none |
| `/health` reported `"commit": "unknown"` | Coolify exposes **no commit SHA** to the container — only `COOLIFY_BRANCH`, `COOLIFY_FQDN`, `COOLIFY_URL`, `COOLIFY_RESOURCE_UUID` | Build timestamp written into the image (`/app/BUILD_STAMP`) plus the branch |

Two process notes worth as much as the fixes:

- **Coolify rebuilt the same old commit three times** because the fix had been
  committed to `main` while the app deploys `staging`, and `git push -q origin
  staging` was a silent no-op. Check `git ls-remote --heads origin` against the
  local SHA before concluding a deploy is broken.
- **The API token needs `read`**, not just `write` and `deploy` — every GET
  endpoint 403s otherwise. UUIDs can be read straight from `coolify-db` over SSH
  as a workaround, which is how this one was set up.

## The gotcha that will bite

**`VITE_*` is baked into the client bundle at build time.** In Coolify these must be ticked
**Build Variable**, not left as plain runtime variables.

Get it wrong and server-rendered pages look perfectly fine while every client-side interaction
silently breaks — the same shape of failure v1 hit with an empty `SITE_DOMAIN`. Check
`/health` for the commit, and view-source for the hydrated Inertia payload.

## Required environment variables

`APP_KEY`, `APP_URL`, `POSTGRES_DB/USER/PASSWORD`, `CREDENTIALS_ENCRYPTION_KEY`, `CLAIM_HASH_SECRET`,
`RESEND_API_KEY`, `MAIL_FROM_ADDRESS`, `GOOGLE_CLIENT_ID/SECRET`, `AWIN_API_TOKEN`,
`AWIN_PUBLISHER_ID`, `BOL_CLIENT_ID/SECRET`, `ANTHROPIC_API_KEY`, `ROBOTS_ALLOW`.

Generate the two secrets with `php artisan key:generate --show`.
**`CLAIM_HASH_SECRET` is effectively permanent** — rotating it orphans every existing wishlist claim.

## Cutover: v2 replaces v1 at brandcoves.com

The domain here is `brandcoves.com` throughout, because that is where the v1 WordPress site is. This
cutover comes **before** the rename to giftcoves.com — see [features/rebrand.md](features/rebrand.md)
— so that a known-good v2 is what the rename moves, and a failure on the day can be attributed to
one change rather than two.

v1 serves `brandcoves.com` from the same box, so this is a Coolify domain move rather than a DNS
move — which makes rollback fast.

1. Back up v1 fully (`mysqldump` + the `wp_data` volume) and export its published URL list.
2. Move v1's app to `v1.brandcoves.com`, still running, now `noindex`.
3. Assign `brandcoves.com` + `www` to `brandcoves2-prod`'s `app` service; Traefik re-issues the cert.
4. Verify, then leave v1 up for a fortnight as a rollback target.
5. **Rollback = reassign the domain back to v1.** No DNS TTL to wait out.

**v1's URLs must not 404.** It has indexed pages at `/brands/…`, `/articles/…`, `/gift-whisperer/`
and six gift guides. v2's routes all live under `/{market}/`, so replacing the site without a
redirect map discards that ranking equity. Phase 7 builds the map, serves **301s** from middleware,
and verifies by crawling the old sitemap — every published v1 URL must return a 301 to a 200, not a
404 and not a redirect chain.

## Moving content between environments

Editorial does not regenerate the way the catalogue does, so it is promoted rather than rewritten:

```bash
docker exec <staging-app> php artisan bc:export-content \
  | docker exec -i <prod-app> php artisan bc:import-content --in=-   # dry run
```

Product references travel as `(market, identity_key)` because integer ids differ per environment.
Dry run is the default and the drop list is the point. See
[content-promotion.md](features/content-promotion.md).

## Checking the config arrived

```bash
docker exec <app> php artisan bc:check-config
curl -s https://giftcoves.com/health | jq .config
```

A setting has to survive `config/`, `.env.example`, the compose file and Coolify to do anything, and
every way that fails is silent. `tests/Unit/ConfigContractTest.php` fails the build when a key cannot
reach a container at all. See [config-contract.md](features/config-contract.md).

## Getting production data onto the laptop

One direction only. Schema changes reach production **only** as migrations run by `migrate`.

```bash
PG=$(ssh root@51.75.78.173 "docker ps --format '{{.Names}}' | grep '^postgres-'")
ssh root@51.75.78.173 "docker exec $PG pg_dump -U brandcoves -Fc brandcoves" > prod.dump
docker compose exec -T postgres pg_restore -U brandcoves -d brandcoves --clean --if-exists < prod.dump
php artisan bc:scrub
```

**`bc:scrub` is mandatory.** `users`, `recipients` and `wishlists` hold real emails and personal
notes about real people's gifts, and this repo sits in a Synology-synced folder. The command refuses
to run against a non-local database.

Most of the time no dump is needed — the catalogue is regenerable from the feeds.

## Rollback and safety

- Coolify → Deployments → redeploy the previous commit.
- Migrations are forward-only. Anything not backwards-compatible uses **expand/contract** (add column
  → deploy code → backfill → later migration drops the old column), so a rollback never meets a
  schema it cannot read.
- `pg_dump` before any risky migration, on top of the scheduled backups.
- **VPS headroom:** v2 adds Postgres + Redis + three PHP containers *per environment* to a box
  already running v1's MySQL and Apache. Check free memory before standing staging up, and retire
  v1's containers once the post-cutover watch period ends.

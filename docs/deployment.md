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

## Pushing is a deploy, so pushing is asked for

Nobody — and no agent — pushes `staging` or `main` on their own initiative. Work gets committed
locally and left there; the branch is *ready to push*, and whether it goes out is a decision someone
makes deliberately, each time. Approval for one push is not approval for the next one.

This is a consequence of the section above rather than a separate policy: with auto-deploy on both
apps, there is no later moment at which a person reviews what is shipping. The push **is** that
moment, so it is the one that has to be chosen.

### Push the change whole

Git cannot push uncommitted work, so the hazard is never a *missing* deploy — it is a **partial**
one. A service committed without the migration behind it, a controller without the React page it
renders, a config key read by code that shipped without the key: each of those builds, deploys, and
then fails on the first real request.

"Is `git status` clean?" is the wrong check. This tree routinely carries dozens of unrelated modified
and untracked files, so that gate would never pass and would train you to wave it through. The right
check is against the diff you are shipping: **does every piece this change needs have a commit?**

Note that the local suite cannot catch this, and neither could CI before the fact — the hook runs
against your working tree, where the missing pieces are still sitting there, present and green. CI
catches it after the push, which is the right place but not a comfortable one when the push already
deployed. So the check is yours to make before you push.

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

## Build speed

Every deploy is a from-source build **on the Coolify box**, and there are two per release — a push to
`main` rebuilds from scratch what `staging` built minutes earlier off the same tree. So the cost that
matters is not the cold build, which happens rarely, but the per-commit one, which happens always.

Four changes were made on 2026-08-31, in descending order of how much they buy:

**The client assets are copied in AFTER the PHP tail, not before it.** `dump-autoload`,
`package:discover`, `event:cache` and `view:cache` are the expensive per-commit work in the runtime
stage, and `COPY --from=frontend … ./public/build` used to sit above all four. None of them reads the
Vite manifest — `@vite()` compiles to a call that resolves it at *request* time, not at `view:cache`
time — so an asset-only change was re-running the entire PHP tail to ship some new JavaScript. Below
them, it invalidates one cheap COPY. Roughly a sixth of recent commits touch `resources/` without
touching `app/`, and those deploys now skip the tail outright.

**`bootstrap/ssr/` is no longer committed.** 3.3 MB of Vite SSR output was tracked in git, and
`.dockerignore` did not exclude it, so it rode into the *runtime* image via `COPY . .` — where
nothing reads it, because the `ssr` service builds from its own stage which takes the bundle straight
from `frontend`. Dead weight is the small half. The real cost was that regenerating it locally
changed `COPY . .` and therefore invalidated all four commands above; two of the thirty commits
before this one touched `bootstrap/` and nothing else, and each paid a full PHP rebuild for a file
that was never read.

**npm and composer install under cache mounts.** The layer cache already covered the build where the
lock file was unchanged, which was never the slow one. The mounts cover the build where it *did*
change, turning a cold re-download of the whole dependency tree into a re-link.

**The `app` healthcheck interval went 15s → 5s.** Traefik will not route to the container until the
first probe passes, and the first probe is one interval after start, so `interval` is deploy latency
and not merely monitoring cadence. FrankenPHP with opcache and a pre-built view cache answers
`/health` in about two seconds; it was waiting fifteen. `start_period` stays at 40s — that governs how
long failures are *forgiven*, and shortening it would make a slow boot fail rather than wait.

> **The cache mounts need BuildKit, and the Coolify host's builder is unverified.** `RUN --mount` is
> a hard syntax error on the legacy builder rather than a slow path, so if that box is somehow not on
> BuildKit the build fails outright instead of degrading. Docker Compose v2 has defaulted to BuildKit
> for years and Coolify requires a Docker new enough to have it, so this is a formality — but it is
> the one change here that cannot fail safely, and staging is where it gets proven.

### Both open questions were answered on 2026-08-31

**The nightly prune was the whole story.** The server had `force_docker_cleanup = True` with
`docker_cleanup_frequency = 0 0 * * *`, which prunes the build cache unconditionally every midnight
— and while force is on, `docker_cleanup_threshold = 80` is **inert**, despite sitting right there
looking like the governing value. So the first deploy after any midnight paid a fully cold build,
including the ~5-minute `install-php-extensions` compile, and staging and production only shared
cached layers when both deploys fell on the same side of midnight. Force cleanup is now **off**; the
80% threshold governs, which prunes when the disk needs it rather than on a clock.

Measured immediately after, on staging:

| Deploy | What it rebuilt | Duration |
|---|---|---|
| webhook, commit `b25bab9` | whole `frontend` + `vendor` stages (the Dockerfile's own install lines changed) | **111s** |
| API-triggered redeploy, same commit | nothing — fully cached | **83s** |

A local build with everything warm runs the tail in ~10s: `install-php-extensions`, both cache-mounted
installs and `npm run build` all `CACHED`, leaving only `COPY . .`, dump-autoload (5.7s), the two
caches (1.4s) and the asset copy (0.1s).

**Build once, deploy twice is therefore shelved, not deferred.** Both apps share one Docker daemon and
`APP_NAME` is identical on each — verified from the outside, since both hosts issue a
`giftcoves-session` cookie and `config/session.php` derives that name from `Str::slug(APP_NAME)`.
`VITE_APP_NAME` is the only build arg that existed, so the `frontend` stage does not fork between the
two apps and production inherits staging's layers directly. A registry would buy little for its setup
cost.

### The one-branch model is no longer blocked

This file used to say the model needs "a production deploy path that works without the Coolify UI"
and that none existed, because `DeployTrigger` sends no `Authorization` header and the stored webhook
answers 401. That is true of the *webhook* and false of the *endpoint*. With a Bearer token:

```bash
curl -H "Authorization: Bearer $COOLIFY_TOKEN"      "http://51.75.78.173:8000/api/v1/deploy?uuid=<application-uuid>"
# → 200 {"deployments":[{"message":"... deployment queued.","deployment_uuid":"..."}]}
```

Proven against `GiftCoves-staging` on 2026-08-31. Coolify records such a deploy as `is_api: true` /
`is_webhook: false`, so the audit trail distinguishes a deliberate release from an automatic one —
which is exactly the property a manually-gated production wants.

Two cautions before adopting it. The token can redeploy, read every environment variable and reassign
domains on both applications, so making it the routine production mechanism raises the stakes on where
it lives. And production stops being `git push origin main` and becomes an authenticated request or
the Coolify UI — deliberate friction, and the point of the model, but a real cost.

**The order in the section above still binds:** auto-deploy off on `GiftCoves-prod` first, repoint
`GiftCoves-staging` second. Reversed, both apps track `main` with auto-deploy on and every commit
reaches real visitors with no staging pass.

### Reading /health after a deploy

Three fields, because the question has three parts, and one field was answering the wrong one:

| Field | Answers | Caveat |
|---|---|---|
| `commit` | which code is serving | the real SHA, short form; null on a laptop |
| `built` | when the image was made | **cacheable** — see below |
| `started` | when this container came up | read from `/proc/1` per request, never stale |

`built` comes from a `RUN date … > BUILD_STAMP` whose command is a constant string, so a redeploy of
an **unchanged commit** is a cache hit and reports the *previous* build's time. Observed exactly that
on staging: an API-triggered redeploy finishing at 19:46 served a stamp of 19:41. That is honest —
an unchanged commit really does produce the same image — but it means a stale-looking `built` is not
evidence of a failed deploy. Ask `commit` which code, and `started` whether anything restarted.

## Gotchas hit standing staging up (2026-08-07)

Five real ones, all fixed. Recorded because every one of them would recur on a
fresh environment.

| Symptom | Cause | Fix |
|---|---|---|
| Build fails `composer: not found` | Runtime stage copies `vendor/` from the composer stage but frankenphp ships no composer binary, and `dump-autoload` must run *after* the app source is present | `COPY --from=composer:2 /usr/bin/composer`, removed again in the same layer |
| Every request 502s while the container reports **healthy** | frankenphp exposes 80, 443 and 2019; adding 8080 gave Traefik four candidates and no `loadbalancer.server.port` label, so it routed to 80 where nothing listened. The healthcheck hit 8080 directly, so the container looked fine | Serve on **80** — the port Traefik already assumes. v1's WordPress works because it exposes exactly one port |
| Redirects and asset URLs come out `http://` | Traefik terminates TLS and forwards plain HTTP; Laravel saw an insecure request | `$middleware->trustProxies(at: '*')` in `bootstrap/app.php`. Safe here: the container publishes no ports and is reachable only through Traefik |
| `queue` and `scheduler` heading for permanently unhealthy | Both inherited the base image's healthcheck (`curl localhost:2019/metrics`, Caddy's admin API). Neither runs a web server | `horizon:status` for queue; healthcheck disabled for scheduler — a check that can never pass is worse than none |
| `/health` reported `"commit": "unknown"` | Believed to be that Coolify exposes **no commit SHA**. It does — `SOURCE_COMMIT` is a build-impact variable on the application; it was simply never passed through | **Fixed 2026-08-31.** Compose passes `SOURCE_COMMIT` as a build arg, the Dockerfile takes it as a late `ARG`, `/health` reports `commit` |

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

## Cutover: v2 replaced v1 at brandcoves.com — done 2026-08-10

Kept as the record of how it was done. Verified again on 2026-08-15: `brandcoves.com/health` reports
`branch: main`, built 2026-08-10T17:03:03Z, and the apex 302s to a market from FrankenPHP.

The rename to giftcoves.com ([features/rebrand.md](features/rebrand.md)) is a second domain move on
top of this one, and does not depend on it.

It was a Coolify domain move rather than a DNS move — which is what made rollback fast.

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

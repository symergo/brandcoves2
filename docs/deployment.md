# Deployment

Coolify on `51.75.78.173`, deploying from a private GitHub repo. Same box that ran v1 until it was
deleted after cutover.

```
laptop ──git push──▶ GitHub ──webhook──▶ Coolify ──▶ build · migrate · redeploy
   ▲                                                         │
   └──────── pg_dump over SSH (production → laptop only) ─────┘
```

## Two applications, one branch

Read from the Coolify API on 2026-08-31, not from memory:

| Coolify app | Branch | Auto deploy | Domain | Notes |
|---|---|---|---|---|
| `GiftCoves-staging` | `main` | **on** | `staging.giftcoves.com` | `ROBOTS_ALLOW=false`, own database, low AI caps |
| `GiftCoves-prod` | `main` | **OFF** | `giftcoves.com` | `ROBOTS_ALLOW=true` |

Application UUIDs, which the deploy trigger needs: staging `vhfcyk39ug5exk0fyvdj8qo3`, production
`gr0kqzz1er3s79u17vdph27t`, server `ek8ge5t94i9ieavic2cvrovf`.

Both: **Build Pack = Docker Compose**, **Compose Location = `/docker-compose.coolify.yml`**, domain
assigned to the **`app`** service, Scheduled Backups on **`postgres`**.

## How it deploys

```bash
git push origin main          # staging builds automatically, within the minute
# verify staging, then deploy production deliberately:
TOK=$(grep -oP '(?<=^KEY=).*' .claude/coolify_api.api | tr -d '
')
curl -H "Authorization: Bearer $TOK"   "http://51.75.78.173:8000/api/v1/deploy?uuid=gr0kqzz1er3s79u17vdph27t"
```

**There is one branch, and production has a human gate.** Pushing `main` deploys staging and nothing
else; production waits for the request above. Coolify records an API-triggered deploy as
`is_api: true` / `is_webhook: false`, so a deliberate release is distinguishable from an automatic
build in the audit trail.

The consequence to internalise: **a fix on `main` is not a fix that is live.** Under the old
two-branch model the danger was forgetting to advance `main`; now it is forgetting to trigger. Both
end the same way — production quietly serving old code while looking perfectly healthy.

> **Auto-deploy cannot be read back.** The Coolify API exposes no auto-deploy field on `GET` for an
> application and rejects it on `PATCH`; it is a UI-only setting. The only proof it is off is moving
> `main` and watching production not rebuild, which is how it was confirmed on 2026-08-31.

> **DANGER: `applications/{uuid}/stop`, `/start` and `/restart` execute on a plain `GET`.** They are
> not read-only probes. Requesting `/stop` to find out whether the route exists **stops the
> application** — that took `giftcoves.com` down for about five minutes on 2026-08-31. Never probe an
> action endpoint against production; use `GiftCoves-staging` if a route has to be discovered at all.
> `/start` queues a full **rebuild** rather than starting a container, so it is not a cheap way to
> apply changed runtime environment variables.

Two smaller facts, recorded so they are not re-derived. The applications were renamed to
`GiftCoves-*` even though renaming is documented to invalidate every issued deploy webhook; staging
has deployed since, so its webhook survived or was re-issued, and production's is unproven because it
has not built from a webhook since 2026-08-16 — which no longer matters, since production deploys by
API. And `APP_NAME` is already `GiftCoves` on both hosts, verified from outside by the
`giftcoves-session` cookie that `config/session.php` derives from `Str::slug(APP_NAME)`; there is
nothing left to rename and nobody to log out.

## Pushing is a deploy, so pushing is asked for

Nobody — and no agent — pushes `main` or triggers production on their own initiative. Work gets
committed locally and left there; the branch is *ready to push*, and whether it goes out is a
decision someone makes deliberately, each time. Approval for one push is not approval for the next
one.

This is a consequence of the section above rather than a separate policy: pushing `main` deploys
staging within the minute, and production is one authenticated request away. Neither has a later
moment at which a person reviews what is shipping, so the push and the trigger are the moments that
have to be chosen.

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

## One branch, two apps — adopted 2026-08-31

Both applications track **`main`**. Staging deploys every push; production only when someone
triggers it. The `staging` branch has been **deleted**.

It replaced a `staging` → `main` fast-forward that was bookkeeping encoding what a deploy already
records, and which drifted. `main` sat **seven commits** behind at one point, including four bug
fixes, while production served real traffic. Worse, the drift was invisible: nothing about production
looked wrong, it was simply old, and the narrower advertiser allowlist in those unshipped commits was
quietly costing catalogue.

It happened again, and worse, on the day the model was adopted: `main` was four commits behind
`staging`, one of which was the OVH mail fix, so **production could not send a magic link at all** —
for a fortnight — while `/health` reported `ok`. That is the failure this model makes structurally
impossible, because there is no second branch to drift.

**The order it was taken in, which matters if it is ever rebuilt:**

1. Auto-deploy **off** on `GiftCoves-prod`.
2. `main` fast-forwarded to `staging`, so nothing was stranded.
3. `GiftCoves-staging` repointed from `staging` to `main`.
4. Only then, the `staging` branch deleted — local and remote.

Doing (3) before (1) would have pointed both apps at one branch while production still auto-deployed:
every commit to real visitors with no staging pass, strictly worse than the model it replaced. Doing
(4) before (3) would have left the staging app tracking a branch that no longer exists.

**What you give up:** there is no longer a branch where in-progress work can sit and still deploy to
staging. `main` must always be deployable, because pushing it *is* a staging deploy.

Keep both environments. Since v1 was deleted there is no fallback, so staging is the only place a bad
migration surfaces before real visitors meet it — and the whole stack idles at ~390 MiB.

## Services

| Service | Role |
|---|---|
| `migrate` | one-shot, runs `migrate --force --isolated`, exits. Everything else waits on it |
| `app` | FrankenPHP on :80 from `docker/Caddyfile`, Traefik routes the domain here. Classic mode; worker mode (Octane) is prepared behind `OCTANE_WORKERS`, off (features/speed.md, "Worker mode") |
| `queue` | `php artisan horizon` — **exactly one replica** |
| `scheduler` | `php artisan schedule:work` — **exactly one replica** |
| `postgres`, `redis` | state; both volumes backed up |

**One more volume since 2026-09-26: `media_data`**, mounted at `/app/storage/app/media` on `app`,
`queue` and `scheduler` (the `x-app` anchor). It holds pictures on list items: photos people upload
and pictures copied from pasted shop pages ([pasted-links.md](features/pasted-links.md)). Unlike
everything else in the image it must survive a deploy, and it is personal data: include it in the
backups next to Postgres. A missing volume does not break the site; pictures stored since the last
deploy disappear and their items show no picture.

Two Horizons would double-process every job, including feed ingestion. `stop_grace_period: 60s` lets
the in-flight job finish rather than abandoning a half-ingested chunk.

### What runs when a container starts (since 2026-09-27)

Every service built from the image (`migrate`, `app`, `queue`, `scheduler`) starts through
`docker/entrypoint.sh`, which runs `php artisan config:cache` and `route:cache` and then execs the
container's own command through the base image's entrypoint. The caches are built **here and not in
the Dockerfile** because one image serves staging and production: `config:cache` freezes the
environment it runs in, and only the running container has Coolify's variables (`APP_KEY`, the
database, `SOURCE_COMMIT`). A failed cache is cleared and the container serves uncached, with an
`entrypoint: ... failed` line in its log, because a container that will not start is an outage here.

Two consequences:

- **A changed environment variable needs a restart, not only a save.** It was always so for a
  running PHP process; with the config cached per container start it is also the only way. A
  redeploy or restart in Coolify rebuilds the cache.
- **`env()` belongs in `config/` only.** Under a cached config Laravel no longer reads `.env`; code
  that needs a setting reads `config()`. `ConfigContractTest` checks that every setting reaches the
  compose file.

`app` runs `frankenphp run --config /app/docker/Caddyfile` (it was `php-server ... -v`). The
Caddyfile serves plain HTTP on :80 from `/app/public` exactly as php-server did, and adds a year of
`immutable` caching on the hashed bundles under `/build/assets`, a day on the icons, and a JSON
access log on stderr with each request's `duration`, without `/health` and without the
forwarded-for headers. `-v` was Caddy's debug log, about 39,000 lines in ten hours. Details and the
reasoning: [features/speed.md](features/speed.md), "Server and pipeline".

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

**2026-09-27: 30s once up, 2s while starting.** The 5s interval ran `/health` (a database and a Redis
check) about 17,000 times a day per environment to buy a fast first route. `start_interval: 2s`
buys that during `start_period` alone, and `interval: 30s` (Docker's default, and the rule in
"Every curl carries a timeout" below) applies after; retries 3. The probe carries its own 4s socket
timeout. `start_interval` needs **Docker Engine 25+**, and compose refuses the file on an older
engine instead of ignoring the key: if a deploy fails with "healthcheck.start_interval ... requires
Docker Engine v25", remove that one line and set `interval` back to 5s. Staging and production share
the host, so the first staging deploy settles it for both.

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
and that none existed, because `DeployTrigger` (the admin Migration page's Deploy button, removed on
2026-09-14 for exactly this reason) sent no `Authorization` header and the stored webhook answered
401. That is true of the *webhook* and false of the *endpoint*. With a Bearer token:

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
| `/health` reported `"commit": "unknown"` | Coolify injects `SOURCE_COMMIT` into the build itself. Declaring it in the compose `args` block to "pass it through" is what broke it: Coolify materialises a **stored** environment variable for every `${VAR}` it parses out of the compose, and a stored variable shadows the value it injects per deployment | **Fixed 2026-09-02**, not 2026-08-31 — the earlier entry claimed a fix that was the cause. The compose reference is removed; the Dockerfile keeps its late `ARG`. Deleting the variables in the UI alone is not enough, the next parse recreates them |

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

## Cutover from v1 — done 2026-08-10

v2 replaced the v1 WordPress site at brandcoves.com by a Coolify domain move, not a DNS change,
which is what made rollback a minute. The runbook, the rollback plan and the rule for v1 URLs (a
short list redirects, the rest 404 on purpose) are in [features/cutover.md](features/cutover.md).

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
# Use the ssh CONFIG ALIAS, not root@51.75.78.173. `~/.ssh/config` binds
# brandcoves-vps to the host, the root user and ~/.ssh/brandcoves_vps; connecting
# by raw host offers no key and fails `Permission denied (publickey,password)`,
# which reads as "no access" and is really the wrong invocation.
#
# And match the PRODUCTION container specifically. There are two postgres
# containers now, one per environment, distinguished by the Coolify project name
# which is the application UUID. The old `grep '^postgres-'` matched both, and
# restoring the wrong one silently gives you staging's small dataset:
#   postgres-gr0kqzz1er3s79u17vdph27t-...  production
#   postgres-vhfcyk39ug5exk0fyvdj8qo3-...  staging
PG=$(ssh brandcoves-vps "docker ps --format '{{.Names}}' | grep gr0kqzz1er3s79u17vdph27t | grep -i postgres")
ssh brandcoves-vps "docker exec $PG pg_dump -U brandcoves -Fc brandcoves" > "$TEMP/prod.dump"
docker compose exec -T postgres pg_restore -U brandcoves -d brandcoves --clean --if-exists < "$TEMP/prod.dump"
php artisan bc:scrub --force
```

Write the dump **outside this repo** — it sits in a Synology-synced folder, and `*.dump` being
gitignored does nothing about the sync. The restore **destroys the local development database**.

To read production without copying anything off the box, let the container supply its own
credentials rather than looking them up:

```bash
ssh brandcoves-vps "docker exec -i $PG sh -c 'psql -U \$POSTGRES_USER -d \$POSTGRES_DB -X -P pager=off'" <<'SQL'
SELECT count(*) FROM product_groups WHERE market='en';
SQL
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
- **VPS headroom:** v2 adds Postgres + Redis + three PHP containers *per environment* to a box that
  ran v1's MySQL and Apache alongside them until v1's containers were retired at the end of the
  post-cutover watch period. Check free memory before standing a new environment up.

## Proposal: Postgres and Redis out of the app deploy (for the owner to decide)

*Written 2026-09-27, speed audit wave 4. Nothing here is built. It is a plan with options.*

**The problem.** `postgres` and `redis` are services in `docker-compose.coolify.yml`, so they
belong to the application resource. Coolify stops and recreates them on every deploy. Each deploy
therefore costs:

- **Postgres's memory.** The 1 GB of shared buffers and the OS file cache for its 3.4 GB working set
  start cold. The first minutes after a deploy read from disk. After an unclean stop on 2026-09-14,
  Whisperer requests hit the 30-second limit.
- **Every Redis cache.** Redis reloads from its appendonly file, so queued jobs survive. But until
  it has finished loading, it refuses commands; that once made `migrate` fail and production answer
  503 (see the Redis healthcheck comment in the compose file). And everything built with an expiry
  (search ids, translations, shop lists, the anonymous page cache planned in wave 3b) is rebuilt by
  the first visitors after the deploy.
- **Deploy time and risk.** The app waits for Postgres to be healthy after a restart it did not
  need. A database stopped mid-checkpoint goes through crash recovery.

The databases change a few times a year (a version, a setting). The app changes several times a day.
They should not share a lifecycle.

### Options

| | What | For | Against |
|---|---|---|---|
| **A. Coolify database resources** | Create a standalone PostgreSQL 16 and a standalone Redis 7 in Coolify (per environment). The app compose loses its `postgres` and `redis` services and points `DB_HOST` / `REDIS_HOST` at them. | Coolify's own scheduled backups (to S3) and restore buttons for Postgres. Deploying the app never touches them. | The settings move out of git and into Coolify's UI: `shared_buffers`, `pg_stat_statements`, `shm_size`, Redis `maxmemory` and `volatile-lru` would all have to be entered there, and they drift silently. The compose file's comments, which record why each setting exists, stop describing what runs. |
| **B. A second compose resource, "state"** | A new file, `docker-compose.state.yml`, holding only `postgres` and `redis` exactly as they are today, deployed as its own Coolify resource. It is deployed only when that file changes. It joins a shared Docker network with the app. | The configuration stays in the repository, with its reasons. The app deploy no longer restarts the databases. Closest to what exists now. | Coolify has to be told to put both resources on one network ("Connect to Predefined Network" on each resource). Backups stay what they are today: the scheduled dump, not Coolify's database backups. One more resource per environment. |
| **C. Leave them, soften the cost** | Keep the compose as is. Add `pg_prewarm` (autoprewarm reloads the buffer cache after a restart), and keep the stop grace period. | No migration, no downtime. | Redis caches still empty on every deploy, and the restart itself stays. It treats the symptom. |

**Recommendation: B.** The settings that make Postgres and Redis behave (all written down with
their reasons in the compose file) stay in git, and deploying the app stops restarting them.
Option A's backup buttons are the one thing B lacks; they can be added later by pointing a Coolify
scheduled task at `pg_dump`.

### Steps for B (per environment, staging first)

1. **Write `docker-compose.state.yml`.** Move the `postgres` and `redis` services and the `pg_data`
   and `redis_data` volumes into it unchanged. Give the stack a fixed network name, for example
   `giftcoves-state-staging`, declared as external in both files.
2. **Create the "state" resource** in Coolify from that file, on the same server. Enable "Connect to
   Predefined Network" on it and on the app resource, and check that `app` can resolve and reach
   the new hosts: `getent hosts` and `pg_isready` from inside the app container.
3. **Move the data.** This is the step with downtime. It is also the step to rehearse on staging.
   - Stop the app's `queue` and `scheduler` so nothing writes.
   - `pg_dump -Fc` from the old Postgres, then `pg_restore` into the new one.
   - Before stopping the old Redis, wait for Horizon to finish its jobs, or copy its
     `appendonly.aof` into the new volume.
   - Expect minutes for staging. For production, measure the dump on the laptop copy first; a few
     GB is typically 10–30 minutes.
   - Alternatively, attach the existing named volumes to the new stack, so no data is copied at all.
     Coolify prefixes volume names with the resource UUID, so check the real names with
     `docker volume ls` first.
4. **Point the app at the new hosts** (`DB_HOST`, `REDIS_HOST`). Then remove `postgres` and `redis`
   from `docker-compose.coolify.yml`, along with the `depends_on` entries on them in the `x-app`
   anchor. Deploy.
5. **Keep the `migrate` service's safety.** Today `migrate` waits for Postgres and Redis to be
   healthy through `depends_on`. Across resources that wait is gone, so `migrate` should wait
   itself: a few retries of `pg_isready` and a Redis `PING`, then run.
6. **Watch a deploy.** The Postgres and Redis containers' `StartedAt` must not change, the first
   page after the deploy must be fast, and Horizon must keep its queue.
7. **Only then, production.** Do it outside the night windows (05:40–09:45 and 18:10–19:45 Belgian
   time), with a fresh `pg_dump` taken just before.

### Risks

- **The network step is the unknown.** If the app cannot resolve the new hosts, every request fails.
  Rehearse on staging, and keep the old services in the compose file, unused, until the new ones
  have served for a day (expand/contract, as for migrations).
- **A wrong host is an outage at `migrate`.** Coolify stops the old containers before `migrate`
  runs. Check that the new `DB_HOST` answers from inside a
  running app container **before** deploying the change.
- **Two copies of the data** while both exist. Remove the old volumes deliberately, after the
  backups have run from the new ones.
- **Backups.** The scheduled dump and the `media_data` backup must be repointed at the new
  containers. A backup script that greps for `postgres-<app uuid>` finds nothing after the move.
  See "Getting production data onto the laptop" above for the container naming.

## Proposal: Cloudflare in front (optional, needs the owner's DNS)

*Written 2026-09-27. Not set up. It needs `giftcoves.com`'s DNS moved to Cloudflare (the account
already holds the bstore zones).*

What it would add: a cache near the visitor for everything we already mark cacheable, absorbing
crawler bursts before they reach the VPS, and HTTP/3. With the orange cloud on, Traefik keeps
terminating TLS behind Cloudflare. Use SSL mode **Full (strict)**: Let's Encrypt keeps renewing
through the proxy on HTTP-01.

The rules, matching speed plan section 4 (the anonymous page cache):

1. **Static, cache everything, respect the origin's headers:** `/build/assets/*` (a year,
   immutable), `/img/*` (the image proxy, a year), `/media/items/*` (a year), `/icons/*` and
   `/favicon.ico` (a day). The origin already sends these headers; Cloudflare only has to honour them.
2. **Pages: cache only what the origin marks public, and only for visitors without a session.**
   - Bypass the cache when the request carries `laravel_session`, `remember_web_*` or `XSRF-TOKEN`.
   - Otherwise, "Cache eligible, respect origin": after wave 3b, anonymous pages carry
     `Cache-Control: public, max-age=60, stale-while-revalidate=600`, and everything else stays
     `private` or `no-cache` and is not stored.
   - The cache key must include `X-Inertia` (via a custom cache key or `Vary`). Otherwise an Inertia
     JSON response is served to a browser asking for HTML.
3. **Never cache:** `/admin*`, `/api/*`, `/livewire/*`, `/horizon*`, `/health`, any method other
   than GET or HEAD, and search results (`/*/search*`), as in plan section 4.
4. **Visitor IP.** Behind Cloudflare, every request arrives from a Cloudflare address.
   - `TrustProxies` must trust Cloudflare's published ranges, and the app must read
     `CF-Connecting-IP`.
   - Otherwise every IP-keyed limit (the image proxy's per-visitor budget, the social-card
     throttle, the editorial API's fallback) would count all visitors as one.
   - Do this **before** switching the orange cloud on.
5. **Bots.** Leave Bot Fight Mode off at first. It has blocked Google's image and feed fetchers on
   other sites. Block the Amazon crawlers the same way the bstore zones do, if wanted.

Risks:

- The IP rule above.
- Stale HTML for up to 60 s plus the revalidation window, which the plan already accepts for
  anonymous visitors.
- A purge is one more step when a page must change at once. Only the Cove release does, and the
  plan's cache-forget on publish does not reach Cloudflare. Either keep the page cache at the
  origin only (Cloudflare caching static files only, rule 1), or purge by URL from
  `PublishDueCoves`. **Start with rule 1 alone**; it is risk-free and carries most of the byte
  savings.

## Every curl carries a timeout

A `curl` with no ceiling does not fail, it *hangs*, and a hang inside a healthcheck compounds. The
WordPress resource on this VPS ran `curl -f http://127.0.0.1` on a **2-second** interval with no
`--max-time`, so every unanswered check became a permanent process. 308 of them accumulated in
uninterruptible IO wait (D-state), the load average read **360** on 6 cores, and Coolify's own API
started returning 504 — which reads like a dead box rather than one site's healthcheck. Docker's
`HEALTHCHECK --timeout` does **not** rescue this: a process in D-state cannot be killed, so the
timeout fires and reaps nothing.

`/root/.curlrc` on the VPS sets `connect-timeout = 10` and `max-time = 300` as a floor — generous on
purpose, because it is a hang-catcher and a tight global ceiling would truncate real transfers. A
healthcheck passes its own tight bound (`--max-time 5`), and an interval of seconds is almost always
wrong; 30s is Docker's default for a reason.

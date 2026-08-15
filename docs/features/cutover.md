---
name: Cutover from v1
area: Operations
status: Done — v2 has served brandcoves.com since 2026-08-10
date_added: 2026-08-08
---

# Cutover from v1

Moving `brandcoves.com` from the v1 WordPress site to v2.

> **This happened, and the document is kept as the record of how.** Verified 2026-08-15:
> `brandcoves.com/` answers 302 to `/be-nl` from FrankenPHP, and `/health` reports `branch: main`
> with a build stamp of 2026-08-10T17:03:03Z. v2 owns the apex.
>
> Every domain below is `brandcoves.com` because that is the domain this cutover moved. The rename to
> GiftCoves ([rebrand.md](rebrand.md)) is a *second* domain move, on top of a v2 that is already
> live — so the two were never going to collide, and the ordering worry recorded here earlier was
> based on this file's stale `not yet executed` status rather than on the running system.
>
> **The lesson worth keeping is about the status line, not the cutover.** A runbook that says "ready"
> after it has been executed will be believed by the next person to read it, including a machine.
> Check `/health` before trusting any claim in this directory about what production is doing.

Both run on the same box under the same Coolify instance, which makes this a
**domain move, not a DNS change** — the single most important fact in this
document. DNS propagates for hours and cannot be undone quickly; a Coolify
domain swap takes effect in seconds and reverses just as fast. Rollback is
therefore a minute, not a day, and that is what makes the whole thing safe to
attempt on a weekday.

---

## Before the day

### 1. Everything below must already be true

- [ ] `staging.brandcoves.com/health` reports `ok`, with both database and Redis checks green.
- [ ] The staging branch has been fast-forwarded to what will become `main`.
- [ ] `php artisan bc:check-bol` passes **on the server**, not just locally.
- [ ] `php artisan bc:refresh-discovery` has run for every market, so no
      discovery surface is empty on the first visit.
- [ ] At least one Daily Cove edition exists per market. A dead `/daily` on day
      one teaches the first visitors the page is not worth returning to, which
      is the one thing that feature cannot recover from.
- [ ] `AI_ENABLED` is set deliberately, either way. Both modes are supported;
      what is not supported is discovering which one you are in during cutover.

### 2. Take a v1 backup you have actually restored

Not "a dump exists" — a dump you have loaded somewhere and looked at. An
untested backup is a belief, not a backup.

```bash
ssh root@51.75.78.173 'docker exec brandcoves-db-1 \
  sh -c "mysqldump -uroot -p\$MYSQL_ROOT_PASSWORD \$MYSQL_DATABASE" | gzip' > v1-cutover.sql.gz
```

If it comes back to a laptop, `php artisan bc:scrub` is mandatory afterwards —
`users`, `recipients` and `wishlists` hold real emails and personal gift notes,
and this repo sits in a Synology-synced folder.

### 3. Know what the v1 URLs do

v2 redirects a **short list** of v1 entry points and deliberately 404s the rest.
See [`LegacyRedirects`](../../app/Services/Seo/LegacyRedirects.php) for why:
mapping `/articles/some-post` to the guides index is a soft 404 that misleads
the reader as much as the crawler.

That is a decision, not an oversight, and it means **v1's article URLs will lose
their rankings**. If that is not acceptable, the answer is to port the articles
into `guides` with their slugs before cutover — not to widen the redirect map.

---

## The cutover

Roughly fifteen minutes, most of it waiting.

### 1. Fast-forward main

```bash
git checkout main && git merge --ff-only staging && git push origin main
```

Forward-only. If the merge is not a fast-forward, stop — `main` has diverged and
something was pushed to it directly.

### 2. Let production deploy and verify it in isolation

Production must be green **on its own domain** before any traffic moves:

```
https://<production-domain>/health
```

Check `built`, `branch: main`, and the migration name. Migrations run as a
one-shot service before `app` starts, so a failed migration means no new
container — the old one keeps serving, which is the correct failure.

### 3. Move v1 aside

In Coolify, change the v1 application's domain to `v1.brandcoves.com`.

**Before** pointing v2 at the apex, not after. Two applications claiming the
same domain leaves Traefik to pick one, and it will not pick the one you meant.

### 4. Point v2 at the apex

Set the v2 production application's domain to `brandcoves.com` (plus `www`, if
that is how the certificate is issued). Wait for the certificate.

### 5. Verify, in this order

```bash
curl -sI https://brandcoves.com/                    # 302 to a market
curl -sI https://brandcoves.com/be-nl               # 200
curl -s  https://brandcoves.com/health              # ok, branch main
curl -sI https://brandcoves.com/search              # 301 -> /en/search
curl -s  https://brandcoves.com/robots.txt          # not the staging noindex
curl -s  https://brandcoves.com/sitemap.xml | head  # five market sitemaps
```

`robots.txt` deserves its own look. Staging serves a blanket `noindex`, and
shipping that to production is the classic replatform disaster — invisible for
days, then the traffic goes.

### 6. Then the manual pass

Search → compare offers → product page → save to a list → gift wizard → share
and claim from a **second browser** → today's Cove → guess the price → react to
a pick → open a guide → scan a barcode → click through to a merchant.

The second browser matters: claim state is the one thing in this codebase whose
failure is silent and unrecoverable, because the owner seeing it does not
produce an error, it produces a spoiled surprise.

---

## Rollback

One step, and it is the reason the domain move is worth doing this way.

1. In Coolify, set v1's domain back to `brandcoves.com`.
2. Set v2's back to its production-only hostname.

Seconds, no DNS, no cache. **v1 stays deployed and running for a fortnight** —
do not delete it, and do not reuse its database. Rolling back is only cheap
while the thing you are rolling back to still exists.

### What rollback does not undo

Accounts created on v2, lists saved on v2, and claims made on v2 live in v2's
database and are invisible to v1. A rollback after a day of real traffic
therefore loses that day's user data from the visitor's point of view — the rows
survive, but nobody can reach them until v2 returns.

This is the real deadline: rollback is free in the first hour and expensive
after the first day. Decide early.

---

## After

- [ ] Submit `https://brandcoves.com/sitemap.xml` in Search Console, and keep
      the v1 property open — that is where the 404s will show up if the redirect
      decision was wrong.
- [ ] Watch `events` and the log for a spike in 404s over the first 48 hours.
- [ ] Confirm the scheduler is running on production: an edition should appear
      the next morning at 09:00. If it does not, `queue` or `scheduler` is not
      up, and both must run **exactly one replica** — two Horizons double-process
      every job.
- [ ] Rotate anything that was shared during the move. `CLAIM_HASH_SECRET` is
      the exception: rotating it orphans every existing wishlist claim, so treat
      it as permanent.
- [ ] After a fortnight of quiet, v1 can be stopped. Keep the database dump.

## Files

- `app/Services/Seo/LegacyRedirects.php` — what redirects and what does not
- `app/Console/Commands/RefreshDiscoveryCommand.php` — fill the surfaces before traffic
- `app/Console/Commands/CheckBolCommand.php` — prove the live source works
- `docs/deployment.md` — the pipeline this sits on top of

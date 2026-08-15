---
name: The rename to GiftCoves
area: Core
status: Code done; infrastructure and third-party accounts outstanding
date_added: 2026-08-15
---

# Brandcoves → GiftCoves

The site is called **GiftCoves** and lives at **giftcoves.com**.

The name follows the product rather than the other way round. Gifting is the front door — the Gift
Cove, the Whisperer, lists, Secret Santa — and discovery is the engine behind it. "Brandcoves"
described the engine.

This was the second of two options. The first was two sites: `giftcoves.com` for the gifting tools
and `brandcoves.com` for articles and discovery, on one instance. It was rejected, and the reason is
worth keeping because it will be proposed again:

- **The session cannot be split.** Cookies are scoped to a registrable domain, so an anonymous list
  built on one is invisible on the other — and lists are anonymous-first by design
  ([wishlists.md](wishlists.md)). The save button lives on product cards, which would have been on
  the other domain from the list they save into.
- **Interest coves and product pages are one topical cluster.** "Gifts for someone who is into
  bouldering", "best climbing shoes" and `/brand/scarpa` earn authority together. Split across two
  domains, neither site is the authority on climbing, and the internal links that carry a reader from
  a cove down to an offer become outbound links.
- **Every affiliate programme registers a site, not a company.** Two domains means two registrations
  with Amazon, Awin and bol before either can show a price.

One domain with the better name costs one migration. Two domains cost all three problems forever.

## What changed

| | |
|---|---|
| Brand name, everywhere a human reads it | `APP_NAME`, `lang/*/site.php`, `resources/legal/**`, breadcrumbs, `StructuredData`, the OG card wordmark, the Filament panel |
| Domain | `giftcoves.com`, `staging.giftcoves.com`; `hello@` and `privacy@` follow it |
| Config namespace | `config/brandcoves.php` → `config/giftcoves.php`, and every `config('brandcoves.*')` call |
| Assets | `public/icons/giftcoves.svg`, `giftcoves-512.png`, and the `bc_logo/files/` sources |
| bol click tracker | `BolConnector` sends `name=giftcoves-{market}`, so the affiliate report is labelled with the site that earned it. Reporting continuity breaks at the rename — that is the point of it |

## What deliberately did not change

Each of these was considered and left alone. They are invisible to visitors, and moving them costs
real risk for nothing.

| | Why not |
|---|---|
| `bc_search_vector()`, `bc_text_config()` | `bc_search_vector()` backs a **stored generated column** on `products`. Postgres cannot alter a generated column's expression, so renaming the function means dropping and re-adding the column — a full table rewrite of the entire catalogue's search index, to change a name nobody reads. See the search notes in CLAUDE.md |
| `php artisan bc:*` | Fifteen commands, the scheduler and every runbook reference them. Operator-facing only |
| The `bc_visitor` cookie | Renaming it orphans every anonymous identity a second time, on top of the loss the domain move already causes. Same class of permanent decision as `CLAIM_HASH_SECRET` |
| Database name, user, password | A production database rename for a cosmetic gain. `docker-compose.yml` and `phpunit.xml` keep `brandcoves` |
| Coolify application names (`brandcoves2-*`) | Renaming an application invalidates every deploy webhook already issued against it — including the one stored encrypted for [content promotion](content-promotion.md) |

## Anonymous lists do not survive the move

**Decided, and accepted.** Cookies do not cross a registrable domain, so `bc_visitor` does not follow
`brandcoves.com` → `giftcoves.com`. Signed-in visitors sign in again. Anyone who built a list without
an account loses it.

The alternative was a one-time handoff: keep the old domain serving, 302 through a short-lived signed
token carrying the anonymous identity id, and re-issue the cookie on the new domain. It was not
built. If the decision is revisited it has to be built **before** the DNS moves — afterwards there is
no request left to carry the token.

Note the same cookie hazard applies without any domain move: `config/session.php` derives the session
cookie name from `APP_NAME`, so `brandcoves-session` becomes `giftcoves-session`. Deploying the
rename onto the *old* domain would log everyone out on its own.

## The old domain 301s, path intact

`App\Http\Middleware\RedirectLegacyHost`, registered **globally and first** in `bootstrap/app.php`.

Not in the 404 handler where [`LegacyRedirects`](cutover.md) lives: that one catches v1 WordPress
paths, which fail to route and therefore reach a 404. `brandcoves.com/be-nl/search` is a perfectly
valid route — it would answer 200 with the wrong domain in the address bar and never reach a 404
handler at all.

```
CANONICAL_HOST=giftcoves.com
LEGACY_HOSTS=brandcoves.com,www.brandcoves.com
```

Three properties hold it together:

- **Path and query survive.** Every indexed URL redirects to its own counterpart. Collapsing them
  onto the homepage is how a migration discards its index — Google reads a mass redirect to `/` as a
  soft 404 and drops the ranking rather than transferring it.
- **`/health` and `/up` answer on any host.** Coolify reaches the container directly rather than
  through the domain, so a 301 there reads as an unhealthy container and rolls the deploy back.
- **Empty config means no redirect.** Local development and any environment before its cutover are
  unaffected, so a deploy cannot start redirecting before DNS is ready.

`tests/Feature/LegacyHostRedirectTest.php` covers each, including that the canonical host is served
rather than redirected — a rule matching its own destination is an infinite chain that surfaces only
as `ERR_TOO_MANY_REDIRECTS`.

## The Coolify side

**None of this is done.** The codebase is renamed; the infrastructure still serves the old domains.
Both applications keep their `brandcoves2-*` names.

Order matters — DNS first, then domains, then env, and the env vars must move *together*.

1. **DNS.** `giftcoves.com` and `www.giftcoves.com` → `51.75.78.173`. Same for
   `staging.giftcoves.com`. **Leave `brandcoves.com` pointed at the same box** — the 301s are served
   by this app, so removing the record makes every old link fail instead of redirect.

2. **Domains**, on the **`app`** service of each application. Add the new ones and *keep* the old
   ones attached. Traefik issues certificates on first request, so the old domain needs a valid cert
   for as long as it is redirecting.

   | App | Domains after |
   |---|---|
   | `brandcoves2-staging` | `staging.giftcoves.com`, `staging.brandcoves.com` |
   | `brandcoves2-prod` | `giftcoves.com`, `www.giftcoves.com`, `brandcoves.com`, `www.brandcoves.com` |

3. **Environment**, both apps:

   ```
   APP_NAME=GiftCoves            # also a Build Variable — VITE_APP_NAME derives from it
   APP_URL=https://giftcoves.com # https://staging.giftcoves.com on staging
   MAIL_FROM_ADDRESS=hello@giftcoves.com
   CANONICAL_HOST=giftcoves.com
   LEGACY_HOSTS=brandcoves.com,www.brandcoves.com
   ```

   `APP_NAME` must be ticked **Build Variable** as well as set at runtime. `VITE_APP_NAME` is
   `${APP_NAME}` and is baked into the client bundle at build time — left as a runtime variable it is
   `undefined` in the browser, and server-rendered pages look correct while client-side interaction
   silently breaks.

4. **Redeploy both**, staging first. A rebuild is required, not a restart: the client bundle carries
   the name.

## What has to be re-registered elsewhere

The rename is not finished when the site loads. Each of these is a separate account with its own
approval, and the first three can cost the affiliate income the site runs on.

- **Amazon Associates** — every site is registered per account, and PA-API content may only be
  displayed on a registered site. Add `giftcoves.com` before Phase 8 turns Amazon on. The tracking
  IDs in `AMAZON_PARTNER_TAGS` are issued by Amazon and are not ours to rename. See
  [amazon-compliance.md](amazon-compliance.md).
- **Awin** — the publisher profile carries a site URL, and some advertisers re-approve on change.
- **bol partner** — site registration plus the `bolPartnerSiteId()` values per market.
- **Google OAuth** — `GOOGLE_REDIRECT_URI` derives from `APP_URL`, so the new callback
  `https://giftcoves.com/auth/google/callback` must be added to the OAuth client **before** the
  deploy, or Google sign-in returns `redirect_uri_mismatch` for everyone.
- **Mail domain** — SPF, DKIM and DMARC for `giftcoves.com` on Resend. Sending as
  `hello@giftcoves.com` from a domain with no DKIM lands in spam, and a double opt-in confirmation
  that lands in spam is a subscriber lost silently. `hello@` and `privacy@` are published in the
  legal pages and, as [legal-pages.md](legal-pages.md) records, **still do not exist as mailboxes** —
  the privacy address is a GDPR commitment to answer within a month.
- **Search Console** — add `giftcoves.com` as a property and submit
  `https://giftcoves.com/sitemap.xml`. Do **not** file a Change of Address from `brandcoves.com`
  unless that property was verified and had traffic; the 301s carry the signal on their own.

## Still open

- **The positioning copy still describes the old shape.** `resources/legal/{en,nl}/about.md` opens
  with *"GiftCoves is a brand and product discovery site"*, which is accurate and no longer the point
  of the name. Rewriting it is editorial work with legal weight — the "we are not a shop"
  characterisation is load-bearing in the imprint — so it was left rather than quietly reworded. Note
  that a rewrite of shipped copy needs `bc:seed-copy --replace`, or the seeded slot shadows the
  language file and the rewrite is invisible.
- **The interest-cove `GuideKind`** that motivated the rename is not built. See
  [discovery-modes.md](discovery-modes.md) — *"a ghost-shop persona would be another pool feeding the
  same retriever"*.

## Verification

```bash
curl -sI https://giftcoves.com/be-nl                      # 200
curl -sI https://brandcoves.com/be-nl/search?q=test       # 301 -> giftcoves.com, same path and query
curl -s  https://giftcoves.com/health                     # ok
curl -s  https://brandcoves.com/health                    # ok, NOT a redirect
curl -s  https://giftcoves.com/sitemap.xml | head         # market sitemaps on the new host
```

## Files

- `app/Http/Middleware/RedirectLegacyHost.php`
- `config/giftcoves.php` — `canonical_host`, `legacy_hosts`
- `bootstrap/app.php` — global, prepended
- `tests/Feature/LegacyHostRedirectTest.php`
- `docs/deployment.md` — the Coolify table, still pre-rename

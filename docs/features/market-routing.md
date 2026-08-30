---
name: Market routing
area: Core
status: Active
date_added: 2026-08-07
---

# Market routing

Every public page lives under `/{market}/` so a URL is unambiguously about one catalogue.

## Why "market" and not "locale"

Laravel already has an app locale, used for framework strings. Conflating the two is a footgun that
bites at exactly the wrong moment.

`be-nl` and `nl-nl` are the **same language** and **different markets**: different merchants,
different prices, different tax, different delivery. A visitor in Antwerp and one in Utrecht read the
same Dutch and must not see the same offers. So:

- **Market** decides the catalogue, currency, bol country code and `hreflang`.
- **Language** (derived from the market) decides which translation strings load.

`App\Enums\Market` is the single source of truth for both.

## How it resolves

| Step | What |
|---|---|
| `Route::pattern('market', ...)` | Constrains `{market}` to the five known values, so an unknown market 404s at the router rather than reaching a controller with a bad value |
| `SetMarket` middleware | Binds `CurrentMarket` into the container, sets the app locale, sets `Content-Language` |
| `HandleInertiaRequests` | Shares the current market and the full market list with every page |
| `app.blade.php` | Sets `<html lang>` from `hrefLang()`, not from the app locale |

`Content-Language` is set on the response because caches and CDNs must not serve a Dutch page to a
French visitor.

## Root redirect

`/` sends the visitor to `MarketPreference::resolve()` — **the stored choice first, then a guess from
`Accept-Language`** — and issues a **302, never a 301**. The destination varies per visitor; caching
it into permanence would pin someone to a market they never chose.

For the same reason the response carries `Cache-Control: no-store, private`. It varies on a cookie
*and* on a request header, so a shared cache holding one copy would hand the next visitor somebody
else's market. `private` alone was not enough: a browser would still reuse a stale guess after the
visitor switched.

Matching is deliberately conservative — exact BCP 47 tag first (`nl-BE` → `be-nl`), then
language-only (`fr` → `be-fr`), then the default. A wrong guess shows the wrong currency and the
wrong merchants, so anything unrecognised falls back rather than being approximated.

Negotiation only ever selects a **published** market. A Spanish `Accept-Language` resolves to the
default, not to `es`.

## Remembering the choice

`Accept-Language` is the browser's language list and nothing else — no geolocation, no account
setting. That is a fair first guess and a bad permanent answer, and the gap is not hypothetical: a
Belgian machine whose browser language is plain "Nederlands" reports `nl-NL`, so it lands on the
**Dutch** catalogue. Chrome and Windows offer "Nederlands" far more prominently than "Nederlands
(België)", so this is the common case in Belgium, not an edge one.

Before the cookie, clicking the Belgian flag fixed it only until the next visit to the bare domain,
which re-ran the same negotiation and sent the visitor straight back. **The switcher looked broken
because its effect had no memory.**

`bc_market` holds the choice for a year — encrypted, `httpOnly`, `SameSite=Lax`, same lifetime as
`bc_visitor`. The expiry does not slide: refreshing it would mean a `Set-Cookie` on every request to
spare a visitor who has not returned in twelve months one flag click.

### Only an explicit choice is recorded

The cookie is written by `MarketPreferenceController` and **by nothing else** — in particular not by
`SetMarket`, which would have been a one-line change and the wrong one. `SetMarket` runs on every
market page, including one reached by opening a friend's shared `/nl-nl/p/123` link, so writing there
would let any link anyone sends you silently repoint your home market. A guess must never be able to
promote itself into a preference.

### Why the switcher POSTs

`POST /market`, not a link. A GET would be a URL that silently rewrites the recipient's market
preference — exactly the shape of thing that gets pasted into a chat and clicked. As a POST it needs
a CSRF token, so only this site's own switcher can spend it.

The switcher therefore builds and submits a hidden form rather than setting `location.href`. It was
already doing a full document load — the market changes catalogue, currency and language at once, and
anything less risks the previous market's prices sitting under the new copy — so nothing about how it
feels changes.

It redirects to the market **home**, not to the equivalent of the current page. Product identity is
market-scoped, so the same path under another market is usually a 404; `Alternates` resolves genuine
equivalents, but only for pages that have one and only for crawlers.

### Re-validated on read

`MarketPreference::stored()` re-checks `isPublished()` every time. The cookie outlives deploys by a
year, so it can name a market that has since been withdrawn — honouring that would pin a visitor to a
catalogue with no supply, the one outcome `isPublished()` exists to prevent. An unhonourable cookie
falls through to negotiation rather than erroring.

### One entry point

The root redirect, the legacy-URL 404 mapper and the guest/auth redirects in `bootstrap/app.php` all
ask `MarketPreference::resolve()`. A visitor who chose Belgium gets Belgium from those, not just from
the homepage.

**With one known gap: the legacy-URL 404 mapper cannot see the cookie.** A 404 is thrown by the
router when nothing matches, which is *before* the `web` group runs — so `EncryptCookies` has not
decrypted anything and `$request->cookie()` hands back the raw ciphertext. `Market::tryFrom()` fails
on it and `stored()` returns null, so that path silently negotiates from `Accept-Language`.

Left as is deliberately. The failure mode is "behaves the way it did before the cookie existed" on
inbound v1 WordPress links only, and closing it means hand-decrypting the cookie against
`CookieValuePrefix` outside the middleware that owns that job — more moving parts, and version-bound
to internals, than the bug is worth. If it ever does matter, the cheaper fix is to promote
`EncryptCookies` to global middleware rather than to decrypt by hand here.

## Published markets

A market is a promise that there is somewhere to buy. `es` cannot keep it: `bc:awin-feeds` reports
*"no Awin coverage for this market"* for Spain, and bol does not operate there either
(`Market::bolCountry()` is null). It has **no supply at all**, not a thin catalogue — so it ships
hidden.

`Market::isPublished()` decides, and `Market::published()` is what public-facing code iterates.

**Hidden means unadvertised, not removed.** `/es/` still routes and still returns 200. The page templates,
guides and Cove plans can all be built before the market opens, and opening it is flipping one arm of
one `match`. Admin and console keep using `Market::cases()` for exactly that reason — an editor has
to be able to work on the market that has not opened yet.

What an unpublished market is kept out of:

| Surface | Why |
|---|---|
| The switcher (`HandleInertiaRequests`) | Left out rather than greyed out — a disabled country reads as a fault, and there is nothing the visitor can do about it |
| `sitemap.xml` | A market sitemap that resolves to an empty catalogue spends crawl budget proving there is nothing there |
| `hreflang` (`Alternates`) | Declaring it tells a crawler there is a Spanish equivalent worth indexing, which is the opposite of hiding it |
| `Accept-Language` negotiation | An empty catalogue is a worse answer than the default, which at least has products |
| `robots.txt` | It still routes and nothing links to it, but a URL remembered from elsewhere would still be crawled |

The `Route::pattern` constraint deliberately still accepts it: hiding a market must not turn its URLs
into 404s, or reopening it becomes a migration instead of a config change.

## What is not scoped to a market

Wish lists. A product, a price, an offer and a search result are statements about one country's
shops; a list is a statement about a person, and somebody who switches market twice in an afternoon
has not started a second collection. `wishlists.market` is provenance — which market it was made in,
which fixes the language of a default title — and never a filter.

The consequence that touches routing: a list holds items from several markets at once, so an item's
link carries **the product's** market, not the reader's, and following one switches market. That is
correct rather than a leak — `SetMarket` reads the prefix and only the switcher writes `bc_market`,
so the visitor's home market is unchanged. See
[wishlists.md](wishlists.md#a-list-belongs-to-a-person-not-to-a-market).

## Anonymous identity

`TrackAnonymousIdentity` runs alongside, giving every visitor a durable encrypted cookie id. The gift
wizard and wishlist tray have to be useful *before* signup — demanding a login before showing results
is how you lose the visit. Everything built under that id is merged into the account at signup.

`last_seen_at` is updated at most once a day: this middleware runs on every request and a write per
page view would be a needless load on the primary.

## Files

- `app/Enums/Market.php` — including `isPublished()` / `published()`
- `app/Support/MarketPreference.php` — the stored choice, and the one place that resolves a market
  for a request whose URL does not carry one
- `app/Http/Controllers/MarketPreferenceController.php`
- `resources/js/Components/MarketSwitcher.tsx`
- `app/Http/Middleware/SetMarket.php`
- `app/Services/Seo/Alternates.php` — hreflang, published only
- `app/Http/Controllers/SitemapController.php` — sitemap index and `robots.txt`
- `app/Http/Middleware/TrackAnonymousIdentity.php`
- `app/Support/CurrentMarket.php`
- `routes/web.php`
- `resources/views/app.blade.php`

## Verification

```bash
curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' --max-redirs 0 \
  -H 'Accept-Language: nl-BE,nl;q=0.9' http://localhost:8000/
# 302 http://localhost:8000/be-nl

# The guess a Belgian browser set to plain "Nederlands" actually produces —
# the reason the cookie exists.
curl -s -o /dev/null -w '%{redirect_url}\n' --max-redirs 0 \
  -H 'Accept-Language: nl-NL,nl;q=0.9' http://localhost:8000/
# http://localhost:8000/nl-nl

# /market is CSRF-protected, so curl alone gets a 419 — that is the control
# working, not a fault.
curl -s -o /dev/null -w '%{http_code}\n' -X POST -d 'market=be-nl' http://localhost:8000/market
# 419

# The override is easiest to check in a browser: switch to Belgium, then open
# the bare domain in a new tab with the browser language still set to Dutch
# (Netherlands). It must stay on /be-nl. The suite covers it headlessly —
# tests/Feature/MarketRoutingTest.php::a_chosen_market_beats_the_browser_language

# Not cacheable: it varies per visitor.
curl -s -D - -o /dev/null http://localhost:8000/ | grep -i cache-control
# Cache-Control: no-store, private

curl -s -D - -o /dev/null http://localhost:8000/be-fr | grep -i content-language
# Content-Language: fr-BE

curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8000/nope
# 404

curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8000/es
# 200 — hidden, but still routes

curl -s http://localhost:8000/sitemap.xml | grep -c '/sitemap/es/'
# 0

curl -s http://localhost:8000/robots.txt | grep 'Disallow: /es/'
# Disallow: /es/   (when ROBOTS_ALLOW=true)
```

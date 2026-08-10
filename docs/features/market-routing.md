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

`/` guesses from `Accept-Language` via `Market::fromAcceptLanguage()` and issues a **302, never a
301**. The guess is based on a request header; caching it into permanence would pin a visitor to a
market they never chose.

Matching is deliberately conservative — exact BCP 47 tag first (`nl-BE` → `be-nl`), then
language-only (`fr` → `be-fr`), then the default. A wrong guess shows the wrong currency and the
wrong merchants, so anything unrecognised falls back rather than being approximated.

Negotiation only ever selects a **published** market. A Spanish `Accept-Language` resolves to the
default, not to `es`.

## Published markets

A market is a promise that there is somewhere to buy. `es` cannot keep it: `bc:awin-feeds` reports
*"no Awin coverage for this market"* for Spain, and bol does not operate there either
(`Market::bolCountry()` is null). It has **no supply at all**, not a thin catalogue — so it ships
hidden.

`Market::isPublished()` decides, and `Market::published()` is what public-facing code iterates.

**Hidden means unadvertised, not removed.** `/es/` still routes and still returns 200. The copy bank,
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

## Anonymous identity

`TrackAnonymousIdentity` runs alongside, giving every visitor a durable encrypted cookie id. The gift
wizard and wishlist tray have to be useful *before* signup — demanding a login before showing results
is how you lose the visit. Everything built under that id is merged into the account at signup.

`last_seen_at` is updated at most once a day: this middleware runs on every request and a write per
page view would be a needless load on the primary.

## Files

- `app/Enums/Market.php` — including `isPublished()` / `published()`
- `app/Http/Middleware/SetMarket.php`
- `app/Services/Seo/Alternates.php` — hreflang, published only
- `app/Http/Controllers/SitemapController.php` — sitemap index and `robots.txt`
- `app/Http/Middleware/TrackAnonymousIdentity.php`
- `app/Support/CurrentMarket.php`
- `routes/web.php`
- `resources/views/app.blade.php`

## Verification

```bash
curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' --max-redirs 0 http://localhost:8000/
# 302 http://localhost:8000/be-nl   (with a Dutch Accept-Language)

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

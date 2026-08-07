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

## Anonymous identity

`TrackAnonymousIdentity` runs alongside, giving every visitor a durable encrypted cookie id. The gift
wizard and wishlist tray have to be useful *before* signup — demanding a login before showing results
is how you lose the visit. Everything built under that id is merged into the account at signup.

`last_seen_at` is updated at most once a day: this middleware runs on every request and a write per
page view would be a needless load on the primary.

## Files

- `app/Enums/Market.php`
- `app/Http/Middleware/SetMarket.php`
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
```

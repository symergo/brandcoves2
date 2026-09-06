---
name: The page behind a dead address
area: Core / Frontend
status: Active
date_added: 2026-09-06
---

# Not found

**Every address that does not exist answers with a real page: a search box, and six places worth
going.**

Laravel's stock 404 is a grey box with a number in it. The person reading it wanted something
specific and did not get it, so the box tells them the site is broken. It is the one page guaranteed
to be met by somebody with intent, and it was the only page nobody had written.

## What prompted it

61 buying guides were retired on 2026-09-06 because they had no article in them — see
[cove-planner.md](cove-planner.md), *When the fold did nothing*. Their addresses were published for
weeks, so they will be followed for months, and until now every one of those visits ended at the grey
box.

## Three ways in, one page

They take different paths through the framework, which is why all three are covered by a test.

| Arrival | Path | Example |
|---|---|---|
| A route matched, the controller gave up | exception handler in `bootstrap/app.php` | `/be-nl/guides/a-retired-guide` |
| No route matched, under a real market | `Route::fallback()` inside the `{market}` group | `/be-nl/anything` |
| No route matched, no market either | the global `Route::fallback()` | `/wp-content/…` |

**The fallback routes are not decoration.** An unmatched URL never reaches the web middleware, so
`CurrentMarket` is unbound and the shared Inertia props the layout reads do not exist — rendering the
page straight from the exception handler would fail while trying to explain a failure. Routing the
request instead means it arrives with a market, a language, and the same header and footer as every
other page.

The market-prefixed fallback exists so a dead address **keeps its market**. The global one cannot
know it — there is no segment for `SetMarket` to read — so it falls back to `Market::default()`, and
sending a French visitor to a Dutch page is worth one extra route to avoid.

There is one hole, deliberately left: a **POST** to an unknown URL. Laravel's fallback answers GET,
so that raises `NotFoundHttpException` with no middleware behind it, and the handler returns the
framework's page rather than a broken copy of ours. It is not a page a person meets.

## The v1 redirect check had to move with it

This is the regression the change could most easily have caused, and it has its own test.

v1 was a WordPress site with thousands of indexed paths, and `LegacyRedirects` maps the ones worth
keeping. That check lived in the exception handler because an unmatched URL was the only thing that
reached it. A fallback route catches those *first* — so without moving the check, every indexed v1
address would quietly have started answering "not found" instead of redirecting, with nothing to
report it. Both entry points now ask, so neither can lose it.

## Decisions in the page itself

- **404, never 200.** A helpful page served as 200 is a soft 404: crawlers index it, and every dead
  address on the site becomes a duplicate competing with the real pages. `noindex, follow` — *follow*
  because the links are the point, and a crawler that reads them finds pages that do exist.
- **The search box is first.** It is the only control on the page that can serve the request the
  visitor actually made. Somebody who followed a dead headphones link can type "koptelefoon" and be
  one click from what they came for; no arrangement of navigation links does that.
- **No apology and no error number.** "404" means nothing to most visitors, and a long apology
  implies breakage where an edited site simply has old addresses.
- **It queries nothing.** A 404 is what crawlers and vulnerability scanners hit most, and it is
  served on the day something is already wrong. Every link on it is a route the market definitely
  has. A "popular right now" rail would be a database query per bogus URL — a cost that scales with
  exactly the traffic worth spending nothing on.
- **Each destination carries a line saying what it is.** A grid of bare nouns makes the visitor guess
  a second time, and they have already guessed wrong once.

## Files

- `app/Http/Controllers/NotFoundController.php` — both entry points, and the v1 check
- `resources/js/Pages/Errors/NotFound.tsx`
- `routes/web.php` — the two `Route::fallback()` entries
- `bootstrap/app.php` — the `NotFoundHttpException` handler
- `lang/{en,nl,fr,es}/site.php` — `not_found.*`, all four languages
- `tests/Feature/NotFoundPageTest.php`

## See also

- [cutover.md](cutover.md) — the v1 redirect map this sits in front of
- [market-routing.md](market-routing.md) — why the market has to survive a 404
- [cove-planner.md](cove-planner.md) — the 61 retired addresses that prompted the page

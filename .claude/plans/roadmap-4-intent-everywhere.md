# Roadmap step 4: intent everywhere

Strategy: [docs/strategy.md](../../docs/strategy.md), engine E. Written 2026-09-26.

## Goal

"Gift → Dad → 60 → cooking → €50–€100" is one structured object that drives search filters, gift
landing pages and Cove product selection, instead of each of those having its own idea of what a
visitor wants.

## Where we start

- The object exists: `App\Services\Gift\TasteBrief` (market, interests, vibe, preferences, budget
  min/max in cents, avoid, values, relationship, occasion, age band, exclusions, limit, profile,
  query). Built from a recipient (`fromRecipient`) or from the wizard's input
  (`GiftController::brief()`, l.385-418). It has no serialisation.
- `SuggestionEngine::suggest(TasteBrief)` is fast (under 100 ms), makes no AI call and needs no
  user: it can run in a web request.
- The vocabulary is `App\Services\Gift\GiftTags` (interests, events, recipient types, vibes,
  preferences), stored in `product_groups.gift_tags` with a GIN index.
- Search (`SearchQuery`) filters on price, merchant, brand, stock, discount; nothing about who or
  why. Guides and personas choose products from search terms only (`cove_plans.queries`,
  `LadderSelector`, `SurpriseSelector`). The Gift Whisperer's results are POST-only: not linkable,
  not indexable. No page like "gift ideas for dad" exists.

## Design

### 1. The brief becomes a value you can store and link

- `TasteBrief::toArray()` / `fromArray()` (validated against `GiftTags`, unknown values dropped).
- `App\Services\Gift\BriefUrl`: a brief ↔ a readable path for the fields that make sense in a URL
  (recipient, interest, occasion, budget band), the rest as query parameters. Words come from the
  lang files, per market, the way `SearchUrl` does it.

### 2. Gift landing pages

`/{market}/{gift-ideas word}/for/{recipient}` and `…/for/{recipient}/{interest}`, optionally
`?budget=50-100`. Rendered by `SuggestionEngine` from the brief in the URL, cached per market and
path for a day.

- **Only combinations that are worth a page exist.** A nightly `PlanGiftLandingPages` job walks
  recipient × interest (and recipient × occasion), runs the engine, and records the combinations
  that return at least eight distinct products (the owner's minimum for a Cove) in
  `gift_landings (market, path, brief jsonb, product_count, checked_at)`. Anything else answers
  404; that keeps thousands of thin pages out of the index.
- **Title and heading** lead with the searched phrase: "Gift ideas for dad who loves cooking". A
  short intro sentence is built from the brief by template, no AI in the request. A landing page
  can later receive an authored intro over the editorial API (same rule as Coves: written in the
  session, sent authored).
- Sitemap entries for recorded combinations; hreflang only between markets that both have the
  combination.
- The Whisperer gains "Open as a page" on its results: a GET link to the matching landing page, or
  to a noindex `/gift/for?…` result page when the brief is too specific for a landing.

### 3. Search filters

`SearchQuery` gains `for` (recipient type), `interest`, `occasion`, validated against `GiftTags`.
They filter with `jsonb_exists_any(product_groups.gift_tags, …)` in `SearchService`'s group query.
Filtered search pages keep canonicalising to the bare term (`search-urls.md`), so the landing pages
own those phrases in search engines, not filtered search. The Search page shows them as chips.

### 4. Coves chosen by intent

`cove_plans.brief` (jsonb, nullable). When present, `EditionBuilder` fills open slots from
`SuggestionEngine` with that brief instead of `SurpriseSelector` over queries; curated items still
come first. The editorial API accepts `brief` on `POST /coves` and `PATCH /coves/{id}` (and
`POST /coves` must keep sending it whole: it resets what it is not sent). Personas are the first
users: "the keen cook" becomes a brief, not a list of search terms.

## Decide before building

1. The URL words per market for gift landing pages, and whether they sit under the existing
   `/gift-ideas/` (personas live at `/gift-ideas/{slug}`: a `for` segment avoids collisions).
2. Which combinations to open first. Recommended: recipient × interest only, then occasions.
3. Gift tags are written only by editors today, so coverage limits every page here. Is a tagging
   pass (AI, queued, capped) part of this step or its own?

## Files

- New: `app/Services/Gift/BriefUrl.php`, `GiftLandingController`, `app/Jobs/PlanGiftLandingPages.php`,
  `gift_landings` migration, `Pages/GiftIdeas/Landing.tsx`.
- Change: `TasteBrief` (serialisation), `SearchQuery` + `SearchService` (tag filters), `Search.tsx`
  (chips), `GiftController` + `Wizard.tsx` (open as a page), `EditionBuilder` + `CovePlan`
  (`brief`), `routes/api.php` editorial validation, `SitemapController`, `Alternates`,
  lang files (four languages).
- Docs: new `gift-landing-pages.md`; update `gift-whisperer.md`, `search.md`, `search-urls.md`,
  `gift-personas.md`, `editorial-api.md` and the seed-coves skill's `reference/api.md`, `INDEX.md`.

## Verification

- Unit: `TasteBriefTest` round trip, `BriefUrlTest` (every market, unknown values dropped).
- Feature: `GiftLandingTest` (a recorded combination renders with 8+ products, an unrecorded one
  404s, title within budget), `SearchTagFilterTest`, editorial API accepts and keeps `brief`,
  `EditionBuilder` with a brief never calls AI (`AiClient` fake asserts nothing sent).
- By hand: run `PlanGiftLandingPages` on dev data, open five landing pages per market, check the
  sitemap lists exactly the recorded ones.

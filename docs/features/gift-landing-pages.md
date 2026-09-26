---
name: Gift landing pages
area: Gifting / SEO
status: Active — recorded nightly (05:40) per published market; recipient × interest only
date_added: 2026-09-26
---

# Gift landing pages: "gift ideas for dad who loves cooking"

Roadmap step 4, parts 1 and 2 ([../strategy.md](../strategy.md), engine E and "Naming and SEO").
A page for each pair of *who it is for* and *what they love* that the catalogue can actually fill:

> `/be-nl/gift-ideas/for/papa/koken`: **Cadeau-ideeën voor papa die van koken houdt**
> `/en/gift-ideas/for/dad/cooking`: **Gift ideas for dad who loves cooking**
> `/be-fr/gift-ideas/for/papa/cuisine`: **Idées cadeaux pour papa qui aime la cuisine**

and one per person, gathering their best pairs: `/en/gift-ideas/for/dad`.

## The brief is the page

A gift brief (`TasteBrief`: who, interests, budget, occasion, age, taste) is the object the
suggestion engine answers. Two things make it something a page can be built from:

- **It can be stored.** `TasteBrief::toArray()` / `fromArray()`. Only what somebody decided is
  kept: no market (whatever holds the brief already has one, and two copies can disagree), no
  limit or exclusions (they belong to one run of the engine). `fromArray()` drops any value outside
  the gift vocabulary rather than keeping a word the engine would quietly match nothing with;
  `problems()` names them, for the editorial API, which refuses instead.
- **It has an address.** `App\Services\Gift\BriefUrl`. The path carries the two things a page is
  *about*, recipient and interest, in the market's own words (`papa`, `koken`); the budget is
  `?budget=50-100` in euros. Everything else (age, style, things to avoid) is the Gift Finder's
  job, not an address.

## Why these URLs

- **`/gift-ideas/for/` in every market.** The owner had not ruled on the words; this was chosen.
  The section word stays `gift-ideas` everywhere, like the persona shelf it sits under, because the
  strategy keeps URLs as they are and a localised section word means a second route and a redirect
  table for one word. What the searcher types, "papa", "koken", is in the path, and that is the
  part that reads.
- **The `for` segment keeps them clear of the personas.** A persona is `/gift-ideas/{slug}`, one
  segment; a landing page is two or three. No persona can be shadowed, and
  `GiftLandingTest::persona_addresses_are_untouched` pins it.
- **The words live in `lang/*/site.php`** (`gift_landing.recipients.*.slug`,
  `gift_landing.interests.*.slug`), so `LocalisationTest` checks all four languages have them.
  Treat them as permanent: changing one moves the page, and the planner drops the row at the old
  address the next night.
- **Another language's words redirect.** `/be-nl/gift-ideas/for/dad/cooking` is a 301 to
  `/be-nl/gift-ideas/for/papa/koken`: a hand-built link lands, and a search engine is never shown
  the same page under two names.

## Only pages worth having exist

`PlanGiftLandingPages` (nightly 05:40, after grouping and the list signals; by hand:
`php artisan bc:plan-gift-landings --market=be-nl`) walks every recipient × interest, asks the
suggestion engine for 24 products and counts the ones that **fit the interest** by the engine's own
verdict (`Suggestion::matchedInterests`). "The engine returned something" is not enough: with
nothing matching it falls back to a budget browse, so the Gift Finder never comes back empty, and
a page of that would be "for dad who loves fishing" showing a candle.

- **Eight or more** (`giftcoves.gift_landings.min_products`, the owner's minimum for a Cove) and the
  pair is recorded in `gift_landings`. Anything else answers 404, which keeps hundreds of thin
  combinations out of the index. A pair that drops under eight loses its row the next night.
- **A recipient's own page** exists when at least one of their pairs does. Its brief is their
  three best interests (most products first), and it links on to every pair.
- **`kids` is not walked** (`excluded_interests`): it says who a present is for, not something dad
  loves.
- **Recipient × occasion** is not walked yet (the owner's order: recipient × interest first).

### The known weakness, and the switch for it

Retrieval is by interest; the recipient only reorders (products tagged for that recipient, by an
editor or by people's lists, rank first). So "dad who loves cooking" and "mum who loves cooking"
share most of their products today. Recipient tags are still rare, which is why the rule does not
demand them. `gift_landings.min_recipient_matches` (0 now) makes a page require that many products
carrying the recipient's tag; raise it once tags are common and the pages will differ more. The
owner has not ruled on this; it is the first thing to look at if search engines treat the pages as
near-duplicates.

## What the page says

All from templates (`GiftLandingCopy`), never from a model: the page is a web request
(invariant 1) and "gift ideas for dad who loves cooking" is a sentence a template gets right.

- **The heading leads with the searched phrase** ([page-titles.md](page-titles.md)). Each
  recipient carries the words between it and the interest (`who`), which is how Dutch gets
  *die/dat* right ("je kind **dat** van gamen houdt") and Spanish its *amante de* with the article
  folded in ("amante **del** café").
- **The listing title** is the heading when it fits the 48 characters a result shows, then a
  shorter lead ("Gifts for…", "Cadeau voor…"), then the bare "Dad who loves cooking". Measured
  after the words are filled in; `BriefUrlTest` renders every pair in every language and fails
  above 48. Five long pairs forced shorter words ("le nautisme", "del agua", "de la nieve").
- **One template intro**, no counts in it. The meta description carries the count.
- **Budget chips** (under 25, 25-50, 50-100, over 100) narrow the page with `?budget=`; the
  canonical stays the bare address, the rule filtered search follows ([search-urls.md](search-urls.md)).
- **Sideways links only to recorded pages**: the same person's other interests, the same interest
  for other people, and the person's own page. The gift ideas shelf (`/gift-ideas`) links every
  person's page, so a visitor and a crawler find them without the sitemap.

Products are cached a day per page and budget, keyed on the night's `checked_at`, so the engine
runs once per page per day, not once per visitor.

## Search engines

- **Sitemap**: exactly the recorded pages, from the first market file (`SitemapController`). The
  planner clears that file's cache when it runs.
- **hreflang only between markets that both have the pair**, paired on (recipient, interest)
  rather than the path, since each market words the path its own way
  (`GiftLandingLinks::alternates()`; `Alternates::persona()` knows the `for` segment too). An
  alternate to a missing page makes a search engine discard the whole cluster.
- **Breadcrumbs** in JSON-LD: GiftCoves › Gift ideas › (the person's page) › this page.

## The Gift Finder's "Open as a page"

The Finder's results are a POST: nobody can bookmark, share or find them again. Under the results
a link opens the landing page nearest the brief (`GiftLandingLinks::pageFor()`): the first of its
interests with a page for that person, else the person's own page, with the budget carried over.
A saved person's relationship is free text ("mama"), so it is read with the search box's word
lists. No page, no button: a link to a page answering a different question would be worse than
none. The plan's noindex `/gift/for?…` page for briefs too specific for a landing was not built.

## Not yet

- An authored intro per page over the editorial API (same rule as Coves: written in the session,
  sent authored).
- Recipient × occasion pages ("for mum, Mother's Day").
- The noindex result page for briefs no landing covers.

## Files

- `app/Services/Gift/{TasteBrief,BriefUrl,GiftLandingCopy,GiftLandingLinks,GiftLandingPlanner}.php`
- `app/Models/GiftLanding.php`, `database/migrations/2026_09_26_000300_gift_landing_pages.php`
- `app/Jobs/PlanGiftLandingPages.php`, `app/Console/Commands/PlanGiftLandingsCommand.php`,
  `routes/console.php`
- `app/Http/Controllers/GiftLandingController.php`, `resources/js/Pages/GiftIdeas/Landing.tsx`,
  `routes/web.php` (`gift-ideas.landing`)
- `SitemapController`, `Alternates`, `GiftIdeasController` (the shelf), `GiftController` +
  `Pages/Gift/Wizard.tsx` (Open as a page)
- `config/giftcoves.php` (`gift_landings`), `lang/*/site.php` (`gift_landing`)
- Tests: `tests/Unit/TasteBriefTest.php`, `BriefUrlTest`, `GiftLandingTest`

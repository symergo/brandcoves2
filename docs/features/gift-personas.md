---
name: Gift personas
area: Discovery / Content
status: Active — 10 per market in be-nl, nl-nl, en
date_added: 2026-08-29
---

# Gift personas

**A Cove that is about a person rather than a day.** "The cottagecore herbalist", "the dad who has
everything", "the friend who reads on the train". Undated, permanent, and listed on
`/{market}/gift-ideas`.

## Why it is a Cove and not a new thing

Because a persona and a Daily Cove differ in exactly one respect — how the page is addressed — and in
nothing else. Same plan table, same curation screen, same builder, same finds, same editorial pass,
same picks with the same reactions, same markup, same presenter.

So `cove_plans.kind` is a column and `daily_pick_sets.kind` is a column, and there is no second
subsystem. A separate table would have duplicated a dozen columns to express a nullable date, and the
two builders would have drifted apart inside a month — the persona one always second, always missing
whatever the daily one learned last.

What differs, and why:

| | Daily Cove | Gift persona |
|---|---|---|
| Addressed by | its date | a permanent `slug` |
| Built by | the 06:00 scheduler | an editor pressing a button |
| Job | `BuildDailyEdition` | `BuildPersonaCove` |
| Claims a theme slot | yes (`used_themes`) | **no** |
| Enters the 90-day repeat memory | yes | **no** |
| Page furniture | deals column, subscribe box | neither — but the same rail and cards |

The last three are the same reasoning from three angles. A persona is not part of the daily column's
rhythm: it does not consume a theme the rotation would otherwise use for sixty days, its products do
not disappear from three months of editions, and it carries no subscribe box because there is
nothing to catch up on. What it does carry, since the rail was shared out across every Cove kind, is
the Gift Cove card, the category rail and the cards offering the other personas — see
[cove-rail.md](cove-rail.md).

`BuildPersonaCove` is a separate job rather than a flag on `BuildDailyEdition` for the same reason:
that job also mines guide topics and seeds the seasonal ones, both of which are about the *day*. An
editor pressing "build" should not also advance the guide queue — that is not what the button says.

## A persona is undated, and the database enforces it

`CHECK (kind <> 'persona' OR drop_date IS NULL)`.

Not a convention, because the failure is silent and public. `CovePlan::approvedFor()` matches on
`(market, drop_date)`, so a persona that quietly acquired a date would be picked up by the 06:00
build and published as that morning's Daily Cove — with nothing anywhere to say so until a reader saw
it.

The mirror constraint on the edition says the same thing from the other side: a `daily` row must have
a date and no slug; a `persona` row must have a slug and no date.

## The `NULLS FIRST` trap

**Postgres sorts `ORDER BY drop_date DESC` with NULLS FIRST.**

A persona has no drop date. So the moment the first one existed, every
`orderByDesc('drop_date')->first()` in the codebase would have returned *it* as today's edition:

- the front page's "today"
- `/daily` with no date
- the top of the recent-editions cards
- the sitemap, which would have emitted `/{market}/daily/` with an empty segment

None of those would have errored. Nothing would have looked broken. The wrong page would simply have
been served.

`DailyPickSet::scopeDaily()` exists for this and is applied at every listing site — the four above
plus the digest job, the OG image endpoint, the editorial API's edition read, the discover hub and
the content envelope. It is an **explicit** scope rather than a global one, because a global default
would also hide personas from the gift-ideas pages, which would then need `withoutGlobalScope` — an
inversion that reads as a mistake and gets copied as a pattern.

`GiftPersonaTest` asserts each surface separately rather than trusting the scope, because the failure
is silent everywhere it can happen.

The date uniqueness also had to become **partial**: a plain `unique (market, drop_date)` permits
exactly one NULL per market in Postgres, so the second persona in a market would have failed to
insert — at 06:00, with a constraint violation and no other symptom.

## The pages

- `GET /{market}/gift-ideas` — the shelf. A plain grid, ordered by first publication. Deliberately
  not a feed: these do not arrive in an order that matters and none is more current than another. A
  persona written in March is exactly as useful in November, which is the whole reason it has no date
  on it.
- `GET /{market}/gift-ideas/{slug}` — one persona.

**Not `/coves/{slug}`.** `/coves/subscribe`, `/coves/confirm/{token}` and `/coves/unsubscribe/{token}`
already live under that prefix, and a slug catch-all beside them would shadow all three the first
time somebody named a persona "subscribe".

`published_at` is stamped **once**, at first build, and a rebuild never refreshes it. A rebuild
refreshes the products and the prose; it does not republish the page. Stamping `now()` on every
rebuild would make a two-month-old persona look new to a crawler every time its products were
refreshed, which is the fastest way to teach one to stop believing the date.

Reactions (🤯 / 😐) are absent from a persona page. On a Daily they are a signal about a find on the
day it appeared; on a page that stands for a year they would accumulate into a rating nobody meant to
give.

## Writing one

1. **Admin → Content → Cove calendar → Create**, kind = *Gift persona*. A slug is required and is
   suggested from the title — but never rewritten from it afterwards, because the address is what has
   been linked and indexed.
2. **Curate** its products. See [cove-curation.md](cove-curation.md). Most personas want `locked`:
   the whole point is a hand-built shelf.
3. **Approve**, then **Build now**. Idempotent — rebuilding updates in place.

Or the same three steps through [the editorial API](editorial-api.md), with `kind: "persona"` and a
`slug`.

## The picture is drawn, and the persona names it

Added 2026-08-30. `cove_plans.scene` and `daily_pick_sets.scene`, nullable, string plus a CHECK,
cast to `App\Enums\CoveScene` on both models.

**The cover used to be a photograph of a product** — the first buyable find on the shelf. Wrong
picture twice over. It made a shelf of *people* look like a shelf of product categories, and the
cover moved whenever stock did: the same persona wearing a different face from one week to the next,
for a reason no reader could see and no editor chose.

`SceneIllustration` draws it instead, in the same language as `CoveIllustration` and
`ListIllustration` — one `160x116` viewBox, one stroke weight, `currentColor` for every line, the
accent only as a translucent wash. That is what lets the card change colour on hover and take the
drawing with it, and it is why these survive a palette change without being redrawn.

**A handful of scenes, not one per persona.** A drawing per persona makes artwork a gate on the
writing. These are *kinds of person* — coffee, cooking, sim racing, has-everything, dogs,
photography, DIY, outdoors — so a new persona almost always finds one that fits, and the ones that
do not get `someone`, a featureless figure. Null means `someone`: every persona written before the
field existed has no scene, and a missing drawing must not be a missing page.

> **Nine became seventeen on 2026-09-05, and the enum stopped being about personas.** Filling the
> shelves out to ten per market put six kinds of person past the original nine — gardeners, plant
> owners, readers, listeners, gamers, travellers — and every one of them would have fallen back to
> `someone`. Six identical portraits on one shelf is a shelf that looks unfinished. At the same time
> `/guides` needed drawings of its own, so `PersonaScene` became `App\Enums\CoveScene`: one column,
> one cast, one component, and `CoveScene::forKind()` deciding which half of the vocabulary a kind
> may name. See [cove-scenes.md](cove-scenes.md) — including why the API now **refuses** a scene the
> kind cannot mean rather than storing it.

**A field, not a lookup keyed on the slug.** Slugs are per market. `de-koffiefanaat` and
`le-fanatique-de-cafe` are one persona wearing two addresses, so a table keyed on the slug would be
correct in the markets somebody remembered and blank in the rest, with nothing to say which was
which. A field also puts the choice where the writing happens — the person deciding this Cove is
about someone who cooks is already in the planner.

**Both tables, because the plan is the source and the edition is the page.** `EditionBuilder::
buildPersona()` copies it across with the title and the blurb. On the plan alone it would be a field
you could set and never see; on the edition alone a rebuild would overwrite it.

Set it in **Admin → Cove planner → Drawing** (the options follow the kind, and the field is hidden
on a kind with no vocabulary — a Daily, a Shop Cove), or send `scene` to
`POST /api/editorial/coves`.

> **Three of the nine were redrawn before they shipped**, and eight of the nineteen added later.
> The first `coffee` was a grinder and read at card size as a phone with a cup beside it; `cooking`
> was a pan from above and read as an artist's palette; `racing`'s pedals were a small detached
> parallelogram that read as a stray shape. Later: `baking` read as a plant in a bowl, `fitness` as
> two dumbbells, `reading` as a stack of boxes. All of them were found by rendering the shelf and
> looking at it, which is the only way this class of mistake is ever found — an SVG that is
> geometrically fine and semantically wrong throws no error. The full list is in
> [cove-scenes.md](cove-scenes.md).

> **A scene on the plan is not a scene on the page.** `EditionBuilder::buildPersona()` copies it
> across at build time, so a persona that is already **published** keeps whatever drawing its
> edition was built with until it is rebuilt. Setting the field on four live `be-nl` personas
> therefore changed nothing a reader could see; the rebuild is a separate, deliberate act, and on a
> `pick_mode = open` persona it also re-runs the ranker and may change which products are on the
> shelf. That is a bigger change than "add an icon", which is why the two are not done together.

## hreflang: paired on the slug, and only when the twin exists

Two markets carrying the same persona slug are carrying the same persona, so
`/be-nl/gift-ideas/de-thuiskok` and its `nl-nl` twin are paired. The prose behind
them differs per market — that is what a translation is.

**They are not paired by swapping the market segment**, which is what happened
until 2026-08-29. `gift-ideas` was missing from the `match` in
`Alternates::for()`, so a persona fell through to `swap()` and declared an
alternate in all five markets without checking any existed. Personas are written
per market and the sets deliberately differ — `de-hondenmens` is a `be-nl` page
because `nl-nl` has a sixth of the dog catalogue, and `de-klusser` is its `nl-nl`
counterpart — so four of the five claims were 404s on those two, and Google
discards an entire hreflang cluster that contains one bad member.

It stayed invisible because the shelf was empty in every market until the first
personas were seeded. `GiftPersonaTest` now pins all four cases: a real pair, a
persona only one market carries, an unpublished twin, and the shelf itself
(which *is* the same page everywhere and correctly still swaps).

The persona set need not match across markets — the pairing handles a partial
overlap. Where the same persona does exist in two markets, keep the slug
identical or the two stop being twins.

## Files

- `app/Enums/CoveKind.php`, `app/Enums/CoveScene.php`, `app/Jobs/BuildPersonaCove.php`
- `resources/js/Components/SceneIllustration.tsx` — every drawing; see [cove-scenes.md](cove-scenes.md)
- `database/migrations/2026_08_31_000200_a_persona_names_its_own_drawing.php`
- `app/Services/Cove/EditionBuilder.php` — `buildPersona()`
- `app/Services/Cove/EditionPresenter.php` — shared with the Daily Cove
- `app/Http/Controllers/GiftIdeasController.php`
- `app/Services/Seo/Alternates.php` — `persona()`, and the `gift-ideas` case
- `resources/js/Pages/GiftIdeas/Index.tsx`, `Persona.tsx`
- `database/migrations/2026_08_29_000300_an_edition_need_not_have_a_date.php`
- `tests/Feature/GiftPersonaTest.php`

## Open

- **The footer still does not link to `/gift-ideas`.** The header menu and the Discover hub both do
  as of 2026-08-30 — the hub carries a card for the shelf and a band naming up to six personas, on
  the argument that a reader recognises the person they are shopping for in a title and cannot
  recognise anything in the phrase "presents chosen around a person". Both the card and the band
  appear only once a market has published one: every other surface on that hub always has something
  on it, and an unconditional card would make the hub the site's only link to a page reading
  "nothing here yet". See [navigation.md](navigation.md).
- **No OG image endpoint for a persona.** `/og/daily/{date}.png` is dated by construction. A persona
  shares as its title and blurb until one is added.
- **A persona's picks do not reach the discovery `curated` pool.** `CuratedRetriever::pool()` bounds
  the daily picks with `drop_date >= now() - 30 days`, and a null date fails that comparison. The
  window is about freshness, which a persona has by construction — see
  [discovery-modes.md](discovery-modes.md) for why the fix is a kind check rather than a wider
  window, and why it was left for its own change.
- **The sitemap lists personas without alternates.** `SitemapController` emits `loc`, `priority` and
  `changefreq` for each one and no `alternates` key, so the hreflang pairing above reaches the head
  and not the sitemap — half of the "two independent signals" [seo.md](seo.md) describes. The naive
  fix is a `persona()` call per URL, which is exactly the two-queries-per-URL shape that once took
  the product sitemap past the proxy's thirty-second timeout; it needs a batched lookup like
  `Alternates::forProducts()`.
- **`es` has no personas, and `be-fr` still has six.** `be-nl`, `nl-nl` and `en` were filled out to
  **ten planned personas each** on 2026-09-05. Nothing is market-specific about the mechanism; a
  persona is written per market like everything else, and the sets deliberately differ where the
  catalogue does — see the hreflang section for why that is safe.
- **Only one of the thirty is published.** The set is ten *plans* per market, and a plan is an
  intention. Four `be-nl` personas are live from an earlier pass; the rest are drafts awaiting
  approval and a build in the planner, which is the intended shape — the editorial API writes
  drafts and a person publishes.
- **`de-hondenmens` cannot be rewritten until somebody re-curates it.** Its locked shortlist holds
  `8390126`, a bol feeding bowl that has since gone out of stock and lost its price, and
  `POST /coves` refuses a write containing an unusable id — rightly, and whole, because an article
  whose second pick silently vanished has a dangling sentence. It is the one persona that did not
  get its scene on 2026-09-05. Drop or replace that item in the planner and the write goes through.

## The set, per market

Ten each, and the scenes are what the sets are organised around — no market repeats one.

| | `be-nl` | `nl-nl` | `en` |
|---|---|---|---|
| coffee | `de-koffiefanaat` | `de-koffiefanaat` | `coffee` |
| cooking | `de-thuiskok` | `de-thuiskok` | `cooking` |
| photography | `de-fotograaf` | `de-fotograaf` | `photography` |
| racing | `de-simracer` | `de-simracer` | — |
| has_everything | `wie-alles-al-heeft` | `wie-alles-al-heeft` | `has-everything` |
| dog | `de-hondenmens` | — | — |
| gardening | `de-tuinier` | — | — |
| plants | `de-plantenouder` | — | — |
| diy | — | `de-klusser` | — |
| reading | `de-lezer` | `de-lezer` | `reading` |
| music | `de-muziekliefhebber` | `de-muziekliefhebber` | `music` |
| baking | — | `de-bakker` | — |
| outdoors | — | `de-wandelaar` | `outdoors` |
| gaming | — | — | `gaming` |
| travel | — | — | `travel` |
| fitness | — | — | `fitness` |

The Dutch slugs are shared across `be-nl` and `nl-nl` wherever both carry the persona, which is what
makes them hreflang twins. `de-hondenmens` is still `be-nl` only for the documented reason — `nl-nl`
has a fraction of the dog catalogue — and `de-klusser` is still its `nl-nl` counterpart.

**The `en` slugs are bare nouns** (`coffee`, `has-everything`) where the titles are not
("The coffee obsessive"). They arrived that way from `POST /coves/drafts` and were kept: the plans
already existed at those addresses, and minting better ones would have left six unreachable orphan
drafts in the planner to buy a prettier URL on a page nothing has linked yet. A slug is suggested
from a title and never rewritten from it, so the mismatch is the normal state and not a defect.

**Every one is `pick_mode = open`**, matching the fourteen written before them. The prose links with
`[[search:…]]` rather than `[[product:N]]` and the builder fills the shelf from `queries`. That is
what lets a persona survive a feed changing under it, which a locked list of ids does not — and it
is why `de-hondenmens`, the one locked shelf among them, is also the one that broke.

**`en` themes were chosen against measured supply, not guessed.** Probing the catalogue first killed
three candidates outright: `baking` returned nothing at all in `en` (`stand mixer` 0, `baking tin` 0,
`rolling pin` 0), and `cooking` and `reading` only became viable on broader words — `pan`, `kitchen`,
`oven`, `book`, `lamp`, `notebook` — where the specific ones (`cutting board`, `bookends`,
`ereader case`) returned zero. Writing a persona a market cannot fill is writing a page that will
never clear `CoveKind::minimumItems()` and so will never publish, and nothing would have said so.

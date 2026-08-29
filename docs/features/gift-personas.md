---
name: Gift personas
area: Discovery / Content
status: Active
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
| Page furniture | archive strip, deals column, subscribe box | none of it |

The last three are the same reasoning from three angles. A persona is not part of the daily column's
rhythm: it does not consume a theme the rotation would otherwise use for sixty days, its products do
not disappear from three months of editions, and it does not carry an archive because there is
nothing to catch up on.

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
- the top of the archive strip
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

## Files

- `app/Enums/CoveKind.php`, `app/Jobs/BuildPersonaCove.php`
- `app/Services/Cove/EditionBuilder.php` — `buildPersona()`
- `app/Services/Cove/EditionPresenter.php` — shared with the Daily Cove
- `app/Http/Controllers/GiftIdeasController.php`
- `resources/js/Pages/GiftIdeas/Index.tsx`, `Persona.tsx`
- `database/migrations/2026_08_29_000300_an_edition_need_not_have_a_date.php`
- `tests/Feature/GiftPersonaTest.php`

## Open

- **Nothing links to `/gift-ideas` from the navigation yet.** The pages exist, are in the sitemap and
  are reachable; the nav entry is a separate decision about what the site's top level says.
- **No OG image endpoint for a persona.** `/og/daily/{date}.png` is dated by construction. A persona
  shares as its title and blurb until one is added.
- **A persona's picks do not reach the discovery `curated` pool.** `CuratedRetriever::pool()` bounds
  the daily picks with `drop_date >= now() - 30 days`, and a null date fails that comparison. The
  window is about freshness, which a persona has by construction — see
  [discovery-modes.md](discovery-modes.md) for why the fix is a kind check rather than a wider
  window, and why it was left for its own change.
- **`fr` and `es` have the copy but no personas.** Nothing is market-specific about the mechanism; a
  persona is written per market like everything else.

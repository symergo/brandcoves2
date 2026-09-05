---
name: The Cove calendar
area: Content / Operations
status: Active
date_added: 2026-09-05
---

# The Cove calendar

**The editorial year, drawn as a year, and the same every year.**

Everything the site knew about what was coming lived in three config files and appeared on no screen.
`config/observances.php` holds ninety-five named days and five moving ones, `config/cove_seasons.php`
two dozen season windows, `config/cove_themes.php` the sixty-four evergreen themes that fill the rest
of the dates. The only screen that showed a date at all was the [Cove planner](cove-planner.md),
which lists plans that already exist — so it answers "what are we publishing" and cannot answer "what
is coming, and have we done anything about it". The second question is the one somebody sits down
with in September to think about Christmas, and answering it meant reading PHP.

**Content → Cove calendar** draws the whole year for one market: season windows month by month, named
days marked on their dates, and against each of them what is planned.

```
Cove calendar        be-nl      2027 · 2028 · [2029]

  6 of 24 season(s) planned, across 14 dated part(s).
  31 of 95 named day(s) have a Daily Cove drafted.

  MARCH 2027
    Seasons running
      tuinieren   20 Feb – 30 Jun          38 products available
      kamperen    15 Mar – 15 Aug   opens  nothing planned
        [ Lay this season out ]
      barbecue    15 Mar – 31 Jul   opens
        Barbecue, deel 1   due 16 Mar 2027   approved   refresh due
        Barbecue, deel 2   due 30 Apr 2027   approved   live
        [ Bring it round for this window ]
    Named days
      08 Mar   Internationale Vrouwendag    draft
      20 Mar   Wereldgeluksdag              [ Draft it ]
```

## The year is drawn from the calendar, not from the database

`YearCalendar` assembles the year from the config and hangs the planning state off it. A fresh
environment that has never run `bc:refresh-discovery` shows the complete year on the first page load;
the `guide_topics` and `cove_plans` rows are joined where they exist, and their absence is a state —
"nothing planned" — rather than a gap.

That is also what makes it **recurring rather than a report on this year**. Every entry is `MM-DD`,
the moving observances are computed per year, and the switcher offers last year through the year
after next. 2029 is already fully drawn; the planner fills it in as its 120-day horizon reaches it.

Three smaller decisions:

- **A season appears in every month it runs through, marked on the one it opens in.** Listing it only
  at its start would hide that half of August is three overlapping windows, which is exactly what a
  year view is for. Repeating it with no start marked would be worse — every month would read as a
  deadline.
- **The evergreen rotation is not listed.** Every date without a named day still gets a Daily Cove,
  themed from `cove_themes.php`, and there are about two hundred and seventy of them. Listing those
  would bury the ninety-five that are actually occasions; a rotation theme claims nothing about its
  date and there is nothing to plan around. A closing note on the page says so, because leaving them
  out silently would read as "nothing happens in the gaps".
- **`availableProducts` is null, not zero, until the topic has been seeded.** "Nobody has counted" is
  a different claim from "there is nothing", and a fresh environment showing two dozen seasons all
  reporting no stock would send somebody looking for a broken importer.

## It writes, unlike the other read-only panels

[Market trends](popularity-charts.md) is deliberately read-only because its buttons would spend a
rate-limited API budget. Nothing here does: **Lay this season out** and **Draft it** read rows and
write rows, cost nothing, and produce **drafts** somebody still has to curate and approve. Being able
to act on the thing you are looking at is the whole difference between a calendar and a wall chart.

Nothing on this page publishes. That stays where it was: an editor approves a plan, and
`PublishDueCoves` honours it on the day.

- **Lay this season out** / **Bring it round for this window** is one button, because they are one
  editorial event a year apart. `SeasonalSeries::plan()` is the one place that knows which applies.
- **Draft it** on a named day calls `PlanDrafter::draftOn()`, which fills exactly the day that was
  clicked. `draft()` takes a number and walks forward filling whatever it finds — right for topping a
  queue up, wrong for somebody pointing at 14 February, because asking for four months of plans to
  get the one you meant is not a reasonable trade and neither is undoing the other hundred and
  nineteen.

## What already recurred, and what did not

Worth stating plainly, because the two halves of the calendar reached this from opposite ends.

**The named days always recurred.** `observances.php` is keyed `MM-DD` and `bc:plan-coves` runs
weekly walking 120 days ahead, so 31 October 2028 gets a Daily plan when the horizon reaches it.
`DailyPickSet::freeSlug()` was already written for it — "a theme recurs, so a collision is the normal
case rather than the exception" — and suffixes, leaving the first year's edition on the clean
address.

**The seasons did not.** `SeasonalTopics::seed()` writes one `guide_topics` row per topic for ever,
and `opening()` filtered on `plan_id IS NULL`. So `kamperen` was laid out one spring and never
offered again: the pages it produced were never refreshed, and a subject the catalogue could not fill
that year was never reconsidered. That filter is gone, `SeasonalSeries::plan()` decides between
laying out and renewing, and the recurrence is described in
[seasonal-series.md](seasonal-series.md#the-season-comes-round).

## Files

- `app/Services/Cove/YearCalendar.php` — the year, assembled
- `app/Filament/Pages/CoveCalendar.php` + `resources/views/filament/pages/cove-calendar.blade.php`
- `app/Services/Cove/PlanDrafter.php` — `draftOn()`, one day
- `app/Services/Cove/SeasonalSeries.php` — `plan()`, lay out or renew
- `config/observances.php`, `config/cove_seasons.php`, `config/cove_themes.php`
- `tests/Feature/CoveCalendarTest.php`

## Open

- **One market at a time.** Five markets share one calendar of dates and differ in which seasons
  apply and what has been planned, so "which market has not had its autumn looked at" is five page
  loads. A per-market column would be the fix and is a bigger screen than this one.
- **No way to skip a season for one year only.** Rejecting a topic is permanent across every year;
  there is no "not this spring". The workaround is to reject it and un-reject it, which nothing
  reminds you to do.

## See also

- [seasonal-series.md](seasonal-series.md) — how a season becomes dated parts, and how it recurs
- [cove-planner.md](cove-planner.md) — the rows this calendar drafts
- [daily-cove.md](daily-cove.md) — what a named day becomes

---
name: Seasonal Coves as a dated series
area: Content / Operations
status: Active
date_added: 2026-09-05
---

# Seasonal Coves as a dated series

**A season is laid out on the Cove planner as several dated parts, one per subject it names.**

Before this, a seasonal Cove was one page. `SeasonalTopics` seeded a window from
`config/cove_seasons.php`, `TopicPlanner` turned the topic into a single guide plan, and somebody
built it whenever the queue reached it. Two things were wrong with that, and neither is fixed by
writing the page better.

**A season is not one subject.** The calendar entry for `kamperen` names a tent, a sleeping bag, a
camping chair and a stove. One page covering four unrelated shelves is a listicle. Four pages, one
per shelf, are four buying guides that each answer a phrase somebody actually types — and the
subjects were already in the config, because they are what the shortlist is retrieved with.

**A season has a schedule, and the calendar could not show it.** The Cove planner is where editorial
is decided. It held Dailies and a pile of undated ideas. A season is the one non-Daily kind that is
*defined* by dates, and it was the one kind whose dates appeared nowhere: "the Halloween run starts
in three weeks" was knowable only by opening a config file.

So the seasonal kind now behaves like this:

```
config/cove_seasons.php   kamperen, 15 Mar – 15 Aug, [tent, slaapzak, campingstoel, kampeerkooktoestel]
        │  SeasonalTopics::seed()          one guide_topics row per market, translated
        ▼
   guide_topics            be-nl · kamperen · seasonal · 03-15 → 08-15
        │  SeasonalSeries::lay()           one part per subject, dated inside the window
        ▼
   cove_plans              kamperen deel 1  due 12 Apr   tent
                           kamperen deel 2  due 30 May   slaapzak
                           kamperen deel 3  due 18 Jul   campingstoel
        │  a person curates and approves each
        ▼
   PublishDueCoves         builds an approved part on the day it is due
        ▼
   /nl-nl/guides/beste-kamperen-deel-2      evergreen, slug-addressed, as before
```

## This is not the Daily's seasonal rotation

There are two seasonal mechanisms and they are not duplicates. `config/cove_themes.php` carries
windowed *day themes* — `pre_halloween`, `gift_season`, `new_year_reset` — and `ThemeRotation` gives
one of them to roughly one day in three while its window is open. That decides what a **Daily
edition** is about on a given morning: it is gone tomorrow and it claims nothing about the date.

`config/cove_seasons.php` carries the **evergreen pages** a season deserves, and this is what lays
them out. The two overlap on purpose in October: a Daily nods at Halloween while the seasonal guide
is the page that is still earning traffic next October. The distinction the copy has to keep is the
one `cove_seasons.php` states at the top — a Cove must never say "today", because it will be read in
February.

## The parts come from the subjects, not from a number

`SeasonalSeries::lay()` splits on `guide_topics.member_queries`, which is the calendar's own nouns
followed by whatever `TopicMiner` merged in — `SeasonalTopics` writes it in that order deliberately,
so taking the first few keeps the chosen subjects and drops the accidental ones. The cap is
`giftcoves.seasons.max_parts`, four, because that is what the calendar can actually supply: raising
it does not invent a fifth subject, it only promotes a merged search phrase to a page.

The subject becomes the part's `focus_keyphrase`, which is the first term `LadderSelector` retrieves
on. That is the whole mechanism: part three is about camping chairs rather than about camping again
because the keyphrase says so. Each part also excludes the products the earlier parts took, because
the subjects overlap in the catalogue and without it part two is part one under a different heading.

### Nothing is laid out that cannot publish

Every subject is probed against the catalogue **before any row is written**, on an unsaved
`CovePlan` — `LadderSelector` reads a market, a keyphrase and a query list off the model and nothing
else, so there is no reason to persist a row to find out whether it deserves to exist.

A subject that cannot reach `guides.min_products` is not made into a part. `buildArticle()` correctly
refuses a thin shortlist, so a doomed part is not a bad page: it is a plan an editor approves,
watches do nothing, and has no way to diagnose.

A season where **no** subject can be filled produces nothing at all, and the topic is left as a
`candidate` with no plan. Parked, not banned — a category that is thin in April may have an
advertiser in May, and marking it queued would mean never noticing.

### One part is not a series

A season that yields one subject produces exactly what it produced before: one plan, titled after the
topic, at `beste-kamperen`, with `series_key` and `part` both null. "Part 1" with no part two is a
promise to a reader that nothing keeps.

## The dates

Evenly at `from + k·span/n`, which puts the **last** part a full interval before the window closes
rather than on the day it does. That gap is the point of the whole seasonal feature: a Halloween page
published on 31 October has never been crawled, and the window opens in August precisely so the pages
inside it have time to be indexed.

Two adjustments, both falling out of one rule — a cursor that only moves forward:

- **Nothing is due in the past.** A season laid out mid-window has slots that have gone by. Those
  parts are late, not cancelled, so they queue from tomorrow.
- **No two parts of one series share a day.** Four pages published at once is not a series, it is one
  long page delivered awkwardly.

The window is `MM-DD` and may wrap the year — Valentine's runs from 27 December — so it is resolved
to the year it next runs in, and a window that closed earlier this year lays out next year's.

## What the schema had to allow

Three changes, in `2026_09_05_000400_a_season_is_a_series`.

**A seasonal plan may carry a date.** `cove_plans_dated_kind_check` said `kind = 'daily' OR drop_date
IS NULL`, and it was right about every kind it was written for: a persona and a buying guide never
stop being current, so dating one invites a reader to treat it as stale. A season is the exception —
it is *defined* by a date range.

The date means something slightly different on the two kinds, deliberately. On a Daily it is the
**address**: the edition is read at `/daily/{date}`. On a seasonal part it is the **due date**: when
the approved plan should be built. The published page is still slug-addressed and evergreen —
`daily_pick_sets_address_check` is untouched, so no seasonal *edition* gains a date and nothing about
how a reader reaches one changed. What the two kinds share is the sentence the planner is sorted by:
this is the day this plan is due.

**One *Daily* per day, not one plan per day.** `cove_plans_market_date_idx` was unique on `(market,
drop_date)`, described as "two plans for one Tuesday is an editorial argument the builder cannot
settle". That argument is about the Daily: only one edition can be reached at `/daily/2026-04-12`. A
seasonal part is reached by its slug and is not competing for that address, so the index is now
partial on `kind = 'daily'` — narrowed to what it always meant, not dropped, because two Daily plans
for one date would otherwise insert happily and the builder would pick by row order.

**A part knows its series.** `series_key` and `part`, rather than parsing "deel 2" back out of a
slug. The planner already carries one marker-in-a-note — the drafted persona's interest — and that is
a documented workaround for a fact with no column; it survives renaming precisely because renaming is
the workflow. A series is not that: the reader-facing page asks which part it is, every slug is
renameable, and `PlanSlugs` suffixes on collision, so a slug is exactly the wrong thing to derive
identity from. A CHECK keeps the two columns together and a partial unique index keeps a re-run from
inserting a second "part 2".

## Publishing on the day it was scheduled for

`App\Jobs\PublishDueCoves` runs at 07:00 per market, after the last Daily has built, and dispatches
`BuildCove` for every approved, unbuilt plan whose date has arrived.

**This is not automatic publishing.** `buildArticle()` refuses anything that is not `approved`, and
approving is a person reading the shortlist and the brief. What the job adds is that the approval is
honoured *on the day the editor chose* rather than whenever somebody remembers to press Build. A
draft sitting on a past date does nothing, for ever. The alternative — publish the moment it is
approved — defeats the point of spreading a season at all: four pages that go live together are one
long page, and the last of them never uses the indexing time the window was opened early to buy.

Two things it deliberately does not do:

- **It never touches a Daily.** `BuildDailyEdition` owns that date and does other work about the day
  around it — mining the search log, seeding the seasonal topics — and running both would build one
  edition twice.
- **It stops at the end of the window.** An approved Halloween part that could not build in October
  must not appear in December. It is not cancelled: the plan keeps its approval and the window
  reopens next year.

It also never rebuilds. A plan with an `edition_id` is skipped, because re-running one nightly would
rewrite the prose of every part of every season for as long as the plans exist — real spend against
the `guide_copy` cap for a page nobody asked to change.

## The season comes round

A season is not a one-off, and the first version of this treated it as one: `SeasonalTopics::opening()`
filtered on `plan_id IS NULL`, so `kamperen` was laid out one spring and never offered again. The
pages it produced were never refreshed, and a subject the catalogue could not fill that year was
never reconsidered.

`SeasonalSeries::plan()` is now the entry point, and it reads which of the two applies:

| The season | What happens |
|---|---|
| has no plans | laid out — `lay()`, one part per subject |
| has plans, dated outside the coming window | renewed — `renew()`, dates slide forward |
| has plans, already dated inside it | nothing at all |

### The pages do not move

**A renewal changes one field: `drop_date`.** Not the title, not the shortlist, not the status —
every other field on the row is somebody's editorial decision, and a yearly pass that overwrote a
curated shortlist would undo a year of work on a schedule. The part keeps its slug, its
`published_at` and the ranking it spent a year earning, and rebuilds **at the same URL** with new
products and newly written prose.

The alternative — `beste-kamperen-2028-deel-1` beside last year's — was refused because by 2029 it is
three near-identical pages competing for one query, and the evergreen page the seasonal window exists
to buy indexing time for is the one that loses. This is the same rule `buildArticle()` already
followed for `published_at`: a rebuild refreshes the page, it does not republish it.

A part an editor **rejected** is re-dated too. The date is a fact about the calendar rather than an
instruction, nothing builds a rejected plan, and leaving it on last year's date would make it read as
permanently overdue.

### How the rebuild knows it is due again

`cove_plans.built_for` is the `drop_date` a plan was last honoured on, and comparing the two is the
whole test:

```
due  =  approved  AND  drop_date <= today
                  AND (built_for IS NULL OR built_for < drop_date)
```

So moving the date into the coming window is all a recurrence has to do. Nothing is cleared and no
status is rewound. This replaced `edition_id IS NULL`, which meant "not built yet" and quietly also
meant "never buildable again" — a reader had to know the second half was load-bearing. See
`2026_09_05_000500_a_season_comes_round_again`, which backfills `built_for` from `drop_date` on
everything already built so the first run after the deploy does not rebuild the lot.

### A season can grow, but it cannot become one

A season is not a fixed size. `kamperen` names four nouns, and a market whose advertisers sold no
camping stoves got three parts; the year an advertiser arrives, the fourth is worth writing — and
nothing else would ever notice, because a season is only looked at when it comes round. So a renewal
probes the subjects that are not yet parts and adds any that can now be filled, numbered after the
last and dated after it, inside the window. The parts that already ran keep their slots: a newcomer
does not get to move published pages' schedules to make room for itself.

**A season laid out as a single page stays a single page.** Its slug has no number, and there is no
way to make it part one of four — renaming it changes a live URL, and leaving it unnumbered beside
`…-deel-2` is a series whose first part is addressed unlike the rest. It refreshes as one page, every
year.

## Where a season is drafted

Three doors, one implementation.

| Door | What it does |
|---|---|
| `bc:plan-coves` | Seeds the seasonal topics, then lays out or renews every season whose window opens inside `--days`. `--no-seasons` draws only the Dailies. |
| **Content → Cove calendar** | The year as a year, with a button per season — see [cove-calendar.md](cove-calendar.md) |
| **Draft some** on the Cove planner, kind *Seasonal* | `PlanDrafter` takes N **seasons**, soonest window first, and writes all their parts |
| `POST /coves/drafts` with `kind: seasonal` | the same thing over HTTP |

`--days` bounds the command the same way it bounds the Daily walk: a season whose window opens beyond
the horizon is not yet worth putting in front of anybody. `--from` does **not** move it, because a
part's date comes from its own window rather than from where the walk started.

On the planner, `count` means **seasons**, not plans — asking for three usually writes a dozen rows.
Counting plans instead would make the box mean "roughly a quarter of this many seasons, depending",
which is not a number anybody can choose. A season the catalogue cannot fill is skipped rather than
fatal, and the shortfall sentence says which of the two reasons applies: an exhausted queue is fixed
by mining more topics, a thin catalogue is fixed by waiting or by adding an advertiser. Told the
first when it is the second, the next thing anybody does is run `bc:refresh-discovery` and get the
same answer.

## What the reader gets

`CoveRail` adds a `series` band: the published parts of this Cove's series, in part order, with the
current one marked and rendered as text rather than as a link to the page you are on.

It is drawn **above** the article rather than in the rail beside it, which is a decision about what
the block is for. The rest of the rail is somewhere to go afterwards; "which part am I reading" is
something you need before you start, and a title reading "deel 2" raises the question immediately.

Null unless at least two parts are actually published — one part is a page, not a series, and a
heading over a list of one reads as a block whose contents failed to load. So a series with part two
live and part one still in draft shows nothing, which is the correct transient state.

The band reads the **plan** behind each edition, because the series is a fact about how the work was
planned and the edition is an output every rebuild overwrites.

## Files

- `app/Services/Cove/SeasonalSeries.php` — the split, the probe and the dates
- `app/Services/Guides/SeasonalTopics.php` — `opening()`, the seasons due on the calendar
- `app/Services/Guides/TopicPlanner.php` — `draftAll()`, and which topics go to the series
- `app/Jobs/PublishDueCoves.php` — an approved part goes live on its day, and again when it comes round
- `app/Services/Cove/YearCalendar.php` — the year these windows are read on
- `app/Console/Commands/PlanCovesCommand.php` — `--no-seasons`
- `app/Services/Cove/CoveRail.php` — `series()`, the reader-facing strip
- `resources/js/Components/CoveRail.tsx` — `CoveSeries`
- `config/cove_seasons.php`, `config/giftcoves.php` (`seasons.max_parts`)
- `database/migrations/2026_09_05_000400_a_season_is_a_series.php`,
  `2026_09_05_000500_a_season_comes_round_again.php`
- `tests/Feature/SeasonalSeriesTest.php`, `tests/Feature/CoveCalendarTest.php`

## Open

- **A season is skipped or kept for good, never for one year.** Rejecting a topic takes it off every
  future calendar; there is no "not this spring". The workaround is to reject it and un-reject it
  later, which nothing reminds you to do.
- **Nothing spaces two overlapping seasons apart.** The cursor guarantees distinct dates *within* a
  series; two seasons whose windows overlap can put a part each on the same day. Visible on the
  planner and movable by hand, which is enough until it is not.

## See also

- [cove-planner.md](cove-planner.md) — the planner these rows appear on
- [cove-rail.md](cove-rail.md) — the rest of the rail
- [cove-calendar.md](cove-calendar.md) — the year these seasons sit on
- [cove-curation.md](cove-curation.md) — curating a part's shortlist

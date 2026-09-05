---
name: What runs without you
area: Content / Operations
status: Active
date_added: 2026-09-05
---

# Editorial automation

**Every stage of the pipeline can run unattended, behind a switch per stage, market and kind.**

The same stages an instruction drives — plan, curate, write, approve, build — on a second trigger:
the scheduler instead of somebody asking. One stage runner, two callers. A third implementation of
"curate this plan" would disagree with both.

*Operations → Automation.*

## The grid, and why it is a grid

Five stages × six kinds × five markets is 150 switches. As a list nobody can read it; as **one grid
per market** — kinds down, stages across — it is thirty cells that fit on a screen.

The shape then carries information. The disabled cells are exactly where a kind has no automatic
source: `plan` is dead for advice, shop and brand because nothing in the catalogue proposes one, and
`curate` is dead for every kind with no products. Each disabled cell shows its reason on hover
rather than sitting blank, because a blank cell reads as an oversight.

| Stage | Control | Notes |
|---|---|---|
| `plan` | on/off | `PlanDrafter`. Occasions only for a Daily — see below |
| `curate` | on/off | `EditionBuilder::candidates()`, capped per run |
| `write` | `off` \| `builder` \| `external` | not on/off: it chooses **who** |
| `approve` | on/off | **the gate** |
| `build` | on/off | gates the two jobs that already build |

## Deploy day changes nothing

The switches ship as a **data migration**, not a code default. A code default makes "what is running
here" a question about which release you are on, and the answer changes under somebody the first
time they deploy.

Getting the seed right took two attempts and the second one is the point. `build` is on for **every
kind**, not only `daily`: `PublishDueCoves` already honours an approved plan of any kind on its due
date, so seeding it off would have silently stopped every seasonal part publishing — precisely the
deploy-day change the seed exists to prevent. A test asserts the shipped grid publishes nothing new.

`approve` ships off everywhere, including `daily`.

## `approve` is the only switch that removes a person

Everything else prepares work. `buildArticle()` refuses a plan nobody approved, so a market with
`plan`, `curate`, `write` and `build` all on can fill the planner, curate it and write it, and still
not put a page in front of a reader.

Turning `approve` on is what `PlanDrafter`'s docblock calls "a content farm with a nicer interface".
So it is coloured as a warning, it says in words what it means the moment it is switched on, and the
page lists every market and kind it is on for — a setting that reaches readers and is visible only
on the screen that sets it is a setting somebody forgets is on.

**Auto-publish is not one category.** A `daily` already publishes unattended and always has;
`BuildDailyEdition` builds from the calendar and an approved plan merely overrides what it would
have chosen. For every other kind, `approve` is genuinely new.

## `write` chooses who, and settles a race

Not on/off, because the question is not whether prose happens.

- **`builder`** — the model writes it here, under `giftcoves.ai.caps`.
- **`external`** — plans are marked `writer = authored` and left for an agent on
  `GET /coves/queue`, costing this server nothing.

That settles a race the `writer` field was already needed for: the batch write stage picks only
`builder` plans and the queue hands out only `authored` ones, so the two writers can never target
the same plan and waste each other's work.

## Two jobs are gated, not absorbed

`RunEditorialAutomation` walks one market's enabled stages **in order** — staggered stages would
mean a plan drafted at 03:50 waits until tomorrow to be curated. It runs at 05:00, before the Daily
builds and well before `PublishDueCoves`, so anything it approves is honoured the same morning.

It deliberately does **not** absorb the two jobs that already build:

- **`BuildDailyEdition`** runs at 06:00 for a 09:00 drop, and those three hours are a deliberate
  retry window — enough for a failure to be retried or noticed before the page is due. A walk that
  also curated and approved could not hold that window without running the whole pipeline at six in
  the morning.
- **`PublishDueCoves`** carries logic belonging to *seasons* rather than to automation: `built_for`,
  so a re-dated part comes round without rebuilding nightly; the window guard, so an approved
  Halloween part cannot appear in December; and series order on a catch-up after an outage.

Rebuilding either inside a generic walk would be a second, worse copy of it. The `build` switch
gates them instead.

The walk also skips any plan with a `drop_date`: that is `PublishDueCoves`'s to honour on its day,
and two builds of one page race over the same edition row.

## `plan` drafts occasions, not dates

For a Daily the walk passes `occasionsOnly`. `ObservanceCalendar::themeFor()` falls back to the
evergreen rotation for any date with no named day, so an unfiltered walk returns the next N
unplanned **dates** — about three-quarters of them rotation themes, which claim nothing about their
date and give a curator nothing to react to. The Cove calendar screen hides all ~270 of them for the
same reason.

`bc:plan-coves` still fills every date, which is what it is for.

## Known: the settings read fails closed

`AutomationSettingsStore::stored()` wraps its cache read in a `try/catch` and returns `[]` — "every
switch off" — when anything throws. That is inherited from `AiSettingsStore` and is right *there*:
during a Docker build or a `migrate` against a fresh schema there is no reachable database, and a
provider that throws takes out the one command that would fix it.

It is less obviously right here, because it now gates `BuildDailyEdition`. **A database or Redis
failure at exactly 06:00 would skip that market's Daily and log it**, where previously the job would
have thrown and been retried.

The risk is low — the job queries the database several times before reaching the gate, so the
connection is already proven — but the failure is *quiet*, and a quietly missing Daily is a failure
this codebase has been bitten by before (see the `retry_after` note in
[scheduled-writing.md](scheduled-writing.md), where `/be-nl/daily` 404'd on production for weeks
while `/health` said `ok`).

**If it needs tightening, the fix is to distinguish the two cases**: "switched off" is a legitimate
empty result, "could not read the switches" is not, and only the first should skip a build. Left as
it is for now because the alternative — throwing during boot on a machine with no database — is a
worse failure and the one the `try/catch` was written for.

## Files

- `app/Services/Settings/AutomationSettingsStore.php` — the switches, and the allowlist derived from
  the enums
- `app/Jobs/RunEditorialAutomation.php` — the walk
- `app/Filament/Pages/Automation.php` + `resources/views/filament/pages/automation.blade.php`
- `database/migrations/2026_09_05_000900_automation_ships_doing_what_it_already_did.php`
- `tests/Feature/EditorialAutomationTest.php`

## See also

- [cove-writer.md](cove-writer.md) — the `writer` field this depends on
- [seasonal-series.md](seasonal-series.md) — what `PublishDueCoves` is protecting
- [ai-invariant.md](ai-invariant.md) — `write: builder` is a queued job under the existing caps;
  `write: external` costs this server nothing

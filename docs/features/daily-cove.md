---
name: The Daily Cove
area: Discovery / Content
status: In progress (Phase 5)
date_added: 2026-08-08
---

# The Daily Cove

**Daily Picks and buying guides, merged into one daily edition with a game at the front of it.**

Supersedes the separate Phase 5 (Daily Picks) and Phase 6 (buying guides).

## Why merge them

Kept apart, each has a hole the other fills:

- **Daily Picks alone gives no reason to come back.** Novelty wears off in about a week. A daily
  product feed is something people visit twice.
- **Buying guides alone have no audience on publish day.** They are pure evergreen SEO — real value,
  but a guide published to nobody takes months to earn its first visit, and nothing about them makes
  anyone return.

Together: the guide gets a daily audience the day it drops and evergreen traffic forever after; the
picks get a permanent, indexable home instead of scrolling into nothing.

And there is a third thing neither has on its own — a **reason to share**.

## The shape: one page a day, three beats

### Beat 1 — The Guess (the loop)

One product from the [Serendipity Engine](serendipity.md), image and description shown, **price
hidden**. Guess what it costs. Feedback in bands (way under / close / way over), a small number of
tries, then the reveal with a link to the actual offers.

The result is a **shareable emoji grid** — the Wordle artefact, and the single best-proven organic
loop of the last five years. It works because:

- The share is a **score**, not a link-beg. Nobody feels marketed to by a row of squares.
- It is **the same puzzle for everyone that day**, so a posted result is a conversation rather than a
  broadcast.
- It carries **no spoiler**, so posting it costs the poster nothing.

Streaks give the return reason. **Derived from attempt dates, not stored as a counter** — a stored
streak drifts, gets corrupted by a timezone bug, and has to be repaired by hand; a `SELECT DISTINCT
played_on` cannot.

Playable without an account (anonymous cookie identity, as with lists), because asking someone to
sign up before their first guess loses them.

### Beat 2 — The Finds

The rest of today's serendipity picks, under a theme. This is Daily Picks, unchanged in substance:
scored by the engine, deduplicated against a 90-day memory so nothing repeats, themed so the set
reads as edited rather than generated.

### Beat 3 — The Guide

Today's buying guide, built from **what people actually searched on the site this week** — the
`search_log` clustering that was Phase 6. "The five best X, and the one actually worth it."

This is where the SEO value lives. Every edition has a permanent URL
(`/{market}/daily/{date}`), so the archive is a growing corpus of indexed pages, each one a guide
plus a set of products plus a puzzle. Ninety days in, that is ninety pages per market that did not
exist before, each answering a question someone demonstrably asked.

## Why this is a defensible loop and not a gimmick

The game is not bolted on. **It is powered by the thing that makes the site worth existing**: we can
run the price-guessing game because we hold real, current, multi-shop prices. A content site cannot
run it. A single retailer running it would be advertising. The puzzle is a demonstration of the
product's actual asset.

And the guess is *interesting* precisely because the product is unusual — which is the Serendipity
Engine's output. The three beats are one machine.

## The calendar: a theme for every day

An edition that opens with "Today's picks" and nothing else gives nobody a reason to return, so
**every date of the year resolves to a theme**. Two mechanisms, deliberately unlike each other:

| | Named days (`config/observances.php`) | Evergreen themes (`config/cove_themes.php`) |
|---|---|---|
| Count | ~100 | ~54 |
| What it claims | A fact about the date | Nothing about the date |
| Failure mode | Wrong in public, once a year, forever | None — "the desk reset" is true on any Tuesday |
| Copy | Hand-written, checked | Hand-written, seasonal |

`ObservanceCalendar::themeFor()` returns the named day if there is one and falls through to
`ThemeRotation` otherwise. `on()` still answers the narrower "is this a *named* day?", which is what
the "coming up" strip wants — nobody is counting down to the desk reset.

### Where the named days come from, and where they do not

**Not the UN international-day list.** It is the obvious source for filling 365 slots and it is the
wrong one: a large share of it is atrocity remembrance and disease awareness — Holocaust Memorial
Day, the Srebrenica genocide, victims of enforced disappearances, World AIDS Day. Real dates, and not
shopping occasions. Putting "today's finds" under a genocide remembrance banner is the kind of
mistake that ends up in a screenshot.

The list is drawn from the commercial and playful calendars instead — food days, fandom days, hobby
days, retail moments. One test decides an entry: *would a reader be pleased, rather than appalled, to
be sold something today?*

### How a date gets an evergreen theme

`ThemeRotation` shuffles the themes eligible for the month with a seed of (year, month, market), then
hands them out by day of month. Consequences worth knowing:

- **Deterministic.** The same date always yields the same theme, so a plan drafted in January still
  matches the edition built in June. `bc:plan-coves` would otherwise describe editions that never
  appear.
- **No repeat inside a month**, because the eligible pool is always longer than any month. A test
  asserts this — add a seasonal theme while removing an all-year one and that is what tells you.
- **Markets diverge.** The market is in the seed, so five markets do not run one identical calendar.
- Sorting is by `hash(seed, key)` rather than a seeded `shuffle()`, because `mt_srand`'s sequence is
  not guaranteed stable across PHP versions and this ordering has to survive an upgrade.

### Seasonal run-ups

A month tag is too coarse for the thing that actually sells: the weeks *before* an event. Nobody buys
a Halloween costume on 31 October, and the first warm weekend in May is when a paddling pool stops
being a silly idea. So a theme may carry a `window` of `MM-DD` dates (wrapping the year end if `to`
precedes `from`), and while the window is open its themes take roughly **one day in three** —
enough that the season is unmistakable, not so much that the site becomes a costume shop for a
fortnight. Named days still win outright, so 31 October is Halloween and not its trailer.

Windows in place: early summer, poolside, barbecue season, holiday packing, back to school,
pre-Halloween, autumn indoors, the Sinterklaas run-up (`be-nl`/`nl-nl` only), gift season, and the
January reset.

The slot decision is `hash(seed, day) % 3`, not `day % 3` — modulo on the day number would put the
seasonal slots on identical dates in every market and every year, which reads as a pattern the second
time you look.

### Copy, and what may be missing

Titles are mandatory in all five markets: `Observance::title()` falls back market language → English
→ **null**, and a theme with no title is simply not a theme, which the builder already handles. The
one-line blurb is optional and deliberately does **not** fall back to English — a Dutch heading with
an English sentence under it looks broken in a way a missing sentence does not. `LocalisationTest`
exempts exactly that one key shape and nothing else.

`fr` and `es` currently carry titles only. The AI editorial pass fills the prose; with `AI_ENABLED=false`
those editions run with a title and no blurb, which is correct rather than broken.

An evergreen theme is passed to the model as "today's angle … this is NOT a named day", because told
"the occasion: cosy" a model writes "today we celebrate cosiness" and invents a holiday.

## Compliance

The guessed price must come from a source that permits price storage and display in this context.
Sources that require live re-fetch and prohibit retained pricing (Amazon) **cannot be the subject of
the daily guess** — the answer would have to be re-fetched at reveal time and could differ from the
one the game was scored against. `Source::allowsPriceTracking()` gates candidate selection, the same
way it gates alerts. See [amazon-compliance.md](amazon-compliance.md).

## AI

Theme lines and the guide's editorial copy are the only AI-touched parts, and they run in the nightly
build job under the `daily_picks` and `guide_copy` caps. The edition builds and publishes with
`AI_ENABLED=false` — themes fall back to a curated rotation, guides to template copy. The game, the
scoring and the picks involve no model at all. See [ai-invariant.md](ai-invariant.md).

### Getting the prose back: `bc:refresh-guide-copy`

An edition is rebuilt every day, so a theme written during an AI outage is replaced by the next
morning's run. **A guide is not.** It is written once at publication and nothing revisited it, so a
guide built while the model was unreachable kept its template copy permanently, and no symptom on the
page said so: it renders, it simply has no editorial in it.

That was not hypothetical. Until `AiClient` stopped reading the answer out of `content[0]` — a
`thinking` block on any prompt long enough to warrant one — every guide fell back to the template
while the usage table showed successful calls, real token counts and zero errors.

`bc:refresh-guide-copy` re-attempts the prose. Daily at 04:40, eight guides a run, which clears a
backlog inside a fortnight without competing with the 06:00 editions for the day's budget. It also
serves as the monthly freshness pass the build plan calls for, since that is the same operation on a
different trigger.

Three rules it holds to:

- **The shortlist is never re-chosen.** Only the words change. Re-picking products would reorder a
  page Google has already indexed, and the new copy would describe a guide nobody ranked.
- **Existing copy is never traded for the template.** `GuideBuilder::copy()` reports whether the
  answer came from a model, and a run that could not reach one leaves the guide exactly as it was.
  Without that, every capped run would quietly strip prose from good guides.
- **The cap is checked per guide, not once up front.** Other features share the day's budget. Running
  on past it makes one failed call per remaining guide, each logged as if the model had let us down.

Guides with no editorial at all are served before stale ones. A stale but real paragraph is in far
better shape than none, and the cap means a run usually cannot have both.

## Planned schema

Expanding the tables Phase 0 already created:

- `daily_pick_sets` → gains `guide_id`, `challenge_group_id`, `challenge_price`
- `daily_picks` — unchanged
- `challenge_attempts` — edition, identity, guess, band, solved, `played_on`
- `guides` / `guide_items` / `guide_topics` — unchanged, now linked from an edition

Streaks are a query over `challenge_attempts.played_on`, not a table.

## Status

Designed. Building next.

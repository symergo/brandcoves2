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

## Planned schema

Expanding the tables Phase 0 already created:

- `daily_pick_sets` → gains `guide_id`, `challenge_group_id`, `challenge_price`
- `daily_picks` — unchanged
- `challenge_attempts` — edition, identity, guess, band, solved, `played_on`
- `guides` / `guide_items` / `guide_topics` — unchanged, now linked from an edition

Streaks are a query over `challenge_attempts.played_on`, not a table.

## Status

Designed. Building next.

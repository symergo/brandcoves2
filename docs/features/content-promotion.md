---
name: Content promotion
area: Content / Operations
status: Active
date_added: 2026-08-10
---

# Content promotion

**Moving editorial work between environments. Never people, never the catalogue.**

`bc:export-content` on one side, `bc:import-content` on the other, with a JSON envelope in between.

## Why the catalogue is rebuilt and editorial is not

The catalogue regenerates from feeds, so copying it moves risk for no gain — production ingests its
own.

Editorial does not regenerate. A guide is **AI-written**, so asking production to write its own would
spend the budget a second time *and* produce different words, leaving two environments that disagree
about what the same guide says. Promotion is not merely the faster option here; it is the only way
they stay one site.

This is also why a wholesale `pg_dump` upstream stays forbidden. `users`, `recipients` and
`wishlists` hold real emails and real notes about real people's gifts — `bc:scrub` exists precisely
because that data is real, and importing it would put it into a second live system.

## The constraint that shapes the whole design

Editorial rows point at products by **environment-local integer id**:

- `daily_picks.group_id`
- `guide_items.group_id`
- `cove_plans.pinned_group_ids`

Each environment assigns those from its own ingestion, so **they do not line up**. Copying rows
verbatim would not fail. It would point a hand-picked Cove at whatever product happens to hold that
id on the far side — the page renders, the price is real, and the pick is simply not the one anybody
chose. **A wrong product is far worse than a missing one, because nothing ever surfaces it.**

The stable handle already exists: `product_groups` is unique on `(market, identity_key)` —
invariant 2. Every product reference is rewritten as that pair on the way out and resolved back on
the way in.

A reference the target cannot resolve is **dropped and named**, never guessed at.
`guide_items.group_id` is `NOT NULL`, so dropping is the only option there anyway; everything else
follows the same rule, so the behaviour is one rule rather than a table of exceptions.

## Allowlist, never denylist

`ContentEnvelope::SURFACES` names what may travel: `feeds`, `copy`, `guides`, `topics`, `editions`,
`plans`. Asking for anything else is an error, not an empty result.

A denylist of personal tables would silently include every table added afterwards, and the cost of
being wrong is exporting real people's gift lists. Columns work the same way, which is how
`created_by` and `author_id` stay behind.

Three kinds of column are stripped:

| Kind | Examples | Why |
|---|---|---|
| People | `created_by`, `author_id` | User ids, and meaningless where that user does not exist |
| Audience | `mindblown_count`, `meh_count` | Reactions from staging's visitors; carrying them puts invented engagement in front of real users |
| Runtime state | `last_run_at`, `last_error`, `last_row_count` | Describes the environment that ran the feed, not the feed |

## Two decisions worth knowing

**Feeds arrive registered but switched off.** Whether a feed runs is a decision about *this*
environment's bandwidth and spend, so importing "on" would start hundreds of megabytes of downloads
as a side effect of a content promotion. Dropping the column was not enough — `feeds.enabled` defaults
to **true**, so an unset value arrives switched on. It has to be stated, and a test caught that. On an
update the local value is left exactly as it was: the local operator outranks the exporting one.

**Dry run is the default; writing takes `--write`.** The interesting question is never "did it
import" but "what could this environment not match" — production's catalogue is smaller than
staging's, so some picks have no counterpart. That list is the reason to run the command at all, and
defaulting to a write would hide it behind a fait accompli.

The dry run does the entire job inside a transaction and rolls it back, rather than simulating it
more cheaply. A report built from a simulation would eventually drift from what the write actually
does, which is the one thing a dry run must never do.

## What cannot travel, even deliberately

`connector_settings` is sealed with `CREDENTIALS_ENCRYPTION_KEY`, and each environment has its own —
production was given a freshly generated one when it was built. Staging's ciphertext is
undecryptable there. **Connector credentials must be set on each environment directly.** This is a
property of the design rather than a bug, and it is written down here so nobody loses an afternoon
to it.

## Usage

```bash
# Straight across, never touching a disk
docker exec <staging-app> php artisan bc:export-content \
  | docker exec -i <prod-app> php artisan bc:import-content --in=-

# Or in two steps, to read the envelope first
docker exec <staging-app> php artisan bc:export-content --out=/tmp/content.json
docker exec -i <prod-app> php artisan bc:import-content --in=- --write < content.json
```

`--surfaces=guides,editions` narrows it. Progress goes to **stderr** so stdout stays a clean pipe;
a progress line inside the JSON would make the envelope unparseable at the far end.

## Verification

Run the import twice. The second run must report the same counts and create nothing — idempotence is
the property most likely to be got wrong, and the one that turns a promotion into two of every Cove.
Natural keys do the work: `guides.(market, slug)`, `daily_pick_sets.(market, drop_date)`,
`feeds.(source, external_feed_id, market)`.

## Files

- `app/Services/Content/ContentEnvelope.php` — the rules
- `app/Console/Commands/ExportContentCommand.php`
- `app/Console/Commands/ImportContentCommand.php`
- `tests/Feature/ContentPromotionTest.php` — remapping, dropping, idempotence, and that nothing
  personal can be exported

## See also

- [product-identity.md](product-identity.md) — why `(market, identity_key)` is the only portable handle
- [daily-cove.md](daily-cove.md) — what an edition is made of
- [config-contract.md](config-contract.md) — the settings half of the same problem

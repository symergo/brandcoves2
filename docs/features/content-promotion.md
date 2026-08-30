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

`ContentEnvelope::SURFACES` names what may travel: `feeds`, `blocks`, `topics`, `editions`, `plans`.
Asking for anything else is an error, not an empty result.

`ContentEnvelope::RETIRED` names the ones an *older* envelope may still carry — `copy` and `guides`.
Import accepts those and export does not offer them, which is the whole asymmetry: a file taken from
an older build has to load, and writing a shape nothing reads would be pointless.

**Version 3 retired `copy`.** Page copy is `page_blocks` now — see
[page-templates.md](page-templates.md) — and a v2 envelope's copy rows are **dropped and named** in the
report rather than converted. Every environment is seeded identically by that release's migration, so
importing them would recreate the same sentences under a different identity and print the region
twice.

**The version check changed direction at the same time, and that is a fix rather than a side effect.**
It was `$version !== self::VERSION`, which is backwards for what it protects: the question is whether
*this build* can read *that file*, and it is a file from a **newer** build that it cannot. The proof
it was wrong sits in the same class — `importLegacyGuides()` exists to fold a v1 envelope's guides
into editions, and had been unreachable dead code since the day it was written, rejected three frames
earlier by the equality check. It is now `$version > self::VERSION`, plus a `< 1` sanity throw.

**Blocks carry their variants nested inside them**, like `plans` carries its items, because a variant
has no identity independent of its block: `(page, region, language, position)` looks like a natural
key and is not, since position is exactly what an edit changes. Import therefore **replaces per
`(page, region, language)`** rather than merging — a merge leaves behind blocks the author deleted on
the far side, and merged blocks would interleave two orderings into one nonsense.

> Importing blocks flushes the page-copy cache without `ContentEnvelope` knowing about it, because the
> importer writes through Eloquent and the flush lives on `PageBlock::booted()`. That is deliberate:
> the copy bank flushed from its admin screen, and the importer is not one, so a promoted change used
> to sit behind a stale cache. Do not "tidy up" the model hook.

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

## In the admin

**Operations → Migration** does the same three things without a shell: shows what is running here,
moves content, and redeploys.

It is a face on `ContentEnvelope`, deliberately — one set of rules rather than two, so the screen
cannot drift from the commands.

**Content moves as a file, not a push.** Download an envelope on one side, upload it on the other.
A one-click push would need this environment to hold a credential for the other one and to be able to
write to it over the network — a standing capability, always live, existing on the day somebody clicks
it by mistake. A file passes through a person, and the dry run makes that person read the drop list
first. **Apply is hidden until something has been checked**, for the same reason.

**The buttons live on the sections they act on.** *Download envelope*, *Check upload* and *Apply
upload* sit under the Content transfer section, in the order you do them; *Save webhook* and *Deploy*
sit under Deploy. They were five page-header actions in a row, with nothing to say which button
belonged to which section — and the most destructive one, Deploy, sat next to the most routine one.

Three things about that section are load-bearing rather than cosmetic:

- **Apply is withdrawn when the selection changes.** A dry run describes one file and one set of
  surfaces. Leaving Apply live afterwards let you preview envelope A, swap to B, and write B with
  nobody having seen its drop list — the exact outcome the gate exists to prevent, reached by a route
  that looks like normal use.
- **A section action must be registered as well as placed.** `footerActions()` decides only where an
  action is *drawn*; `getActions()` is what resolves one by name when it is clicked. An action that
  is only in the footer renders correctly and then cannot mount its confirmation modal.
- **An empty export is refused.** Exporting a fresh environment produces a valid envelope containing
  nothing, which downloads and imports without complaint and leaves the far side exactly as it was.
  The only symptom is somebody concluding the importer is broken, so the export says how many rows
  per surface it found and declines when that is zero.

The page also shows what this environment holds, **broken down per Cove kind** — Daily Coves,
personas, buying guides, seasonal guides, advice articles — plus picks, plans, curated products and
the catalogue. Since the fold every published page lives in `daily_pick_sets`, so a single "412
editions" on each side would hide the fact that one environment has no guides at all, which is
precisely what you open this page to find out. A production catalogue smaller than staging's is also
why picks get dropped, and seeing both counts explains a drop list before it appears.

### Deploy

A **per-application Coolify deploy webhook**, stored encrypted with `APP_KEY`, deliberately not an
API token. A token can rename domains, read every environment variable of every application on the
box, and delete things; the worst this secret can do if it leaks is redeploy the current commit.

It cannot choose a commit — the webhook deploys whatever the tracked branch points at. A button that
can put any commit on production is a deploy pipeline with no review step, and that belongs in
Coolify where the audit trail is.

Today this is a convenience rather than a gate: both applications have auto-deploy on, so a push to
`main` already ships within the minute. It becomes the gate under the one-branch model in
[../deployment.md](../deployment.md), which turns auto-deploy off on production.

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

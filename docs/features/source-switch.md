---
name: Source switch
area: Catalogue / Operations
status: Active
date_added: 2026-09-01
---

# Source switch — turning a source off, per market, from the panel

Every source can be switched off for one market at a time from **Catalogue → Market supply**, without
a deploy and without touching the environment. `App\Services\Connectors\SourceSwitch` holds the
state; the grid at the top of the Market supply page is the whole interface.

Individual Awin feed rows keep their own `enabled` flag and now flip straight from the Feeds table
(a `ToggleColumn` rather than an Edit-form round trip). The two are different scopes of the same
decision: the source switch stops *a whole source in a market*, the feed toggle stops *one
advertiser*.

---

## Why it exists

There were two ways to stop a source and neither was the one anybody wanted.

**`giftcoves.connectors.*.enabled` is global and lives in the environment.** Turning bol off for
Spain alone was impossible, and turning it off at all was a redeploy — the same friction the AI
settings screen ([ai-invariant.md](ai-invariant.md)) exists to remove, for the same reason: stopping
a misbehaving source during an incident should not require a build, and should not require whoever
is awake to have Coolify open.

**The other way was per-market and accidental.** eBay and Tradedoubler already skip a market whose
marketplace or query scoping is blank — `Market::ebayMarketplace()`, `Market::tradedoublerQuery()` —
so `EBAY_MARKETPLACE_ES=` does switch eBay off for Spain today. But that config means *eBay does not
serve this market*, not *we have chosen to stop asking*, and bol has no such lever at all:
`Market::bolCountry()` is a `match` arm, so switching bol off for one market was a code change.

Reusing the blank mapping would make the diagnostic lie. Switch eBay off for `es` by blanking its
marketplace and Market supply reports **"no marketplace mapped — EBAY_MARKETPLACE_ES"**, sending the
next person to fix an environment variable that is set perfectly well. So this is a third, separate
fact, and `MarketSupply::blockers()` short-circuits on it and reports it in its own words.

## What off means, and what it does not

**Off stops us asking.** Live search skips the source, `bc:pull-charts` skips it, and feed ingestion
returns before downloading anything.

**Off does not retract what the source already stored.** A feed source's rows stay in `products` and
keep appearing in search, because they are a catalogue, not a cache — re-ingesting them is a
multi-hundred-megabyte download, so a settings toggle that silently discarded them would be data
loss wearing the clothes of a preference. `bc:withdraw-source` is the deliberate,
dry-run-by-default tool for suppressing those rows, and it **refuses to run while the source still
serves the market**. So the order is: switch off here, withdraw there.

That sequencing is why the switch does not confirm on the way off. It is one click to undo and it
destroys nothing; its destructive neighbour is a console command with its own dry run. A modal in
front of the switch somebody is reaching for during an incident is a modal in the way.

This is `SourceSwitchTest::switching_a_source_off_does_not_retract_what_it_already_stored()`, which
exists because the assumption runs the other way round for most people.

## Where the gate sits, and the one place it could not

The check lives in each connector's `supports()`, as the **first** condition. That is deliberate:
`supports()` is already the authority on whether a source serves a market — `ConnectorRegistry`,
`MarketSupply` and each connector's own `search()` all defer to it — so gating anywhere else would
have left a bypass.

**Feed ingestion is the exception, and it needed its own check.** Nothing on that path calls
`supports()`: the scheduler dispatches straight from `Feed::query()->enabled()`, and so do
`bc:ingest` and the "Ingest now" button. So `App\Jobs\IngestFeed` carries the check itself, right
after the disabled-feed check it mirrors, and that one job is the choke point all three dispatchers
share. Without it a switched-off source would keep downloading on the usual timetable, which is the
single thing switching it off is meant to stop.

Both a silent return, not a failure: an administrator turning a source off is not a fault, and a job
that *failed* here would retry twice and land in the failed table for doing what it was told.

## Storage

`connector_settings`, the same encrypted store the AI settings use — **no migration needed**. That
table's `source` column is CHECK-constrained, and `2026_09_02_000100_ebay_is_a_source` already
rebuilds it as `Source::values()` plus the non-connector subsystems (`ai`, `ops`), so every source
value is accepted today.

One row per source, `key = 'markets'`, holding a map of market value → `false`. A row per
`(source, market)` would be thirty rows to express "eBay is off in Spain" plus twenty-nine defaults,
and every read would decrypt rows that say nothing. `encrypted_value` is cast `encrypted:json`, so a
map costs one row and one decrypt.

**Only overrides are stored, and a source with no row is on.** Switching one back on *deletes* the
entry rather than writing `true` — the same shape `AiSettingsStore::put()` uses for falling back to
the environment. A fresh install stores nothing, which also means a market added to `Market` later
arrives switched on rather than silently off.

Entries for markets that no longer exist are dropped on read rather than migrated, because a key no
page can render is also a key no toggle can clear.

### Why not overlaid onto config

`AiSettingsStore` writes its values *into* the config at boot, and its stated reason was that
`AiClient`, `AiUsage` and the usage table already read `config('giftcoves.ai.*')` — the overlay meant
not changing them, and not having two ways to ask one question.

None of that applies here. Per-market enablement is a dimension no config key has ever had, so there
is no existing reader to preserve. Inventing `giftcoves.connectors.ebay.markets.es` purely to have
something to overlay would add a config surface whose only writer and only reader is `SourceSwitch`.

### Cached, and the try that wraps the cache call

An hour, flushed on write, because this is read on every search. The `try` wraps the **`Cache::remember`
call**, not the query inside it — copied deliberately from `AiSettingsStore`, where that exact
distinction once broke a Docker build: `package:discover` boots the application with no Postgres and
no Redis, the cache store falls back to the database driver, and the *lookup* throws several frames
before the query a narrower guard would have protected. Three situations run this without a reachable
database — a build, `migrate` against a fresh schema, and a test that has not migrated — and in all
three the right answer is the same: no overrides, every source on, boot completes.

## Why the switches are on Market supply

That page already *is* a source × market grid, and it already explains why each cell is dark. A
second identical grid on a settings page of its own would mean reading the diagnosis in one screen
and acting on it in another, with nothing keeping the two in step.

The switches are a **separate grid above** the status table rather than a control inside each status
cell, because the two say different things: the switch grid is what we have *asked for*, the table
below is what is *happening*. A source can be switched on and still dark — no credential, no
marketplace, backing off after a 429 — and that gap is the most useful thing the two grids show
together.

The page's old docblock promised "read-only, and no network". The half that mattered survives: this
still fetches nothing, ingests nothing and spends nothing, so it loads when an upstream is down —
which is exactly when somebody wants to switch the failing source off.

## Tests

`tests/Feature/SourceSwitchTest.php`, plus two cases in `MarketSupplyTest`.

The two failure modes pull in opposite directions and both are covered: a switch that does not stop
the source (two paths — `supports()` for live, `IngestFeed` for feeds), and a switch that stops too
much (the catalogue must survive it). The ingestion pair is deliberately on/off/on rather than just
off: a guard that stopped a feed *permanently* — by poisoning its cursor, say — would pass an
off-only test and still be a bug nobody could undo from the panel.

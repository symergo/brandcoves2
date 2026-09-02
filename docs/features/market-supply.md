---
name: Market supply
area: Catalogue / Operations
status: Active
date_added: 2026-08-30
---


# Market supply

**One screen that answers "what sells into this market", for feed and live sources together.**

`/admin/market-supply`, in the **Catalogue** group.

> The grid at the top of this page also **switches sources off per market** — see
> [source-switch.md](source-switch.md) for what off means, and for the catalogue it
> deliberately does not retract.

## The problem it solves

The admin could answer *which market does this feed serve* — [FeedsTable.php](../../app/Filament/Resources/Feeds/Tables/FeedsTable.php)
has a market column, a market filter and sorts on it by default. It could not answer the reverse,
and the reverse is the question anybody actually arrives with: **does this market have supply, and
if not, why not.**

Worse, it could only ever have answered it for Awin. A `feeds` row *is* a feed source. bol, eBay,
Tradedoubler and Amazon have no rows at all — their coverage is computed per request from config, by
`supports()` on each connector:

| Source | Serves a market when |
|---|---|
| Awin | an enabled `feeds` row exists for it |
| bol | credentials present **and** `Market::bolCountry()` is not null |
| eBay | credentials present **and** `Market::ebayMarketplace()` is not null |
| Tradedoubler | token present **and** `Market::tradedoublerQuery()` is not null |

`ConnectorRegistry::liveSourcesFor()` is the authority on that, and before this page its only callers
were two controllers and a console command. Nothing in the panel read it. The closest thing was the
config report on the migration screen, and it is **global**: it says `EBAY_CLIENT_ID` is set, not
that `es` is dark. Per-market truth existed only on the console, in `bc:check-bol`, `bc:check-ebay`
and `bc:check-tradedoubler` — which is exactly the SSH session
[the Awin discovery screen](../../app/Filament/Pages/DiscoverAwinFeeds.php) was built to remove.

So a market could be silently supply-less: its pages render, its sitemap is submitted, and its search
quietly returns nothing. That has no other symptom in the panel, which is why a count of dark markets
is a **red badge in the sidebar** rather than something you find by visiting.

## What a cell says, and why the distinctions exist

"No supply" has at least five causes. All five look identical on the site and each needs completely
different work, so none of them may collapse into one another:

| Status | Feed source | Live source |
|---|---|---|
| `absent` | no feeds registered | **not integrated** — no connector class exists |
| `off` | registered, all disabled | not serving; the notes name the variable that fixes it |
| `failing` | every enabled feed erroring | — |
| `pending` | enabled, never run | — |
| `ok` | at least one enabled feed has run | serving |

Two of those deserve their own note.

**A partly failing feed set still reads `ok`, and says `3 of 11 failing`.** That is the failure that
hides: the catalogue keeps filling from the healthy feeds, the site looks fine, and one merchant has
quietly gone. Marking the whole cell red would be wrong (the market *is* supplied) and marking it
plain green would be worse.

**`absent` on a live source means credentials will not help.** Amazon has config, a `Source` case and
a full set of compliance rules in [Source.php](../../app/Enums/Source.php), and *no connector class
at all* — `grep AmazonConnector` returns nothing, while `config/giftcoves.php` claims "the connector
is written and registered but disabled". Supplying `AMAZON_ACCESS_KEY` would switch on nothing. The
cell says so rather than leaving somebody to discover it after chasing a key.

## Serving, and earning nothing

A green cell can still be worthless, and this is the one condition on the page that nothing else
anywhere reports. Without an EPN campaign id or a bol partner site id the affiliate link **still
resolves**, the visitor **still buys**, and the commission goes to nobody. No error, no empty result,
no failing test — see the notes on `Market::ebayCampaignId()` and `Market::bolPartnerSiteId()`.

So a serving source with no way to earn carries an amber line on the face of the cell. Amber and not
red deliberately: the visitor's experience is perfect, which is precisely why this is invisible
everywhere else.

Tradedoubler has no equivalent, because its one token is both the credential and the affiliate id: a
source that answers at all is a source that earns.

## Why the reasons are advisory

`supports()` decides whether a source serves a market. `MarketSupply::blockers()` only *explains*
that answer, and the two are separate methods on purpose.

Inverting four `supports()` implementations into a list of reasons would mean either duplicating each
connector's conditions in the report — where they would drift — or bending four connectors to emit
diagnostics they do not otherwise need. Neither is worth it, because the two kinds of error are not
equally bad: a wrong **explanation** is survivable, and a wrong **yes/no** is a page that lies about
coverage. When the blocker list comes up empty for a source that is nonetheless dark, the cell says
so and points at the `bc:check-*` command rather than implying nothing is wrong.

Every reason names the variable that fixes it. "Not serving" on its own is the same dead end the old
discovery modal's "nothing matched" was.

## What it deliberately does not do

**No network.** Every cell comes from the database and `config()`. The page therefore loads when an
upstream is down — which is exactly when somebody opens it — and cannot spend an API budget or hang.
The cost is that green means *configured and enabled*, never *the credential is accepted*: a revoked
key, a spent quota and a marketplace the Browse API does not serve all read green here. The page says
this in a collapsed section and names the three `bc:check-*` commands that do prove it.

**No values, only presence.** Same rule as [ConfigReport](../../app/Services/Ops/ConfigReport.php),
and for the same reason: this renders into an HTML page that gets screenshotted.

**Every market, including unpublished ones.** `es` is closed *because* it has no supply, and the way
it reopens is these cells turning green. Hiding it would hide the work. The row is labelled
`unpublished` so an empty one reads as expected rather than as a fault.

## Caching

Only the three database aggregates are cached, for 60 seconds, under `bc:market-supply-counts`.
Connector and config state is free to evaluate and is read live on every render, so somebody who has
just corrected an env var is not told to wait a minute. The **Re-count** header action clears it, for
the person watching an ingestion land.

This matters when writing tests: creating a feed *after* reading the report reads the report's own
snapshot. Set fixtures up first, or call `forget()`.

## Sidebar grouping

`FeedResource` and `MerchantResource` had no `navigationGroup` and floated above the sidebar's groups
while Ingestion jobs and Products sat inside **Catalogue**. Both moved in, because this page links
into the feeds list and a link whose target sits outside its siblings' group reads as a different
part of the app. Order: Market supply, Feeds, Discover Awin feeds, Ingestion jobs, Products,
Merchants — the pipeline, with the summary on top.

## Files

| Path | Role |
|---|---|
| [app/Services/Ops/MarketSupply.php](../../app/Services/Ops/MarketSupply.php) | The rules, as data. Testable without rendering |
| [app/Filament/Pages/MarketSupply.php](../../app/Filament/Pages/MarketSupply.php) | The page, the sidebar badge, the re-count action |
| [resources/views/filament/pages/market-supply.blade.php](../../resources/views/filament/pages/market-supply.blade.php) | The grid |
| [app/Services/Connectors/ConnectorRegistry.php](../../app/Services/Connectors/ConnectorRegistry.php) | Gained `liveSources()` and a nullable `live()` — see below |
| [tests/Feature/MarketSupplyTest.php](../../tests/Feature/MarketSupplyTest.php) | 9 tests, one per distinction |

`ConnectorRegistry::live()` is nullable where `feed()` throws. `feed()` is called by the ingestion
pipeline, which has already decided the source exists and cannot proceed without it — an exception is
honest there. `live()` is called by a diagnostic asking *is this source integrated at all*, and a
question whose whole purpose is to report "no" must be able to return it rather than blowing up the
page that asked.

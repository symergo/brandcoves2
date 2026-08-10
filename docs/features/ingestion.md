---
name: Feed ingestion
area: Catalogue
status: Active — Awin feed + bol live; Amazon deferred to Phase 8
date_added: 2026-08-07
---

# Feed ingestion

Offers in, comparable physical products out.

```
Awin CSV ──stream──▶ Offer ──▶ OfferUpserter ──▶ products
                                    │                 │
                              price_history      identity_key
                                                      │
                                              ProductGrouper (SQL)
                                                      ▼
                                               product_groups
```

## Two kinds of source, because they fail differently

| | Feed (Awin) | Live (bol, Amazon) |
|---|---|---|
| When | Hourly, into our index | Per request, cached 15 min |
| Size | Hundreds of MB | A page of results |
| Failure | Resume from cursor | Degrade to fewer sources |
| Interface | `FeedConnector` | `LiveConnector` |

Adding a source is a `ConnectorRegistry` registration plus a config entry. The
ingestion pipeline and search service only ever see the interfaces.

## Which advertisers we take

`bc:awin-feeds` registers feeds; `connectors.awin.advertisers` decides which.
Measured on the live account (2026-08-10): **82 active feeds, but only 6
advertisers** — the rest are category slices of the same shops. Feed count is
not merchant count, and only merchant count matters.

| Advertiser | Markets | Rows | Why |
|---|---|---|---|
| Coolblue | be-nl, be-fr, nl-nl | 17.1k / 16.8k / 16.3k | Overlapping electronics — the comparison core |
| Krefel | be-nl, be-fr | 9.1k | ” |
| DreamLand | be-nl, be-fr | 31.1k / 22.9k | Toys: the most gift-shaped category available |
| Action | be-nl | 188 | Only low-price general retailer on the network |
| Vanden Borre | be-nl, be-fr | 13.5k | Its own publisher account; absent from the primary one |

Excluded deliberately: **FNAC BE**, at 1.2M rows the largest thing available and
the worst fit — music, books and DVDs, where EAN overlap with the others is
effectively zero. Its toy catalogue (23k NL / 69k FR) would pair well with
DreamLand, but the allowlist matches on *advertiser*, so there is no way to take
the toys without the marketplace.

DreamLand and Action are not there on comparison grounds. Electronics alone
answers "who is cheapest" and not "what should I buy for this person", which is
the question the rest of the site exists to answer.

`es` and `en` have **no Awin coverage at all** — not a configuration gap. Across
the 582 non-joined feeds, ES offers 10 small advertisers and the 123 GB ones are
the only real pool.

### Daily-deal feeds exist, and are not `Feed` rows

Coolblue publishes rotating deal feeds: `95830` (nl-nl, 2.8k), `95941` (be-nl,
2.0k), `95840` (be-nl, 78), `95932` + `95938`/`95939`/`95940` (nl-nl, today's
three). They re-import several times a day.

They are **subsets of the full feed already ingested**, so registering them as
feeds would duplicate offers, not add any. Their value is as a *discount signal*,
which needs a different consumer than `IngestFeed` and a column to carry it.
Unbuilt on purpose — noted here so the next person does not rediscover it.

Same account, same caveat: `95835` (Refurbished) and `95895` (Tweedekans) are
second-hand, and nothing downstream would currently mark them as such.

## Discovery: one set of rules, two faces

The market-matching rules live in `AwinFeedDiscovery`. `bc:awin-feeds` is the
console face of it and **Discover feeds** in `/admin/feeds` is the other. They
were going to be two copies of the same logic, and the copy that drifts is the
one that quietly serves Belgian prices to Dutch shoppers.

`--only=vandenborre,dreamland` (and the *Advertisers* box in the admin) narrows a
run to named advertisers, beating both the allowlist and `--all`. Adding one shop
should add one shop; before this the only granularity was "everything on the
allowlist", so bringing in a new merchant meant re-registering every existing one.

### Re-running discovery must not switch a feed off

`enabled` used to be part of the `updateOrCreate` payload, so a plain
`bc:awin-feeds` — the obvious thing to type, and now a button anyone in the admin
can press — set `enabled = false` on every feed it had already registered. The
running feeds would stop, the catalogue would decay over the following days as
rows aged out, and nothing on any screen would say why.

It is now written **only on create, or when explicitly enabling**. New feeds still
arrive disabled: switching thirty on at once is thirty concurrent
multi-hundred-megabyte downloads on the next scheduled run.
`rediscovery_does_not_switch_off_a_running_feed` is the test that holds it.

## Chunked and resumable

A feed cannot be ingested in one transaction and a redeploy mid-run must not
lose the work.

- The connector `stream()`s via a generator; `compress.zlib://` decompresses as
  it reads, so a 400 MB gzipped feed never lands in memory or on disk in full.
- `IngestFeed` buffers 1000 rows, upserts them, **then** records the cursor.

> The order matters. Recording the cursor *after* the commit means a crash
> re-reads a chunk rather than skipping one. Re-reading is harmless because the
> writes are upserts; skipping would silently lose rows with nothing to detect it.

## Rows that disappear are retired, not deleted

Anything this run did not touch is marked `stale`. A wishlist item or a
published guide may still point at it, and a dead link is worse than an
out-of-stock badge. Only rows from *this* feed are touched, so one advertiser's
outage cannot retire another's catalogue.

## Grouping is set-based

Identity resolution is PHP (check digits, placeholder rejection, title
normalisation), so it runs **once at ingest** and the result is stored on the
offer. That lets grouping be three SQL statements over the whole market instead
of pulling 60k rows into PHP on every run.

1. `INSERT … ON CONFLICT DO NOTHING` — one group per `(market, identity_key)`.
2. `UPDATE products SET group_id` — join offers to their group.
3. One `WITH … UPDATE` — recompute every aggregate a results page reads.

It runs **after** ingestion, not per chunk: a group's cheapest offer computed
from a half-loaded catalogue is simply wrong.

**Ties break on lowest id.** Without that, a group whose two cheapest offers are
equally priced would flip `best_offer_id` on every run, churning caches and
making the UI visibly jump between merchants for no reason.

## Prices

Integer cents throughout. Feed values arrive as `12.99`, `12,99` and
`1.299,00` — **whichever separator appears last is the decimal separator**,
which handles both European and Anglo formats correctly. A CHECK constraint
forbids negatives; a negative price would sort straight to the top of every
cheapest-offer query.

`price_history` takes **one sample per product per day**, enforced by a unique
index. Ingestion runs hourly, and 24 identical rows per product per day across a
60k catalogue is roughly half a billion rows a year to support a sparkline.

## Rate limiting (live sources)

A Redis token bucket, shared across web and queue containers because the
upstream limit applies to all of them together. Refill and spend happen inside
one Lua script, using Redis' own `TIME` so workers share a clock rather than
trusting drifting container clocks.

**The sizing trap:** a bucket can emit `capacity + rate` requests inside one
second — the full bucket plus everything that refills while it drains. Setting
`rate = 10, capacity = 10` for a documented 10 rps limit therefore allows 20 and
earns a 429. `forDocumentedLimit()` sizes so `capacity + rate <= limit`, and
`RateLimiterTest` proves it empirically by hammering for a full second.

On a 429 the bucket is drained and a cooldown set; callers skip the source
entirely rather than queueing behind a limit already refusing them.

## Merchant identity

Derived from `merchant_deep_link`, **never** from `aw_deep_link` — the latter is
an `awin1.com` tracking redirect, so every merchant would end up showing the
affiliate network's domain and favicon instead of their own.

## Operating it

```bash
php artisan bc:ingest --sync                 # everything, inline, watchable
php artisan bc:ingest --market=be-nl         # one market
php artisan bc:ingest --feed=3 --fresh       # discard the cursor, start over
```

Or from `/admin/feeds`: **Ingest now** and **Reset cursor** per feed, with live
progress under **Ingestion jobs** (polls every 10s) and a sidebar badge counting
failing feeds.

Scheduled: ingest hourly, group at :40, prune price history nightly.

## Files

- `app/Services/Connectors/` — interfaces, `Offer`, `ConnectorRegistry`, `RateLimiter`
- `app/Services/Connectors/Awin/AwinConnector.php`
- `app/Services/Connectors/Bol/BolConnector.php`
- `app/Services/Identity/{Gtin,IdentityResolver,Identity}.php`
- `app/Services/Ingestion/{OfferUpserter,ProductGrouper}.php`
- `app/Jobs/{IngestFeed,GroupProducts}.php`

## Verification

`tests/Feature/IngestionTest.php` runs the whole pipeline against
`tests/Fixtures/awin-sample.csv`, which encodes what real feeds contain: two
merchants sharing an EAN, placeholder EANs (`0`, `N/A`), a too-short title, an
unbranded row, a European-format price, and a `javascript:` URL that is asserted
never to reach the database.

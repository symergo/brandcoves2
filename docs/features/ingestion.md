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

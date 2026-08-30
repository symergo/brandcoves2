# eBay — a third live source

eBay joins bol as a **live connector**: queried per request through its Browse API, cached briefly,
never ingested. `App\Services\Connectors\Ebay\EbayConnector`, registered in `AppServiceProvider`
alongside bol and Awin.

Adding it touched no search code, no ingestion code and no controller. That was the point of
`ConnectorRegistry` and it held: a class, a config block, one registration line, and a migration.

---

## Why live and not a feed

bol is live because it has no feed we can take. eBay is live for the opposite reason — it *has*
feeds, and they are the wrong shape.

eBay's unit of inventory is a **listing**, not a product. A listing ends, sells out, gets relisted at
a different price, or is a single second-hand item that exists exactly once. A nightly download of
those is a photograph of a shop that has already rearranged itself, and a comparison page quoting
yesterday's price for a listing that closed last night is worse than one that simply omits eBay —
the visitor clicks through to a 404 and learns not to trust the price beside it.

Live has a cost, and it is the one described under *Grouping* below.

## What is asked for, and what is refused

The search sends `filter=conditions:{NEW},buyingOptions:{FIXED_PRICE}`
(`giftcoves.connectors.ebay.filter`). Both halves are deliberate, and both cost recall:

- **Fixed price only.** An auction's number on screen is a *bid*, not a price. Feeding it into the
  min/median aggregates behind "cheapest offer" would make that badge a claim that stops being true
  the moment somebody else bids — and the badge is the one thing on the page that has to be exactly
  right.
- **New only.** Used goods are genuinely giftable and this still excludes them, because condition on
  eBay is seller-declared prose and a gift bought sight-unseen from a description is a different
  product from the same title new. If that ever changes, it changes here and nowhere else.

Emptying `EBAY_FILTER` in the environment is how you go and look at the other half.

## A foreign price is dropped, not converted

Every market here is euro (`Market::currency()`) and `products.price` carries no per-row currency.
eBay returns the listing's own currency, and a marketplace does carry listings priced in another one.

A converted number would enter the "cheapest offer" aggregate at a rate nobody recorded on a day
nobody remembers — £279 stored as 27900 cents quietly undercuts a genuine €289 offer and wins the
badge. `EbayConnector::price()` drops the listing instead. It costs one result.

## Markets and marketplaces

The mapping lives in config (`giftcoves.connectors.ebay.marketplace`), not in a `match` arm on the
enum, because it is the part of this integration **most likely to be wrong and most expensive to be
wrong about**: a marketplace the Browse API does not serve answers 200 with an empty body, which is
indistinguishable from a market where eBay has nothing to sell.

| Market | Marketplace | Why |
|---|---|---|
| `nl-nl` | `EBAY_NL` | Native. |
| `be-nl` | `EBAY_NL` | eBay publishes `EBAY_BENL`, but the Belgian storefronts have long redirected and Browse's marketplace coverage is narrower than its id list. Dutch, euro, ships to Belgium. |
| `be-fr` | `EBAY_FR` | Same reasoning as `be-nl`, in French. |
| `en` | `EBAY_NL` | Follows bol's precedent. There is no English euro marketplace: `EBAY_GB` prices in sterling, and the currency guard above would then drop every single row. |
| `es` | `EBAY_ES` | Native — and the one market Awin and bol both leave empty. Supply waiting for the switcher to open. |

Each is overridable (`EBAY_MARKETPLACE_BE_NL` and friends), so pointing `be-nl` at `EBAY_BENL` after
proving it works is an env change rather than a deploy. **Blank means "never ask eBay for this
market"** — never "use the default", which would return priced, buyable, irrelevant results.

`php artisan bc:check-ebay --market=be-nl` is what turns the guess in that table into a fact. It
prints the resolved marketplace, the campaign id, the filter, and runs a real search.

## Grouping: the cost of being live

**eBay search results carry no barcode.** `item_summary` returns no `gtin`, `ean`, `upc` or `brand`
— only the item detail endpoint does, and calling it per result would turn one request into
twenty-five against a daily quota.

So most eBay offers reach `IdentityResolver` with neither a barcode nor a brand, fall through to the
brand+title fallback key, and resolve to nothing. They land in `products` and never join a
`product_group`, which means they are invisible to group-based search — invariant 3.

Three things soften it, and none of them fixes it:

- `BrandAttribution` fills the brand in when the *query itself* was a brand name, which is exactly
  when it matters most (a Sony page showing Sony listings) and gives those rows a fallback identity.
- `fetchById()` calls the detail endpoint and **does** get `gtin` and `brand`. Nothing calls it yet
  — it is the `LiveConnector` contract, and no re-check job exists for any source — but it is
  where a barcode comes from when one does, and a re-checked offer groups properly from then on.
- eBay titles are written for eBay's search engine — `NEW Sony WH-1000XM5 Wireless Headphones Black
  *FREE SHIPPING*` — so a brand parsed out of one would be a guess, and a wrong brand splits a
  product or mislabels a facet. Left null on purpose.

The practical read: eBay widens *supply*, not *comparison*. It is a source of things to find, not
reliably a second price on a card that already exists. The editorial index at `/api/editorial` says
so in as many words, because a writer who does not know it will assume the connector is broken.

## No charts

bol registers as both a `LiveConnector` and a `PopularityConnector` because it publishes a
browse-ordered bestseller list — demand nobody typed a query to produce. eBay has no equivalent
endpoint: its trending surfaces are web pages, and a search sorted by best match is *relevance*,
which is the one thing a demand signal must not be. Charting eBay would mean scraping, so eBay does
not chart. One registration, not two.

## Attribution, and the failure that is invisible

The `X-EBAY-C-ENDUSERCTX: affiliateCampaignId=…` header is what makes eBay return
`itemAffiliateWebUrl` at all. Without a campaign id every field still arrives, the search still
works, the links still resolve — and **no click is ever attributed**. Nothing on the site reports a
problem; it shows up months later as an empty EPN statement.

This is bol's site-id trap exactly ([search.md](search.md) tells the same story), which is why
`bc:check-ebay` prints a missing campaign id in red and its result table has a `Tracked link`
column. That column tests for `campid=` / `mkcid=` and **not** for the host: the tracked and
untracked URLs are the same `ebay.xx/itm/…` link, so a host check would print a green "yes" on every
row of a connector earning nothing.

Campaign ids are per marketplace, not per market — `be-nl`, `nl-nl` and `en` all read `EBAY_NL` and
share one.

## One merchant row, not one per seller

`merchantExternalId` is the constant `'ebay'`. The seller is in the payload and is deliberately
unused: `merchants` is the shop directory a visitor reads as "who you compare"
([shop-coves.md](shop-coves.md)), and eBay has millions of sellers. Keying on them would turn a page
of six shops into an unbounded one and make every eBay offer look like a shop nobody has heard of.
eBay is the shop; the seller is a detail of the listing.

## Rate limiting

Two buckets, `ebay:search` and `ebay:item`, because eBay meters each Browse method against its own
daily quota — the same structural fact as bol's per-endpoint limits, so a search-heavy hour cannot
stop a wishlist re-check.

The numbers are chosen differently from bol's, though. bol documents 10 requests/second and its
bucket is sized so `capacity + rate ≤ 10` provably holds. eBay documents a **daily** call quota and
no per-second ceiling, so 5/s with a burst of 1 is sized against the budget instead: fast enough
that a search never waits, slow enough that a runaway loop burns a day's quota in twenty minutes
rather than two.

The cooldown after a 429 is 300s, five times bol's, for the same reason — a 429 here usually means
the day is spent, and retrying in a minute just spends the next request too.

## Adding a source is still a migration

Worth writing down because it is the one step the registry abstraction does *not* cover. Seven tables
carry a `CHECK (source IN (…))` built from `Source::values()` **at the moment each table's own
migration ran**. Adding a case to the enum therefore gives a fresh clone the new source and gives
every already-migrated database nothing — so eBay would work perfectly on a laptop created today and
fail every insert on staging, from identical code.

`2026_09_02_000100_ebay_is_a_source` rebuilds all seven from `Source::values()`, which makes it
idempotent and convergent. The next source needs a file like it, not an edit to it.

## Configuration

```
EBAY_CLIENT_ID=          # App ID from a PRODUCTION keyset — a sandbox keyset
EBAY_CLIENT_SECRET=      # authenticates against a different host entirely
EBAY_CAMPAIGN_ID_NL=     # EPN campaign; without it, clicks earn nothing
EBAY_CAMPAIGN_ID_FR=
EBAY_CAMPAIGN_ID_ES=
```

`enabled` defaults to **true** and the connector is inert without credentials — `supports()` requires
the OAuth pair — so shipping this before the eBay developer account exists changes nothing anywhere.
That is deliberate: an `EBAY_ENABLED=false` flag would be forgotten alongside the credentials it
guards, which is the state Amazon has been in since Phase 8 was deferred.

`/health` reports `config.ebay`, and `bc:check-config` lists all five keys with the campaign ids
marked optional-but-consequential.

## Tests

`tests/Feature/EbayConnectorTest.php`. One trap there is worth knowing before writing more:
`Http::fake()` evaluates **every** matching stub and keeps the last one's response rather than
stopping at the first match. The token URL and the API share a host, so a broad `api.ebay.com/*`
stub still runs for the token request — harmless with a plain response, and not harmless with
`Http::sequence()`, where the token request silently pops the first queued response and the search
gets the second. The browse stub is scoped to `api.ebay.com/buy/*` for that reason.

The other one: `burst` is 1, so any test that searches twice must reset the Redis bucket between the
calls or the second search makes no request at all.

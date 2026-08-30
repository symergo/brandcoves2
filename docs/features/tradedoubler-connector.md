# Tradedoubler — the first source that is a network

`App\Services\Connectors\Tradedoubler\TradedoublerConnector`, registered live in
`AppServiceProvider` beside bol and eBay. Third live source, and structurally unlike the other two.

> **Read this first: this connector has never seen a real payload, and the credential it was built
> for is rejected.**
>
> The token supplied on 2026-08-30 answers **HTTP 403** with
> `{"message":"Invalid token, Request not Authorised","statuscode":"4001"}` — reproduced with
> `bc:check-tradedoubler`. So no live response could be read, and the field mapping below is
> Tradedoubler's documented shape and nothing more.
>
> That matters more than an ordinary unknown, because **a wrong field name in a connector fails
> silently**: an empty list is indistinguishable from "the network has nothing for this query", which
> is how the Awin barcode-column bug survived for weeks. Everything below describes intent that the
> tests confirm and that a live call has not.
>
> **Before trusting this in production, get a working credential and run
> `php artisan bc:check-tradedoubler --market=nl-nl --raw`.** The 403 suggests the value is not an
> Open Product API token at all — it was handed over labelled `client_secret`, and Tradedoubler
> issues client id/secret pairs for its OAuth APIs, which are a different product with a token
> exchange this connector does not perform. If that is what it turns out to be, the change is
> contained: an `accessToken()` method beside `request()`, exactly as bol and eBay have.

---

## Why it is different from bol and eBay

bol is a shop. eBay is a shop. Tradedoubler is **thousands of advertisers behind one endpoint**, and
its payload says so: a product carries a *list* of offers, one per advertiser selling it.

That shape is unusually kind to this codebase. `products` rows are offers and `product_groups` rows
are physical products (invariant 3) — and Tradedoubler hands that split over already made. One
payload product becomes several `Offer`s, each with its own merchant, sharing one barcode, so they
group together on arrival.

**It is the first source that delivers a real price comparison in a single request** rather than
assembling one over weeks of ingestion. bol contributes one price. eBay contributes one price and
usually no barcode. Tradedoubler contributes three shops for one product with an EAN on it.

## Why live and not ingested

Tradedoubler *does* publish feeds, and taking them would mean joining programmes one at a time and
running an ingestion job per programme. That is precisely Awin's shape, and Awin already occupies it
— a second per-advertiser feed pipeline buys nothing the first one does not.

The API is the whole network in one call, which is the part Awin cannot do.

## The fan-out, and the two bugs hiding in it

**Each advertiser's offer needs its own external id.** `products` is unique on
`(source, external_id, market)`, so the id is composite: `{productId}:{programId}`. Keying on the
product alone would make a product's advertisers overwrite each other, and the last one written would
masquerade as the only price — turning the one source that gives us a comparison into the one that
hides it.

**A duplicate inside one payload loses the whole batch.** `OfferUpserter` writes a search's offers in
a single upsert, so the same `(product, advertiser)` pair appearing twice does not waste a row: it
makes Postgres refuse the statement with `ON CONFLICT DO UPDATE command cannot affect row a second
time`, and every offer from that search is lost. bol hit exactly this on its first live chart run.
First sighting wins, which also keeps the network's own relevance order intact.

## The merchant is the advertiser

`merchantExternalId` is `td-{programId}`, `merchantName` is the program name — **the opposite call
from eBay**, where one merchant row stands for the whole marketplace.

The reasoning inverts because the merchants do. eBay's sellers are millions of individuals, and
naming them would make the shop directory meaningless. Tradedoubler's are retailers with names a
shopper recognises, and collapsing them into "Tradedoubler" would put a company that sells nothing on
the buy button — and throw away the entire point of the source, which is that the offers come from
*different shops*.

The id is prefixed `td-` because a Tradedoubler program id is a bare integer, indistinguishable from
an Awin advertiser id in a diagnostic and identical to one in a query that forgot to filter on source.

The domain comes from the advertiser's own product URL, never from `productUrl` — that is the
network's tracking link, and deriving a domain from it would give `tradedoubler.com`, and therefore
Tradedoubler's favicon, for every advertiser alike. The same trap Awin's `merchantDomain()` describes.

## Market scoping is the riskiest part

Tradedoubler spans every European market at once and **ignores a filter parameter it does not
recognise**. So a wrong scoping is not an error and not an empty list — it is a Belgian visitor being
shown German offers, in German, priced for delivery from Germany, with nothing anywhere reporting a
problem.

Scoping therefore lives in config as an *array of query parameters per market*
(`giftcoves.connectors.tradedoubler.query`), passed through verbatim:

| Market | Sent | Why |
|---|---|---|
| `nl-nl`, `be-nl` | `language=nl` | |
| `be-fr` | `language=fr` | |
| `en` | `language=nl` | No euro-market English catalogue — the same call bol and eBay make. |
| `es` | `language=es` | |

`language` is the opening bid, not the answer. **Program-id scoping is the real fix** once the
operator knows which advertisers they are joined to, exactly as `connectors.awin.advertisers` is for
Awin — and the config holds a whole parameter array so that move is an env change rather than a code
change. An empty or absent entry means the market is skipped outright; blank never means "ask
unscoped".

**The currency guard is what holds when the scoping is wrong.** Any offer whose currency is not the
market's is dropped, never converted. `products.price` has no per-row currency, so a converted number
enters the min and median aggregates behind "cheapest offer" at a rate nobody recorded — and 99
kronor stored as 9900 cents wins that badge outright. This matters more here than for eBay, because
here a mis-scoped query returns foreign listings *by design*.

## A 4xx is never retried

`retry(2, 200, …)` guards on the exception: a transport failure or a 5xx is retried, a 4xx is not.

The other connectors here retry unconditionally, which is harmless while their credentials work and
measurably not while they do not — a rejected token answers in milliseconds, and an unconditional
retry asks a second time for the same refusal on *every search*. Found by a test that counted
requests after the live 403 above: two calls, one answer, no possibility of a different one.

A `ConnectionException` is not a `RequestException` and carries no response, so a timeout still
retries, which is the case retry exists for.

The same over-eager retry is still in `BolConnector` and `EbayConnector`, deliberately left alone —
both are working and verified, and changing them is a separate, testable move rather than a
drive-by.

## Prices come from a history, most recent first

`priceHistory` is a list. Reading the wrong end of it quotes a price from weeks ago, which looks
entirely reasonable on a card and is wrong in the direction that gets noticed at checkout. `reset()`,
not `end()`.

## Stock: Unknown, not the bol inference

A missing availability field becomes `Unknown`, **not** `OutOfStock` and not `InStock`.

This is deliberately the opposite of bol's rule, where the presence of a price *is* the stock signal
because bol only returns products it can sell. A network advertiser's feed routinely carries priced
rows for things it has run out of, so a price here proves nothing — and showing a sold-out product as
available is the worse failure.

## Attribution: nothing to get wrong, for once

The token carries the affiliate id, so `productUrl` comes back as an already-tracked
`clk.tradedoubler.com` link. There is no campaign id to attach and no site id to configure — which
removes the invisible earn-nothing failure that both bol and eBay have.

A rejected token backs off for the full cooldown rather than being retried per search. Unlike bol and
eBay there is no cached token to discard — the credential IS the config value — so nothing changes
until somebody edits the environment, and an empty result is deliberately not cached. Without the
back-off every search would spend a request, and up to eight seconds of a visitor's page load,
rediscovering that the affiliate account is dead.

It moves the risk somewhere else. **The token is the payment credential**: anybody holding it can
attribute their own traffic to this account. And because the Open Product API takes it as a *query
parameter* rather than a header, it ends up in URLs — so nothing in the connector logs a request URL,
and `bc:check-tradedoubler` prints the token's length rather than any URL it built.

## Verifying it

```bash
php artisan bc:check-tradedoubler --market=nl-nl --raw
```

Prints the token length, the resolved market scoping, the response envelope's real keys, one real
product and one real offer field-by-field, then the parsed results with two summary numbers:

- **Distinct shops** — one shop across ten offers means `programName` is not being read, and the
  comparison this source exists for is not happening.
- **Offers with a barcode** — zero means these offers cannot join any group, and each is a lone card
  rather than a price beside bol's.

A successful HTTP call that parses zero offers is the specific failure this command exists to catch:
the request works and the field mapping does not. The connector also logs
`tradedoubler returned an unrecognised envelope` with the keys it actually received.

## Configuration

```
TRADEDOUBLER_TOKEN=              # Open Product API token, publisher interface
TRADEDOUBLER_LANGUAGE_BE_NL=nl   # per-market scoping overrides, all optional
```

`enabled` defaults to true and the connector is inert without a token (`supports()` requires it), so
this ships safely into an environment that has none — and, thanks to the back-off above, safely into
one whose token is *wrong*: the first search of a five-minute window spends one request discovering
that, and the rest of the window costs nothing.

Rate limiting is 5/s, burst 1, cooldown 300s — the same conservative numbers as eBay's, chosen for a
different reason: **Tradedoubler publishes no per-second limit at all**, which is not permission. It
means there is no documented number to size against and no way to know we have crossed it until
requests start failing, so a 429 gets a long back-off rather than a short one.

Two buckets (`search`, `product`) even though there is no known budget to split, because it is what
stops a busy hour of searching from starving a re-check, and it is the shape that lets a real limit
be tuned later without touching the class.

## `fetchById` is a search with an exact-match filter

The Open Product API has no single-product endpoint, so a re-check searches on the product id and
keeps only an exact external-id match. Anything else is discarded rather than accepted as close
enough: a search can return a *different advertiser's* listing of the same product, and a wishlist
item silently repointed at another shop's offer is worse than one that fails to refresh, because
nothing about it would look wrong.

## Adding a source is still a migration

`2026_09_02_000200_tradedoubler_is_a_source` — the second instance of the file
[ebay-connector.md](ebay-connector.md) predicted would be needed, and a deliberate copy rather than
an edit to that one: it has already run everywhere, so widening its constraint set in place would
change nothing on any existing database and would diverge a fresh clone from staging.

Seven tables carry a `CHECK (source IN (…))` frozen from `Source::values()` at their own migration
time. Rebuilding all seven from `Source::values()` keeps each such file idempotent and convergent.

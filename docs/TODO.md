# TODO

Work that is **written and merged but not yet proven**, and what unblocks each item.

This file is deliberately not a backlog of ideas. Everything here is code that already ships,
already has tests, and is one missing input away from being trustworthy — which is the most
dangerous state for a thing to be in, because it looks finished from every angle except the one that
matters. Delete an entry when it is verified; do not let it become a wishlist.

---

## 1. Verify the eBay API against a live account

**Blocked on:** an eBay **production** keyset (App ID + Cert ID) and at least one eBay Partner
Network campaign id.

**Status:** the connector, its 17 tests, the config and the migration are all in
([ebay-connector.md](features/ebay-connector.md)). It is inert until credentials exist —
`supports()` requires the OAuth pair — so nothing is broken meanwhile; eBay is simply absent from
search.

**What to provide**

```
EBAY_CLIENT_ID=          # App ID, PRODUCTION keyset — a sandbox keyset authenticates
EBAY_CLIENT_SECRET=      # against a different host and returns test data
EBAY_CAMPAIGN_ID_NL=     # EPN campaign; be-nl, nl-nl and en all read EBAY_NL
EBAY_CAMPAIGN_ID_FR=     # be-fr
EBAY_CAMPAIGN_ID_ES=     # es
```

**Then run**

```bash
php artisan bc:check-ebay --market=nl-nl
php artisan bc:check-ebay --market=be-nl   # the marketplace guess, see below
```

**What a pass looks like:** token exchange OK, a table of five results, and `Tracked link` = `yes` on
every row. A red `NO` in that column means the campaign id did not reach the request, and every
click will resolve, sell, and be attributed to nobody — the failure that only shows up months later
as an empty EPN statement.

**The specific thing to look at:** `be-nl` and `be-fr` are mapped to `EBAY_NL` and `EBAY_FR`, not to
`EBAY_BENL` / `EBAY_BEFR`. That is a judgement call, not a fact — Browse's marketplace coverage is
narrower than eBay's marketplace id list, and an unserved marketplace answers 200 with an empty body
rather than an error. If `bc:check-ebay --market=be-nl` returns results, the mapping is fine as it
stands; it is worth trying `EBAY_MARKETPLACE_BE_NL=EBAY_BENL` once to see whether the native
marketplace is better supply. Either outcome is an env change, not a deploy.

---

## 2. Verify the Tradedoubler API — and get a credential that works

**Blocked on:** a working Tradedoubler credential. **The one supplied is rejected.**

```
php artisan bc:check-tradedoubler --market=nl-nl
→ HTTP 403  {"message":"Invalid token, Request not Authorised","statuscode":"4001"}
```

**What to provide, and the question behind it:** the connector is built against the **Open Product
API**, which takes a single `token` from the publisher interface (Publisher → Product feeds) as a
query parameter. The value supplied was labelled `client_secret`, and Tradedoubler issues client
id/secret *pairs* for its OAuth APIs — a different product with a token exchange this connector does
not perform. So either:

- the Open Product API token, and this works as written; or
- the **client_id** that goes with that secret, in which case the connector needs an
  `accessToken()` method beside `request()`, exactly as bol and eBay have. Contained change, roughly
  an hour.

**Then run**

```bash
php artisan bc:check-tradedoubler --market=nl-nl --raw
```

**What a pass looks like** — and this one is not just "no error":

| Line | What it must say | Why |
|---|---|---|
| `Envelope keys` | includes `products` | A wrong key parses zero offers out of a perfectly good response |
| `Distinct shops` | 2 or more of N offers | One shop means `programName` is not being read, and the comparison this source exists for is not happening |
| `Offers with a barcode` | most of them | Without an EAN these offers cannot join a product group, so each is a lone card rather than a price beside bol's |
| `Tracked link` | `yes` on every row | |

**Why `--raw` matters more here than anywhere else:** this is the only connector in the codebase
whose field mapping has **never met a live response** — the 403 meant one could not be read. Its
field names come from Tradedoubler's documented shape and nothing more, and a wrong field name in a
connector fails *silently*: an empty list is indistinguishable from "the network has nothing for
this query". That is how the Awin barcode-column bug survived for weeks. `--raw` prints the real
envelope, one real product and one real offer field by field, so the mapping can be checked against
the thing itself rather than inferred from an empty result.

**Also worth a look once it answers:** market scoping is `language=nl` and friends, which is an
opening bid rather than an answer. Tradedoubler spans every European market at once and *ignores* a
filter parameter it does not recognise, so a wrong scope shows Belgian visitors German offers with
nothing anywhere reporting a problem. Program-id scoping is the real fix once you know which
advertisers you are joined to — the same shape as `connectors.awin.advertisers`. The currency guard
(non-euro offers dropped, never converted) is what holds meanwhile.

---

## Not blocked, but noted while doing the above

- **`BolConnector` and `EbayConnector` retry a 4xx.** `TradedoublerConnector` does not any more: a
  rejected credential answers in milliseconds and an unconditional retry asks a second time for the
  same refusal on every search. The other two are working and verified, so they were left alone
  deliberately rather than changed in passing — but the same guard belongs in both, and it is a
  small, testable change.
- **`fetchById()` has no callers.** All three live connectors implement it because `LiveConnector`
  requires it, and no re-check job exists for any source. It is where a wishlist item would get a
  fresh price — and, for eBay, the only place a barcode can come from.

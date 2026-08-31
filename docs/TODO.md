# TODO

Work that is **written and merged but not yet proven**, and what unblocks each item.

This file is deliberately not a backlog of ideas. Everything here is code that already ships,
already has tests, and is one missing input away from being trustworthy — which is the most
dangerous state for a thing to be in, because it looks finished from every angle except the one that
matters. Delete an entry when it is verified; do not let it become a wishlist.

---

## 1. Verify the eBay API against a live account

**Blocked on:** an App ID / Cert ID pair that eBay actually accepts, plus at least one eBay Partner
Network campaign id.

**Status (tested 2026-08-31): a keyset was supplied and eBay rejects it.**

```
php artisan bc:check-ebay --market=nl-nl
→ HTTP 401  {"error":"invalid_client","error_description":"client authentication failed"}
```

The paste is not the problem, which is worth recording so nobody re-checks it: both values are
structurally correct production credentials — App ID `<user>-<app>-PRD-<hex>-<hex>` (40 chars), Cert
ID `PRD-<hex>-<hex>-<hex>-<hex>` (36 chars), no whitespace, no quotes, not swapped, neither carrying
`SBX`. The token request itself is also not implicated: `bc:check-ebay` makes that call directly
rather than through the connector, so the 401 is eBay refusing the pair, not our code mis-sending it.

**Cause found: the application is marked `non compliant` in eBay's developer portal**, because it had
no Marketplace Account Deletion endpoint. A non-compliant keyset does not mint production tokens,
which is the whole of the `invalid_client` above — the credentials were never the problem.

That endpoint now exists: `/webhooks/ebay/account-deletion`, 11 tests, verified end to end against
the local dev server ([ebay-account-deletion.md](features/ebay-account-deletion.md)).

**So the remaining work is a sequence, and the order matters** — eBay validates the endpoint the
moment it is saved in the portal, so the route has to be live first or the challenge hits a 404 and
the application stays non compliant:

1. **Deploy** to the host being registered. Until then the endpoint does not exist in the running
   build.
2. Set `EBAY_DELETION_VERIFICATION_TOKEN` and `EBAY_DELETION_ENDPOINT` on that Coolify app. The
   endpoint must be the exact URL to be registered — it is an input to the challenge hash, so www
   and non-www are different answers.
3. Self-test with the `curl` in the feature doc **before** touching the portal, so a failure there
   means eBay rather than us.
4. Register endpoint + token in the portal (Alerts and Notifications → Marketplace Account
   Deletion). The "non compliant" label should clear.
5. Re-run `bc:check-ebay`. If it still answers `invalid_client`, then and only then is it worth
   suspecting the keyset itself — an unaccepted API License Agreement, or a Cert ID regenerated
   after being copied.

Meanwhile nothing is broken: `supports()` requires the pair, so eBay is simply absent from search,
and the connector's own 17 tests pass ([ebay-connector.md](features/ebay-connector.md)).

**Campaign ids are still blank**, so even once authentication succeeds every eBay click will earn
nothing until they are set. That failure is invisible from the site — see the `Tracked link` column
below.

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

## 3. Read the UX and the layout on a real phone

**Blocked on:** somebody opening `staging.giftcoves.com` on a handset. Nothing else — this is the
one item here whose missing input is a person rather than a credential.

**Status:** everything below ships and is tested. What is untested is what it *looks* like at 390px,
and no test in this suite can answer that: the frontend has no visual regression coverage, `tsc`
type-checks the props and the PHP suite asserts the props arrive. A layout that wraps into nonsense
passes all of it.

**The specific things to look at**, newest first — these are the changes most likely to be wrong on
a phone, not a general invitation to browse:

| Where | The risk |
|---|---|
| Five picker rows that gained a scan button — the add-to-list panel, the suggestion box on a shared list, the picks picker on an answer, self-describe, the discovery dial | Each row is now `[field] [scan] [submit]` inside a `flex-wrap`. At 390px the field has to stay usable with two buttons beside it; the failure mode is a one-word-wide input above two orphaned buttons. The add panel is the tightest, because it also lost its Cancel and nothing rebalanced the row afterwards. |
| The Amazon CTA in the search and brand rails | **The rail is `hidden` below `lg`**, behind the Filters toggle — so on a phone the CTA is inside a collapsed panel, which is close to not being there. The empty state carries its own copy and is fine; the resting page is the question. Possibly it belongs somewhere else entirely on small screens. |
| The Amazon CTA on a product page | Accent fill, favicon, arrow, directly under the barcode. It is deliberately loud, and on a narrow column loud is louder — check it does not read as the page's primary action next to the shops we actually carry. |
| The long product description | Up to 1800 characters of somebody else's marketing copy in paragraphs, below the offer table. Fine on a desktop column; on a phone it is several screens. Consider whether it wants a fold. |
| The home page's first screen | `home.intro` was deleted, which should have *helped* — the search field moved a paragraph up. Worth confirming that is what actually happened rather than assuming it. |
| The feedback form | New page, never seen on a handset. A 7-row textarea plus two inputs. |

**What a pass looks like:** on a 390px viewport, `document.body.scrollWidth` is 390. Sideways scroll
on the body is the one failure this codebase has hit before and written down — see the `min-w-0`
comment in `Search.tsx`, where a grid item's default `min-width: auto` grew a track to the width of
every lane laid end to end and the body scrolled instead of the strip. Measured at 1204px before the
fix. Any new horizontal scroll is that bug again somewhere else.

---

## Not blocked, but noted while doing the above

- **`BolConnector` and `EbayConnector` retry a 4xx.** `TradedoublerConnector` does not any more: a
  rejected credential answers in milliseconds and an unconditional retry asks a second time for the
  same refusal on every search. The other two are working and verified, so they were left alone
  deliberately rather than changed in passing — but the same guard belongs in both, and it is a
  small, testable change.
- **eBay notification signatures are not verified.** Each account-deletion POST carries an
  `x-ebay-signature` header, checkable against a public key from eBay's Notification API — which
  needs an application access token, which a non-compliant keyset will not issue. Circular, so it is
  deferred until tokens mint, at which point it can be written against a real signed payload rather
  than a guess. It buys little meanwhile: the handler takes no action, so a forged notification
  achieves nothing beyond a log line carrying no personal data.
- **`fetchById()` has no callers.** All three live connectors implement it because `LiveConnector`
  requires it, and no re-check job exists for any source. It is where a wishlist item would get a
  fresh price — and, for eBay, the only place a barcode can come from.

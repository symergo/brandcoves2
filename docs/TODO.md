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

That endpoint now exists: `/webhooks/ebay/account-deletion`, tested, verified end to end against
the local dev server ([ebay-account-deletion.md](features/ebay-account-deletion.md)).

**Remaining work:** the setup in
[ebay-account-deletion.md](features/ebay-account-deletion.md#setting-it-up) — deploy before
registering. If `bc:check-ebay` still answers `invalid_client` after the portal clears "non
compliant", only then suspect the keyset itself (an unaccepted API License Agreement, or a Cert ID
regenerated after being copied).

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

**Why `--raw`, and what to check after it answers** (market scoping, the currency guard):
[tradedoubler-connector.md](features/tradedoubler-connector.md). Its field mapping has never met a
live response.

---

## 3. Read the UX and the layout on a real phone

**Blocked on:** somebody opening `staging.giftcoves.com` on a handset. Nothing else — this is the
one item here whose missing input is a person rather than a credential.

**Status (2026-09-07):** rendered at 390px with Playwright against the dev server — see the phone pass in
[features/design-system.md](features/design-system.md). `document.body.scrollWidth` was 390 on every page except two, both fixed.
What a script cannot judge is whether it *looks* right, so the handset check still stands.

**Status before that:** everything below ships and is tested. What is untested is what it *looks* like at 390px,
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
  same refusal on every search. bol is working and verified; eBay has never authenticated (see
  above), so both were left alone deliberately rather than changed in passing — but the same guard
  belongs in both, and it is a small, testable change.
- **eBay notification signatures are not verified.** Deferred until tokens mint; see
  [ebay-account-deletion.md](features/ebay-account-deletion.md#signature-verification-is-deferred-and-the-reason-is-circular).
- **Drop `list_invitations`, and `guide_topics.last_attempt_at` / `attempts`, in the release after
  2026-09-14's.** The code behind both went that day: the invitation redemption path (production's
  table held no rows) and the "recently attempted" topic rule (nothing had written an attempt since
  the one-guide-at-a-time builder went). They stayed one release on purpose, because the previous
  build reads them — the invitations table on every sign-in — so dropping them in the same release
  would break that build on a rollback. Once the 2026-09-14 build has been live and nobody has needed
  to roll back, one migration can `Schema::dropIfExists('list_invitations')` and drop the two
  columns. Take `last_attempt_at` and `attempts` out of `ContentEnvelope`'s `topics` exclusion list
  in the same change. That is the contract half of expand/contract; delete this entry when it ships.
  See [list-taxonomy.md](features/list-taxonomy.md) and `App\Models\GuideTopic`.
- **The wishlist refresh passes a bolProductId to bol.** `RefreshWishlistedProducts` calls
  `fetchById($product->external_id)`, bol's product endpoint takes an EAN only, and
  `BolConnector::fetchById()` returns null for anything else — so no bol offer is ever refreshed
  there. Pass `products.ean` to `fetchByEan()`. See [page-import.md](features/page-import.md).
- **Drop `copy_templates`.** Unread since page templates replaced the copy bank; see
  [page-templates.md](features/page-templates.md).

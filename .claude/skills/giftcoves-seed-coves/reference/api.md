# Endpoint contract

Orientation only. `GET /api/editorial` is authoritative and describes the same thing
from the server it is actually talking to.

Hosts, both serving the identical API:

    production   https://giftcoves.com/api/editorial        GET /health -> branch: main
    staging      https://staging.giftcoves.com/api/editorial GET /health -> branch: staging

`/health` also reports the deployed `migration`, which is the reliable field for "does
this host have that endpoint yet" — the build stamp is not.

Auth: `Authorization: Bearer $GIFTCOVES_API_KEY` on everything under `/api/editorial`.
Keys are issued per environment; a staging key answers 401 on production.

**Group ids are per environment and per market.** EAN `4548736132580` is group `3210`
on production and `3921` on staging. Resolve against the host you are writing to.

The trap is the other direction: group `3921` on production is an **ASUS Vivobook 16**
— a real be-nl product, in stock, priced, pictured, and accepted by every check the
server makes. An id from the wrong host is not an error, it is a different product.

No market prefix on any of these routes — the market is always an explicit parameter,
which is what stops a write landing in the wrong one by inheriting a default.

Markets: `be-nl`, `be-fr`, `en`, `es`, `nl-nl`.

## Abilities

| Ability | Unlocks |
|---|---|
| `editorial.read` | product lookup, topics, plans, queue, guides, editions |
| `editorial.write` | drafts — nothing here can reach a reader |
| `editorial.publish` | approve, build, publish; also editing an approved plan |

## Read

    GET /                              abilities, markets, endpoints, contract
    GET /products                      market (req), q, category, brand,
                                       minPriceCents, maxPriceCents,
                                       includeLive (0/1), limit (1..100, def 24)
    GET /products/{groupId}            adds the offers: merchant, priceCents, source
    GET /topics                        market (req), status (def candidate), limit
    GET /coves                         market, kind, status, from, to, limit
    GET /coves/queue                   market, kinds[], limit (1..20), horizon days
    GET /coves/{planId}
    GET /guides                        GET /guides/{id}
    GET /editions/{market}/{date}      future and unpublished included

`/products` returns only presentable groups: in market, in stock, priced, with an
image. Each carries `priceGuessEligible` (may it be the subject of the daily price
game) and `sources` (which programmes back it), so a limitation is learned at lookup
time rather than as a silently skipped pick at build time.

**Prices are integer cents everywhere.** `maxPriceCents: 50` means fifty cents.

## Write (drafts only)

    POST /coves                        create or upsert a plan
    POST /coves/{planId}/editorial     prose only; requires the plan's `revision`
    POST /guides                       a buying guide in the guides table

**`POST /coves/drafts` does not exist on any host.** Probed against production
2026-08-29: **405**, "Supported methods: GET, HEAD" — the wildcard `GET /coves/{plan}`
is all that is registered there. `CoveDraftController`, `PlanDrafter`, `DraftedPlans`
and `PlanSlugs` are untracked files in a local working tree, and the `routes/api.php`
line that would register them is an uncommitted diff. Nothing was deployed, so nothing
answers. Use `GET /topics` and `GET /coves/queue` and write the titles yourself.

## Publish

    POST /coves/{planId}/approve       {"build": true} to queue the build too
    POST /coves/{planId}/build
    POST /guides/{id}/publish          {"unpublish": true} to reverse
    POST /editions/{market}/{date}/build

## POST /coves — the full body

```jsonc
{
  "market": "be-nl",                  // required
  "kind": "daily",                    // daily|persona|guide|seasonal|advice|shop
  "date": "2026-09-14",               // daily only, YYYY-MM-DD; may be omitted
                                      // entirely for an undated idea
  "slug": "voor-de-hondenliefhebber", // every other kind; alpha_dash, max 80
  "title": "…",                       // required, max 120
  "blurb": "…",                       // max 300 — it becomes the meta description
  "editorial": "…",                   // max 4000; the article for daily/persona.
                                      // Set, the builder uses it verbatim and
                                      // never calls the model.
  "pickMode": "locked",               // open (default) | locked. locked publishes
                                      // exactly the shortlist.
  "queries": ["hondenmand"],          // max 12, max 60 chars; product words
  "buildInstructions": "…",           // max 1000; direction for the whole piece

  // Article kinds only (guide, seasonal, advice) — refused elsewhere:
  "focusKeyphrase": "…",              // max 120
  "metaDescription": "…",             // max 160
  "body": "…",                        // max 20000
  "faq": [{"question": "…", "answer": "…"}],   // max 10, both halves required

  // Seasonal only — refused elsewhere. MM-DD, year-less; an end before its
  // start wraps the year.
  "seasonFrom": "11-15",
  "seasonTo": "12-27",

  "items": [                          // max 24, ordered — position is curation
    {"groupId": 8412, "note": "the only one with a real grinder",
     "verdict": "best overall"},
    {"source": "amazon", "externalId": "B0…"}   // only for a source whose
                                                // catalogue may not be mirrored
  ],
  "pinnedGroupIds": [8412, 5190],     // legacy flat form, still accepted
  "note": "…"                         // max 1000, curator note
}
```

Every item needs either a `groupId` or both a `source` and an `externalId`. A source
that is in the catalogue must be sent as a `groupId` — storing it by external id
would make a second, unlinked copy of a product the site can already compare.

`note` is a brief to whoever writes the prose and is never shown to a reader.

### Response

`201`/`200` with the stored plan and:

```json
"linkCheck": { "links": 1, "unresolved": ["product:999999"] }
```

Advisory on a plan: the final allowlist also includes the finds the builder picks at
build time, which do not exist yet. Reported anyway, because telling an author a link
is fine when it might not be is the failure that matters.

## POST /coves/{id}/editorial — prose only

Narrower than `POST /coves` deliberately: that one replaces the item list wholesale,
so an agent sending only words there can empty a curated shortlist. This cannot touch
membership or rank.

```jsonc
{
  "revision": "…",                    // required; from GET /coves/queue
  "title": "…", "blurb": "…", "editorial": "…", "body": "…",
  "metaDescription": "…",
  "faq": [{"question": "…", "answer": "…"}],
  "items": [{"id": 123, "copy": "…", "verdict": "…"}]   // cove_plan_item ids
}
```

## Resolving an EAN (not part of this API)

    GET https://<host>/<market>/scan/<ean>       public, no auth, 120/min

```json
{"status":"found","gtin":"8712345678901","title":"…","price":2999,
 "url":"/be-nl/p/8412/de-titel"}
```

The group id is the third path segment of `url`. `not_found` and a `422` for a failed
check digit are the other two answers. GTIN-8, UPC-A (12) and ITF-14 are all
normalised to GTIN-13 before the lookup, so a camera read of an American product
still resolves.

Coverage is EAN-grouped products only: a feed row with no barcode is grouped by brand
and title instead, and the scan cannot see it even though the site holds it.

## Rate limits

Keyed by token, not IP. Reads 120/min, writes 20/min. Unauthenticated callers fall
back to the address, which is all they have.

## Amazon

There is no Amazon connector in this codebase — only the config keys and the
compliance rules. Amazon forbids mirroring title, price, image and availability, so
an Amazon product cannot be displayed until something re-fetches those live at
render. `GET /` reports this in its `sources` block. What can be written today is
advice *about* shopping on Amazon, which needs no product data.

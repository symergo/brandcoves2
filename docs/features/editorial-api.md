---
name: The editorial API
area: Content / Operations
status: Active
date_added: 2026-08-09
---

# The editorial API

**Machine access to the writing surfaces — Daily Coves and buying guides — over HTTP, with a
revocable key instead of a shell.**

`/api/editorial/*`, bearer-authenticated, no session and no market prefix.

## Why it exists

The Daily Cove has an editorial calendar, a plan table and an admin panel, and every one of them
assumes the author is a person sitting in front of a browser. Writing a Cove any other way meant SSH
and tinker, which is the wrong tool in three separate directions: it is the most privileged access
the box has, it leaves no record of who wrote what, and it cannot be handed to an automated writer
without handing over the whole server.

A key that may draft an article is a far smaller thing to give away than root. That is the trade this
feature makes.

## The shape

Three ability strings, not roles:

| Ability | What it unlocks | Why separate |
|---|---|---|
| `editorial.read` | Product lookup, ripe topics, plans, guides, published editions | The grounding calls. Useful on their own, safe on their own. |
| `editorial.write` | Create and rewrite drafts | **Nothing in this group can reach a reader.** |
| `editorial.publish` | Approve a plan, publish a guide, queue a build | The calls that put something in front of people. |

A role called "editor" would collapse write and publish the first time anyone needed the safer
variant. The interesting configuration — an automated writer that drafts, a human who approves — is
only expressible because they are two strings.

### Getting a key

**In the admin panel** — *Operations → API keys → Mint a key*. Two modals: the first collects the
name, abilities and expiry, the second reveals the plaintext with a copy button.

They have to be two modals rather than a form and a success toast. The secret exists exactly once,
and a notification that any stray click dismisses is the wrong container for something
unrecoverable — so the reveal refuses to close on a click-away or an Escape, and
`replaceMountedAction` swaps the mint for it rather than closing.

Minting is the only special case. Everything after it is ordinary: change a key's abilities without
rotating the secret (the realistic path is a key that drafted for a fortnight and has earned
publish — and rotating it to say so means editing it wherever it is deployed), revoke, and delete
only once revoked.

**On the command line**, which is what you want in a deploy script or when the panel is not up yet:

```bash
php artisan bc:api-token "claude editorial"            # read + write. Drafts only.
php artisan bc:api-token "claude" --abilities=editorial.read,editorial.write,editorial.publish
php artisan bc:api-token --list
php artisan bc:api-token --revoke=3
```

Both paths call `ApiToken::issue()`, so a panel key and a command key are the same thing. A test
asserts that, because "the panel is decorative" is a failure that would otherwise show up only when
someone tried to use what it produced.

The plaintext is printed once. Only its SHA-256 is stored, for the same reason as `login_tokens`: a
database leak should yield a list of names and timestamps, not working keys. Revocation is a
timestamp rather than a delete, because during an incident the useful question is *when did this stop
working*, and a deleted row cannot answer it.

## The grounding problem

This is the part that decides whether the output is worth publishing.

A writer with no catalogue access does not decline to name products. It invents them — confidently,
in the right format, with plausible brands. So the API is built so that **an author can only
reference ids that came back from `/products`**. Every id is validated against the market before a
write is accepted, and a bad one fails the *whole* write rather than being dropped:

> an article whose second pick silently vanished is an article with a dangling sentence

`/products` returns only presentable groups — in stock, priced, with an image — plus the compliance
flags that decide where a product may appear. `priceGuessEligible` is there so an author learns at
lookup time that a product cannot carry the daily price game, rather than discovering it as a
silently skipped pick at build time.

`/topics` answers "what should I write about" with evidence: clusters of queries visitors actually
typed into this site. A guide written against one of those has an audience before it is published,
which is the entire reason guides rank.

### A barcode is a lookup

`GET /products?market=…&ean=4548736132580` resolves a barcode against the `(market, identity_key)`
unique index — the same one hit the shopper path takes, normalised through `Gtin::normalise()` so a
12-digit UPC-A or a 14-digit ITF-14 finds the GTIN-13 the catalogue stores.

It needs saying why this is a separate parameter rather than something `q` should have handled.
`/products?q=` is full-text against `products.search_vector`, and that vector is title A / brand B /
category C / description D. **No EAN is in it.** So an author holding a list of barcodes — the most
natural way to hand over a shortlist, and the way a merchant's own catalogue export is keyed — got an
empty array back, which reads as "we don't stock it" rather than "you asked the wrong way". Until
this existed the route through was the *public* `/{market}/scan/{barcode}` endpoint followed by
parsing a group id out of the URL it returned, which is what the seed skill spent most of its words
on.

Three outcomes, and telling them apart is the point:

| Reply | Means |
|---|---|
| `count: 1` | found — exactly one, because `(market, identity_key)` is unique |
| `count: 0` | no EAN-grouped product in this market |
| **422** | the barcode failed its check digit |

The 422 is deliberate rather than an empty list. A failed check digit is a **misread, not a miss**,
and an author told "not found" would go looking in another market, or worse, search by name and pin
something else.

**Ids are per environment as well as per market.** Measured on 2026-08-29, one barcode in one market:
`4548736132580` is group `3210` on production, `3921` on staging and `21214` on a local dev database.
Ingestion order assigns them and nothing reconciles them, so an id resolved on staging and written to
production names a real, in-stock, perfectly usable *different product* — the one class of mistake
`rejectUnusable()` cannot catch, because nothing about the row is wrong. Resolve against the host you
are writing to, every time. A barcode is the same number everywhere; an id is not, which is the
strongest argument for preferring EANs in a brief.

Two limits worth naming rather than discovering. Coverage is **EAN-grouped groups only** — a feed row
with no barcode is grouped as `brand|normalised-title` instead and its `identity_key` is not a GTIN,
so the site holds a product this cannot see. And `includeLive=1` alongside `ean=` asks bol first and
then re-reads the catalogue in the same request, so a product nobody has ingested can be fetched,
grouped and found in one call.

### Where products come from

`/products` reads the catalogue, which is the Awin feeds. That was a silent limit: an author writing
"the four best kitchen scales" was writing "the four best kitchen scales **that happen to be in an
Awin feed**", and nothing in the response said so.

`includeLive=1` also asks the live sources — bol today. It does not return a second class of result.
The live offers go through the path a shopper's search already uses: `SearchService` pulls them,
ingests them via the ordinary `OfferUpserter`, and groups the new arrivals so an incoming bol offer
joins an existing card as another shop. What comes back is an ordinary product group with an ordinary
id, comparable, linkable, and reachable through `/go/` — **carrying bol's partner affiliate URL**,
because it came in through the same door as every other offer.

Reused rather than reimplemented for the reason that matters most: a second path into the catalogue
would be a second implementation of the identity rules, and that is exactly where a wrong merge would
come from.

Off by default because it costs an upstream call and most lookups are answered by the catalogue. Each
product reports its `sources`, so "also on bol" is a fact an author can check rather than assume.

### Amazon is not connected

Stated here because the alternative is a writer trying, getting nothing, and quietly writing about
something else.

There is no Amazon connector in this codebase — only the config keys, the `AmazonProduct` decision
table and the compliance rules in `Source`. The blocker is not the editorial side. Amazon forbids
mirroring title, price, image and availability, so an Amazon product cannot be *displayed* at all
until something re-fetches those live at render, and that needs verified PA-API credentials.

`GET /api/editorial` reports this in its `sources` block, so a client learns it from the server rather
than from this file. What an author *can* write today is advice **about** shopping on Amazon, which
needs no product data at all — see below.

## Links: tokens, never URLs

Prose written through this API uses the same contract the AI path uses, and for the same reason —
see [CoveMarkup](../../app/Services/Guides/CoveMarkup.php). The author writes
`[[product:1234|the odd one]]`, `[[brand:Sony]]`, `[[search:draadloze koptelefoon]]`; the renderer
resolves them against an allowlist and strips anything else back to plain text.

The safety property is that **a hallucinated link becomes an unlinked phrase**, not a 404 in the
middle of an article. The cost is that a writer cannot tell the difference between a link that worked
and one that quietly did nothing — so every write returns a `linkCheck`, and the edition read-back
returns the authoritative one:

```json
"linkCheck": { "links": 1, "unresolved": ["product:999999"] }
```

For a plan this is **advisory**: the final allowlist includes the finds the Serendipity Engine picks
at build time, which do not exist when the plan is written. A token naming a product outside the
curated shortlist may still resolve later. It is reported as unresolved anyway, because that is what is known now, and
telling an author a link is fine when it might not be is the failure that matters.

### Linking to the rest of the site

Two token kinds exist for destinations that are ours rather than a feed's, and they are what stop an
article being a leaf:

    [[guide:beste-koptelefoons]]   → /{market}/guides/beste-koptelefoons
    [[page:gift-whisperer]]        → /{market}/gift

`guide` is allowlisted like everything else, from **published guides in this market, excluding the
one being rendered** — a link to a draft is a 404 for a reader and an indexed dead end for a crawler,
a slug that exists in `be-nl` need not exist in `es`, and an article linking to itself is a loop.

`page` is not allowlisted per article. Those destinations are enumerated in
`giftcoves.linkable_pages`, they are identical in every market, and the config *is* the allowlist —
a per-article copy would be the same list every time. Adding a page there is the only step needed to
make it linkable; `EditorialLinkTest` resolves every entry against the router so a renamed route
fails the build rather than the page.

Guides used to reject tokens outright, because the page rendered plain text and a token would have
been *printed* at the reader. Both halves are fixed: guide prose now renders through `CoveMarkup`
like a Cove's, and `CoveMarkup::plain()` flattens tokens to their labels for the places that are not
HTML — a `<meta>` description, a FAQPage answer in JSON-LD, a card blurb in the listing. A crawler
reads an `acceptedAnswer` literally, so an anchor tag in one is markup in a field that expects prose.

## Two kinds of article

`guides.kind` is `buying` or `advice`, and it decides one thing: whether a product shortlist is
required.

A **buying** guide is a ranked shortlist — "the five best X, and the one actually worth it". The
products are the substance and the prose is presentation, which is why it needs at least three.

An **advice** article has no shortlist. "How to tell a paid review from a real one", "what a good
returns policy looks like", "how to shop safely on Amazon". The prose *is* the substance, and
demanding products would either block the piece or pad it with things the writing is not about.

One table rather than two, because everything else is identical — slug, market, status, meta, FAQ,
freshness, the same URL space, the same sitemap entry. A separate table would duplicate a dozen
columns to express one integer.

Two rules follow the kind rather than the item count, and both would be bugs the other way round:

- **`noindex` on an empty shortlist applies to buying guides only.** A buying guide whose products
  all went out of stock is a thin page. An advice article has none by design and is the most
  indexable thing the site publishes — the same rule would `noindex` exactly the pages written to
  rank.
- **No `ItemList` JSON-LD without items.** An empty one asserts that the page ranks nothing, which is
  worse than staying quiet.

## Asking the planner for ideas

```
POST /api/editorial/coves/drafts   {"market": "be-nl", "kind": "guide", "count": 10}
```

The first call of a writing run, and the one that decides whether the run is worth making.

An agent asked to think of ten guide topics will think of ten plausible ones. This site already
knows which ten are worth writing: `GET /topics` exposes the phrases people typed into its own
search box, with how many products exist to answer each. That is a demand signal no model has and
no competitor can measure, and until this endpoint the only way to act on it was a per-row button
in the admin panel.

Each kind draws on the source that knows something about it, and nothing else:

| Kind | Where the ideas come from |
|---|---|
| `daily` | the observance calendar — the next themed days with no plan yet |
| `guide` | the mined topic queue, most demand first |
| `seasonal` | the seasonal calendar, soonest window first: a season is only useful if the page is indexed *before* it opens. **`count` means seasons, not plans** — each one comes back as several dated parts, one per subject it names |
| `persona` | one per gift-wizard interest, carrying that interest's own product nouns from `AngleMap` |
| `advice` | nothing. **422 with the reason** |
| `shop` | nothing. **422 with the reason** |

Every plan comes back as a `draft` with a shortlist of real, in-stock, priced products already on
it — the same selection the builder would have made — so the next call has ids it may link to
without a search per product.

A seasonal request can therefore return more plans than were asked for, and can return fewer seasons:
one the catalogue cannot fill a single part of is skipped rather than fatal, and `shortfall` says
which of the two reasons applies — an exhausted queue is fixed by mining more topics, a thin
catalogue is not. See [seasonal-series.md](seasonal-series.md).

**`shortfall` is the field a scheduled caller must read.** Fewer plans than asked for is normal:
the topic queue runs dry, every interest already has a persona. A bare count cannot distinguish
"the source is exhausted, stop asking" from "the request failed, retry", and one of those is an
infinite loop. `shortfall` says which, in a sentence, and names the command that would produce
more.

Refusing `advice` and `shop` with a 422 rather than an empty 200 is the same decision. Nothing in
the data suggests an advice article — it is an opinion about how to shop, not a topic a catalogue
can propose — and a Shop Cove is seeded from the repository by `bc:seed-shop-coves` with no builder
that reads a plan. An agent told that writes the titles itself; an agent handed a zero retries
forever.

### The loop

`GET /api/editorial/` describes it, so a client never has to be told out of band:

1. `POST /coves/drafts` — ask for ideas. Skip it when you already know what to write.
2. `GET /coves/queue` — the briefs: shortlist, curator notes, link allowlist, revision.
3. `POST /coves/{id}/editorial` — the prose, quoting that revision.
4. `POST /coves/{id}/approve` with `build=1` — needs `editorial.publish`. Without it a person
   approves in the panel, which is the intended shape.

Nothing in steps 1–3 can reach a reader, and none of it costs AI spend on this server: the prose is
written on the caller's side of the wire. See [ai-invariant.md](ai-invariant.md).

## Writing a Cove

The API writes a `cove_plans` row, never an edition directly.

That split already existed for the editorial calendar and it is exactly what an external author
needs. A plan can be written days ahead, reviewed, revised and rejected, and the builder still
decides whether the catalogue can carry it on the day. An API that wrote editions directly would be
an API that can publish a three-product page because a feed had a bad night.

```
POST /api/editorial/coves          → draft
POST /api/editorial/coves/{id}/approve  {"build": true}
GET  /api/editorial/editions/{market}/{date}
```

Upsert on `(market, date)` for a Daily, and on `(market, slug)` for everything else, because a
client retrying after a timeout must not get a constraint violation for work it already did.

### Every kind, addressed the way that kind is addressed

`POST /coves` began as the Daily's endpoint and grew a persona. Every kind added after that —
`guide`, `seasonal`, `advice`, `shop` — arrived here silently addressed as a Daily: the handler
named `CoveKind::Persona` explicitly and everything else fell to the `default` arm, so a buying
guide POSTed with a slug was stored **with a date and no slug at all**, and answered `201`.

It now asks the enum, which is the same question `CoveKind` answers for the router, the sitemap and
the planner:

- a `daily` is addressed by its `date`; sending a `slug` is refused, because the slug of a Daily
  comes from its title at build time
- every other kind is addressed by its `slug`; sending a `date` is refused, and omitting the slug
  is refused rather than stored as an unreachable page
- **one slug namespace per market covers every kind.** A slug another kind already holds is a 422,
  never an upsert — the upsert would silently change what an existing page *is*: its URL space, its
  layout and its product floor at once

The article kinds accept the parts of a piece that are decided before it is written —
`focusKeyphrase`, `metaDescription`, `body`, `faq`, and `seasonFrom`/`seasonTo` on a seasonal
guide. A seasonal plan also **reads back** a `series` object (`key`, `part`) and a `date`: a season is
laid out as a series of dated parts, and "part 2" is a fact about what the writing may assume the
reader has already seen. Null on a season the catalogue could fill only one subject of — that is a
page rather than a series and carries no number anywhere. Left empty the builder writes them; filled they survive every rebuild. Sent with a kind that
has no use for them they are **refused, not dropped**: an author who sends a FAQ with a persona and
receives a 200 has every reason to believe it was stored, and finds out when the page renders
without one.

### Building one

`POST /coves/{id}/build` dispatches `BuildCove`, which reads the kind off the plan. It used to name
`BuildDailyEdition` and `BuildPersonaCove` individually, so approving a guide with `build=1`
answered `202` and queued nothing at all — the failure mode this API is most prone to, because
every step of it looks like it worked.

A Daily still goes through `BuildDailyEdition`, because that job also mines yesterday's searches
for topics and seeds the seasonal ones. Both are facts about the *day*, and an editor building next
Tuesday should not advance the topic queue.

`readBack` follows the kind too: a Daily points at the API endpoint that reports what the builder
actually managed to put on the page, and every permanent kind points at its own URL.

### The plan says who writes it

`cove_plans.writer` is `builder` or `authored`, and it is the one question the build asks. It
replaced an inference from whichever fields happened to be filled — which got it wrong for an author
who sent a finished `body` and left the `blurb` to us, running the model over their article and
replacing their title, reported nowhere. See [cove-writer.md](cove-writer.md).

Nothing changes for a client that has been writing here for months: **sending prose still means you
wrote it.** `POST /coves` sets `authored` when the body carries `editorial` or `body`, and
`POST /coves/{id}/editorial` sets it unconditionally. Send `writer: "builder"` to go the other way —
to file a first draft you want the model to finish.

### The prompt is served, not described

`GET /coves/{id}/brief` returns the assembled `system` and `user` messages the builder would send for
that plan, **prompt-bank override included**, plus the link allowlist, the shortlist with its notes
and card copy, the kind's product floor, and a `revision` you can quote straight back.

That last one matters on its own: `GET /coves/queue` lists only plans with **no prose yet**, so it
could never hand out a revision for the plan you wanted to *revise*. The only way back was the
whole-plan upsert, which replaces the shortlist.

Use it instead of working from a copy of the rules. Four hand-maintained copies of the writing
contract exist — the `writing` block below, `docs/publishing-guide.md`,
[scheduled-writing.md](scheduled-writing.md) and the seed skill — and they had already drifted apart:
the API root omits the one-paragraph-per-product rule that `ProseCards` exists to make undroppable.

One caveat, and it decides how you plan: the brief is exact for a **locked** plan, because the
shortlist *is* the edition. For an `open` one the engine tops the list up on the day, so the
allowlist may widen later. An authored Cove usually wants `pickMode: "locked"`.

### `items[].copy` is the card's sentence, not the curator's reason

`POST /coves/{id}/editorial` writes `items[].copy` into `cove_plan_items.copy`. It used to write it
into `note` — the reason a person chose the product — so an author posting back the sentence they had
just written destroyed the instruction that produced it, and the next rewrite was briefed with its
own output.

The two are read back separately, and only `copy` and `verdict` reach a reader.

### Authored prose wins outright, and skips the model

`cove_plans.editorial` is new. When it is set, `EditionBuilder` uses it verbatim and **never calls
the model** — not as a seed to rewrite, not as a fallback.

The reason it lives on the plan rather than on the edition: `daily_pick_sets.editorial` is an
*output*, rewritten on every build, and a build is routine — the scheduler retries, a redeploy
interrupts, an editor presses the button. Copy typed by an author has to survive that. Written on the
plan, a rebuild reproduces the article; written on the edition, the next rebuild silently replaces it
with a generated one.

A pleasant consequence: **a Cove written through this API costs nothing in AI spend**, because the
one part that used a model is the part the author supplied. See [ai-invariant.md](ai-invariant.md) —
nothing in any handler here touches `AiClient`, and builds are dispatched to the queue.

### The plan is linked but not consumed

The builder sets `cove_plans.edition_id` and deliberately leaves `status` alone. Marking it `used` is
what the column comment describes and would be a bug: `approvedFor()` matches `approved` only, so the
next rebuild of that date would not find the plan and would quietly replace the author's title and
prose with generated ones.

## Writing a guide

`POST /api/editorial/guides` takes a title, intro, body, FAQ, meta fields and a ranked list of
product ids. Items are required and the copy is not — the same principle `GuideBuilder` works to: the
shortlist is the substance, the prose is presentation. A guide with seven real comparable products
and no commentary is useful; commentary with no products is not a guide.

Items are rebuilt wholesale rather than diffed, because ranks are positional and a partial update
leaves a guide whose #3 is missing. Rank is array order — position is the argument a "best of" makes,
so it is the author's to decide.

Guides land as drafts; the public route filters on `published`. Rewriting an already-published guide
keeps it published, because guides are meant to be kept current and refusing would make the API
useless for the thing guides most need.

## What a write-capable key still cannot do

The sideways route into publication is the one worth naming: draft a plan, wait for a human to
approve it, then rewrite what it says. Editing an `approved` or `used` plan requires
`editorial.publish`. Without that rule the draft/approve split is decoration.

## Rate limits

Keyed by **token**, not by IP. By IP is wrong in both directions: two keys behind one CI runner would
throttle each other, and one key from a rotating address would never be limited. Unauthenticated
callers have no token and fall back to the address, which is all they have.

Reads are generous (120/min) on purpose — researching a Cove means looking at a lot of products, and
an author who finds lookup expensive starts guessing ids instead, which is the failure the lookup
exists to prevent. Writes are 20/min: each rewrites rows, and a writer stuck in a retry loop is the
realistic way this gets hammered. Both in `config/giftcoves.php`.

## Reading back

`GET /api/editorial/editions/{market}/{date}` shows future and unpublished editions, unlike the
public page. The reason the public route hides them — tomorrow's theme and finds leaking by URL — does not
apply to a holder of an editorial key, and an author building tomorrow's Cove needs to read it today.
It is also the only place the challenge answer is exposed.

`theme.source` is the field to check first when a Cove did not come out as written. `planned` means
the plan won; anything else (`observance`, `theme`, `ai`, `curated`) means it did not — and the most
likely reason is that nobody approved it.

## Briefing an automated writer

The full brief lives in **[../publishing-guide.md](../publishing-guide.md)** — the block to hand to
Claude, covering all three article types, the link vocabulary and the publishing flow.

The short version below is the orientation half of it, kept here because it is the part that has to
change when this file does.

```markdown
# Writing for GiftCoves

You write product-inspiration content for GiftCoves through its editorial API. You have no
shell access and do not need one.

    Base URL: https://giftcoves.com/api/editorial   (staging: https://staging.giftcoves.com)
    Auth:     Authorization: Bearer $GIFTCOVES_API_KEY

Read the key from the environment. Never paste it into a file, a commit or a message.

**Start every session with `GET /api/editorial`.** It returns your abilities, the markets, the
endpoint list and the writing contract. It is the source of truth; this brief is orientation.

## The one rule that matters

**You cannot name a product you have not looked up.** Search `/products?market=…&q=…` and use the
ids it returns. Do not guess an id, do not reuse one from memory, do not carry an id between
markets — the same product in another market is a different id with different offers, and mixing
them is a correctness bug, not a typo. A write containing an unusable id is rejected whole.

## How to write a Daily Cove

1. `GET /coves?market=…&from=…` — see what is already planned. Do not write over an approved plan.
2. `GET /products?market=…&q=…` — find real things. Look at more than you need.
3. `POST /coves` with `market`, `date` (YYYY-MM-DD), `title`, `blurb`, `editorial`, `items`,
   `queries`.
4. Read `linkCheck` in the response. `unresolved` lists tokens that will render as plain text.
   Fix them and POST again — the same date updates in place.

### `items`: the curated shortlist

The products the article is *about*, in the order the article follows, each with the reason it is on
the list:

```json
"items": [
  { "groupId": 8412, "note": "the only one with a real grinder", "verdict": "best overall" },
  { "groupId": 5190, "note": "cheap, and the writing should say why that is fine" }
]
```

`note` is a brief to whoever writes the prose — including a later `POST` from you — and is never
shown to a reader. Every plan tells the builder's model to cover **every** product, one per
paragraph; what curating adds is that they are covered *in your order*, with your reason for each.
The shortlist is a commitment and not a hint. See [cove-curation.md](cove-curation.md) and
[product-cards-in-prose.md](product-cards-in-prose.md).

An item may instead carry `source` + `externalId`, but only for a source whose catalogue may not be
mirrored (Amazon). Anything already in the catalogue has a `groupId`, and storing it by external id
would make a second, unlinked copy of a product the site can already compare properly.

A write **replaces** the shortlist rather than adding to it: a merge would make "remove the third
product" impossible to express, and a retry after a timeout would double the list.

`pinnedGroupIds` — a flat array of ids — is still accepted and written as items, so a key issued
before curation existed keeps working. Errors are reported under whichever field you sent.

### `buildInstructions`: how it should be written

Direction for whoever writes the prose, applied once to the whole article — "keep it short", "lean on
the nostalgia, not the tech". Distinct from an item's `note`, which is about one product, and from
`editorial`, which *is* the article and skips the model entirely. Sent as part of the brief rather
than as a rule, so it cannot loosen the constraints on prices and invented claims. Capped at 1000
characters: a brief long enough to be an article is an article.

### Writing a gift persona

A persona is a Cove with no date, served permanently at `/{market}/gift-ideas/{slug}`. Same call,
with `kind: "persona"` and a `slug` instead of a `date`; sending both is rejected rather than
reconciled, because a persona holding a date would be published as that morning's Daily Cove. Most
personas want `"pickMode": "locked"`, which publishes exactly the shortlist. See
[gift-personas.md](gift-personas.md).

Write in the market's language (`GET /api/editorial` lists them). `queries` are product words —
"hondenmand" finds products, "cadeau voor hondenliefhebbers" finds nothing.

`scene` names the drawing on the card — one of `App\Enums\CoveScene`. Optional, and omitting it
means the kind's default: `someone` (a featureless figure) for a persona, `article` for a guide or
an advice piece.

**Validated against the kind, not against the whole enum.** One column holds two vocabularies that
do not overlap — a persona names a *kind of person*, an article names a *subject* — so `customs` on
a persona is a **422**, and so is any scene at all on a Daily or a Shop Cove, which name none. The
error names the values that kind does take. This is stricter than it was: a scene used to be stored
on any kind on the argument that it was harmless there, which stopped being true the moment there
was a second vocabulary to be wrong in.

| Kind | May name |
|---|---|
| `persona` | `coffee` `cooking` `racing` `has_everything` `dog` `photography` `diy` `outdoors` `gardening` `plants` `music` `reading` `gaming` `fitness` `travel` `baking` `someone` |
| `guide` `seasonal` `advice` | `rights` `price_history` `seller` `reviews` `refurbished` `shop_check` `phishing` `customs` `gift_return` `missing_parcel` `article` |
| `daily` `shop` | nothing |

> **A scene the deployed server does not know is a 422, and that is a deploy gate.** The API checks
> against the enum in the running build and the database against the CHECK in the running schema, so
> content naming a new scene cannot be written until the code carrying it is live. Measured on
> 2026-09-05: 13 of 30 persona writes came back `The selected scene is invalid` against a host still
> running the previous nine. Deploy, then write.

Read it back on the plan to confirm what landed. See [cove-scenes.md](cove-scenes.md) for the
drawings and [gift-personas.md](gift-personas.md) for why it is a field rather than a lookup on the
slug.

> **Setting `scene` on a plan does not change a page that is already published.**
> `EditionBuilder::buildPersona()` copies it onto the edition at build time, so a live persona keeps
> the drawing it was built with until it is rebuilt — which is a separate call, and on an `open`
> persona also re-runs the ranker.

> **`POST /coves` replaces the plan, not just the fields you send.** To add a scene to a persona that
> already has prose, read the plan first and send it back whole with `scene` added — a body carrying
> only the scene blanks the editorial.

### Voice

Dry, specific, quietly amused. You are pointing at odd things and saying why they are worth a
second look. You are not selling.

**Shape: a short opening, then a paragraph about each product.** The frontend renders each product's
card directly under the paragraph whose copy names it — so the paragraph is the writing that product
gets, and the only writing it gets. A product no paragraph names appears as a bare card at the foot
of the page with nothing said about it. One product per paragraph: two in one stacks both cards
under it and reads as a caption for a pair, and only the first mention places a card. See
[product-cards-in-prose.md](product-cards-in-prose.md).

- Never state a price, a rating, or a claim about quality or stock. Prices move and the page
  renders live ones; a number in a sentence is wrong within a week.
- No "amazing", no exclamation marks, no rhetorical questions.

### Links

Never write a URL, a markdown link or an HTML tag. Link with tokens:

    [[product:1234|the odd one]]    [[brand:Sony]]    [[search:draadloze koptelefoon]]

Only the edition's own products, their brands and their categories resolve. Anything else is
silently rendered as plain text, which is why you must read `linkCheck`.

## How to write a buying guide

`GET /topics?market=…` first — those are clusters of queries real visitors typed, and a guide
against one has an audience the day it publishes. Then `POST /guides` with 3–12 items, each a
`groupId`, a short `verdict` ("best for small kitchens") and a sentence of `copy`.

Guides render as plain text: **no link tokens anywhere in a guide** — they would be printed to the
reader. Each item already links to its own product page.

## What happens to your work

Everything you write lands as a draft and waits for a person. That is the design, not a failure.
Do not try to route around it: a `403` means your key lacks that ability — say so and stop.

A `422` tells you exactly what was wrong. Fix it and retry; never drop the offending item to make
the request pass, because that leaves an article referring to something that is no longer in it.

If you can publish and do, read back with `GET /editions/{market}/{date}` afterwards and check
`theme.source`. `planned` means your plan won. Anything else means it did not — usually because
nobody approved it.
```

## Related changes

- `BuildDailyEdition` now takes an optional `Y-m-d`. It previously always built *today*, which made
  the admin panel's "Build now" button on a plan for next Tuesday appear to do nothing.
- The Cove calendar in Filament shows and edits the `editorial` field, because reviewing what an
  automated writer produced before approving it is the entire point of the draft/approve split.

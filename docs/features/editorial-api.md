---
name: The editorial API
area: Content / Operations
status: Active
date_added: 2026-08-09
---

# The editorial API

**Machine access to the writing surfaces (every kind of Cove, plus product display titles and gift
tags) over HTTP, with a revocable key instead of a shell.**

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
| `editorial.publish` | Approve a plan, publish a guide, queue a build, write a product's display title or gift tags | The calls that put something in front of people. |

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

A `guide` token without a label renders the guide's **title** as the anchor text (since 2026-09-08;
before that it rendered the slug, hyphens and all). A label still wins when one is given.

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

`POST /guides` takes `kind: buying | advice` (`App\Enums\GuideKind`), and it decides one thing:
whether a product shortlist is required. The row is stored in `daily_pick_sets` as a `guide` or
`advice` Cove.

A **buying** guide is a ranked shortlist — "the five best X, and the one actually worth it". The
products are the substance and the prose is presentation, which is why it needs at least three.

An **advice** article has no shortlist. "How to tell a paid review from a real one", "what a good
returns policy looks like", "how to shop safely on Amazon". The prose *is* the substance, and
demanding products would either block the piece or pad it with things the writing is not about.

Both are rows in `daily_pick_sets`, like every other Cove since the fold. `GuideKind` survives only
as this endpoint's vocabulary, because it carries the lower authored floor: three products, not the
builder's five.

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

Each kind draws on its own source; the table and the reasons advice, shop and brand are refused are
in [cove-planner.md](cove-planner.md#filling-the-planner-give-me-ten-more-of-these). Since
2026-09-18 `kind: brand` answers **422 with the reason**, like the other two; before that it was an
unhandled case and a 500.

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
can propose — and Shop Coves come from the repository (`bc:seed-shop-coves`), not from anything a
machine can propose. An agent told that writes the titles itself; an agent handed a zero retries
forever.

### The loop

`GET /api/editorial/` describes it, so a client never has to be told out of band:

1. `POST /coves/drafts` — ask for ideas. Skip it when you already know what to write.
2. `GET /coves/queue` — the briefs (shortlist, curator notes, link allowlist, revision), for the
   plans marked for an outside writer and still missing their prose. See
   [scheduled-writing.md](scheduled-writing.md).
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

- `cove_plans.writer` — sending prose sets `authored`; send `writer: "builder"` to go the other way.
  See [cove-writer.md](cove-writer.md).
- `GET /coves/{id}/brief` serves the assembled prompt and a `revision`; exact for a locked plan. See
  cove-writer.md.
- `items[].copy` is the card's sentence, `note` the curator's reason; they are separate columns. See
  cove-writer.md.

### Authored prose wins outright, and skips the model

`cove_plans.editorial` holds authored prose. When it is set, `EditionBuilder` uses it verbatim and **never calls
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

**Unlike `POST /coves`, this writes the page itself** rather than a plan the builder later reads.
Two things used to follow from that, and neither does now:

- **House style is applied.** Title, intro, body, FAQ question and answer, each item's copy and
  verdict, and the meta description all go through `HouseStyle` on the way in, exactly as the plan
  upsert and the item copy endpoint do. Prose keeps its `**` because `CoveMarkup` renders it; a
  title, a verdict, an FAQ question and a meta description lose theirs, because nothing renders
  those. See [house-style.md](house-style.md).
- **A plan is minted behind the page.** `CovePlan::recordFor()`, the same call the advice and shop
  seeders make, so the guide can be opened, re-curated, redone and rebuilt from the planner like
  every other kind. The plan is `used`, never `approved`: it records what was published rather than
  instructing the next build. Rewriting a live guide re-links that plan instead of minting a second
  one.

The slug is still derived from the title **as it arrived**, before house style touches it. A guide's
URL must not move because its punctuation was tidied, or the next rewrite would publish a second
page beside the live one.

Minting the plan also brought this endpoint under the **one slug namespace per market** rule that
`POST /coves` already enforced. It keys its own page on (market, kind, slug), so it used to accept a
slug some other kind was already using; `cove_plans_market_slug_idx` is (market, slug) with no kind
in it, so the plan behind that page cannot. A slug another kind holds is now a 422 naming the
conflict, checked before anything is written.

`POST /coves` with `kind: guide` or `advice` is still the richer route, because a plan can be
curated, briefed and approved before anything is published. `/guides` is the one-shot version: it
publishes the page and records it.

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

Hand it `.claude/skills/giftcoves-seed-coves/SKILL.md`, and have it write from
`GET /coves/{id}/brief`, which serves the exact prompt the builder uses. Do not keep a copy of the
rules here; four copies drifted apart before (see [cove-writer.md](cove-writer.md)).

One thing the skill does not cover, worth keeping here: a persona or article write may carry
`scene` (`App\Enums\CoveScene`), validated against the kind rather than the whole enum, and a
scene the deployed server does not know is a **422** — which is a deploy gate, because the API
checks the running build and the database the running CHECK. Deploy before writing content that
names a new one. See [cove-scenes.md](cove-scenes.md).

## Related changes

- `BuildDailyEdition` now takes an optional `Y-m-d`. It previously always built *today*, which made
  the admin panel's "Build now" button on a plan for next Tuesday appear to do nothing.
- The Cove planner in Filament shows and edits the `editorial` field, because reviewing what an
  automated writer produced before approving it is the entire point of the draft/approve split.

## Learned the hard way (2026-09-07, the first hundred authored pieces)

A session published 20 dailies, 39 personas, 28 advice articles and 35 brand coves on production in
one day, all authored here and none through the model. What the docs above did not say:

- **`POST /coves` replaces the plan.** Upserting an approved daily with three more items reset its
  `editorial`, `blurb`, `pickMode` and `writer` to the defaults, and the daily would have gone to
  the model at 06:00. Send every field, every time; then re-send `items[].copy` through
  `POST /coves/{id}/editorial`, because the copy lines are on the items the upsert replaced.
- **Item ids live in the brief** (`GET /coves/{id}/brief`, `items[].id`) and in the queue, not in
  `GET /coves/{id}`, which describes items by product only.
- **Approved plans refuse item changes** (`assertOpenForCuration`, 403 whatever the ability). A
  `used` plan refuses both `approve` and `build`; the three leftover "verlanglijstjes" plans (678,
  679, 680) were republished under fresh slugs for that reason and still sit there as `used`.
- **A brand cove can only link what its brief lists.** `allowlist.searches` is the brand's top-40
  categories in that market; LEGO's allowlist on be-nl is two entries long, so its piece links one.
- **The daily allowlist is the item categories**, so a "search for more" link on a product paragraph
  has to name the product's category string exactly (`[[search:Toetsenbord gaming pc|…]]`), read
  from `GET /products/{id}`.
- `metaDescription` is capped at 160 characters and `blurb` at 300; the 422 names the field.

## Display titles (2026-09-14)

Two endpoints for the gift-friendly product titles that sit beside the feed's title:
`GET /products/untitled` lists the products on an editorial surface that still lack one, each row
naming the surface (`daily`, `plan`, `chart`, `surprise`), paged by id; `POST /products/titles`
writes up to 200 at once, all or nothing, `null` to clear. The write is a publish, because a title
reaches every reader on the next request. The titles are authored outside and posted in; no job in
the application writes them. The why, the surfaces and the writing brief are in
[display-titles.md](display-titles.md).

## Gift tags (2026-09-14)

`GET /products/untagged` and `POST /products/tags`, the same shape as the display-title pair: the
listing carries the vocabulary, the write is a publish, all or nothing, replacing a product's tags.
The vocabulary, the engine's reading of them and the tagging brief are in
[gift-tags.md](gift-tags.md).

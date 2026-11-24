---
name: giftcoves-seed-coves
description: Drive the GiftCoves editorial pipeline over its API — feed the planner with topics, pick the products, write the prose from the prompt the server holds, then approve and publish. Any kind of Cove (daily, persona, guide, seasonal, advice, shop, brand), any contiguous run of those stages. Use when asked to seed, draft, plan, curate, brief, write, build or publish Coves, gift personas, buying guides, seasonal guides, advice articles, shop or brand pages for GiftCoves or brandcoves, or to turn a list of EANs into a cove plan.
---

# Driving the GiftCoves editorial pipeline

Drive the whole editorial pipeline through `/api/editorial`: feed the planner with topics,
pick the products, write the prose **from the prompt the server holds**, approve, publish.

Any contiguous run of those stages. "Feed the planner and publish" is all of them; "build
the coves for which the products are picked" starts at the writing.

**Everything up to `approve` is a draft.** Publishing needs the `editorial.publish` ability
and is a separate, deliberate call — never make it unless the user asks in this
conversation.

## Setup, every session

1. **Base URL** — production unless the user says otherwise.
   - production: `https://giftcoves.com`
   - staging: `https://staging.giftcoves.com`

   Both serve the identical API. `GET /health` names the environment. Both hosts report
   `branch: main` since the staging branch was deleted on 2026-08-31, and `environment` says
   `production` on both, so neither field distinguishes them. Trust the host you called.
2. **Key** — `GIFTCOVES_API_KEY` from the environment if it is set. If it is not, ask
   the user to paste one, hold it for this session only, and never write it to a file
   that gets returned, echo it in a command you print, or repeat it back in a message.
   **A key is issued per environment.** A staging key gets 401 on production.
3. **`GET /api/editorial`** — always. It returns the abilities this key actually has,
   the live market list and the writing contract. It is the source of truth;
   `reference/api.md` in this skill is orientation and may lag the server.

If step 3 answers 401 the key is wrong, revoked, or for the other environment. Stop
and say so — do not retry.

## One host, start to finish

**A group id means nothing outside the environment that issued it.** Measured, same
barcode, same market:

    EAN 4548736132580  ->  3210 on production, 3921 on staging, 21214 on a dev box

So resolve and write against the same host, always. An id carried across environments
is not a 404 you would notice. Proven, not theorised: group `3921` is the Sony
WH-1000XM5 on staging and an **ASUS Vivobook 16** on production — same id, same
market, in stock, priced, pictured, and accepted by every validation the server has.

**Nothing can catch that for you.** The server's `rejectUnusable()` checks the market,
stock, price and image, and an id from the wrong environment passes all four.
`scripts/seed_coves.py` re-checks existence and market and would also have passed it.
The only real defences are:

- **Prefer EANs in briefs.** A barcode is the same number everywhere; an id is not.
- When a brief must carry a literal `groupId`, give the item an `"expect"` string —
  a few words from the intended title. The script fetches the id and refuses to write
  if the title does not contain them, which is what actually caught the Vivobook.
- Never reuse an id from an earlier session, another conversation, another
  environment, or another market. Resolve again; it is one cheap request.

## Production is production

Everything this skill writes is a **draft** — a `cove_plans` row the builder ignores
until a person approves it — so seeding production is not itself a publishing act.
Two things still follow from it:

- Say which host you are writing to before you write, and confirm with the user if
  they have not said. The script prints it.
- Never call `approve`, `build` or `publish` on production unless the user asks in
  this conversation. Those are the calls that reach real visitors.

## The one hard rule

**A product can only be named by an id the server gave you.** Never invent a
`groupId`, never reuse one from memory, never carry one between markets — the same
physical product in `be-nl` and `nl-nl` is a different id with different offers, and
mixing them is a correctness bug rather than a typo. A write containing one unusable
id is rejected whole, on purpose: an article whose second pick silently vanished has
a dangling sentence.

## A barcode is a lookup

    GET /products?market=<market>&ean=<ean>

Resolves against the `(market, identity_key)` index — the same hit the shopper path takes,
normalised so a 12-digit UPC-A or a 14-digit ITF-14 finds the GTIN-13 the catalogue stores.

| Reply | Means |
|---|---|
| `count: 1` | found. `data[0].id` is the group id for **this host** |
| `count: 0` | no EAN-grouped product in that market |
| **422** | the barcode failed its check digit — a misread, not a miss |

The 422 matters: a failed check digit will fail in every market, so retrying elsewhere is
wasted. A `count: 0` may still be recoverable — add `&includeLive=1`, which asks bol and
ingests what comes back before re-reading the catalogue, all in the one request.

Coverage is EAN-grouped groups only: a feed shipping no barcode leaves its products grouped
by brand and title, so the site can hold a product this cannot see. Fall back to
`?q=<title words>` and confirm with `GET /products/{id}`.

**Prefer EANs in a brief.** A barcode is the same number everywhere; a group id is not — it
is per market *and* per environment, and an id from the wrong host names a real, in-stock,
perfectly usable **different product** that every validation the server has will accept.

## Addressing: date or slug, never both

`CoveKind` decides, and the server refuses the wrong one rather than reconciling it.

| kind | addressed by | shortlist | article fields |
|---|---|---|---|
| `daily` | `date` (`YYYY-MM-DD`) — **no slug** | optional | no |
| `persona` | `slug` — **no date** | usually with `pickMode: "locked"` | no |
| `guide` | `slug` | the substance, 3–12 | yes |
| `seasonal` | `slug` | yes | yes, plus `seasonFrom`/`seasonTo` as `MM-DD` |
| `advice` | `slug` | none by design | yes |
| `shop` | `slug` — must name a shop this market compares | none; live rails instead | yes |
| `brand` | `slug` — must be a `brand_stats` slug | none; live rails instead | yes |

Article fields — `focusKeyphrase`, `searchQuestions`, `metaDescription`, `body`, `faq` — are **refused**
on a non-article kind, not dropped. A Daily's or a persona's words go in `editorial`.

**Except a Daily's two search fields (since 2026-11-24): send `focusKeyphrase` and `metaDescription`
with every Daily.** The Daily's title is a mood ("Op twee wielen"); the search phrase goes in front of it
in the page's `<title>` ("Fietsaccessoires cadeau: Op twee wielen"). Lowercase, 2 to 5 words, what a
person types, built from the products, usually with "cadeau"; a repeated theme reuses the first
edition's phrase (repeats point at the first edition as canonical). Use the phrase of an existing guide
on the subject where there is one: the Daily then links to that guide. The description: 120 to 155
characters naming what is in the list, no prices, no dashes. `PATCH /coves/{id}` sets both on a live
Daily at once, without a rebuild.

One slug namespace covers every kind in a market, so a slug another kind already
holds is a 422 and never an upsert.

`POST /coves` upserts on `(market, date)` for a Daily and on `(market, slug)` for
everything else, so a retry after a timeout is safe. It also **replaces the shortlist
wholesale** — send the full list every time, or use `POST /coves/{id}/editorial`
(prose only, cannot touch membership or rank) when revising words alone.

## The flow

An instruction is a **selector plus a target stage**, and naming a stage implies every
earlier one not already satisfied. "Feed the planner and publish" is all six; "build the
coves for which the products are picked" starts at the writing.

| Stage | Call |
|---|---|
| plan | `GET /calendar` → `POST /coves/drafts` (or `POST /calendar/draft` for one named day, `POST /seasons/{topic}/plan` for a season) |
| curate | `POST /coves/{id}/items`, `PATCH /coves/{id}/items`, `POST /coves/{id}/suggest` |
| tag | `POST /products/tags` for every product on the shortlist that has no gift tags (see below) |
| write | `GET /coves/{id}/brief` → write → `POST /coves/{id}/editorial` |
| approve | `POST /coves/{id}/approve` — needs `editorial.publish` |
| build | happens on the day, or `POST /coves/{id}/build` |

`POST /coves/stages/{stage}` runs `curate`, `approve` or `build` over a **set** — writes are
20/min per token, so a thirty-Cove run made one call at a time spends most of an hour being
paced. `dryRun: true` answers "what would this publish" before it publishes. Every response
is per id and never a bare count.

### Tag what you picked

Part of every run that picks or writes products (owner, 2026-11-15), for the kinds with a
shortlist: daily, persona, guide, seasonal. Once the shortlist is settled, and before you
report the Cove as done, give every product on it that has no gift tags its tags. You have
just judged who each product suits in order to write about it; the tags are that judgment
in the form the rest of the site reads (Find a gift, the gift landing pages, and the
"a good gift for ..." sentence under the search results, which only appears for tagged
products).

- Which ones: `GET /products/untagged?market=<m>&limit=200` lists the untagged products on
  editorial surfaces; keep the rows whose `id` is on your shortlist. A product that already
  has tags is left alone: `POST /products/tags` replaces what a product had.
- How to judge and post: `.claude/skills/giftcoves-tag-products/SKILL.md` and its
  `reference/brief.md`. Same rules, same vocabulary, same `POST /products/tags` body. Never
  `"giftable": false` for a product you put in a Cove yourself; if it is not a gift, it
  should not be on the shortlist.
- One batch per market, after the Cove's own writes, at the same 3 s pace.
- Say in the report how many products were tagged.

### Write from the brief, not from these notes

**`GET /coves/{id}/brief` is the contract.** It returns the exact `system` and `user`
messages the built-in builder would send for that plan — including any edit somebody has
made in the admin panel — plus the link allowlist, the shortlist with its notes, the
product floor, and a `revision` to quote back.

Everything below is orientation. The brief is the thing that is true.

### What state a plan is in

`GET /coves?state=` filters on the same vocabulary the planner screen shows:
`draft`, `written`, `approved`, `due_again`, `live`, `thin`, `archive`. Each plan reports
its `state` and a `nextStage`.

**`thin` is the one worth knowing.** It means the build ran and produced no page, almost
always a catalogue too thin to clear the kind's floor. It used to be indistinguishable from
"not built yet", which is the difference between *published* and *nothing happened*.

### Reading back

`GET /coves/{id}/edition` — for **any** kind, not only a Daily. It reports whether a page
exists, `themeSource` (`planned` means your plan won), and `lastBuild.why` when the last
build came to nothing.

### Who writes it

`cove_plans.writer` is `builder` or `authored`. Sending prose sets `authored` for you, so a
brief that carries words means the model never runs over them. Send `writer: "builder"` to
file a first draft you want the builder to finish.

`items[].copy` on `POST /coves/{id}/editorial` is the **sentence printed under the card**.
`note` is the curator's reason for choosing the product and is read by the writer alone;
the two are separate fields and writing one no longer destroys the other.

## Writing the prose

`editorial` is the article for a Daily or a persona (8000 characters max, the same
cap the builder applies to model-written prose). `body` is the article for a guide or
an advice piece. Write in the market's language.

**A picture between paragraphs is a figure token on a line of its own** (advice and guide
bodies; authored articles only, the builder never writes one). The key is a `CoveScene` value:

    [[figure:coin_jar]]

**An advice article is consumer journalism (owner, 2026-11-07).** Read the `cove.advice`
prompt from the brief and follow it: an SEO title with the main keyword (`focusKeyphrase`), a
two- or three-sentence core answer as the blurb, 4-7 sections under `## ` headings phrased as
the questions people search (`searchQuestions`, set them on the plan), a checklist as a list,
an FAQ of 3-5 not answered in the text, a `metaDescription` of at most 155 characters,
900-1300 words. Name laws, periods and bodies; never a product price, never a shop's policy as
fact. In an article body (guide, seasonal, advice) these render, each a paragraph of its own:

    ## A question people search?        ### A sub-heading
    - a checklist item                  1. a numbered step
    (every line of the paragraph starts with the marker)

and a link to an official source, only on the domains in `giftcoves.editorial_sources`
(https; check the page exists, or link the home page):

    [[source:https://www.consuwijzer.nl/|ConsuWijzer]]

**Put the picture on our server first** (since 2026-11-23): `POST /api/editorial/images` with a
`file` or a `url`, plus `alt`, answers our own address and the `[[image:…]]` line to paste. A
picture linked from another site disappears when they move it; ours does not. JPEG, PNG, WebP or
GIF; never SVG, never Amazon (docs/features/editorial-images.md).

**Authored articles only (since 2026-11-07, for the gift-card guides):** a photograph on a line
of its own, and a link to the shop where something is sold. Both https, no credentials or port.
The picture's description is its alt text and is required. A shop link may be any shop, Amazon
included; an Amazon address on the market's own storefront gets our Associates tag added and
`sponsored`. Check every picture address loads before publishing.

    [[image:https://www.bongo.be/…/kaart.png|De Bongo-cadeaubon]]
    [[image:https://www.bongo.be/…/kaart.png|Bongo-cadeaubon|https://www.bongo.be/nl/bon]]

The second form adds where it is sold: the picture then carries the site's save button, which
puts it on a list as an offline item with that name, picture and link. Write the description as
the thing's name when you give a third part, since it becomes the item's title.
    Koop hem bij [[shop:https://www.bongo.be/nl|Bongo]].

A third part written `link:` makes the picture itself the link, with no save button (2026-11-23,
the Prime logo): `[[image:https://giftcoves.com/media/editorial/….webp|Amazon Prime logo|link:https://www.amazon.com.be/prime]]`.
Tagged and tracked like a shop link.

An article body may be 60,000 characters (was 20,000); the advice prompt's 900-1300 words is a
default, not a cap, when the owner asks for an extensive piece.

**Otherwise link with tokens, never a URL, markdown or HTML:**

    [[product:1234|the odd one]]   [[brand:Sony]]   [[search:draadloze koptelefoon]]
    [[guide:beste-koptelefoons]]   [[page:gift-whisperer]]

Only the plan's own products, their brands and their categories resolve; anything
else is stripped back to plain text, which is why `linkCheck` has to be read. On a
plan it is advisory — the builder's own finds are not in the allowlist yet.

Voice: dry, specific, quietly amused. Pointing at odd things and saying why they are
worth a second look, not selling.

**Shape: a short opening, then a paragraph about each product.** The frontend renders
each product's card directly underneath the paragraph whose copy names it, so the
paragraph is the writing that product gets and the only writing it gets. A product no
paragraph names has no card in the article at all — it drops to a bare card at the
foot of the page.

- **One product per paragraph.** Two in one paragraph stacks both cards under it and
  reads as a caption for a pair. Only the first mention of a product places a card.
- **Never state a price, a rating, or a claim about stock or quality.** The page
  renders live prices; a number in a sentence is wrong within a week.
- No "amazing", no exclamation marks, no rhetorical questions.

`queries` are **product words** — "hondenmand" finds products, "cadeau voor
hondenliefhebbers" finds nothing. They bias the builder's finds and never filter.

Per item, `note` is a brief to whoever writes the prose and is never shown to a
reader; `verdict` is a short label such as "best for small kitchens".
`buildInstructions` is direction for the whole piece, capped at 1000 characters.

## What a 4xx means

- **422** — read the message, it names the field. Fix and retry. **Never drop the
  offending item to make the request pass**: that leaves an article referring to
  something that is no longer in it.
- **403** — the key lacks that ability. Say so and stop. Editing an already-approved
  plan needs `editorial.publish`; that is the design, not an obstacle to route around.
- **429** — reads are 120/min and writes 20/min, keyed per token. Back off.

The full endpoint contract is in `reference/api.md`.

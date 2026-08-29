---
name: giftcoves-seed-coves
description: Seed the GiftCoves cove planner over the editorial API — create or rewrite draft Cove plans of any kind (daily, persona, guide, seasonal, advice) with a curated product shortlist given as EAN barcodes, a topic or title, and authored editorial prose. Use when asked to seed, draft, plan, brief or write Coves, gift personas, buying guides, seasonal guides or advice articles for GiftCoves or brandcoves, or to turn a list of EANs into a cove plan.
---

# Seeding the GiftCoves cove planner

Create draft `cove_plans` rows through `/api/editorial`, curated with real products
and authored prose. **Everything this skill writes is a draft.** Publishing needs the
`editorial.publish` ability and is a separate, deliberate call — never make it unless
the user asks in this conversation.

## Setup, every session

1. **Base URL** — production unless the user says otherwise.
   - production: `https://giftcoves.com`
   - staging: `https://staging.giftcoves.com`

   Both serve the identical API. `GET /health` names the environment: `branch: "main"`
   is production, `branch: "staging"` is not. `environment` says `production` on both,
   because staging runs production config — it is not the field to read.
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

## EANs are not first-class — resolve them first

There is **no EAN lookup in the editorial API**. `GET /products?q=` is full-text over
title, brand, category and description; a barcode matches none of those and comes
back as an empty list that reads like "we do not stock it".

EANs resolve through the **public** scan endpoint instead, which hits the
`(market, identity_key)` unique index directly:

    GET https://<host>/<market>/scan/<ean>        # no auth, 120/min

`status: "found"` returns `url: /<market>/p/<groupId>/<slug>` — the group id is the
third path segment. `status: "not_found"` means no EAN-grouped product in that
market. `422` means the barcode failed its check digit: a misread, not a miss.

**A miss is not always final.** A feed that ships no barcode leaves its products
grouped by brand and title instead, so the site can hold a product the scan cannot
see, and bol is queried live rather than crawled. Two recoveries, in order:

1. `GET /products?market=…&q=<ean>&includeLive=1` — asks bol with that term and
   ingests what comes back. Its own reply will still be empty (full-text, no EAN), so
   **ignore the response body** and re-run the scan. Best-effort.
2. Search by name: `GET /products?market=…&q=<title words>`, then confirm the match
   with `GET /products/{id}`, which lists the merchants and prices behind the group.

Never guess past a miss. Report the unresolved EANs and let the user decide.

`scripts/seed_coves.py` does all of this. Prefer it over hand-rolled requests — it
resolves, retries, enforces the address rules below, and prints what it would send.

## Only `daily` and `persona` work on the deployed API

Measured against production on 2026-08-29 by writing one and reading it back. The rest
of this section describes the *intended* contract; the deployed `CovePlanController`
does not implement it yet, and **fails silently rather than refusing**:

- `'slug' => $kind === Persona ? $slug : null` — a guide's or seasonal's slug is
  **discarded**. The plan is stored with no address at all.
- `validated()` does not list `body`, `faq`, `focusKeyphrase`, `metaDescription`,
  `seasonFrom` or `seasonTo`. Laravel returns only validated keys, so those fields are
  **dropped without a word**. Not refused — dropped.
- The response is **`201`**. Nothing in it says the article was thrown away.

A real one: `POST /coves` with `kind: "guide"`, a slug, a body and a FAQ returned 201
and created plan 740 with `slug: null`, `body` absent, `faq` absent, `hasEditorial:
false`. It cannot be built, because a dateless non-persona has no address.

**And it is not idempotent.** The upsert finds an existing plan by slug (personas) or
by date (dailies). A guide has neither once its slug has been discarded, so the lookup
returns nothing and every retry **creates another orphan row**. The same brief sent
twice made plans 740 *and* 741. A client retrying after a timeout would fill the
planner. This is the strongest reason not to send article kinds at this host.

**So on production today, write only:**

| kind | how | carries |
|---|---|---|
| `daily` | `date` | `title`, `blurb`, `editorial`, `queries`, `items`, `buildInstructions` |
| `persona` | `slug` | the same, plus `pickMode: "locked"` |

For a guide, use **`POST /guides`** instead — a separate, fully deployed endpoint over
the `guides` table (title, intro, body, FAQ, meta, ranked items, 3–12 required).

Everything below becomes true once the uncommitted `CovePlanController` rewrite is
committed and deployed. Re-check with one write and a read-back before relying on it.

## Addressing: date or slug, never both

`CoveKind` decides, and the server refuses the wrong one rather than reconciling it.

| kind | addressed by | shortlist | article fields |
|---|---|---|---|
| `daily` | `date` (`YYYY-MM-DD`) — **no slug** | optional | no |
| `persona` | `slug` — **no date** | usually with `pickMode: "locked"` | no |
| `guide` | `slug` | the substance, 3–12 | yes |
| `seasonal` | `slug` | yes | yes, plus `seasonFrom`/`seasonTo` as `MM-DD` |
| `advice` | `slug` | none by design | yes |
| `shop` | `slug` | seeded from the repo, not here | no |

Article fields — `focusKeyphrase`, `metaDescription`, `body`, `faq` — are **refused**
on a non-article kind, not dropped. A Daily's or a persona's words go in `editorial`.

One slug namespace covers every kind in a market, so a slug another kind already
holds is a 422 and never an upsert.

`POST /coves` upserts on `(market, date)` for a Daily and on `(market, slug)` for
everything else, so a retry after a timeout is safe. It also **replaces the shortlist
wholesale** — send the full list every time, or use `POST /coves/{id}/editorial`
(prose only, cannot touch membership or rank) when revising words alone.

## The flow

1. **What is already planned** — `GET /coves?market=…` (the calendar, so you do not
   write over an approved plan) and `GET /coves/queue?market=…` (briefs that need
   prose: shortlist, curator notes, link allowlist, and the `revision` string that
   `POST /coves/{id}/editorial` requires).
2. **What is worth writing** — `GET /topics?market=…`, clusters of queries real
   visitors typed into this site, with how many products exist to answer each. That
   is the demand signal; a guide written against one has an audience the day it
   publishes.
3. Resolve the EANs, as above.
4. `POST /coves` — the plan, with its `items` and its prose.
5. Read **`linkCheck.unresolved`** in the response. Those tokens will render as plain
   text. Fix them and POST again; the same date or slug updates in place.

> **`POST /coves/drafts` is not deployed.** Probed on production 2026-08-29: it
> answers **405**, because the wildcard `GET /coves/{plan}` is the only thing
> registered at that path. The controller and its service layer exist only in an
> uncommitted working tree, so neither host has it. Do not call it, and do not treat
> its absence as an outage. Steps 1 and 2 cover what it would have given you: think
> up the titles yourself, grounded in `/topics`.

## Writing the prose

`editorial` is the article for a Daily or a persona (4000 characters max, the same
cap the builder applies to model-written prose). `body` is the article for a guide or
an advice piece. Write in the market's language.

**Link with tokens, never a URL, markdown or HTML:**

    [[product:1234|the odd one]]   [[brand:Sony]]   [[search:draadloze koptelefoon]]
    [[guide:beste-koptelefoons]]   [[page:gift-whisperer]]

Only the plan's own products, their brands and their categories resolve; anything
else is stripped back to plain text, which is why `linkCheck` has to be read. On a
plan it is advisory — the builder's own finds are not in the allowlist yet.

Voice: two or three paragraphs, dry, specific, quietly amused. Pointing at odd things
and saying why they are worth a second look, not selling.

- **Never state a price, a rating, or a claim about stock or quality.** The page
  renders live prices; a number in a sentence is wrong within a week.
- No "amazing", no exclamation marks, no rhetorical questions.
- Do not walk the list in order. Pick two or three worth a sentence, let the rest stand.

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

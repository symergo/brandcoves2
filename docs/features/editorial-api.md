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
at build time, which do not exist when the plan is written. A token naming an unpinned product may
still resolve later. It is reported as unresolved anyway, because that is what is known now, and
telling an author a link is fine when it might not be is the failure that matters.

### Guides are the exception

Guide prose renders as plain text — the page already links every item to its own product page — so a
token there would be **printed to the reader**. The write is refused rather than the token stripped:
the author meant to link, and a silently deleted link is a hole nobody notices until it is indexed.

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

Upsert on `(market, date)`, because the unique index allows one dated plan per market per day and a
client retrying after a timeout must not get a constraint violation for work it already did.

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
realistic way this gets hammered. Both in `config/brandcoves.php`.

## Reading back

`GET /api/editorial/editions/{market}/{date}` shows future and unpublished editions, unlike the
public page. The reason the public route hides them — guessing tomorrow's puzzle by URL — does not
apply to a holder of an editorial key, and an author building tomorrow's Cove needs to read it today.
It is also the only place the challenge answer is exposed.

`theme.source` is the field to check first when a Cove did not come out as written. `planned` means
the plan won; anything else (`observance`, `theme`, `ai`, `curated`) means it did not — and the most
likely reason is that nobody approved it.

## Briefing an automated writer

The block below is what you hand to Claude — as a `CLAUDE.md` in whatever directory it works from, as
a scheduled-agent prompt, or pasted into a conversation. It is deliberately short: the API root is
self-describing, so the brief tells it where to look rather than duplicating the endpoint list, which
would drift.

Kept here so the two change together. If you edit the API's contract, edit this.

```markdown
# Writing for Brandcoves

You write product-inspiration content for Brandcoves through its editorial API. You have no
shell access and do not need one.

    Base URL: https://brandcoves.com/api/editorial   (staging: https://staging.brandcoves.com)
    Auth:     Authorization: Bearer $BRANDCOVES_API_KEY

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
3. `POST /coves` with `market`, `date` (YYYY-MM-DD), `title`, `blurb`, `editorial`,
   `pinnedGroupIds`, `queries`.
4. Read `linkCheck` in the response. `unresolved` lists tokens that will render as plain text.
   Fix them and POST again — the same date updates in place.

Write in the market's language (`GET /api/editorial` lists them). `queries` are product words —
"hondenmand" finds products, "cadeau voor hondenliefhebbers" finds nothing.

### Voice

Two or three paragraphs. Dry, specific, quietly amused. You are pointing at odd things and saying
why they are worth a second look. You are not selling.

- Never state a price, a rating, or a claim about quality or stock. Prices move and the page
  renders live ones; a number in a sentence is wrong within a week.
- No "amazing", no exclamation marks, no rhetorical questions.
- Do not walk the list in order. Pick two or three worth a sentence and let the rest stand.

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

---
name: Who writes a Cove
area: Content / Operations
status: Active
date_added: 2026-09-05
---

# Who writes a Cove

**The plan says who writes it, and the prompt is served to whoever that is.**

Two changes that are really one: `cove_plans.writer` states whether the builder writes a Cove or
somebody else does, and `GET /api/editorial/coves/{id}/brief` hands that somebody the exact prompt
the builder would have used.

## The answer used to be inferred, in three places that disagreed

`EditionBuilder` decided "has a person already written this?" by looking at which fields happened to
be non-empty:

```php
editorial()  short-circuits on  filled($plan->editorial)
article()    short-circuits on  filled($plan->body) && filled($plan->blurb)
```

The second one is why this is a field now. An author who sent a finished `body` and left the
one-line `blurb` to us got the model run over their article anyway: real spend against `guide_copy`,
a generated title replacing the one they chose, and **nothing anywhere reporting it**. They found
out by reading the published page.

`App\Enums\PlanWriter` replaces the guess with a statement — `builder` or `authored` — and
`callsModel()` is the one question the builder asks.

### `builder` is the default, and the migration backfills

Every row that predates the column is one the builder writes; a default of `authored` would tell the
builder to publish the empty prose of several hundred plans nobody has touched. The migration then
marks anything already carrying `editorial` or `body` as `authored`, so the stored value says what
the code was already doing.

**`blurb` is deliberately not part of that backfill test.** A plan with a body and no blurb is
precisely the case that was being got wrong, and it was authored too.

### Sending prose still means you wrote it

Both write endpoints default `writer` for you, so no key deployed against the old behaviour breaks:

- `POST /coves` sets `authored` when the body carries `editorial` or `body`;
- `POST /coves/{id}/editorial` sets `authored` unconditionally — posting prose to the prose endpoint
  *is* the claim. Send `writer: builder` explicitly to file a first draft for the model to finish.

`filled($plan->body)` survives inside `article()` as a **floor**, not as the question: a plan marked
authored before anybody has written it would otherwise publish a page with nothing on it, and a
generated article is a far better answer to that than an empty one.

### A redo hands the pen back

`EditionBuilder::redo()` clears the prose, so it also resets `writer` to `builder`. Leaving it
`authored` would say the plan is waiting on a person who has already had their turn, about prose that
has just been deleted — and the page would rebuild empty.

## `cove_plan_items.copy`: the sentence, not the reason

`note` means *why a person put this product on the list*. It is a brief for whoever writes the piece
and no reader ever sees it. One endpoint quietly broke that: `POST /coves/{id}/editorial` accepted
`items[].copy` — an author's finished sentence about a product — and wrote it into `note`,
overwriting the reasoning with the prose it was supposed to produce. The next rewrite of that plan
was then briefed with its own output.

Worse, the copy had nowhere to go anyway. `itemCopy()` read each card's sentence out of the *model's*
return value and nowhere else, so when a plan carried authored prose the builder short-circuited,
that value came back empty, and **every `daily_picks.blurb` was null** — an article written entirely
by hand published with blank cards under the paragraphs discussing them.

Two columns, because two people write them at different moments:

| Column | Written by | Reaches a reader |
|---|---|---|
| `note` | the curator, while choosing | no |
| `copy` | the writer, afterwards | **yes**, under the card |
| `verdict` | the curator | yes, on the card |

`itemCopy()` now prefers the authored `copy`, **matched by group id**. The model's own output stays
positional — it was handed `$finds` in that order — but a plan's items are not in that order: the
shortlist leads the edition and the engine's additions follow it, so item N of the plan is not find
N. Reading authored copy positionally would attach one product's sentence to another product's card,
which is worse than having none.

A redo clears `copy` and keeps `note` and `verdict`, for the same reason it clears the body: one is
output, the others are decisions.

## The prompt is served, not described

`App\Services\Cove\CovePrompt` is the one place a Cove's prompt is assembled, and both writers ask
it: `EditionBuilder` when the model writes, and `GET /coves/{id}/brief` when somebody else does.

Before this, the writing contract reached an external author as **four hand-maintained copies** — the
API root's `writing` block, `docs/publishing-guide.md`, `docs/features/scheduled-writing.md` and the
seed skill. They had already drifted: the API root, which the skill calls the source of truth, omits
the one-paragraph-per-product rule that `ProseCards::promptContract()` exists to make undroppable. An
agent following the server's own description of itself writes prose that publishes with bare cards.

What comes back is the assembled `system` and `user`, **prompt-bank override included** — so an edit
at *Operations → Prompts* governs Claude the same afternoon it governs the builder.

### The layers, and which of them an editor may change

| Layer | Source | Editable |
|---|---|---|
| Voice and rules, per kind | `PromptBank` → `Defaults::*_SYSTEM` | **yes** |
| One paragraph per product | `ProseCards::promptContract()` | no |
| The two curated rules (column kinds) | `CovePrompt` | no |
| Link tokens + this piece's allowlist | `CoveMarkup::promptContract()` | no |
| The brief | the plan, bound into the kind's template | n/a |

The uneditable layers are uneditable on purpose: a prompt edit may change how a Cove *sounds* and
must not be able to drop the rules that decide whether its cards render.

### A body-writing kind is `GuideWriter`'s, and that is not a detail

`CovePrompt::forPlan()` delegates to `GuideWriter::promptFor()` for every kind that writes a body. A
guide's prompt is a different shape from a column's, and rightly so — a guide argues product by
product and its order *is* the ranking, so it carries its own order rule rather than the column's
"somebody chose that order".

The first version of `CovePrompt` assembled the column shape for every kind. The byte-identical test
caught it on the first run: an author writing a guide would have been handed the Daily's rules and a
prompt the builder never sends. **That test is the feature.** Everything else here is convenience;
that one is the property, and it is the only thing that stops the contract drifting again.

### It also found a self-link

Assembling the allowlist in one place surfaced a real bug. `guideSlugs()` takes an exclusion and the
builder never passed one, so on a **rebuild** a guide was offered its own slug and could link to
itself — a loop for a reader, a dead end for a crawler. It never showed on a first build, because the
page does not exist yet at that point. Both callers now exclude `$plan->edition_id`.

## Shop Coves can be built

`CoveKind::writesBody()` is new, and it answers the build question that `isArticle()` was being asked
by mistake. `isArticle()` asks whether a kind lives in the `/guides` URL space; Shop answers **false**
deliberately, because it is read at `/shops/{slug}` and must stay out of the guides index, sitemap and
hreflang pairing.

Asking that question at the top of `buildArticle()` left `CoveKind::Shop` with no build path at all:
`BuildCove` fell through to the Daily arm, found no `drop_date` and returned null. A Shop plan could
be planned, curated and approved, and then quietly did nothing — while `Defaults::SHOP_SYSTEM` sat in
the prompt bank with no caller.

The same confusion was in `CovePlanController`, where `isArticle()` gated the article *fields*: a
Shop plan could not carry the `body` that `buildArticle()` requires. Both now ask `writesBody()`.

`bc:seed-shop-coves` also calls `CovePlan::recordFor()` now, as the advice seeder already did — the
shipped Shop Coves were the only published pages on the site with no plan behind them, so they were
invisible to the planner and impossible to re-curate.

### A Shop slug has to name a real shop

Every other rule about a slug is about shape. This one is about meaning: a Shop Cove's slug is derived
from `merchants.domain`, which is what lets one shop keep one address in every market it trades in and
be paired for hreflang. A hand-written or API-supplied slug bypasses that derivation, and the result
is an article about a shop absent from the directory it sits above — with nothing to report it,
because the plan is perfectly well-formed.

`App\Services\Shops\ShopDirectory` now owns both halves of that question. `ShopsController` and
`bc:seed-shop-coves` each carried their own copy of the membership query, and the seeder's own comment
said the day a third caller appeared was the day it earned a home — validating a plan's slug is that
caller.

## A barcode is a lookup now

`GET /products?ean=` resolves a barcode against `(market, identity_key)`, the same index the shopper
path uses. `q` runs against `search_vector`, which holds title, brand, category and description and
**no EAN at all**, so a barcode came back as an empty list that reads like "we don't stock it".

The workaround the seed skill was built around — the *public* `/{market}/scan/{ean}` endpoint, then
parsing a group id out of the URL it returned — is retired.

Three outcomes an author must be able to tell apart, which is why an invalid barcode is a **422**
rather than an empty list: a failed check digit is a misread, not a product we do not carry, and an
author told "not found" would go looking for it in another market.

## Files

- `app/Enums/PlanWriter.php`, `app/Enums/CoveKind.php` (`writesBody()`)
- `app/Services/Cove/CovePrompt.php` — the one assembly
- `app/Services/Cove/PlanRevision.php` — one hash, two endpoints
- `app/Services/Shops/ShopDirectory.php` — membership and slug, in one place
- `app/Http/Controllers/Api/CoveBriefController.php`
- `database/migrations/2026_09_05_000600_*`, `..._000700_*`
- `tests/Feature/CoveBriefApiTest.php` — the byte-identical test

## Open

- **The observance's own queries are still not linkable.** `CovePrompt::allowlist()` accepts an
  observance and no caller passes one, so the parameter is dead and a Christmas edition cannot link
  `[[search:kerstcadeau]]`. Worth changing; not worth changing inside a refactor whose value is that
  the served prompt and the sent prompt are identical.
- **The panel has no `writer` switch yet.** Typing prose into the Filament plan form leaves the plan
  marked `builder`, and the next build will rewrite it. The API defaults correctly; the panel does
  not, and that is the next thing to fix.
- `GET /coves/{id}/brief` describes an **open** plan's page approximately, because the engine tops
  the shortlist up on the day. An authored Cove usually wants `pickMode: locked`.

## See also

- [prompt-bank.md](prompt-bank.md) — what an editor may change about the voice
- [editorial-api.md](editorial-api.md) — the endpoints and the abilities
- [cove-curation.md](cove-curation.md) — where `note` and `verdict` come from
- [shop-coves.md](shop-coves.md) — why Shop is prose and not a `/guides` article
